<?php
// --- notaire-dashboard.php (Connecté BDD - Design Propriétaire) ---

ob_start(); // Démarrer Output Buffering
if (session_status() === PHP_SESSION_NONE) session_start();

// --- Auth & DB Connexion ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'Notaire') {
    ob_end_clean(); header("Location: auth-signin.php"); exit;
}
// Assurez-vous que db_connection est dans le même dossier (admin)
require_once 'db_connection.php';
if (!isset($pdo)) { error_log("CRITICAL: PDO object not defined."); ob_end_clean(); die("Erreur BDD."); }

// --- Fonctions Utilitaires & Récupération ID Notaire ---
function get_logged_in_notaire_id(PDO $pdo, ?int $userId): ?int {
    if ($userId === null) return null; try { $stmt = $pdo->prepare("SELECT idNotaire FROM notaire WHERE idUser = ?"); $stmt->execute([$userId]); $result = $stmt->fetchColumn(); return $result ? (int)$result : null; } catch (PDOException $e) { error_log("Err get_notaire_id:".$e->getMessage()); return null; }
}
$logged_in_user_id = $_SESSION['user_id'] ?? null;
$current_notaire_id = get_logged_in_notaire_id($pdo, $logged_in_user_id);
if ($current_notaire_id === null) { ob_end_clean(); die("Erreur: Notaire non identifié."); }

// --- Initialisation des Données ---
$stats = ['totalBiensGeres' => 0, 'biensAttente' => 0, 'contratsActifs' => 0, 'paiementsAttente' => 0];
$tasks = []; // Tâches pour le notaire
$recentNotifications = []; // Notifications pour le notaire
$unreadNotificationCount = 0;
$pageAlerts = [];

