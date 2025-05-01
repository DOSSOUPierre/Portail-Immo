<?php
// --- notaire-gestion-locataires.php (Adapté depuis admin-gestion-utilisateurs) ---

if (session_status() === PHP_SESSION_NONE) { session_start(); }
// *** 1. Vérification Rôle Notaire ***
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'Notaire') {
    header("Location: auth-signin.php");
    exit;
}

require_once 'db_connection.php'; // $pdo

// --- 2. Récupération ID Notaire Connecté ---
function get_logged_in_notaire_id(PDO $pdo, ?int $userId): ?int {
    if ($userId === null) return null;
    try {
        $stmt = $pdo->prepare("SELECT idNotaire FROM notaire WHERE idUser = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetchColumn();
        return $result ? (int)$result : null;
    } catch (PDOException $e) { error_log("Erreur get_logged_in_notaire_id: " . $e->getMessage()); return null; }
}

$logged_in_user_id = $_SESSION['user_id'] ?? null;
$current_notaire_id = get_logged_in_notaire_id($pdo, $logged_in_user_id);

if ($current_notaire_id === null) {
     $_SESSION['error_message'] = "Impossible d'identifier le compte notaire associé.";
     header("Location: auth-signin.php?logout=1");
     exit;
}

$pageAlerts = [];
$defaultAvatar = 'assets/images/users/avatar-placeholder.png';

// --- 3. GESTION DES ACTIONS AJAX (Simplifié) ---
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['action'])) {
     header('Content-Type: application/json');
     $action = $_GET['action'];
     $responseAjax = ['success' => false, 'message' => 'Action inconnue ou erreur serveur.'];
     $userIdAjax = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null; // ID Utilisateur (locataire)

     try {
         // --- Action: get_locataires (Filtré par Notaire) ---
         if ($action === 'get_locataires') {
            $page = isset($_GET['page']) ? filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) : 1;
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $itemsPerPage = 15;
            $offset = ($page - 1) * $itemsPerPage;

            $locataires = []; $totalItems = 0;

            // SQL pour récupérer les locataires LIÉS au notaire via les contrats
            $baseSqlSelect = "SELECT DISTINCT u.idUser, u.nom AS locNom, u.prenom AS locPrenom, u.email AS locEmail, u.statut AS userStatut, l.telephone AS locTel ";
            $baseSqlFrom = " FROM utilisateurs u JOIN locataire l ON u.idUser = l.idUser JOIN contrat c ON l.idLocataire = c.idLocataire ";
            $baseSqlWhere = " WHERE c.idNotaire = :idNotaire "; // Filtre crucial
            $params = [':idNotaire' => $current_notaire_id];

            // Filtre de recherche
            if (!empty($search)) {
                $baseSqlWhere .= " AND (u.nom LIKE :search OR u.prenom LIKE :search OR u.email LIKE :search)";
                $params[':search'] = "%" . $search . "%";
            }

            // Requête COUNT
            $sqlCount = "SELECT COUNT(DISTINCT u.idUser) " . $baseSqlFrom . $baseSqlWhere;
            $stmtCount = $pdo->prepare($sqlCount);
            $stmtCount->execute($params);
            $totalItems = $stmtCount->fetchColumn();
            $totalPages = ceil($totalItems / $itemsPerPage);
            $page = max(1, min($page, $totalPages));
            $offset = ($page - 1) * $itemsPerPage;

            // Requête DATA
            $sqlData = $baseSqlSelect . $baseSqlFrom . $baseSqlWhere . " ORDER BY u.nom ASC, u.prenom ASC LIMIT :limit OFFSET :offset";
            $stmtData = $pdo->prepare($sqlData);
            foreach ($params as $key => $value) { $stmtData->bindValue($key, $value); }
            $stmtData->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
            $stmtData->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmtData->execute();
            $locataires = $stmtData->fetchAll(PDO::FETCH_ASSOC);

            $responseAjax['success'] = true;
            $responseAjax['locataires'] = $locataires;
            $responseAjax['pagination'] = [
                'currentPage' => $page, 'totalPages' => $totalPages, 'totalItems' => $totalItems ];
         }
         // --- Action : Récupérer Détails Locataire (Vérifie le lien avec le notaire) ---
         elseif ($action === 'get_user_details' && $userIdAjax) {
             $stmtCheckLink = $pdo->prepare("SELECT COUNT(*) FROM locataire l JOIN contrat c ON l.idLocataire = c.idLocataire WHERE l.idUser = :idUser AND c.idNotaire = :idNotaire");
             $stmtCheckLink->execute([':idUser' => $userIdAjax, ':idNotaire' => $current_notaire_id]);
             if ($stmtCheckLink->fetchColumn() > 0) { // Est lié au notaire
                 $sql = "SELECT u.idUser, u.nom, u.prenom, u.email, u.role, u.statut, l.telephone, l.adresse as adresseLocataire
                         FROM utilisateurs u
                         LEFT JOIN locataire l ON u.idUser = l.idUser
                         WHERE u.idUser = :idUser AND u.role = 'Locataire'"; // Assure que c'est bien un locataire
                 $stmt = $pdo->prepare($sql);
                 $stmt->execute([':idUser' => $userIdAjax]);
                 $details = $stmt->fetch(PDO::FETCH_ASSOC);

                 if ($details) {
                     $responseAjax['success'] = true;
                     $responseAjax['details'] = $details;
                 } else { $responseAjax['message'] = "Locataire non trouvé."; http_response_code(404); }
             } else {
                  $responseAjax['message'] = "Accès aux détails refusé."; http_response_code(403);
             }
         }
         // --- Autres actions non pertinentes retirées ---
         else {
             $responseAjax['message'] = 'Action non valide pour cette page.';
         }
     } catch (PDOException $e) {
         error_log("Erreur PDO Action AJAX Notaire Locataires: " . $e->getMessage());
         $responseAjax['message'] = 'Erreur base de données.';
         http_response_code(500);
     }

     echo json_encode($responseAjax);
     exit; // Fin script AJAX
}

