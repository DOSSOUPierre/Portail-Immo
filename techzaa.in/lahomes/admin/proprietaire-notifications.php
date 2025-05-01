<?php
// proprietaire-notifications.php

// Démarrer la session PHP au tout début
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Configuration & Includes ---
require_once 'db_connection.php'; // $pdo est maintenant disponible

// --- Fonctions Utilitaires Backend ---

/**
 * Récupère l'ID Propriétaire à partir de l'ID Utilisateur.
 */
function get_logged_in_proprietaire_id(PDO $pdo, ?int $userId): ?int {
    if ($userId === null) return null;
    try {
        $stmt = $pdo->prepare("SELECT idProprietaire FROM proprietaire WHERE idUser = :userId");
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetchColumn();
        return $result ? (int)$result : null;
    } catch (PDOException $e) { error_log(__FUNCTION__ . " Erreur: " . $e->getMessage()); return null; }
}

/**
 * Récupère l'ID Utilisateur associé à un ID Propriétaire.
 */
 function get_user_id_for_proprietaire(PDO $pdo, ?int $proprietaireId): ?int {
    if ($proprietaireId === null) return null;
     try {
         $stmt = $pdo->prepare("SELECT idUser FROM proprietaire WHERE idProprietaire = :propId");
         $stmt->bindParam(':propId', $proprietaireId, PDO::PARAM_INT);
         $stmt->execute();
         $result = $stmt->fetchColumn();
         return $result ? (int)$result : null;
     } catch (PDOException $e) { error_log(__FUNCTION__ . " Erreur: " . $e->getMessage()); return null; }
 }

// --- Récupération ID Propriétaire & Utilisateur Connecté ---
$logged_in_user_id = $_SESSION['user_id'] ?? null;
$current_proprietaire_id = get_logged_in_proprietaire_id($pdo, $logged_in_user_id);
$current_owner_user_id = get_user_id_for_proprietaire($pdo, $current_proprietaire_id); // ID Utilisateur du Propriétaire

