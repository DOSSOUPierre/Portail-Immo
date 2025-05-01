<?php
// --- proprietaire-mes-biens.php (Version confirmée compatible avec la BDD fournie) ---

ob_start();
$isAjaxRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// 1. Session & Auth
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'Propriétaire') {
    error_log("Auth failure: Role=" . ($_SESSION['user_role'] ?? 'Non défini')); // Log pour débogage
    ob_end_clean();
    if ($isAjaxRequest) {
        header('Content-Type: application/json'); http_response_code(401); echo json_encode(['success' => false, 'message' => 'Session invalide ou rôle incorrect.']); exit;
    } else {
        header("Location: auth-signin.php?error=session_required"); exit;
    }
}

// 2. Connexion BDD
require_once __DIR__ . '/db_connection.php'; // Vérifier ce chemin !
if (!isset($pdo)) {
    error_log("CRITICAL: PDO object not defined after require."); ob_end_clean(); die("Erreur BDD.");
}

// 3. Récupérer ID Propriétaire
$idProprietaire = $_SESSION['idProprietaire'] ?? null;
if ($idProprietaire === null && isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    try {
        // Utiliser LEFT JOIN pour gérer le cas où idUser existe mais pas l'entrée proprietaire
        $stmt_pid = $pdo->prepare("SELECT p.idProprietaire FROM utilisateurs u LEFT JOIN proprietaire p ON u.idUser = p.idUser WHERE u.idUser = :userId AND u.role = 'Propriétaire'");
        $stmt_pid->bindParam(':userId', $userId, PDO::PARAM_INT); $stmt_pid->execute();
        $result = $stmt_pid->fetch(PDO::FETCH_ASSOC);
        if ($result && $result['idProprietaire']) {
            $idProprietaire = (int)$result['idProprietaire'];
            $_SESSION['idProprietaire'] = $idProprietaire; // Mettre en session si trouvé
             error_log("idProprietaire trouvé : " . $idProprietaire . " pour idUser: " . $userId); // Log
        } else {
            error_log("Propriétaire introuvable (PDO) pour user id: " . $userId . ". Rôle: " . $_SESSION['user_role']);
            ob_end_clean(); die("Erreur profil propriétaire non trouvé ou invalide.");
        }
    } catch (PDOException $e) {
        error_log("PDO get_prop_id Exception: " . $e->getMessage()); ob_end_clean(); die("Erreur serveur ID prop.");
    }
}
// Vérification finale après tentative de récupération
if ($idProprietaire === null) {
    error_log("Erreur fatale: ID Propriétaire est NULL après vérification. Session user_id: " . ($_SESSION['user_id'] ?? 'non défini'));
    ob_end_clean(); die("Erreur fatale: ID Propriétaire introuvable.");
} else {
    // Log pour confirmer l'ID utilisé
    error_log("Script exécuté pour idProprietaire: " . $idProprietaire);
}


// --- Définitions ---
$uploadDirRelative = 'uploads/biens/';
$uploadDirServer = __DIR__ . '/' . $uploadDirRelative; // Assurer un chemin absolu correct
$pageAlerts = []; $modalData = []; $modalAlerts = [];
$defaultImage = 'assets/images/property/default.jpg'; // Vérifier ce chemin aussi
// Création dossier upload si besoin (avec gestion erreur plus explicite)
if (!is_dir($uploadDirServer)) {
    if (!@mkdir($uploadDirServer, 0775, true) && !is_dir($uploadDirServer)) { // Re-vérifier après tentative
        $errorMsg = "Échec critique: Impossible de créer le dossier d'upload: " . $uploadDirServer . ". Vérifiez les permissions.";
        error_log($errorMsg);
        // On ne die pas forcément, mais on ajoute une alerte visible
        $pageAlerts[] = ['type' => 'danger', 'message' => 'Erreur technique: Le dossier pour les images ne peut pas être créé. Les uploads échoueront.'];
    } else {
         error_log("Dossier upload créé: " . $uploadDirServer);
    }
} elseif (!is_writable($uploadDirServer)) {
    $errorMsg = "Avertissement: Le dossier d'upload existe mais n'est pas accessible en écriture: " . $uploadDirServer . ". Vérifiez les permissions.";
    error_log($errorMsg);
    $pageAlerts[] = ['type' => 'warning', 'message' => 'Erreur technique: Le dossier pour les images n\'est pas accessible en écriture. Les uploads pourraient échouer.'];
}