try {
    // --- 1. Récupérer les Statistiques Notaire ---
    // Biens Gérés (Tous les biens liés aux contrats du notaire)
    $sql_bg = "SELECT COUNT(DISTINCT c.idBien) FROM contrat c WHERE c.idNotaire = :idNotaire";
    $stmt_bg = $pdo->prepare($sql_bg); $stmt_bg->execute([':idNotaire' => $current_notaire_id]);
    $stats['totalBiensGeres'] = $stmt_bg->fetchColumn();

    // Biens à Valider (Tous les biens avec statut 'En attente' - L'admin/notaire voit tout ?)
    // Ou filtrer sur les biens liés aux contrats du notaire ? (Choix 1 ici)
    $sql_ba = "SELECT COUNT(*) FROM bienimmobiliers b JOIN contrat c ON b.idBien = c.idBien WHERE c.idNotaire = :idNotaire AND b.supervisionStatut = 'En attente'";
    $stmt_ba = $pdo->prepare($sql_ba); $stmt_ba->execute([':idNotaire' => $current_notaire_id]);
    $stats['biensAttente'] = $stmt_ba->fetchColumn();

    // Contrats Actifs gérés par le notaire
    $sql_ca = "SELECT COUNT(*) FROM contrat WHERE idNotaire = :idNotaire AND statutContrat = 'Actif'";
    $stmt_ca = $pdo->prepare($sql_ca); $stmt_ca->execute([':idNotaire' => $current_notaire_id]);
    $stats['contratsActifs'] = $stmt_ca->fetchColumn();

    // Paiements en Attente gérés par le notaire
    $sql_pa = "SELECT COUNT(*) FROM paiementloyer py JOIN contrat c ON py.idContrat = c.idContrat WHERE c.idNotaire = :idNotaire AND py.statutPaiement = 'en attente'";
    $stmt_pa = $pdo->prepare($sql_pa); $stmt_pa->execute([':idNotaire' => $current_notaire_id]);
    $stats['paiementsAttente'] = $stmt_pa->fetchColumn();

    // --- 2. Identifier les Tâches Prioritaires pour le Notaire ---
    if ($stats['biensAttente'] > 0) {
        $tasks[] = ['text' => "Bien(s) à valider", 'count' => $stats['biensAttente'], 'level' => 'warning', 'icon' => 'ri-checkbox-indeterminate-line', 'link' => 'admin-supervision-biens.php']; // Lien page supervision admin/notaire
    }
    // Contrats nécessitant la signature du notaire
    $sql_csn = "SELECT COUNT(*) FROM contrat WHERE idNotaire = :idNotaire AND statutContrat = 'Signé par les parties' AND signatureNotaireDate IS NULL";
    $stmt_csn = $pdo->prepare($sql_csn); $stmt_csn->execute([':idNotaire' => $current_notaire_id]); $countContratsASigner = $stmt_csn->fetchColumn();
    if ($countContratsASigner > 0) {
        $tasks[] = ['text' => "Contrat(s) à signer/activer", 'count' => $countContratsASigner, 'level' => 'danger', 'icon' => 'ri-pencil-fill', 'link' => 'notaire-gestion-contrats.php?filterStatut=Signé%20par%20les%20parties'];
    }
    // Contrats en attente de signatures des parties (juste pour info)
    $sql_csp = "SELECT COUNT(*) FROM contrat WHERE idNotaire = :idNotaire AND statutContrat = 'En attente signatures' AND (signatureLocataireDate IS NULL OR signatureProprioDate IS NULL)";
    $stmt_csp = $pdo->prepare($sql_csp); $stmt_csp->execute([':idNotaire' => $current_notaire_id]); $countContratsAttenteParties = $stmt_csp->fetchColumn();
    if ($countContratsAttenteParties > 0) {
         $tasks[] = ['text' => "Contrat(s) en attente (parties)", 'count' => $countContratsAttenteParties, 'level' => 'info', 'icon' => 'ri-time-line', 'link' => 'notaire-gestion-contrats.php?filterStatut=En%20attente%20signatures'];
    }
     if ($stats['paiementsAttente'] > 0) {
        $tasks[] = ['text' => "Paiement(s) à vérifier", 'count' => $stats['paiementsAttente'], 'level' => 'primary', 'icon' => 'ri-bank-card-2-line', 'link' => 'notaire-suivi-financier.php?filterStatut=en%20attente'];
    }
     // Ajouter d'autres tâches si besoin

    // --- 3. Récupérer Notifications Récentes pour cet utilisateur Notaire ---
     $sql_activity = "SELECT idNotif, dateNotif, contenu, typeNotif, statutNotif
                      FROM notification
                      WHERE idUser = :idUserNotaire -- Utiliser l'ID User du notaire
                      ORDER BY idNotif DESC
                      LIMIT 5";
    $stmt_activity = $pdo->prepare($sql_activity);
    $stmt_activity->execute([':idUserNotaire' => $logged_in_user_id]);
    $recentNotifications = $stmt_activity->fetchAll(PDO::FETCH_ASSOC);

    // --- 4. Compter Notifications Non Lues pour cet utilisateur Notaire ---
    $sql_unread = "SELECT COUNT(*) FROM notification WHERE idUser = :idUserNotaire AND statutNotif = 'Envoyé'";
    $stmt_unread = $pdo->prepare($sql_unread);
    $stmt_unread->execute([':idUserNotaire' => $logged_in_user_id]);
    $unreadNotificationCount = $stmt_unread->fetchColumn() ?: 0;

} catch (PDOException $e) {
    $pageAlerts[] = ['type' => 'danger', 'message' => 'Erreur chargement données tableau de bord.'];
    error_log("Erreur PDO Dashboard Notaire: " . $e->getMessage());
}

