<?php
// --- locataire-contrat.php (Design imité + Contenu Contrat Locataire) ---

// 1. Session & Auth (Locataire)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'Locataire') { header("Location: auth-signin.php"); exit; }

// 2. Connexion BDD ($pdo)
require_once 'db_connection.php'; // Dans admin/
if (!isset($pdo)) { die("Erreur BDD."); }

// 3. Récupérer IDs Locataire & Nom
$locataireUserId = $_SESSION['user_id'];
$locatairePrenom = $_SESSION['user_prenom'] ?? 'Locataire';
$idLocataire = $_SESSION['idLocataire'] ?? null;
$locataireNomComplet = trim(($locatairePrenom ?? '') . ' ' . ($_SESSION['user_nom'] ?? ''));
if ($idLocataire === null && $locataireUserId) { try { $stmt_lid = $pdo->prepare("SELECT idLocataire, u.prenom, u.nom FROM locataire l JOIN utilisateurs u ON l.idUser = u.idUser WHERE l.idUser = :userId"); $stmt_lid->execute([':userId' => $locataireUserId]); $locInfo = $stmt_lid->fetch(PDO::FETCH_ASSOC); if ($locInfo) { $idLocataire = (int)$locInfo['idLocataire']; $_SESSION['idLocataire'] = $idLocataire; if (empty($locataireNomComplet)) { $locatairePrenom = $locInfo['prenom']; $locataireNomComplet = trim($locatairePrenom . ' ' . $locInfo['nom']); $_SESSION['user_prenom'] = $locatairePrenom; $_SESSION['user_nom'] = $locInfo['nom']; } } else { $errorMessage = "Profil locataire introuvable."; } } catch (PDOException $e) { $errorMessage = "Erreur profil."; error_log("PDO get_loc_id: " . $e->getMessage()); } }
if ($idLocataire === null && !isset($errorMessage)) { $errorMessage = "Impossible d'identifier le profil locataire."; }

// --- Initialisation ---
$contratsLocataire = [];
$pageAlerts = [];
$unreadNotificationCount = 0; // Pour les badges

