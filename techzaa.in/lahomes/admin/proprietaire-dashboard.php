<?php
// --- proprietaire-dashboard.php (CORRIGÉ AVEC LOGIQUE LIEN NOTIFICATION) ---

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'Propriétaire') { header("Location: auth-signin.php"); exit; }

require_once 'db_connection.php'; // Assure-toi que $pdo est défini ici

$idProprietaire = $_SESSION['idProprietaire'] ?? null; // Récupéré lors de la connexion
$proprietaireUserId = $_SESSION['user_id'] ?? null; // ID Utilisateur général

// Vérification essentielle
if ($proprietaireUserId === null) { die("Erreur: ID utilisateur propriétaire non trouvé en session."); }

// --- Initialisation des données ---
$stats = ['totalBiens' => 0, 'biensOccupes' => 0, 'tauxOccupation' => 0, 'revenusMois' => 0, 'biensEnAttente' => 0];
$recentPayments = [];
$recentNotifications = []; // Tableau pour les notifications
$unreadNotificationCount = 0; // Compteur non lus
$pageAlerts = [];

// --- Requêtes pour les Statistiques, Paiements & Notifications ---
try {
    // Stats Biens (Inchangé)
    $stmtStatsBiens = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN statut = 'Occupé' THEN 1 ELSE 0 END) AS occupes, SUM(CASE WHEN supervisionStatut = 'En attente' THEN 1 ELSE 0 END) AS en_attente FROM bienimmobiliers WHERE idProprietaire = ?");
    $stmtStatsBiens->execute([$idProprietaire]); $statsBiens = $stmtStatsBiens->fetch(PDO::FETCH_ASSOC);
    if ($statsBiens) { $stats['totalBiens'] = (int)$statsBiens['total']; $stats['biensOccupes'] = (int)$statsBiens['occupes']; $stats['biensEnAttente'] = (int)$statsBiens['en_attente']; $stats['tauxOccupation'] = ($stats['totalBiens'] > 0) ? round(($stats['biensOccupes'] / $stats['totalBiens']) * 100, 1) : 0; }

    // Stats Revenus (Inchangé)
    $stmtRevenus = $pdo->prepare("SELECT SUM(pl.montant) AS total FROM paiementloyer pl JOIN contrat c ON pl.idContrat = c.idContrat WHERE c.idProprietaire = ? AND pl.statutPaiement = 'validé' AND MONTH(pl.datePaiement) = MONTH(CURRENT_DATE()) AND YEAR(pl.datePaiement) = YEAR(CURRENT_DATE())");
    $stmtRevenus->execute([$idProprietaire]); $stats['revenusMois'] = $stmtRevenus->fetchColumn() ?: 0;

    // Paiements Récents (Inchangé)
    $stmtPaiements = $pdo->prepare("SELECT pl.datePaiement, pl.montant, CONCAT(u.prenom, ' ', u.nom) AS locataireNom, b.adresse AS bienAdresse FROM paiementloyer pl JOIN contrat c ON pl.idContrat = c.idContrat JOIN locataire loc ON c.idLocataire = loc.idLocataire JOIN utilisateurs u ON loc.idUser = u.idUser JOIN bienimmobiliers b ON c.idBien = b.idBien WHERE c.idProprietaire = ? AND pl.statutPaiement = 'validé' ORDER BY pl.datePaiement DESC, pl.idPaiement DESC LIMIT 4");
    $stmtPaiements->execute([$idProprietaire]); $recentPayments = $stmtPaiements->fetchAll(PDO::FETCH_ASSOC);

    // Récupérer les Notifications (Inchangé)
    $stmtNotifs = $pdo->prepare("SELECT idNotif, contenu, dateNotif, typeNotif, statutNotif FROM notification WHERE idUser = ? ORDER BY idNotif DESC LIMIT 5"); // Tri par ID plus fiable
    $stmtNotifs->execute([$proprietaireUserId]); $recentNotifications = $stmtNotifs->fetchAll(PDO::FETCH_ASSOC);

    // Compter les non lues (Inchangé)
    $stmtUnread = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE idUser = ? AND statutNotif = 'Envoyé'");
    $stmtUnread->execute([$proprietaireUserId]); $unreadNotificationCount = $stmtUnread->fetchColumn() ?: 0;

} catch (PDOException $e) { $pageAlerts[] = ['type' => 'danger', 'message' => 'Erreur chargement données dashboard.']; error_log("Err PDO dashboard proprietaire: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <!-- ... (head HTML identique) ... -->
     <meta charset="utf-8" />
    <title>Tableau de Bord Propriétaire | GLN</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" id="app-style"/>
    <script src="assets/js/config.min.js"></script>
     <style> /* Styles identiques */
          .stat-card .avatar-title { font-size: 1.7rem; } .stat-card h4 { font-size: 1.4rem; font-weight: 600; } .stat-card p { font-size: 0.85rem; color: #6c757d; margin-bottom: 0.25rem; }
          .recent-list-card .list-group-item { padding: 0.65rem 1rem; border-bottom: 1px solid #e9ecef; } .recent-list-card .list-group-item:last-child { border-bottom: none; } .recent-list-card .list-icon { width: 30px; height: 30px; font-size: 1rem; } .recent-list-card .item-text { font-size: 0.875rem; line-height: 1.4; } .recent-list-card .item-subtext { font-size: 0.75rem; color: #6c757d; } .recent-list-card .item-amount { font-weight: 500; }
          .quick-actions-card .btn i { margin-right: 0.4rem; } .placeholder-glow span { min-height: 1em; display: inline-block; } .card .placeholder { min-height: 1em; } .row > * { margin-bottom: 1.5rem; } .row:last-child > * { margin-bottom: 0; }
          .notification-unread { background-color: #f1f3f4 !important; /* Force background */ font-weight: 500; }
          .notify-item, .list-group-item-action { font-weight: normal; background-color: #ffffff; }
          .list-group-item-action:hover { background-color: #f8f9fa; }
     </style>
</head>
<body>
    <div class="wrapper">
        <!-- ========== Topbar Start ========== -->
        <header class=""> <div class="topbar"> <div class="container-fluid"> <div class="navbar-header"> <div class="d-flex align-items-center gap-2"> <div class="topbar-item"><button type="button" class="button-toggle-menu topbar-button"><i class="ri-menu-2-line fs-24"></i></button></div> <form class="app-search d-none d-md-block me-auto"><div class="position-relative"><input type="search" class="form-control border-0" placeholder="Rechercher..." autocomplete="off" value=""><i class="ri-search-line search-widget-icon"></i></div></form> </div> <div class="d-flex align-items-center gap-1"> <div class="topbar-item"><button type="button" class="topbar-button" id="light-dark-mode"><i class="ri-moon-line fs-24 light-mode"></i><i class="ri-sun-line fs-24 dark-mode"></i></button></div> <div class="dropdown topbar-item d-none d-lg-flex"><button type="button" class="topbar-button" data-toggle="fullscreen"><i class="ri-fullscreen-line fs-24 fullscreen"></i><i class="ri-fullscreen-exit-line fs-24 quit-fullscreen"></i></button></div>
            <!-- *** Notifications Dropdown AVEC LOGIQUE LIEN CORRIGÉE *** -->
            <div class="dropdown topbar-item">
                 <button type="button" class="topbar-button position-relative" id="page-header-notifications-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                     <i class="ri-notification-3-line fs-24"></i>
                     <span id="topbarUnreadCount" class="position-absolute topbar-badge fs-10 translate-middle badge bg-danger rounded-pill <?php echo $unreadNotificationCount == 0 ? 'd-none' : ''; ?>">
                         <?php echo $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount; ?><span class="visually-hidden">notifications</span>
                     </span>
                 </button>
                 <div class="dropdown-menu py-0 dropdown-lg dropdown-menu-end" aria-labelledby="page-header-notifications-dropdown">
                      <div class="p-3 border-bottom border-dashed"><div class="row align-items-center"><div class="col"><h6 class="m-0 fs-16 fw-semibold"> Notifications</h6></div><div class="col-auto"><a href="javascript: void(0);" class="text-dark text-decoration-underline" onclick="markAllOwnerNotificationsAsRead()"> <small>Marquer tout lu</small></a></div></div></div>
                      <div data-simplebar style="max-height: 280px;" id="topbarNotificationList">
                           <?php if (empty($recentNotifications)): ?>
                                <h6 class="text-muted fs-13 fw-normal p-3 mb-0 text-center">Aucune notification</h6>
                           <?php else:
                                foreach (array_slice($recentNotifications, 0, 4) as $notif):
                                    $isUnread = ($notif['statutNotif'] === 'Envoyé'); // Non lu si 'Envoyé'
                                    $notifDate = !empty($notif['dateNotif']) ? date('d/m H:i', strtotime($notif['dateNotif'])) : '';

                                    // <<<--- DÉBUT LOGIQUE LIEN ---<<<
                                    $lienNotification = "proprietaire-notifications.php#notif-" . $notif['idNotif']; // Lien par défaut
                                    $idContratTrouve = null;
                                    $iconClass = 'ri-information-line'; $iconColor = 'text-secondary'; $bgColor = 'bg-light';
                                    // Essayer d'extraire l'ID du contrat en cherchant "(#ID)"
                                    if (preg_match('/\(#(\d+)\)/', $notif['contenu'], $matches)) {
                                        $idContratTrouve = $matches[1];
                                        $lienNotification = "signer-contrats.php?id=" . $idContratTrouve; // Pointe vers la page de signature
                                        $iconClass = 'ri-file-text-line'; $iconColor = 'text-info'; $bgColor = 'bg-info-subtle'; // Icône contrat
                                    } elseif (stripos($notif['contenu'], 'paiement') !== false || stripos($notif['contenu'], 'loyer') !== false) { $iconClass = 'ri-money-dollar-circle-line'; $iconColor = 'text-success'; $bgColor='bg-success-subtle';
                                    } elseif (stripos($notif['contenu'], 'bien validé') !== false || stripos($notif['contenu'], 'bien approuvé') !== false) { $iconClass = 'ri-checkbox-circle-line'; $iconColor = 'text-success'; $bgColor='bg-success-subtle';
                                    } elseif (stripos($notif['contenu'], 'bien rejeté') !== false || stripos($notif['contenu'], 'bien suspendu') !== false) { $iconClass = 'ri-close-circle-line'; $iconColor = 'text-danger'; $bgColor='bg-danger-subtle'; }
                                    // >>>--- FIN LOGIQUE LIEN --- >>>
                                ?>
                                <a href="<?php echo htmlspecialchars($lienNotification); ?>" class="dropdown-item notify-item <?php echo $isUnread ? 'notification-unread' : ''; ?>" onclick="markNotificationAsRead(<?php echo $notif['idNotif']; ?>, this)">
                                    <div class="notify-icon <?php echo $bgColor; ?> <?php echo $iconColor; ?>"><i class="<?php echo $iconClass; ?>"></i></div>
                                    <p class="notify-details">
                                        <?php echo htmlspecialchars(substr($notif['contenu'], 0, 60)) . (strlen($notif['contenu']) > 60 ? '...' : ''); ?>
                                        <small class="noti-time text-muted"><?php echo $notifDate; ?></small>
                                    </p>
                                </a>
                           <?php endforeach; endif; ?>
                      </div>
                      <div class="text-center py-2 border-top border-dashed"><a href="proprietaire-notifications.php" class="btn btn-sm btn-light">Voir toutes</a></div>
                 </div>
            </div>
             <!-- Reste Topbar -->
             <div class="topbar-item d-none d-md-flex"><button type="button" class="topbar-button" id="theme-settings-btn" data-bs-toggle="offcanvas" data-bs-target="#theme-settings-offcanvas" aria-controls="theme-settings-offcanvas"><i class="ri-settings-4-line fs-24"></i></button></div>
             <div class="dropdown topbar-item"> <a type="button" class="topbar-button" id="page-header-user-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><span class="d-flex align-items-center"><img class="rounded-circle" width="32" src="assets/images/users/avatar-proprietaire.png" alt="proprio"></span></a> <div class="dropdown-menu dropdown-menu-end"> <h6 class="dropdown-header">Bienvenue <?php echo htmlspecialchars($_SESSION['user_prenom'] ?? 'Propriétaire'); ?> !</h6> <a class="dropdown-item" href="#profil"><i class="ri-user-line me-1"></i> Profil</a> <div class="dropdown-divider my-1"></div> <a class="dropdown-item text-danger" href="auth-signout.php"><i class="ri-logout-box-line me-1"></i> Déconnexion</a> </div> </div>
         </div> </div> </div> </div> </header>
        <!-- ========== Topbar End ========== -->

        <!-- ========== Right Sidebar & App Menu (Lien Signer Contrats Ajouté) ========== -->
         <div> <div class="offcanvas offcanvas-end border-0 rounded-start-4 overflow-hidden" tabindex="-1" id="theme-settings-offcanvas"> <div class="d-flex align-items-center bg-primary p-3 offcanvas-header"><h5 class="text-white m-0">Theme Settings</h5><button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="offcanvas" aria-label="Close"></button></div><div class="offcanvas-body p-0"><div data-simplebar class="h-100"><div class="p-3 settings-bar"></div></div></div><div class="offcanvas-footer border-top p-3 text-center"><div class="row"><div class="col"><button type="button" class="btn btn-danger w-100" id="reset-layout">Reset</button></div></div></div> </div> </div>
         <div class="main-nav"> <div class="logo-box"><a href="proprietaire-dashboard.php" class="logo-dark"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-dark.png" class="logo-lg"></a><a href="proprietaire-dashboard.php" class="logo-light"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-light.png" class="logo-lg"></a></div> <button type="button" class="button-sm-hover" aria-label="Show Full Sidebar"><i class="ri-menu-2-line fs-24 button-sm-hover-icon"></i></button> <div class="scrollbar" data-simplebar> <ul class="navbar-nav" id="navbar-nav"> <li class="menu-title">Menu Propriétaire</li> <li class="nav-item"><a class="nav-link menu-link active" href="proprietaire-dashboard.php"><i class="ri-dashboard-2-line"></i><span>Tableau de Bord</span></a></li> <li class="nav-item"><a class="nav-link menu-link" href="proprietaire-mes-biens.php"><i class="ri-community-line"></i><span>Mes Biens</span></a></li> <li class="nav-item"><a class="nav-link menu-link" href="proprietaire-finances.php"><i class="ri-money-dollar-circle-line"></i><span>Finances</span></a></li> <li class="nav-item"><a class="nav-link menu-link" href="proprietaire-contrats.php"><i class="ri-file-list-3-line"></i><span>Contrats</span></a></li> <li class="nav-item"><a class="nav-link menu-link" href="proprietaire-notifications.php"><i class="ri-notification-3-line"></i><span>Notifications</span><span class="badge bg-danger badge-pill ms-auto" id="sidebarUnreadCount" <?php echo $unreadNotificationCount == 0 ? 'style="display: none;"' : ''; ?>><?php echo $unreadNotificationCount; ?></span></a></li> <li class="nav-item"><a class="nav-link menu-link" href="signer-contrats.php"></a></li></ul> </div> </div>
        <!-- ========== Fin HTML Intégré ========== -->

        <!-- Page Content -->
        <div class="page-content">
            <div class="container-fluid">
                <!-- Page Title -->
                <div class="row"> <div class="col-12"> <div class="page-title-box"> <h4 class="mb-0 fw-semibold">Tableau de Bord Propriétaire</h4> <ol class="breadcrumb mb-0"> <li class="breadcrumb-item"><a href="javascript: void(0);">Propriétaire</a></li> <li class="breadcrumb-item active">Tableau de Bord</li> </ol> </div> </div> </div>

                 <!-- Alertes PHP Page -->
                 <div id="pageAlertPlaceholder"> <?php foreach ($pageAlerts as $alert): ?> <div class="alert alert-<?php echo htmlspecialchars($alert['type']); ?> alert-dismissible fade show"> <?php echo htmlspecialchars($alert['message']); ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button> </div> <?php endforeach; ?> </div>

                <!-- Ligne 1: Stats -->
                <div class="row">
                    <div class="col-md-6 col-xl-3"> <div class="card stat-card"> <div class="card-body"> <div class="d-flex align-items-center"> <div class="flex-shrink-0 me-3"> <div class="avatar-sm bg-primary-subtle rounded d-flex align-items-center justify-content-center"> <i class="ri-community-line text-primary avatar-title"></i> </div> </div> <div class="flex-grow-1 overflow-hidden"> <p class="mb-1 text-truncate">Total Biens</p> <h4 class="text-dark mb-0"><?php echo number_format($stats['totalBiens']); ?></h4> </div> </div> </div> </div> </div>
                    <div class="col-md-6 col-xl-3"> <div class="card stat-card"> <div class="card-body"> <div class="d-flex align-items-center"> <div class="flex-shrink-0 me-3"> <div class="avatar-sm bg-warning-subtle rounded d-flex align-items-center justify-content-center"> <i class="ri-user-follow-line text-warning avatar-title"></i> </div> </div> <div class="flex-grow-1 overflow-hidden"> <p class="mb-1 text-truncate">Biens Occupés / Taux</p> <h4 class="text-dark mb-0"><?php echo number_format($stats['biensOccupes']); ?> <small class="fs-6 fw-normal"><?php echo $stats['tauxOccupation']; ?>%</small></h4> </div> </div> </div> </div> </div>
                    <div class="col-md-6 col-xl-3"> <div class="card stat-card"> <div class="card-body"> <div class="d-flex align-items-center"> <div class="flex-shrink-0 me-3"> <div class="avatar-sm bg-success-subtle rounded d-flex align-items-center justify-content-center"> <i class="ri-money-dollar-circle-line text-success avatar-title"></i> </div> </div> <div class="flex-grow-1 overflow-hidden"> <p class="mb-1 text-truncate">Revenus (Mois)</p> <h4 class="text-dark mb-0"><?php echo number_format($stats['revenusMois']); ?> <small class="fs-6 fw-normal">FCFA</small></h4> </div> </div> </div> </div> </div>
                    <div class="col-md-6 col-xl-3"> <div class="card stat-card"> <div class="card-body"> <div class="d-flex align-items-center"> <div class="flex-shrink-0 me-3"> <div class="avatar-sm bg-info-subtle rounded d-flex align-items-center justify-content-center"> <i class="ri-time-line text-info avatar-title"></i> </div> </div> <div class="flex-grow-1 overflow-hidden"> <p class="mb-1 text-truncate">Biens en Attente</p> <h4 class="text-dark mb-0"><?php echo number_format($stats['biensEnAttente']); ?></h4> </div> </div> </div> </div> </div>
                </div>

                <!-- Ligne 2: Graphique et Actions/Notifications -->
                <div class="row">
                    <!-- Graphique -->
                    <div class="col-xl-8"> <div class="card h-100"> <div class="card-header"><h4 class="card-title">Évolution des Revenus (Exemple)</h4></div> <div class="card-body d-flex align-items-center justify-content-center text-muted"> <i>Graphique à implémenter</i> </div> </div> </div>

                    <!-- Colonne Actions & Notifications -->
                     <div class="col-xl-4 d-flex flex-column">
                         <!-- Actions Rapides -->
                        <div class="card quick-actions-card mb-4"> <div class="card-header"><h4 class="card-title mb-0 fs-15">Accès Rapide</h4></div> <div class="card-body p-2"> <div class="list-group list-group-flush"> <a href="proprietaire-mes-biens.php#ajouter" class="list-group-item list-group-item-action"><i class="ri-add-circle-line text-primary align-middle me-2"></i>Ajouter un Bien</a> <a href="proprietaire-finances.php" class="list-group-item list-group-item-action"><i class="ri-bank-card-line text-success align-middle me-2"></i>Mes Finances</a> <a href="proprietaire-contrats.php" class="list-group-item list-group-item-action"><i class="ri-file-list-2-line text-info align-middle me-2"></i>Mes Contrats</a></div> </div> </div>

                         <!-- Notifications Récentes AVEC LOGIQUE LIEN CORRIGÉE -->
                        <div class="card recent-list-card flex-grow-1">
                            <div class="card-header d-flex justify-content-between align-items-center"> <h5 class="card-title mb-0 fs-15">Notifications Récentes</h5> <a href="proprietaire-notifications.php" class="btn-link fw-medium fs-13">Tout voir</a> </div>
                            <div class="card-body p-0">
                                 <div class="list-group list-group-flush" id="recentNotificationsList" style="max-height: 250px; overflow-y: auto;">
                                     <?php if (empty($recentNotifications)): ?>
                                         <div id="noRecentNotifications" class="list-group-item text-center text-muted py-3"> Aucune notification récente. </div>
                                     <?php else:
                                         foreach ($recentNotifications as $notif):
                                             $isUnread = ($notif['statutNotif'] === 'Envoyé'); // Non lu si 'Envoyé'
                                             $notifDate = !empty($notif['dateNotif']) ? date('d/m H:i', strtotime($notif['dateNotif'])) : '';
                                             // --- Début Logique Lien ---
                                             $lienNotification = "proprietaire-notifications.php#notif-" . $notif['idNotif'];
                                             $idContratTrouve = null; $iconClass = 'ri-information-line'; $iconColor = 'text-secondary'; $bgColor = 'bg-light';
                                             // *** CORRECTION REGEX ICI ***
                                             if (preg_match('/\(#(\d+)\)/', $notif['contenu'], $matches)) {
                                                 $idContratTrouve = $matches[1];
                                                 $lienNotification = "signer-contrats.php?id=" . $idContratTrouve; // Lien Signature
                                                 $iconClass = 'ri-file-text-line'; $iconColor = 'text-info'; $bgColor = 'bg-info-subtle';
                                             } elseif (stripos($notif['contenu'], 'paiement') !== false || stripos($notif['contenu'], 'loyer') !== false) { $iconClass = 'ri-money-dollar-circle-line'; $iconColor = 'text-success'; $bgColor='bg-success-subtle';
                                             } elseif (stripos($notif['contenu'], 'bien validé') !== false || stripos($notif['contenu'], 'bien approuvé') !== false) { $iconClass = 'ri-checkbox-circle-line'; $iconColor = 'text-success'; $bgColor='bg-success-subtle';
                                             } elseif (stripos($notif['contenu'], 'bien rejeté') !== false || stripos($notif['contenu'], 'bien suspendu') !== false) { $iconClass = 'ri-close-circle-line'; $iconColor = 'text-danger'; $bgColor='bg-danger-subtle'; }
                                             // --- Fin Logique Lien ---
                                         ?>
                                         <a href="<?php echo htmlspecialchars($lienNotification); ?>" class="list-group-item list-group-item-action <?php echo $isUnread ? 'notification-unread' : ''; ?>" onclick="markNotificationAsRead(<?php echo $notif['idNotif']; ?>, this)">
                                             <div class="d-flex align-items-center">
                                                 <div class="flex-shrink-0 me-2"> <div class="avatar-xs rounded-circle <?php echo $bgColor; ?> d-flex align-items-center justify-content-center list-icon"> <i class="<?php echo $iconClass; ?> <?php echo $iconColor; ?>"></i> </div> </div>
                                                 <div class="flex-grow-1 overflow-hidden"> <p class="notification-text text-truncate mb-0 fs-13"><?php echo htmlspecialchars($notif['contenu']); ?></p> <small class="notification-time-sm text-muted"><?php echo $notifDate; ?></small> </div>
                                             </div>
                                         </a>
                                         <?php endforeach; endif; ?>
                                      <!-- Fallback -->
                                      <div id="noRecentNotificationsFallback" class="list-group-item text-center text-muted py-3 <?php echo !empty($recentNotifications) ? 'd-none' : ''; ?>"> Aucune notification récente. </div>
                                 </div>
                             </div>
                        </div>
                    </div>
                </div>

                 <!-- Ligne 3: Paiements Récents -->
                 <div class="row"> <div class="col-12"> <div class="card recent-list-card">
                     <div class="card-header d-flex justify-content-between align-items-center"> <h4 class="card-title mb-0 fs-15">Derniers Paiements Reçus</h4> <a href="proprietaire-finances.php" class="btn-link fw-medium fs-13">Historique complet</a> </div>
                     <div class="card-body p-0"> <div class="table-responsive">
                         <table class="table table-sm table-centered table-hover mb-0"> <thead class="table-light"> <tr> <th>Date</th> <th>Locataire</th> <th>Bien Concerné</th> <th class="text-end">Montant (FCFA)</th> </tr> </thead>
                             <tbody id="recentPaymentsList">
                                 <?php if (empty($recentPayments)): ?>
                                     <tr id="noRecentPayments"> <td colspan="4" class="text-center text-muted py-3">Aucun paiement récent.</td> </tr>
                                 <?php else: foreach ($recentPayments as $p): $paymentDate = !empty($p['datePaiement']) ? date('d/m/Y', strtotime($p['datePaiement'])) : '-'; ?>
                                     <tr> <td><small><?php echo $paymentDate; ?></small></td> <td><?php echo htmlspecialchars($p['locataireNom'] ?? '-'); ?></td> <td><small><?php echo htmlspecialchars($p['bienAdresse'] ?? '-'); ?></small></td> <td class="text-end fw-medium"><?php echo number_format($p['montant'] ?? 0); ?></td> </tr>
                                 <?php endforeach; endif; ?>
                             </tbody>
                         </table>
                     </div> </div>
                 </div> </div> </div>

            </div> <!-- container-fluid -->
        </div> <!-- page-content -->

        <!-- Footer -->
         <footer class="footer"> <div class="container-fluid"> <div class="row"> <div class="col-12 text-center"> <script>document.write(new Date().getFullYear())</script> © Alteforme GLN. </div> </div> </div> </footer>

    </div> <!-- END wrapper -->

    <!-- Javascript -->
    <script src="assets/js/vendor.js"></script>
    <script src="assets/js/app.js"></script>
    <!-- ApexCharts si utilisé -->
    <!-- <script src="assets/libs/apexcharts/apexcharts.min.js"></script> -->
    <script>
        // JS pour màj compteur topbar et marquer lu
        document.addEventListener('DOMContentLoaded', function() {
            const unreadCount = <?php echo $unreadNotificationCount; ?>;
            const topbarBadge = document.getElementById('topbarUnreadCount');
            const sidebarBadge = document.getElementById('sidebarUnreadCount');

            window.updateUnreadCount = function(count) { /* Identique */ if (count === undefined || count === null) count = 0; if (topbarBadge) { topbarBadge.textContent = count > 9 ? '9+' : count; topbarBadge.classList.toggle('d-none', count === 0); } if (sidebarBadge) { sidebarBadge.textContent = count; sidebarBadge.style.display = count > 0 ? 'inline-block' : 'none'; } }
            updateUnreadCount(unreadCount); // Appel initial

            // --- Marquer une notif comme lue (AJAX) ---
             window.markNotificationAsRead = async function(notificationId, element) {
                 if (!element || !element.classList.contains('notification-unread')) { return; }
                 console.log(`Marquer notif ${notificationId} comme lue (Propriétaire)`);
                 try {
                     const formData = new FormData();
                     formData.append('action', 'mark_notification_read'); // Action PHP à créer
                     formData.append('idNotif', notificationId);
                     // ** IMPORTANT: Créez ce fichier ou ajoutez l'action à un handler existant **
                     const response = await fetch('backend/notification_handler_proprio.php', { method: 'POST', body: formData });
                     const data = await response.json();
                     if (response.ok && data.success) {
                         console.log("Notif marquée lue serveur.");
                         element.classList.remove('notification-unread');
                         const currentCount = parseInt(topbarBadge.textContent.replace('+', ''), 10);
                         if (!isNaN(currentCount) && currentCount > 0) updateUnreadCount(currentCount - 1);
                     } else { console.error("Erreur serveur marquage notif:", data.message); }
                 } catch (error) { console.error("Erreur réseau marquage notif:", error); }
             }

            // --- Marquer TOUT comme lu (AJAX) ---
            window.markAllOwnerNotificationsAsRead = async function() {
                 console.log("Marquer toutes les notifs comme lues (Propriétaire)");
                 try {
                    const formData = new FormData();
                    formData.append('action', 'mark_all_notifications_read'); // Action PHP à créer
                    // ** IMPORTANT: Créez ce fichier ou ajoutez l'action à un handler existant **
                    const response = await fetch('backend/notification_handler_proprio.php', { method: 'POST', body: formData }); // Utiliser l'idUser via session PHP
                    const data = await response.json();
                    if (response.ok && data.success) {
                         console.log("Toutes notifs marquées lues.");
                         document.querySelectorAll('.notification-unread').forEach(el => el.classList.remove('notification-unread'));
                         updateUnreadCount(0);
                     } else { console.error("Erreur serveur marquage toutes:", data.message); alert("Erreur lors du marquage."); }
                 } catch (error) { console.error("Erreur réseau marquage toutes:", error); alert("Erreur réseau."); }
            }
        });
    </script>
</body>
</html>