// --- Gestion des Actions AJAX ---
if (isset($_REQUEST['action'])) {

    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Action non reconnue ou erreur interne.'];
    $action = $_REQUEST['action'];

    // --- Vérification Authentification (Propriétaire) ---
    if ($current_owner_user_id === null) {
        $response['message'] = "Accès non autorisé ou session expirée."; http_response_code(403); echo json_encode($response); exit;
    }

    try {
        switch ($action) {
            // --- Récupérer les notifications ---
            case 'get_notifications':
                $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1; $perPage = 10; $offset = ($page - 1) * $perPage;
                $filterStatus = $_GET['statutNotif'] ?? ''; $filterType = $_GET['typeNotif'] ?? '';
                $sqlBase = " FROM notification WHERE idUser = :userId "; $params = [':userId' => $current_owner_user_id]; $whereClauses = [];
                if (!empty($filterStatus) && in_array($filterStatus, ['Lu', 'Non Lu'])) { $whereClauses[] = "statutNotif = :status"; $params[':status'] = $filterStatus; }
                if (!empty($filterType)) { /* Valider $filterType si nécessaire */ $whereClauses[] = "typeNotif = :type"; $params[':type'] = $filterType; }
                if (!empty($whereClauses)) { $sqlBase .= " AND " . implode(' AND ', $whereClauses); }
                $sqlCount = "SELECT COUNT(*) " . $sqlBase; $stmtCount = $pdo->prepare($sqlCount); $stmtCount->execute($params); $totalItems = (int) $stmtCount->fetchColumn(); $totalPages = $totalItems > 0 ? ceil($totalItems / $perPage) : 1; $page = min($page, $totalPages); $offset = max(0, ($page - 1) * $perPage);
                $sqlUnreadCount = "SELECT COUNT(*) FROM notification WHERE idUser = :userId AND statutNotif = 'Non Lu'"; $stmtUnread = $pdo->prepare($sqlUnreadCount); $stmtUnread->bindParam(':userId', $current_owner_user_id, PDO::PARAM_INT); $stmtUnread->execute(); $unreadCount = (int) $stmtUnread->fetchColumn();
                $sqlData = " SELECT idNotif, dateNotif, contenu, statutNotif, typeNotif " . $sqlBase . " ORDER BY dateNotif DESC LIMIT :limit OFFSET :offset "; $stmtData = $pdo->prepare($sqlData); $stmtData->bindValue(':limit', $perPage, PDO::PARAM_INT); $stmtData->bindValue(':offset', $offset, PDO::PARAM_INT); foreach ($params as $key => $value) { $stmtData->bindValue($key, $value); } $stmtData->execute(); $notifications = $stmtData->fetchAll(PDO::FETCH_ASSOC);
                $response = [ 'success' => true, 'data' => $notifications, 'pagination' => [ 'currentPage' => $page, 'totalPages' => $totalPages, 'totalItems' => $totalItems, 'unreadCount' => $unreadCount ] ];
                break;

            // --- Marquer une notification comme lue ---
            case 'mark_notification_read':
                $notificationId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                if (!$notificationId) { $response['message'] = "ID invalide."; http_response_code(400); break; }
                $sqlMark = "UPDATE notification SET statutNotif = 'Lu' WHERE idNotif = :notifId AND idUser = :userId AND statutNotif = 'Non Lu'";
                $stmtMark = $pdo->prepare($sqlMark); $stmtMark->bindParam(':notifId', $notificationId, PDO::PARAM_INT); $stmtMark->bindParam(':userId', $current_owner_user_id, PDO::PARAM_INT); $stmtMark->execute();
                if ($stmtMark->rowCount() > 0) { $response = ['success' => true, 'message' => 'Notification marquée comme lue.']; }
                else { $response = ['success' => false, 'message' => 'Notification non trouvée ou déjà lue.']; }
                break;

            // --- Marquer toutes les notifications comme lues ---
            case 'mark_all_notifications_read':
                $sqlMarkAll = "UPDATE notification SET statutNotif = 'Lu' WHERE idUser = :userId AND statutNotif = 'Non Lu'";
                $stmtMarkAll = $pdo->prepare($sqlMarkAll); $stmtMarkAll->bindParam(':userId', $current_owner_user_id, PDO::PARAM_INT); $stmtMarkAll->execute();
                $markedCount = $stmtMarkAll->rowCount(); $response = ['success' => true, 'message' => $markedCount . ' notification(s) marquée(s) comme lue(s).'];
                break;

            default:
                $response['message'] = "Action '" . htmlspecialchars($action) . "' non reconnue."; http_response_code(400); break;
        }
    } catch (Exception $e) {
        error_log("Erreur AJAX Action '{$action}': " . $e->getMessage()); $response['message'] = 'Erreur serveur.'; http_response_code(500);
    }
    echo json_encode($response); exit;
}
// --- Fin Gestion AJAX ---

