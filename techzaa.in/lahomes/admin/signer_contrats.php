<?php
// --- signature.php (Version Upload Image Signature - PDO) ---

// error_reporting(E_ALL); ini_set('display_errors', 1); // DEBUG ONLY
ob_start();

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db_connection.php'; // Connexion $pdo

if (!isset($pdo)) { ob_end_clean(); die("Erreur BDD."); }

// --- Initialisations ---
$pageAlerts = []; $contratDetails = null; $userInfo = null; $signatureRole = null; $canSign = false; $token = filter_input(INPUT_GET, 'token', FILTER_SANITIZE_STRING);
$uploadDirSignaturesServer = __DIR__ . '/uploads/signatures/'; // Chemin SERVEUR
$uploadDirSignaturesRelative = 'uploads/signatures/'; // Chemin RELATIF (pour affichage potentiel)
if (!is_dir($uploadDirSignaturesServer)) @mkdir($uploadDirSignaturesServer, 0775, true); // Créer si besoin

// --- 1. Valider Token & Récupérer Infos Contrat ---
if (empty($token) || strlen($token) !== 64) {
    $pageAlerts[] = ['type' => 'danger', 'message' => 'Lien invalide.'];
} else {
    $sql = "SELECT c.idContrat, c.refContrat, c.dateDebut, c.dateFin, c.montantLoyer, c.statutContrat, c.tokenExpireAt, c.signatureLocataireDate, c.signatureProprioDate, b.adresse AS bienAdresse, b.ville AS bienVille, CASE WHEN c.tokenSignatureLocataire = :t1 THEN 'Locataire' WHEN c.tokenSignatureProprietaire = :t2 THEN 'Proprietaire' ELSE NULL END AS roleSignataire, CASE WHEN c.tokenSignatureLocataire = :t3 THEN CONCAT(u_loc.prenom, ' ', u_loc.nom) WHEN c.tokenSignatureProprietaire = :t4 THEN CONCAT(u_prop.prenom, ' ', u_prop.nom) ELSE NULL END AS nomSignataire, CASE WHEN c.tokenSignatureLocataire = :t5 THEN loc.idLocataire WHEN c.tokenSignatureProprietaire = :t6 THEN prop.idProprietaire ELSE NULL END AS idPartie -- Renvoie idLocataire ou idProprietaire
            FROM contrat c JOIN bienimmobiliers b ON c.idBien = b.idBien JOIN locataire loc ON c.idLocataire = loc.idLocataire JOIN utilisateurs u_loc ON loc.idUser = u_loc.idUser JOIN proprietaire prop ON c.idProprietaire = prop.idProprietaire JOIN utilisateurs u_prop ON prop.idUser = u_prop.idUser
            WHERE (c.tokenSignatureLocataire = :t7 OR c.tokenSignatureProprietaire = :t8) AND c.statutContrat = 'En attente signatures' AND c.tokenExpireAt > NOW()";
    $stmt = $pdo->prepare($sql);
    if ($stmt) {
        for ($i = 1; $i <= 8; $i++) $stmt->bindValue(':t' . $i, $token);
        if ($stmt->execute()) { $contratDetails = $stmt->fetch(PDO::FETCH_ASSOC); if ($contratDetails) { $signatureRole = $contratDetails['roleSignataire']; if (($signatureRole === 'Locataire' && !empty($contratDetails['signatureLocataireDate'])) || ($signatureRole === 'Proprietaire' && !empty($contratDetails['signatureProprioDate']))) { $pageAlerts[] = ['type' => 'info', 'message' => 'Contrat déjà signé.']; $canSign = false; } elseif ($signatureRole) { $canSign = true; $userInfo = ['nomComplet' => $contratDetails['nomSignataire'], 'idPartie' => $contratDetails['idPartie']]; } else { $pageAlerts[] = ['type' => 'danger', 'message' => 'Rôle signataire non identifié.']; } } else { $pageAlerts[] = ['type' => 'danger', 'message' => 'Lien invalide, expiré ou contrat plus en attente.']; } } else { error_log("PDO Exec Err: ".implode(',',$stmt->errorInfo())); $pageAlerts[] = ['type' => 'danger', 'message' => 'Erreur serveur validation lien (exec).']; }
    } else { error_log("PDO Prep Err: ".implode(',',$pdo->errorInfo())); $pageAlerts[] = ['type' => 'danger', 'message' => 'Erreur serveur validation lien (prep).']; }
}

