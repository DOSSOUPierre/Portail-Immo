<?php
// --- locataire-maintenance.php (Adapté à ta BDD + Table Maintenance) ---

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'Locataire') {
    $_SESSION['error_message'] = "Accès non autorisé."; header("Location: auth-signin.php"); exit;
}

require_once 'db_connection.php'; // $pdo

// --- Récupération ID Locataire et Contrat Actif ---
$user_id = $_SESSION['user_id'];
$locataire_id = null;
$contrat_actif = null;
$idContratActif = null;
$idBienContrat = null; // ID du bien lié au contrat actif
$idNotaireContrat = null;
$idProprietaireContrat = null;
$locataire_info = []; // Pour le nom dans le header
$pageAlerts = [];
$errorMessage = '';
$initialDataLoaded = false;

// Fonction get_locataire_id_from_user
function get_locataire_id_from_user(PDO $pdo, int $userId): ?int {
    try { $stmt = $pdo->prepare("SELECT idLocataire FROM locataire WHERE idUser = ?"); $stmt->execute([$userId]); return $stmt->fetchColumn() ?: null; }
    catch (PDOException $e) { error_log("Err get_locataire_id: " . $e->getMessage()); return null; }
}

// --- Chargement Données Initiales ---
try {
    if (!isset($pdo) || !$pdo instanceof PDO) { throw new Exception("Connexion BDD indisponible."); }

    $locataire_id = get_locataire_id_from_user($pdo, $user_id);
    if ($locataire_id === null) { throw new Exception("Infos locataire introuvables."); }

    // Récupérer infos utilisateur pour le header
    $stmtLocInfo = $pdo->prepare("SELECT prenom FROM utilisateurs WHERE idUser = ?");
    $stmtLocInfo->execute([$user_id]);
    $locataire_info = $stmtLocInfo->fetch(PDO::FETCH_ASSOC);

    // Récupérer le contrat ACTIF et les IDs liés
    $stmtContrat = $pdo->prepare("SELECT idContrat, idBien, idNotaire, idProprietaire FROM contrat WHERE idLocataire = ? AND statutContrat = 'Actif' ORDER BY dateDebut DESC LIMIT 1");
    $stmtContrat->execute([$locataire_id]);
    $contrat_actif = $stmtContrat->fetch(PDO::FETCH_ASSOC);

    if($contrat_actif) {
        $idContratActif = $contrat_actif['idContrat'];
        $idBienContrat = $contrat_actif['idBien']; // Important pour lier la demande
        $idNotaireContrat = $contrat_actif['idNotaire'];
        $idProprietaireContrat = $contrat_actif['idProprietaire'];
    } else {
        // Pas de contrat actif, on peut quand même afficher l'historique mais pas faire de nouvelle demande
         $pageAlerts[] = ['type' => 'warning', 'message' => 'Aucun contrat actif trouvé. Vous ne pouvez pas soumettre de nouvelle demande de maintenance actuellement.'];
    }

    // Charger l'historique des demandes pour CE locataire
    $sqlHistorique = "SELECT idDemande, dateSoumission, description, statutDemande
                      FROM demandes_maintenance -- Utilise la nouvelle table
                      WHERE idLocataire = :idLocataire
                      ORDER BY dateSoumission DESC
                      LIMIT 50";
    $stmtHisto = $pdo->prepare($sqlHistorique);
    $stmtHisto->execute([':idLocataire' => $locataire_id]);
    $historique_demandes = $stmtHisto->fetchAll(PDO::FETCH_ASSOC);

    $initialDataLoaded = true;

} catch (PDOException $e) { $errorMessage = 'Erreur BDD.'; error_log("Err PDO loc-maintenance Init UserID {$user_id}: " . $e->getMessage());
} catch (Exception $e) { $errorMessage = $e->getMessage(); error_log("Err Gen loc-maintenance Init UserID {$user_id}: " . $e->getMessage()); }
if(!empty($errorMessage)) { $pageAlerts[] = ['type' => 'danger', 'message' => $errorMessage]; }


