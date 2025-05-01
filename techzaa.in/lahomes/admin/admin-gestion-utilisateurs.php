<?php
// --- admin-gestion-utilisateurs.php (Version PDO) ---

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'Administrateur') { header("Location: auth-signin.php"); exit; }

// Utilisation de PDO via db_connection.php
require_once 'db_connection.php'; // Assurez-vous que ce fichier définit $pdo

$pageAlerts = []; $defaultAvatar = 'assets/images/users/avatar-placeholder.png';

// --- Récupération des comptes initiaux pour les badges (Version PDO) ---
$initialCounts = ['Notaire' => 0, 'Propriétaire' => 0, 'Locataire' => 0, 'Administrateur' => 0];
try {
    $sql_counts = "SELECT role, COUNT(*) as count FROM utilisateurs GROUP BY role";
    $stmt_counts = $pdo->query($sql_counts); // Simple query, pas de paramètres user
    while ($row = $stmt_counts->fetch(PDO::FETCH_ASSOC)) {
        if (isset($initialCounts[$row['role']])) {
            $initialCounts[$row['role']] = $row['count'];
        }
    }
} catch (PDOException $e) {
    error_log("Err PDO count init: " . $e->getMessage());
    // Optionnel : Afficher une alerte ou gérer l'erreur
    $pageAlerts[] = ['type' => 'warning', 'message' => 'Erreur chargement des compteurs.'];
}