// --- 2. Gestion Action AJAX POST pour l'Upload et Signature ---
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' && isset($_POST['action']) && $_POST['action'] === 'upload_signature') {
    ob_end_clean(); header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Erreur traitement signature.'];
    $ajaxToken = filter_input(INPUT_POST, 'token', FILTER_SANITIZE_STRING);
    $idContratAjax = filter_input(INPUT_POST, 'idContrat', FILTER_VALIDATE_INT); // Récupérer l'ID contrat envoyé par JS

    // **Re-validation rapide du token ET récupération rôle/ID partie (ESSENTIEL)**
    $roleForSign = null; $idPartieForSign = null;
    if ($ajaxToken && $idContratAjax) {
        $sqlRecheck = "SELECT CASE WHEN c.tokenSignatureLocataire = :t1 THEN 'Locataire' WHEN c.tokenSignatureProprietaire = :t2 THEN 'Proprietaire' ELSE NULL END AS roleSig, CASE WHEN c.tokenSignatureLocataire = :t3 THEN c.idLocataire WHEN c.tokenSignatureProprietaire = :t4 THEN c.idProprietaire ELSE NULL END AS idPartieSig, CASE WHEN c.tokenSignatureLocataire = :t5 THEN c.signatureLocataireDate WHEN c.tokenSignatureProprietaire = :t6 THEN c.signatureProprioDate ELSE NULL END AS dateSigExistante FROM contrat c WHERE c.idContrat = :cid AND (c.tokenSignatureLocataire = :t7 OR c.tokenSignatureProprietaire = :t8) AND c.statutContrat = 'En attente signatures' AND c.tokenExpireAt > NOW()";
        $stmtRecheck = $pdo->prepare($sqlRecheck);
        if($stmtRecheck){ for($i=1;$i<=8;$i++)$stmtRecheck->bindValue(':t'.$i, $ajaxToken); $stmtRecheck->bindValue(':cid', $idContratAjax, PDO::PARAM_INT); if($stmtRecheck->execute()){$recheckData=$stmtRecheck->fetch(PDO::FETCH_ASSOC);if($recheckData && empty($recheckData['dateSigExistante'])){ $roleForSign=$recheckData['roleSig']; $idPartieForSign=$recheckData['idPartieSig']; }} }
    }

    if (!$roleForSign || !$idPartieForSign) {
        echo json_encode(['success' => false, 'message' => 'Token invalide, expiré ou signature déjà effectuée.']); exit;
    }

    try {
        // Vérifier si le fichier a été uploadé correctement
        if (!isset($_FILES['signatureImage']) || $_FILES['signatureImage']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Aucun fichier sélectionné ou erreur lors de l\'upload.');
        }

        $file = $_FILES['signatureImage'];
        $maxSize = 2 * 1024 * 1024; // 2MB
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        // Vérification Taille
        if ($file['size'] > $maxSize) throw new RuntimeException('Fichier trop lourd (max 2Mo).');
        if ($file['size'] == 0) throw new RuntimeException('Fichier vide.');

        // Vérification Type MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE); $mimeType = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
        if (!in_array($mimeType, $allowedMimeTypes)) throw new RuntimeException('Format de fichier invalide (JPG, PNG, GIF, WEBP autorisés).');

        // Générer un nom de fichier unique et sécurisé
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        // Ex: signature_locataire_IDLOC_contrat_IDCONTRAT_timestamp.ext
        $safeRole = strtolower($roleForSign);
        $newFileName = "signature_{$safeRole}_{$idPartieForSign}_contrat_{$idContratAjax}_" . time() . '.' . $extension;
        $destinationPath = $uploadDirSignaturesServer . $newFileName;

        // Déplacer le fichier uploadé
        if (!move_uploaded_file($file['tmp_name'], $destinationPath)) {
            throw new RuntimeException('Erreur lors de l\'enregistrement de l\'image de signature.');
        }

        // Mettre à jour la base de données (Transaction)
        $pdo->beginTransaction();
        try {
            $colonneDate = ($roleForSign === 'Locataire') ? 'signatureLocataireDate' : 'signatureProprioDate';
            $colonneFichier = ($roleForSign === 'Locataire') ? 'signatureLocataireFichier' : 'signatureProprioFichier';

            $sqlSign = "UPDATE contrat SET {$colonneDate} = NOW(), {$colonneFichier} = :filename WHERE idContrat = :cid AND {$colonneDate} IS NULL";
            $stmtSign = $pdo->prepare($sqlSign);
            if(!$stmtSign) throw new Exception("Err prep sign");
            $stmtSign->bindValue(':filename', $newFileName); // Stocke juste le nom du fichier
            $stmtSign->bindValue(':cid', $idContratAjax, PDO::PARAM_INT);
            if(!$stmtSign->execute()) throw new Exception("Err exec sign");
            $affected = $stmtSign->rowCount();

            if ($affected > 0) {
                 // Vérifier si l'autre a signé -> statut 'Signé par les parties'
                 $stmtCheckBoth = $pdo->prepare("SELECT signatureLocataireDate, signatureProprioDate FROM contrat WHERE idContrat = :cid");
                 if($stmtCheckBoth){ $stmtCheckBoth->bindValue(':cid', $idContratAjax); $stmtCheckBoth->execute(); $sigDates = $stmtCheckBoth->fetch(PDO::FETCH_ASSOC);
                     if(!empty($sigDates['signatureLocataireDate']) && !empty($sigDates['signatureProprioDate'])) {
                         $stmtUpdateStatut = $pdo->prepare("UPDATE contrat SET statutContrat = 'Signé par les parties' WHERE idContrat = :cid");
                         if($stmtUpdateStatut){ $stmtUpdateStatut->bindValue(':cid', $idContratAjax); $stmtUpdateStatut->execute();}
                     }
                 }
                 $pdo->commit();
                 $response = ['success' => true, 'message' => 'Signature enregistrée avec succès!'];
            } else { throw new Exception("Signature déjà enregistrée ou erreur inattendue."); }
        } catch (Exception $e) { $pdo->rollback(); throw $e; }

    } catch (Exception $e) { // Catch global pour l'action AJAX
        error_log("AJAX Signature Upload Error: " . $e->getMessage()); $response['message'] = $e instanceof RuntimeException || $e instanceof InvalidArgumentException ? $e->getMessage() : "Erreur serveur."; http_response_code($e->getCode() >= 400 ? $e->getCode() : 400); if (isset($pdo) && $pdo->inTransaction()) $pdo->rollback();
    }

    // --- Envoi Réponse JSON ---
    // Fermeture connexion non nécessaire pour PDO typiquement
    ob_end_clean(); echo json_encode($response); exit;
}

