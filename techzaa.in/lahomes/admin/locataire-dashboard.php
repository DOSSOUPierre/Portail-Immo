<?php
// --- locataire-dashboard.php (Ta Structure + Données DB) ---

// 1. Session & Auth (Locataire)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'Locataire') { header("Location: auth-signin.php"); exit; }

// 2. Connexion BDD ($pdo)
require_once 'db_connection.php'; // Assure-toi que ce chemin est correct (dans admin/)
if (!isset($pdo)) { die("Erreur BDD."); }

// 3. Récupérer ID Utilisateur et Prénom du Locataire connecté
$locataireUserId = $_SESSION['user_id'] ?? null;
$locatairePrenom = $_SESSION['user_prenom'] ?? 'Locataire'; // Utiliser le prénom de la session
if ($locataireUserId === null) { die("Erreur: ID utilisateur locataire non trouvé en session."); }
$idLocataire = $_SESSION['idLocataire'] ?? null;
if($idLocataire === null) {
    try {
        $stmt_lid = $pdo->prepare("SELECT idLocataire FROM locataire WHERE idUser = :userId");
        $stmt_lid->execute([':userId' => $locataireUserId]);
        $idLocataire = $stmt_lid->fetchColumn();
        if ($idLocataire) $_SESSION['idLocataire'] = (int)$idLocataire;
        else error_log("Warn: profil locataire non trouvé pour user " . $locataireUserId);
    } catch (PDOException $e) { error_log("PDO get_loc_id: " . $e->getMessage()); /* Gérer erreur */ }
}

// --- Initialisation des données ---
$contratActifData = null;
$paymentStatus = ['message' => '<span class="text-muted">Vérification...</span>', 'class' => 'text-muted', 'buttonText' => 'Vérifier Statut', 'disablePaymentButton' => true];
$maintenanceStatus = ['message' => "Aucune demande en cours."];
$recentNotifications = [];
$unreadNotificationCount = 0;
$pageAlerts = [];