// --- Si PAS AJAX, préparation affichage HTML ---
$proprietaire_prenom = ''; $proprietaire_nom = '';
if ($current_owner_user_id === null) {
    // Sécurité: si on arrive ici sans être un propriétaire connecté, on redirige
    header('Location: auth-signin.php?error=unauthorized'); exit;
} else {
    try {
        $stmtName = $pdo->prepare("SELECT prenom, nom FROM utilisateurs WHERE idUser = :userId"); $stmtName->bindParam(':userId', $current_owner_user_id, PDO::PARAM_INT); $stmtName->execute(); $ownerInfo = $stmtName->fetch(PDO::FETCH_ASSOC);
        if ($ownerInfo) { $proprietaire_prenom = $ownerInfo['prenom']; $proprietaire_nom = $ownerInfo['nom']; }
    } catch (PDOException $e) { error_log("Erreur récupération nom proprio: " . $e->getMessage()); }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <title>Notifications | Espace Propriétaire</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Centre de notifications pour les propriétaires." />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" id="app-style"/>
    <script src="assets/js/config.js"></script>
    <style>
        /* Styles CSS (inchangés) */
        .list-group-item { cursor: pointer; transition: background-color 0.2s ease-in-out; } .list-group-item:hover { background-color: #f8f9fa; } .notification-unread { background-color: #eef2f7; font-weight: 500; } .notification-unread .notification-content { color: #343a40; } .notification-icon { width: 32px; height: 32px; font-size: 1.1rem; } .notification-time { font-size: 0.8rem; color: #6c757d; } #notificationList .loading-placeholder, #notificationList .no-results-placeholder { text-align: center; font-style: italic; color: var(--ct-secondary-color); padding: 1.5rem; } #notificationList .list-group-item.d-none { display: none !important; } .pagination { margin-bottom: 0 !important; }
        .toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 1100; } .toast { width: 350px; max-width: 100%; font-size: .875rem; background-clip: padding-box; border: 1px solid rgba(0,0,0,.1); box-shadow: 0 .5rem 1rem rgba(0,0,0,.15); border-radius: .25rem; } .toast:not(.showing):not(.show) { opacity: 0; } .toast.hide { display: none; } .toast-header { display: flex; align-items: center; padding: .5rem .75rem; color: #6c757d; background-color: rgba(255,255,255,.85); background-clip: padding-box; border-bottom: 1px solid rgba(0,0,0,.05); border-top-left-radius: calc(.25rem - 1px); border-top-right-radius: calc(.25rem - 1px); } .toast-header .btn-close { margin-left: auto; } .toast-body { padding: .75rem; }
    </style>
</head>
<body>
    <!-- Toast Container -->
    <div class="toast-container position-fixed top-0 end-0 p-3"> <div id="liveToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true"> <div class="toast-header"> <strong class="me-auto" id="toastTitle">Notification</strong> <small id="toastTimestamp">À l'instant</small> <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button> </div> <div class="toast-body" id="toastBody"> Message ici. </div> </div> </div>
    <!-- Wrapper -->
    <div class="wrapper">
         <!-- ========== Topbar Start ========== -->
         <header class=""> <div class="topbar"> <div class="container-fluid"> <div class="navbar-header"> <div class="d-flex align-items-center gap-2"> <div class="topbar-item"><button type="button" class="button-toggle-menu topbar-button"><i class="ri-menu-2-line fs-24"></i></button></div> <form class="app-search d-none d-md-block me-auto"><div class="position-relative"><input type="search" class="form-control border-0" placeholder="Rechercher..." autocomplete="off" value=""><i class="ri-search-line search-widget-icon"></i></div></form> </div> <div class="d-flex align-items-center gap-1"> <div class="topbar-item"><button type="button" class="topbar-button" id="light-dark-mode"><i class="ri-moon-line fs-24 light-mode"></i><i class="ri-sun-line fs-24 dark-mode"></i></button></div> <div class="dropdown topbar-item d-none d-lg-flex"><button type="button" class="topbar-button" data-toggle="fullscreen"><i class="ri-fullscreen-line fs-24 fullscreen"></i><i class="ri-fullscreen-exit-line fs-24 quit-fullscreen"></i></button></div>
                 <div class="dropdown topbar-item"> <button type="button" class="topbar-button position-relative" id="page-header-notifications-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="ri-notification-3-line fs-24"></i><span id="topbarUnreadCount" class="position-absolute topbar-badge fs-10 translate-middle badge bg-danger rounded-pill d-none">0<span class="visually-hidden">unread messages</span></span></button> <div class="dropdown-menu py-0 dropdown-lg dropdown-menu-end" aria-labelledby="page-header-notifications-dropdown"> <div class="p-3 border-top-0 border-start-0 border-end-0 border-dashed border"><div class="row align-items-center"><div class="col"><h6 class="m-0 fs-16 fw-semibold"> Notifications</h6></div><div class="col-auto"><a href="javascript: void(0);" class="text-dark text-decoration-underline" onclick="window.markAllAsRead()"> <small>Marquer tout comme lu</small></a></div></div></div> <div data-simplebar style="max-height: 280px;" id="topbarNotificationList"><p class="text-center p-2 text-muted">Chargement...</p></div> <div class="text-center py-3"><a href="proprietaire-notifications.php" class="btn btn-primary btn-sm">Voir toutes <i class="ri-arrow-right-line ms-1"></i></a></div> </div> </div>
                 <div class="topbar-item d-none d-md-flex"><button type="button" class="topbar-button" id="theme-settings-btn" data-bs-toggle="offcanvas" data-bs-target="#theme-settings-offcanvas" aria-controls="theme-settings-offcanvas"><i class="ri-settings-4-line fs-24"></i></button></div>
                 <div class="dropdown topbar-item"> <a type="button" class="topbar-button" id="page-header-user-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><span class="d-flex align-items-center"><img class="rounded-circle" width="32" src="assets/images/users/avatar-proprio-placeholder.png" alt="avatar"></span></a> <div class="dropdown-menu dropdown-menu-end"> <h6 class="dropdown-header">Bienvenue <?php echo htmlspecialchars($proprietaire_prenom); ?>!</h6> <a class="dropdown-item" href="proprietaire-profil.php"><i class="ri-user-line align-middle me-1"></i> Profil</a> <div class="dropdown-divider my-1"></div> <a class="dropdown-item text-danger" href="auth-signout.php"><i class="ri-logout-box-line align-middle me-1"></i> Déconnexion</a> </div> </div>
            </div> </div> </div> </div> </header>
         <!-- ========== Topbar End ========== -->
          <!-- ========== Right Sidebar (Theme Settings) Start ========== -->
           <div> <div class="offcanvas offcanvas-end border-0 rounded-start-4 overflow-hidden" tabindex="-1" id="theme-settings-offcanvas"> <div class="d-flex align-items-center bg-primary p-3 offcanvas-header"><h5 class="text-white m-0">Paramètres Thème</h5><button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="offcanvas" aria-label="Close"></button></div><div class="offcanvas-body p-0"><div data-simplebar class="h-100"><div class="p-3 settings-bar"></div></div></div><div class="offcanvas-footer border-top p-3 text-center"><div class="row"><div class="col"><button type="button" class="btn btn-danger w-100" id="reset-layout">Réinitialiser</button></div></div></div> </div> </div>
          <!-- ========== Right Sidebar End ========== -->
         <!-- ========== App Menu Start (Propriétaire) ========== -->
         <div class="main-nav"> <div class="logo-box"> <a href="proprietaire-dashboard.php" class="logo-dark"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-dark.png" class="logo-lg"></a> <a href="proprietaire-dashboard.php" class="logo-light"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-light.png" class="logo-lg"></a> </div> <button type="button" class="button-sm-hover" aria-label="Show Full Sidebar"><i class="ri-menu-2-line fs-24 button-sm-hover-icon"></i></button> <div class="scrollbar" data-simplebar> <ul class="navbar-nav" id="navbar-nav"> <li class="menu-title">Menu Propriétaire</li> <li class="nav-item"><a class="nav-link" href="proprietaire-dashboard.php"><span class="nav-icon"><i class="ri-dashboard-2-line"></i></span><span class="nav-text">Tableau de Bord</span></a></li> <li class="nav-item"><a class="nav-link" href="proprietaire-mes-biens.php"><span class="nav-icon"><i class="ri-community-line"></i></span><span class="nav-text">Mes Biens</span></a></li> <li class="nav-item"><a class="nav-link" href="proprietaire-finances.php"><span class="nav-icon"><i class="ri-money-dollar-circle-line"></i></span><span class="nav-text">Finances</span></a></li> <li class="nav-item"><a class="nav-link" href="proprietaire-contrats.php"><span class="nav-icon"><i class="ri-file-list-3-line"></i></span><span class="nav-text">Contrats</span></a></li> <li class="nav-item"><a class="nav-link active" href="proprietaire-notifications.php"><span class="nav-icon"><i class="ri-notification-3-line"></i></span><span class="nav-text">Notifications</span></a></li> </ul> </div> </div>
         <!-- ========== App Menu End ========== -->

         <!-- ==================================================== -->
         <!-- Start Page Content here -->
         <!-- ==================================================== -->
         <div class="page-content"> <div class="container-fluid">
                 <div class="row"> <div class="col-12"> <div class="page-title-box"> <h4 class="mb-0 fw-semibold">Centre de Notifications</h4> <ol class="breadcrumb mb-0"> <li class="breadcrumb-item"><a href="proprietaire-dashboard.php">Tableau de Bord</a></li> <li class="breadcrumb-item active">Notifications</li> </ol> </div> </div> </div>
                 <div class="row mb-3"> <div class="col-12"> <div class="card"> <div class="card-body pb-2"> <form id="filterNotificationsForm" class="row gy-2 gx-2 align-items-center"> <div class="col-xl-4 col-lg-4 col-md-6"> <label for="filterNotifStatus" class="form-label visually-hidden">Statut</label> <select class="form-select form-select-sm" id="filterNotifStatus" name="statutNotif"> <option value="">Toutes</option> <option value="Non Lu">Non Lues</option> <option value="Lu">Lues</option> </select> </div> <div class="col-xl-4 col-lg-4 col-md-6"> <label for="filterNotifType" class="form-label visually-hidden">Type</label> <select class="form-select form-select-sm" id="filterNotifType" name="typeNotif"> <option value="">Tous Types</option> <option value="paiement">Paiement</option> <option value="contrat">Contrat</option> <option value="bien">Bien Immobilier</option> <option value="systeme">Système</option> <!-- Adapter --> </select> </div> <div class="col-auto"> <button type="submit" class="btn btn-primary btn-sm"><i class="ri-filter-3-line me-1"></i>Filtrer</button> <button type="reset" class="btn btn-secondary btn-sm ms-1" id="resetNotificationFilters"><i class="ri-refresh-line me-1"></i>Reset</button> </div> </form> </div> </div> </div> </div>
                 <div class="row"> <div class="col-12"> <div class="card"> <div class="card-header d-flex justify-content-between align-items-center"> <h5 class="card-title mb-0">Historique des Notifications</h5> <button type="button" class="btn btn-light btn-sm" id="markAllReadBtn" onclick="window.markAllAsRead()"> <i class="ri-mail-open-line me-1"></i> Marquer tout comme lu </button> </div> <div class="card-body p-0"> <div class="list-group list-group-flush" id="notificationList"> <div class="list-group-item loading-placeholder"> <div class="d-flex justify-content-center align-items-center py-4"> <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Chargement... </div> </div> <div class="list-group-item no-results-placeholder d-none text-center py-4"> Aucune notification trouvée. </div> </div> </div> <div class="card-footer bg-light border-top d-flex justify-content-end pt-2 pb-2"> <nav aria-label="Pagination Notifications"><ul id="paginationControls" class="pagination pagination-sm"></ul></nav> </div> </div> </div> </div>
            </div> </div>
         <!-- ==================================================== -->
         <!-- End Page Content -->
         <!-- ==================================================== -->
         <!-- Footer -->
          <footer class="footer"> <div class="container-fluid"> <div class="row"> <div class="col-12 text-center"> <script>document.write(new Date().getFullYear())</script> © Gestion Locative Notariale. </div> </div> </div> </footer>
    </div> <!-- wrapper -->

    <!-- JS Vendor, App -->
    <script src="assets/js/vendor.js"></script>
    <script src="assets/js/app.js"></script>

    <!-- Script Page Notifications -->
    <script>
        // --- Fonctions globales pour onclick ---
        window.markAsRead = async function(notificationId, element) { /* ... (Code JS inchangé) ... */ if (!element || !notificationId || !element.classList.contains('notification-unread')) { console.log(`Notif ${notificationId || 'inconnue'} déjà lue ou invalide.`); return; } console.log(`Tentative lu pour notif ${notificationId}`); element.style.opacity = '0.6'; const formData = new FormData(); formData.append('action', 'mark_notification_read'); formData.append('id', notificationId); try { const data = await fetchData(API_ENDPOINT, { method: 'POST', body: formData }, null, 'markNotificationRead'); if (data.success) { element.classList.remove('notification-unread'); element.style.opacity = '1'; console.log(`Notif ${notificationId} marquée lue.`); updateUnreadCount(-1); } else { element.style.opacity = '1'; console.warn(`Échec marquage lu notif ${notificationId}`); } } catch (error) { element.style.opacity = '1'; } };
        window.markAllAsRead = async function() { /* ... (Code JS inchangé) ... */ console.log("Tentative tout lu."); const unreadItems = document.querySelectorAll('#notificationList .notification-unread'); if (unreadItems.length === 0) { showToast("Déjà lues.", 'info'); return; } unreadItems.forEach(item => item.style.opacity = '0.6'); const markAllBtn = document.getElementById('markAllReadBtn'); if(markAllBtn) markAllBtn.disabled = true; const formData = new FormData(); formData.append('action', 'mark_all_notifications_read'); try { const data = await fetchData(API_ENDPOINT, { method: 'POST', body: formData }, null, 'markAllNotificationsRead'); if (data.success) { unreadItems.forEach(item => { item.classList.remove('notification-unread'); item.style.opacity = '1'; }); updateUnreadCount(0); showToast(data.message || "Tout marqué comme lu.", 'success'); } else { unreadItems.forEach(item => item.style.opacity = '1'); console.warn("Échec marquage tout lu"); } } catch (error) { unreadItems.forEach(item => item.style.opacity = '1'); } finally { if(markAllBtn) markAllBtn.disabled = false; } };

        // --- Code exécuté après chargement du DOM ---
        document.addEventListener('DOMContentLoaded', function() {
             const notificationListDiv = document.getElementById('notificationList'); const paginationControls = document.getElementById('paginationControls'); const filterForm = document.getElementById('filterNotificationsForm'); const resetFiltersBtn = document.getElementById('resetNotificationFilters'); const loadingPlaceholder = notificationListDiv?.querySelector('.loading-placeholder'); const noResultsPlaceholder = notificationListDiv?.querySelector('.no-results-placeholder'); const topbarUnreadCount = document.getElementById('topbarUnreadCount'); const toastEl = document.getElementById('liveToast'); const toastBody = document.getElementById('toastBody'); const toastTitle = document.getElementById('toastTitle'); const liveToast = toastEl ? new bootstrap.Toast(toastEl, { delay: 5000 }) : null; const API_ENDPOINT = '';
             // Fonctions Utilitaires
             function showToast(message, type = 'info') { if (liveToast) { toastBody.textContent = message; toastEl.classList.remove('text-bg-success', 'text-bg-danger', 'text-bg-warning', 'text-bg-info'); let bgClass = 'text-bg-info'; let title = 'Info'; if (type === 'success') { bgClass = 'text-bg-success'; title = 'Succès'; } else if (type === 'error' || type === 'danger') { bgClass = 'text-bg-danger'; title = 'Erreur'; } else if (type === 'warning') { bgClass = 'text-bg-warning'; title = 'Attention'; } toastEl.classList.add(bgClass); toastTitle.textContent = title; liveToast.show(); } else { console.warn("Toast non init."); alert(`[${type}] ${message}`); } }
             async function fetchData(url, options = {}, errorDisplayElement = null, actionName = 'fetch') { const defaultHeaders = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }; options.headers = { ...defaultHeaders, ...options.headers }; if (errorDisplayElement) errorDisplayElement.classList.add('d-none'); try { const response = await fetch(url, options); const responseText = await response.text(); let data; try { data = JSON.parse(responseText); } catch (parseError) { console.error(`Erreur JSON ${actionName}:`, parseError, responseText); throw new Error(`Réponse serveur invalide.`); } if (!response.ok || (data && data.success === false)) { throw new Error(data?.message || `Erreur ${actionName}`); } return data; } catch (error) { console.error(`Erreur ${actionName}:`, error); const errorMessage = error.message || 'Erreur inconnue.'; if (errorDisplayElement) { errorDisplayElement.textContent = errorMessage; errorDisplayElement.classList.remove('d-none'); } else { showToast(errorMessage, 'error'); } throw error; } }
             // Création Item Notif
             function createNotificationItem(notif) { const item = document.createElement('a'); item.href = "javascript:void(0);"; item.classList.add('list-group-item', 'list-group-item-action'); item.setAttribute('data-notification-id', notif.idNotif); item.onclick = function() { window.markAsRead(notif.idNotif, this); }; if (notif.statutNotif !== 'Lu') { item.classList.add('notification-unread'); } let iconClass = 'ri-information-line'; let iconColor = 'text-secondary'; let bgColor = 'bg-light'; switch (notif.typeNotif ? notif.typeNotif.toLowerCase() : 'systeme') { case 'paiement': iconClass = 'ri-money-dollar-circle-line'; iconColor = 'text-success'; bgColor='bg-success-subtle'; break; case 'contrat': iconClass = 'ri-file-list-3-line'; iconColor = 'text-warning'; bgColor='bg-warning-subtle'; break; case 'bien': iconClass = 'ri-community-line'; iconColor = 'text-info'; bgColor='bg-info-subtle'; break; case 'maintenance': iconClass = 'ri-tools-line'; iconColor = 'text-primary'; bgColor='bg-primary-subtle'; break; case 'erreur': case 'suspendu': iconClass = 'ri-error-warning-line'; iconColor = 'text-danger'; bgColor='bg-danger-subtle'; break; default: iconClass = 'ri-settings-3-line'; iconColor = 'text-muted'; bgColor='bg-light'; break; } let formattedDate = 'Date inconnue'; try { const dateObj = new Date(notif.dateNotif); if (!isNaN(dateObj.getTime())) { formattedDate = dateObj.toLocaleString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }); } } catch (e) { console.error("Err date:", e); } item.innerHTML = `<div class="d-flex align-items-start"> <div class="flex-shrink-0 me-3"> <div class="avatar-sm rounded-circle ${bgColor} d-flex align-items-center justify-content-center notification-icon"><i class="${iconClass} ${iconColor}"></i></div> </div> <div class="flex-grow-1"> <div class="notification-content">${notif.contenu || ''}</div> <small class="notification-time text-muted">${formattedDate}</small> </div> </div>`; return item; }
             // MàJ Compteur Non Lus
             window.updateUnreadCount = function(count) { if (!topbarUnreadCount) return; let finalCount = parseInt(count); if (isNaN(finalCount) || finalCount < 0) { finalCount = notificationListDiv?.querySelectorAll('.notification-unread').length ?? 0; } topbarUnreadCount.textContent = finalCount > 9 ? '9+' : finalCount; topbarUnreadCount.classList.toggle('d-none', finalCount === 0); const topbarList = document.getElementById('topbarNotificationList'); if (topbarList) { topbarList.innerHTML = finalCount === 0 ? '<p class="text-center p-2 text-muted small">Aucune nouvelle</p>' : '<p class="text-center p-2 text-muted small">Chargement aperçu...</p>'; } }
             // MàJ Pagination
             function updateNotificationPagination(paginationInfo, currentFilters) { if (!paginationControls || !paginationInfo) return; paginationControls.innerHTML = ''; if (paginationInfo.totalPages <= 1) return; const createPageLink = (page, text, isDisabled = false, isActive = false) => { const li = document.createElement('li'); li.classList.add('page-item'); if (isDisabled) li.classList.add('disabled'); if (isActive) li.classList.add('active'); li.innerHTML = `<a class="page-link" href="#" data-page="${page}">${text}</a>`; return li; } paginationControls.appendChild(createPageLink(paginationInfo.currentPage - 1, 'Précédent', paginationInfo.currentPage === 1)); const maxPagesToShow = 5; let startPage, endPage; if (paginationInfo.totalPages <= maxPagesToShow) { startPage = 1; endPage = paginationInfo.totalPages; } else { let maxPagesBeforeCurrentPage = Math.floor(maxPagesToShow / 2); let maxPagesAfterCurrentPage = Math.ceil(maxPagesToShow / 2) - 1; if (paginationInfo.currentPage <= maxPagesBeforeCurrentPage) { startPage = 1; endPage = maxPagesToShow; } else if (paginationInfo.currentPage + maxPagesAfterCurrentPage >= paginationInfo.totalPages) { startPage = paginationInfo.totalPages - maxPagesToShow + 1; endPage = paginationInfo.totalPages; } else { startPage = paginationInfo.currentPage - maxPagesBeforeCurrentPage; endPage = paginationInfo.currentPage + maxPagesAfterCurrentPage; } } if (startPage > 1) { paginationControls.appendChild(createPageLink(1, '1')); if (startPage > 2) { paginationControls.appendChild(createPageLink(null, '...', true)); } } for (let i = startPage; i <= endPage; i++) { paginationControls.appendChild(createPageLink(i, i, false, i === paginationInfo.currentPage)); } if (endPage < paginationInfo.totalPages) { if (endPage < paginationInfo.totalPages - 1) { paginationControls.appendChild(createPageLink(null, '...', true)); } paginationControls.appendChild(createPageLink(paginationInfo.totalPages, paginationInfo.totalPages)); } paginationControls.appendChild(createPageLink(paginationInfo.currentPage + 1, 'Suivant', paginationInfo.currentPage === paginationInfo.totalPages)); paginationControls.querySelectorAll('a.page-link[data-page]').forEach(link => { link.addEventListener('click', function(e) { e.preventDefault(); if (this.closest('.page-item').classList.contains('disabled') || this.closest('.page-item').classList.contains('active')) return; const page = parseInt(this.getAttribute('data-page')); if (!isNaN(page)) { const currentFormData = new FormData(filterForm); const currentFiltersObj = Object.fromEntries(currentFormData.entries()); loadNotifications(page, currentFiltersObj); } }); }); }
            // --- Chargement principal Notifications ---
            async function loadNotifications(page = 1, filters = {}) { if (!notificationListDiv || !loadingPlaceholder || !noResultsPlaceholder) { return; } loadingPlaceholder.classList.remove('d-none'); noResultsPlaceholder.classList.add('d-none'); notificationListDiv.querySelectorAll('.list-group-item:not(.loading-placeholder):not(.no-results-placeholder)').forEach(item => item.remove()); const params = new URLSearchParams(filters); params.append('page', page); const url = `${API_ENDPOINT}?action=get_notifications&${params.toString()}`; try { const data = await fetchData(url, { method: 'GET' }, noResultsPlaceholder, 'loadNotifications'); loadingPlaceholder.classList.add('d-none'); if (data.data && Array.isArray(data.data) && data.data.length > 0) { data.data.forEach(notif => { const item = createNotificationItem(notif); notificationListDiv.appendChild(item); }); updateNotificationPagination(data.pagination, filters); updateUnreadCount(data.pagination.unreadCount); } else { noResultsPlaceholder.textContent = "Aucune notification trouvée."; noResultsPlaceholder.classList.remove('d-none'); updateNotificationPagination({ currentPage: 1, totalPages: 1, totalItems: 0, unreadCount: 0 }, filters); updateUnreadCount(0); } } catch (error) { loadingPlaceholder.classList.add('d-none'); noResultsPlaceholder.classList.remove('d-none'); updateUnreadCount(0); } }
            // --- Écouteurs ---
            if (filterForm) { filterForm.addEventListener('submit', function(e) { e.preventDefault(); const formData = new FormData(filterForm); const filters = Object.fromEntries(formData.entries()); loadNotifications(1, filters); }); }
            if (resetFiltersBtn) { resetFiltersBtn.addEventListener('click', function() { if(filterForm) filterForm.reset(); const filters = {}; loadNotifications(1, filters); }); }
            // --- Init ---
            loadNotifications();
         }); // Fin DOMContentLoaded
     </script>

</body>
</html>