// --- GESTION DES ACTIONS AJAX (GET - Version PDO) ---
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['action'])) {
     header('Content-Type: application/json');
     $action = $_GET['action'];
     $responseAjax = ['success' => false, 'message' => 'Action inconnue ou erreur serveur.']; // Message par défaut
     $userIdAjax = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
     $roleParam = $_GET['role'] ?? null;

     try { // Encapsuler toute la logique AJAX dans un try/catch PDO
         // --- Action: get_users (PDO) ---
         if ($action === 'get_users' && $roleParam) {
            $page = isset($_GET['page']) ? filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) : 1;
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $itemsPerPage = 15;
            $offset = ($page - 1) * $itemsPerPage;

            $users = []; $totalItems = 0; $totalActiveAdmins = 0;

            // Construire les requêtes SQL (avec placeholders nommés pour PDO)
            $baseSqlSelect = "SELECT u.idUser, u.nom, u.prenom, u.email, u.role, u.statut";
            $baseSqlFrom = " FROM utilisateurs u ";
            $baseSqlWhere = " WHERE u.role = :role ";
            $params = [':role' => $roleParam];

            // Ajouter jointures et champs spécifiques au rôle
            switch($roleParam) {
                case 'Notaire':
                    $baseSqlSelect .= ", n.telephone, cab.nomCabinet ";
                    $baseSqlFrom .= " LEFT JOIN notaire n ON u.idUser=n.idUser LEFT JOIN cabinet cab ON n.idNotaire=cab.idNotaire ";
                    break;
                case 'Propriétaire':
                    $baseSqlSelect .= ", (SELECT COUNT(*) FROM bienimmobiliers b JOIN proprietaire p_sub ON b.idProprietaire=p_sub.idProprietaire WHERE p_sub.idUser = u.idUser) as nbBiens ";
                    // Jointure non nécessaire pour la sélection de base ici
                    break;
                case 'Locataire':
                     $baseSqlSelect .= ", l.telephone, (SELECT bi.adresse FROM contrat c JOIN bienimmobiliers bi ON c.idBien=bi.idBien JOIN locataire l_sub ON c.idLocataire=l_sub.idLocataire WHERE l_sub.idUser=u.idUser AND c.statutContrat='Actif' LIMIT 1) as logementActuel ";
                     $baseSqlFrom .= " LEFT JOIN locataire l ON u.idUser=l.idUser ";
                    break;
                 case 'Administrateur':
                    // Aucune jointure spécifique nécessaire pour la liste de base
                     break;
            }

            // Ajouter le filtre de recherche
            if (!empty($search)) {
                $baseSqlWhere .= " AND (u.nom LIKE :search OR u.prenom LIKE :search OR u.email LIKE :search)";
                $params[':search'] = "%" . $search . "%";
            }

            // Requête COUNT
            $sqlCount = "SELECT COUNT(u.idUser) " . $baseSqlFrom . $baseSqlWhere;
            $stmtCount = $pdo->prepare($sqlCount);
            $stmtCount->execute($params);
            $totalItems = $stmtCount->fetchColumn(); // Récupère la première colonne
            $totalPages = ceil($totalItems / $itemsPerPage);
             // Ajuster la page si elle dépasse après filtrage
            $page = max(1, min($page, $totalPages));
            $offset = ($page - 1) * $itemsPerPage;


            // Compter les admins actifs si rôle = Administrateur
            if ($roleParam === 'Administrateur') {
                 $stmt_count_active = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs WHERE role = 'Administrateur' AND statut = 'Actif'");
                 $stmt_count_active->execute();
                 $totalActiveAdmins = $stmt_count_active->fetchColumn();
            }

            // Requête DATA (avec LIMIT et OFFSET)
            $sqlData = $baseSqlSelect . $baseSqlFrom . $baseSqlWhere . " ORDER BY u.nom ASC, u.prenom ASC LIMIT :limit OFFSET :offset";
            $stmtData = $pdo->prepare($sqlData);
            // Binder les paramètres (rôle, search, limit, offset)
            foreach ($params as $key => $value) { $stmtData->bindValue($key, $value); } // Bind role et search
            $stmtData->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
            $stmtData->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmtData->execute();
            $users = $stmtData->fetchAll(PDO::FETCH_ASSOC); // Récupère toutes les lignes

            $responseAjax['success'] = true;
            $responseAjax['users'] = $users;
            $responseAjax['pagination'] = [
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'totalItems' => $totalItems,
                'totalActiveAdmins' => $totalActiveAdmins // Transmis même si 0 pour les autres rôles
            ];
         }
         // --- ACTION : Basculer Statut (PDO) ---
         elseif ($action === 'toggle_status' && $userIdAjax) {
            // Récupérer statut et rôle actuels
            $stmt_get = $pdo->prepare("SELECT statut, role FROM utilisateurs WHERE idUser = :idUser");
            $stmt_get->execute([':idUser' => $userIdAjax]);
            $user_stat = $stmt_get->fetch(PDO::FETCH_ASSOC);

            if ($user_stat) {
                $currentStatus = $user_stat['statut'];
                $roleUser = $user_stat['role'];
                $canToggle = true;

                // Vérifier si on essaie de suspendre le seul admin actif
                if ($roleUser == 'Administrateur' && $userIdAjax == $_SESSION['user_id'] && $currentStatus == 'Actif') {
                    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs WHERE role = 'Administrateur' AND statut = 'Actif'");
                    $stmt_count->execute();
                    $activeAdmins = $stmt_count->fetchColumn();
                    if ($activeAdmins <= 1) {
                        $canToggle = false;
                        $responseAjax['message'] = "Impossible de suspendre le seul administrateur actif.";
                    }
                }

                if ($canToggle) {
                    $newStatus = ($currentStatus === 'Actif') ? 'Suspendu' : 'Actif';
                    $sql_update = "UPDATE utilisateurs SET statut = :newStatus WHERE idUser = :idUser";
                    $stmt_update = $pdo->prepare($sql_update);
                    $stmt_update->execute([':newStatus' => $newStatus, ':idUser' => $userIdAjax]);

                    if ($stmt_update->rowCount() > 0) { // Vérifier si la mise à jour a eu lieu
                        $responseAjax['success'] = true;
                        $responseAjax['message'] = "Statut mis à jour avec succès.";
                        $responseAjax['newStatus'] = $newStatus;
                    } else {
                        // Soit pas trouvé, soit déjà le bon statut, soit erreur DB non capturée
                        $responseAjax['success'] = false; // Ou true si "Aucun changement" est ok
                        $responseAjax['message'] = "Aucun changement de statut effectué (ou utilisateur introuvable).";
                         $responseAjax['newStatus'] = $currentStatus; // Renvoyer le statut actuel
                    }
                }
                // Le message pour canToggle=false est déjà défini plus haut
            } else {
                $responseAjax['message'] = "Utilisateur non trouvé.";
            }
         }
         // --- ACTION : Récupérer Détails (PDO) ---
         elseif ($action === 'get_user_details' && $userIdAjax && $roleParam) {
             // Construction SQL (identique mais avec placeholders nommés si besoin)
             $sql = "SELECT u.idUser, u.nom, u.prenom, u.email, u.role, u.statut, ";
             switch ($roleParam) {
                 case 'Notaire': $sql .= " n.telephone, n.numeroCNI, n.adresse as adresseNotaire, cab.nomCabinet, cab.adresseCabinet "; break;
                 case 'Propriétaire': $sql .= " p.numeroCNI, p.adresse as adresseProprietaire "; break;
                 case 'Locataire': $sql .= " l.telephone, l.adresse as adresseLocataire "; break;
                 default: $sql .= " NULL as specificInfo "; break; // Pour Admin ou rôle inconnu
             }
             $sql .= " FROM utilisateurs u ";
             switch ($roleParam) {
                 case 'Notaire': $sql .= " LEFT JOIN notaire n ON u.idUser = n.idUser LEFT JOIN cabinet cab ON n.idNotaire = cab.idNotaire "; break;
                 case 'Propriétaire': $sql .= " LEFT JOIN proprietaire p ON u.idUser = p.idUser "; break;
                 case 'Locataire': $sql .= " LEFT JOIN locataire l ON u.idUser = l.idUser "; break;
                 // Pas de jointure nécessaire pour Admin ici
             }
             $sql .= " WHERE u.idUser = :idUser";
             $stmt = $pdo->prepare($sql);
             $stmt->execute([':idUser' => $userIdAjax]);
             $details = $stmt->fetch(PDO::FETCH_ASSOC);

             if ($details) {
                 $responseAjax['success'] = true;
                 $responseAjax['details'] = $details;
             } else {
                 $responseAjax['message'] = "Utilisateur non trouvé.";
             }
         }
         // --- Gestion erreurs génériques AJAX ---
         else {
             if(!$userIdAjax && in_array($action, ['toggle_status', 'get_user_details'])) {
                 $responseAjax['message'] = 'ID Utilisateur manquant.';
             } elseif (!$roleParam && $action === 'get_users') {
                 $responseAjax['message'] = 'Rôle manquant.';
             }
             // Si aucune condition n'est remplie, le message par défaut "Action inconnue..." est conservé.
         }
     } catch (PDOException $e) {
         // Attraper les erreurs PDO pour toutes les actions AJAX
         error_log("Erreur PDO Action AJAX: " . $e->getMessage());
         $responseAjax['message'] = 'Erreur base de données lors de l\'action.'; // Message générique pour l'utilisateur
         // Optionnel: Mettre un code HTTP 500
         // http_response_code(500);
     }

     echo json_encode($responseAjax);
     exit; // Fin script AJAX
}