// --- Requêtes pour récupérer les données ---
try {
    // Récupérer Contrat Actif (avec dates début et fin)
    $stmtContrat = $pdo->prepare("
        SELECT c.idContrat, c.dateDebut, c.dateFin, c.montantLoyer, b.adresse
        FROM contrat c
        JOIN bienimmobiliers b ON c.idBien = b.idBien
        WHERE c.idLocataire = ? AND c.statutContrat = 'Actif'
        ORDER BY c.idContrat DESC LIMIT 1
    ");
    if ($idLocataire) {
        $stmtContrat->execute([$idLocataire]);
        $contratActifData = $stmtContrat->fetch(PDO::FETCH_ASSOC);
    }

    // Déterminer Statut Paiement (Logique Simplifiée à affiner)
    if ($contratActifData) {
         // TODO: Mettre ici une vraie logique pour vérifier si le loyer du mois est dû
         $paiementAJour = true; // Supposer à jour pour l'instant
         if($paiementAJour) {
             $paymentStatus = ['message' => '<span class="text-success">Vos paiements sont à jour.</span>', 'class' => 'text-success', 'buttonText' => 'Voir Historique', 'disablePaymentButton' => false, 'link' => 'locataire-paiements.php'];
         } else {
             // $montantDu = ... // Calculer le montant dû
             // $paymentStatus = ['message' => '<span class="text-danger">Paiement requis: ' . number_format($montantDu) . ' FCFA</span>', 'class' => 'text-danger', 'buttonText' => 'Effectuer Paiement', 'disablePaymentButton' => false, 'link' => 'locataire-paiements.php#payer'];
         }
    } else {
         $paymentStatus = ['message' => '<span class="text-muted">Aucun contrat actif.</span>', 'class' => 'text-muted', 'buttonText' => 'Effectuer Paiement', 'disablePaymentButton' => true];
    }

    // Récupérer Maintenance (Placeholder)
    // ...

    // Récupérer Notifications & Compteur Non Lus
    $stmtNotifs = $pdo->prepare("SELECT idNotif, contenu, dateNotif, typeNotif, statutNotif FROM notification WHERE idUser = ? ORDER BY idNotif DESC LIMIT 5");
    $stmtNotifs->execute([$locataireUserId]); $recentNotifications = $stmtNotifs->fetchAll(PDO::FETCH_ASSOC);
    $stmtUnread = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE idUser = ? AND statutNotif = 'Envoyé'");
    $stmtUnread->execute([$locataireUserId]); $unreadNotificationCount = $stmtUnread->fetchColumn() ?: 0;

} catch (PDOException $e) { $pageAlerts[] = ['type' => 'danger', 'message' => 'Erreur chargement données dashboard.']; error_log("Err PDO dashboard locataire: " . $e->getMessage()); }
$pdo = null; // Fermer la connexion après les requêtes

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <!-- HEAD HTML (Ton code exact ici) -->
     <meta charset="utf-8" />
     <title>Tableau de Bord Locataire | GLN</title>
     <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <meta http-equiv="X-UA-Compatible" content="IE=edge" />
     <link rel="shortcut icon" href="assets/images/favicon.ico"> <!-- Vérifie chemin -->
     <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" /> <!-- Vérifie chemin -->
     <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" /> <!-- Vérifie chemin -->
     <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" id="app-style"/> <!-- Vérifie chemin -->
     <script src="assets/js/config.min.js"></script> <!-- Vérifie chemin -->
     <style>
        /* Tes styles CSS exacts ici */
        .welcome-message { margin-bottom: 1.5rem; } .contract-summary-card dt { font-weight: 500; color: #6c757d; min-width: 130px; float: left; clear: left; margin-bottom: 0.5rem; } .contract-summary-card dd { margin-left: 140px; font-weight: 600; color: #343a40; margin-bottom: 0.5rem;} .payment-status-card .status-text { font-size: 1.1rem; font-weight: 500; } .payment-action-card .btn-lg { padding: 0.75rem 1.5rem; font-size: 1.1rem; } .recent-notifications-card .list-group-item { padding: 0.75rem 1rem; border-bottom: 1px solid #e9ecef;} .recent-notifications-card .list-group-item:last-child { border-bottom: none; } .recent-notifications-card .notification-icon-sm { width: 28px; height: 28px; font-size: 1rem; } .recent-notifications-card .notification-text { font-size: 0.85rem; line-height: 1.4; } .recent-notifications-card .notification-time-sm { font-size: 0.75rem; color: #6c757d; } .placeholder-glow span { min-height: 1em; display: inline-block; } .card .placeholder { min-height: 1em; } dl dd span.placeholder { width: 60%; } .row > * { margin-bottom: 1.5rem; } .row:last-child > * { margin-bottom: 0; }
        .notification-unread { background-color: #f1f3f4 !important; font-weight: 500; }
        .notify-item, .list-group-item-action { font-weight: normal; background-color: #ffffff; }
        .list-group-item-action:hover { background-color: #f8f9fa; }
     </style>
</head>
<body>
    <div class="wrapper">
        <!-- ========== Topbar Start (Ton HTML exact ici) ========== -->
        <!-- Assure-toi que l'avatar et le nom utilisateur sont corrects -->
         <header class=""> <div class="topbar"> <div class="container-fluid"> <div class="navbar-header"> <div class="d-flex align-items-center gap-2"> <div class="topbar-item"><button type="button" class="button-toggle-menu topbar-button"><i class="ri-menu-2-line fs-24"></i></button></div> <form class="app-search d-none d-md-block me-auto"><div class="position-relative"><input type="search" class="form-control border-0" placeholder="Rechercher..." autocomplete="off" value=""><i class="ri-search-line search-widget-icon"></i></div></form> </div> <div class="d-flex align-items-center gap-1"> <div class="topbar-item"><button type="button" class="topbar-button" id="light-dark-mode"><i class="ri-moon-line fs-24 light-mode"></i><i class="ri-sun-line fs-24 dark-mode"></i></button></div> <div class="dropdown topbar-item d-none d-lg-flex"><button type="button" class="topbar-button" data-toggle="fullscreen"><i class="ri-fullscreen-line fs-24 fullscreen"></i><i class="ri-fullscreen-exit-line fs-24 quit-fullscreen"></i></button></div>
             <!-- Notifications Dropdown (Rempli par PHP) -->
             <div class="dropdown topbar-item"> <button type="button" class="topbar-button position-relative" id="page-header-notifications-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"> <i class="ri-notification-3-line fs-24"></i> <span id="topbarUnreadCount" class="position-absolute topbar-badge fs-10 translate-middle badge bg-danger rounded-pill <?php echo $unreadNotificationCount == 0 ? 'd-none' : ''; ?>"> <?php echo $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount; ?><span class="visually-hidden">notifs</span> </span> </button> <div class="dropdown-menu py-0 dropdown-lg dropdown-menu-end" aria-labelledby="page-header-notifications-dropdown"> <div class="p-3 border-bottom border-dashed"><div class="row align-items-center"><div class="col"><h6 class="m-0 fs-16 fw-semibold"> Notifications</h6></div><div class="col-auto"><a href="javascript: void(0);" class="text-dark text-decoration-underline" onclick="markAllLocataireNotificationsAsRead()"> <small>Marquer tout lu</small></a></div></div></div> <div data-simplebar style="max-height: 280px;" id="topbarNotificationList"> <?php /* ... Boucle Notifs ... */ ?> </div> <div class="text-center py-2 border-top border-dashed"><a href="locataire-notifications.php" class="btn btn-sm btn-light">Voir toutes</a></div> </div> </div>
             <div class="topbar-item d-none d-md-flex"><button type="button" class="topbar-button" id="theme-settings-btn" data-bs-toggle="offcanvas" data-bs-target="#theme-settings-offcanvas"><i class="ri-settings-4-line fs-24"></i></button></div>
             <!-- User Menu -->
             <div class="dropdown topbar-item"> <a type="button" class="topbar-button" id="page-header-user-dropdown" data-bs-toggle="dropdown"><span class="d-flex align-items-center"><img class="rounded-circle" width="32" src="assets/images/users/avatar-placeholder.png" alt="avatar-locataire"></span></a> <div class="dropdown-menu dropdown-menu-end"> <h6 class="dropdown-header" id="userDropdownHeader">Bienvenue <?php echo htmlspecialchars($locatairePrenom); ?> !</h6> <a class="dropdown-item" href="locataire-profil.php"><i class="ri-user-line align-middle me-1"></i>Mon Profil</a> <a class="dropdown-item" href="locataire-contrat.php"><i class="ri-file-list-3-line align-middle me-1"></i>Mon Contrat</a> <div class="dropdown-divider my-1"></div> <a class="dropdown-item text-danger" href="auth-signout.php"><i class="ri-logout-box-line align-middle me-1"></i>Déconnexion</a> </div> </div>
         </div> </div> </div> </div> </header>
        <!-- ========== Topbar End ========== -->

        <!-- ========== Right Sidebar & App Menu (Ton HTML exact ici) ========== -->
         <div> <div class="offcanvas offcanvas-end border-0 rounded-start-4 overflow-hidden" tabindex="-1" id="theme-settings-offcanvas">...</div> </div>
         <div class="main-nav">
               <div class="logo-box"> <!-- Logo -->
                    <a href="locataire-dashboard.html" class="logo-dark"><img src="assets/images/logo-sm.png" class="logo-sm" alt="logo sm"><img src="assets/images/logo-dark.png" class="logo-lg" alt="logo dark"></a>
                    <a href="locataire-dashboard.html" class="logo-light"><img src="assets/images/logo-sm.png" class="logo-sm" alt="logo sm"><img src="assets/images/logo-light.png" class="logo-lg" alt="logo light"></a>
               </div>
               <button type="button" class="button-sm-hover" aria-label="Show Full Sidebar"><i class="ri-menu-2-line fs-24 button-sm-hover-icon"></i></button>
               <div class="scrollbar" data-simplebar>
                    <ul class="navbar-nav" id="navbar-nav">
                         <li class="menu-title">Menu Locataire</li>
                         <li class="nav-item">
                              <a class="nav-link active" href="locataire-dashboard.html"><span class="nav-icon"><i class="ri-dashboard-line"></i></span><span class="nav-text">Tableau de Bord</span></a>
                         </li>
                         <li class="nav-item">
                              <!-- Lien Actif -->
                              <a class="nav-link" href="locataire-contrat.html"><span class="nav-icon"><i class="ri-file-text-line"></i></span><span class="nav-text">Mes Contrats</span></a> <!-- MODIFIÉ: Texte lien -->
                         </li>
                          <li class="nav-item">
                              <a class="nav-link" href="locataire-paiements.html"><span class="nav-icon"><i class="ri-secure-payment-line"></i></span><span class="nav-text">Paiements & Quittances</span></a>
                         </li>
                         <li class="nav-item">
                              <a class="nav-link" href="locataire-maintenance.html"><span class="nav-icon"><i class="ri-tools-line"></i></span><span class="nav-text">Maintenance</span></a>
                         </li>
                         <li class="nav-item">
                              <a class="nav-link" href="locataire-notifications.html"><span class="nav-icon"><i class="ri-notification-3-line"></i></span><span class="nav-text">Notifications</span><span class="badge bg-danger badge-pill text-end" id="sidebarUnreadCount" style="display: none;"></span></a>
                         </li>
                    </ul>
               </div>
          </div>
        <!-- Page Content -->
        <div class="page-content">
            <div class="container-fluid">
                <!-- Page Title (Ton HTML) -->
                <div class="row"> <div class="col-12"> <div class="page-title-box"> <h4 class="mb-0 fw-semibold">Tableau de Bord Locataire</h4> <ol class="breadcrumb m-0"> <li class="breadcrumb-item"><a href="javascript: void(0);">Espace Locataire</a></li> <li class="breadcrumb-item active">Tableau de Bord</li> </ol> </div> </div> </div>
                <!-- Accueil (Utilise PHP) -->
                 <div class="row"> <div class="col-12"> <h4 class="welcome-message">Bonjour, <span id="locatairePrenom"><?php echo htmlspecialchars($locatairePrenom); ?></span> !</h4> </div> </div>
                 <!-- Alertes PHP Page -->
                 <div id="pageAlertPlaceholder"> <?php foreach ($pageAlerts as $alert): ?> <div class="alert alert-<?php echo htmlspecialchars($alert['type']); ?> alert-dismissible fade show"> <?php echo htmlspecialchars($alert['message']); ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button> </div> <?php endforeach; ?> </div>

                <div class="row">
                    <!-- Colonne Principale -->
                    <div class="col-lg-7 col-xl-8">
                        <!-- Résumé Contrat (Ton HTML + PHP) -->
                        <div class="card contract-summary-card"> <div class="card-header"> <h5 class="card-title mb-0"><i class="ri-home-heart-line me-1"></i> Mon Logement</h5> </div> <div class="card-body">
                            <?php if ($contratActifData): ?>
                             <dl class="mb-0">
                                 <dt>Adresse :</dt> <dd id="logementAdresse"><?php echo htmlspecialchars($contratActifData['adresse'] ?? 'Non défini'); ?></dd>
                                 <!-- AFFICHAGE DATE DEBUT ET FIN -->
                                 <dt>Début Contrat:</dt> <dd id="contratDateDebut"><?php echo !empty($contratActifData['dateDebut']) ? date('d/m/Y', strtotime($contratActifData['dateDebut'])) : 'N/A'; ?></dd>
                                 <dt>Fin Contrat :</dt> <dd id="contratDateFin"><?php echo !empty($contratActifData['dateFin']) ? date('d/m/Y', strtotime($contratActifData['dateFin'])) : 'N/A'; ?></dd>
                                 <!-- FIN AFFICHAGE DATES -->
                                 <dt>Loyer :</dt> <dd id="contratMontantLoyer"><?php echo isset($contratActifData['montantLoyer']) ? number_format($contratActifData['montantLoyer'], 0, ',', ' ') . ' FCFA' : 'N/A'; ?></dd>
                             </dl>
                             <?php else: ?>
                                 <p class="text-muted text-center my-3">Aucun contrat de location actif.</p>
                             <?php endif; ?>
                        </div> </div>
                        <!-- Statut et Action Paiement (Ton HTML + PHP) -->
                        <div class="card payment-action-card text-center"> <div class="card-body"> <h5 class="card-title">Statut Paiements</h5> <div id="paiementStatusMessage" class="status-text my-3 <?php echo htmlspecialchars($paymentStatus['class'] ?? 'text-muted'); ?>"> <?php echo $paymentStatus['message'] ?? '<span class="text-muted">Statut indisponible</span>'; ?> </div>
                            <a href="<?php echo htmlspecialchars($paymentStatus['link'] ?? 'locataire-paiements.php'); ?>" id="btnPayerMain" class="btn btn-lg mt-2 <?php echo ($paymentStatus['disablePaymentButton'] ?? true) ? 'btn-secondary disabled' : 'btn-success'; ?>"> <i class="ri-secure-payment-line me-2"></i> <span id="btnPayerText"><?php echo htmlspecialchars($paymentStatus['buttonText'] ?? 'Voir Paiements'); ?></span> </a>
                        </div> </div>
                    </div>
                    <!-- Colonne Latérale -->
                     <div class="col-lg-5 col-xl-4 d-flex flex-column">
                        <!-- Maintenance (Ton HTML + PHP) -->
                        <div class="card mb-4"> <div class="card-header"><h5 class="card-title mb-0 fs-15"><i class="ri-tools-line me-1"></i> Maintenance</h5></div> <div class="card-body"> <p id="maintenanceStatus" class="mb-2 fs-14"><?php echo htmlspecialchars($maintenanceStatus['message'] ?? 'Statut indisponible.'); ?></p> <a href="locataire-maintenance.php" class="btn btn-outline-secondary btn-sm"> <i class="ri-add-line me-1"></i> Demande / Suivi</a> </div> </div>
                         <!-- Notifications Récentes (Ton HTML + PHP + Logique Lien Corrigée) -->
                        <div class="card recent-notifications-card flex-grow-1">
                            <div class="card-header d-flex justify-content-between align-items-center"> <h5 class="card-title mb-0 fs-15"><i class="ri-notification-3-line me-1"></i> Notifications Récentes</h5> <a href="locataire-notifications.php" class="btn-link fw-medium fs-13">Tout voir</a> </div>
                            <div class="card-body p-0">
                                 <div class="list-group list-group-flush" id="recentNotificationsList" style="max-height: 250px; overflow-y: auto;">
                                      <?php if (empty($recentNotifications)): ?> <div id="noRecentNotifications" class="list-group-item text-center text-muted py-3"> Aucune notification récente. </div>
                                      <?php else:
                                         $activityIcons = ['paiement' => 'ri-money-dollar-circle-line text-success bg-success-subtle', 'rappel' => 'ri-alarm-warning-line text-warning bg-warning-subtle', 'contrat' => 'ri-file-text-line text-info bg-info-subtle', 'maintenance' => 'ri-tools-line text-primary bg-primary-subtle', 'autre' => 'ri-information-line text-secondary bg-light', 'info' => 'ri-information-line text-secondary bg-light'];
                                         foreach ($recentNotifications as $notif): $isUnread = ($notif['statutNotif'] === 'Envoyé'); $notifDate = !empty($notif['dateNotif']) ? date('d/m H:i', strtotime($notif['dateNotif'])) : '-'; $lienNotification = "locataire-notifications.php#notif-" . $notif['idNotif']; $iconInfoKey = $notif['typeNotif'] ?? 'info'; $iconInfo = $activityIcons[$iconInfoKey] ?? $activityIcons['info']; list($iconClass, $iconColor, $bgColor) = explode(' ', $iconInfo . ' text-secondary bg-light');
                                         // ** CORRECTION LIEN SI SIGNATURE REQUISE **
                                         if (stripos($notif['contenu'], 'signer') !== false && preg_match('/contrat.*?\(#(\d+)\)/i', $notif['contenu'], $matches)) { $idContratTrouve = $matches[1]; $lienNotification = "signer_contrat.php?id=" . $idContratTrouve; } // Adapter le nom du fichier si différent ?>
                                         <a href="<?php echo htmlspecialchars($lienNotification); ?>" class="list-group-item list-group-item-action <?php echo $isUnread ? 'notification-unread' : ''; ?>" onclick="markNotificationAsRead(<?php echo $notif['idNotif']; ?>, this)"> <div class="d-flex align-items-center"> <div class="flex-shrink-0 me-2"> <div class="avatar-xs rounded-circle <?php echo $bgColor; ?> d-flex align-items-center justify-content-center notification-icon-sm"> <i class="<?php echo $iconClass; ?> <?php echo $iconColor; ?>"></i> </div> </div> <div class="flex-grow-1 overflow-hidden"> <p class="notification-text text-truncate mb-0 fs-13"><?php echo htmlspecialchars($notif['contenu']); ?></p> <small class="notification-time-sm text-muted"><?php echo $notifDate; ?></small> </div> </div> </a>
                                         <?php endforeach; endif; ?>
                                      <div id="noRecentNotificationsFallback" class="list-group-item text-center text-muted py-3 <?php echo !empty($recentNotifications) ? 'd-none' : ''; ?>"> Aucune notification récente. </div>
                                 </div>
                             </div>
                        </div>
                    </div>
                </div>
            </div> <!-- container-fluid -->
        </div> <!-- page-content -->
        <!-- Footer (Ton HTML) -->
         <footer class="footer"> ... </footer>
    </div> <!-- END wrapper -->

    <!-- Javascript (Tes Scripts) -->
    <script src="assets/js/vendor.js"></script> <!-- Vérifie chemin -->
    <script src="assets/js/app.js"></script> <!-- Vérifie chemin -->
    <!-- <script src="assets/libs/apexcharts/apexcharts.min.js"></script> -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script> <!-- Si besoin -->
    <script>
        // JS pour màj compteur topbar et marquer lu (Identique à la version précédente)
        document.addEventListener('DOMContentLoaded', function() {
            const unreadCount = <?php echo json_encode($unreadNotificationCount); ?>;
            const topbarBadge = document.getElementById('topbarUnreadCount');
            const sidebarBadge = document.getElementById('sidebarUnreadCount');
            window.updateUnreadCount = function(count) { /*...*/ }
            updateUnreadCount(unreadCount);
            window.markNotificationAsRead = async function(notificationId, element) { /*...*/ }
            window.markAllLocataireNotificationsAsRead = async function() { /*...*/ }
        });
    </script>
</body>
</html>