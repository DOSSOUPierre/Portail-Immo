<?php
// --- signer-contrats.php (Version Validation Simple par Clic - Bouton Corrigé) ---
if (session_status() === PHP_SESSION_NONE) session_start();
error_reporting(E_ALL); ini_set('display_errors', 1); // DEBUG

// 1. Auth & Rôle Check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Propriétaire', 'Locataire'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') { ob_end_clean(); header('Content-Type: application/json'); http_response_code(401); echo json_encode(['success' => false, 'message' => 'Auth requise.']); exit; }
    else { header("Location: auth-signin.php?error=auth_required"); exit; }
}

require_once 'db_connection.php'; // $pdo
if (!isset($pdo)) { die("Erreur BDD."); }

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];
$contratId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
error_log("SIGN_CONTRAT_SIMPLE_CLICK: UserID={$userId}, Role={$userRole}, ContratID GET=".($contratId ?: 'NULL'));

// --- Définitions ---
$pageError = null; $contratDetails = null; $userCanSign = false; $userHasSigned = false;

// --- GESTION ACTION AJAX DE VALIDATION/SIGNATURE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sign_contract_simple') {
    ob_end_clean(); ob_start(); header('Content-Type: application/json');
    $responseAjax = ['success' => false, 'message' => 'Erreur init validation.'];
    error_log("SIGN_CONTRAT_SIMPLE_CLICK AJAX: Début action 'sign_contract_simple'");

    $contratIdToSign = filter_input(INPUT_POST, 'idContrat', FILTER_VALIDATE_INT);
    $signingRoleAjax = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_STRING);
    $signingUserIdAjax = $_SESSION['user_id'] ?? null;
    // ** IMPORTANT: Vérifier le nom de la checkbox envoyé par le formulaire **
    $confirmationChecked = isset($_POST['confirmSignatureCheckSimple']); // Utiliser le bon name

    error_log("SIGN_CONTRAT_SIMPLE_CLICK AJAX: POST reçu - idContrat={$contratIdToSign}, role={$signingRoleAjax}, userId={$signingUserIdAjax}, confirm={$confirmationChecked}");

    // Vérifications POST (Confirmation est essentielle)
    if (!$contratIdToSign || $signingRoleAjax !== $userRole || $signingUserIdAjax !== $userId || !$confirmationChecked) {
        $responseAjax['message'] = "Données invalides ou confirmation manquante."; http_response_code(400); error_log("SIGN_CONTRAT_SIMPLE_CLICK AJAX: Echec validation POST."); echo json_encode($responseAjax); exit;
    }

    // --- Traitement BDD ---
    try {
        $pdo->beginTransaction();
        // 1. Re-vérifier contrat, user, statut, signature (FOR UPDATE)
        $sqlCheck = "SELECT c.idContrat, c.statutContrat, l.idUser as loc_uid, p.idUser as prop_uid, c.signatureProprioDate, c.signatureLocataireDate, c.signatureNotaireDate, c.idNotaire FROM contrat c LEFT JOIN locataire l ON c.idLocataire=l.idLocataire LEFT JOIN proprietaire p ON c.idProprietaire=p.idProprietaire WHERE c.idContrat = :contratId FOR UPDATE"; $stmtCheck = $pdo->prepare($sqlCheck); $stmtCheck->execute([':contratId' => $contratIdToSign]); $contrat = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if (!$contrat) throw new Exception("Contrat non trouvé.", 404);
        if (($signingRoleAjax === 'Propriétaire' && $contrat['prop_uid'] != $signingUserIdAjax) || ($signingRoleAjax === 'Locataire' && $contrat['loc_uid'] != $signingUserIdAjax)) throw new Exception("Non autorisé.", 403);
        if (($signingRoleAjax === 'Propriétaire' && $contrat['signatureProprioDate'] !== null) || ($signingRoleAjax === 'Locataire' && $contrat['signatureLocataireDate'] !== null)) throw new Exception("Déjà validé.");
        if (!in_array($contrat['statutContrat'], ['En attente signatures', 'Signé par les parties'])) throw new Exception("Validation impossible (statut: '".$contrat['statutContrat']."').");

        // 2. Mettre à jour la date de signature dans la BDD
        $updateColumnDate = ($signingRoleAjax === 'Propriétaire') ? 'signatureProprioDate' : 'signatureLocataireDate';
        $sqlSetSignature = "UPDATE contrat SET {$updateColumnDate} = NOW() WHERE idContrat = :contratId AND {$updateColumnDate} IS NULL";
        $stmtSetSignature = $pdo->prepare($sqlSetSignature);
        if (!$stmtSetSignature->execute([':contratId' => $contratIdToSign])) { throw new Exception("Echec MàJ BDD Date: " . implode(", ", $stmtSetSignature->errorInfo())); }
        if ($stmtSetSignature->rowCount() === 0) throw new Exception("Validation BDD non effectuée (déjà fait?).");
        error_log("SIGN_CONTRAT_SIMPLE_CLICK AJAX: BDD date MàJ C{$contratIdToSign}, Role {$signingRoleAjax}");

        // 3. Mettre à jour statut contrat (identique)
        $stmtGetUpdatedSigs = $pdo->prepare("SELECT signatureProprioDate, signatureLocataireDate, signatureNotaireDate FROM contrat WHERE idContrat = :idContrat"); $stmtGetUpdatedSigs->execute([':idContrat' => $contratIdToSign]); $updatedSigs = $stmtGetUpdatedSigs->fetch(PDO::FETCH_ASSOC); $propHasSigned = $updatedSigs['signatureProprioDate'] !== null; $locHasSigned = $updatedSigs['signatureLocataireDate'] !== null; $notaireHasSigned = $updatedSigs['signatureNotaireDate'] !== null;
        $newStatutContrat = 'En attente signatures'; if ($propHasSigned && $locHasSigned && $notaireHasSigned) { $newStatutContrat = 'Actif'; } elseif ($propHasSigned && $locHasSigned && !$notaireHasSigned) { $newStatutContrat = 'Signé par les parties'; }
        if ($newStatutContrat !== $contrat['statutContrat']) { $stmtUpdateStatut = $pdo->prepare("UPDATE contrat SET statutContrat = :newStatut WHERE idContrat = :contratId"); if(!$stmtUpdateStatut->execute([':newStatut' => $newStatutContrat, ':contratId' => $contratIdToSign])) { error_log("WARN: Echec MàJ Statut C{$contratIdToSign}");} else { error_log("SIGN_CONTRAT_SIMPLE_CLICK AJAX: Statut màj à {$newStatutContrat}"); } }

        // 4. Commit et réponse succès
        $pdo->commit();
        $responseAjax = ['success' => true, 'message' => 'Contrat validé avec succès !', 'newStatus' => $newStatutContrat, 'signedDate' => date('d/m/Y H:i')];
        error_log("SIGN_CONTRAT_SIMPLE_CLICK AJAX: Succès C{$contratIdToSign}");

        // 5. Notifications post-validation (identique)
         try { /* ... code notif ... */ } catch(PDOException $eN) { error_log("Erreur PDO notif post-validation C{$contratIdToSign}: ".$eN->getMessage()); }

    } catch (Exception $e) { if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack(); $responseAjax['success'] = false; $responseAjax['message'] = $e->getMessage(); error_log("AJAX Error sign_contract_simple (User $userId): " . $e->getMessage() . " Ligne: " . $e->getLine()); $httpCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500; http_response_code($httpCode); }
    if (isset($pdo)) $pdo = null; echo json_encode($responseAjax); exit;
}
// *** FIN GESTION ACTION AJAX ***