// --- TRAITEMENT POST (save_bien) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'save_bien') {
    error_log("Début traitement POST save_bien pour idProprietaire: " . $idProprietaire); // Log
    $idBienPost = !empty($_POST['idBien']) ? filter_var($_POST['idBien'], FILTER_VALIDATE_INT) : null;
    $adressePost = trim($_POST['adresse'] ?? '');
    $loyerMensuelPost = filter_input(INPUT_POST, 'loyerMensuel', FILTER_VALIDATE_FLOAT);
    $idProprietairePost = filter_input(INPUT_POST, 'idProprietaire', FILTER_VALIDATE_INT);
    $modalData = $_POST;

    if ($idProprietairePost !== $idProprietaire) {
        error_log("Erreur save_bien: ID Propriétaire mismatch. Session: $idProprietaire, POST: $idProprietairePost");
        $pageAlerts[] = ['type' => 'danger', 'message' => 'Action non autorisée (ID Proprio mismatch).'];
    } else {
        $errors = [];
        if (empty($adressePost)) $errors[] = "L'adresse est requise.";
        if ($loyerMensuelPost === false || $loyerMensuelPost <= 0) $errors[] = "Le loyer mensuel doit être un nombre positif."; // Loyer > 0

        // Vérification si MODIFICATION autorisée (statut = 'Libre')
        if (!empty($idBienPost)) {
             error_log("Vérification statut pour MODIFICATION du bien ID: " . $idBienPost);
            try {
                $stmt_cs = $pdo->prepare("SELECT statut FROM bienimmobiliers WHERE idBien = :idBien AND idProprietaire = :idProp");
                $stmt_cs->execute([':idBien' => $idBienPost, ':idProp' => $idProprietaire]);
                $currentStatus = $stmt_cs->fetchColumn();
                if ($currentStatus === false) {
                    $errors[] = "Bien non trouvé ou non autorisé pour la modification.";
                    error_log("Erreur modif: Bien ID $idBienPost non trouvé pour proprio $idProprietaire.");
                } elseif ($currentStatus !== 'Libre') {
                    $errors[] = "Modification impossible : le bien doit être 'Libre' pour être modifié (statut actuel : '" . htmlspecialchars($currentStatus) . "').";
                     error_log("Erreur modif: Bien ID $idBienPost n'est pas Libre (Statut: $currentStatus).");
                } else {
                     error_log("Statut vérifié OK (Libre) pour modif Bien ID: " . $idBienPost);
                }
            } catch (PDOException $e) {
                $errors[] = "Erreur serveur lors de la vérification du statut du bien."; error_log("PDO check status modif Exception: ".$e->getMessage());
            }
        } else {
             error_log("Début traitement AJOUT nouveau bien.");
        }

        // Gestion Upload Image Principale
        $imageProfilName = null; $oldImageProfil = null; $newImageUploaded = false;
        if (empty($errors) && isset($_FILES['image_profil']) && $_FILES['image_profil']['error'] === UPLOAD_ERR_OK) {
            $img = $_FILES['image_profil']; $maxSize = 2 * 1024 * 1024; $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE); $mimeType = finfo_file($finfo, $img['tmp_name']); finfo_close($finfo);
            if ($img['size'] > $maxSize) { $errors[] = "Image principale: taille > 2Mo."; }
            elseif (!in_array($mimeType, $allowedMimeTypes)) { $errors[] = "Image principale: format invalide (JPG, PNG, GIF)."; }
            else {
                $extension = strtolower(pathinfo($img['name'], PATHINFO_EXTENSION)); $imageProfilName = 'bien_profil_' . $idProprietaire . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension; $uploadPath = $uploadDirServer . $imageProfilName;
                if (!@move_uploaded_file($img['tmp_name'], $uploadPath)) { // Ajouter @ pour éviter warning si échec
                     $moveError = error_get_last();
                    $errors[] = "Erreur serveur lors de l'enregistrement de l'image principale."; $imageProfilName = null; error_log("move_uploaded_file ECHEC pour $uploadPath. Erreur: " . ($moveError['message'] ?? 'Inconnue'));
                } else {
                    $newImageUploaded = true; error_log("Image principale uploadée: $uploadPath");
                }
            }
        } elseif (isset($_FILES['image_profil']) && $_FILES['image_profil']['error'] !== UPLOAD_ERR_NO_FILE) {
            $errors[] = "Erreur transfert image principale (Code: ".$_FILES['image_profil']['error'].").";
             error_log("Erreur upload image principale. Code: " . $_FILES['image_profil']['error']);
        }

        // Récupérer ancienne image si modification
        if (empty($errors) && !empty($idBienPost)) {
            try {
                $stmt_old = $pdo->prepare("SELECT image_profil FROM bienimmobiliers WHERE idBien=:idBien AND idProprietaire=:idProp");
                $stmt_old->execute([':idBien' => $idBienPost, ':idProp' => $idProprietaire]);
                $oldImageProfil = $stmt_old->fetchColumn();
                error_log("Ancienne image pour bien $idBienPost : " . ($oldImageProfil ?: 'Aucune'));
                if (!$newImageUploaded && $oldImageProfil) {
                    $imageProfilName = $oldImageProfil; // Garder l'ancien nom si pas de nouvel upload
                    $oldImageProfil = null; // Ne pas supprimer le fichier si on le garde
                     error_log("Conservation de l'ancienne image: " . $imageProfilName);
                }
            } catch (PDOException $e) { $errors[] = "Erreur récup. image existante."; error_log("PDO get old img Exception: " . $e->getMessage()); }
        }

        // --- Insertion ou Mise à jour BDD ---
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                error_log("Début transaction BDD pour save_bien ID: " . ($idBienPost ?: 'NOUVEAU'));

                if (empty($idBienPost)) { // AJOUT
                    $sql = "INSERT INTO bienimmobiliers (adresse, loyerMensuel, image_profil, idProprietaire, supervisionStatut, statut) VALUES (:adr, :loy, :img, :idp, 'En attente', 'Libre')";
                    $stmt = $pdo->prepare($sql);
                    $params = [':adr' => $adressePost, ':loy' => $loyerMensuelPost, ':img' => $imageProfilName, ':idp' => $idProprietaire];
                    error_log("SQL AJOUT: " . $sql . " Params: " . json_encode($params));
                    $stmt->execute($params);
                    $idBienPost = $pdo->lastInsertId(); // Récupérer le nouvel ID
                    error_log("Bien AJOUTÉ avec succès. Nouvel ID: " . $idBienPost);
                    $pageAlerts[] = ['type' => 'success', 'message' => 'Bien ajouté avec succès (ID: '.$idBienPost.'). Statut: Libre, en attente de validation.'];

                } else { // MODIFICATION
                    $sql = "UPDATE bienimmobiliers SET adresse = :adr, loyerMensuel = :loy, image_profil = :img WHERE idBien = :idb AND idProprietaire = :idp";
                    $stmt = $pdo->prepare($sql);
                     $params = [':adr' => $adressePost, ':loy' => $loyerMensuelPost, ':img' => $imageProfilName, ':idb' => $idBienPost, ':idp' => $idProprietaire];
                     error_log("SQL MODIF: " . $sql . " Params: " . json_encode($params));
                    $stmt->execute($params);
                     error_log("Bien MODIFIÉ avec succès. ID: " . $idBienPost . ". RowCount: " . $stmt->rowCount());
                    $pageAlerts[] = ['type' => 'success', 'message' => 'Bien (ID: '.$idBienPost.') mis à jour avec succès.'];

                    // Supprimer l'ancienne image principale SEULEMENT si une nouvelle a été uploadée ET qu'une ancienne existait
                    if ($newImageUploaded && $oldImageProfil && !empty($oldImageProfil) && file_exists($uploadDirServer.$oldImageProfil)) {
                        if (@unlink($uploadDirServer.$oldImageProfil)) {
                             error_log("Ancienne image principale SUPPRIMÉE: " . $uploadDirServer.$oldImageProfil);
                        } else {
                             error_log("ERREUR suppression ancienne image principale: " . $uploadDirServer.$oldImageProfil);
                            // Ne pas bloquer mais logger l'erreur
                        }
                    }
                }

                // --- Gestion Images Secondaires ---
                 if ($idBienPost > 0 && isset($_FILES['images_description']) && is_array($_FILES['images_description']['name']) && $_FILES['images_description']['error'][0] !== UPLOAD_ERR_NO_FILE) {
                     error_log("Début traitement images secondaires pour bien ID: " . $idBienPost);
                    // 1. Récupérer et Supprimer anciennes images secondaires (serveur + BDD)
                    $stmt_g = $pdo->prepare("SELECT nom_fichier FROM images_description_bien WHERE id_bien = :idb"); $stmt_g->execute([':idb' => $idBienPost]); $oldS = $stmt_g->fetchAll(PDO::FETCH_COLUMN);
                    if($oldS) {
                         error_log("Anciennes images secondaires trouvées (" . count($oldS) . "): " . implode(', ', $oldS));
                        foreach($oldS as $o) { if (!empty($o) && file_exists($uploadDirServer.$o)) { if(@unlink($uploadDirServer.$o)){ error_log("Ancienne img sec SUPPRIMÉE: ".$uploadDirServer.$o);} else {error_log("ERREUR suppr ancienne img sec: ".$uploadDirServer.$o);}} }
                        $stmt_d = $pdo->prepare("DELETE FROM images_description_bien WHERE id_bien = :idb"); $stmt_d->execute([':idb' => $idBienPost]);
                         error_log("Entrées BDD anciennes images secondaires supprimées pour bien ID: " . $idBienPost . ". RowCount: " . $stmt_d->rowCount());
                    } else {
                         error_log("Aucune ancienne image secondaire trouvée pour bien ID: " . $idBienPost);
                    }

                    // 2. Insérer nouvelles images secondaires
                    $sql_i = "INSERT INTO images_description_bien (id_bien, nom_fichier) VALUES (:idb, :nf)"; $stmt_i = $pdo->prepare($sql_i); $upS=0; $errS=[];
                    for ($i = 0; $i < count($_FILES['images_description']['name']) && $upS < 5; $i++) {
                        if ($_FILES['images_description']['error'][$i] === UPLOAD_ERR_OK) {
                            $isec = ['n'=>$_FILES['images_description']['name'][$i],'t'=>$_FILES['images_description']['tmp_name'][$i],'s'=>$_FILES['images_description']['size'][$i]]; $maxS=2*1024*1024; $aT=['image/jpeg','image/png','image/gif'];
                            $fi=finfo_open(FILEINFO_MIME_TYPE); $mi=finfo_file($fi,$isec['t']); finfo_close($fi);
                            if($isec['s']<=$maxS && in_array($mi,$aT)){
                                $exS=strtolower(pathinfo($isec['n'],PATHINFO_EXTENSION)); $iSn='bien_sec_'.$idBienPost.'_'.time().'_'.bin2hex(random_bytes(3)).$i.'.'.$exS; $upS_path=$uploadDirServer.$iSn;
                                if(move_uploaded_file($isec['t'],$upS_path)){
                                    $stmt_i->execute([':idb'=>$idBienPost,':nf'=>$iSn]); $upS++;
                                    error_log("Image secondaire uploadée et insérée: $upS_path");
                                } else { $errS[]="Err up Srv: ".htmlspecialchars($isec['n']); error_log("Echec move_uploaded_file img sec: ".$upS_path); }
                            } else { $errS[]="Ign S (taille/fmt): ".htmlspecialchars($isec['n']); error_log("Img sec ignorée (taille/format): ".htmlspecialchars($isec['n'])); }
                        } elseif ($_FILES['images_description']['error'][$i]!==UPLOAD_ERR_NO_FILE) { $errS[]="Err trans S ".htmlspecialchars($_FILES['images_description']['name'][$i])." (Code: ".$_FILES['images_description']['error'][$i].")"; error_log("Erreur transfert img sec. Code: ".$_FILES['images_description']['error'][$i]);}
                    }
                    if(!empty($errS)) $pageAlerts[]=['type'=>'warning','message'=>"Attention (images secondaires):<br>".implode('<br>',$errS)];
                 } else {
                      error_log("Aucune nouvelle image secondaire à traiter pour bien ID: " . $idBienPost);
                 }
                $pdo->commit();
                error_log("Transaction BDD COMMIT pour save_bien ID: " . $idBienPost);
                $modalData = []; // Vider données si succès

            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("PDO save_bien TX Exception - ROLLBACK: ".$e->getMessage() . " pour bien ID: " . ($idBienPost ?: 'NOUVEAU'));
                $pageAlerts[] = ['type' => 'danger', 'message' => 'Erreur serveur lors de l\'enregistrement en base de données.'];
                // Nettoyer nouvelle image principale si rollback
                if ($newImageUploaded && $imageProfilName && file_exists($uploadDirServer.$imageProfilName)) {
                    @unlink($uploadDirServer.$imageProfilName);
                    error_log("Image principale uploadée nettoyée suite au rollback: " . $uploadDirServer.$imageProfilName);
                }
                // Note: on ne nettoie pas les images secondaires ici, c'est plus complexe
            } catch (Exception $e) { // Attraper d'autres erreurs potentielles
                 if ($pdo->inTransaction()) $pdo->rollBack();
                  error_log("Exception générale save_bien - ROLLBACK si applicable: ".$e->getMessage());
                 $pageAlerts[] = ['type' => 'danger', 'message' => 'Erreur serveur inattendue lors de l\'enregistrement.'];
                 if ($newImageUploaded && $imageProfilName && file_exists($uploadDirServer.$imageProfilName)) @unlink($uploadDirServer.$imageProfilName);
            }
        } else {
            error_log("Erreurs de validation trouvées avant BDD: " . implode('; ', $errors));
            $modalAlerts = $errors; // Afficher les erreurs dans le modal
        }
    }
}

