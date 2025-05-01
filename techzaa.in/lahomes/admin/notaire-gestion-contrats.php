<?php
// --- notaire-gestion-contrats.php (Focus sur chargement options modal) ---
session_start();

require_once 'db_connection.php'; // $pdo

// --- Fonction pour récupérer l'ID Notaire (GARDÉE) ---
function get_logged_in_notaire_id(PDO $pdo, ?int $userId): ?int {
    if ($userId === null) return null;
    try {
        $stmt = $pdo->prepare("SELECT idNotaire FROM notaire WHERE idUser = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetchColumn();
        return $result ? (int)$result : null;
    } catch (PDOException $e) { error_log("Erreur get_logged_in_notaire_id: " . $e->getMessage()); return null; }
}

// --- Récupération ID Notaire Connecté ---
$logged_in_user_id = $_SESSION['user_id'] ?? null;
$current_notaire_id = get_logged_in_notaire_id($pdo, $logged_in_user_id);

// --- Gestion des Actions AJAX ---
if (isset($_REQUEST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Action non reconnue ou erreur interne.'];

    // Vérif Notaire identifié
    if ($current_notaire_id === null && in_array($_REQUEST['action'], ['get_contrats', 'create_contrat', 'get_options', 'delete_contrat'])) {
        $response['message'] = "Accès non autorisé ou session expirée.";
        http_response_code(403);
        echo json_encode($response);
        exit;
    }

    try {
        switch ($_REQUEST['action']) {

            case 'get_contrats':
                // ... (Code pour récupérer les contrats - inchangé) ...
                 $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
                $perPage = 10;
                $offset = ($page - 1) * $perPage;

                $sqlBase = "
                    FROM contrat c
                    JOIN bienimmobiliers b ON c.idBien = b.idBien
                    JOIN locataire l ON c.idLocataire = l.idLocataire
                    JOIN utilisateurs u_loc ON l.idUser = u_loc.idUser
                    LEFT JOIN proprietaire p ON c.idProprietaire = p.idProprietaire
                    LEFT JOIN utilisateurs u_prop ON p.idUser = u_prop.idUser
                    WHERE c.idNotaire = :idNotaire
                ";
                $params = [':idNotaire' => $current_notaire_id];
                $whereClauses = [];

                // Filtres
                if (!empty($_GET['searchTerm'])) {
                    $searchTerm = '%' . trim($_GET['searchTerm']) . '%';
                    $whereClauses[] = "(CAST(c.idContrat AS CHAR) LIKE :searchTerm OR b.adresse LIKE :searchTerm OR u_loc.nom LIKE :searchTerm OR u_loc.prenom LIKE :searchTerm OR u_prop.nom LIKE :searchTerm OR u_prop.prenom LIKE :searchTerm)";
                    $params[':searchTerm'] = $searchTerm;
                }
                if (!empty($_GET['statutContrat'])) {
                    $whereClauses[] = "c.statutContrat = :statutContrat";
                    $params[':statutContrat'] = trim($_GET['statutContrat']);
                }

                if (!empty($whereClauses)) {
                    $sqlBase .= " AND " . implode(' AND ', $whereClauses);
                }

                // Compter
                $sqlCount = "SELECT COUNT(c.idContrat) " . $sqlBase;
                $stmtCount = $pdo->prepare($sqlCount);
                $stmtCount->execute($params);
                $totalItems = $stmtCount->fetchColumn();
                $totalPages = ceil($totalItems / $perPage);
                $page = min($page, max(1, $totalPages));
                $offset = ($page - 1) * $perPage;

                // Récupérer les données
                $sqlData = "
                    SELECT
                        c.idContrat, c.idContrat AS ref, c.dateDebut, c.dateFin,
                        c.statutContrat,
                        c.signatureLocataireDate, c.signatureProprioDate, c.signatureNotaireDate,
                        b.adresse AS bienAdresse,
                        CONCAT(u_loc.prenom, ' ', u_loc.nom) AS locataireNom,
                        CONCAT(u_prop.prenom, ' ', u_prop.nom) AS proprietaireNom
                    " . $sqlBase . "
                    ORDER BY c.dateCreation DESC
                    LIMIT :limit OFFSET :offset
                ";
                $stmtData = $pdo->prepare($sqlData);
                $stmtData->bindValue(':limit', $perPage, PDO::PARAM_INT);
                $stmtData->bindValue(':offset', $offset, PDO::PARAM_INT);
                foreach ($params as $key => $value) { $stmtData->bindValue($key, $value); }
                $stmtData->execute();
                $contratsData = $stmtData->fetchAll(PDO::FETCH_ASSOC);

                $contratsFormatted = array_map(function($row) {
                    $signatures_info = [];
                    if ($row['signatureProprioDate']) $signatures_info[] = 'P';
                    if ($row['signatureLocataireDate']) $signatures_info[] = 'L';
                    if ($row['signatureNotaireDate']) $signatures_info[] = 'N';
                    $row['signatures_presentes'] = !empty($signatures_info) ? implode('+', $signatures_info) : 'Aucune';
                    unset($row['signatureLocataireDate'], $row['signatureProprioDate'], $row['signatureNotaireDate']);
                    return $row;
                }, $contratsData);


                $response = [
                    'success' => true,
                    'contrats' => $contratsFormatted,
                    'pagination' => [
                        'currentPage' => $page, 'totalPages' => $totalPages,
                        'totalItems' => $totalItems, 'perPage' => $perPage
                    ]
                ];
                break;

            // --- Récupérer les options pour le formulaire ---
            case 'get_options':
                // *** VÉRIFIEZ CES REQUÊTES ET NOMS DE COLONNES ***
                $stmtBiens = $pdo->prepare("
                    SELECT idBien, adresse
                    FROM bienimmobiliers
                    WHERE statut = 'Libre' AND supervisionStatut = 'Validé'
                    ORDER BY adresse ASC
                ");
                $stmtBiens->execute();
                // Vérifie si la requête a retourné des lignes avant fetchAll
                $biens = ($stmtBiens->rowCount() > 0) ? $stmtBiens->fetchAll(PDO::FETCH_ASSOC) : [];

                $stmtLocataires = $pdo->prepare("
                    SELECT l.idLocataire, CONCAT(u.prenom, ' ', u.nom, ' (', u.email, ')') AS locataireDisplay
                    FROM locataire l
                    JOIN utilisateurs u ON l.idUser = u.idUser
                    WHERE u.statut = 'Actif' /* Assurez-vous que cette condition est correcte */
                    ORDER BY u.nom ASC, u.prenom ASC
                ");
                $stmtLocataires->execute();
                 // Vérifie si la requête a retourné des lignes avant fetchAll
                $locataires = ($stmtLocataires->rowCount() > 0) ? $stmtLocataires->fetchAll(PDO::FETCH_ASSOC) : [];

                $response = [
                    'success' => true,
                    'biens' => $biens,
                    'locataires' => $locataires
                ];
                // error_log("Get Options Result: " . json_encode($response)); // Debug: voir ce qui est envoyé
                break;

            // --- Créer un nouveau contrat ---
            case 'create_contrat':
                // ... (Code pour créer contrat - inchangé) ...
                $idBien = filter_input(INPUT_POST, 'idBien', FILTER_VALIDATE_INT);
                $idLocataire = filter_input(INPUT_POST, 'idLocataire', FILTER_VALIDATE_INT);
                $dateDebut = $_POST['dateDebut'] ?? null;
                $dateFin = $_POST['dateFin'] ?? null;
                $montantLoyer = filter_input(INPUT_POST, 'montantLoyer', FILTER_VALIDATE_FLOAT);

                 if (!$idBien || !$idLocataire || !$dateDebut || !$dateFin || $montantLoyer === false || $montantLoyer < 0 || ($dateFin <= $dateDebut)) {
                     $response['message'] = "Données du formulaire invalides ou manquantes."; break;
                 }

                 $stmtProp = $pdo->prepare("SELECT idProprietaire FROM bienimmobiliers WHERE idBien = ? AND statut = 'Libre' AND supervisionStatut = 'Validé'");
                 $stmtProp->execute([$idBien]);
                 $idProprietaire = $stmtProp->fetchColumn();
                 if (!$idProprietaire) { $response['message'] = "Bien non trouvé ou propriétaire non associé."; break; }

                $pdo->beginTransaction();
                try {
                    $sqlInsert = "INSERT INTO contrat (dateDebut, dateFin, montantLoyer, statutContrat, idBien, idLocataire, idProprietaire, idNotaire, dateCreation)
                                  VALUES (:dateDebut, :dateFin, :montantLoyer, 'Nouveau', :idBien, :idLocataire, :idProprietaire, :idNotaire, NOW())";
                    $stmtInsert = $pdo->prepare($sqlInsert);
                    $stmtInsert->execute([
                        ':dateDebut' => $dateDebut, ':dateFin' => $dateFin, ':montantLoyer' => $montantLoyer,
                        ':idBien' => $idBien, ':idLocataire' => $idLocataire, ':idProprietaire' => $idProprietaire,
                        ':idNotaire' => $current_notaire_id
                    ]);
                    $newContratId = $pdo->lastInsertId();

                    $stmtUpdateBien = $pdo->prepare("UPDATE bienimmobiliers SET statut = 'Occupé' WHERE idBien = ?");
                    $stmtUpdateBien->execute([$idBien]);

                    $pdo->commit();

                     // Récupérer les données formatées du nouveau contrat
                     $stmtNew = $pdo->prepare("
                        SELECT
                            c.idContrat, c.idContrat AS ref, c.dateDebut, c.dateFin, c.statutContrat,
                            b.adresse AS bienAdresse,
                            CONCAT(u_loc.prenom, ' ', u_loc.nom) AS locataireNom,
                            CONCAT(u_prop.prenom, ' ', u_prop.nom) AS proprietaireNom,
                            'N/A' AS signatures_presentes
                        FROM contrat c
                        JOIN bienimmobiliers b ON c.idBien = b.idBien
                        JOIN locataire l ON c.idLocataire = l.idLocataire
                        JOIN utilisateurs u_loc ON l.idUser = u_loc.idUser
                        LEFT JOIN proprietaire p ON c.idProprietaire = p.idProprietaire
                        LEFT JOIN utilisateurs u_prop ON p.idUser = u_prop.idUser
                        WHERE c.idContrat = ?
                    ");
                    $stmtNew->execute([$newContratId]);
                    $newContratData = $stmtNew->fetch(PDO::FETCH_ASSOC);

                    $response = [
                        'success' => true,
                        'message' => "Contrat #" . $newContratId . " créé avec succès !",
                        'newContrat' => $newContratData
                    ];

                } catch (PDOException $e) {
                    $pdo->rollBack();
                    error_log("Erreur création contrat: " . $e->getMessage());
                    $response['message'] = "Erreur lors de la création du contrat.";
                }
                break;

            // --- Supprimer un contrat ---
            case 'delete_contrat':
                // ... (Code pour supprimer contrat - inchangé) ...
                 $contratId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                 if (!$contratId) { $response['message'] = "ID Contrat invalide."; break; }

                 $stmtGetBien = $pdo->prepare("SELECT idBien FROM contrat WHERE idContrat = ? AND idNotaire = ?");
                 $stmtGetBien->execute([$contratId, $current_notaire_id]);
                 $idBienASupprimer = $stmtGetBien->fetchColumn();

                 $pdo->beginTransaction();
                 try {
                     $stmtDelete = $pdo->prepare("DELETE FROM contrat WHERE idContrat = ? AND idNotaire = ?");
                     $stmtDelete->execute([$contratId, $current_notaire_id]);
                     $deletedRows = $stmtDelete->rowCount();

                     if ($deletedRows > 0) {
                         if ($idBienASupprimer) {
                             $stmtCheckAutresContrats = $pdo->prepare("SELECT COUNT(*) FROM contrat WHERE idBien = ?");
                             $stmtCheckAutresContrats->execute([$idBienASupprimer]);
                             $autresContratsCount = $stmtCheckAutresContrats->fetchColumn();

                             if ($autresContratsCount == 0) {
                                 $stmtUpdateBien = $pdo->prepare("UPDATE bienimmobiliers SET statut = 'Libre' WHERE idBien = ?");
                                 $stmtUpdateBien->execute([$idBienASupprimer]);
                                 error_log("Bien ID {$idBienASupprimer} repassé à 'Libre' après suppression du DERNIER contrat {$contratId}.");
                             } else {
                                  error_log("Bien ID {$idBienASupprimer} non libéré car {$autresContratsCount} autre(s) contrat(s) existent.");
                             }
                         }
                         $pdo->commit();
                         $response = ['success' => true, 'message' => 'Contrat supprimé avec succès.'];
                     } else {
                         $pdo->rollBack();
                         $response['message'] = "Contrat non trouvé ou accès non autorisé.";
                     }
                 } catch (PDOException $e) {
                     $pdo->rollBack();
                     error_log("Erreur suppression contrat: " . $e->getMessage());
                     $response['message'] = "Erreur lors de la suppression du contrat.";
                 }
                 break;
        }
    } catch (PDOException $e) {
        error_log("Erreur PDO générale AJAX: " . $e->getMessage());
        $response['message'] = 'Erreur base de données: ' . $e->getMessage();
        http_response_code(500);
    } catch (Exception $e) {
        error_log("Erreur PHP générale AJAX: " . $e->getMessage());
        $response['message'] = 'Erreur serveur interne.';
        http_response_code(500);
    }

    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <title>Gestion des Contrats | Espace Notaire</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Création et gestion des contrats de location par le notaire." />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/flatpickr/flatpickr.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
    <script src="assets/js/config.min.js"></script>
    <style>
        .table th, .table td { vertical-align: middle; font-size: 0.875rem; }
        .table th { font-weight: 500; }
        .btn-sm i { font-size: 1rem; }
        .form-select-sm, .form-control-sm { height: calc(1.5em + .5rem + 2px); padding: .25rem .5rem; font-size: .875rem; }
        .loading-row td, .no-results-row td { text-align: center; font-style: italic; color: #6c757d; padding: 1rem; }
        .placeholder-glow span { min-height: 1em; display: inline-block; background-color: currentColor; opacity: 0.2;}
        td .placeholder { width: 80%; }
        .btn.loading { position: relative; pointer-events: none; color: transparent !important; }
        .btn.loading::after { content: ''; position: absolute; top: 50%; left: 50%; width: 1rem; height: 1rem; margin-top: -0.5rem; margin-left: -0.5rem; border: 2px solid rgba(255, 255, 255, 0.6); border-top-color: #ffffff; border-radius: 50%; animation: button-spinner .6s linear infinite; }
        @keyframes button-spinner { to { transform: rotate(360deg); } }
        .form-control.is-invalid, .form-select.is-invalid { border-color: #dc3545; }
        .invalid-feedback { display: none; width: 100%; margin-top: .25rem; font-size: .875em; color: #dc3545; }
        .was-validated :invalid ~ .invalid-feedback { display: block; }
        .was-validated .form-select:invalid { border-color: #dc3545; }
        .was-validated .flatpickr-input.is-invalid { border-color: #dc3545 !important; }
        #tableContratsNotaire th:last-child, #tableContratsNotaire td:last-child { width: 150px; text-align: center; }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- ========== Topbar, Sidebar (Collez votre HTML ici) ========== -->
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
                      <li class="nav-item"><a class="nav-link" href="notaire-gestion-locataires.php"><span class="nav-icon"><i class="ri-group-line"></i></span><span class="nav-text">Gestion Locataires</span></a></li>
                      <li class="nav-item"><a class="nav-link active" href="notaire-gestion-contrats.php"><span class="nav-icon"><i class="ri-file-list-3-line"></i></span><span class="nav-text">Gestion Contrats</span></a></li>
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
                 <div class="row"> <div class="col-12"> <div class="page-title-box"> <h4 class="mb-0 fw-semibold">Gestion des Contrats</h4> <ol class="breadcrumb mb-0"> <li class="breadcrumb-item"><a href="notaire-dashboard.php">Espace Notaire</a></li> <li class="breadcrumb-item active">Gestion Contrats</li> </ol> </div> </div> </div>

                <!-- Filtres et Ajout -->
                 <div class="row mb-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body pb-2">
                                <div class="row justify-content-between align-items-center">
                                    <div class="col-lg-8">
                                         <form id="filterContratsForm" class="row gy-2 gx-3 align-items-center">
                                             <div class="col-md-5"> <input type="search" class="form-control form-control-sm" id="searchContratTerm" name="searchTerm" placeholder="Ref, Locataire, Bien..."> </div>
                                             <div class="col-md-4">
                                                  <select class="form-select form-select-sm" id="filterContratStatut" name="statutContrat">
                                                       <option value="">Tous Statuts Contrat</option>
                                                       <option value="Nouveau">Nouveau</option>
                                                       <option value="Actif">Actif</option>
                                                       <option value="RÃ©siliÃ©">RÃ©siliÃ©</option>
                                                       <option value="ExpirÃ©">ExpirÃ©</option>
                                                  </select>
                                             </div>
                                             <div class="col-md-auto">
                                                  <button type="submit" class="btn btn-primary btn-sm" title="Filtrer"><i class="ri-filter-3-line"></i></button>
                                                  <button type="reset" class="btn btn-secondary btn-sm ms-1" id="resetContratFiltersBtn" title="RÃ©initialiser"><i class="ri-refresh-line"></i></button>
                                             </div>
                                         </form>
                                    </div>
                                    <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                                         <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalNouveauContrat">
                                             <i class="ri-add-line"></i> Nouveau Contrat
                                         </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                 </div>

                <!-- Tableau des Contrats -->
                 <div class="row">
                     <div class="col-12">
                          <div class="card">
                               <div class="card-header"><h5 class="card-title mb-0">Liste des Contrats de Location</h5></div>
                               <div class="card-body p-0">
                                    <div class="table-responsive">
                                         <table id="tableContratsNotaire" class="table table-hover table-centered mb-0">
                                              <thead class="table-light">
                                                   <tr>
                                                        <th># Réf</th>
                                                        <th>Bien Immobilier</th>
                                                        <th>Locataire</th>
                                                        <th>Propriétaire</th>
                                                        <th>Période</th>
                                                        <th>Statut</th>
                                                        <!-- Colonne Signature retirée -->
                                                        <th style="width: 150px;">Actions</th>
                                                   </tr>
                                              </thead>
                                              <tbody id="contratsTableBody">
                                                   <tr class="loading-row placeholder-glow"><td colspan="7"><span class="placeholder col-12"></span></td></tr>
                                                   <tr class="loading-row placeholder-glow"><td colspan="7"><span class="placeholder col-12"></span></td></tr>
                                                   <tr class="no-results-row d-none"><td colspan="7">Aucun contrat trouvé.</td></tr>
                                              </tbody>
                                         </table>
                                    </div>
                               </div>
                                <div class="card-footer bg-white border-top d-flex justify-content-end pt-2 pb-0">
                                     <nav aria-label="Pagination Contrats"><ul id="paginationControlsContrat" class="pagination pagination-sm mb-2"></ul></nav>
                                </div>
                          </div>
                     </div>
                 </div>

            </div> <!-- container-fluid -->
        </div> <!-- page-content -->

         <!-- Modal Nouveau Contrat -->
         <div class="modal fade" id="modalNouveauContrat" tabindex="-1" aria-labelledby="modalNouveauContratLabel" aria-hidden="true">
             <div class="modal-dialog modal-lg modal-dialog-scrollable">
                 <div class="modal-content">
                     <div class="modal-header">
                         <h5 class="modal-title" id="modalNouveauContratLabel">Créer un Nouveau Contrat</h5>
                         <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                     </div>
                     <div class="modal-body">
                         <div id="formNouveauContratError" class="alert alert-danger d-none" role="alert"></div>
                         <form id="formNouveauContrat" novalidate>
                             <div class="mb-3">
                                 <label for="selectBienContrat" class="form-label">Bien Immobilier <span class="text-danger">*</span></label>
                                 <select class="form-select" id="selectBienContrat" name="idBien" required>
                                     <option value="" selected disabled>-- Chargement... --</option>
                                 </select>
                                 <div class="invalid-feedback">Veuillez sélectionner un bien.</div>
                             </div>
                             <div class="mb-3">
                                 <label for="selectLocataireContrat" class="form-label">Locataire <span class="text-danger">*</span></label>
                                 <select class="form-select" id="selectLocataireContrat" name="idLocataire" required>
                                     <option value="" selected disabled>-- Chargement... --</option>
                                 </select>
                                 <div class="invalid-feedback">Veuillez sélectionner un locataire.</div>
                             </div>
                             <div class="row">
                                 <div class="col-md-6 mb-3">
                                     <label for="dateDebutContrat" class="form-label">Date Début <span class="text-danger">*</span></label>
                                     <input type="text" class="form-control flatpickr-input" id="dateDebutContrat" name="dateDebut" required placeholder="YYYY-MM-DD">
                                     <div class="invalid-feedback">Date début invalide.</div>
                                 </div>
                                 <div class="col-md-6 mb-3">
                                     <label for="dateFinContrat" class="form-label">Date Fin <span class="text-danger">*</span></label>
                                     <input type="text" class="form-control flatpickr-input" id="dateFinContrat" name="dateFin" required placeholder="YYYY-MM-DD">
                                     <div class="invalid-feedback">Date fin invalide (> début).</div>
                                 </div>
                             </div>
                             <div class="mb-3">
                                 <label for="montantLoyerContrat" class="form-label">Loyer Mensuel (FCFA) <span class="text-danger">*</span></label>
                                 <input type="number" class="form-control" id="montantLoyerContrat" name="montantLoyer" required placeholder="Ex: 150000" min="0" step="any">
                                 <div class="invalid-feedback">Montant invalide (>= 0).</div>
                             </div>
                         </form>
                     </div>
                     <div class="modal-footer">
                         <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                         <button type="submit" form="formNouveauContrat" class="btn btn-primary" id="submitNouveauContratBtn">Enregistrer Contrat</button>
                     </div>
                 </div>
             </div>
         </div>

        <!-- Footer -->
         <footer class="footer"> <div class="container-fluid"> <div class="row"> <div class="col-12 text-center"> <script>document.write(new Date().getFullYear())</script> © Gestion Locative Notariale. </div> </div> </div> </footer>

    </div> <!-- wrapper -->

    <!-- JS Vendor, Libs et App -->
    <script src="assets/js/vendor.js"></script>
    <script src="assets/libs/flatpickr/flatpickr.min.js"></script>
    <script src="assets/libs/flatpickr/l10n/fr.js"></script>
    <script src="assets/js/app.js"></script>

    <!-- ============================================================== -->
    <!-- SCRIPT PERSONNALISÉ (Adapté à la nouvelle logique) -->
    <!-- ============================================================== -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {

             // --- Références DOM ---
             const tableBody = document.getElementById('contratsTableBody');
             const loadingRowHTML = tableBody.querySelector('.loading-row')?.outerHTML || '<tr class="loading-row placeholder-glow"><td colspan="7"><span class="placeholder col-12"></span></td></tr>';
             const noResultsRow = tableBody.querySelector('.no-results-row');
             const paginationControls = document.getElementById('paginationControlsContrat');
             const filterForm = document.getElementById('filterContratsForm');
             const resetFiltersBtn = document.getElementById('resetContratFiltersBtn');
             const modalNouveauContratEl = document.getElementById('modalNouveauContrat');
             const modalNouveauContrat = bootstrap.Modal.getOrCreateInstance(modalNouveauContratEl);
             const formNouveauContrat = document.getElementById('formNouveauContrat');
             const submitNouveauContratBtn = document.getElementById('submitNouveauContratBtn');
             const formNouveauContratError = document.getElementById('formNouveauContratError');
             const selectBien = document.getElementById('selectBienContrat');
             const selectLocataire = document.getElementById('selectLocataireContrat');
             const dateDebutInput = document.getElementById('dateDebutContrat');
             const dateFinInput = document.getElementById('dateFinContrat');

             // --- Variables globales ---
             let currentFilters = {};
             let currentPage = 1;

             // Initialiser Flatpickr
             const fpDebut = flatpickr(dateDebutInput, { dateFormat: "Y-m-d", locale: "fr", allowInput: false, minDate: "today" });
             const fpFin = flatpickr(dateFinInput, { dateFormat: "Y-m-d", locale: "fr", allowInput: false });
             fpDebut.config.onChange.push(function(selectedDates) { fpFin.set('minDate', selectedDates[0] ? selectedDates[0] : null); });

             // --- Fonctions Utilitaires ---
             const displayError = (el, msg) => { if (el) { el.textContent = msg; el.classList.remove('d-none'); } console.error("Display Error:", msg); };
             const clearError = (el) => { if (el) { el.textContent = ''; el.classList.add('d-none'); }};
             const setButtonLoading = (btn, isLoading, originalText = '') => { if (!btn) return; if (isLoading) { btn.disabled = true; if (!btn.dataset.originalText) { btn.dataset.originalText = btn.innerHTML; } btn.innerHTML = ''; btn.classList.add('loading'); } else { btn.disabled = false; btn.innerHTML = btn.dataset.originalText || originalText; btn.classList.remove('loading'); }};

            // --- Création d'une ligne de tableau (Simplifiée) ---
            function createContratRow(c) {
                const row = document.createElement('tr');
                row.setAttribute('data-contrat-id', c.idContrat);
                const periode = (c.dateDebut && c.dateFin) ? `${c.dateDebut} au ${c.dateFin}` : 'N/A';

                let statutBadgeClass = 'bg-secondary'; let statutText = c.statutContrat || '?';
                switch(c.statutContrat) { case 'Actif': statutBadgeClass = 'bg-success'; break; case 'Résil': statutBadgeClass = 'bg-danger'; break; case 'Expiré': statutBadgeClass = 'bg-dark'; break; case 'Nouveau': statutBadgeClass = 'bg-info text-dark'; statutText = 'Nouveau'; break; }
                const statutBadge = `<span class="badge ${statutBadgeClass}">${statutText}</span>`;

                // Lien pour générer le PDF
                const generatePdfUrl = `generer_contrat_pdf.php?id=${c.idContrat}`; // *** ASSUREZ-VOUS QUE generer_contrat_pdf.php EXISTE ET FONCTIONNE ***

                row.innerHTML = `
                    <td>#${c.ref || c.idContrat}</td>
                    <td><small>${c.bienAdresse || '?'}</small></td>
                    <td>${c.locataireNom || '?'}</td>
                    <td>${c.proprietaireNom || '?'}</td>
                    <td><small>${periode}</small></td>
                    <td>${statutBadge}</td>
                    <td>
                        <div class="d-flex gap-1 justify-content-center flex-wrap">
                             <a href="${generatePdfUrl}" target="_blank" class="btn btn-sm btn-success" title="Générer le Contrat PDF"><i class="ri-file-pdf-2-line"></i> PDF</a>
                             <button onclick="deleteContrat(${c.idContrat})" class="btn btn-sm btn-danger" title="Supprimer"><i class="ri-delete-bin-line"></i></button>
                        </div>
                    </td>`;
                return row;
            }

             // --- Chargement des Contrats ---
             async function loadContrats(page = 1) {
                 currentPage = page;
                 tableBody.innerHTML = loadingRowHTML + loadingRowHTML;
                 noResultsRow.classList.add('d-none');
                 paginationControls.innerHTML = '';

                 const params = new URLSearchParams(currentFilters);
                 params.append('action', 'get_contrats');
                 params.append('page', currentPage);
                 const formData = new FormData(filterForm);
                 formData.forEach((value, key) => { if(value) params.append(key, value); });

                 try {
                    const response = await fetch(`?${params.toString()}`);
                    const data = await response.json();

                    if (!response.ok) throw new Error(data.message || `Erreur HTTP ${response.status}`);
                    if (!data.success) throw new Error(data.message || "Erreur chargement contrats.");

                    tableBody.innerHTML = '';
                    if (data.contrats && data.contrats.length > 0) {
                        data.contrats.forEach(contrat => tableBody.appendChild(createContratRow(contrat)));
                    } else {
                        tableBody.appendChild(noResultsRow);
                        noResultsRow.classList.remove('d-none');
                    }
                    updateContratPagination(data.pagination, currentPage);

                 } catch (error) {
                    tableBody.innerHTML = ''; tableBody.appendChild(noResultsRow);
                    noResultsRow.classList.remove('d-none');
                    noResultsRow.querySelector('td').textContent = `Erreur chargement: ${error.message}`;
                    console.error("Erreur loadContrats:", error);
                 }
            }

             // --- Chargement des Options du Modal ---
             async function loadSelectOptions() {
                selectBien.disabled = true; selectLocataire.disabled = true;
                selectBien.innerHTML = '<option value="" selected disabled>-- Chargement Biens... --</option>';
                selectLocataire.innerHTML = '<option value="" selected disabled>-- Chargement Locataires... --</option>';
                clearError(formNouveauContratError);
                console.log("Chargement des options..."); // Debug
                try {
                    const response = await fetch('?action=get_options');
                    console.log("Réponse fetch options reçue:", response.status); // Debug
                    const data = await response.json();
                    console.log("Données JSON options:", data); // Debug

                    if (!response.ok || !data.success) throw new Error(data.message || "Erreur chargement options.");

                    // Remplissage Select Bien
                    selectBien.innerHTML = '<option value="" selected disabled>-- Sélectionner un bien --</option>';
                    if (data.biens && data.biens.length > 0) {
                       console.log(`Trouvé ${data.biens.length} biens.`); // Debug
                       data.biens.forEach(b => selectBien.add(new Option(`${b.idBien} - ${b.adresse}`, b.idBien))); // Afficher ID + Adresse
                       selectBien.disabled = false;
                    } else {
                       console.log("Aucun bien trouvé."); // Debug
                       selectBien.innerHTML = '<option value="" disabled>Aucun bien libre/validé</option>';
                    }

                    // Remplissage Select Locataire
                    selectLocataire.innerHTML = '<option value="" selected disabled>-- Sélectionner un locataire --</option>';
                     if (data.locataires && data.locataires.length > 0) {
                        console.log(`Trouvé ${data.locataires.length} locataires.`); // Debug
                        data.locataires.forEach(l => selectLocataire.add(new Option(l.locataireDisplay, l.idLocataire)));
                        selectLocataire.disabled = false;
                    } else {
                        console.log("Aucun locataire trouvé."); // Debug
                        selectLocataire.innerHTML = '<option value="" disabled>Aucun locataire trouvé</option>';
                    }

                } catch (error) {
                    console.error("Erreur dans loadSelectOptions:", error); // Debug
                    displayError(formNouveauContratError, `Erreur chargement options: ${error.message}`);
                    selectBien.innerHTML = '<option value="" disabled>Erreur chargement</option>';
                    selectLocataire.innerHTML = '<option value="" disabled>Erreur chargement</option>';
                }
            }

             // --- Soumission Nouveau Contrat ---
             async function handleNouveauContratSubmit(e) {
                 e.preventDefault(); e.stopPropagation();
                 clearError(formNouveauContratError);
                 formNouveauContrat.classList.remove('was-validated');
                 dateDebutInput.classList.remove('is-invalid'); dateFinInput.classList.remove('is-invalid');

                 const dateDebut = fpDebut.selectedDates[0]; const dateFin = fpFin.selectedDates[0];
                 let datesValides = true;
                 if (!dateDebut) { dateDebutInput.classList.add('is-invalid'); datesValides = false; }
                 if (!dateFin) { dateFinInput.classList.add('is-invalid'); datesValides = false; }
                 if(dateDebut && dateFin && dateFin <= dateDebut) { dateFinInput.classList.add('is-invalid'); if (!formNouveauContratError.textContent) displayError(formNouveauContratError, "Date fin incorrecte."); datesValides = false; }

                 let formValide = formNouveauContrat.checkValidity();
                 if (!formValide || !datesValides) { formNouveauContrat.classList.add('was-validated'); if (!formNouveauContratError.textContent) displayError(formNouveauContratError, "Champs invalides."); return; }

                 const formData = new FormData(formNouveauContrat);
                 formData.append('action', 'create_contrat');
                 const originalButtonText = submitNouveauContratBtn.innerHTML; setButtonLoading(submitNouveauContratBtn, true);

                 try {
                    const response = await fetch('', { method: 'POST', body: formData });
                    const data = await response.json();
                    if (!response.ok || !data.success) throw new Error(data.message || `Erreur ${response.status}`);

                    const newRow = createContratRow(data.newContrat);
                    noResultsRow.classList.add('d-none');
                    // Insérer la nouvelle ligne en haut du tbody
                    if (tableBody.firstChild && !tableBody.firstChild.classList.contains('no-results-row')) {
                        tableBody.insertBefore(newRow, tableBody.firstChild);
                    } else {
                        tableBody.appendChild(newRow); // Ajouter si la table était vide
                    }
                    modalNouveauContrat.hide();
                    alert(data.message || "Contrat créé !");
                    formNouveauContrat.reset(); formNouveauContrat.classList.remove('was-validated');
                    fpDebut.clear(); fpFin.clear(); fpFin.set('minDate', null);
                    // Pas besoin de recharger les options ici, seulement au prochain 'show'

                 } catch (error) { displayError(formNouveauContratError, error.message); }
                 finally { setButtonLoading(submitNouveauContratBtn, false, originalButtonText); }
            }

            // --- Fonction pour Supprimer un Contrat ---
            window.deleteContrat = async function(contratId) {
                if (!confirm(`Êtes-vous sûr de vouloir supprimer le contrat #${contratId} ? Cette action est irréversible.`)) return;
                const row = tableBody.querySelector(`tr[data-contrat-id="${contratId}"]`); if (row) row.style.opacity = '0.5';
                const formData = new FormData(); formData.append('action', 'delete_contrat'); formData.append('id', contratId);
                try {
                    const response = await fetch('', { method: 'POST', body: formData });
                    const data = await response.json();
                    if (!response.ok || !data.success) throw new Error(data.message || `Erreur ${response.status}`);
                    alert(data.message || 'Contrat supprimé.');
                    loadContrats(currentPage); // Recharger la page actuelle pour màj
                } catch (error) {
                    alert(`Erreur suppression: ${error.message}`);
                    if (row) row.style.opacity = '1';
                }
            };

             // --- Écouteurs d'événements ---
             formNouveauContrat.addEventListener('submit', handleNouveauContratSubmit);
             // *** CORRECTION ICI: Appeler loadSelectOptions quand le modal EST AFFICHE ***
             modalNouveauContratEl.addEventListener('shown.bs.modal', () => {
                 console.log("Modal Nouveau Contrat affiché, chargement options..."); // Debug
                 loadSelectOptions();
                 // Reset du formulaire et des erreurs (peut être fait ici ou dans show.bs.modal)
                 clearError(formNouveauContratError);
                 formNouveauContrat.classList.remove('was-validated');
                 formNouveauContrat.reset();
                 fpDebut.clear(); fpFin.clear(); fpFin.set('minDate', null);
             });
            // Optionnel: Vider les selects quand le modal se ferme
             modalNouveauContratEl.addEventListener('hidden.bs.modal', () => {
                 selectBien.innerHTML = '<option value="" selected disabled>-- Chargement... --</option>';
                 selectLocataire.innerHTML = '<option value="" selected disabled>-- Chargement... --</option>';
             });

             filterForm.addEventListener('submit', (e) => { e.preventDefault(); currentFilters = Object.fromEntries(new FormData(filterForm).entries()); loadContrats(1); });
             resetFiltersBtn.addEventListener('click', () => { filterForm.reset(); currentFilters = {}; loadContrats(1); });

             // --- Chargement Initial ---
             loadContrats();

        }); // Fin DOMContentLoaded

        // --- Fonction Pagination (Identique) ---
        function updateContratPagination(paginationInfo, currentPage) {
            const paginationControls = document.getElementById('paginationControlsContrat'); paginationControls.innerHTML = '';
            if (!paginationInfo || paginationInfo.totalPages <= 1) return;
            const totalPages = paginationInfo.totalPages;

            const createPageItem = (page, label = null, isActive = false, isDisabled = false) => {
                const li = document.createElement('li'); li.className = `page-item ${isActive ? 'active' : ''} ${isDisabled ? 'disabled' : ''}`;
                const a = document.createElement('a'); a.className = 'page-link'; a.href = '#'; a.innerHTML = label !== null ? label : page;
                if (!isDisabled && label !== '...') {
                    a.dataset.page = page;
                    a.addEventListener('click', (e) => { e.preventDefault(); const targetPage = parseInt(a.dataset.page); if (!isNaN(targetPage)) { loadContrats(targetPage); } });
                } else { a.setAttribute('aria-disabled', 'true'); }
                li.appendChild(a); return li;
            };

            paginationControls.appendChild(createPageItem(currentPage - 1, '«', false, currentPage === 1));
            const maxPagesToShow = 5; let startPage, endPage;
            if (totalPages <= maxPagesToShow) { startPage = 1; endPage = totalPages; }
            else { const maxSide = Math.floor((maxPagesToShow - 3) / 2); let Pstart = currentPage - maxSide; let Pend = currentPage + maxSide; if (maxPagesToShow === 5 && currentPage > 2 && currentPage < totalPages -1) {Pstart=currentPage-1; Pend=currentPage+1;} else if (currentPage <=3) {Pstart=1; Pend=maxPagesToShow-2;} else if (currentPage >= totalPages-2) {Pstart = totalPages - maxPagesToShow + 3; Pend=totalPages;} startPage = Pstart; endPage = Pend; }

             if (startPage > 1) paginationControls.appendChild(createPageItem(1));
             if (startPage > 2) paginationControls.appendChild(createPageItem(null, '...', false, true));
            for (let i = startPage; i <= endPage; i++) { if (i > 0 && i <= totalPages) paginationControls.appendChild(createPageItem(i, null, i === currentPage)); }
             if (endPage < totalPages - 1) paginationControls.appendChild(createPageItem(null, '...', false, true));
             if (endPage < totalPages) paginationControls.appendChild(createPageItem(totalPages));
            paginationControls.appendChild(createPageItem(currentPage + 1, '»', false, currentPage === totalPages));
        }
    </script>
</body>
</html>