// --- Chargement Initial des détails ---
if (!$contratId) { $pageError = "ID de contrat manquant ou invalide."; error_log("SIGN_CONTRAT_SIMPLE: Erreur chargement - ID manquant."); }
else {
    error_log("SIGN_CONTRAT_SIMPLE: Chargement détails C{$contratId}");
    try {
        // Requête pour charger les détails (identique V3)
        $sql = "SELECT c.*, b.adresse as bien_adresse, CONCAT(COALESCE(ul.nom,'?'), ' ', COALESCE(ul.prenom,'')) as locataire_nom_complet, ul.email as locataire_email, l.telephone as locataire_telephone, l.adresse as locataire_adresse, l.idUser as locataire_user_id, CONCAT(COALESCE(up.nom,'?'), ' ', COALESCE(up.prenom,'')) as proprietaire_nom_complet, up.email as proprietaire_email, p.numeroCNI as proprietaire_cni, p.adresse as proprietaire_adresse, p.idUser as proprietaire_user_id FROM contrat c LEFT JOIN bienimmobiliers b ON c.idBien = b.idBien LEFT JOIN locataire l ON c.idLocataire = l.idLocataire LEFT JOIN utilisateurs ul ON l.idUser = ul.idUser LEFT JOIN proprietaire p ON c.idProprietaire = p.idProprietaire LEFT JOIN utilisateurs up ON p.idUser = up.idUser WHERE c.idContrat = :contratId";
        $stmt = $pdo->prepare($sql); $stmt->execute([':contratId' => $contratId]); $contratDetails = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contratDetails) { throw new Exception("Contrat introuvable.", 404); }
        error_log("SIGN_CONTRAT_SIMPLE: Détails C{$contratId} trouvés.");
        // Vérifier autorisation
        if (($userRole === 'Propriétaire' && $contratDetails['proprietaire_user_id'] != $userId) || ($userRole === 'Locataire' && $contratDetails['locataire_user_id'] != $userId)) { throw new Exception("Accès non autorisé.", 403); }
        error_log("SIGN_CONTRAT_SIMPLE: Accès autorisé U{$userId}/R{$userRole}.");
        // Déterminer si l'utilisateur peut signer
        $userHasSigned = ($userRole === 'Propriétaire' && $contratDetails['signatureProprioDate'] !== null) || ($userRole === 'Locataire' && $contratDetails['signatureLocataireDate'] !== null);
        $isContratActiveOrFinished = !in_array($contratDetails['statutContrat'], ['En attente signatures', 'Signé par les parties']);
        $userCanSign = !$userHasSigned && !$isContratActiveOrFinished;
        error_log("SIGN_CONTRAT_SIMPLE: userHasSigned=".($userHasSigned?'O':'N').", isContratActiveOrFinished=".($isContratActiveOrFinished?'O':'N').", userCanSign=".($userCanSign?'O':'N'));
    } catch (Exception $e) { error_log("Erreur chargement page signer-contrats C{$contratId}/U{$userId}: " . $e->getMessage()); $pageError = "Erreur: " . htmlspecialchars($e->getMessage()); if ($e->getCode() == 404 || $e->getCode() == 403) $contratDetails = null; }
}
$pdo = null;
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <!-- ... (head HTML identique: meta, title, CSS links, config.js) ... -->
     <meta charset="utf-8" />
    <title>Validation Contrat #<?php echo htmlspecialchars($contratId ?: 'Erreur'); ?> | GLN</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/sweetalert2/sweetalert2.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" id="app-style"/>
    <script src="assets/js/config.min.js"></script>
    <style>
        /* Styles Signature Simple */
        .signature-section dt { font-weight: 600; color: var(--bs-secondary); } .signature-section dd { margin-bottom: 0.8rem; font-size: 0.9rem;} .signature-pending i { color: var(--bs-warning);} .signature-pending span { font-style: italic; color: #6c757d;} .signature-done i { color: var(--bs-success);} .signature-done span { font-weight: 500; color: var(--bs-success);}
        .contract-details-box { background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 0.3rem; font-size: 0.9rem;} .contract-details-box dt { color: var(--bs-primary); padding-top: 0.4rem; } .contract-details-box dd { padding-top: 0.4rem; word-break: break-word; }
        .btn.loading { position: relative; pointer-events: none; color: transparent !important; } .btn.loading::after { content: ''; position: absolute; top: 50%; left: 50%; width: 1rem; height: 1rem; margin-top: -0.5rem; margin-left: -0.5rem; border: 2px solid rgba(255, 255, 255, 0.6); border-top-color: #ffffff; border-radius: 50%; animation: button-spinner .6s linear infinite; } @keyframes button-spinner { to { transform: rotate(360deg); } }
        .invalid-feedback { display: none; width: 100%; margin-top: .25rem; font-size: .875em; color: #dc3545; } .was-validated .form-check-input:invalid ~ .form-check-label { color: #dc3545; } .was-validated .form-check-input:invalid ~ .invalid-feedback { display: block;}
        .form-check-label.small { font-size: 0.9em; }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- ========== Topbar, Sidebar (Adapter pour Rôle) ========== -->
         <header class="">...</header>
         <div class="main-nav">... <li class="nav-item"><a class="nav-link menu-link active" href="signer-contrats.php"><i class="ri-pencil-line"></i><span>Signer Contrats</span></a></li> ...</div>

        <div class="page-content">
            <div class="container-fluid">
                <!-- Titre -->
                <div class="row"> <div class="col-12"> <div class="page-title-box"> <h4 class="page-title">Validation Contrat #<?php echo htmlspecialchars($contratId ?: 'N/A'); ?></h4> <ol class="breadcrumb m-0"> <li class="breadcrumb-item"><a href="<?php echo strtolower($userRole); ?>-dashboard.php">TdB</a></li> <li class="breadcrumb-item active">Validation</li> </ol> </div> </div> </div>

                 <!-- Affichage Erreur Page ou Contenu Contrat -->
                 <?php if ($pageError): ?>
                    <div class="alert alert-danger" role="alert"><i class="ri-error-warning-line me-1"></i> Erreur: <?php echo $pageError; ?><p class="mt-2"><a href="<?php echo strtolower($userRole); ?>-dashboard.php" class="alert-link">Retour</a></p></div>
                 <?php elseif ($contratDetails): ?>
                    <div class="row"> <div class="col-12"> <div class="card">
                        <div class="card-body"> <div class="row">
                            <!-- Colonne Détails Contrat/Parties -->
                            <div class="col-lg-7 border-end-lg mb-4 mb-lg-0 pe-lg-4">
                                <h5 class="mb-3 text-primary"><i class="ri-file-list-3-line me-2"></i>Détails du Contrat</h5>
                                <dl class="row contract-details-list">
                                     <dt class="col-sm-4">Bien:</dt> <dd class="col-sm-8"><?php echo htmlspecialchars($contratDetails['bien_adresse'] ?? 'N/A'); ?></dd>
                                     <dt class="col-sm-4">Période:</dt> <dd class="col-sm-8"><?php echo !empty($contratDetails['dateDebut']) ? date('d/m/Y', strtotime($contratDetails['dateDebut'])) : '?'; ?> - <?php echo !empty($contratDetails['dateFin']) ? date('d/m/Y', strtotime($contratDetails['dateFin'])) : '?'; ?></dd>
                                     <dt class="col-sm-4">Loyer:</dt> <dd class="col-sm-8"><?php echo isset($contratDetails['montantLoyer']) ? number_format($contratDetails['montantLoyer'], 0, ',', ' ') . ' FCFA' : 'N/A'; ?></dd>
                                     <dt class="col-sm-4">Statut:</dt> <dd class="col-sm-8"><strong id="contractStatusDisplay"><?php echo htmlspecialchars($contratDetails['statutContrat'] ?? 'N/A'); ?></strong></dd>
                                     <dt class="col-sm-4">Locataire:</dt> <dd class="col-sm-8"><?php echo htmlspecialchars($contratDetails['locataire_nom_complet'] ?? 'N/A'); ?></dd>
                                     <dt class="col-sm-4">Propriétaire:</dt> <dd class="col-sm-8"><?php echo htmlspecialchars($contratDetails['proprietaire_nom_complet'] ?? 'N/A'); ?></dd>
                                </dl>
                            </div>
                            <!-- Colonne Signatures & Action -->
                            <div class="col-lg-5 ps-lg-4">
                                <h5 class="mb-3 text-primary"><i class="ri-pencil-ruler-2-line me-2"></i>Validations</h5>
                                <dl class="signature-section">
                                    <dt>Propriétaire</dt> <dd id="sigStatusProp"> <?php /* Affichage statut */ if($contratDetails['signatureProprioDate']): ?> <span class="signature-done"><i class="ri-checkbox-circle-fill me-1"></i> Validé le <?php echo date('d/m/y H:i', strtotime($contratDetails['signatureProprioDate'])); ?></span> <?php else: ?> <span class="signature-pending"><i class="ri-time-line me-1"></i> Attente</span> <?php endif; ?> </dd>
                                    <dt>Locataire</dt> <dd id="sigStatusLoc"> <?php /* Affichage statut */ if($contratDetails['signatureLocataireDate']): ?> <span class="signature-done"><i class="ri-checkbox-circle-fill me-1"></i> Validé le <?php echo date('d/m/y H:i', strtotime($contratDetails['signatureLocataireDate'])); ?></span> <?php else: ?> <span class="signature-pending"><i class="ri-time-line me-1"></i> Attente</span> <?php endif; ?> </dd>
                                    <dt>Notaire</dt> <dd id="sigStatusNotaire"> <?php /* Affichage statut */ if($contratDetails['signatureNotaireDate']): ?> <span class="signature-done"><i class="ri-shield-check-fill me-1"></i> Activé le <?php echo date('d/m/y H:i', strtotime($contratDetails['signatureNotaireDate'])); ?></span> <?php else: ?> <span class="signature-pending"><i class="ri-time-line me-1"></i> Attente</span> <?php endif; ?> </dd>
                                </dl> <hr class="my-3">

                                <!-- *** ZONE D'ACTION SIMPLIFIÉE (SANS UPLOAD) *** -->
                                <div id="signatureActionZone">
                                     <?php if($userCanSign): // Si l'utilisateur peut valider ?>
                                         <form id="formSignContractSimple" novalidate>
                                             <input type="hidden" name="action" value="sign_contract_simple"> <!-- Nouvelle action PHP -->
                                             <input type="hidden" name="idContrat" value="<?php echo $contratId; ?>">
                                             <input type="hidden" name="role" value="<?php echo $userRole; ?>">

                                             <div class="alert alert-warning small p-2 mb-3" role="alert">
                                                 <i class="ri-information-line"></i> Veuillez vérifier les détails du contrat avant de valider.
                                             </div>
                                             <!-- Juste la checkbox de confirmation -->
                                             <div class="form-check mb-3">
                                                 <input class="form-check-input" type="checkbox" value="1" id="confirmSignatureCheckSimple" name="confirmSignatureCheckSimple" required>
                                                 <label class="form-check-label fw-medium" for="confirmSignatureCheckSimple">
                                                     Je confirme avoir lu et j'accepte les termes.
                                                 </label>
                                                 <div class="invalid-feedback">Confirmation requise pour valider.</div>
                                             </div>
                                             <!-- Bouton de validation -->
                                             <div class="d-grid">
                                                 <button type="submit" id="btnSignSimple" class="btn btn-success btn-lg" disabled> <!-- Désactivé initialement -->
                                                     <i class="ri-check-double-line me-1"></i> Valider le Contrat
                                                 </button>
                                             </div>
                                             <div id="signErrorPlaceholder" class="alert alert-danger mt-2 d-none small p-2"></div>
                                         </form>
                                     <?php elseif($userHasSigned): // Si déjà validé ?>
                                          <div class="alert alert-success text-center" role="alert"> <i class="ri-check-double-line me-1"></i> Contrat validé de votre part. </div>
                                     <?php else: // Si contrat non validable ?>
                                          <div class="alert alert-info text-center" role="alert"> <i class="ri-information-line me-1"></i> Statut: <strong><?php echo htmlspecialchars($contratDetails['statutContrat']); ?></strong>.<br>Aucune action requise. </div>
                                     <?php endif; ?>
                                </div>
                             </div>
                         </div> </div>
                    </div> </div> </div>
                <?php else: ?>
                     <div class="alert alert-warning">Impossible de charger les informations du contrat (ID: <?php echo htmlspecialchars($contratId ?: 'inconnu'); ?>).</div>
                <?php endif; ?>
            </div> <!-- container-fluid -->
        </div> <!-- page-content -->
        <!-- Footer -->
         <footer class="footer"> ... </footer>
    </div> <!-- wrapper -->

    <!-- JS Core & Libs -->
    <script src="assets/js/vendor.js"></script>
    <script src="assets/libs/sweetalert2/sweetalert2.all.min.js"></script>
    <script src="assets/js/app.js"></script>
    <!-- Script spécifique signature SIMPLE -->
    <script>
        // --- Fonctions Utilitaires (identiques) ---
        const displayError = (el, msg) => { if(el) { el.innerHTML = msg; el.classList.remove('d-none'); } console.error("UI Error:", msg); };
        const clearError = (el) => { if(el) { el.innerHTML = ''; el.classList.add('d-none'); } };
        const setButtonLoading = (btn, isLoading, originalText = null) => { if (!btn) return; const spinnerHtml = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>'; if (isLoading) { btn.disabled = true; if(originalText === null) originalText = btn.innerHTML; btn.dataset.originalText = originalText; btn.innerHTML = spinnerHtml + ' Patientez...'; } else { btn.disabled = false; btn.innerHTML = btn.dataset.originalText || originalText || 'Action'; } };
        const formatDateTimeFR = (dt) => { if (!dt) return null; try { return new Date(dt).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'}); } catch(e) { return null; } };
        const htmlspecialchars = (str) => { /* Identique */ if (typeof str !== 'string') return str; const map = { '&': '&', '<': '<', '>': '>', '"': '"', "'": ''' }; return str.replace(/[&<>"']/g, function(m) { return map[m]; }); };

        document.addEventListener('DOMContentLoaded', function() {
             // --- Références DOM ---
             const formSignSimple = document.getElementById('formSignContractSimple'); // ID du formulaire
             const signButtonSimple = document.getElementById('btnSignSimple'); // ID du bouton
             const confirmCheckSimple = document.getElementById('confirmSignatureCheckSimple'); // ID de la checkbox
             const errorPlaceholderSimple = document.getElementById('signErrorPlaceholder'); // Placeholder erreur
             const actionZone = document.getElementById('signatureActionZone'); // Zone entière d'action
             const contratId = <?php echo json_encode($contratId); ?>; // ID contrat depuis PHP
             const userRole = <?php echo json_encode($userRole); ?>; // Rôle depuis PHP

            // --- Activation/Désactivation bouton basé sur checkbox ---
             if (confirmCheckSimple && signButtonSimple) {
                 confirmCheckSimple.addEventListener('change', function() {
                    // Le bouton est actif SEULEMENT si la case est cochée
                    signButtonSimple.disabled = !this.checked;
                    if(this.checked) {
                         confirmCheckSimple.classList.remove('is-invalid'); // Enlever erreur visuelle si on coche
                         clearError(errorPlaceholderSimple); // Nettoyer aussi msg erreur général
                    }
                 });
             }

            // --- Soumission du formulaire de validation simple ---
             if (formSignSimple) {
                 formSignSimple.addEventListener('submit', async function(event) {
                     event.preventDefault(); // Empêcher rechargement page
                     event.stopPropagation();
                     clearError(errorPlaceholderSimple); // Nettoyer erreurs précédentes
                     formSignSimple.classList.remove('was-validated'); // Reset style validation BS

                     // Vérifier la checkbox au moment du submit
                     if (!confirmCheckSimple || !confirmCheckSimple.checked) {
                         confirmCheckSimple.classList.add('is-invalid'); // Ajouter style erreur BS
                         formSignSimple.classList.add('was-validated'); // Activer style validation BS
                         displayError(errorPlaceholderSimple, "Vous devez accepter les termes pour valider.");
                         return; // Arrêter si non cochée
                     }

                     // Confirmation SweetAlert
                     const confirmation = await Swal.fire({ title: 'Confirmer Validation', html: `Validez-vous définitivement ce contrat (#${contratId}) en tant que <strong>${userRole}</strong> ?`, icon: 'question', showCancelButton: true, confirmButtonColor: '#28a745', cancelButtonColor: '#6c757d', confirmButtonText: 'Oui, Valider', cancelButtonText: 'Annuler' });
                     if (!confirmation.isConfirmed) return; // Arrêter si annulation

                     // Appel AJAX vers l'action PHP 'sign_contract_simple'
                     const originalBtnHtml = signButtonSimple.innerHTML; setButtonLoading(signButtonSimple, true);
                     try {
                         const formData = new FormData(formSignSimple); // Contient action, idContrat, role, checkbox cochée
                         // Envoi vers le script PHP actuel (qui gère l'action AJAX)
                         const response = await fetch('', { method: 'POST', body: formData });
                         const data = await response.json(); // Attendre réponse JSON

                         if (!response.ok || !data.success) { // Gérer erreurs serveur ou logique
                             throw new Error(data.message || `Erreur ${response.status}`);
                         }

                         // Succès !
                         Swal.fire('Validé!', data.message || 'Contrat validé avec succès !', 'success');
                         const signedDate = data.signedDate ? new Date(data.signedDate.replace(/(\d{2})\/(\d{2})\/(\d{4}) (\d{2}:\d{2})/, '$3-$2-$1T$4:00')) : new Date();
                         updateSignatureStatusUI(userRole, signedDate); // Met à jour l'affichage du statut
                         if(actionZone) { // Remplacer le formulaire par un message de succès
                              actionZone.innerHTML = `<div class="alert alert-success text-center p-2 mt-3" role="alert"><i class="ri-check-double-line me-1"></i> Validation enregistrée.</div>`;
                         }

                     } catch (error) {
                         // Afficher erreur via SweetAlert ou dans le placeholder
                         Swal.fire('Erreur', `La validation n'a pas pu être enregistrée : ${error.message}`, 'error');
                         // displayError(errorPlaceholderSimple, `Erreur: ${error.message}`); // Alternative
                         console.error("Erreur validation simple:", error);
                         setButtonLoading(signButtonSimple, false, originalBtnHtml); // Restaurer bouton
                     }
                 });
             }

            // --- MàJ UI Signature (SIMPLE - sans imagePath) ---
             function updateSignatureStatusUI(role, date) {
                 const formattedDate = date ? formatDateTimeFR(date) : null;
                 const successHtml = formattedDate ? `<span class="signature-done"><i class="ri-checkbox-circle-fill me-1"></i> Validé le ${formattedDate}</span>` : `<span class="signature-done"><i class="ri-checkbox-circle-fill me-1"></i> Validé</span>`;
                 let targetElementId = ''; if (role === 'Propriétaire') targetElementId = `sigStatusProp`; else if (role === 'Locataire') targetElementId = `sigStatusLoc`;
                 const el = document.getElementById(targetElementId); if(el) el.innerHTML = successHtml;
                 // Optionnel: Mettre à jour le statut général du contrat si renvoyé par l'AJAX
                 // const statusBadge = document.getElementById('contractStatusDisplay');
                 // if(statusBadge && data.newStatus) statusBadge.textContent = data.newStatus;
             }

            // Impression (Placeholder)
            window.printContratDetails = function() { Swal.fire('Info', 'Impression à développer.', 'info'); }

        }); // Fin DOMContentLoaded
    </script>

</body>
</html>