<?php
// --- admin-dashboard.php (Version PDO) ---

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'Administrateur') { header("Location: auth-signin.php"); exit; }

// Utilisation de PDO via db_connection.php
require_once 'db_connection.php'; // Assure-toi que $pdo est défini ici

// --- Initialisation des données ---
$stats = ['totalUsers' => 0, 'notairesActifs' => 0, 'totalBiens' => 0, 'biensAttente' => 0];
$alerts = [];
$recentActivity = [];
$unreadAdminNotifications = 0; // Pas utilisé dans le HTML actuel, mais conservé
$pageAlerts = [];

// --- Requêtes pour les Statistiques (Version PDO) ---
try {
    // Total Utilisateurs
    $stmt_total_users = $pdo->query("SELECT COUNT(*) FROM utilisateurs");
    $stats['totalUsers'] = $stmt_total_users->fetchColumn();

    // Notaires Actifs
    $stmt_notaires = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'Notaire' AND statut = 'Actif'");
    $stats['notairesActifs'] = $stmt_notaires->fetchColumn();

    // Total Biens
    $stmt_total_biens = $pdo->query("SELECT COUNT(*) FROM bienimmobiliers");
    $stats['totalBiens'] = $stmt_total_biens->fetchColumn();

    // Biens en Attente
    $stmt_biens_attente = $pdo->query("SELECT COUNT(*) FROM bienimmobiliers WHERE supervisionStatut = 'En attente'");
    $stats['biensAttente'] = $stmt_biens_attente->fetchColumn();

} catch (PDOException $e) {
    $pageAlerts[] = ['type' => 'danger', 'message' => 'Erreur lors de la récupération des statistiques.'];
    error_log("Err PDO stats dashboard: " . $e->getMessage());
}

// --- Génération des Alertes / Actions Rapides (Logique inchangée) ---
if ($stats['biensAttente'] > 0) {
    $alerts[] = [
        'text' => "Bien(s) en attente de validation",
        'count' => $stats['biensAttente'],
        'level' => 'warning',
        'icon' => 'ri-checkbox-indeterminate-line',
        'link' => 'admin-supervision-biens.php' // Lien direct vers la page de supervision
    ];
}
// Ajouter d'autres alertes si nécessaire (ex: comptes suspendus, etc.)

// --- Récupération Activité Récente (Version PDO) ---
try {
    // Note: La table 'notification' et sa structure (contenu, typeNotif, etc.) sont nécessaires
    // Si elle n'existe pas ou est différente, cette requête échouera ou devra être adaptée.
    $sql_activity = "SELECT n.idNotif, n.dateNotif, n.contenu, n.typeNotif, u.prenom, u.nom, u.role
                     FROM notification n
                     JOIN utilisateurs u ON n.idUser = u.idUser
                     ORDER BY n.idNotif DESC
                     LIMIT 5";
    $stmt_activity = $pdo->query($sql_activity);
    // Formatter les données récupérées (si la table existe)
    while ($log = $stmt_activity->fetch(PDO::FETCH_ASSOC)) {
        $activityItem = [
            'date' => $log['dateNotif'], // Utiliser la date de la notif
            'type' => $log['typeNotif'] ?? 'info', // Utiliser le type ou 'info' par défaut
             // Construire une description basée sur le contenu et l'utilisateur/rôle
            'description' => ($log['prenom'] ?? '') . ' ' . ($log['nom'] ?? '') . ' (' . ($log['role'] ?? 'Système') . ') : ' . ($log['contenu'] ?? 'Action effectuée.'),
        ];
         // Adapter 'type' pour l'icône si nécessaire
         if (stripos($activityItem['description'], 'paiement') !== false) $activityItem['type'] = 'paiement';
         elseif (stripos($activityItem['description'], 'contrat') !== false) $activityItem['type'] = 'contrat';
         elseif (stripos($activityItem['description'], 'bien') !== false) $activityItem['type'] = 'bien';
         // etc. pour d'autres types

        $recentActivity[] = $activityItem;
    }
} catch (PDOException $e) {
     // Si la table notification n'existe pas, cette erreur sera capturée
     if (strpos($e->getMessage(), "Base table or view not found") !== false && strpos($e->getMessage(), "notification") !== false) {
         error_log("Info PDO: La table 'notification' n'existe pas ou n'est pas accessible (Dashboard Admin).");
         // Ne pas afficher d'erreur bloquante à l'admin, l'activité sera juste vide.
     } else {
         $pageAlerts[] = ['type' => 'warning', 'message' => 'Erreur chargement activité récente.'];
         error_log("Err PDO récupération activité: " . $e->getMessage());
     }
}