// --- CHARGEMENT INITIAL (Vide car AJAX) ---

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <title>Gestion Locataires | Espace Notaire</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Liste des locataires associés aux contrats gérés par l'étude notariale." />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
    <script src="assets/js/config.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        /* Styles CSS (Similaires, ajustez si besoin) */
        .table th, .table td { vertical-align: middle; font-size: 0.875rem; }
        .table th { font-weight: 500; }
        .loading-initial td, .no-results-row td { text-align: center; font-style: italic; color: #6c757d; padding: 1.5rem; }
        .search-filter-row { margin-bottom: 1rem; }
        .modal-body dl dt { font-weight: 500; color: var(--couleur-primaire); }
        .modal-body dl dd { margin-bottom: 0.8rem; color: var(--couleur-texte-dark); }
        .btn-sm i { font-size: 1rem; }
        .pagination { margin-bottom: 0; }
        .table td:last-child { text-align: center; width: 180px; } /* Colonne actions */
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- ========== Topbar, Sidebar (Votre HTML Notaire) ========== -->
        <header class=""> <div class="topbar"> <div class="container-fluid"> <div class="navbar-header"> <div class="d-flex align-items-center gap-2"> <div class="topbar-item"><button type="button" class="button-toggle-menu topbar-button"><i class="ri-menu-2-line fs-24"></i></button></div> <form class="app-search d-none d-md-block me-auto"><div class="position-relative"><input type="search" class="form-control border-0" placeholder="Rechercher..." autocomplete="off" value=""><i class="ri-search-line search-widget-icon"></i></div></form> </div> <div class="d-flex align-items-center gap-1"> <div class="topbar-item"><button type="button" class="topbar-button" id="light-dark-mode"><i class="ri-moon-line fs-24 light-mode"></i><i class="ri-sun-line fs-24 dark-mode"></i></button></div> <div class="dropdown topbar-item d-none d-lg-flex"><button type="button" class="topbar-button" data-toggle="fullscreen"><i class="ri-fullscreen-line fs-24 fullscreen"></i><i class="ri-fullscreen-exit-line fs-24 quit-fullscreen"></i></button></div> <div class="dropdown topbar-item"> <button type="button" class="topbar-button position-relative" id="page-header-notifications-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="ri-notification-3-line fs-24"></i><span id="topbarUnreadCount" class="position-absolute topbar-badge fs-10 translate-middle badge bg-danger rounded-pill">0<span class="visually-hidden">unread</span></span></button> <div class="dropdown-menu py-0 dropdown-lg dropdown-menu-end" aria-labelledby="page-header-notifications-dropdown"> <div class="p-3 border-top-0 border-start-0 border-end-0 border-dashed border"><div class="row align-items-center"><div class="col"><h6 class="m-0 fs-16 fw-semibold"> Notifications</h6></div><div class="col-auto"><a href="javascript: void(0);" class="text-dark text-decoration-underline"> <small>Marquer tout comme lu</small></a></div></div></div> <div data-simplebar style="max-height: 280px;" id="topbarNotificationList"></div> <div class="text-center py-3"><a href="notaire-notifications.html" class="btn btn-primary btn-sm">Tout voir <i class="ri-arrow-right-line ms-1"></i></a></div> </div> </div> <div class="topbar-item d-none d-md-flex"><button type="button" class="topbar-button" id="theme-settings-btn" data-bs-toggle="offcanvas" data-bs-target="#theme-settings-offcanvas" aria-controls="theme-settings-offcanvas"><i class="ri-settings-4-line fs-24"></i></button></div> <div class="dropdown topbar-item"> <a type="button" class="topbar-button" id="page-header-user-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><span class="d-flex align-items-center"><img class="rounded-circle" width="32" src="assets/images/users/avatar-notaire.png" alt="notaire"></span></a> <div class="dropdown-menu dropdown-menu-end"> <h6 class="dropdown-header" id="userDropdownHeader">Bienvenue Maître !</h6> <a class="dropdown-item" href="notaire-profil.html"><i class="ri-user-line align-middle me-1"></i> Mon Profil/Cabinet</a> <div class="dropdown-divider my-1"></div> <a class="dropdown-item text-danger" href="auth-signout.php"><i class="ri-logout-box-line align-middle me-1"></i> Déconnexion</a> </div> </div> </div> </div> </div> </header>
         <div> <div class="offcanvas offcanvas-end border-0 rounded-start-4 overflow-hidden" tabindex="-1" id="theme-settings-offcanvas"> <div class="d-flex align-items-center bg-primary p-3 offcanvas-header"><h5 class="text-white m-0">Theme Settings</h5><button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="offcanvas" aria-label="Close"></button></div><div class="offcanvas-body p-0"><div data-simplebar class="h-100"><div class="p-3 settings-bar"></div></div></div><div class="offcanvas-footer border-top p-3 text-center"><div class="row"><div class="col"><button type="button" class="btn btn-danger w-100" id="reset-layout">Reset</button></div></div></div> </div> </div>
         <div class="main-nav">
            <div class="logo-box"> <a href="notaire-dashboard.php" class="logo-dark"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-dark.png" class="logo-lg"></a> <a href="notaire-dashboard.php" class="logo-light"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-light.png" class="logo-lg"></a> </div>
            <button type="button" class="button-sm-hover" aria-label="Show Full Sidebar"><i class="ri-menu-2-line fs-24 button-sm-hover-icon"></i></button>
            <div class="scrollbar" data-simplebar>
                 <ul class="navbar-nav" id="navbar-nav">
                      <li class="menu-title">Menu Notaire</li>
                      <li class="nav-item"><a class="nav-link" href="notaire-dashboard.php"><span class="nav-icon"><i class="ri-dashboard-line"></i></span><span class="nav-text">Tableau de Bord</span></a></li>
                      <li class="nav-item"><a class="nav-link" href="notaire-gestion-biens.php"><span class="nav-icon"><i class="ri-community-line"></i></span><span class="nav-text">Gestion Biens</span></a></li>
                      <!-- *** Lien Actif *** -->
                      <li class="nav-item"><a class="nav-link active" href="notaire-gestion-locataires.php"><span class="nav-icon"><i class="ri-group-line"></i></span><span class="nav-text">Gestion Locataires</span></a></li>
                      <li class="nav-item"><a class="nav-link" href="notaire-gestion-contrats.php"><span class="nav-icon"><i class="ri-file-list-3-line"></i></span><span class="nav-text">Gestion Contrats</span></a></li>
                      <li class="nav-item"><a class="nav-link" href="notaire-suivi-financier.php"><span class="nav-icon"><i class="ri-bank-card-line"></i></span><span class="nav-text">Suivi Financier</span></a></li>
                      <li class="nav-item"><a class="nav-link" href="notaire-support.php"><span class="nav-icon"><i class="ri-customer-service-2-line"></i></span><span class="nav-text">Support & Litiges</span></a></li>
                      <li class="nav-item"><a class="nav-link" href="notaire-notifications.php"><span class="nav-icon"><i class="ri-notification-3-line"></i></span><span class="nav-text">Notifications</span><span class="badge bg-danger badge-pill text-end" id="sidebarUnreadCount" style="display: none;"></span></a></li>
                 </ul>
            </div>
       </div>
        <!-- ============================================================== -->

        <div class="page-content">
            <div class="container-fluid">
                <!-- Titre de Page -->
                 <div class="row"> <div class="col-12"> <div class="page-title-box"> <h4 class="mb-0 fw-semibold">Gestion des Locataires</h4> <ol class="breadcrumb mb-0"> <li class="breadcrumb-item"><a href="notaire-dashboard.php">Espace Notaire</a></li> <li class="breadcrumb-item active">Mes Locataires</li> </ol> </div> </div> </div>
                 <div id="pageAlertPlaceholder"> <?php foreach ($pageAlerts as $alert): ?> <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show"> <?php echo htmlspecialchars($alert['message']); ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button> </div> <?php endforeach; ?> </div>

                <!-- Barre de Recherche -->
                 <div class="row search-filter-row align-items-center mb-3">
                     <div class="col-12">
                         <form id="locataireSearchForm">
                             <div class="input-group input-group-sm">
                                 <input type="search" id="locataireSearchInput" class="form-control" placeholder="Rechercher un locataire par nom ou email...">
                                 <button class="btn btn-light" type="submit" title="Rechercher"><i class="ri-search-line"></i></button>
                                 <button class="btn btn-light" type="button" id="resetLocataireSearchBtn" title="Effacer la recherche" style="display: none;"><i class="ri-close-line"></i></button>
                             </div>
                         </form>
                     </div>
                 </div>

                 <!-- Tableau des Locataires -->
                 <div class="row"> <div class="col-12"> <div class="card">
                     <div class="card-header"><h5 class="card-title mb-0">Locataires Associés à Vos Contrats</h5></div>
                     <div class="card-body p-0"> <div class="table-responsive">
                         <table class="table table-sm table-hover table-centered mb-0 user-table">
                             <thead class="table-light">
                                 <tr>
                                     <th>Nom & Prénom</th>
                                     <th>Email</th>
                                     <th>Téléphone</th>
                                     <th>Statut Compte</th>
                                     <th style="width: 180px;">Actions</th>
                                 </tr>
                             </thead>
                             <tbody id="locatairesTableBody">
                                 <tr class="loading-initial"><td colspan="5" class="text-center py-3"><span class="spinner-border spinner-border-sm"></span> Chargement des locataires...</td></tr>
                             </tbody>
                         </table>
                     </div></div>
                     <div class="card-footer bg-white border-top d-flex justify-content-between align-items-center">
                         <div class="fs-sm text-muted">
                             Total: <span id="totalLocatairesCount">0</span> locataire(s)
                         </div>
                         <nav><ul id="paginationLocataires" class="pagination pagination-sm mb-0"></ul></nav>
                     </div>
                 </div> </div> </div>

            </div> <!-- container-fluid -->
        </div> <!-- page-content -->

        <!-- Modal Voir Détails Locataire -->
        <div class="modal fade" id="modalUserDetails" tabindex="-1" aria-labelledby="modalUserDetailsLabel" aria-hidden="true">
             <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                 <div class="modal-content">
                     <div class="modal-header">
                         <h5 class="modal-title" id="modalUserDetailsLabel">Détails Locataire</h5>
                         <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                     </div>
                     <div class="modal-body" id="userDetailsContent">
                         <p class="text-center py-5">Chargement des détails...</p>
                     </div>
                     <div class="modal-footer">
                         <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                         <a href="#" id="modalContactEmailBtn" class="btn btn-primary btn-sm" style="display: none;"><i class="ri-mail-send-line"></i> Email</a>
                         <a href="#" id="modalContactTelBtn" class="btn btn-success btn-sm" style="display: none;"><i class="ri-phone-line"></i> Appeler</a>
                     </div>
                 </div>
             </div>
         </div>

        <!-- Footer -->
        <footer class="footer"> <div class="container-fluid"> <div class="row"> <div class="col-12 text-center"> <script>document.write(new Date().getFullYear())</script> © Gestion Locative Notariale. </div> </div> </div> </footer>
    </div> <!-- wrapper -->

    <!-- JS -->
    <script src="assets/js/vendor.js"></script>
    <script src="assets/js/app.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // --- Fonction Globale Voir Détails ---
        window.viewUserDetails = function(userId) {
             const modalEl = document.getElementById('modalUserDetails');
             const modalContent = document.getElementById('userDetailsContent');
             const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
             const emailBtn = document.getElementById('modalContactEmailBtn');
             const telBtn = document.getElementById('modalContactTelBtn');
             modalContent.innerHTML = '<p class="text-center py-5"><span class="spinner-border spinner-border-sm"></span> Chargement...</p>';
             emailBtn.style.display = 'none'; telBtn.style.display = 'none';
             modalInstance.show();

             fetch(`?action=get_user_details&id=${userId}`)
                 .then(response => response.ok ? response.json() : response.text().then(text => { throw new Error(`Erreur réseau: ${response.status} - ${text}`) }))
                 .then(data => {
                     if (data.success && data.details) {
                         const d = data.details; let detailsHtml = '<dl class="row">';
                         const display = (val) => val ? escapeHTML(val) : '<em class="text-muted">Non fourni</em>';
                         const escapeHTML = str => str ? String(str).replace(/[&<>'"]/g, tag => ({'&': '&', '<': '<', '>': '>', "'": ''', '"': '"'}[tag] || tag)) : '';

                         detailsHtml += `<dt class="col-sm-4">Nom Complet:</dt><dd class="col-sm-8">${display(d.prenom)} ${display(d.nom)}</dd>`;
                         detailsHtml += `<dt class="col-sm-4">Email:</dt><dd class="col-sm-8">${display(d.email)}</dd>`;
                         detailsHtml += `<dt class="col-sm-4">Téléphone:</dt><dd class="col-sm-8">${display(d.telephone)}</dd>`;
                          detailsHtml += `<dt class="col-sm-4">Adresse (Locataire):</dt><dd class="col-sm-8">${display(d.adresseLocataire)}</dd>`;
                         detailsHtml += `<dt class="col-sm-4">Statut Compte:</dt><dd class="col-sm-8"><span class="badge ${d.statut === 'Actif' ? 'bg-success' : 'bg-danger'}">${display(d.statut)}</span></dd>`;
                         detailsHtml += '</dl>';
                         modalContent.innerHTML = detailsHtml;

                         if (d.email) { emailBtn.href = `mailto:${d.email}`; emailBtn.style.display = 'inline-block'; }
                         if (d.telephone) { telBtn.href = `tel:${d.telephone.replace(/[^0-9+]/g, '')}`; telBtn.style.display = 'inline-block'; }
                     } else {
                         modalContent.innerHTML = `<p class="text-danger text-center">Erreur: ${escapeHTML(data.message || 'Impossible de charger les détails.')}</p>`;
                     }
                 })
                 .catch(error => {
                     console.error('Error fetching details:', error);
                     modalContent.innerHTML = `<p class="text-danger text-center">Erreur réseau (${escapeHTML(error.message)}).</p>`;
                 });
         };

        // --- Script Principal ---
        document.addEventListener('DOMContentLoaded', function() {
            const tableBody = document.getElementById('locatairesTableBody');
            const paginationControls = document.getElementById('paginationLocataires');
            const totalCountSpan = document.getElementById('totalLocatairesCount');
            const searchForm = document.getElementById('locataireSearchForm');
            const searchInput = document.getElementById('locataireSearchInput');
            const resetSearchBtn = document.getElementById('resetLocataireSearchBtn');
            let currentPage = 1;
            let currentSearchTerm = '';
            let currentPaginationInfo = {};

            // --- Fonction Chargement Locataires ---
            function loadLocataires(page = 1, searchTerm = currentSearchTerm) {
                 currentPage = page; currentSearchTerm = searchTerm;
                 const loadingHTML = `<tr class="loading-row"><td colspan="5" class="text-center py-3"><span class="spinner-border spinner-border-sm me-2"></span>Chargement...</td></tr>`;
                 tableBody.innerHTML = loadingHTML;
                 const noResultsRowHTML = `<tr class="no-results-row" style="display: none;"><td colspan="5" class="text-center py-3">Aucun locataire trouvé.</td></tr>`;

                 const params = new URLSearchParams({ action: 'get_locataires', page, search: searchTerm });
                 const url = `?${params.toString()}`;

                 fetch(url)
                 .then(response => response.ok ? response.json() : response.text().then(text => { throw new Error(`Erreur réseau: ${response.status} - ${text}`) }))
                 .then(data => {
                     tableBody.innerHTML = '';
                     if (data.success && data.locataires && data.locataires.length > 0) {
                         totalCountSpan.textContent = data.pagination.totalItems;
                         currentPaginationInfo = data.pagination;
                         data.locataires.forEach(loc => {
                             const row = tableBody.insertRow(); row.id = `locataireRowNotaire-${loc.idLocataire}`;
                             const escapeHTML = str => str ? String(str).replace(/[&<>'"]/g, tag => ({'&': '&', '<': '<', '>': '>', "'": ''', '"': '"'}[tag] || tag)) : '';

                             const nomComplet = escapeHTML(`${loc.locPrenom || ''} ${loc.locNom || ''}`);
                             const email = escapeHTML(loc.locEmail || '-');
                             const tel = escapeHTML(loc.locTel || '-');
                             const statutBadge = loc.userStatut === 'Actif' ? `<span class="badge bg-success">Actif</span>` : `<span class="badge bg-danger">${escapeHTML(loc.userStatut)}</span>`; // Affiche le statut réel si pas Actif

                             const emailLink = loc.locEmail ? `mailto:${email}` : '#';
                             const telLink = loc.locTel ? `tel:${tel.replace(/[^0-9+]/g, '')}` : '#';
                             const contratsLink = `notaire-gestion-contrats.php?searchTerm=${encodeURIComponent(nomComplet)}`; // Lien vers contrats

                             const actions = `
                                 <div class="btn-group btn-group-sm" role="group">
                                     <button onclick="viewUserDetails(${loc.idUser})" class="btn btn-info" title="Voir Détails"><i class="ri-eye-line"></i></button>
                                     <a href="${contratsLink}" class="btn btn-primary" title="Voir Contrats"><i class="ri-file-list-3-line"></i></a>
                                     ${loc.locEmail ? `<a href="${emailLink}" class="btn btn-secondary" title="Email"><i class="ri-mail-send-line"></i></a>` : '<button class="btn btn-secondary btn-sm" disabled><i class="ri-mail-send-line"></i></button>'}
                                     ${loc.locTel ? `<a href="${telLink}" class="btn btn-success" title="Appeler"><i class="ri-phone-line"></i></a>` : '<button class="btn btn-success btn-sm" disabled><i class="ri-phone-line"></i></button>'}
                                 </div>`;

                             row.innerHTML = `
                                 <td>${nomComplet}</td>
                                 <td>${email}</td>
                                 <td>${tel}</td>
                                 <td>${statutBadge}</td>
                                 <td>${actions}</td>`;
                         });
                     } else {
                         tableBody.innerHTML = noResultsRowHTML;
                         tableBody.querySelector('.no-results-row').style.display = 'table-row';
                         totalCountSpan.textContent = 0; currentPaginationInfo = {};
                         if(!data.success) console.warn(`Warn loading locataires: `+(data.message || 'Aucun locataire trouvé'));
                     }
                     updatePagination(paginationControls, currentPaginationInfo, loadLocataires);
                 })
                 .catch(error => {
                     console.error(`Error fetch locataires:`, error);
                     tableBody.innerHTML = `<tr class="error-row"><td colspan="5" class="text-center text-danger py-3">Erreur chargement: ${escapeHTML(error.message)}</td></tr>`;
                     totalCountSpan.textContent = '?'; updatePagination(paginationControls, null, null);
                });
            }

            // --- Fonction Pagination ---
            function updatePagination(controlsElement, paginationInfo, loadFunctionCallback) {
                 controlsElement.innerHTML = '';
                 if (!paginationInfo || !paginationInfo.totalPages || paginationInfo.totalPages <= 1) return;
                 const currentPage = paginationInfo.currentPage; const totalPages = paginationInfo.totalPages;

                 const createPageItem = (page, label = null, isActive = false, isDisabled = false) => {
                     const li = document.createElement('li'); li.className = `page-item ${isActive ? 'active' : ''} ${isDisabled ? 'disabled' : ''}`;
                     const a = document.createElement('a'); a.className = 'page-link'; a.href = '#'; a.innerHTML = label !== null ? label : page;
                     if (!isDisabled && label !== '...') { a.dataset.page = page; a.addEventListener('click', (e) => { e.preventDefault(); if (loadFunctionCallback) loadFunctionCallback(page, currentSearchTerm); }); }
                     else { a.setAttribute('aria-disabled', 'true'); a.style.cursor = 'default'; }
                     li.appendChild(a); return li;
                 };

                 controlsElement.appendChild(createPageItem(currentPage - 1, '«', false, currentPage === 1));
                 const maxPagesToShow = 5; let startPage, endPage; if (totalPages <= maxPagesToShow) { startPage = 1; endPage = totalPages; } else { const maxSide = Math.floor((maxPagesToShow - 3) / 2); if (currentPage <= maxSide + 1) { startPage = 1; endPage = maxPagesToShow - 1; } else if (currentPage >= totalPages - maxSide) { startPage = totalPages - maxPagesToShow + 2; endPage = totalPages; } else { startPage = currentPage - maxSide; endPage = currentPage + maxSide; } }
                 if (startPage > 1) controlsElement.appendChild(createPageItem(1)); if (startPage > 2) controlsElement.appendChild(createPageItem(null, '...', false, true));
                 for (let i = startPage; i <= endPage; i++) { if (i > 0 && i <= totalPages) controlsElement.appendChild(createPageItem(i, null, i === currentPage)); }
                 if (endPage < totalPages - 1) controlsElement.appendChild(createPageItem(null, '...', false, true)); if (endPage < totalPages) controlsElement.appendChild(createPageItem(totalPages));
                 controlsElement.appendChild(createPageItem(currentPage + 1, '»', false, currentPage === totalPages));
            }

            // --- Gestion Recherche ---
             searchForm.addEventListener('submit', function(e) { e.preventDefault(); searchLocataires(); });
             resetSearchBtn.addEventListener('click', function() { searchInput.value = ''; this.style.display = 'none'; searchLocataires(); });
             searchInput.addEventListener('input', function() { resetSearchBtn.style.display = this.value ? 'inline-block' : 'none'; });
             function searchLocataires() { const searchTerm = searchInput.value.trim(); loadLocataires(1, searchTerm); }

            // --- Chargement Initial ---
             loadLocataires();

        });
    </script>
</body>
</html>