// --- Récupération Données ---
try {
    // Récupérer TOUS les contrats du locataire + Nom Proprio
    if ($idLocataire) {
        $sqlContrats = "SELECT c.idContrat, c.dateDebut, c.dateFin, c.montantLoyer, c.statutContrat,
                               c.signatureLocataireDate, c.signatureProprioDate, c.signatureNotaireDate,
                               c.fichierContratSigneFinal, -- Chemin PDF final
                               b.adresse as bienAdresse,
                               CONCAT(u_prop.prenom, ' ', u_prop.nom) AS proprietaireNomComplet
                        FROM contrat c
                        JOIN bienimmobiliers b ON c.idBien = b.idBien
                        LEFT JOIN proprietaire p ON c.idProprietaire = p.idProprietaire
                        LEFT JOIN utilisateurs u_prop ON p.idUser = u_prop.idUser
                        WHERE c.idLocataire = :idLocataire
                        ORDER BY c.dateDebut DESC, c.idContrat DESC";
        $stmtContrats = $pdo->prepare($sqlContrats);
        $stmtContrats->execute([':idLocataire' => $idLocataire]);
        $contratsLocataire = $stmtContrats->fetchAll(PDO::FETCH_ASSOC);
    }

    // Compter les notifications non lues
    $stmtUnread = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE idUser = ? AND statutNotif = 'Envoyé'");
    $stmtUnread->execute([$locataireUserId]); $unreadNotificationCount = $stmtUnread->fetchColumn() ?: 0;

} catch (PDOException $e) { $pageAlerts[] = ['type' => 'danger', 'message' => 'Erreur chargement contrats.']; error_log("Err PDO locataire-contrat: " . $e->getMessage()); }
$pdo = null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <title>Mes Contrats | Espace Locataire</title> <!-- Titre Locataire -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="assets/images/favicon.ico"> <!-- Chemin depuis admin/ -->
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/flatpickr/flatpickr.min.css" rel="stylesheet" type="text/css" /> <!-- Gardé si filtres date ajoutés -->
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
    <script src="assets/js/config.min.js"></script>
    <!-- Styles inspirés de notaire-suivi-financier -->
    <style>
        .table th, .table td { vertical-align: middle; font-size: 0.875rem; }
        .table th { font-weight: 600; color: #6c757d; } /* Style Notaire */
        .btn-sm i { font-size: 1rem; }
        .form-select-sm, .form-control-sm { height: calc(1.5em + .5rem + 2px); padding: .25rem .5rem; font-size: .875rem; }
        .no-results-row td { text-align: center; font-style: italic; color: #6c757d; padding: 1rem; }
        .placeholder-glow span { min-height: 1em; display: inline-block; background-color: currentColor; opacity: 0.2;}
        td .placeholder { width: 80%; }
        .total-row td { font-weight: bold; border-top: 2px solid #dee2e6; } /* Conservé au cas où */
        /* Alignements spécifiques pour tableau contrat */
        #tableContratsLocataire th:nth-child(4), #tableContratsLocataire td:nth-child(4) { text-align: right; } /* Loyer */
        #tableContratsLocataire th:nth-child(5), #tableContratsLocataire td:nth-child(5) { text-align: center; } /* Statut */
        #tableContratsLocataire th:last-child, #tableContratsLocataire td:last-child { text-align: right; } /* Actions */
         /* Styles Modal Détails */
         #detailsContratContenu dt { font-weight: 600; color: var(--bs-primary); padding-top: 0.3rem; }
         #detailsContratContenu dd { margin-left: 0; padding-left: 0.5em; margin-bottom: 0.5rem; }
         #detailsContratContenu dd:not(:last-child) { border-bottom: 1px dashed #eee; padding-bottom: 0.5rem; }
         .status-badge { min-width: 100px; text-align: center; }
         .modal-body h6 { margin-top: 1rem; margin-bottom: 0.5rem; color: var(--bs-info); border-bottom: 1px solid #eee; padding-bottom: 0.25rem; }
         .modal-body h6:first-of-type { margin-top: 0; }
         /* Notification non lue */
        .notification-unread { background-color: #f1f3f4 !important; font-weight: 500; }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- ========== Topbar et Menu Latéral (LOCATAIRE) ========== -->
        <!-- Il est crucial d'utiliser l'interface LOCATAIRE ici -->
        <header class=""> <div class="topbar"> <div class="container-fluid"> <div class="navbar-header"> <div class="d-flex align-items-center gap-2"> <div class="topbar-item"><button type="button" class="button-toggle-menu topbar-button"><i class="ri-menu-2-line fs-24"></i></button></div> <form class="app-search d-none d-md-block me-auto"><div class="position-relative"><input type="search" class="form-control border-0" placeholder="Rechercher..." autocomplete="off" value=""><i class="ri-search-line search-widget-icon"></i></div></form> </div> <div class="d-flex align-items-center gap-1"> <!-- ... icons topbar ... -->
             <div class="dropdown topbar-item"> <button type="button" class="topbar-button position-relative" id="page-header-notifications-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"> <i class="ri-notification-3-line fs-24"></i> <span id="topbarUnreadCount" class="position-absolute topbar-badge fs-10 translate-middle badge bg-danger rounded-pill <?php echo $unreadNotificationCount == 0 ? 'd-none' : ''; ?>"> <?php echo $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount; ?><span class="visually-hidden">unread</span> </span> </button> <div class="dropdown-menu py-0 dropdown-lg dropdown-menu-end" aria-labelledby="page-header-notifications-dropdown"> <!-- Dropdown Notifs Locataire --> </div> </div>
             <div class="topbar-item d-none d-md-flex"><button type="button" class="topbar-button" id="theme-settings-btn" data-bs-toggle="offcanvas" data-bs-target="#theme-settings-offcanvas"><i class="ri-settings-4-line fs-24"></i></button></div>
             <div class="dropdown topbar-item"> <a type="button" class="topbar-button" id="page-header-user-dropdown" data-bs-toggle="dropdown"><span class="d-flex align-items-center"><img class="rounded-circle" width="32" src="assets/images/users/avatar-placeholder.png" alt="avatar-locataire"></span></a> <div class="dropdown-menu dropdown-menu-end"> <h6 class="dropdown-header">Bienvenue <?php echo htmlspecialchars($locatairePrenom); ?> !</h6> <a class="dropdown-item" href="locataire-profil.php"><i class="ri-user-line me-1"></i> Profil</a> <a class="dropdown-item active" href="locataire-contrat.php"><i class="ri-file-text-line me-1"></i> Contrat</a> <div class="dropdown-divider my-1"></div> <a class="dropdown-item text-danger" href="auth-signout.php"><i class="ri-logout-box-line me-1"></i> Déconnexion</a> </div> </div>
         </div> </div> </div> </div> </header>
         <div> <div class="offcanvas offcanvas-end border-0 rounded-start-4 overflow-hidden" tabindex="-1" id="theme-settings-offcanvas">...</div> </div>
          <div class="main-nav">
              <div class="logo-box"> <a href="locataire-dashboard.php" class="logo-dark"><img src="assets/images/logo-sm.png" alt="sm"><img src="assets/images/logo-dark.png" alt="dark"></a> <a href="locataire-dashboard.php" class="logo-light"><img src="assets/images/logo-sm.png" alt="sm"><img src="assets/images/logo-light.png" alt="light"></a> </div>
              <button type="button" class="button-sm-hover"><i class="ri-menu-2-line fs-24"></i></button>
              <div class="scrollbar" data-simplebar>
                   <ul class="navbar-nav" id="navbar-nav">
                        <li class="menu-title">Menu Locataire</li>
                        <li class="nav-item"><a class="nav-link menu-link" href="locataire-dashboard.php"><i class="ri-dashboard-line"></i><span>Tableau de Bord</span></a></li>
                        <li class="nav-item"><a class="nav-link menu-link active" href="locataire-contrat.php"><i class="ri-file-text-line"></i><span>Mon Contrat</span></a></li>
                        <li class="nav-item"><a class="nav-link menu-link" href="locataire-paiements.php"><i class="ri-secure-payment-line"></i><span>Paiements</span></a></li>
                        <li class="nav-item"><a class="nav-link menu-link" href="locataire-maintenance.php"><i class="ri-tools-line"></i><span>Maintenance</span></a></li>
                        <li class="nav-item"><a class="nav-link menu-link" href="locataire-notifications.php"><i class="ri-notification-3-line"></i><span>Notifications</span><span class="badge bg-danger badge-pill ms-auto <?php echo ($unreadNotificationCount ?? 0) == 0 ? 'd-none' : ''; ?>" id="sidebarUnreadCount"><?php echo ($unreadNotificationCount ?? 0); ?></span></a></li>
                   </ul>
              </div>
         </div>
        <!-- ========== Fin Menus ========== -->

        <div class="page-content">
            <div class="container-fluid">
                <!-- Titre Page Locataire -->
                <div class="row"> <div class="col-12"> <div class="page-title-box"> <h4 class="mb-0 fw-semibold">Mes Contrats de Location</h4> <ol class="breadcrumb m-0"> <li class="breadcrumb-item"><a href="locataire-dashboard.php">Espace Locataire</a></li> <li class="breadcrumb-item active">Mes Contrats</li> </ol> </div> </div> </div>
                <!-- Alertes -->
                <div id="pageAlertPlaceholder"> <?php foreach ($pageAlerts as $alert): ?> <div class="alert alert-<?php echo htmlspecialchars($alert['type']); ?> alert-dismissible fade show"> <?php echo htmlspecialchars($alert['message']); ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button> </div> <?php endforeach; ?> </div>
                <?php if ($errorMessage): ?><div class="alert alert-warning"><?php echo htmlspecialchars($errorMessage); ?></div><?php endif; ?>

                 <!-- Tableau des Contrats (Style inspiré Notaire Suivi Financier) -->
                 <div class="row"> <div class="col-12"> <div class="card shadow-sm">
                     <div class="card-header bg-light py-2"> <h5 class="card-title mb-0 fs-15">Liste de mes contrats</h5> </div>
                     <div class="card-body p-0"> <div class="table-responsive">
                         <table id="tableContratsLocataire" class="table table-hover table-centered mb-0"> <!-- Utilise les styles de locataire-paiements -->
                             <thead class="table-light">
                                 <tr>
                                     <th>Bien Immobilier</th>
                                     <th>Début</th>
                                     <th>Fin</th>
                                     <th class="text-end">Loyer (FCFA)</th>
                                     <th class="text-center">Statut</th>
                                     <th style="width: 120px; text-align: right;">Actions</th>
                                 </tr>
                             </thead>
                             <tbody id="contratsLocataireTableBody">
                                 <!-- Ligne "aucun contrat" -->
                                 <tr class="no-results-row <?php echo empty($contratsLocataire) ? '' : 'd-none'; ?>"> <td colspan="6" class="text-center text-muted py-3">Vous n'avez aucun contrat enregistré.</td> </tr>
                                 <!-- Boucle PHP pour afficher les contrats -->
                                 <?php if ($contratsLocataire): foreach ($contratsLocataire as $c):
                                     $statutClass = 'secondary'; $statutText = htmlspecialchars($c['statutContrat'] ?? 'Inconnu');
                                     switch($c['statutContrat']) { case 'Actif': $statutClass = 'success'; break; case 'Résilié': case 'Expiré': $statutClass = 'danger'; break; case 'En attente signatures': case 'Signé par les parties': case 'En attente signature Locataire': $statutClass = 'warning text-dark'; $statutText = 'En attente'; break; }
                                     // Condition Téléchargement : Statut Actif ET fichier défini
                                     $canDownload = $c['statutContrat'] === 'Actif' && !empty($c['fichierContratSigneFinal']);
                                     $downloadDisabled = !$canDownload ? 'disabled' : '';
                                     $downloadTitle = $canDownload ? 'Télécharger le contrat signé' : 'Document non disponible';
                                     // Stocker toutes les données pour le modal JS
                                     $detailsJson = htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8');
                                 ?>
                                 <tr id="contratRow-<?php echo $c['idContrat']; ?>" data-details='<?php echo $detailsJson; ?>'>
                                     <td><?php echo htmlspecialchars($c['bienAdresse'] ?? 'N/A'); ?></td>
                                     <td><?php echo $c['dateDebut'] ? date('d/m/Y', strtotime($c['dateDebut'])) : '-'; ?></td>
                                     <td><?php echo $c['dateFin'] ? date('d/m/Y', strtotime($c['dateFin'])) : '-'; ?></td>
                                     <td class="text-end"><?php echo number_format($c['montantLoyer'] ?? 0, 0, ',', ' '); ?></td>
                                     <td class="text-center"><span class="badge bg-<?php echo $statutClass; ?> status-badge"><?php echo $statutText; ?></span></td>
                                     <td style="text-align: right;">
                                         <div class="btn-group btn-group-sm">
                                              <button type="button" class="btn btn-soft-info" title="Voir Détails" onclick="viewContractDetails(<?php echo $c['idContrat']; ?>)"> <i class="ri-eye-line"></i> </button>
                                              <button type="button" class="btn btn-soft-primary" title="<?php echo $downloadTitle; ?>" onclick="downloadContract(<?php echo $c['idContrat']; ?>)" <?php echo $downloadDisabled; ?>> <i class="ri-download-2-line"></i> </button>
                                         </div>
                                     </td>
                                 </tr>
                                 <?php endforeach; endif; ?>
                             </tbody>
                         </table>
                     </div> </div>
                     <!-- Pagination (Optionnelle si beaucoup de contrats) -->
                     <!-- <div class="card-footer ..."><nav><ul id="paginationControls">...</ul></nav></div> -->
                 </div> </div> </div>
            </div> <!-- container-fluid -->
        </div> <!-- page-content -->

        <!-- ========== MODAL DETAILS CONTRAT ========== -->
         <div class="modal fade" id="modalDetailsContrat" tabindex="-1" aria-labelledby="modalDetailsContratLabel" aria-hidden="true">
             <div class="modal-dialog modal-lg modal-dialog-scrollable"> <div class="modal-content">
                 <div class="modal-header bg-info text-white"> <h5 class="modal-title" id="modalDetailsContratLabel">Détails du Contrat</h5> <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button> </div>
                 <div class="modal-body" id="detailsContratContenu"><p class="text-center py-4"><span class="spinner-border spinner-border-sm"></span> Chargement...</p></div>
                 <div class="modal-footer"> <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button> <button type="button" class="btn btn-primary btn-sm" id="modalDownloadBtn" onclick="downloadCurrentContract()" style="display: none;"> <i class="ri-download-2-line me-1"></i> Télécharger </button> </div>
             </div> </div>
         </div>

        <!-- Footer -->
         <footer class="footer">...</footer>
    </div> <!-- wrapper -->

    <!-- JS Vendor & App -->
    <script src="assets/js/vendor.js"></script>
    <script src="assets/js/app.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Script Page (Fonctionnel pour Voir/Télécharger) -->
    <script>
        function htmlspecialchars(str) { if (typeof str !== 'string') return str; const map = { '&': '&', '<': '<', '>': '>', '"': '"', "'": ''' }; return str.replace(/[&<>"']/g, function(m) { return map[m]; }); }

        const detailsModalEl = document.getElementById('modalDetailsContrat');
        const detailsModal = detailsModalEl ? new bootstrap.Modal(detailsModalEl) : null;
        const detailsModalContent = document.getElementById('detailsContratContenu');
        const modalDownloadBtn = document.getElementById('modalDownloadBtn');
        let currentContractIdForDownload = null;

        window.viewContractDetails = function(contractId) {
            if (!detailsModal || !detailsModalContent || !modalDownloadBtn) { console.error("Modal elements missing!"); return; }
            detailsModalContent.innerHTML = '<p class="text-center py-4"><span class="spinner-border spinner-border-sm"></span> Chargement...</p>';
            modalDownloadBtn.style.display = 'none'; currentContractIdForDownload = null; detailsModal.show();

            const row = document.getElementById(`contratRow-${contractId}`);
            const detailsDataString = row ? row.getAttribute('data-details') : null;

            if (detailsDataString) {
                try {
                    const details = JSON.parse(detailsDataString);
                    const formattedLoyer = `${parseInt(details.montantLoyer || 0).toLocaleString('fr-FR')} FCFA`;
                    let statutClass = 'secondary'; let statutText = htmlspecialchars(details.statutContrat || '?');
                    switch(details.statutContrat) { case 'Actif': statutClass = 'success'; break; case 'Résilié': case 'Expiré': statutClass = 'danger'; break; case 'En attente signatures': case 'Signé par les parties': case 'En attente signature Locataire': statutClass = 'warning text-dark'; statutText = 'En attente'; break; }
                    const sigProp = details.signatureProprioDate ? '<span class="badge bg-success-lighten text-success">Signé</span>' : '<span class="badge bg-warning-lighten text-warning">Attente</span>';
                    const sigLoc = details.signatureLocataireDate ? '<span class="badge bg-success-lighten text-success">Signé (Vous)</span>' : '<span class="badge bg-danger-lighten text-danger">Non Signé</span>';
                    const sigNot = details.signatureNotaireDate ? '<span class="badge bg-success-lighten text-success">Activé</span>' : '<span class="badge bg-secondary-lighten text-secondary">Attente</span>';
                    const canDownload = details.statutContrat === 'Actif' && details.fichierContratSigneFinal;

                    // Utilisation de dl/dt/dd pour un meilleur formatage
                    detailsModalContent.innerHTML = `
                         <h6>Informations Générales</h6>
                         <dl class="row mb-0">
                             <dt class="col-sm-4">Bien Immobilier:</dt><dd class="col-sm-8">${htmlspecialchars(details.bienAdresse)}</dd>
                             <dt class="col-sm-4">Propriétaire:</dt><dd class="col-sm-8">${htmlspecialchars(details.proprietaireNomComplet || 'N/A')}</dd>
                             <dt class="col-sm-4">Date Début:</dt><dd class="col-sm-8">${details.dateDebut ? new Date(details.dateDebut).toLocaleDateString('fr-FR') : '-'}</dd>
                             <dt class="col-sm-4">Date Fin:</dt><dd class="col-sm-8">${details.dateFin ? new Date(details.dateFin).toLocaleDateString('fr-FR') : '-'}</dd>
                             <dt class="col-sm-4">Loyer Mensuel:</dt><dd class="col-sm-8">${formattedLoyer}</dd>
                             <dt class="col-sm-4">Statut Contrat:</dt><dd class="col-sm-8"><span class="badge bg-${statutClass}">${statutText}</span></dd>
                         </dl>
                         <h6>État des Signatures</h6>
                         <dl class="row mb-0">
                             <dt class="col-sm-4">Signature Propriétaire:</dt><dd class="col-sm-8">${sigProp}</dd>
                             <dt class="col-sm-4">Votre Signature:</dt><dd class="col-sm-8">${sigLoc}</dd>
                             <dt class="col-sm-4">Activation Notaire:</dt><dd class="col-sm-8">${sigNot}</dd>
                         </dl>`;

                     if (canDownload) { currentContractIdForDownload = contractId; modalDownloadBtn.style.display = 'inline-block'; }
                     else { modalDownloadBtn.style.display = 'none'; }

                 } catch (e) { console.error("Erreur parsing JSON:", e); detailsModalContent.innerHTML = '<p class="text-danger text-center">Erreur lecture détails.</p>'; }
            } else { detailsModalContent.innerHTML = '<p class="text-danger text-center">Détails introuvables.</p>'; }
        }

        window.downloadCurrentContract = function() { if(currentContractIdForDownload) downloadContract(currentContractIdForDownload); }
        window.downloadContract = function(contractId) {
            if (!contractId) return;
             const downloadUrl = `download_final_contract.php?id=${contractId}`; // Script dédié
             console.log("Tentative téléchargement:", downloadUrl);
             window.open(downloadUrl, '_blank'); // Ouvre dans un nouvel onglet
        }

        // --- JS Notifications ---
        document.addEventListener('DOMContentLoaded', function() {
             const unreadCount = <?php echo json_encode($unreadNotificationCount ?? 0); ?>;
             const topbarBadge = document.getElementById('topbarUnreadCount');
             const sidebarBadge = document.getElementById('sidebarUnreadCount');
             window.updateUnreadCount = function(count) { if(topbarBadge){/*...*/} if(sidebarBadge){/*...*/} }
             updateUnreadCount(unreadCount);
             window.markNotificationAsRead = async function(notificationId, element) { /* ... AJAX vers handler locataire ... */ }
             window.markAllLocataireNotificationsAsRead = async function() { /* ... AJAX vers handler locataire ... */ }
        });
    </script>
</body>
</html>