$pdo = null; // Fermer connexion
ob_end_flush(); // Envoyer buffer
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <!-- HEAD HTML (Ton code exact ici) -->
     <meta charset="utf-8" />
     <title>Tableau de Bord Notaire | Gestion Locative Notariale</title>
     <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <link rel="shortcut icon" href="assets/images/favicon.ico">
     <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
     <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
     <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
     <script src="assets/js/config.min.js"></script>
     <style>
         /* Styles Dashboard Notaire V3 (Identiques à ton code fourni) */
         .stat-card .avatar-sm { width: 40px; height: 40px; } .stat-card .avatar-title { font-size: 1.5rem; } .stat-card h5 { font-size: 1.2rem; font-weight: 600; } .stat-card p { font-size: 0.8rem; color: #6c757d; margin-bottom: 0; } .task-priority-card .list-group-item { border-left: 4px solid transparent; transition: background-color 0.2s; padding: 0.8rem 1.2rem; border-bottom: 1px solid #efefef !important; } .task-priority-card .list-group-item:last-child { border-bottom: none !important; } .task-priority-card .list-group-item:hover { background-color: #f8f9fa; } .task-priority-card .list-group-item.border-warning { border-left-color: #ffc107; } .task-priority-card .list-group-item.border-danger { border-left-color: #dc3545; } .task-priority-card .list-group-item.border-info { border-left-color: #0dcaf0; } .task-priority-card .list-group-item.border-primary { border-left-color: #604ae3; } .task-priority-card .task-icon { font-size: 1.1rem; margin-right: 0.75rem; } .task-priority-card .task-text { font-weight: 500; } .task-priority-card .badge { font-size: 0.85rem; } .recent-activity-card .list-group-item { padding: 0.75rem 1rem; } .recent-activity-card .item-icon { width: 32px; height: 32px; font-size: 1rem; } .recent-activity-card .item-text { font-size: 0.875rem; line-height: 1.4; } .recent-activity-card .item-time { font-size: 0.75rem; color: #6c757d; } .row > * { margin-bottom: 1.5rem; } .row:last-child > * { margin-bottom: 0; }
         /* Styles pour notification non lue */
         .notification-unread { background-color: #f1f3f4 !important; font-weight: 500; }
     </style>
</head>
<body>
    <div class="wrapper">
        <!-- ========== Topbar, Menu (Ton HTML exact ici, vérifie les liens et avatar) ========== -->
         <header class=""> <div class="topbar"> <div class="container-fluid"> <div class="navbar-header"> <div class="d-flex align-items-center gap-2"> <div class="topbar-item"><button type="button" class="button-toggle-menu topbar-button"><i class="ri-menu-2-line fs-24"></i></button></div> <form class="app-search d-none d-md-block me-auto"><div class="position-relative"><input type="search" class="form-control border-0" placeholder="Rechercher..." autocomplete="off" value=""><i class="ri-search-line search-widget-icon"></i></div></form> </div> <div class="d-flex align-items-center gap-1"> <div class="topbar-item"><button type="button" class="topbar-button" id="light-dark-mode"><i class="ri-moon-line fs-24 light-mode"></i><i class="ri-sun-line fs-24 dark-mode"></i></button></div> <div class="dropdown topbar-item d-none d-lg-flex"><button type="button" class="topbar-button" data-toggle="fullscreen"><i class="ri-fullscreen-line fs-24 fullscreen"></i><i class="ri-fullscreen-exit-line fs-24 quit-fullscreen"></i></button></div>
             <!-- Notifications Dropdown (Utilise $unreadNotificationCount et $recentNotifications) -->
             <div class="dropdown topbar-item">
                  <button type="button" class="topbar-button position-relative" id="page-header-notifications-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                      <i class="ri-notification-3-line fs-24"></i>
                      <span id="topbarUnreadCount" class="position-absolute topbar-badge fs-10 translate-middle badge bg-danger rounded-pill <?php echo $unreadNotificationCount == 0 ? 'd-none' : ''; ?>">
                         <?php echo $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount; ?><span class="visually-hidden">unread</span>
                      </span>
                  </button>
                  <div class="dropdown-menu py-0 dropdown-lg dropdown-menu-end" aria-labelledby="page-header-notifications-dropdown">
                       <div class="p-3 border-bottom border-dashed"><div class="row align-items-center"><div class="col"><h6 class="m-0 fs-16 fw-semibold"> Notifications</h6></div><div class="col-auto"><a href="javascript: void(0);" class="text-dark text-decoration-underline" onclick="markAllAsRead()"> <small>Marquer tout lu</small></a></div></div></div>
                       <div data-simplebar style="max-height: 280px;" id="topbarNotificationList">
                             <?php if (empty($recentNotifications)): ?>
                                <h6 class="text-muted fs-13 fw-normal p-3 mb-0 text-center">Aucune notification</h6>
                           <?php else:
                                foreach (array_slice($recentNotifications, 0, 4) as $notif): // Limiter à 4 pour le dropdown
                                    $isUnread = ($notif['statutNotif'] === 'Envoyé');
                                    $notifDate = !empty($notif['dateNotif']) ? date('d/m H:i', strtotime($notif['dateNotif'])) : '';
                                    $lienNotification = "notaire-notifications.php#notif-" . $notif['idNotif']; // Lien général vers page notifs
                                    // Adapter l'icône si possible (basé sur $notif['typeNotif'])
                                    $iconClass = 'ri-information-line'; $iconColor = 'text-secondary'; $bgColor = 'bg-light';
                                    switch ($notif['typeNotif'] ?? 'info') {
                                         case 'paiement': $iconClass = 'ri-bank-card-line'; $iconColor = 'text-danger'; $bgColor='bg-danger-subtle'; break;
                                         case 'contrat': $iconClass = 'ri-file-list-3-line'; $iconColor = 'text-info'; $bgColor='bg-info-subtle'; break;
                                         /* ... autres types ... */
                                     }
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
                       <div class="text-center py-2 border-top border-dashed"><a href="notaire-notifications.php" class="btn btn-sm btn-light">Voir toutes</a></div>
                  </div>
             </div>
             <!-- Reste Topbar -->
             <div class="topbar-item d-none d-md-flex"><button type="button" class="topbar-button" id="theme-settings-btn" data-bs-toggle="offcanvas" data-bs-target="#theme-settings-offcanvas"><i class="ri-settings-4-line fs-24"></i></button></div>
             <div class="dropdown topbar-item"> <a type="button" class="topbar-button" id="page-header-user-dropdown" data-bs-toggle="dropdown"><span class="d-flex align-items-center"><img class="rounded-circle" width="32" src="assets/images/users/avatar-notaire.png" alt="notaire"></span></a> <div class="dropdown-menu dropdown-menu-end"> <h6 class="dropdown-header">Bienvenue Maître <?php echo htmlspecialchars($_SESSION['user_prenom'] ?? ''); ?>!</h6> <a class="dropdown-item" href="notaire-profil.php"><i class="ri-user-line me-1"></i> Profil/Cabinet</a> <div class="dropdown-divider"></div> <a class="dropdown-item text-danger" href="auth-signout.php"><i class="ri-logout-box-line me-1"></i> Déconnexion</a> </div> </div>
         </div> </div> </div> </div> </header>
         <div> <div class="offcanvas offcanvas-end border-0 rounded-start-4 overflow-hidden" tabindex="-1" id="theme-settings-offcanvas"> <div class="d-flex align-items-center bg-primary p-3 offcanvas-header"><h5 class="text-white m-0">Theme Settings</h5><button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="offcanvas"></button></div><div class="offcanvas-body p-0"><div data-simplebar class="h-100"><div class="p-3 settings-bar"></div></div></div><div class="offcanvas-footer border-top p-3 text-center"><button type="button" class="btn btn-danger w-100" id="reset-layout">Reset</button></div> </div> </div>
          <div class="main-nav"> <div class="logo-box"> <a href="notaire-dashboard.php" class="logo-dark"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-dark.png" class="logo-lg"></a> <a href="notaire-dashboard.php" class="logo-light"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-light.png" class="logo-lg"></a> </div> <button type="button" class="button-sm-hover" aria-label="Show Full Sidebar"><i class="ri-menu-2-line fs-24 button-sm-hover-icon"></i></button> <div class="scrollbar" data-simplebar> <ul class="navbar-nav" id="navbar-nav"> <li class="menu-title">Menu Notaire</li> <li class="nav-item"><a class="nav-link active" href="notaire-dashboard.php"><span class="nav-icon"><i class="ri-dashboard-line"></i></span><span class="nav-text">Tableau de Bord</span></a></li> <li class="nav-item"><a class="nav-link" href="notaire-gestion-biens.php"><span class="nav-icon"><i class="ri-community-line"></i></span><span class="nav-text">Gestion Biens</span></a></li> <li class="nav-item"><a class="nav-link" href="notaire-gestion-locataires.php"><span class="nav-icon"><i class="ri-group-line"></i></span><span class="nav-text">Gestion Locataires</span></a></li> <li class="nav-item"><a class="nav-link" href="notaire-gestion-contrats.php"><span class="nav-icon"><i class="ri-file-list-3-line"></i></span><span class="nav-text">Gestion Contrats</span></a></li> <li class="nav-item"><a class="nav-link" href="notaire-suivi-financier.php"><span class="nav-icon"><i class="ri-bank-card-line"></i></span><span class="nav-text">Suivi Financier</span></a></li> <li class="nav-item"><a class="nav-link" href="notaire-support.php"><span class="nav-icon"><i class="ri-customer-service-2-line"></i></span><span class="nav-text">Support & Demandes</span></a></li> <li class="nav-item"><a class="nav-link" href="notaire-notifications.php"><span class="nav-icon"><i class="ri-notification-3-line"></i></span><span class="nav-text">Notifications</span><span class="badge bg-danger badge-pill ms-auto" id="sidebarUnreadCount" <?php echo $unreadNotificationCount == 0 ? 'style="display: none;"' : ''; ?>><?php echo $unreadNotificationCount; ?></span></a></li> </ul> </div> </div>
        <!-- ========== Fin HTML Intégré ========== -->

        <div class="page-content">
            <div class="container-fluid">
                <!-- ========== Titre Page (Ton HTML) ========== -->
                 <div class="row"> <div class="col-12"> <div class="page-title-box"> <h4 class="mb-0 fw-semibold">Tableau de Bord Notaire</h4> <ol class="breadcrumb mb-0"> <li class="breadcrumb-item"><a href="javascript: void(0);">Espace Notaire</a></li> <li class="breadcrumb-item active">Tableau de Bord</li> </ol> </div> </div> </div>

                 <!-- ========== Alertes PHP Page ========== -->
                 <div id="pageAlertPlaceholder"> <?php foreach ($pageAlerts as $alert): ?> <div class="alert alert-<?php echo htmlspecialchars($alert['type']); ?> alert-dismissible fade show"> <?php echo htmlspecialchars($alert['message']); ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button> </div> <?php endforeach; ?> </div>

                 <!-- ========== Ligne 1: Statistiques (Remplies par PHP) ========== -->
                 <div class="row">
                     <div class="col-md-6 col-xl-3"> <div class="card stat-card"> <div class="card-body"> <div class="d-flex align-items-center justify-content-between"> <div> <p class="mb-1">Biens Gérés</p> <h5 class="text-dark mb-0"><?php echo number_format($stats['totalBiensGeres']); ?></h5> </div> <div class="avatar-sm flex-shrink-0 ms-2"><span class="avatar-title bg-primary-subtle text-primary rounded"><i class="ri-community-fill avatar-title"></i></span></div> </div> </div> </div> </div>
                     <div class="col-md-6 col-xl-3"> <div class="card stat-card"> <div class="card-body"> <div class="d-flex align-items-center justify-content-between"> <div> <p class="mb-1">Biens à Valider</p> <h5 class="text-dark mb-0"><?php echo number_format($stats['biensAttente']); ?></h5> </div> <div class="avatar-sm flex-shrink-0 ms-2"><span class="avatar-title bg-warning-subtle text-warning rounded"><i class="ri-checkbox-indeterminate-line avatar-title"></i></span></div> </div> </div> </div> </div>
                     <div class="col-md-6 col-xl-3"> <div class="card stat-card"> <div class="card-body"> <div class="d-flex align-items-center justify-content-between"> <div> <p class="mb-1">Contrats Actifs</p> <h5 class="text-dark mb-0"><?php echo number_format($stats['contratsActifs']); ?></h5> </div> <div class="avatar-sm flex-shrink-0 ms-2"><span class="avatar-title bg-success-subtle text-success rounded"><i class="ri-file-list-3-fill avatar-title"></i></span></div> </div> </div> </div> </div>
                     <div class="col-md-6 col-xl-3"> <div class="card stat-card"> <div class="card-body"> <div class="d-flex align-items-center justify-content-between"> <div> <p class="mb-1">Paiements à Vérifier</p> <h5 class="text-dark mb-0"><?php echo number_format($stats['paiementsAttente']); ?></h5> </div> <div class="avatar-sm flex-shrink-0 ms-2"><span class="avatar-title bg-danger-subtle text-danger rounded"><i class="ri-bank-card-2-line avatar-title"></i></span></div> </div> </div> </div> </div>
                 </div>

                <!-- ========== Ligne 2: Tâches et Activité (Remplies par PHP) ========== -->
                <div class="row">
                    <div class="col-lg-7">
                        <div class="card task-priority-card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center"> <h5 class="card-title mb-0"><i class="ri-task-line me-2"></i>Tâches Prioritaires</h5> </div>
                            <div class="card-body p-0">
                                <div class="list-group list-group-flush" id="taskList">
                                    <?php if (empty($tasks)): ?>
                                        <div id="noTasksMessage" class="list-group-item text-center text-muted py-3"> Aucune tâche prioritaire. </div>
                                    <?php else: foreach ($tasks as $task): ?>
                                        <a href="<?php echo htmlspecialchars($task['link'] ?? '#!'); ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center border-<?php echo $task['level'] ?? 'secondary'; ?>">
                                            <span><i class="<?php echo $task['icon'] ?? 'ri-task-line'; ?> task-icon text-<?php echo $task['level'] ?? 'secondary'; ?>"></i> <span class="task-text"><?php echo htmlspecialchars($task['text']); ?></span></span>
                                            <?php if (isset($task['count']) && $task['count'] > 0): ?><span class="badge bg-<?php echo $task['level'] ?? 'secondary'; ?> rounded-pill"><?php echo $task['count']; ?></span><?php endif; ?>
                                        </a>
                                    <?php endforeach; endif; ?>
                                </div>
                             </div>
                             <div class="card-footer text-center border-top-0 pt-0"> <a href="notaire-gestion-contrats.php" class="btn btn-success mt-2"><i class="ri-add-line me-1"></i> Créer un Contrat</a> </div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                         <div class="card recent-activity-card h-100">
                             <div class="card-header d-flex justify-content-between align-items-center"> <h5 class="card-title mb-0"><i class="ri-history-line me-2"></i>Activité Récente</h5> <a href="notaire-notifications.php" class="btn-link fw-medium small">Tout voir</a> </div>
                              <div class="card-body p-0">
                                  <div class="list-group list-group-flush" id="notaireRecentActivityList" style="max-height: 450px; overflow-y: auto;">
                                      <?php if (empty($recentNotifications)): ?>
                                         <div id="noRecentActivity" class="list-group-item text-center text-muted py-3"> Aucune activité récente. </div>
                                     <?php else:
                                         $activityIcons = ['nouveau_bien' => 'ri-community-line text-warning bg-warning-subtle', 'paiement_recu' => 'ri-bank-card-line text-danger bg-danger-subtle', 'paiement_valide' => 'ri-check-double-line text-success bg-success-subtle', 'demande_maintenance' => 'ri-tools-line text-primary bg-primary-subtle', 'contrat_cree' => 'ri-file-add-line text-success bg-success-subtle', 'signature_recue' => 'ri-pencil-fill text-info bg-info-subtle', 'info' => 'ri-notification-3-line text-muted bg-light', 'autre' => 'ri-notification-3-line text-muted bg-light']; // Ajout type 'autre'
                                         foreach ($recentNotifications as $log):
                                             $iconInfoKey = $log['typeNotif'] ?? 'info';
                                             $iconInfo = $activityIcons[$iconInfoKey] ?? $activityIcons['info'];
                                             list($iconClass, $iconColor, $bgColor) = explode(' ', $iconInfo . ' text-secondary bg-light');
                                             $formattedTime = $log['dateNotif'] ? date('d/m H:i', strtotime($log['dateNotif'])) : '-'; // Utiliser dateNotif
                                             $isUnread = ($log['statutNotif'] === 'Envoyé');
                                             $lienNotification = "notaire-notifications.php#notif-" . $log['idNotif']; // Lien vers la notif spécifique
                                         ?>
                                         <a href="<?php echo htmlspecialchars($lienNotification); ?>" class="list-group-item list-group-item-action <?php echo $isUnread ? 'notification-unread' : ''; ?>" onclick="markNotificationAsRead(<?php echo $log['idNotif']; ?>, this)">
                                              <div class="d-flex align-items-center">
                                                  <div class="flex-shrink-0 me-3"> <div class="avatar-sm rounded-circle <?php echo $bgColor; ?> d-flex align-items-center justify-content-center item-icon"><i class="<?php echo $iconClass; ?> <?php echo $iconColor; ?>"></i></div> </div>
                                                  <div class="flex-grow-1 overflow-hidden"> <p class="item-text text-truncate mb-0 small"><?php echo htmlspecialchars($log['contenu']); ?></p> <small class="item-time text-muted"><?php echo $formattedTime; ?></small> </div>
                                              </div>
                                          </a>
                                         <?php endforeach; endif; ?>
                                  </div>
                              </div>
                         </div>
                     </div>
                </div>

            </div> <!-- container-fluid -->
        </div> <!-- page-content -->

        <!-- Footer (Ton HTML exact ici) -->
         <footer class="footer">...</footer>
    </div> <!-- wrapper -->

    <!-- JS Vendor & App (Ton JS exact ici, vérifie les chemins) -->
    <script src="assets/js/vendor.js"></script>
    <script src="assets/js/app.js"></script>
    <!-- ApexCharts si besoin -->
    <!-- <script src="assets/libs/apexcharts/apexcharts.min.js"></script> -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script> <!-- Si tu utilises Swal -->

    <!-- Custom JS Dashboard Notaire (MAJ Compteurs Notifs) -->
    <script>
         // Met à jour les compteurs de notifications
         function updateUnreadCount(count = 0) {
             const countInt = parseInt(count) || 0;
             const topbarBadge = document.getElementById('topbarUnreadCount');
             const sidebarBadge = document.getElementById('sidebarUnreadCount');
             if (topbarBadge) { topbarBadge.textContent = countInt > 9 ? '9+' : countInt; topbarBadge.classList.toggle('d-none', countInt === 0); }
             if (sidebarBadge) { sidebarBadge.textContent = countInt; sidebarBadge.style.display = countInt > 0 ? 'inline-block' : 'none'; }
         }

         // Appeler la mise à jour avec le compte PHP au chargement
         document.addEventListener('DOMContentLoaded', function() {
            updateUnreadCount(<?php echo json_encode($unreadNotificationCount); ?>);
         });

         // Marquer une notif comme lue (Fonction à implémenter avec AJAX)
         function markNotificationAsRead(notificationId, element) {
             if (!element || !element.classList.contains('notification-unread')) return;
             console.log(`Marquer notif ${notificationId} lue (Notaire)`);
             // TODO: AJAX POST vers un handler PHP (action=mark_read, id=notificationId)
             // En cas de succès AJAX:
             element.classList.remove('notification-unread');
             const currentCount = parseInt(document.getElementById('topbarUnreadCount').textContent.replace('+', '')) || 0;
             if(currentCount > 0) updateUnreadCount(currentCount - 1);
             // alert("Marquage 'lu' à implémenter via AJAX.");
         }

         // Marquer TOUT comme lu (Fonction à implémenter avec AJAX)
         function markAllAsRead() {
             console.log("Marquer toutes les notifs lues (Notaire)");
             // TODO: AJAX POST vers un handler PHP (action=mark_all_read)
             // En cas de succès AJAX:
             document.querySelectorAll('.notification-unread').forEach(el => el.classList.remove('notification-unread'));
             updateUnreadCount(0);
             alert("Marquage 'tout lu' à implémenter via AJAX.");
         }
    </script>
</body>
</html>