// --- TRAITEMENT DE LA SOUMISSION DU FORMULAIRE (POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'submit_maintenance') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Erreur lors de la soumission.'];

    // Re-vérifier les infos essentielles
    if ($locataire_id === null) { $response['message'] = "Locataire non identifié."; echo json_encode($response); exit; }
    if ($idContratActif === null || $idBienContrat === null) { $response['message'] = "Contrat actif ou bien associé introuvable."; echo json_encode($response); exit; }

    $description = trim($_POST['description'] ?? '');
    $photo_info = $_FILES['photo'] ?? null;
    $photo_name = null;
    $uploadDirServer = __DIR__ . '/uploads/maintenance/';
    $uploadDirRelative = 'uploads/maintenance/';

    if (empty($description)) { $response['message'] = "La description est obligatoire."; echo json_encode($response); exit; }

    // Gestion Upload
    if ($photo_info && $photo_info['error'] === UPLOAD_ERR_OK) {
        // ... (Code de validation et d'upload identique à la réponse précédente) ...
        $allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif']; $max_file_size = 5 * 1024 * 1024;
        if (!in_array($photo_info['type'], $allowed_mime_types)) { $response['message'] = 'Format image non autorisé.'; echo json_encode($response); exit; }
        if ($photo_info['size'] > $max_file_size) { $response['message'] = 'Image trop volumineuse (Max 5Mo).'; echo json_encode($response); exit; }
        if (!file_exists($uploadDirServer)) { mkdir($uploadDirServer, 0775, true); } // Tente de créer le dossier
        $file_extension = pathinfo($photo_info['name'], PATHINFO_EXTENSION);
        $photo_name = 'maint_' . $locataire_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($file_extension);
        $destination = $uploadDirServer . $photo_name;
        if (!move_uploaded_file($photo_info['tmp_name'], $destination)) { error_log("Erreur move_uploaded_file: " . $destination); $response['message'] = 'Erreur enregistrement image.'; echo json_encode($response); exit; }
    } elseif ($photo_info && $photo_info['error'] !== UPLOAD_ERR_NO_FILE) { $response['message'] = 'Erreur upload image (code: ' . $photo_info['error'] . ').'; echo json_encode($response); exit; }

    // Insertion BDD et Notifications
    $pdo->beginTransaction();
    try {
        // 1. Insérer la demande
        // *** Utilise la nouvelle table 'demandes_maintenance' ***
        $sqlInsertDemande = "INSERT INTO demandes_maintenance
                                (idLocataire, idContrat, idBien, idNotaire, idProprietaire, description, photo, statutDemande, dateSoumission)
                             VALUES
                                (:idLocataire, :idContrat, :idBien, :idNotaire, :idProprietaire, :description, :photo, 'Envoyée', NOW())";
        $stmtDemande = $pdo->prepare($sqlInsertDemande);
        $stmtDemande->execute([
            ':idLocataire' => $locataire_id,
            ':idContrat' => $idContratActif,
            ':idBien' => $idBienContrat, // Lier au bien
            ':idNotaire' => $idNotaireContrat, // Lier au notaire
            ':idProprietaire' => $idProprietaireContrat, // Lier au proprio
            ':description' => $description,
            ':photo' => $photo_name
        ]);
        $newDemandeId = $pdo->lastInsertId();

        // 2. Récupérer idUser Notaire et Propriétaire
         $idUserNotaire = null; $idUserProprietaire = null;
         if($idNotaireContrat) { $stmtUN = $pdo->prepare("SELECT idUser FROM notaire WHERE idNotaire = ?"); $stmtUN->execute([$idNotaireContrat]); $idUserNotaire = $stmtUN->fetchColumn(); }
         if($idProprietaireContrat) { $stmtUP = $pdo->prepare("SELECT idUser FROM proprietaire WHERE idProprietaire = ?"); $stmtUP->execute([$idProprietaireContrat]); $idUserProprietaire = $stmtUP->fetchColumn(); }

        // 3. Insérer les notifications
        if ($idUserNotaire || $idUserProprietaire) {
            $sqlInsertNotif = "INSERT INTO notification (idUser, contenu, dateNotif, statutNotif, typeNotif) VALUES (?, ?, CURDATE(), 'Envoyé', 'Maintenance')";
            $stmtNotif = $pdo->prepare($sqlInsertNotif);
            $contenuNotif = "Nvelle demande maintenance (#{$newDemandeId}) pour contrat #{$idContratActif}.";
            if ($idUserNotaire) { $stmtNotif->execute([$idUserNotaire, $contenuNotif]); }
            if ($idUserProprietaire) { $stmtNotif->execute([$idUserProprietaire, $contenuNotif]); }
        }

        $pdo->commit();
        $response = ['success' => true, 'message' => 'Demande de maintenance envoyée avec succès.'];

    } catch (PDOException $e) {
        $pdo->rollBack(); error_log("Erreur PDO submit maintenance: " . $e->getMessage());
        if ($photo_name && file_exists($uploadDirServer . $photo_name)) { unlink($uploadDirServer . $photo_name); }
        $response['message'] = "Erreur BDD enregistrement."; http_response_code(500);
    } catch (Exception $e) {
        $pdo->rollBack(); error_log("Erreur Gen submit maintenance: " . $e->getMessage());
        if ($photo_name && file_exists($uploadDirServer . $photo_name)) { unlink($uploadDirServer . $photo_name); }
        $response['message'] = "Erreur serveur interne."; http_response_code(500);
    }

    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <title>Demandes de Maintenance | Espace Locataire</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Soumission et suivi des demandes de maintenance." />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
    <script src="assets/js/config.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        /* Vos styles CSS */
         body { padding-top: 70px; } @media (min-width: 992px) { body { padding-top: 80px; } }
         .table th, .table td { vertical-align: middle; font-size: 0.875rem; }
         .table th { font-weight: 500; }
         .description-column { min-width: 250px; max-width: 400px; white-space: normal; word-wrap: break-word; }
         #nouvelleDemandeDescription { min-height: 120px; }
         #maintenanceRequestsBody .no-results-row td, #maintenanceRequestsBody .error-row td { text-align: center; font-style: italic; color: #6c757d; padding: 1.5rem; }
         .btn.loading { position: relative; pointer-events: none; color: transparent !important; }
         .btn.loading::after { content: ''; position: absolute; top: 50%; left: 50%; width: 1rem; height: 1rem; margin-top: -0.5rem; margin-left: -0.5rem; border: 2px solid rgba(255, 255, 255, 0.6); border-top-color: #ffffff; border-radius: 50%; animation: button-spinner .6s linear infinite; }
         @keyframes button-spinner { to { transform: rotate(360deg); } }
         .content-requires-data.loading-error { display: none; }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- ========== Topbar, Sidebar ========== -->
         <header class=""> <div class="topbar"> <div class="container-fluid"> <div class="navbar-header"> <div class="d-flex align-items-center gap-2"> <div class="topbar-item"><button type="button" class="button-toggle-menu topbar-button"><i class="ri-menu-2-line fs-24"></i></button></div> <form class="app-search d-none d-md-block me-auto"><div class="position-relative"><input type="search" class="form-control border-0" placeholder="Rechercher..." autocomplete="off" value=""><i class="ri-search-line search-widget-icon"></i></div></form> </div> <div class="d-flex align-items-center gap-1"> <div class="topbar-item"><button type="button" class="topbar-button" id="light-dark-mode"><i class="ri-moon-line fs-24 light-mode"></i><i class="ri-sun-line fs-24 dark-mode"></i></button></div> <div class="dropdown topbar-item d-none d-lg-flex"><button type="button" class="topbar-button" data-toggle="fullscreen"><i class="ri-fullscreen-line fs-24 fullscreen"></i><i class="ri-fullscreen-exit-line fs-24 quit-fullscreen"></i></button></div> <div class="dropdown topbar-item"> <button type="button" class="topbar-button position-relative" id="page-header-notifications-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="ri-notification-3-line fs-24"></i><span id="topbarUnreadCount" class="position-absolute topbar-badge fs-10 translate-middle badge bg-danger rounded-pill">0<span class="visually-hidden">notifications non lues</span></span></button> <div class="dropdown-menu py-0 dropdown-lg dropdown-menu-end" aria-labelledby="page-header-notifications-dropdown"> <div class="p-3 border-top-0 border-start-0 border-end-0 border-dashed border"><div class="row align-items-center"><div class="col"><h6 class="m-0 fs-16 fw-semibold"> Notifications</h6></div><div class="col-auto"><a href="javascript: void(0);" class="text-dark text-decoration-underline" onclick="markAllAsRead()"> <small>Marquer tout comme lu</small></a></div></div></div> <div data-simplebar style="max-height: 280px;" id="topbarNotificationList"></div> <div class="text-center py-3"><a href="locataire-notifications.php" class="btn btn-primary btn-sm">Voir toutes les notifications <i class="ri-arrow-right-line ms-1"></i></a></div> </div> </div> <div class="topbar-item d-none d-md-flex"><button type="button" class="topbar-button" id="theme-settings-btn" data-bs-toggle="offcanvas" data-bs-target="#theme-settings-offcanvas" aria-controls="theme-settings-offcanvas"><i class="ri-settings-4-line fs-24"></i></button></div> <div class="dropdown topbar-item"> <a type="button" class="topbar-button" id="page-header-user-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><span class="d-flex align-items-center"><img class="rounded-circle" width="32" src="assets/images/users/avatar-placeholder.png" alt="avatar-locataire"></span></a> <div class="dropdown-menu dropdown-menu-end"> <h6 class="dropdown-header" id="userDropdownHeader">Bienvenue <?= htmlspecialchars($locataire_info['prenom'] ?? 'Locataire') ?> !</h6> <a class="dropdown-item" href="locataire-profil.php"><i class="ri-user-line align-middle me-1"></i> <span class="align-middle">Mon Profil</span></a> <a class="dropdown-item active" href="locataire-maintenance.php"><i class="ri-tools-line align-middle me-1"></i> <span class="align-middle">Maintenance</span></a> <div class="dropdown-divider my-1"></div> <a class="dropdown-item text-danger" href="auth-signout.php"><i class="ri-logout-box-line align-middle me-1"></i> <span class="align-middle">Déconnexion</span></a> </div> </div> </div> </div> </div> </header>
         <div> <div class="offcanvas offcanvas-end border-0 rounded-start-4 overflow-hidden" tabindex="-1" id="theme-settings-offcanvas"> <div class="d-flex align-items-center bg-primary p-3 offcanvas-header"><h5 class="text-white m-0">Theme Settings</h5><button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="offcanvas" aria-label="Close"></button></div><div class="offcanvas-body p-0"><div data-simplebar class="h-100"><div class="p-3 settings-bar"></div></div></div><div class="offcanvas-footer border-top p-3 text-center"><div class="row"><div class="col"><button type="button" class="btn btn-danger w-100" id="reset-layout">Reset</button></div></div></div> </div> </div>
         <div class="main-nav"> <div class="logo-box"> <a href="locataire-dashboard.php" class="logo-dark"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-dark.png" class="logo-lg"></a> <a href="locataire-dashboard.php" class="logo-light"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-light.png" class="logo-lg"></a> </div> <button type="button" class="button-sm-hover" aria-label="Show Full Sidebar"><i class="ri-menu-2-line fs-24 button-sm-hover-icon"></i></button> <div class="scrollbar" data-simplebar> <ul class="navbar-nav" id="navbar-nav"> <li class="menu-title">Menu Locataire</li> <li class="nav-item"><a class="nav-link" href="locataire-dashboard.php"><span class="nav-icon"><i class="ri-dashboard-line"></i></span><span class="nav-text">Tableau de Bord</span></a></li> <li class="nav-item"><a class="nav-link" href="locataire-contrat.php"><span class="nav-icon"><i class="ri-file-text-line"></i></span><span class="nav-text">Mon Contrat</span></a></li> <li class="nav-item"><a class="nav-link" href="locataire-paiements.php"><span class="nav-icon"><i class="ri-secure-payment-line"></i></span><span class="nav-text">Paiements & Quittances</span></a></li> <li class="nav-item"><a class="nav-link active" href="locataire-maintenance.php"><span class="nav-icon"><i class="ri-tools-line"></i></span><span class="nav-text">Maintenance</span></a></li> <li class="nav-item"><a class="nav-link" href="locataire-notifications.php"><span class="nav-icon"><i class="ri-notification-3-line"></i></span><span class="nav-text">Notifications</span><span class="badge bg-danger badge-pill text-end" id="sidebarUnreadCount" style="display: none;"></span></a></li> </ul> </div> </div>
        <!-- ============================================================== -->

        <div class="page-content">
            <div class="container-fluid">
                <!-- Titre de Page -->
                <div class="row"> <div class="col-12"> <div class="page-title-box"> <h4 class="mb-0 fw-semibold">Demandes de Maintenance</h4> <ol class="breadcrumb mb-0"> <li class="breadcrumb-item"><a href="locataire-dashboard.php">Espace Locataire</a></li> <li class="breadcrumb-item active">Maintenance</li> </ol> </div> </div> </div>
                 <div id="pageAlertPlaceholder"> <?php foreach ($pageAlerts as $alert): ?> <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show"> <?php echo htmlspecialchars($alert['message']); ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button> </div> <?php endforeach; ?> </div>

                 <!-- Contenu Principal (Conditionné) -->
                 <div class="content-requires-data <?= !$initialDataLoaded ? 'loading-error' : '' ?>">
                     <!-- Bouton Nouvelle Demande -->
                     <div class="row mb-3">
                          <div class="col-12 text-end">
                               <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNouvelleDemande" <?= !$contrat_actif ? 'disabled title="Aucun contrat actif trouvé"' : '' ?>>
                                    <i class="ri-add-line me-1"></i> Nouvelle Demande
                               </button>
                          </div>
                     </div>

                     <!-- Tableau Historique Demandes -->
                     <div class="row">
                         <div class="col-12">
                              <div class="card">
                                   <div class="card-header"><h5 class="card-title mb-0">Historique de vos Demandes</h5></div>
                                   <div class="card-body p-0">
                                        <div class="table-responsive">
                                             <table id="tableDemandesMaintenance" class="table table-hover table-centered mb-0">
                                                  <thead class="table-light">
                                                       <tr>
                                                            <th>Date Soumission</th>
                                                            <th class="description-column">Description</th>
                                                            <th>Statut</th>
                                                            <!-- <th>Action</th> -->
                                                       </tr>
                                                  </thead>
                                                  <tbody id="maintenanceRequestsBody">
                                                    <?php if (empty($historique_demandes)): ?>
                                                        <tr class="no-results-row"><td colspan="3">Aucune demande de maintenance soumise pour le moment.</td></tr>
                                                    <?php else: ?>
                                                        <?php foreach ($historique_demandes as $demande):
                                                            $formattedDate = $demande['dateSoumission'] ? date("d/m/Y H:i", strtotime($demande['dateSoumission'])) : '-';
                                                            $descriptionCourte = htmlspecialchars(substr($demande['description'] ?? '', 0, 100)) . (strlen($demande['description'] ?? '') > 100 ? '...' : '');
                                                            $statutDemande = htmlspecialchars($demande['statutDemande'] ?? 'Inconnu');
                                                            $badgeClass = 'bg-secondary';
                                                            switch (strtolower($statutDemande)) {
                                                                case 'envoyée': $badgeClass = 'bg-warning text-dark'; break;
                                                                case 'prise en charge': case 'en cours': $badgeClass = 'bg-info'; break;
                                                                case 'terminée': case 'résolue': $badgeClass = 'bg-success'; break;
                                                                case 'refusée': case 'annulée': $badgeClass = 'bg-danger'; break;
                                                            }
                                                        ?>
                                                        <tr>
                                                            <td><?= $formattedDate ?></td>
                                                            <td class="description-column" title="<?= htmlspecialchars($demande['description'] ?? '') ?>"><?= $descriptionCourte ?></td>
                                                            <td><span class="badge <?= $badgeClass ?>"><?= $statutDemande ?></span></td>
                                                            <!-- <td><button class="btn btn-sm btn-light"><i class="ri-eye-line"></i></button></td> -->
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                  </tbody>
                                             </table>
                                        </div>
                                   </div>
                                   <div class="card-footer bg-white border-top d-flex justify-content-between align-items-center">
                                       <div class="fs-sm text-muted">Total: <?= count($historique_demandes) ?> demande(s)</div>
                                       <!-- Pagination si nécessaire -->
                                   </div>
                              </div>
                         </div>
                     </div>
                 </div><!-- Fin content-requires-data -->

            </div> <!-- container-fluid -->
        </div> <!-- page-content -->

         <!-- START MODALS -->
         <!-- Modal: Nouvelle Demande de Maintenance -->
         <div class="modal fade" id="modalNouvelleDemande" tabindex="-1" aria-labelledby="modalNouvelleDemandeLabel" aria-hidden="true">
             <div class="modal-dialog modal-lg modal-dialog-centered">
                 <div class="modal-content">
                     <div class="modal-header">
                         <h5 class="modal-title" id="modalNouvelleDemandeLabel">Nouvelle Demande de Maintenance</h5>
                         <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                     </div>
                     <div class="modal-body">
                          <div id="modalMaintenanceError" class="alert alert-danger d-none" role="alert"></div>
                         <form id="formNouvelleDemande" enctype="multipart/form-data" novalidate>
                              <input type="hidden" name="action" value="submit_maintenance">
                              <input type="hidden" name="idContrat" value="<?= htmlspecialchars($idContratActif ?? '') ?>">
                              <!-- On n'a plus besoin d'envoyer idLocataire, idBien, etc. car on les a côté serveur via idContrat -->

                             <div class="mb-3">
                                 <label for="nouvelleDemandeDescription" class="form-label">Description du problème <span class="text-danger">*</span></label>
                                 <textarea class="form-control" id="nouvelleDemandeDescription" name="description" rows="5" placeholder="Décrivez le problème précisément..." required></textarea>
                                 <div class="invalid-feedback">Veuillez décrire le problème.</div>
                             </div>
                             <div class="mb-3">
                                <label for="nouvelleDemandePhoto" class="form-label">Joindre une photo (Optionnel)</label>
                                <input class="form-control" type="file" id="nouvelleDemandePhoto" name="photo" accept="image/png, image/jpeg, image/gif">
                                 <div class="form-text">Formats: PNG, JPG, GIF. Taille max: 5Mo.</div>
                             </div>
                         </form>
                     </div>
                     <div class="modal-footer justify-content-between">
                          <span id="modalMaintenanceLoading" style="display: none;"><span class="spinner-border spinner-border-sm text-primary"></span> Envoi...</span>
                         <div>
                             <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                             <button type="button" class="btn btn-primary" id="submitDemandeBtn">Envoyer la Demande</button>
                         </div>
                     </div>
                 </div>
             </div>
         </div>
         <!-- End Modal: Nouvelle Demande -->
         <!-- END MODALS -->

        <!-- Footer -->
        <footer class="footer"> <div class="container-fluid"> <div class="row"> <div class="col-12 text-center"> <script>document.write(new Date().getFullYear())</script> © Gestion Locative Notariale. </div> </div> </div> </footer>
    </div> <!-- wrapper -->

    <!-- JS Vendor, Libs et App -->
    <script src="assets/js/vendor.js"></script>
    <script src="assets/js/app.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- SCRIPT PERSONNALISÉ -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
             const formNouvelleDemande = document.getElementById('formNouvelleDemande');
             const modalNouvelleDemandeEl = document.getElementById('modalNouvelleDemande');
             const modalNouvelleDemande = bootstrap.Modal.getOrCreateInstance(modalNouvelleDemandeEl);
             const submitDemandeBtn = document.getElementById('submitDemandeBtn');
             const modalErrorDiv = document.getElementById('modalMaintenanceError');
             const modalLoadingSpinner = document.getElementById('modalMaintenanceLoading');

             if (submitDemandeBtn && formNouvelleDemande) {
                 submitDemandeBtn.addEventListener('click', function() {
                     if (!formNouvelleDemande.checkValidity()) {
                         formNouvelleDemande.classList.add('was-validated');
                         displayModalError("Veuillez décrire le problème.");
                         return;
                     }
                     formNouvelleDemande.classList.remove('was-validated');
                     clearModalError();

                     const formData = new FormData(formNouvelleDemande);
                     const originalButtonText = submitDemandeBtn.innerHTML;
                     setModalLoading(true);

                     fetch('', { method: 'POST', body: formData })
                     .then(response => response.ok ? response.json() : response.text().then(text => { throw new Error(`Erreur ${response.status}: ${text}`) }))
                     .then(data => {
                         if (data.success) {
                             modalNouvelleDemande.hide(); formNouvelleDemande.reset();
                             Swal.fire({ icon: 'success', title: 'Envoyé !', text: data.message, timer: 3000, timerProgressBar: true })
                                 .then(() => { window.location.reload(); });
                         } else { displayModalError(data.message || "Erreur lors de l'envoi."); }
                     })
                     .catch(error => { console.error("Erreur soumission:", error); displayModalError("Erreur technique: " + error.message); })
                     .finally(() => { setModalLoading(false, originalButtonText); });
                 });
             }

            // Helpers modal
             function displayModalError(message) { modalErrorDiv.textContent = message; modalErrorDiv.classList.remove('d-none'); }
             function clearModalError() { modalErrorDiv.textContent = ''; modalErrorDiv.classList.add('d-none'); }
             function setModalLoading(isLoading, originalText = 'Envoyer la Demande') {
                 submitDemandeBtn.disabled = isLoading;
                 if (isLoading) { modalLoadingSpinner.style.display = 'inline-block'; submitDemandeBtn.innerHTML = 'Envoi...'; }
                 else { modalLoadingSpinner.style.display = 'none'; submitDemandeBtn.innerHTML = originalText; }
            }
             // Reset form on modal close
              modalNouvelleDemandeEl.addEventListener('hidden.bs.modal', () => { formNouvelleDemande.reset(); formNouvelleDemande.classList.remove('was-validated'); clearModalError(); });

        }); // Fin DOMContentLoaded
    </script>

</body>
</html>