// --- Fermeture de la connexion PDO (généralement non nécessaire) ---
// $pdo = null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <title>Tableau de Bord Admin | Gestion Locative Notariale</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
    <script src="assets/js/config.min.js"></script>
    <style>
        /* Styles spécifiques Dashboard Admin (inchangés) */
        .stat-card .avatar-sm { width: 40px; height: 40px; }
        .stat-card .avatar-title { font-size: 1.5rem; }
        .stat-card h5 { font-size: 1.2rem; font-weight: 600; }
        .stat-card p { font-size: 0.8rem; color: #6c757d; margin-bottom: 0; }
        .alert-card .list-group-item { padding: 0.8rem 1rem; border-color: #e9ecef; border-left-width: 3px; border-radius: 0; margin-bottom: -1px; }
        .alert-card .list-group-item:first-child { border-top-left-radius: 0; border-top-right-radius: 0; }
        .alert-card .list-group-item:last-child { border-bottom-left-radius: 0; border-bottom-right-radius: 0; margin-bottom: 0; }
        .alert-card .list-group-item:hover { background-color: #f8f9fa; }
        .alert-card .badge { font-size: 0.8rem; }
        .activity-log .list-group-item { padding: 0.75rem 1rem; border-bottom: 1px solid #f1f3f7; }
        .activity-log .log-icon { font-size: 1.1rem; margin-right: 0.6rem; width: 20px; text-align: center;}
        .activity-log .log-time { font-size: 0.75rem; color: #6c757d; }
        .row > * { margin-bottom: 1.5rem; }
        .row:last-child > * { margin-bottom: 0; }
    </style>
</head>
<body>
    <div class="wrapper">

        <!-- ========== Topbar, Right Sidebar, App Menu (HTML INCHANGÉ - collez le vôtre) ========== -->
        <header class="">
             <div class="topbar">
             <div class="container-fluid">
                  <div class="navbar-header">
                       <div class="d-flex align-items-center gap-2">
                            <div class="topbar-item"> <button type="button" class="button-toggle-menu topbar-button"><i class="ri-menu-2-line fs-24"></i></button> </div>
                            <form class="app-search d-none d-md-block me-auto"><div class="position-relative"><input type="search" class="form-control border-0" placeholder="Rechercher..." autocomplete="off" value=""><i class="ri-search-line search-widget-icon"></i></div></form>
                       </div>
                       <div class="d-flex align-items-center gap-1">
                            <div class="topbar-item"><button type="button" class="topbar-button" id="light-dark-mode"><i class="ri-moon-line fs-24 light-mode"></i><i class="ri-sun-line fs-24 dark-mode"></i></button></div>
                            <div class="dropdown topbar-item d-none d-lg-flex"><button type="button" class="topbar-button" data-toggle="fullscreen"><i class="ri-fullscreen-line fs-24 fullscreen"></i><i class="ri-fullscreen-exit-line fs-24 quit-fullscreen"></i></button></div>
                            <div class="dropdown topbar-item"> <button type="button" class="topbar-button position-relative" id="page-header-notifications-dropdown" data-bs-toggle="dropdown"><i class="ri-notification-3-line fs-24"></i><span id="topbarAdminUnreadCount" class="position-absolute topbar-badge fs-10 translate-middle badge bg-danger rounded-pill">0<span class="visually-hidden">unread</span></span></button> <div class="dropdown-menu py-0 dropdown-lg dropdown-menu-end"> <div class="p-3 border-bottom border-dashed"> <div class="row align-items-center"><div class="col"><h6 class="m-0 fs-16 fw-semibold">Notifications Admin</h6></div><div class="col-auto"><a href="#!" class="text-dark text-decoration-underline"><small>Tout marquer lu</small></a></div></div></div> <div data-simplebar style="max-height: 280px;" id="topbarAdminNotificationList"></div> <div class="text-center py-3"><a href="admin-notifications.html" class="btn btn-primary btn-sm">Tout voir</a></div> </div> </div>
                            <div class="topbar-item d-none d-md-flex"><button type="button" class="topbar-button" id="theme-settings-btn" data-bs-toggle="offcanvas" data-bs-target="#theme-settings-offcanvas"><i class="ri-settings-4-line fs-24"></i></button></div>
                            <div class="dropdown topbar-item"> <a type="button" class="topbar-button" id="page-header-user-dropdown" data-bs-toggle="dropdown"><span class="d-flex align-items-center"><img class="rounded-circle" width="32" src="assets/images/users/avatar-admin.png" alt="admin"></span></a> <div class="dropdown-menu dropdown-menu-end"> <h6 class="dropdown-header">Admin <?php echo htmlspecialchars($_SESSION['user_prenom'] ?? ''); ?></h6> <a class="dropdown-item" href="admin-profil.html"><i class="ri-user-line me-1"></i> Profil</a> <!-- Lien Paramètres retiré --> <div class="dropdown-divider"></div> <a class="dropdown-item text-danger" href="auth-signin.php?logout=1"><i class="ri-logout-box-line me-1"></i> Déconnexion</a> </div> </div>
                       </div>
                  </div>
             </div></div>
        </header>
         <div> <div class="offcanvas offcanvas-end border-0 rounded-start-4 overflow-hidden" tabindex="-1" id="theme-settings-offcanvas"> <div class="d-flex align-items-center bg-primary p-3 offcanvas-header"><h5 class="text-white m-0">Theme Settings</h5><button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="offcanvas"></button></div><div class="offcanvas-body p-0"><div data-simplebar class="h-100"><div class="p-3 settings-bar"> </div></div></div><div class="offcanvas-footer border-top p-3 text-center"><button type="button" class="btn btn-danger w-100" id="reset-layout">Reset</button></div> </div> </div>
          <div class="main-nav">
             <div class="logo-box"> <a href="admin-dashboard.php" class="logo-dark"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-dark.png" class="logo-lg"></a> <a href="admin-dashboard.php" class="logo-light"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-light.png" class="logo-lg"></a> </div>
             <button type="button" class="button-sm-hover" aria-label="Show Full Sidebar"><i class="ri-menu-2-line fs-24 button-sm-hover-icon"></i></button>
             <div class="scrollbar" data-simplebar>
                  <ul class="navbar-nav" id="navbar-nav">
                       <li class="menu-title">Menu Admin</li>
                       <li class="nav-item"><a class="nav-link active" href="admin-dashboard.php"><span class="nav-icon"><i class="ri-dashboard-line"></i></span><span class="nav-text">Dashboard</span></a></li>
                        <!-- Lien corrigé gestion utilisateurs -->
                       <li class="nav-item"><a class="nav-link" href="admin-gestion-utilisateurs.php"><span class="nav-icon"><i class="ri-account-circle-line"></i></span><span class="nav-text">Gestion Utilisateurs</span></a></li>
                       <li class="nav-item"><a class="nav-link" href="admin-supervision-biens.php"><span class="nav-icon"><i class="ri-building-line"></i></span><span class="nav-text">Supervision Biens</span></a></li>
                       <!-- Liens retirés -->
                  </ul>
             </div>
        </div>

        <div class="page-content">
            <div class="container-fluid">
                <!-- Titre, Alertes PHP (HTML inchangé) -->
                 <div class="row"> <div class="col-12"> <div class="page-title-box"> <h4 class="mb-0 fw-semibold">Tableau de Bord Administrateur</h4> <ol class="breadcrumb mb-0"> <li class="breadcrumb-item"><a href="javascript: void(0);">Admin</a></li> <li class="breadcrumb-item active">Tableau de Bord</li> </ol> </div> </div> </div>
                 <div id="pageAlertPlaceholder"> <?php foreach ($pageAlerts as $alert): ?> <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show"> <?php echo htmlspecialchars($alert['message']); ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button> </div> <?php endforeach; ?> </div>

                <!-- Ligne 1: Statistiques (HTML inchangé) -->
                <div class="row">
                    <div class="col-md-6 col-xl-3"> <div class="card stat-card"> <div class="card-body"> <div class="d-flex align-items-center justify-content-between"> <div> <p class="mb-1">Utilisateurs Totals</p> <h5 class="text-dark mb-0"><?php echo number_format($stats['totalUsers'], 0, ',', ' '); ?></h5> </div> <div class="avatar-sm flex-shrink-0 ms-2"><span class="avatar-title bg-primary-subtle text-primary rounded"><i class="ri-group-line avatar-title"></i></span></div> </div> </div> </div> </div>
                    <div class="col-md-6 col-xl-3"> <div class="card stat-card"> <div class="card-body"> <div class="d-flex align-items-center justify-content-between"> <div> <p class="mb-1">Notaires Actifs</p> <h5 class="text-dark mb-0"><?php echo number_format($stats['notairesActifs'], 0, ',', ' '); ?></h5> </div> <div class="avatar-sm flex-shrink-0 ms-2"><span class="avatar-title bg-info-subtle text-info rounded"><i class="ri-user-star-line avatar-title"></i></span></div> </div> </div> </div> </div>
                    <div class="col-md-6 col-xl-3"> <div class="card stat-card"> <div class="card-body"> <div class="d-flex align-items-center justify-content-between"> <div> <p class="mb-1">Biens Enregistrés</p> <h5 class="text-dark mb-0"><?php echo number_format($stats['totalBiens'], 0, ',', ' '); ?></h5> </div> <div class="avatar-sm flex-shrink-0 ms-2"><span class="avatar-title bg-success-subtle text-success rounded"><i class="ri-home-line avatar-title"></i></span></div> </div> </div> </div> </div>
                    <div class="col-md-6 col-xl-3"> <div class="card stat-card"> <div class="card-body"> <div class="d-flex align-items-center justify-content-between"> <div> <p class="mb-1">Biens à Valider</p> <h5 class="text-dark mb-0"><?php echo number_format($stats['biensAttente'], 0, ',', ' '); ?></h5> </div> <div class="avatar-sm flex-shrink-0 ms-2"><span class="avatar-title bg-warning-subtle text-warning rounded"><i class="ri-checkbox-indeterminate-line avatar-title"></i></span></div> </div> </div> </div> </div>
                </div>

                <!-- Ligne 2: Alertes et Activité (HTML presque inchangé) -->
                <div class="row">
                    <div class="col-lg-6 d-flex flex-column">
                        <div class="card alert-card flex-grow-1">
                            <div class="card-header"><h5 class="card-title mb-0"><i class="ri-alert-line me-2"></i>Alertes & Actions Rapides</h5></div>
                            <div class="card-body p-0">
                                <div class="list-group list-group-flush" id="adminAlertList">
                                    <?php if (empty($alerts)): ?>
                                        <div class="list-group-item text-center text-muted py-3"> Aucune alerte pour le moment. </div>
                                    <?php else: foreach ($alerts as $alert): ?>
                                        <a href="<?php echo htmlspecialchars($alert['link'] ?? '#!'); ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center border-<?php echo $alert['level'] ?? 'warning'; ?>">
                                            <span><i class="<?php echo $alert['icon'] ?? 'ri-alert-line'; ?> me-2 text-<?php echo $alert['level'] ?? 'warning'; ?>"></i> <?php echo htmlspecialchars($alert['text']); ?></span>
                                            <?php if (isset($alert['count']) && $alert['count'] > 0): ?>
                                                <span class="badge bg-<?php echo $alert['level'] ?? 'warning'; ?> rounded-pill"><?php echo $alert['count']; ?></span>
                                            <?php endif; ?>
                                        </a>
                                    <?php endforeach; endif; ?>
                                </div>
                            </div>
                            <!-- Bouton Ajouter Notaire retiré, se fait dans la page gestion utilisateurs -->
                            <!-- <div class="card-footer text-center border-top-0 pt-0"> <a href="admin-gestion-utilisateurs.php#addNotaire" class="btn btn-success mt-2"><i class="ri-user-add-line me-1"></i> Ajouter Notaire</a> </div> -->
                        </div>
                    </div>
                    <div class="col-lg-6 d-flex flex-column">
                        <div class="card activity-log flex-grow-1">
                             <div class="card-header"><h5 class="card-title mb-0"><i class="ri-history-line me-2"></i>Journal d'Activité Récent</h5></div>
                             <div class="card-body p-0">
                                 <div class="list-group list-group-flush" id="adminActivityLog" style="max-height: 400px; overflow-y: auto;">
                                     <?php if (empty($recentActivity)): ?>
                                         <div class="list-group-item text-center text-muted py-3"> Aucune activité récente enregistrée. </div>
                                     <?php else:
                                         // Assigner une icône basée sur le 'type' (si défini lors de la création de la notif)
                                         $activityIcons = [
                                             'paiement' => 'ri-bank-card-line text-danger',
                                             'contrat' => 'ri-file-list-3-line text-info',
                                             'bien' => 'ri-check-double-line text-primary', // ex: validation bien
                                             'user_add' => 'ri-user-add-line text-success',
                                             'user_status' => 'ri-user-settings-line text-warning', // ex: suspension
                                             'error' => 'ri-error-warning-line text-danger',
                                             'info' => 'ri-information-line text-secondary' // Default
                                         ];
                                         foreach ($recentActivity as $log):
                                             $iconClass = $activityIcons[$log['type']] ?? $activityIcons['info'];
                                             // Formatter la date si elle existe
                                             $logTime = !empty($log['date']) ? date('d/m/Y H:i', strtotime($log['date'])) : 'Date inconnue';
                                         ?>
                                         <div class="list-group-item">
                                             <div class="d-flex align-items-center">
                                                 <i class="<?php echo $iconClass; ?> log-icon"></i>
                                                 <div class="flex-grow-1">
                                                     <p class="mb-0 item-text small"><?php echo htmlspecialchars($log['description']); ?></p>
                                                     <small class="log-time"><?php echo $logTime; ?></small>
                                                 </div>
                                             </div>
                                         </div>
                                         <?php endforeach; endif; ?>
                                 </div>
                             </div>
                             <!-- Optionnel: Ajouter un lien "Voir tout" si la table notification est bien utilisée -->
                             <!-- <div class="card-footer text-center"><a href="admin-activity-log.php" class="btn btn-link btn-sm">Voir tout le journal</a></div> -->
                        </div>
                    </div>
                </div>
            </div> <!-- container-fluid -->
        </div> <!-- page-content -->

        <!-- Footer (HTML inchangé) -->
        <footer class="footer"> <div class="container-fluid"> <div class="row"> <div class="col-12 text-center"> <script>document.write(new Date().getFullYear())</script> © Admin Panel. </div> </div> </div> </footer>
    </div> <!-- wrapper -->

    <!-- Scripts JS (inchangés) -->
    <script src="assets/js/vendor.js"></script>
    <script src="assets/js/app.js"></script>
    <!-- Aucun JS spécifique nécessaire pour ce dashboard simple chargé par PHP -->
</body>
</html>