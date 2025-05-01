<?php
// --- ajax/notaire_contrats_actions.php ---

// error_reporting(E_ALL); ini_set('display_errors', 1); // DEBUG ONLY

ob_start(); // Bufferisation pour JSON propre
header('Content-Type: application/json'); // Toujours JSON

// 1. Session & Auth (Récupère juste les infos, vérification droits par action)
if (session_status() === PHP_SESSION_NONE) session_start();
$userId = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['user_role'] ?? null;
$current_notaire_id = $_SESSION['idNotaire'] ?? null; // Doit être défini par auth_notaire.php

// 2. Connexion BDD
require_once __DIR__ . '/../db_connection.php'; // Ajuster chemin vers db_connection
if (!isset($pdo)) { http_response_code(500); echo json_encode(['success' => false, 'message' => 'Erreur BDD.']); exit; }

// 3. Action Routing
$action = $_REQUEST['action'] ?? null;
$responseAjax = ['success' => false, 'message' => 'Action non spécifiée ou erreur interne.'];

// --- Vérification droits Notaire pour toutes les actions de ce fichier ---
if ($userId === null || $userRole !== 'Notaire' || $current_notaire_id === null) {
    error_log("[AJAX Notaire Contrat] Accès refusé: UserID=" . ($userId ?? 'ND') . " Role=" . ($userRole ?? 'ND') . " IDNotaire=" . ($current_notaire_id ?? 'ND'));
    http_response_code(403);
    $responseAjax['message'] = "Accès non autorisé.";
    echo json_encode($responseAjax);
    exit;
}
// --- Fin Vérification droits ---