// --- GESTION AJAX ---
elseif ($isAjaxRequest) {
     ob_end_clean(); ob_start(); // Nettoyer buffer précédent, démarrer un nouveau pour la réponse JSON
     header('Content-Type: application/json');
     $responseAjax = ['success' => false, 'message' => 'Action AJAX inconnue ou erreur serveur.'];
     $requestMethod = $_SERVER['REQUEST_METHOD'];
     $action = $_REQUEST['action'] ?? null; // GET ou POST pour 'action'
      error_log("Requête AJAX reçue: Action=" . ($action ?? 'Non défini') . ", Méthode=" . $requestMethod . ", Propriétaire=" . $idProprietaire);

     try {
         switch ($action) {
             // --- ACTION : GET DETAILS ---
             case 'get_details':
                 if ($requestMethod !== 'GET') throw new Exception("Méthode GET requise.", 405);
                 $idBienAjax = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
                 if (!$idBienAjax) throw new Exception("ID du bien invalide ou manquant.", 400);
                  error_log("AJAX get_details: idBien=" . $idBienAjax);

                 $sql = "SELECT b.*, GROUP_CONCAT(img.nom_fichier SEPARATOR '|||') as images_secondaires_list
                         FROM bienimmobiliers b
                         LEFT JOIN images_description_bien img ON b.idBien = img.id_bien
                         WHERE b.idBien = :idBien AND b.idProprietaire = :idProp
                         GROUP BY b.idBien";
                 $stmt = $pdo->prepare($sql);
                 $stmt->execute([':idBien' => $idBienAjax, ':idProp' => $idProprietaire]);
                 $details = $stmt->fetch(PDO::FETCH_ASSOC);

                 if ($details) {
                      error_log("AJAX get_details: Détails trouvés pour idBien=" . $idBienAjax);
                     $details['images_secondaires'] = !empty($details['images_secondaires_list']) ? explode('|||', $details['images_secondaires_list']) : [];
                     unset($details['images_secondaires_list']);
                     $responseAjax = ['success' => true, 'details' => $details];
                 } else {
                      error_log("AJAX get_details: Bien non trouvé/autorisé pour idBien=" . $idBienAjax . " et idProprietaire=" . $idProprietaire);
                     $responseAjax['message'] = "Bien non trouvé ou non autorisé.";
                     http_response_code(404);
                 }
                 break;

             // --- ACTION : DELETE BIEN ---
             case 'delete_bien':
                 if ($requestMethod !== 'POST') throw new Exception("Méthode POST requise.", 405);
                 $idBienDelete = isset($_POST['id']) ? filter_var($_POST['id'], FILTER_VALIDATE_INT) : null;
                 if (!$idBienDelete) throw new Exception("ID du bien invalide ou manquant.", 400);
                  error_log("AJAX delete_bien: Tentative pour idBien=" . $idBienDelete . " par idProprietaire=" . $idProprietaire);

                 $pdo->beginTransaction();
                 try {
                     // Vérification statut 'Libre' ET absence de contrat 'Actif'
                     $sql_check = "SELECT b.image_profil, b.statut,
                                   (SELECT COUNT(c.idContrat) FROM contrat c WHERE c.idBien = b.idBien AND c.statutContrat = 'Actif') as contratActifCount
                                   FROM bienimmobiliers b
                                   WHERE b.idBien = :idBien AND b.idProprietaire = :idProp";
                     $stmt_check = $pdo->prepare($sql_check);
                     $stmt_check->execute([':idBien' => $idBienDelete, ':idProp' => $idProprietaire]);
                     $row_check = $stmt_check->fetch(PDO::FETCH_ASSOC);

                     if (!$row_check) {
                         error_log("AJAX delete_bien: ECHEC - Bien non trouvé/autorisé (ID: $idBienDelete, Prop: $idProprietaire)");
                         throw new Exception("Bien non trouvé ou non autorisé.", 404);
                     }
                     if ($row_check['statut'] !== 'Libre') {
                         error_log("AJAX delete_bien: ECHEC - Statut non Libre (ID: $idBienDelete, Statut: " . $row_check['statut'] . ")");
                         throw new Exception("Suppression impossible : le bien doit être 'Libre' (statut actuel : '" . htmlspecialchars($row_check['statut']) . "').", 403); // 403 Forbidden
                     }
                     if ($row_check['contratActifCount'] > 0) {
                          error_log("AJAX delete_bien: ECHEC - Contrat actif trouvé (ID: $idBienDelete, Count: " . $row_check['contratActifCount'] . ")");
                         throw new Exception("Suppression impossible: un contrat actif est toujours associé à ce bien.", 403); // 403 Forbidden
                     }
                      error_log("AJAX delete_bien: Vérifications OK pour ID: $idBienDelete");

                     // Suppression Images (serveur + BDD)
                     $imgP = $row_check['image_profil'];
                     if ($imgP && file_exists($uploadDirServer . $imgP)) {
                         if(@unlink($uploadDirServer . $imgP)) {error_log("AJAX delete_bien: Image principale supprimée: ".$uploadDirServer.$imgP);} else {error_log("AJAX delete_bien: ERREUR suppression image principale: ".$uploadDirServer.$imgP);}
                     }
                     $sql_imgs = "SELECT nom_fichier FROM images_description_bien WHERE id_bien = :idBien";
                     $stmt_imgs = $pdo->prepare($sql_imgs);
                     $stmt_imgs->execute([':idBien' => $idBienDelete]);
                     $deletedSecCount = 0;
                     while ($imgSecName = $stmt_imgs->fetchColumn()) {
                         if (!empty($imgSecName) && file_exists($uploadDirServer . $imgSecName)) {
                            if(@unlink($uploadDirServer . $imgSecName)) {$deletedSecCount++;} else {error_log("AJAX delete_bien: ERREUR suppression image secondaire: ".$uploadDirServer.$imgSecName);}
                         }
                     }
                      error_log("AJAX delete_bien: $deletedSecCount image(s) secondaire(s) supprimée(s) du serveur pour ID: $idBienDelete");
                     $sql_del_desc = "DELETE FROM images_description_bien WHERE id_bien = :idBien";
                     $stmt_del_desc = $pdo->prepare($sql_del_desc);
                     $stmt_del_desc->execute([':idBien' => $idBienDelete]);
                      error_log("AJAX delete_bien: Entrées BDD images secondaires supprimées pour ID: $idBienDelete. RowCount: " . $stmt_del_desc->rowCount());

                     // Suppression Bien
                     $sql_del_bien = "DELETE FROM bienimmobiliers WHERE idBien = :idBien AND idProprietaire = :idProp";
                     $stmt_del_bien = $pdo->prepare($sql_del_bien);
                     $stmt_del_bien->execute([':idBien' => $idBienDelete, ':idProp' => $idProprietaire]);

                     if ($stmt_del_bien->rowCount() > 0) {
                         $pdo->commit();
                         error_log("AJAX delete_bien: SUCCES et COMMIT pour ID: $idBienDelete");
                         $responseAjax = ['success' => true, 'message' => "Bien #{$idBienDelete} supprimé avec succès."];
                     } else {
                         // Ne devrait pas arriver si la vérification initiale a réussi, sauf condition de concurrence
                         error_log("AJAX delete_bien: ECHEC suppression BDD (rowCount=0) après vérifications OK pour ID: $idBienDelete");
                         throw new Exception("Echec de la suppression en base de données (le bien a peut-être été supprimé simultanément?).", 500);
                     }
                 } catch (Exception $e) { // Attrape les exceptions de la transaction (y compris celles qu'on a lancées)
                     $pdo->rollBack();
                     error_log("AJAX delete_bien: ROLLBACK suite à Exception: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
                     // Remonter l'exception pour la gestion globale
                     throw $e;
                 }
                 break;

             default:
                 error_log("AJAX Erreur: Action inconnue reçue: " . ($action ?? 'NULL'));
                 $responseAjax['message'] = 'Action AJAX non reconnue.';
                 http_response_code(400); // Bad Request
                 break;
         }
     } catch (PDOException $e) { // Erreurs BDD globales pour AJAX
         if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
         error_log("AJAX PDOException: " . $e->getMessage());
         $responseAjax['success'] = false;
         $responseAjax['message'] = "Erreur de base de données lors de l'opération.";
         http_response_code(500); // Internal Server Error
     } catch (Exception $e) { // Autres erreurs globales pour AJAX
         if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack(); // Assurer rollback si besoin
         error_log("AJAX Exception: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
         $responseAjax['success'] = false;
         $responseAjax['message'] = $e->getMessage(); // Renvoyer le message d'erreur spécifique
         // Utiliser le code d'erreur s'il est valide (4xx, 5xx), sinon 500 par défaut
         $httpCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
         http_response_code($httpCode);
     }

     // Nettoyer le buffer AJAX et envoyer la réponse JSON
     if (isset($pdo)) $pdo = null; // Fermer la connexion pour la réponse AJAX
     ob_end_clean(); // Nettoie le buffer démarré au début du bloc AJAX
     echo json_encode($responseAjax);
     exit; // Arrêter le script après réponse AJAX
}

// --- CHARGEMENT INITIAL DES BIENS ---
$biens_proprietaire = [];
// Éviter de recharger si on vient d'une action POST réussie sans erreurs de validation modal
$loadInitial = !($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'save_bien' && empty($modalAlerts));

if ($loadInitial) {
    error_log("Chargement initial des biens pour idProprietaire: " . $idProprietaire);
    try {
        // Sélectionner toutes les colonnes nécessaires pour l'affichage
        $sql_get_biens = "SELECT idBien, adresse, statut, loyerMensuel, image_profil, supervisionStatut
                          FROM bienimmobiliers
                          WHERE idProprietaire = :idProp
                          ORDER BY idBien DESC";
        $stmt_get_biens = $pdo->prepare($sql_get_biens);
        $stmt_get_biens->bindParam(':idProp', $idProprietaire, PDO::PARAM_INT);
        $stmt_get_biens->execute();
        $biens_proprietaire = $stmt_get_biens->fetchAll(PDO::FETCH_ASSOC);
        error_log("Chargement initial: " . count($biens_proprietaire) . " biens trouvés.");
    } catch (PDOException $e) {
        $pageAlerts[] = ['type' => 'danger', 'message' => 'Erreur lors du chargement initial des biens.'];
        error_log("PDO get_biens init Exception: " . $e->getMessage());
    }
} else {
     error_log("Chargement initial SKIP (venant d'un POST save_bien avec erreurs modal ou succès)");
}

// Fermer la connexion PDO pour la partie rendu HTML (si elle n'a pas déjà été fermée par AJAX)
if (isset($pdo)) $pdo = null;
ob_end_flush(); // Envoyer le contenu HTML bufferisé (tout ce qui précède <!DOCTYPE html>)
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <title>Mes Biens Immobiliers | Propriétaire</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <!-- CSS -->
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/sweetalert2/sweetalert2.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" id="app-style"/>
    <!-- Config JS doit être chargé avant les autres JS de l'app -->
    <script src="assets/js/config.min.js"></script>
    <style>
         /* Styles */
         .table th, .table td { vertical-align: middle; font-size: 0.85rem; padding: 0.5rem; } .table th { font-weight: 600; white-space: nowrap; } .img-thumbnail-small { max-width: 70px; max-height: 45px; width: auto; height: auto; object-fit: cover; display: block; margin: auto; } td:first-child { width: 80px; text-align: center; } .preview-image { max-width: 100px; max-height: 100px; margin: 5px; border: 1px solid #ddd; padding: 2px; object-fit: cover; } #previewImagesDescriptionContainer img { margin-right: 5px; margin-bottom: 5px; } .invalid-feedback { display: none; width: 100%; margin-top: .25rem; font-size: .875em; color: #dc3545; } .form-control.is-invalid ~ .invalid-feedback, .form-select.is-invalid ~ .invalid-feedback, .was-validated .form-control:invalid ~ .invalid-feedback, .was-validated .form-select:invalid ~ .invalid-feedback { display: block; }
         .btn[disabled]{ cursor: not-allowed; opacity: 0.65; }
         .btn[disabled][title]:hover::after { content: attr(title); position: absolute; left: 50%; transform: translateX(-50%); bottom: 100%; margin-bottom: 5px; background: rgba(0,0,0,0.8); color: white; padding: 4px 8px; border-radius: 3px; font-size: 0.8em; white-space: nowrap; z-index: 1070; }
         #detailsBienContenu dl dt { font-weight: 600; color: var(--bs-primary); padding-top: 0.4rem; }
         #detailsBienContenu dl dd { margin-left: 0; padding-left: 0.5em; margin-bottom: 0.4rem; border-bottom: 1px dashed #eee; padding-bottom: 0.4rem; word-break: break-word; } /* Ajout word-break */
         #detailsBienContenu dl dd:last-of-type { border-bottom: none; }
         #detailsBienContenu h6 { margin-top: 1rem; margin-bottom: 0.5rem; color: var(--bs-primary); border-bottom: 1px solid var(--bs-primary); padding-bottom: 0.25rem; }
         .img-detail-main { max-height: 300px; width: auto; max-width: 100%; display: block; margin-bottom: 1rem; border: 1px solid #dee2e6; padding: 0.25rem; border-radius: 0.25rem;}
         .img-detail-secondary { max-height: 100px; width: auto; border: 1px solid #dee2e6; padding: 0.25rem; border-radius: 0.25rem; margin-right: 0.5rem; margin-bottom: 0.5rem; cursor: pointer;} /* Ajout cursor pointer */
    </style>
</head>
<body>
     <div class="wrapper">
        <!-- ========== Topbar, Menu (COLLER VOTRE HTML ICI) ========== -->
        <header class=""> <div class="topbar"> <div class="container-fluid"> <div class="navbar-header"> <div class="d-flex align-items-center gap-2"> <div class="topbar-item"><button type="button" class="button-toggle-menu topbar-button"><i class="ri-menu-2-line fs-24"></i></button></div> <form class="app-search d-none d-md-block me-auto"><div class="position-relative"><input type="search" class="form-control border-0" placeholder="Rechercher..." autocomplete="off" value=""><i class="ri-search-line search-widget-icon"></i></div></form> </div> <div class="d-flex align-items-center gap-1"> <div class="topbar-item"><button type="button" class="topbar-button" id="light-dark-mode"><i class="ri-moon-line fs-24 light-mode"></i><i class="ri-sun-line fs-24 dark-mode"></i></button></div> <div class="dropdown topbar-item d-none d-lg-flex"><button type="button" class="topbar-button" data-toggle="fullscreen"><i class="ri-fullscreen-line fs-24 fullscreen"></i><i class="ri-fullscreen-exit-line fs-24 quit-fullscreen"></i></button></div> <div class="dropdown topbar-item"> <button type="button" class="topbar-button position-relative" id="page-header-notifications-dropdown" data-bs-toggle="dropdown"><i class="ri-notification-3-line fs-24"></i><span class="position-absolute topbar-badge fs-10 translate-middle badge bg-danger rounded-pill">0<span class="visually-hidden">unread</span></span></button> <div class="dropdown-menu py-0 dropdown-lg dropdown-menu-end"></div> </div> <div class="topbar-item d-none d-md-flex"><button type="button" class="topbar-button" id="theme-settings-btn" data-bs-toggle="offcanvas" data-bs-target="#theme-settings-offcanvas"><i class="ri-settings-4-line fs-24"></i></button></div> <div class="dropdown topbar-item"> <a type="button" class="topbar-button" id="page-header-user-dropdown" data-bs-toggle="dropdown"><span class="d-flex align-items-center"><img class="rounded-circle" width="32" src="assets/images/users/avatar-proprietaire.png" alt="proprio"></span></a> <div class="dropdown-menu dropdown-menu-end"> <h6 class="dropdown-header">Bienvenue <?php echo htmlspecialchars($_SESSION['user_prenom'] ?? 'Propriétaire'); ?> !</h6> <a class="dropdown-item" href="#profil"><i class="ri-user-line me-1"></i> Profil</a> <div class="dropdown-divider my-1"></div> <a class="dropdown-item text-danger" href="auth-signout.php"><i class="ri-logout-box-line me-1"></i> Déconnexion</a> </div> </div> </div> </div> </div> </div> </header>
        <div> <div class="offcanvas offcanvas-end border-0 rounded-start-4 overflow-hidden" tabindex="-1" id="theme-settings-offcanvas"> <div class="d-flex align-items-center bg-primary p-3 offcanvas-header"><h5 class="text-white m-0">Theme Settings</h5><button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="offcanvas"></button></div><div class="offcanvas-body p-0"><div data-simplebar class="h-100"><div class="p-3 settings-bar"></div></div></div><div class="offcanvas-footer border-top p-3 text-center"><button type="button" class="btn btn-danger w-100" id="reset-layout">Reset</button></div> </div> </div>
        <div class="main-nav">
        <div class="logo-box"><a href="proprietaire-dashboard.php" class="logo-dark"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-dark.png" class="logo-lg"></a><a href="proprietaire-dashboard.php" class="logo-light"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-light.png" class="logo-lg"></a></div> <button type="button" class="button-sm-hover"><i class="ri-menu-2-line fs-24 button-sm-hover-icon"></i></button> <div class="scrollbar" data-simplebar> <ul class="navbar-nav" id="navbar-nav"> <li class="menu-title">Menu Propriétaire</li> <li class="nav-item"><a class="nav-link menu-link" href="proprietaire-dashboard.php"><i class="ri-dashboard-2-line"></i><span>Tableau de Bord</span></a></li> <li class="nav-item"><a class="nav-link menu-link active" href="proprietaire-mes-biens.php"><i class="ri-community-line"></i><span>Mes Biens</span></a></li> <li class="nav-item"><a class="nav-link menu-link" href="proprietaire-finances.php"><i class="ri-money-dollar-circle-line"></i><span>Finances</span></a></li> <li class="nav-item"><a class="nav-link menu-link" href="proprietaire-contrats.php"><i class="ri-file-list-3-line"></i><span>Contrats</span></a></li> <li class="nav-item"><a class="nav-link menu-link" href="proprietaire-notifications.php"><i class="ri-notification-3-line"></i><span>Notifications</span></a></li> </ul> </div> </div>
        <!-- ========== Fin HTML Intégré ========== -->

        <div class="page-content">
            <div class="container-fluid">
                <!-- Titre & Breadcrumb -->
                <div class="row"> <div class="col-12"> <div class="page-title-box">
                    <div class="page-title-right"> <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalAjoutModifBien" onclick="prepareAddModal()"> <i class="ri-add-line me-1 align-middle"></i> Ajouter un Bien </button> </div>
                    <h4 class="page-title">Mes Biens Immobiliers</h4> <ol class="breadcrumb m-0"> <li class="breadcrumb-item"><a href="proprietaire-dashboard.php">Propriétaire</a></li> <li class="breadcrumb-item active">Mes Biens</li> </ol>
                </div> </div> </div>

                 <!-- Alertes Page -->
                 <div id="pageAlertPlaceholder"> <?php foreach ($pageAlerts as $alert): ?> <div class="alert alert-<?php echo htmlspecialchars($alert['type']); ?> alert-dismissible fade show" role="alert"> <?php echo $alert['message']; /* Message peut contenir HTML simple comme <br> */ ?> <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button> </div> <?php endforeach; ?> </div>

                 <!-- Tableau des Biens -->
                 <div class="row"> <div class="col-12"> <div class="card shadow-sm">
                     <div class="card-header bg-light py-2"> <h5 class="card-title mb-0 fs-15">Liste de toutes mes propriétés</h5> </div>
                     <div class="card-body p-0"> <div class="table-responsive">
                         <table id="tableBiens" class="table table-sm table-hover table-centered table-striped mb-0">
                             <thead class="table-light"> <tr> <th>Image</th> <th>Adresse</th> <th>Statut Loc.</th> <th>Loyer</th> <th>Validation Admin</th> <th style="width: 160px;">Actions</th> </tr> </thead>
                             <tbody id="tableBiensBody">
                                 <!-- Message si aucun bien -->
                                 <tr id="noResultsRow" <?php echo !empty($biens_proprietaire) ? 'style="display: none;"' : ''; ?>> <td colspan="6" class="text-center text-muted py-3">Vous n'avez pas encore ajouté de bien. <button type="button" class="btn btn-link btn-sm p-0" data-bs-toggle="modal" data-bs-target="#modalAjoutModifBien" onclick="prepareAddModal()">Ajouter maintenant</button></td> </tr>
                                  <!-- Boucle PHP (Logique inchangée) -->
                                  <?php foreach ($biens_proprietaire as $bien):
                                       $statutLocBadgeClass = $bien['statut'] === 'Libre' ? 'bg-success-lighten text-success' : ($bien['statut'] === 'Occupé' ? 'bg-warning-lighten text-warning' : 'bg-secondary-lighten text-secondary');
                                       $statutSupBadgeClass = 'bg-secondary-lighten text-secondary'; $statutSupText = htmlspecialchars($bien['supervisionStatut']);
                                       if ($bien['supervisionStatut'] === 'En attente') $statutSupBadgeClass = 'bg-info-lighten text-info';
                                       elseif ($bien['supervisionStatut'] === 'Validé') $statutSupBadgeClass = 'bg-success-lighten text-success';
                                       elseif ($bien['supervisionStatut'] === 'Suspendu') $statutSupBadgeClass = 'bg-danger-lighten text-danger';
                                       $imagePath = !empty($bien['image_profil']) && file_exists($uploadDirServer . $bien['image_profil']) ? $uploadDirRelative . htmlspecialchars($bien['image_profil']) : $defaultImage;
                                       $loyerFormatte = number_format($bien['loyerMensuel'] ?? 0, 0, ',', ' ') . ' F';
                                       $isBienLibre = ($bien['statut'] === 'Libre');
                                       $adressePourJS = htmlspecialchars(addslashes($bien['adresse'] ?? 'Bien sans adresse')); // Préparer pour JS
                                  ?>
                                  <tr id="bienRow-<?php echo $bien['idBien']; ?>">
                                       <td><img src="<?php echo $imagePath; ?>" alt="Aperçu" class="img-thumbnail-small" onerror="this.onerror=null; this.src='<?php echo $defaultImage; ?>';"></td>
                                       <td><?php echo htmlspecialchars($bien['adresse']); ?></td>
                                       <td><span class="badge <?php echo $statutLocBadgeClass; ?>"><?php echo htmlspecialchars($bien['statut']); ?></span></td>
                                       <td><?php echo $loyerFormatte; ?></td>
                                       <td><span class="badge <?php echo $statutSupBadgeClass; ?>"><?php echo $statutSupText; ?></span></td>
                                       <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-soft-primary" onclick="viewBienDetails(<?php echo $bien['idBien']; ?>)" title="Voir Détails"><i class="ri-eye-line"></i></button>
                                                <?php if ($isBienLibre): ?>
                                                    <button type="button" class="btn btn-soft-warning" data-bs-toggle="modal" data-bs-target="#modalAjoutModifBien" onclick="prepareEditModal(<?php echo $bien['idBien']; ?>)" title="Modifier"><i class="ri-pencil-line"></i></button>
                                                    <button type="button" class="btn btn-soft-danger" onclick="confirmDeleteBien(<?php echo $bien['idBien']; ?>, '<?php echo $adressePourJS; ?>')" title="Supprimer"><i class="ri-delete-bin-line"></i></button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-soft-warning" disabled title="Modification impossible (Bien <?php echo htmlspecialchars(strtolower($bien['statut'])); ?>)"><i class="ri-pencil-line"></i></button>
                                                    <button type="button" class="btn btn-soft-danger" disabled title="Suppression impossible (Bien <?php echo htmlspecialchars(strtolower($bien['statut'])); ?>)"><i class="ri-delete-bin-line"></i></button>
                                                <?php endif; ?>
                                             </div>
                                        </td>
                                  </tr>
                                 <?php endforeach; ?>
                             </tbody>
                         </table>
                     </div> </div>
                 </div> </div> </div>
            </div> <!-- container-fluid -->
             <!-- Footer -->
             <footer class="footer"> <div class="container-fluid"> <div class="row"> <div class="col-12 text-center"> <script>document.write(new Date().getFullYear())</script> © VotreNom GLN. </div> </div> </div> </footer>
        </div> <!-- page-content -->
     </div> <!-- wrapper -->

     <!-- Modals (HTML inchangé) -->
      <div class="modal fade" id="modalAjoutModifBien" tabindex="-1" aria-labelledby="modalAjoutModifBienLabel" aria-hidden="true" data-bs-backdrop="static">
          <div class="modal-dialog modal-lg modal-dialog-scrollable"> <div class="modal-content">
              <div class="modal-header bg-primary text-white"> <h5 class="modal-title" id="modalAjoutModifBienLabel">Ajouter un Bien</h5> <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button> </div>
              <div class="modal-body">
                  <div id="modalFormAlertPlaceholder"></div>
                  <form id="formBien" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" enctype="multipart/form-data" novalidate> <input type="hidden" name="action" value="save_bien"> <input type="hidden" id="idBien" name="idBien" value=""> <input type="hidden" name="idProprietaire" value="<?php echo htmlspecialchars($idProprietaire); ?>">
                      <div class="mb-3"> <label for="adresseBien" class="form-label">Adresse Complète <span class="text-danger">*</span></label> <textarea class="form-control form-control-sm" id="adresseBien" name="adresse" rows="2" required><?php echo htmlspecialchars($modalData['adresse'] ?? ''); ?></textarea><div class="invalid-feedback">L'adresse complète est requise.</div></div>
                      <div class="row g-3">
                           <div class="col-md-6 mb-2"> <label for="loyerMensuelBien" class="form-label">Loyer Mensuel <span class="text-danger">*</span></label> <div class="input-group input-group-sm"><input type="number" class="form-control" id="loyerMensuelBien" name="loyerMensuel" required min="1" step="1" value="<?php echo htmlspecialchars($modalData['loyerMensuel'] ?? ''); ?>"><span class="input-group-text">FCFA</span><div class="invalid-feedback">Le loyer mensuel (positif) est requis.</div></div></div>
                           <div class="col-md-6 mb-2"> <label for="imageProfilBien" class="form-label">Image Principale</label> <input class="form-control form-control-sm" type="file" id="imageProfilBien" name="image_profil" accept="image/png, image/jpeg, image/gif" onchange="previewMainImage(event)"><div class="invalid-feedback">Format (JPG,PNG,GIF) ou Taille (>2Mo) invalide.</div></div>
                      </div>
                      <div class="text-center my-2"> <img id="previewImageProfil" src="#" alt="Aperçu Principal" class="preview-image" style="display: none; max-height: 150px;" /> </div>
                      <div class="mb-2"> <label for="imagesDescriptionBien" class="form-label">Images Secondaires (Optionnel, max 5)</label> <input class="form-control form-control-sm" type="file" id="imagesDescriptionBien" name="images_description[]" accept="image/png, image/jpeg, image/gif" multiple onchange="previewSecondaryImages(event)"><div class="invalid-feedback">Limite(5), Format ou Taille(>2Mo/img) invalide.</div></div>
                      <div class="mt-2 d-flex flex-wrap justify-content-center" id="previewImagesDescriptionContainer"></div>
                  </form>
              </div>
              <div class="modal-footer"> <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button> <button type="submit" form="formBien" class="btn btn-primary btn-sm" id="btnSubmitBien"><span class="button-text">Enregistrer</span></button> </div>
          </div> </div>
      </div>
      <div class="modal fade" id="modalDetailsBien" tabindex="-1" aria-labelledby="modalDetailsBienLabel" aria-hidden="true">
          <div class="modal-dialog modal-xl modal-dialog-scrollable"> <div class="modal-content">
              <div class="modal-header bg-info text-white"> <h5 class="modal-title" id="modalDetailsBienLabel">Détails du Bien</h5> <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button> </div>
              <div class="modal-body" id="detailsBienContenu"><p class="text-center py-5"><span class="spinner-border text-info"></span> Chargement...</p></div>
              <div class="modal-footer"> <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button> </div>
          </div> </div>
      </div>
      <!-- Modal pour visionneuse d'images -->
      <div class="modal fade" id="imageViewerModal" tabindex="-1" aria-labelledby="imageViewerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
          <div class="modal-content bg-transparent border-0">
            <div class="modal-header border-0">
                <h5 class="modal-title text-white" id="imageViewerModalLabel">Image</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-0">
              <img src="" class="img-fluid" id="imageViewerContent" alt="Image en grand" style="max-height: 80vh;">
            </div>
          </div>
        </div>
      </div>


     <!-- Scripts JS -->
     <script src="assets/js/vendor.js"></script>
     <script src="assets/libs/sweetalert2/sweetalert2.all.min.js"></script>
     <!-- app.js doit être chargé APRES vendor.js -->
     <script src="assets/js/app.js"></script>
     <!-- Script Page Spécifique -->
     <script src="assets/js/vendor.js"></script>
     <script src="assets/libs/sweetalert2/sweetalert2.all.min.js"></script>
     <!-- app.js doit être chargé APRES vendor.js -->
     <script src="assets/js/app.js"></script>
     <!-- Script Page Spécifique -->
     <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- Références DOM ---
            const biensTableBodyJS = document.getElementById('tableBiensBody');
            const noResultsRowJS = document.getElementById('noResultsRow');
            const modalAjoutModifElement = document.getElementById('modalAjoutModifBien');
            const modalAjoutModifJS = bootstrap.Modal.getOrCreateInstance(modalAjoutModifElement);
            const formBienJS = document.getElementById('formBien');
            const modalFormAlertPlaceholderJS = document.getElementById('modalFormAlertPlaceholder');
            const pageAlertPlaceholderJS = document.getElementById('pageAlertPlaceholder');
            const modalDetailsBienElement = document.getElementById('modalDetailsBien');
            const modalDetailsBienJS = bootstrap.Modal.getOrCreateInstance(modalDetailsBienElement);
            const detailsBienContenuJS = document.getElementById('detailsBienContenu');
            const uploadDirJS = '<?php echo $uploadDirRelative; ?>';
            const defaultImgJS = '<?php echo $defaultImage; ?>';
            const imageViewerModalEl = document.getElementById('imageViewerModal');
            const imageViewerModal = bootstrap.Modal.getOrCreateInstance(imageViewerModalEl);
            const imageViewerContent = document.getElementById('imageViewerContent');
            const imageViewerLabel = document.getElementById('imageViewerModalLabel');


            // --- Fonctions Utilitaires ---
            function showAlert(placeholder, message, type = 'danger') {
                if(!placeholder) { console.error("Placeholder pour alerte non trouvé!"); return; }
                const wrapper = document.createElement('div');
                wrapper.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show mb-2" role="alert"><div>${message}</div><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>`;
                placeholder.innerHTML = '';
                placeholder.append(wrapper.firstChild);
            }
            function clearAlert(placeholder) { if(placeholder) placeholder.innerHTML = ''; }
            function numberFormat(number, decimals = 0, dec_point = ',', thousands_sep = ' ') { /* ... code number format ... */ number = (number + '').replace(/[^0-9+\-Ee.]/g, ''); const n = !isFinite(+number) ? 0 : +number; const prec = !isFinite(+decimals) ? 0 : Math.abs(decimals); const sep = (typeof thousands_sep === 'undefined') ? ',' : thousands_sep; const dec = (typeof dec_point === 'undefined') ? '.' : dec_point; let s = ''; const toFixedFix = function (n, prec) { const k = Math.pow(10, prec); return '' + Math.round(n * k) / k; }; s = (prec ? toFixedFix(n, prec) : '' + Math.round(n)).split('.'); if (s[0].length > 3) { s[0] = s[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, sep); } if ((s[1] || '').length < prec) { s[1] = s[1] || ''; s[1] += new Array(prec - s[1].length + 1).join('0'); } return s.join(dec); }
            function checkIfTableIsEmpty() { if(noResultsRowJS && biensTableBodyJS) noResultsRowJS.style.display = biensTableBodyJS.querySelectorAll('tr:not(#noResultsRow)').length === 0 ? 'table-row' : 'none'; }
            function clearValidationErrors(form) { if(form) { form.classList.remove('was-validated'); form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid')); } }
            function clearPreviewsAndErrors() { /* ... code clear previews ... */ const previewProfil = document.getElementById('previewImageProfil'); const previewContainer = document.getElementById('previewImagesDescriptionContainer'); const inputProfil = document.getElementById('imageProfilBien'); const inputDesc = document.getElementById('imagesDescriptionBien'); if (previewProfil) { previewProfil.style.display = 'none'; previewProfil.src = '#'; } if (previewContainer) { previewContainer.innerHTML = ''; } if (inputProfil) { inputProfil.value = ''; inputProfil.classList.remove('is-invalid'); } if (inputDesc) { inputDesc.value = ''; inputDesc.classList.remove('is-invalid'); } if(formBienJS) clearValidationErrors(formBienJS); }
            function previewMainImage(event) { /* ... code preview main image ... */ const fileInput = event.target; const file = fileInput.files[0]; const preview = document.getElementById('previewImageProfil'); const feedback = fileInput.nextElementSibling; const maxSize = 2 * 1024 * 1024; const allowedTypes = ['image/jpeg', 'image/png', 'image/gif']; fileInput.classList.remove('is-invalid'); if (feedback) feedback.style.display = 'none'; if (file && preview) { if (!allowedTypes.includes(file.type)) { fileInput.classList.add('is-invalid'); if(feedback) feedback.textContent = 'Format invalide (JPG, PNG, GIF).'; preview.style.display = 'none'; preview.src = '#'; fileInput.value = ''; return; } if (file.size > maxSize) { fileInput.classList.add('is-invalid'); if(feedback) feedback.textContent = 'Image trop lourde (> 2Mo).'; preview.style.display = 'none'; preview.src = '#'; fileInput.value = ''; return; } const reader = new FileReader(); reader.onload = function(e) { preview.src = e.target.result; preview.style.display = 'block'; } reader.readAsDataURL(file); } else if (preview) { preview.src = '#'; preview.style.display = 'none'; } }
            function previewSecondaryImages(event) { /* ... code preview secondary images ... */ const fileInput = event.target; const container = document.getElementById('previewImagesDescriptionContainer'); const files = fileInput.files; const feedback = fileInput.nextElementSibling; const maxFiles = 5; const maxSizePerFile = 2 * 1024 * 1024; const allowedTypes = ['image/jpeg', 'image/png', 'image/gif']; let validFilesCount = 0; let errors = []; fileInput.classList.remove('is-invalid'); if(feedback) feedback.style.display = 'none'; if (container) container.innerHTML = ''; if (files.length > maxFiles) { errors.push(`Limite de ${maxFiles} images dépassée.`); fileInput.value = ''; } else { for (const file of files) { if (!allowedTypes.includes(file.type)) { errors.push(`Format invalide: ${file.name}`); continue; } if (file.size > maxSizePerFile) { errors.push(`Fichier trop lourd (>2Mo): ${file.name}`); continue; } if(container) { const reader = new FileReader(); reader.onload = function(e) { const img = document.createElement('img'); img.src = e.target.result; img.alt = `Aperçu ${file.name}`; img.classList.add('preview-image'); container.appendChild(img); } reader.readAsDataURL(file); validFilesCount++; } } if (errors.length > 0) { fileInput.classList.add('is-invalid'); if(feedback) feedback.textContent = errors.join(' '); if(container) container.innerHTML = ''; fileInput.value = ''; } } }
            function showLoadingSweetalert(title = 'Traitement...') { Swal.fire({ title: title, html: 'Veuillez patienter...', allowOutsideClick: false, didOpen: () => { Swal.showLoading() } }); }
            // IMPORTANT: Fonction JS pour échapper le HTML (utilisée plus bas)
            function htmlspecialchars(str) { if (typeof str !== 'string') return String(str); const map = { '&': '&', '<': '<', '>': '>', '"': '"', "'": ''' }; return str.replace(/[&<>"']/g, function(m) { return map[m]; }); }

            // --- Préparation Modals ---
            window.prepareAddModal = function() { /* ... code prepare add modal ... */ console.log("Préparation modal AJOUT"); formBienJS.reset(); document.getElementById('idBien').value = ''; document.getElementById('modalAjoutModifBienLabel').textContent = 'Ajouter un Nouveau Bien'; document.getElementById('btnSubmitBien').querySelector('.button-text').textContent = 'Enregistrer'; clearPreviewsAndErrors(); clearAlert(modalFormAlertPlaceholderJS); }
            window.prepareEditModal = function(bienId) { /* ... code prepare edit modal ... */ console.log("Préparation modal MODIFICATION pour Bien ID:", bienId); prepareAddModal(); document.getElementById('idBien').value = bienId; document.getElementById('modalAjoutModifBienLabel').textContent = 'Modifier le Bien #' + bienId; document.getElementById('btnSubmitBien').querySelector('.button-text').textContent = 'Mettre à jour'; showLoadingSweetalert('Chargement des données...'); fetch(`?action=get_details&id=${bienId}`).then(response => { if (!response.ok) { throw new Error(`Erreur réseau ${response.status}`); } return response.json(); }).then(data => { Swal.close(); if (data.success && data.details) { console.log("Détails reçus pour modif:", data.details); const d = data.details; const adrField = document.getElementById('adresseBien'); const loyerField = document.getElementById('loyerMensuelBien'); if(adrField) adrField.value = d.adresse || ''; if(loyerField) loyerField.value = d.loyerMensuel || ''; const imgP = document.getElementById('previewImageProfil'); if(imgP && d.image_profil){ imgP.src = `${uploadDirJS}${htmlspecialchars(d.image_profil)}`; imgP.onerror = function() { this.onerror=null; this.src=defaultImgJS; console.warn("Image principale non trouvée:", this.src); }; imgP.style.display = 'block'; } else if (imgP) { imgP.style.display = 'none'; } const contS = document.getElementById('previewImagesDescriptionContainer'); if (contS) { contS.innerHTML = ''; if(d.images_secondaires && d.images_secondaires.length > 0){ d.images_secondaires.forEach(n => { if(n){ const i = document.createElement('img'); i.src = `${uploadDirJS}${htmlspecialchars(n)}`; i.alt = "Aperçu secondaire"; i.classList.add('preview-image'); i.onerror = function() { this.remove(); console.warn("Image secondaire non trouvée:", this.src); }; contS.appendChild(i); } }); } } } else { console.error("Erreur chargement détails pour modif:", data.message); showAlert(modalFormAlertPlaceholderJS, data.message || "Erreur lors du chargement des détails.", 'danger'); } }).catch(error => { Swal.close(); console.error('Erreur fetch pour modif:', error); showAlert(modalFormAlertPlaceholderJS, `Erreur réseau ou serveur (${error.message}).`, 'danger'); }); }

            // --- Voir Détails ---
            window.viewBienDetails = async function(bienId) { /* ... code view details ... */ console.log("Affichage détails pour Bien ID:", bienId); if (!bienId) return; detailsBienContenuJS.innerHTML = '<p class="text-center py-5"><span class="spinner-border text-info"></span> Chargement...</p>'; modalDetailsBienJS.show(); try { const response = await fetch(`?action=get_details&id=${bienId}`); if (!response.ok) { throw new Error(`Erreur réseau ${response.status}`); } const data = await response.json(); if (!data.success || !data.details) { throw new Error(data.message || "Données non reçues."); } console.log("Détails reçus pour affichage:", data.details); const d = data.details; const detailRow = (label, value, isHtml = false) => { const displayValue = (value === null || value === undefined || String(value).trim() === '') ? '<span class="text-muted fst-italic">Non spécifié</span>' : (isHtml ? value : htmlspecialchars(value)); return `<dt class="col-sm-4 col-lg-3">${htmlspecialchars(label)}</dt><dd class="col-sm-8 col-lg-9">${displayValue}</dd>`; }; const createBadge = (text, type) => `<span class="badge bg-${type}-lighten text-${type}">${htmlspecialchars(text)}</span>`; let detailsHtml = '<h6>Informations Générales</h6><dl class="row">'; detailsHtml += detailRow('ID Bien', d.idBien); detailsHtml += detailRow('Adresse', d.adresse); detailsHtml += detailRow('Loyer Mensuel', (d.loyerMensuel ? numberFormat(d.loyerMensuel) + ' FCFA' : '')); let statutLocClass = d.statut === 'Libre' ? 'success' : (d.statut === 'Occupé' ? 'warning' : 'secondary'); detailsHtml += detailRow('Statut Location', createBadge(d.statut || 'Inconnu', statutLocClass), true); let statutSupClass = d.supervisionStatut === 'Validé' ? 'success' : (d.supervisionStatut === 'En attente' ? 'info' : (d.supervisionStatut === 'Suspendu' ? 'danger' : 'secondary')); detailsHtml += detailRow('Validation Admin', createBadge(d.supervisionStatut || 'Inconnu', statutSupClass), true); detailsHtml += '</dl>'; detailsHtml += '<hr><h6>Images</h6>'; const mainImageUrl = d.image_profil ? `${uploadDirJS}${htmlspecialchars(d.image_profil)}` : defaultImgJS; detailsHtml += '<div class="row"><div class="col-md-5 text-center"><strong>Principale:</strong><br>'; detailsHtml += `<img src="${mainImageUrl}" alt="Image Principale" class="img-detail-main" style="cursor: pointer;" onclick="openImageViewer('${mainImageUrl}', 'Image Principale')" onerror="this.onerror=null; this.src='${defaultImgJS}'; this.style.cursor='default'; this.onclick=null;">`; detailsHtml += '</div><div class="col-md-7"><strong>Secondaires:</strong><br>'; if (d.images_secondaires && d.images_secondaires.length > 0) { detailsHtml += '<div class="d-flex flex-wrap gap-2">'; d.images_secondaires.forEach((imgName, index) => { if(imgName) { const secImageUrl = `${uploadDirJS}${htmlspecialchars(imgName)}`; detailsHtml += `<img src="${secImageUrl}" alt="Image secondaire ${index + 1}" class="img-detail-secondary" onclick="openImageViewer('${secImageUrl}', 'Image Secondaire ${index + 1}')" onerror="this.remove(); console.warn('Img sec non trouvée:', this.src)">`; } }); detailsHtml += '</div>'; } else { detailsHtml += '<p><span class="text-muted fst-italic">Aucune image secondaire.</span></p>'; } detailsHtml += '</div></div>'; detailsBienContenuJS.innerHTML = detailsHtml; } catch (error) { console.error("Erreur viewBienDetails:", error); detailsBienContenuJS.innerHTML = `<div class="alert alert-danger">Erreur: ${htmlspecialchars(error.message)}</div>`; } };

            // --- Fonction pour ouvrir la visionneuse ---
            window.openImageViewer = function(imageUrl, imageTitle) { /* ... code open image viewer ... */ console.log("Ouverture visionneuse pour:", imageUrl); if (imageViewerContent && imageViewerLabel) { imageViewerContent.src = imageUrl; imageViewerContent.alt = imageTitle; imageViewerLabel.textContent = imageTitle; imageViewerModal.show(); } else { console.error("Éléments visionneuse non trouvés."); } }

            // --- Supprimer Bien ---
            window.confirmDeleteBien = function(bienId, bienAdresse) { /* ... code confirm delete ... */ console.log("Confirmation suppression pour Bien ID:", bienId); if (!bienId) return; Swal.fire({ title: 'Êtes-vous sûr ?', html: `Supprimer :<br><b>${htmlspecialchars(bienAdresse)} (ID: ${bienId})</b> ?<br><small class='text-danger'>Irréversible !</small>`, icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', cancelButtonColor: '#6c757d', confirmButtonText: 'Oui, supprimer !', cancelButtonText: 'Annuler', reverseButtons: true }).then((result) => { if (result.isConfirmed) { console.log("Suppression confirmée:", bienId); deleteBien(bienId); } else { console.log("Suppression annulée:", bienId); } }); };
            async function deleteBien(bienId) { /* ... code delete bien ... */ console.log("Tentative suppression AJAX:", bienId); showLoadingSweetalert('Suppression...'); const formData = new FormData(); formData.append('action', 'delete_bien'); formData.append('id', bienId); try { const response = await fetch('', { method: 'POST', body: formData }); const data = await response.json(); Swal.close(); if (!response.ok || !data.success) { console.error(`Échec suppression ${bienId}:`, data.message || `Erreur ${response.status}`); throw new Error(data.message || `Erreur ${response.status}`); } console.log(`Succès suppression ${bienId}:`, data.message); await Swal.fire({ title: 'Supprimé !', text: data.message || `Bien #${bienId} supprimé.`, icon: 'success', timer: 2000, showConfirmButton: false }); const rowToRemove = document.getElementById(`bienRow-${bienId}`); if (rowToRemove) { rowToRemove.remove(); console.log("Ligne supprimée:", bienId); checkIfTableIsEmpty(); } else { console.warn("Ligne non trouvée:", bienId); } } catch (error) { Swal.close(); console.error("Erreur catch delete:", error); Swal.fire({ title: 'Erreur !', text: `Impossible de supprimer : ${error.message}`, icon: 'error' }); } }


            // --- **** C'EST CE BLOC QU'IL FAUT VÉRIFIER ET UTILISER **** ---
            // --- Affichage Erreurs POST (validation formulaire) ---
            <?php
            // On exécute ce bloc JS seulement si la méthode est POST,
            // l'action est 'save_bien', et il y a des erreurs de validation ($modalAlerts n'est pas vide)
            if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'save_bien' && !empty($modalAlerts)):
            ?>
                // Utiliser DOMContentLoaded ici est redondant si ce script est à la fin, mais plus sûr
                // document.addEventListener('DOMContentLoaded', function() { // Redondant si à la fin du body
                    console.log("Affichage erreurs POST dans modal:", <?php echo json_encode($modalAlerts); ?>);

                    // Pré-remplir le formulaire avec les données soumises (sauf fichiers)
                    <?php foreach($modalData as $key => $value):
                        if(!in_array($key, ['action','idBien','idProprietaire','images_description', 'image_profil'])):
                            try { ?>
                                const field = document.getElementById('<?php echo htmlspecialchars(str_replace("[]", "", $key)); ?>Bien');
                                if(field && field.type !== 'file') {
                                   field.value = <?php echo json_encode($value); ?>; // Utiliser json_encode pour la valeur
                                   console.log("Pré-remplissage champ:", field.id, "avec valeur:", field.value);
                                }
                            <?php } catch(Exception $e) { error_log("JS render error value: ".$e->getMessage());}
                        endif;
                    endforeach; ?>

                    // Gérer le titre et bouton si c'était une modification
                    const idBienFieldVal = <?php echo json_encode($modalData["idBien"] ?? ""); ?>;
                    if(idBienFieldVal !== "" && idBienFieldVal !== null) { // Vérifier null aussi
                        document.getElementById('idBien').value = idBienFieldVal;
                        document.getElementById('modalAjoutModifBienLabel').textContent = 'Modifier Bien #' + idBienFieldVal;
                        document.getElementById('btnSubmitBien').querySelector('.button-text').textContent = 'Mettre à jour';
                        console.log("Modal configuré pour MODIFICATION (ID:", idBienFieldVal, ") suite à erreur POST");
                    } else {
                        console.log("Modal configuré pour AJOUT suite à erreur POST");
                    }

                    // Afficher les messages d'erreur dans le modal
                    // Utiliser json_encode pour passer le tableau PHP, puis le traiter en JS
                    const errorMessages = <?php echo json_encode($modalAlerts); ?>;
                    // Utiliser la fonction JS htmlspecialchars définie plus haut pour sécuriser l'affichage
                    const errorHtml = errorMessages.map(msg => htmlspecialchars(msg)).join('<br>');
                    showAlert(modalFormAlertPlaceholderJS, errorHtml, 'danger');

                    // Ré-afficher le modal
                    if (modalAjoutModifJS) {
                         modalAjoutModifJS.show();
                         console.log("Modal d'ajout/modif ré-affiché avec erreurs.");
                    } else {
                         console.error("Instance du modal non trouvée pour ré-affichage.");
                    }
                // }); // Fin DOMContentLoaded (redondant si script à la fin)
            <?php endif; // Fin du bloc conditionnel PHP ?>
            // --- **** FIN DU BLOC À VÉRIFIER **** ---


            // --- Init & Events ---
             checkIfTableIsEmpty();
             modalDetailsBienElement.addEventListener('hidden.bs.modal', () => {
                 detailsBienContenuJS.innerHTML = '<p class="text-center py-5"><span class="spinner-border text-info"></span> Chargement...</p>';
                 console.log("Modal détails fermé, contenu réinitialisé.");
             });
             imageViewerModalEl.addEventListener('hidden.bs.modal', () => {
                if(imageViewerContent) imageViewerContent.src = '';
                console.log("Visionneuse fermée.");
             });

             console.log("Script propriétaire-mes-biens.php initialisé.");

        }); // Fin DOMContentLoaded général
     </script>
</body>
</html>