// --- CHARGEMENT INITIAL (Vide car AJAX) ---
// La connexion PDO est établie au début et sera implicitement fermée à la fin du script.

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <title>Gestion Utilisateurs | Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
    <script src="assets/js/config.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        .table th, .table td { vertical-align: middle; font-size: 0.875rem; }
        .table th { font-weight: 500; }
        .user-table td:last-child { text-align: right; }
        .loading-row td, .no-results-row td { text-align: center; font-style: italic; color: #6c757d; padding: 1rem; }
        .search-filter-row { margin-bottom: 1rem; }
        .modal-body dl dt { font-weight: 500; }
        .modal-body dl dd { margin-bottom: 0.5rem; }
        .btn-sm i { font-size: 1rem; }
        .pagination { margin-bottom: 0; }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Topbar, Right Sidebar, App Menu (HTML INCHANGÉ - collez le vôtre) -->
         <header class=""> <div class="topbar"> <div class="container-fluid"> <div class="navbar-header"> <div class="d-flex align-items-center gap-2"> <div class="topbar-item"><button type="button" class="button-toggle-menu topbar-button"><i class="ri-menu-2-line fs-24"></i></button></div> <form class="app-search d-none d-md-block me-auto"><div class="position-relative"><input type="search" class="form-control border-0" placeholder="Rechercher..." autocomplete="off" value=""><i class="ri-search-line search-widget-icon"></i></div></form> </div> <div class="d-flex align-items-center gap-1"> <div class="topbar-item"><button type="button" class="topbar-button" id="light-dark-mode"><i class="ri-moon-line fs-24 light-mode"></i><i class="ri-sun-line fs-24 dark-mode"></i></button></div> <div class="dropdown topbar-item d-none d-lg-flex"><button type="button" class="topbar-button" data-toggle="fullscreen"><i class="ri-fullscreen-line fs-24 fullscreen"></i><i class="ri-fullscreen-exit-line fs-24 quit-fullscreen"></i></button></div> <div class="dropdown topbar-item"> <button type="button" class="topbar-button position-relative" id="page-header-notifications-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="ri-notification-3-line fs-24"></i><span id="topbarAdminUnreadCount" class="position-absolute topbar-badge fs-10 translate-middle badge bg-danger rounded-pill">0<span class="visually-hidden">unread</span></span></button> <div class="dropdown-menu py-0 dropdown-lg dropdown-menu-end" aria-labelledby="page-header-notifications-dropdown"> <div class="p-3 border-top-0 border-start-0 border-end-0 border-dashed border"><div class="row align-items-center"><div class="col"><h6 class="m-0 fs-16 fw-semibold"> Notifications Admin</h6></div><div class="col-auto"><a href="javascript: void(0);" class="text-dark text-decoration-underline"> <small>Marquer tout comme lu</small></a></div></div></div> <div data-simplebar style="max-height: 280px;" id="topbarAdminNotificationList"></div> <div class="text-center py-3"><a href="admin-notifications.html" class="btn btn-primary btn-sm">Tout voir <i class="ri-arrow-right-line ms-1"></i></a></div> </div> </div> <div class="topbar-item d-none d-md-flex"><button type="button" class="topbar-button" id="theme-settings-btn" data-bs-toggle="offcanvas" data-bs-target="#theme-settings-offcanvas" aria-controls="theme-settings-offcanvas"><i class="ri-settings-4-line fs-24"></i></button></div> <div class="dropdown topbar-item"> <a type="button" class="topbar-button" id="page-header-user-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><span class="d-flex align-items-center"><img class="rounded-circle" width="32" src="assets/images/users/avatar-admin.png" alt="admin"></span></a> <div class="dropdown-menu dropdown-menu-end"> <h6 class="dropdown-header">Admin <?php echo htmlspecialchars($_SESSION['user_prenom'] ?? ''); ?></h6> <a class="dropdown-item" href="admin-profil.html"><i class="ri-user-line align-middle me-1"></i> Mon Profil</a> <a class="dropdown-item text-danger" href="auth-signin.php?logout=1"><i class="ri-logout-box-line align-middle me-1"></i> Déconnexion</a> </div> </div> </div> </div> </div> </div> </header>
         <div> <div class="offcanvas offcanvas-end border-0 rounded-start-4 overflow-hidden" tabindex="-1" id="theme-settings-offcanvas"> <div class="d-flex align-items-center bg-primary p-3 offcanvas-header"><h5 class="text-white m-0">Theme Settings</h5><button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="offcanvas"></button></div><div class="offcanvas-body p-0"><div data-simplebar class="h-100"><div class="p-3 settings-bar"></div></div></div><div class="offcanvas-footer border-top p-3 text-center"><button type="button" class="btn btn-danger w-100" id="reset-layout">Reset</button></div> </div> </div>
          <div class="main-nav">
             <div class="logo-box"> <a href="admin-dashboard.php" class="logo-dark"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-dark.png" class="logo-lg"></a> <a href="admin-dashboard.php" class="logo-light"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-light.png" class="logo-lg"></a> </div>
             <button type="button" class="button-sm-hover" aria-label="Show Full Sidebar"><i class="ri-menu-2-line fs-24 button-sm-hover-icon"></i></button>
             <div class="scrollbar" data-simplebar>
                  <ul class="navbar-nav" id="navbar-nav">
                       <li class="menu-title">Menu Admin</li>
                       <li class="nav-item"><a class="nav-link" href="admin-dashboard.php"><span class="nav-icon"><i class="ri-dashboard-line"></i></span><span class="nav-text">Dashboard</span></a></li>
                       <li class="nav-item"><a class="nav-link active" href="admin-gestion-utilisateurs.php"><span class="nav-icon"><i class="ri-account-circle-line"></i></span><span class="nav-text">Gestion Utilisateurs</span></a></li>
                       <li class="nav-item"><a class="nav-link" href="admin-supervision-biens.php"><span class="nav-icon"><i class="ri-building-line"></i></span><span class="nav-text">Supervision Biens</span></a></li>
                  </ul>
             </div>
        </div>

        <div class="page-content">
            <div class="container-fluid">
                <!-- Titre, Alertes, Recherche, Onglets (HTML inchangé) -->
                 <div class="row"> <div class="col-12"> <div class="page-title-box"> <h4 class="mb-0 fw-semibold">Gestion des Utilisateurs</h4> <ol class="breadcrumb mb-0"> <li class="breadcrumb-item"><a href="admin-dashboard.php">Admin</a></li> <li class="breadcrumb-item active">Utilisateurs</li> </ol> </div> </div> </div>
                 <div id="pageAlertPlaceholder"> <?php foreach ($pageAlerts as $alert): ?> <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show"> <?php echo htmlspecialchars($alert['message']); ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button> </div> <?php endforeach; ?> </div>
                 <div class="row search-filter-row align-items-center"> <div class="col-12"> <form id="globalUserSearchForm"> <div class="input-group input-group-sm"> <input type="search" id="globalUserSearchInput" class="form-control" placeholder="Rechercher par nom ou email dans l'onglet actif..."> <button class="btn btn-light" type="submit" title="Rechercher"><i class="ri-search-line"></i></button> <button class="btn btn-light" type="button" id="resetSearchBtn" title="Effacer la recherche" style="display: none;"><i class="ri-close-line"></i></button> </div> </form> </div> </div>
                 <ul class="nav nav-tabs nav-justified mb-3" id="userManagementTabs" role="tablist">
                    <li class="nav-item" role="presentation"><button class="nav-link active" id="notaires-tab" data-role="Notaire" data-bs-toggle="tab" data-bs-target="#notaires-tab-pane" type="button"><i class="ri-user-star-line me-1"></i> Notaires <span id="notairesCountBadge" class="badge rounded-pill bg-light text-dark ms-1"><?php echo $initialCounts['Notaire']; ?></span></button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" id="proprietaires-tab" data-role="Propriétaire" data-bs-toggle="tab" data-bs-target="#proprietaires-tab-pane" type="button"><i class="ri-user-settings-line me-1"></i> Propriétaires <span id="proprietairesCountBadge" class="badge rounded-pill bg-light text-dark ms-1"><?php echo $initialCounts['Propriétaire']; ?></span></button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" id="locataires-tab" data-role="Locataire" data-bs-toggle="tab" data-bs-target="#locataires-tab-pane" type="button"><i class="ri-user-line me-1"></i> Locataires <span id="locatairesCountBadge" class="badge rounded-pill bg-light text-dark ms-1"><?php echo $initialCounts['Locataire']; ?></span></button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" id="administrateurs-tab" data-role="Administrateur" data-bs-toggle="tab" data-bs-target="#administrateurs-tab-pane" type="button"><i class="ri-admin-line me-1"></i> Admins <span id="adminsCountBadge" class="badge rounded-pill bg-light text-dark ms-1"><?php echo $initialCounts['Administrateur']; ?></span></button></li>
                 </ul>
                 <div class="tab-content" id="userManagementTabsContent">
                      <!-- Onglet Notaires -->
                     <div class="tab-pane fade show active" id="notaires-tab-pane" role="tabpanel">
                         <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover table-centered mb-0 user-table">
                             <thead class="table-light"><tr><th>Nom & Prénom</th><th>Email</th><th>Téléphone</th><th>Cabinet</th><th>Statut</th><th style="width: 100px;">Actions</th></tr></thead>
                             <tbody id="notairesTableBody"><tr class="loading-initial"><td colspan="6" class="text-center py-3"><span class="spinner-border spinner-border-sm"></span> Chargement...</td></tr></tbody>
                         </table></div></div><div class="card-footer bg-white border-top d-flex justify-content-end"><nav><ul id="paginationNotaires" class="pagination pagination-sm mb-0"></ul></nav></div></div>
                     </div>
                     <!-- Onglet Propriétaires -->
                     <div class="tab-pane fade" id="proprietaires-tab-pane" role="tabpanel">
                          <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover table-centered mb-0 user-table">
                              <thead class="table-light"><tr><th>Nom & Prénom</th><th>Email</th><th>Nb Biens</th><th>Statut Compte</th><th style="width: 100px;">Actions</th></tr></thead>
                              <tbody id="proprietairesTableBody"><tr class="loading-initial"><td colspan="5" class="text-center py-3"><span class="spinner-border spinner-border-sm"></span> Chargement...</td></tr></tbody>
                          </table></div></div><div class="card-footer bg-white border-top d-flex justify-content-end"><nav><ul id="paginationProprietaires" class="pagination pagination-sm mb-0"></ul></nav></div></div>
                     </div>
                     <!-- Onglet Locataires -->
                     <div class="tab-pane fade" id="locataires-tab-pane" role="tabpanel">
                          <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover table-centered mb-0 user-table">
                              <thead class="table-light"><tr><th>Nom & Prénom</th><th>Email</th><th>Téléphone</th><th>Logement Actuel</th><th>Statut Compte</th><th style="width: 100px;">Actions</th></tr></thead>
                              <tbody id="locatairesTableBodyAdmin"><tr class="loading-initial"><td colspan="6" class="text-center py-3"><span class="spinner-border spinner-border-sm"></span> Chargement...</td></tr></tbody>
                          </table></div></div><div class="card-footer bg-white border-top d-flex justify-content-end"><nav><ul id="paginationLocatairesAdmin" class="pagination pagination-sm mb-0"></ul></nav></div></div>
                     </div>
                     <!-- Onglet Admins -->
                     <div class="tab-pane fade" id="administrateurs-tab-pane" role="tabpanel">
                           <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover table-centered mb-0 user-table">
                              <thead class="table-light"><tr><th>Nom & Prénom</th><th>Email</th><th>Statut</th><th style="width: 100px;">Actions</th></tr></thead>
                              <tbody id="adminsTableBody"><tr class="loading-initial"><td colspan="4" class="text-center py-3"><span class="spinner-border spinner-border-sm"></span> Chargement...</td></tr></tbody>
                          </table></div></div><div class="card-footer bg-white border-top d-flex justify-content-end"><nav><ul id="paginationAdmins" class="pagination pagination-sm mb-0"></ul></nav></div></div>
                     </div>
                 </div>
            </div>
        </div>
        <!-- Modal Voir Détails (HTML inchangé) -->
        <div class="modal fade" id="modalUserDetails" tabindex="-1" aria-labelledby="modalUserDetailsLabel" aria-hidden="true">
             <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                 <div class="modal-content">
                     <div class="modal-header">
                         <h5 class="modal-title" id="modalUserDetailsLabel">Détails Utilisateur</h5>
                         <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                     </div>
                     <div class="modal-body" id="userDetailsContent">
                         <p class="text-center py-5">Chargement des détails...</p>
                     </div>
                     <div class="modal-footer">
                         <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                     </div>
                 </div>
             </div>
         </div>
        <!-- Footer (HTML inchangé) -->
        <footer class="footer"> <div class="container-fluid"> <div class="row"> <div class="col-12 text-center"> <script>document.write(new Date().getFullYear())</script> © Gestion Locative Notariale. </div> </div> </div> </footer>
    </div>

    <!-- Scripts JS (inchangés car la logique AJAX reste la même) -->
    <script src="assets/js/vendor.js"></script>
    <script src="assets/js/app.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // --- Fonctions Globales ---
        window.viewUserDetails = function(userId, role) {
             const modalEl = document.getElementById('modalUserDetails');
             const modalContent = document.getElementById('userDetailsContent');
             const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
             modalContent.innerHTML = '<p class="text-center py-5">Chargement...</p>';
             modalInstance.show();

             fetch(`<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?action=get_user_details&id=${userId}&role=${role}`)
                 .then(response => response.ok ? response.json() : Promise.reject('Network error'))
                 .then(data => {
                     if (data.success && data.details) {
                         const d = data.details; let detailsHtml = '<dl class="row">';
                         detailsHtml += `<dt class="col-sm-4">ID:</dt><dd class="col-sm-8">${d.idUser}</dd>`;
                         detailsHtml += `<dt class="col-sm-4">Nom Prénom:</dt><dd class="col-sm-8">${d.prenom || ''} ${d.nom || ''}</dd>`;
                         detailsHtml += `<dt class="col-sm-4">Email:</dt><dd class="col-sm-8">${d.email || '-'}</dd>`;
                         detailsHtml += `<dt class="col-sm-4">Rôle:</dt><dd class="col-sm-8">${d.role || '-'}</dd>`;
                         detailsHtml += `<dt class="col-sm-4">Statut Compte:</dt><dd class="col-sm-8"><span class="badge ${d.statut === 'Actif' ? 'bg-success' : 'bg-danger'}">${d.statut}</span></dd>`;
                         // Infos spécifiques
                         if (d.role === 'Notaire') {
                             detailsHtml += `<dt class="col-sm-4">Téléphone:</dt><dd class="col-sm-8">${d.telephone || '-'}</dd>`;
                             detailsHtml += `<dt class="col-sm-4">N° CNI:</dt><dd class="col-sm-8">${d.numeroCNI || '-'}</dd>`;
                             detailsHtml += `<dt class="col-sm-4">Adresse Perso:</dt><dd class="col-sm-8">${d.adresseNotaire || '-'}</dd>`;
                             detailsHtml += `<dt class="col-sm-4">Nom Cabinet:</dt><dd class="col-sm-8">${d.nomCabinet || '-'}</dd>`;
                             detailsHtml += `<dt class="col-sm-4">Adresse Cabinet:</dt><dd class="col-sm-8">${d.adresseCabinet || '-'}</dd>`;
                         } else if (d.role === 'Propriétaire') {
                             detailsHtml += `<dt class="col-sm-4">N° CNI:</dt><dd class="col-sm-8">${d.numeroCNI || '-'}</dd>`;
                             detailsHtml += `<dt class="col-sm-4">Adresse:</dt><dd class="col-sm-8">${d.adresseProprietaire || '-'}</dd>`;
                         } else if (d.role === 'Locataire') {
                             detailsHtml += `<dt class="col-sm-4">Téléphone:</dt><dd class="col-sm-8">${d.telephone || '-'}</dd>`;
                             detailsHtml += `<dt class="col-sm-4">Adresse:</dt><dd class="col-sm-8">${d.adresseLocataire || '-'}</dd>`;
                             // On pourrait ajouter le logement actuel ici si besoin
                         }
                         detailsHtml += '</dl>';
                         modalContent.innerHTML = detailsHtml;
                     } else {
                         modalContent.innerHTML = `<p class="text-danger text-center">Erreur: ${data.message || 'Impossible de charger les détails.'}</p>`;
                     }
                 })
                 .catch(error => {
                     console.error('Error fetching details:', error);
                     modalContent.innerHTML = '<p class="text-danger text-center">Erreur réseau.</p>';
                 });
         };

        window.toggleUserStatus = function(userId, currentStatus) {
             const action = currentStatus === 'Actif' ? 'Suspendre' : 'Activer';
             const userRow = document.getElementById(`userRow-${userId}`);
             const currentRole = userRow?.dataset.role; // Récupère le rôle depuis l'attribut data-role
             const loggedInUserId = <?php echo json_encode($_SESSION['user_id']); ?>; // Récupère l'ID de l'admin connecté

             const swalWithBootstrapButtons = Swal.mixin({ customClass: { confirmButton: (action === 'Suspendre' ? 'btn btn-danger ms-2' : 'btn btn-success ms-2'), cancelButton: 'btn btn-secondary' }, buttonsStyling: false });

             swalWithBootstrapButtons.fire({ title: `Confirmer`, text: `Voulez-vous ${action} cet utilisateur (ID: ${userId}) ?`, icon: 'question', showCancelButton: true, confirmButtonText: `Oui, ${action}`, cancelButtonText: 'Annuler', reverseButtons: true })
             .then((result) => {
                 if (result.isConfirmed) {
                      showLoadingSweetalert();
                     fetch(`<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?action=toggle_status&id=${userId}`)
                        .then(response => response.ok ? response.json() : Promise.reject('Network error'))
                        .then(data => {
                            Swal.close();
                            if (data.success) {
                                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: data.message || 'Statut mis à jour!', showConfirmButton: false, timer: 2500 });
                                if (userRow && data.newStatus) {
                                     updateUserRow(userRow, { statut: data.newStatus });
                                     // Recharger le comptage des admins actifs si c'est un admin qui a été modifié
                                     if(currentRole === 'Administrateur') {
                                        // Recharger seulement la liste des admins pour mettre à jour le compte
                                         loadUsers('Administrateur', currentPage, currentSearchTerm);
                                     }
                                }
                            } else {
                                Swal.fire('Erreur', data.message || 'Échec de la mise à jour.', 'error');
                            }
                        }).catch(error => {
                            Swal.close(); console.error('Error toggle status:', error); Swal.fire('Erreur', 'Erreur réseau.', 'error');
                        });
                 }
             });
        }

        function updateUserRow(row, updatedData) {
             if (!row) return;
             const statusCell = row.querySelector('td:nth-last-child(2)'); // L'avant-dernière cellule
             const actionCell = row.querySelector('td:last-child .d-flex'); // Le conteneur des boutons
             const loggedInUserId = <?php echo json_encode($_SESSION['user_id']); ?>;
             const userId = parseInt(row.id.replace('userRow-', ''));
             const userRole = row.dataset.role;

             if (statusCell && updatedData.statut) {
                 statusCell.innerHTML = updatedData.statut === 'Actif' ? `<span class="badge bg-success">Actif</span>` : `<span class="badge bg-danger">Suspendu</span>`;
             }
             if (actionCell && updatedData.statut) {
                 const toggleBtn = actionCell.querySelector('button[onclick^="toggleUserStatus"]');
                 if (toggleBtn) {
                     const newIconClass = updatedData.statut === 'Actif' ? 'ri-pause-circle-line' : 'ri-play-circle-line';
                     const newBtnClass = updatedData.statut === 'Actif' ? 'btn-warning' : 'btn-success';
                     const newTitle = updatedData.statut === 'Actif' ? 'Suspendre' : 'Activer';
                     toggleBtn.className = `btn btn-sm ${newBtnClass}`; // Remplace toutes les classes btn-*
                     toggleBtn.title = newTitle;
                     toggleBtn.querySelector('i').className = newIconClass;
                     // Mettre à jour l'appel onclick
                     toggleBtn.setAttribute('onclick', `toggleUserStatus(${userId}, '${updatedData.statut}')`);

                     // Gérer la désactivation si c'est le seul admin actif
                     // Note: on ne peut pas recalculer le total ici facilement, on se fie à l'état actuel
                      toggleBtn.disabled = false; toggleBtn.removeAttribute('title'); // Réactiver par défaut
                      if(userRole === 'Administrateur' && userId === loggedInUserId && updatedData.statut === 'Actif') {
                         // Si on vient d'activer le seul admin (nous-même), il faudrait idéalement
                         // revérifier le compte total pour savoir si on peut le resuspendre.
                         // Pour simplifier, on ne le désactive pas ici, la logique serveur l'empêchera.
                      }
                 }
             }
        }

        function showLoadingSweetalert(title = 'Traitement...') { Swal.fire({ title: title, html: 'Patientez...', allowOutsideClick: false, didOpen: () => { Swal.showLoading() } }); }

        // --- Script Principal (Identique sauf appels PDO implicites) ---
        document.addEventListener('DOMContentLoaded', function() {
            // --- Refs DOM ---
            const tabBodies = { notaires: document.getElementById('notairesTableBody'), proprietaires: document.getElementById('proprietairesTableBody'), locataires: document.getElementById('locatairesTableBodyAdmin'), administrateurs: document.getElementById('adminsTableBody') };
            const paginations = { notaires: document.getElementById('paginationNotaires'), proprietaires: document.getElementById('paginationProprietaires'), locataires: document.getElementById('paginationLocatairesAdmin'), administrateurs: document.getElementById('paginationAdmins') };
            const countBadges = { notaires: document.getElementById('notairesCountBadge'), proprietaires: document.getElementById('proprietairesCountBadge'), locataires: document.getElementById('locatairesCountBadge'), administrateurs: document.getElementById('adminsCountBadge') };
            const globalSearchForm = document.getElementById('globalUserSearchForm'); const globalSearchInput = document.getElementById('globalUserSearchInput'); const resetSearchBtn = document.getElementById('resetSearchBtn');
            let currentRole = 'Notaire'; let currentPage = 1; let currentSearchTerm = ''; let currentPaginationInfo = {};

            // --- Fonction Chargement Utilisateurs (Appelle PHP/PDO via AJAX) ---
            function loadUsers(roleForApi = currentRole, page = 1, searchTerm = currentSearchTerm) {
                 currentRole = roleForApi; currentPage = page; currentSearchTerm = searchTerm;
                 const roleKey = roleForApi.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9]/g, '') + 's'; // ex: 'notaires', 'proprietaires', 'locataires', 'administrateurs'
                 const tableBody = tabBodies[roleKey];
                 const paginationControls = paginations[roleKey];
                 const countBadge = countBadges[roleKey];
                 if (!tableBody || !paginationControls || !countBadge) { console.error("Elements JS manquants pour role:", roleForApi, " (clé:", roleKey, ")"); return; }

                 const loadingHTML = `<tr class="loading-row"><td colspan="10" class="text-center py-3"><span class="spinner-border spinner-border-sm me-2"></span>Chargement...</td></tr>`;
                 tableBody.innerHTML = loadingHTML;
                 const noResultsRowHTML = `<tr class="no-results-row" style="display: none;"><td colspan="10" class="text-center py-3">Aucun utilisateur trouvé pour ce rôle.</td></tr>`;

                 const params = new URLSearchParams({ action: 'get_users', role: roleForApi, page, search: searchTerm });
                 const url = `<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?${params.toString()}`;

                 fetch(url)
                 .then(response => response.ok ? response.json() : response.text().then(text => { throw new Error(`Network error: ${response.status} ${response.statusText} - ${text}`) }))
                 .then(data => {
                     tableBody.innerHTML = '';
                     if (data.success && data.users && data.users.length > 0) {
                         countBadge.textContent = data.pagination.totalItems; currentPaginationInfo = data.pagination;
                         data.users.forEach(user => {
                             const row = tableBody.insertRow(); row.id = `userRow-${user.idUser}`; row.dataset.role = user.role; // Stocker le rôle
                             const statutBadge = user.statut === 'Actif' ? `<span class="badge bg-success">Actif</span>` : `<span class="badge bg-danger">Suspendu</span>`;
                             const toggleBtnIcon = user.statut === 'Actif' ? 'ri-pause-circle-line' : 'ri-play-circle-line'; const toggleBtnClass = user.statut === 'Actif' ? 'btn-warning' : 'btn-success'; const toggleBtnTitle = user.statut === 'Actif' ? 'Suspendre' : 'Activer';
                             // Vérifier si on peut suspendre (le seul admin actif ne peut pas l'être)
                              const selfActionDisabled = (user.idUser == <?php echo $_SESSION['user_id']; ?> && user.role === 'Administrateur' && user.statut === 'Actif' && data.pagination.totalActiveAdmins <= 1) ? 'disabled title="Seul admin actif"' : '';
                             const actions = `<div class="d-flex gap-1 justify-content-end"> <button onclick="viewUserDetails(${user.idUser}, '${user.role}')" class="btn btn-sm btn-light" title="Détails"><i class="ri-eye-line"></i></button> <button onclick="toggleUserStatus(${user.idUser}, '${user.statut}')" class="btn btn-sm ${toggleBtnClass}" title="${toggleBtnTitle}" ${selfActionDisabled}><i class="${toggleBtnIcon}"></i></button> </div>`;
                             const userName = `${user.prenom || ''} ${user.nom || ''}`.trim();
                             let cells = '';
                             if (roleForApi === 'Notaire') { cells = `<td>${userName}</td><td>${user.email || '-'}</td><td>${user.telephone || '-'}</td><td>${user.nomCabinet || '-'}</td><td>${statutBadge}</td><td>${actions}</td>`; row.innerHTML = cells; }
                             else if (roleForApi === 'Propriétaire') { cells = `<td>${userName}</td><td>${user.email || '-'}</td><td>${user.nbBiens || 0}</td><td>${statutBadge}</td><td>${actions}</td>`; row.innerHTML = cells; }
                             else if (roleForApi === 'Locataire') { cells = `<td>${userName}</td><td>${user.email || '-'}</td><td>${user.telephone || '-'}</td><td>${user.logementActuel || '-'}</td><td>${statutBadge}</td><td>${actions}</td>`; row.innerHTML = cells; }
                             else if (roleForApi === 'Administrateur') { cells = `<td>${userName}</td><td>${user.email || '-'}</td><td>${statutBadge}</td><td>${actions}</td>`; row.innerHTML = cells; }
                         });
                     } else {
                         tableBody.innerHTML = noResultsRowHTML;
                         tableBody.querySelector('.no-results-row').style.display = 'table-row';
                         countBadge.textContent = 0; currentPaginationInfo = {};
                         if(!data.success) console.warn(`Warn loading ${roleForApi}: `+(data.message || 'Aucun utilisateur trouvé'));
                     }
                     updatePagination(paginationControls, currentPaginationInfo, (newPage) => loadUsers(roleForApi, newPage, searchTerm));
                 })
                 .catch(error => {
                     console.error(`Error fetch ${roleForApi}:`, error);
                     tableBody.innerHTML = `<tr class="error-row"><td colspan="10" class="text-center text-danger py-3">Erreur lors du chargement des données. (${error.message})</td></tr>`; // Colspan large
                     countBadge.textContent = '?'; updatePagination(paginationControls, null, null);
                });
            }

            // --- Fonction Pagination (Adaptée) ---
            function updatePagination(controlsElement, paginationInfo, loadFunctionCallback) {
                 controlsElement.innerHTML = ''; // Vider
                 if (!paginationInfo || !paginationInfo.totalPages || paginationInfo.totalPages <= 1) return;

                 const currentPage = paginationInfo.currentPage; const totalPages = paginationInfo.totalPages;

                 const createPageItem = (page, label = null, isActive = false, isDisabled = false) => {
                     const li = document.createElement('li'); li.className = `page-item ${isActive ? 'active' : ''} ${isDisabled ? 'disabled' : ''}`;
                     const a = document.createElement('a'); a.className = 'page-link'; a.href = '#'; a.innerHTML = label !== null ? label : page;
                     if (!isDisabled && label !== '...') { a.dataset.page = page; a.addEventListener('click', (e) => { e.preventDefault(); if (loadFunctionCallback) loadFunctionCallback(page); }); }
                     else { a.setAttribute('aria-disabled', 'true'); a.style.cursor = 'default'; }
                     li.appendChild(a); return li;
                 };

                 // Prev
                 controlsElement.appendChild(createPageItem(currentPage - 1, '«', false, currentPage === 1));
                 // Pages (logique simplifiée pour test)
                  const maxPagesToShow = 5; let startPage, endPage; if (totalPages <= maxPagesToShow) { startPage = 1; endPage = totalPages; } else { const maxSide = Math.floor((maxPagesToShow - 3) / 2); if (currentPage <= maxSide + 1) { startPage = 1; endPage = maxPagesToShow - 1; } else if (currentPage >= totalPages - maxSide) { startPage = totalPages - maxPagesToShow + 2; endPage = totalPages; } else { startPage = currentPage - maxSide; endPage = currentPage + maxSide; } }
                  if (startPage > 1) controlsElement.appendChild(createPageItem(1)); if (startPage > 2) controlsElement.appendChild(createPageItem(null, '...', false, true));
                  for (let i = startPage; i <= endPage; i++) { if (i > 0 && i <= totalPages) controlsElement.appendChild(createPageItem(i, null, i === currentPage)); }
                  if (endPage < totalPages - 1) controlsElement.appendChild(createPageItem(null, '...', false, true)); if (endPage < totalPages) controlsElement.appendChild(createPageItem(totalPages));
                 // Next
                 controlsElement.appendChild(createPageItem(currentPage + 1, '»', false, currentPage === totalPages));
            }

            // --- Gestion Onglets (inchangée) ---
             const userTabs = document.querySelectorAll('#userManagementTabs button[data-bs-toggle="tab"]');
             userTabs.forEach(tabEl => {
                 tabEl.addEventListener('shown.bs.tab', event => {
                     const roleForApi = event.target.dataset.role;
                     globalSearchInput.value = ''; resetSearchBtn.style.display = 'none'; currentSearchTerm = '';
                     loadUsers(roleForApi, 1); // Charger via AJAX, page 1 sans recherche
                 });
             });

            // --- Gestion Recherche (inchangée) ---
             globalSearchForm.addEventListener('submit', function(e) { e.preventDefault(); searchUsers(); });
             resetSearchBtn.addEventListener('click', function() { globalSearchInput.value = ''; this.style.display = 'none'; searchUsers(); });
             globalSearchInput.addEventListener('input', function() { resetSearchBtn.style.display = this.value ? 'inline-block' : 'none'; });
             function searchUsers() { const searchTerm = globalSearchInput.value.trim(); const activeTab = document.querySelector('#userManagementTabs .nav-link.active'); if(activeTab) { const roleForApi = activeTab.dataset.role; loadUsers(roleForApi, 1, searchTerm); } }

            // --- Chargement Initial ---
             loadUsers('Notaire'); // Charger le premier onglet via AJAX

        });
    </script>
</body>
</html>