try {
    switch ($action) {
        // --- Action: get_contrats ---
        case 'get_contrats':
            // --- COPIER/COLLER TOUT le code PHP du case 'get_contracts' ici ---
            // (Celui qui utilise $pdo, les filtres, la pagination et formate la réponse)
            // Remplacer $response par $responseAjax
             if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception("Méthode GET requise.", 405);
             $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]); $itemsPerPage = 10; $offset = ($page - 1) * $itemsPerPage;
             $params = [':idNotaire' => $current_notaire_id]; $whereClauses = [];
             if (!empty($_GET['searchTerm'])) { $searchTerm = '%' . trim($_GET['searchTerm']) . '%'; $whereClauses[] = "(CAST(c.idContrat AS CHAR) LIKE :searchTerm OR b.adresse LIKE :searchTerm OR CONCAT(u_loc.prenom, ' ', u_loc.nom) LIKE :searchTerm OR CONCAT(u_prop.prenom, ' ', u_prop.nom) LIKE :searchTerm)"; $params[':searchTerm'] = $searchTerm; }
             if (!empty($_GET['statutContrat'])) { $whereClauses[] = "c.statutContrat = :statutContrat"; $params[':statutContrat'] = trim($_GET['statutContrat']); }
             if (!empty($_GET['statutSignature'])) { $statutSig = trim($_GET['statutSignature']); if ($statutSig === 'Complet') $whereClauses[] = "(c.signatureProprioDate IS NOT NULL AND c.signatureLocataireDate IS NOT NULL AND c.signatureNotaireDate IS NOT NULL)"; elseif ($statutSig === 'Incomplet') $whereClauses[] = "(c.signatureProprioDate IS NULL OR c.signatureLocataireDate IS NULL OR c.signatureNotaireDate IS NULL)"; elseif ($statutSig === 'Manquante_notaire') $whereClauses[] = "(c.signatureProprioDate IS NOT NULL AND c.signatureLocataireDate IS NOT NULL AND c.signatureNotaireDate IS NULL)"; elseif ($statutSig === 'Manquante_partie') $whereClauses[] = "(c.signatureProprioDate IS NULL OR c.signatureLocataireDate IS NULL)"; }
             $sqlBaseFrom = " FROM contrat c JOIN bienimmobiliers b ON c.idBien = b.idBien JOIN locataire l ON c.idLocataire = l.idLocataire JOIN utilisateurs u_loc ON l.idUser = u_loc.idUser LEFT JOIN proprietaire p ON c.idProprietaire = p.idProprietaire LEFT JOIN utilisateurs u_prop ON p.idUser = u_prop.idUser";
             $sqlWhere = " WHERE c.idNotaire = :idNotaire " . (!empty($whereClauses) ? " AND " . implode(" AND ", $whereClauses) : "");
             $sqlCount = "SELECT COUNT(c.idContrat) " . $sqlBaseFrom . $sqlWhere; $stmtCount = $pdo->prepare($sqlCount); if (!$stmtCount) throw new PDOException("Err prep count"); $stmtCount->execute($params); $totalItems = $stmtCount->fetchColumn(); $totalPages = $totalItems > 0 ? ceil($totalItems / $itemsPerPage) : 0; $page = max(1, min($page, $totalPages > 0 ? $totalPages : 1)); $offset = ($page - 1) * $itemsPerPage;
             $contracts = [];
             if ($totalItems > 0) { $sqlData = "SELECT c.idContrat, c.idContrat AS ref, c.dateDebut, c.dateFin, c.statutContrat, c.signatureLocataireDate, c.signatureProprioDate, c.signatureNotaireDate, c.fichierContratSigneFinal, b.adresse AS bienAdresse, CONCAT(u_loc.prenom, ' ', u_loc.nom) AS locataireNom, CONCAT(u_prop.prenom, ' ', u_prop.nom) AS proprietaireNom " . $sqlBaseFrom . $sqlWhere . " ORDER BY c.dateCreation DESC LIMIT :limit OFFSET :offset "; $stmtData = $pdo->prepare($sqlData); if (!$stmtData) throw new PDOException("Err prep data"); $params[':limit'] = $itemsPerPage; $params[':offset'] = $offset; foreach ($params as $key => $value) { $stmtData->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR); } $stmtData->execute(); $contractsData = $stmtData->fetchAll(PDO::FETCH_ASSOC); foreach ($contractsData as $row) { $row['sigL'] = $row['signatureLocataireDate'] !== null; $row['sigP'] = $row['signatureProprioDate'] !== null; $row['sigN'] = $row['signatureNotaireDate'] !== null; $docPath = !empty($row['fichierContratSigneFinal']) ? __DIR__ . '/../' . $row['fichierContratSigneFinal'] : null; $row['hasDoc'] = $docPath && file_exists($docPath) && $row['sigL'] && $row['sigP'] && $row['sigN']; unset($row['signatureLocataireDate'], $row['signatureProprioDate'], $row['signatureNotaireDate'], $row['fichierContratSigneFinal']); $contracts[] = $row; } }
             error_log("[AJAX Notaire] get_contrats: Trouvé $totalItems contrats, page $page/$totalPages.");
             $responseAjax = [ 'success' => true, 'contrats' => $contracts, 'pagination' => [ 'currentPage' => $page, 'totalPages' => $totalPages, 'totalItems' => $totalItems ] ];
            break;

        // --- Action: get_options (Modal Nouveau) ---
        case 'get_options':
            // --- COPIER/COLLER TOUT le code PHP du case 'get_options' ici ---
             if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception("Méthode GET requise.", 405);
             $stmtBiens = $pdo->prepare(" SELECT idBien, CONCAT(adresse, ' (Prop: ', COALESCE(u_prop.prenom, ''), ' ', COALESCE(u_prop.nom,'Inconnu'), ')') as description FROM bienimmobiliers b LEFT JOIN proprietaire p ON b.idProprietaire = p.idProprietaire LEFT JOIN utilisateurs u_prop ON p.idUser = u_prop.idUser WHERE b.statut = 'Libre' AND b.supervisionStatut = 'Validé' ORDER BY b.adresse ASC "); $stmtBiens->execute(); $biens = $stmtBiens->fetchAll(PDO::FETCH_ASSOC);
             $stmtLocataires = $pdo->prepare(" SELECT l.idLocataire, CONCAT(u.prenom, ' ', u.nom, ' (', u.email, ')') AS description FROM locataire l JOIN utilisateurs u ON l.idUser = u.idUser WHERE u.statut = 'Actif' ORDER BY u.nom ASC, u.prenom ASC "); $stmtLocataires->execute(); $locataires = $stmtLocataires->fetchAll(PDO::FETCH_ASSOC);
             error_log("[AJAX Notaire] get_options: Trouvé " . count($biens) . " biens, " . count($locataires) . " locataires.");
             $responseAjax = [ 'success' => true, 'biens' => $biens, 'locataires' => $locataires ];
            break;

         // --- Action: get_contract_details (Modal Détails) ---
         case 'get_contrat_details':
            // --- COPIER/COLLER TOUT le code PHP du case 'get_contract_details' ici ---
             if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception("Méthode GET requise.", 405); $contratId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT); if (!$contratId) throw new InvalidArgumentException("ID Contrat invalide."); error_log("[AJAX Notaire] get_contract_details ID: $contratId");
             $sql = "SELECT c.*, b.adresse as bienAdresse, b.typeBien, u_loc.nom as locataireNom, u_loc.prenom as locatairePrenom, u_loc.email AS locataireEmail, u_loc.telephone AS locataireTel, u_prop.nom as proprioNom, u_prop.prenom as proprioPrenom, u_prop.email AS proprietaireEmail, u_prop.telephone AS proprietaireTel FROM contrat c JOIN bienimmobiliers b ON c.idBien = b.idBien JOIN locataire loc ON c.idLocataire = loc.idLocataire JOIN utilisateurs u_loc ON loc.idUser = u_loc.idUser LEFT JOIN proprietaire p ON c.idProprietaire = p.idProprietaire LEFT JOIN utilisateurs u_prop ON p.idUser = u_prop.idUser WHERE c.idContrat = :idContrat AND c.idNotaire = :idNotaire"; $stmt = $pdo->prepare($sql); if(!$stmt) throw new PDOException("Err prep details"); $stmt->execute([':idContrat' => $contratId, ':idNotaire' => $current_notaire_id]); $details = $stmt->fetch(PDO::FETCH_ASSOC); if (!$details) throw new Exception("Contrat non trouvé/accès refusé.", 404);
             $details['locataireFullName'] = trim(($details['locatairePrenom'] ?? '') . ' ' . ($details['locataireNom'] ?? '')); $details['proprioFullName'] = trim(($details['proprioPrenom'] ?? '') . ' ' . ($details['proprioNom'] ?? '')); $details['sigL'] = $details['signatureLocataireDate'] !== null; $details['sigP'] = $details['signatureProprioDate'] !== null; $details['sigN'] = $details['signatureNotaireDate'] !== null; $docPath = !empty($details['fichierContratSigneFinal']) ? __DIR__ . '/../' . $details['fichierContratSigneFinal'] : null; $details['hasDocument'] = $docPath && file_exists($docPath) && $details['sigL'] && $details['sigP'] && $details['sigN']; $details['downloadUrl'] = $details['hasDocument'] ? "notaire-gestion-contrats.php?action=download_contract&id=" . $contratId : null; // Point vers le fichier principal pour download
             $details['montantLoyer'] = isset($details['montantLoyer']) ? (float)$details['montantLoyer'] : null; $details['caution'] = isset($details['caution']) ? (float)$details['caution'] : null; $details['moisAvance'] = isset($details['moisAvance']) ? (int)$details['moisAvance'] : null; unset($details['signatureLocataireDate'], $details['signatureProprioDate'], $details['signatureNotaireDate'], $details['fichierContratSigneFinal']);
             $responseAjax = ['success' => true, 'details' => $details];
             break;

        // --- Action: create_contrat ---
        case 'create_contrat':
            // --- COPIER/COLLER TOUT le code PHP du case 'create_contrat' ici ---
             if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Méthode POST requise.", 405);
             $idBien = filter_input(INPUT_POST, 'idBien', FILTER_VALIDATE_INT); $idLocataire = filter_input(INPUT_POST, 'idLocataire', FILTER_VALIDATE_INT); $dateDebutInput = filter_input(INPUT_POST, 'dateDebut', FILTER_SANITIZE_STRING); $dateFinInput = filter_input(INPUT_POST, 'dateFin', FILTER_SANITIZE_STRING); $montantLoyer = filter_input(INPUT_POST, 'montantLoyer', FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0]]); $cautionInput = filter_input(INPUT_POST, 'caution', FILTER_VALIDATE_FLOAT); $moisAvanceInput = filter_input(INPUT_POST, 'moisAvance', FILTER_VALIDATE_INT);
             if (!$idBien || !$idLocataire || !$dateDebutInput || !$dateFinInput || $montantLoyer === false) throw new InvalidArgumentException("Données obligatoires invalides.");
             $dateDebut = DateTime::createFromFormat('Y-m-d', $dateDebutInput); $dateFin = DateTime::createFromFormat('Y-m-d', $dateFinInput); if (!$dateDebut || $dateDebut->format('Y-m-d') !== $dateDebutInput || !$dateFin || $dateFin->format('Y-m-d') !== $dateFinInput || $dateFin <= $dateDebut) throw new InvalidArgumentException("Dates invalides.");
             $caution = ($cautionInput !== false && $cautionInput >= 0) ? $cautionInput : $montantLoyer; $moisAvance = ($moisAvanceInput !== false && $moisAvanceInput >= 0) ? $moisAvanceInput : 3;
             $pdo->beginTransaction(); try { $stmtCheckBien = $pdo->prepare("SELECT idProprietaire, statut, adresse FROM bienimmobiliers WHERE idBien = ? AND supervisionStatut = 'Validé' FOR UPDATE"); if(!$stmtCheckBien) throw new PDOException("E Prep CheckBien"); $stmtCheckBien->execute([$idBien]); $bienData = $stmtCheckBien->fetch(PDO::FETCH_ASSOC); if (!$bienData) throw new Exception("Bien non trouvé/validé."); if ($bienData['statut'] !== 'Libre') throw new Exception("Bien non disponible (statut: ".$bienData['statut'].")."); if (empty($bienData['idProprietaire'])) throw new Exception("Propriétaire non associé."); $idProprietaire = $bienData['idProprietaire']; $adresseBienNotif = $bienData['adresse'] ?? '(inconnue)';
             $stmtUserLoc = $pdo->prepare("SELECT idUser FROM locataire WHERE idLocataire = ?"); $stmtUserLoc->execute([$idLocataire]); $idUserLocataire = $stmtUserLoc->fetchColumn(); $stmtUserProp = $pdo->prepare("SELECT idUser FROM proprietaire WHERE idProprietaire = ?"); $stmtUserProp->execute([$idProprietaire]); $idUserProprietaire = $stmtUserProp->fetchColumn(); if ($idUserLocataire === false || $idUserProprietaire === false) throw new Exception("Utilisateur(s) lié(s) introuvable(s).");
             $sqlInsert = "INSERT INTO contrat (idBien, idLocataire, idProprietaire, idNotaire, dateDebut, dateFin, montantLoyer, caution, moisAvance, dateCreation, statutContrat) VALUES (:idBien, :idLocataire, :idProprietaire, :idNotaire, :dateDebut, :dateFin, :montantLoyer, :caution, :moisAvance, NOW(), :statut)"; $stmtInsert = $pdo->prepare($sqlInsert); if (!$stmtInsert) throw new PDOException("E Prep InsertC"); $paramsInsert = [ ':idBien' => $idBien, ':idLocataire' => $idLocataire, ':idProprietaire' => $idProprietaire, ':idNotaire' => $current_notaire_id, ':dateDebut' => $dateDebut->format('Y-m-d'), ':dateFin' => $dateFin->format('Y-m-d'), ':montantLoyer' => $montantLoyer, ':caution' => $caution, ':moisAvance' => $moisAvance, ':statut' => 'En attente signatures' ]; if (!$stmtInsert->execute($paramsInsert)) throw new PDOException("E Exec InsertC: ".$stmtInsert->errorInfo()[2]); $newContratId = $pdo->lastInsertId(); if (!$newContratId) throw new Exception("E ID contrat.");
             $sqlUpdateBien = $pdo->prepare("UPDATE bienimmobiliers SET statut = 'Occupé' WHERE idBien = ?"); if($sqlUpdateBien){ if (!$sqlUpdateBien->execute([$idBien])) error_log("Warn: Update Bien Echec C{$newContratId}"); } else { error_log("Warn: Prep Update Bien Echec C{$newContratId}"); }
             $pdo->commit(); error_log("[AJAX Notaire] Contrat ID $newContratId créé par Notaire $current_notaire_id.");
             try { $sqlNotif = "INSERT INTO notification (idUser, contenu, dateNotif, typeNotif, statutNotif) VALUES (?, ?, CURDATE(), ?, 'Envoyé')"; $stmtNotif = $pdo->prepare($sqlNotif); $typeNotification = 'contrat'; if ($idUserLocataire) { $contenuLoc = "Nouveau contrat (#{$newContratId}) créé pour bien: " . htmlspecialchars($adresseBienNotif) . "."; $stmtNotif->execute([$idUserLocataire, $contenuLoc, $typeNotification]); } if ($idUserProprietaire) { $contenuProp = "Nouveau contrat (#{$newContratId}) établi par notaire pour bien: " . htmlspecialchars($adresseBienNotif) . "."; $stmtNotif->execute([$idUserProprietaire, $contenuProp, $typeNotification]); } } catch (PDOException $e) { error_log("Erreur PDO notifs C{$newContratId}: " . $e->getMessage()); }
             $sqlSelectNew = "SELECT c.idContrat, c.idContrat AS ref, c.dateDebut, c.dateFin, c.statutContrat, b.adresse AS bienAdresse, CONCAT(u_loc.prenom, ' ', u_loc.nom) AS locataireNom, CONCAT(u_prop.prenom, ' ', u_prop.nom) AS proprietaireNom, false AS sigL, false AS sigP, false AS sigN, false AS hasDoc FROM contrat c JOIN bienimmobiliers b ON c.idBien = b.idBien JOIN locataire l ON c.idLocataire = l.idLocataire JOIN utilisateurs u_loc ON l.idUser = u_loc.idUser LEFT JOIN proprietaire p ON c.idProprietaire = p.idProprietaire LEFT JOIN utilisateurs u_prop ON p.idUser = u_prop.idUser WHERE c.idContrat = ?"; $stmtNew = $pdo->prepare($sqlSelectNew); if(!$stmtNew) throw new PDOException("E Prep SelNew"); $stmtNew->execute([$newContratId]); $newContractDetails = $stmtNew->fetch(PDO::FETCH_ASSOC); if (!$newContractDetails) throw new Exception("E récup détails post-création.");
             $responseAjax = ['success' => true, 'message' => "Contrat #{$newContratId} créé !", 'newContrat' => $newContractDetails];
            } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log("[AJAX Notaire] Erreur create_contrat: " . $e->getMessage()); throw $e; }
            break;

        // --- Action: sign_contrat_notaire ---
        case 'sign_contrat_notaire':
            // --- COPIER/COLLER TOUT le code PHP du case 'sign_contrat_notaire' ici ---
             if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Méthode POST requise.", 405); $contratId = filter_input(INPUT_POST, 'contractId', FILTER_VALIDATE_INT); if (!$contratId) throw new InvalidArgumentException("ID Contrat invalide."); error_log("[AJAX Notaire] sign_contrat_notaire ID: $contratId");
             $pdo->beginTransaction(); try { $stmtCheck = $pdo->prepare("SELECT signatureProprioDate, signatureLocataireDate, signatureNotaireDate FROM contrat WHERE idContrat = :idContrat AND idNotaire = :idNotaire FOR UPDATE"); if(!$stmtCheck) throw new PDOException("Err prep check sig N"); $stmtCheck->execute([':idContrat' => $contratId, ':idNotaire' => $current_notaire_id]); $sigData = $stmtCheck->fetch(PDO::FETCH_ASSOC); if (!$sigData) throw new Exception("Contrat non trouvé/accès refusé.", 404); if ($sigData['signatureNotaireDate'] !== null) throw new Exception("Déjà signé par notaire."); if ($sigData['signatureProprioDate'] === null || $sigData['signatureLocataireDate'] === null) throw new Exception("Signatures P/L requises.");
             $sqlUpdate = $pdo->prepare("UPDATE contrat SET signatureNotaireDate = NOW(), statutContrat = 'Actif' WHERE idContrat = :idContrat"); if (!$sqlUpdate) throw new PDOException("Err prep upd sig N"); if (!$sqlUpdate->execute([':idContrat' => $contratId])) throw new PDOException("Echec exec upd sig N: ".$sqlUpdate->errorInfo()[2]); if ($sqlUpdate->rowCount() == 0) throw new Exception("MàJ signature notaire échouée.");
             // TODO: Génération PDF si nécessaire
             $pdo->commit(); error_log("[AJAX Notaire] Contrat ID $contratId signé/activé.");
             try { $stmtUserLoc = $pdo->prepare("SELECT l.idUser, b.adresse FROM contrat c JOIN locataire l ON c.idLocataire=l.idLocataire JOIN bienimmobiliers b ON c.idBien=b.idBien WHERE c.idContrat = ?"); $stmtUserLoc->execute([$contratId]); $locInfo = $stmtUserLoc->fetch(); $stmtUserProp = $pdo->prepare("SELECT p.idUser FROM contrat c JOIN proprietaire p ON c.idProprietaire=p.idProprietaire WHERE c.idContrat = ?"); $stmtUserProp->execute([$contratId]); $propInfo = $stmtUserProp->fetch(); $adresseBien = $locInfo['adresse'] ?? 'votre bien'; $sqlNotif = "INSERT INTO notification (idUser, contenu, dateNotif, typeNotif, statutNotif) VALUES (?, ?, CURDATE(), ?, 'Envoyé')"; $stmtNotif = $pdo->prepare($sqlNotif); $typeNotif = 'contrat'; if($locInfo && $locInfo['idUser']) $stmtNotif->execute([$locInfo['idUser'], "Contrat #{$contratId} pour ".htmlspecialchars($adresseBien)." est maintenant Actif.", $typeNotif]); if($propInfo && $propInfo['idUser']) $stmtNotif->execute([$propInfo['idUser'], "Contrat #{$contratId} pour ".htmlspecialchars($adresseBien)." est maintenant Actif.", $typeNotif]); } catch (PDOException $e) { error_log("Erreur notifs post-sig notaire C{$contratId}: ".$e->getMessage()); }
             $responseAjax = ['success' => true, 'message' => "Contrat #{$contratId} signé et activé !"];
            } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log("[AJAX Notaire] Erreur sign_contrat_notaire: ".$e->getMessage()); throw $e; }
            break;

        // --- Action: delete_contrat ---
        case 'delete_contrat':
            // --- COPIER/COLLER TOUT le code PHP du case 'delete_contrat' ici ---
             if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Méthode POST requise.", 405); $contratId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT); if (!$contratId) throw new InvalidArgumentException("ID Contrat invalide."); error_log("[AJAX Notaire] delete_contrat ID: $contratId");
             $pdo->beginTransaction(); try { $stmtBien = $pdo->prepare("SELECT idBien FROM contrat WHERE idContrat = :id AND idNotaire = :nid"); if(!$stmtBien) throw new PDOException("Err prep get bien"); $stmtBien->execute([':id' => $contratId, ':nid' => $current_notaire_id]); $idBien = $stmtBien->fetchColumn(); $stmtDel = $pdo->prepare("DELETE FROM contrat WHERE idContrat = :id AND idNotaire = :nid"); if(!$stmtDel) throw new PDOException("Err prep del contrat"); $stmtDel->execute([':id' => $contratId, ':nid' => $current_notaire_id]); if ($stmtDel->rowCount() == 0) throw new Exception("Contrat non trouvé/échec suppression.", 404);
             if ($idBien) { $stmtUpBien = $pdo->prepare("UPDATE bienimmobiliers SET statut = 'Libre' WHERE idBien = ? AND statut = 'Occupé'"); if(!$stmtUpBien) throw new PDOException("Err prep upd bien"); if ($stmtUpBien->execute([$idBien])) { error_log("Bien #$idBien remis Libre (suppr C#$contratId)."); } else { error_log("Warn: Echec MàJ statut bien #$idBien."); } } else { error_log("Warn: idBien non trouvé C#$contratId supprimé."); }
             $pdo->commit(); error_log("[AJAX Notaire] Contrat ID $contratId supprimé."); $responseAjax = ['success' => true, 'message' => "Contrat #{$contratId} supprimé."];
            } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log("[AJAX Notaire] Erreur delete_contrat: ".$e->getMessage()); throw $e; }
            break;

        // --- Autres actions (Simplifié) ---
        case 'resend_signatures':
            $responseAjax = ['success' => true, 'message' => 'Fonctionnalité de renvoi non implémentée.']; break;
        case 'get_signature_status': // Pourrait être utile pour ancien modal, mais non utilisé par le nouveau
             $contratId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT); if (!$contratId) throw new InvalidArgumentException("ID contrat invalide."); $stmt = $pdo->prepare("SELECT signatureProprioDate, signatureLocataireDate, signatureNotaireDate FROM contrat WHERE idContrat = :id AND idNotaire = :nid"); $stmt->execute([':id' => $contratId, ':nid' => $current_notaire_id]); $data = $stmt->fetch(PDO::FETCH_ASSOC); if (!$data) throw new Exception("Contrat non trouvé", 404); $responseAjax = ['success' => true, 'sigStatus' => ['prop' => ($data['signatureProprioDate'] !== null), 'loc' => ($data['signatureLocataireDate'] !== null), 'notaire' => ($data['signatureNotaireDate'] !== null)]];
             break;


        default:
            error_log("[AJAX Notaire] Action inconnue: " . $action);
            throw new Exception("Action inconnue.", 400);
    } // Fin Switch

} catch (Exception $e) { // Gestion Erreurs AJAX Générale
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("[AJAX Notaire] ERREUR Action '$action': " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
    $responseAjax = ['success' => false, 'message' => $e->getMessage()];
    $httpCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($httpCode);
}

// --- Envoi réponse JSON ---
if (isset($pdo)) $pdo = null;
ob_end_clean();
echo json_encode($responseAjax);
exit;

} // --- Fin GESTION ACTIONS AJAX ---

// --- Gestion Download (Action non-AJAX - Doit être dans le fichier principal) ---
// PAS ICI, laisser dans notaire-gestion-contrats.php
?>