// --- Si pas AJAX, continuer pour affichage HTML ---
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <title><?php echo $contratDetails ? 'Signature Contrat #' . htmlspecialchars($contratDetails['refContrat'] ?? $contratDetails['idContrat']) : 'Signature Contrat'; ?> | Portail Notarial</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <!-- CSS -->
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/sweetalert2/sweetalert2.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" id="app-style" />
    <script src="assets/js/config.min.js"></script>
    <style>
        body { background-color: #f0f2f5; } /* Fond légèrement différent */
        .signature-page-container { max-width: 800px; margin: 2rem auto; }
        .signature-card { background-color: #fff; border-radius: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.07); }
        .gradient-header { background: linear-gradient(90deg, var(--bs-primary), var(--bs-info)); color: white; padding: 1.5rem; border-top-left-radius: 10px; border-top-right-radius: 10px; }
        .gradient-header h1 { margin-bottom: 0; font-size: 1.8rem; font-weight: 600; }
        .section-box { padding: 1.5rem 2rem; border-bottom: 1px solid #e9ecef; }
        .section-box:last-child { border-bottom: none; }
        .section-title { font-size: 1.2rem; font-weight: 600; margin-bottom: 1rem; color: var(--bs-primary); }
        .contract-link a { text-decoration: none; font-weight: 500; }
        .contract-link i { vertical-align: middle; }
        .upload-area { border: 2px dashed #adb5bd; border-radius: 5px; padding: 2rem; text-align: center; cursor: pointer; transition: background-color 0.2s ease; }
        .upload-area:hover { background-color: #f8f9fa; }
        .upload-area p { margin-bottom: 0.5rem; color: #6c757d; }
        .upload-area small { font-size: 0.8em; color: #6c757d; }
        #signaturePreview { max-width: 100%; max-height: 150px; margin-top: 1rem; border: 1px solid #eee; display: none; /* Caché initialement */ }
        /* Effet Glassmorphism/Glow (Simple exemple) */
        .btn-glass { background: rgba(var(--bs-primary-rgb), 0.1); border: 1px solid rgba(var(--bs-primary-rgb), 0.2); color: var(--bs-primary); backdrop-filter: blur(5px); transition: all 0.3s ease; }
        .btn-glass:hover { background: rgba(var(--bs-primary-rgb), 0.2); box-shadow: 0 0 15px rgba(var(--bs-primary-rgb), 0.3); }
        .btn.loading::after { border-top-color: var(--bs-primary); /* Adapte couleur spinner */ }
    </style>
</head>
<body>
    <div class="signature-page-container">
        <div class="text-center mb-4">
             <a href="index.php"> <img src="assets/images/logo-dark.png" alt="Logo" height="30"> </a>
        </div>

        <!-- Affichage des erreurs / infos générales -->
        <?php if (!empty($pageAlerts)): ?>
            <?php foreach ($pageAlerts as $alert): ?>
                <div class="alert alert-<?= htmlspecialchars($alert['type']); ?> text-center" role="alert">
                    <?= htmlspecialchars($alert['message']); ?>
                    <?php if (!$contratDetails): ?><br><a href="index.php" class="btn btn-sm btn-link mt-2">Retour</a><?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>


        <?php if ($contratDetails && $signatureRole && $canSign): // Afficher le processus de signature ?>
            <div class="signature-card animate-fadeIn">
                <div class="gradient-header text-center">
                    <h1>Signature Électronique</h1>
                </div>

                <div class="section-box">
                    <h4 class="section-title">Contrat à Signer</h4>
                    <p>Vous êtes invité(e) à signer le contrat de location suivant en tant que <strong><?= htmlspecialchars($signatureRole) ?></strong>.</p>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Référence :</dt><dd class="col-sm-8">#<?= htmlspecialchars($contratDetails['refContrat'] ?? $contratDetails['idContrat']) ?></dd>
                        <dt class="col-sm-4">Bien :</dt><dd class="col-sm-8"><?= htmlspecialchars($contratDetails['bienAdresse'] . ($contratDetails['bienVille'] ? ', '.$contratDetails['bienVille'] : '')) ?></dd>
                        <dt class="col-sm-4">Période :</dt><dd class="col-sm-8"><?= date('d/m/Y', strtotime($contratDetails['dateDebut'])) ?> au <?= date('d/m/Y', strtotime($contratDetails['dateFin'])) ?></dd>
                        <dt class="col-sm-4">Loyer :</dt><dd class="col-sm-8"><?= number_format($contratDetails['montantLoyer'], 0, ',', ' ') ?> FCFA/mois</dd>
                    </dl>
                     <div class="mt-3 contract-link">
                          <!-- Optionnel: Lien pour voir le PDF complet si un fichier initial a été uploadé -->
                          <!-- <a href="chemin/vers/pdf/initial" target="_blank"><i class="ri-file-pdf-line me-1"></i> Voir le document complet</a> -->
                     </div>
                </div>

                <div class="section-box">
                    <h4 class="section-title">Joindre votre Signature</h4>
                    <p class="text-muted">Prenez une photo claire ou scannez votre signature manuscrite sur papier blanc.</p>
                    <form id="formUploadSignature" novalidate>
                         <!-- L'ID contrat est nécessaire pour l'action AJAX -->
                         <input type="hidden" name="idContrat" value="<?= htmlspecialchars($contratDetails['idContrat']) ?>">
                         <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"> <!-- Renvoyer le token pour re-vérification -->

                        <div class="mb-3">
                            <label for="signatureImage" class="form-label visually-hidden">Choisir fichier</label>
                            <div class="upload-area" onclick="document.getElementById('signatureImage').click();">
                                <i class="ri-upload-cloud-2-line fs-1 text-muted"></i>
                                <p>Cliquez ici pour choisir une image</p>
                                <small>(JPG, PNG, GIF, WEBP - Max 2Mo)</small>
                            </div>
                            <input class="form-control d-none" type="file" id="signatureImage" name="signatureImage" accept="image/jpeg, image/png, image/gif, image/webp" required>
                            <div class="invalid-feedback">Veuillez sélectionner une image de signature valide.</div>
                        </div>

                        <div class="text-center">
                             <img id="signaturePreview" src="#" alt="Aperçu signature">
                        </div>

                        <div id="upload-alert-placeholder" class="mt-3"></div> <!-- Pour erreurs upload/signature -->

                        <div class="mt-4 text-center">
                             <button type="submit" class="btn btn-primary btn-lg btn-glass w-100">
                                 <span class="spinner-border spinner-border-sm d-none me-1" role="status"></span>
                                 Envoyer ma Signature
                             </button>
                        </div>
                         <p class="text-center mt-3 small text-muted">En cliquant sur "Envoyer", vous confirmez avoir lu et accepté les termes du contrat et que cette image représente votre signature légale pour ce document.</p>
                    </form>
                </div>
            </div>

        <?php elseif ($contratDetails): // Cas où le contrat est trouvé mais déjà signé ?>
            <!-- Afficher un message de confirmation -->
            <div class="card signature-card">
                <div class="card-body text-center py-5">
                    <i class="ri-checkbox-circle-line text-success fs-1"></i>
                    <h3 class="mt-3">Signature Enregistrée</h3>
                    <p class="text-muted">Vous avez déjà signé ce contrat. Aucune autre action n'est requise de votre part pour le moment.</p>
                    <a href="index.php" class="btn btn-outline-primary mt-3">Retour à l'accueil</a>
                </div>
            </div>
        <?php endif; // Fin if ($contratDetails) ?>

         <!-- Footer Minimaliste -->
          <footer class="mt-5 text-center text-muted">
              <p><small>© <script>document.write(new Date().getFullYear())</script> Portail Immobilier Notarial.</small></p>
          </footer>

    </div> <!-- Fin Container -->

    <!-- JS Core -->
    <script src="assets/js/vendor.js"></script>
    <script src="assets/js/app.js"></script>
    <!-- JS Page Signature -->
    <script>
        const showAlert=(pId,msg,type='danger')=>{const pl=document.getElementById(pId);if(pl){const w=document.createElement('div');w.innerHTML=`<div class="alert alert-${type} alert-dismissible fade show" role="alert">${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;pl.innerHTML='';pl.append(w);}}; const clearAlert=(pId)=>{const pl=document.getElementById(pId);if(pl)pl.innerHTML='';};
        const setButtonLoading = (btn, isLoading, originalText = 'Envoyer ma Signature') => { if (!btn) return; const spinner = btn.querySelector('.spinner-border'); if (isLoading) { btn.disabled = true; btn.dataset.originalText = btn.textContent; spinner?.classList.remove('d-none'); } else { btn.disabled = false; btn.textContent = originalText || btn.dataset.originalText; spinner?.classList.add('d-none'); }};

        document.addEventListener('DOMContentLoaded', () => {
            const canSign = <?= json_encode($canSign) ?>;
            if (!canSign) return; // Ne pas exécuter JS si pas besoin de signer

            const form = document.getElementById('formUploadSignature');
            const fileInput = document.getElementById('signatureImage');
            const preview = document.getElementById('signaturePreview');
            const submitBtn = form.querySelector('button[type="submit"]');
            const alertPlaceholder = 'upload-alert-placeholder';

            // Aperçu de l'image sélectionnée
            fileInput?.addEventListener('change', function(event) {
                const file = event.target.files[0];
                if (file) {
                    // Validation type/taille côté client (basique)
                    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    if (!allowedTypes.includes(file.type)) {
                        showAlert(alertPlaceholder, 'Format de fichier invalide (JPG, PNG, GIF, WEBP).', 'warning');
                        preview.style.display = 'none'; preview.src = '#'; fileInput.value = ''; return;
                    }
                    if (file.size > 2 * 1024 * 1024) { // 2MB
                        showAlert(alertPlaceholder, 'Fichier trop volumineux (max 2Mo).', 'warning');
                        preview.style.display = 'none'; preview.src = '#'; fileInput.value = ''; return;
                    }

                    clearAlert(alertPlaceholder);
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                    }
                    reader.readAsDataURL(file);
                } else {
                     preview.style.display = 'none'; preview.src = '#';
                }
            });

            // Soumission du formulaire (Upload + Signature)
            form?.addEventListener('submit', async function(event) {
                event.preventDefault(); event.stopPropagation();
                clearAlert(alertPlaceholder);
                form.classList.remove('was-validated');

                // Vérifier si un fichier est sélectionné
                if (!fileInput.files || fileInput.files.length === 0) {
                    fileInput.classList.add('is-invalid');
                    showAlert(alertPlaceholder, 'Veuillez sélectionner une image de votre signature.', 'warning');
                    form.classList.add('was-validated');
                    return;
                }
                 fileInput.classList.remove('is-invalid'); // Valide si fichier présent

                setButtonLoading(submitBtn, true);
                const formData = new FormData(form);
                formData.append('action', 'upload_signature'); // Action AJAX

                try {
                    const response = await fetch('', { // POST vers signature.php
                         method: 'POST',
                         headers: {'X-Requested-With':'XMLHttpRequest'},
                         body: formData
                    });
                    const data = await response.json();

                    if (!response.ok) throw new Error(data.message || `Erreur ${response.status}`);

                    if (data.success) {
                        // Afficher succès et désactiver formulaire
                        document.getElementById('step-request-otp')?.remove(); // Si structure OTP était là
                        document.getElementById('step-validate-otp')?.remove();
                        form.innerHTML = `<div class="alert alert-success text-center"><i class="ri-check-double-line fs-3 align-middle me-1"></i> ${data.message || 'Signature enregistrée avec succès !'}</div><div class="text-center mt-3"><a href="index.php" class="btn btn-outline-primary">Retour à l'accueil</a></div>`;
                         // On pourrait aussi utiliser SweetAlert ici
                         // Swal.fire('Succès!', data.message || 'Signature enregistrée!', 'success').then(() => { /* ... */});
                    } else {
                        throw new Error(data.message || "Échec de l'enregistrement.");
                    }
                } catch (error) {
                    console.error('Erreur upload signature:', error);
                    showAlert(alertPlaceholder, error.message, 'danger');
                    // Ne pas réinitialiser le fichier pour que l'user n'ait pas à re-sélectionner
                } finally {
                    setButtonLoading(submitBtn, false);
                }
            });

        }); // Fin DOMContentLoaded
    </script>

</body>
</html>