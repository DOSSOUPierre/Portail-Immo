<thinking>
L'objectif est d'adapter la page `notaire-support.php` (qui est actuellement une simulation statique/JS) pour qu'elle affiche réellement les demandes de maintenance soumises par les locataires (via `locataire-maintenance.php`) et stockées dans la table `demandes_maintenance`.

**Étapes Clés :**

1.  **PHP (Haut de page) :**
    *   Authentification du notaire et récupération de `$current_notaire_id`.
    *   **Requête SQL Principale :** Interroger la table `demandes_maintenance` pour récupérer les demandes où `idNotaire` correspond à `$current_notaire_id`. Il faudra probablement joindre d'autres tables pour afficher des informations utiles comme le nom du locataire et l'adresse du bien.
    *   **Filtrage/Pagination (Optionnel mais recommandé) :** Ajouter des options pour filtrer par statut (`statutDemande`) et implémenter une pagination si le nombre de demandes peut être élevé. Pour commencer, on peut afficher les plus récentes ou celles "Envoyée" / "En cours".
    *   Stocker les résultats dans une variable PHP (ex: `$demandes_maintenance`).
2.  **HTML (Tableau Maintenance) :**
    *   Modifier la boucle `foreach` (ou la structure équivalente si on utilise AJAX) pour itérer sur les `$demandes_maintenance` récupérées en PHP.
    *   Afficher les informations pertinentes : Date, Nom du Locataire (via jointure), Adresse du Bien (via jointure), Description, Statut actuel.
    *   **Actions :** Ajouter un bouton "Traiter" (ou "Voir Détails") pour chaque demande, qui ouvrira un modal (`#modalTreatMaintenance`).
3.  **HTML (Modal `#modalTreatMaintenance`) :**
    *   Garder la structure du modal.
4.  **JavaScript :**
    *   **Chargement Initial :** Remplacer la simulation `setTimeout` dans `loadMaintenanceRequests` par un appel AJAX (`fetch`) vers une action PHP (ex: `action=get_maintenance_requests`) sur la même page, qui effectuera la requête SQL filtrée/paginée.
    *   **`treatMaintenanceRequest(demandeId)` :** Modifier cette fonction pour qu'elle fasse un appel AJAX (`fetch`) vers une action PHP (`action=get_maintenance_details`) afin de récupérer les **vrais détails** de la demande (y compris le chemin de la photo si elle existe) et les afficher dans le modal.
    *   **`submitMaintenanceUpdate()` :** Modifier cette fonction pour qu'elle envoie les données du formulaire modal (nouvel état, notes) via AJAX (`fetch POST`) vers une action PHP (`action=update_maintenance_status`) qui mettra à jour la demande dans la BDD et enverra potentiellement une notification au locataire.
5.  **PHP (Traitement AJAX) :**
    *   Ajouter les `case` correspondants dans le bloc `if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['action']))` (ou POST si nécessaire) :
        *   `get_maintenance_requests`: Exécute la requête SQL pour récupérer la liste paginée/filtrée des demandes pour ce notaire.
        *   `get_maintenance_details`: Récupère les détails complets d'une demande spécifique (vérifie l'accès du notaire).
        *   `update_maintenance_status`: Met à jour le `statutDemande` et `notesInternes` dans la table `demandes_maintenance`, et insère une notification pour le locataire si demandé.

**Simplification pour Commencer :**

Pour cette première version fonctionnelle, concentrons-nous sur :
*   Le chargement PHP direct de l'historique des demandes **non terminées** pour le notaire.
*   Le bouton "Traiter" qui ouvre le modal.
*   Le chargement AJAX des détails dans le modal.
*   La mise à jour du statut via AJAX.

Je vais intégrer la logique PHP et JS dans un seul fichier pour correspondre à ta structure.

**Code Adapté `notaire-support.php` :**
</thinking>

```php
<?php
// --- notaire-support.php (Adapté pour afficher les vraies demandes) ---

if (session_status() === PHP_SESSION_NONE) { session_start(); }
// *** 1. Vérification Rôle Notaire ***
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'Notaire') {
    header("Location: auth-signin.php"); exit;
}

require_once 'db_connection.php'; // $pdo

// --- 2. Récupération ID Notaire Connecté ---
function get_logged_in_notaire_id(PDO $pdo, ?int $userId): ?int { /* ... (fonction inchangée) ... */ try { $stmt = $pdo->prepare("SELECT idNotaire FROM notaire WHERE idUser = ?"); $stmt->execute([$userId]); return $stmt->fetchColumn() ?: null; } catch (PDOException $e) { error_log("Err get_notaire_id: " . $e->getMessage()); return null; } }
$logged_in_user_id = $_SESSION['user_id'] ?? null;
$current_notaire_id = get_logged_in_notaire_id($pdo, $logged_in_user_id);

if ($current_notaire_id === null) { $_SESSION['error_message'] = "Compte notaire non identifié."; header("Location: auth-signin.php?logout=1"); exit; }

$pageAlerts = [];
$errorMessage = '';
$initialDataLoaded = false;
$demandes_maintenance = []; // Initialiser le tableau des demandes

// --- GESTION DES ACTIONS AJAX ---
if (isset($_REQUEST['action'])) { // Accepte GET ou POST
    header('Content-Type: application/json');
    $action = $_REQUEST['action'];
    $responseAjax = ['success' => false, 'message' => 'Action non reconnue ou erreur serveur.'];

    try {
        switch ($action) {
            // *** Action pour récupérer les détails d'une demande ***
            case 'get_maintenance_details':
                $demandeId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
                if (!$demandeId) { $responseAjax['message'] = 'ID de demande manquant.'; http_response_code(400); break; }

                // Récupérer détails + infos liées (locataire, bien) en vérifiant l'accès notaire
                $sqlDetails = "SELECT
                                dm.idDemande, dm.dateSoumission, dm.description, dm.photo, dm.statutDemande,
                                CONCAT(u.prenom, ' ', u.nom) AS locataireNom,
                                b.adresse AS bienAdresse
                               -- Ajoutez d'autres infos si besoin (ex: tel locataire)
                               FROM demandes_maintenance dm
                               JOIN locataire l ON dm.idLocataire = l.idLocataire
                               JOIN utilisateurs u ON l.idUser = u.idUser
                               JOIN bienimmobiliers b ON dm.idBien = b.idBien
                               WHERE dm.idDemande = :idDemande AND dm.idNotaire = :idNotaire"; // Vérifie accès notaire

                $stmt = $pdo->prepare($sqlDetails);
                $stmt->execute([':idDemande' => $demandeId, ':idNotaire' => $current_notaire_id]);
                $details = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($details) {
                    // Construire l'URL complète de la photo si elle existe
                    if (!empty($details['photo'])) {
                        $uploadDirRelative = 'uploads/maintenance/'; // Doit correspondre au chemin d'upload
                        $details['photoUrl'] = $uploadDirRelative . htmlspecialchars($details['photo']);
                    } else {
                        $details['photoUrl'] = null;
                    }
                    $responseAjax = ['success' => true, 'details' => $details];
                } else {
                    $responseAjax['message'] = 'Demande non trouvée ou accès refusé.';
                    http_response_code(404);
                }
                break;

             // *** Action pour mettre à jour le statut de la demande ***
             case 'update_maintenance_status':
                if ($_SERVER["REQUEST_METHOD"] !== "POST") { $responseAjax['message'] = 'Méthode non autorisée.'; http_response_code(405); break; }

                $demandeIdUpdate = filter_input(INPUT_POST, 'idDemande', FILTER_VALIDATE_INT);
                $newStatus = $_POST['newStatus'] ?? null;
                $notes = trim($_POST['notes'] ?? '');
                $notifyLocataire = isset($_POST['notifyLocataire']) && $_POST['notifyLocataire'] === 'true';

                // Validation
                 $allowedStatuses = ['Prise en charge', 'En cours', 'Terminée', 'Refusée', 'Annulée']; // Statuts que le notaire peut définir
                 if (!$demandeIdUpdate || empty($newStatus) || !in_array($newStatus, $allowedStatuses)) {
                    $responseAjax['message'] = 'Données invalides pour la mise à jour.'; http_response_code(400); break;
                }

                 // Récupérer infos nécessaires pour update et notification (idLocataire, idUser locataire)
                 $stmtInfo = $pdo->prepare("SELECT dm.idLocataire, l.idUser, dm.statutDemande as oldStatus FROM demandes_maintenance dm JOIN locataire l ON dm.idLocataire = l.idLocataire WHERE dm.idDemande = ? AND dm.idNotaire = ?");
                 $stmtInfo->execute([$demandeIdUpdate, $current_notaire_id]);
                 $demandeInfo = $stmtInfo->fetch(PDO::FETCH_ASSOC);

                 if (!$demandeInfo) { $responseAjax['message'] = 'Demande non trouvée ou accès refusé.'; http_response_code(404); break; }
                 if ($demandeInfo['oldStatus'] === $newStatus && empty($notes)) { $responseAjax = ['success' => true, 'message' => 'Aucun changement détecté.']; break; } // Pas de modif

                 $pdo->beginTransaction();
                 try {
                     // 1. Mettre à jour la demande
                     $sqlSet = ["statutDemande = :newStatus"];
                     $paramsUpdate = [':newStatus' => $newStatus, ':idDemande' => $demandeIdUpdate, ':idNotaire' => $current_notaire_id];
                     if (!empty($notes)) {
                         $sqlSet[] = "notesInternes = CONCAT(IFNULL(notesInternes, ''), '\n---\n', NOW(), ' (Notaire):\n', :notes)"; // Ajoute la note avec date
                         $paramsUpdate[':notes'] = $notes;
                     }
                     if ($newStatus === 'Terminée' || $newStatus === 'Résolue') { // Mettre à jour date résolution si pertinent
                          $sqlSet[] = "dateResolution = CURDATE()";
                     }
                     $sqlUpdate = "UPDATE demandes_maintenance SET " . implode(', ', $sqlSet) . " WHERE idDemande = :idDemande AND idNotaire = :idNotaire";
                     $stmtUpdate = $pdo->prepare($sqlUpdate);
                     $stmtUpdate->execute($paramsUpdate);

                     if ($stmtUpdate->rowCount() > 0) {
                         // 2. Envoyer notification au locataire si coché
                         if ($notifyLocataire && $demandeInfo['idUser']) {
                              $contenuNotif = "Statut demande maintenance #{$demandeIdUpdate} mis à jour : {$newStatus}.";
                              if (!empty($notes)) { $contenuNotif .= "\nCommentaire : " . $notes; }
                              $sqlInsertNotif = "INSERT INTO notification (idUser, contenu, dateNotif, statutNotif, typeNotif) VALUES (?, ?, CURDATE(), 'Envoyé', 'Maintenance')";
                              $stmtNotif = $pdo->prepare($sqlInsertNotif);
                              $stmtNotif->execute([$demandeInfo['idUser'], $contenuNotif]);
                         }
                         $pdo->commit();
                         $responseAjax = ['success' => true, 'message' => "Statut de la demande #{$demandeIdUpdate} mis à jour en '{$newStatus}'."];
                     } else {
                         $pdo->rollBack(); // Annuler si l'update n'a affecté aucune ligne
                         $responseAjax['message'] = 'Aucun changement effectué ou accès refusé.';
                     }

                 } catch (PDOException $e) {
                     $pdo->rollBack();
                     error_log("Erreur PDO update maintenance: " . $e->getMessage());
                     $responseAjax['message'] = 'Erreur base de données lors de la mise à jour.'; http_response_code(500);
                 }
                 break;

            default:
                $responseAjax['message'] = 'Action inconnue.'; http_response_code(400);
                break;
        }
    } catch (PDOException | Exception $e) {
        error_log("Erreur Générale AJAX Notaire Support ({$action}): " . $e->getMessage());
        $responseAjax['message'] = 'Erreur serveur interne.'; http_response_code(500);
    }
    echo json_encode($responseAjax);
    exit;
}


// --- CHARGEMENT INITIAL DE L'HISTORIQUE DES DEMANDES POUR CE NOTAIRE ---
try {
    if (!isset($pdo)) { throw new Exception("Connexion BDD non disponible."); }
    if ($current_notaire_id === null) { throw new Exception("Notaire non identifié."); }

    // Récupérer les demandes NON terminées/annulées pour ce notaire
    $sqlHistorique = "SELECT
                        dm.idDemande, dm.dateSoumission, dm.description, dm.statutDemande,
                        CONCAT(u.prenom, ' ', u.nom) AS locataireNom,
                        b.adresse AS bienAdresse
                      FROM demandes_maintenance dm
                      JOIN locataire l ON dm.idLocataire = l.idLocataire
                      JOIN utilisateurs u ON l.idUser = u.idUser
                      JOIN bienimmobiliers b ON dm.idBien = b.idBien
                      WHERE dm.idNotaire = :idNotaire
                      AND dm.statutDemande NOT IN ('Terminée', 'Annulée', 'Résolue', 'Refusée') -- Statuts ouverts
                      ORDER BY dm.dateSoumission DESC";
    $stmtHisto = $pdo->prepare($sqlHistorique);
    $stmtHisto->execute([':idNotaire' => $current_notaire_id]);
    $demandes_maintenance = $stmtHisto->fetchAll(PDO::FETCH_ASSOC);
    $initialDataLoaded = true; // Marquer comme chargé

} catch (PDOException $e) { $errorMessage = 'Erreur chargement historique.'; error_log("Err PDO notaire-support Init NotaireID {$current_notaire_id}: " . $e->getMessage());
} catch (Exception $e) { $errorMessage = $e->getMessage(); error_log("Err Gen notaire-support Init NotaireID {$current_notaire_id}: " . $e->getMessage()); }
if(!empty($errorMessage)) { $pageAlerts[] = ['type' => 'danger', 'message' => $errorMessage]; }

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <title>Support & Demandes | Espace Notaire</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Gestion des demandes de maintenance et autres requêtes." />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
    <script src="assets/js/config.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        /* Vos Styles CSS */
         body { padding-top: 70px; } @media (min-width: 992px) { body { padding-top: 80px; } }
         .table th, .table td { vertical-align: middle; font-size: 0.875rem; }
         .table th { font-weight: 500; }
         .btn-sm i { font-size: 1rem; }
         .description-column { min-width: 250px; max-width: 450px; white-space: normal; word-wrap: break-word;}
         .no-results-row td { text-align: center; font-style: italic; color: #6c757d; padding: 1rem; }
         .table td:last-child { text-align: center; width: 100px;}
         .nav-pills .nav-link.active { background-color: var(--couleur-primaire); color: white;}
         /* Style pour modal details */
         #maintenanceRequestDetails dt { font-weight: bold; color: var(--couleur-primaire); }
         #maintenanceRequestDetails dd { margin-left: 0; margin-bottom: 0.75rem; }
         #maintenanceRequestDetails img { max-width: 100%; height: auto; max-height: 250px; border-radius: .3rem; cursor: pointer; border: 1px solid #eee; }
         .content-requires-data.loading-error { display: none; }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- ========== Topbar, Sidebar ========== -->
         <header class=""> <div class="topbar"> <div class="container-fluid"> <div class="navbar-header"> <div class="d-flex align-items-center gap-2"> <div class="topbar-item"><button type="button" class="button-toggle-menu topbar-button"><i class="ri-menu-2-line fs-24"></i></button></div> <form class="app-search d-none d-md-block me-auto"><div class="position-relative"><input type="search" class="form-control border-0" placeholder="Rechercher..." autocomplete="off" value=""><i class="ri-search-line search-widget-icon"></i></div></form> </div> <div class="d-flex align-items-center gap-1"> <div class="topbar-item"><button type="button" class="topbar-button" id="light-dark-mode"><i class="ri-moon-line fs-24 light-mode"></i><i class="ri-sun-line fs-24 dark-mode"></i></button></div> <div class="dropdown topbar-item d-none d-lg-flex"><button type="button" class="topbar-button" data-toggle="fullscreen"><i class="ri-fullscreen-line fs-24 fullscreen"></i><i class="ri-fullscreen-exit-line fs-24 quit-fullscreen"></i></button></div> <div class="dropdown topbar-item"> <button type="button" class="topbar-button position-relative" id="page-header-notifications-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="ri-notification-3-line fs-24"></i><span id="topbarUnreadCount" class="position-absolute topbar-badge fs-10 translate-middle badge bg-danger rounded-pill">0<span class="visually-hidden">unread</span></span></button> <div class="dropdown-menu py-0 dropdown-lg dropdown-menu-end" aria-labelledby="page-header-notifications-dropdown"> <div class="p-3 border-top-0 border-start-0 border-end-0 border-dashed border"><div class="row align-items-center"><div class="col"><h6 class="m-0 fs-16 fw-semibold"> Notifications</h6></div><div class="col-auto"><a href="javascript: void(0);" class="text-dark text-decoration-underline"> <small>Marquer tout comme lu</small></a></div></div></div> <div data-simplebar style="max-height: 280px;" id="topbarNotificationList"></div> <div class="text-center py-3"><a href="notaire-notifications.php" class="btn btn-primary btn-sm">Tout voir <i class="ri-arrow-right-line ms-1"></i></a></div> </div> </div> <div class="topbar-item d-none d-md-flex"><button type="button" class="topbar-button" id="theme-settings-btn" data-bs-toggle="offcanvas" data-bs-target="#theme-settings-offcanvas" aria-controls="theme-settings-offcanvas"><i class="ri-settings-4-line fs-24"></i></button></div> <div class="dropdown topbar-item"> <a type="button" class="topbar-button" id="page-header-user-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><span class="d-flex align-items-center"><img class="rounded-circle" width="32" src="assets/images/users/avatar-notaire.png" alt="notaire"></span></a> <div class="dropdown-menu dropdown-menu-end"> <h6 class="dropdown-header" id="userDropdownHeader">Bienvenue Maître !</h6> <a class="dropdown-item" href="notaire-profil.php"><i class="ri-user-line align-middle me-1"></i> Mon Profil/Cabinet</a> <div class="dropdown-divider my-1"></div> <a class="dropdown-item text-danger" href="auth-signout.php"><i class="ri-logout-box-line align-middle me-1"></i> Déconnexion</a> </div> </div> </div> </div> </div> </header>
         <div> <div class="offcanvas offcanvas-end border-0 rounded-start-4 overflow-hidden" tabindex="-1" id="theme-settings-offcanvas"> <div class="d-flex align-items-center bg-primary p-3 offcanvas-header"><h5 class="text-white m-0">Theme Settings</h5><button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="offcanvas" aria-label="Close"></button></div><div class="offcanvas-body p-0"><div data-simplebar class="h-100"><div class="p-3 settings-bar"></div></div></div><div class="offcanvas-footer border-top p-3 text-center"><div class="row"><div class="col"><button type="button" class="btn btn-danger w-100" id="reset-layout">Reset</button></div></div></div> </div> </div>
         <div class="main-nav"> <div class="logo-box"> <a href="notaire-dashboard.php" class="logo-dark"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-dark.png" class="logo-lg"></a> <a href="notaire-dashboard.php" class="logo-light"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-light.png" class="logo-lg"></a> </div> <button type="button" class="button-sm-hover" aria-label="Show Full Sidebar"><i class="ri-menu-2-line fs-24 button-sm-hover-icon"></i></button> <div class="scrollbar" data-simplebar> <ul class="navbar-nav" id="navbar-nav"> <li class="menu-title">Menu Notaire</li> <li class="nav-item"><a class="nav-link" href="notaire-dashboard.php"><span class="nav-icon"><i class="ri-dashboard-line"></i></span><span class="nav-text">Tableau de Bord</span></a></li> <li class="nav-item"><a class="nav-link" href="notaire-gestion-biens.php"><span class="nav-icon"><i class="ri-community-line"></i></span><span class="nav-text">Gestion Biens</span></a></li> <li class="nav-item"><a class="nav-link" href="notaire-gestion-locataires.php"><span class="nav-icon"><i class="ri-group-line"></i></span><span class="nav-text">Gestion Locataires</span></a></li> <li class="nav-item"><a class="nav-link" href="notaire-gestion-contrats.php"><span class="nav-icon"><i class="ri-file-list-3-line"></i></span><span class="nav-text">Gestion Contrats</span></a></li> <li class="nav-item"><a class="nav-link" href="notaire-suivi-financier.php"><span class="nav-icon"><i class="ri-bank-card-line"></i></span><span class="nav-text">Suivi Financier</span></a></li> <li class="nav-item"><a class="nav-link active" href="notaire-support.php"><span class="nav-icon"><i class="ri-customer-service-2-line"></i></span><span class="nav-text">Support & Demandes</span></a></li> <li class="nav-item"><a class="nav-link" href="notaire-notifications.php"><span class="nav-icon"><i class="ri-notification-3-line"></i></span><span class="nav-text">Notifications</span><span class="badge bg-danger badge-pill text-end" id="sidebarUnreadCount" style="display: none;"></span></a></li> </ul> </div> </div>
        <!-- ============================================================== -->

        <div class="page-content">
            <div class="container-fluid">
                <!-- Titre de Page -->
                 <div class="row"> <div class="col-12"> <div class="page-title-box"> <h4 class="mb-0 fw-semibold">Support & Demandes</h4> <ol class="breadcrumb mb-0"> <li class="breadcrumb-item"><a href="notaire-dashboard.php">Espace Notaire</a></li> <li class="breadcrumb-item active">Support</li> </ol> </div> </div> </div>
                 <div id="pageAlertPlaceholder"> <?php foreach ($pageAlerts as $alert): ?> <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show"> <?php echo htmlspecialchars($alert['message']); ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button> </div> <?php endforeach; ?> </div>

                 <!-- Navigation par Onglets (Tabs) -->
                 <ul class="nav nav-pills nav-fill mb-3" id="supportTab" role="tablist">
                     <li class="nav-item" role="presentation">
                         <button class="nav-link active" id="maintenance-tab" data-bs-toggle="tab" data-bs-target="#maintenance-tab-pane" type="button" role="tab">
                            <i class="ri-tools-line me-1"></i> Demandes de Maintenance
                            <span id="maintenanceCountBadge" class="badge rounded-pill bg-danger ms-1" style="<?= count($demandes_maintenance) > 0 ? '' : 'display: none;' ?>">
                                <?= count($demandes_maintenance) ?>
                            </span>
                         </button>
                     </li>
                     <li class="nav-item" role="presentation">
                         <button class="nav-link disabled" id="requests-tab" data-bs-toggle="tab" data-bs-target="#requests-tab-pane" type="button" role="tab" aria-disabled="true">
                             <i class="ri-question-answer-line me-1"></i> Autres Demandes (Bientôt)
                         </button>
                     </li>
                 </ul>

                 <!-- Contenu des Onglets -->
                 <div class="tab-content" id="supportTabContent">
                     <!-- Onglet Maintenance -->
                     <div class="tab-pane fade show active" id="maintenance-tab-pane" role="tabpanel" aria-labelledby="maintenance-tab" tabindex="0">
                          <div class="card">
                               <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">Demandes de Maintenance Ouvertes</h5>
                                     <!-- Filtres si besoin -->
                               </div>
                               <div class="card-body p-0">
                                    <div class="table-responsive">
                                         <table class="table table-hover table-centered mb-0">
                                              <thead class="table-light">
                                                   <tr>
                                                        <th>Date Soumission</th>
                                                        <th>Locataire</th>
                                                        <th>Bien Concerné</th>
                                                        <th class="description-column">Description</th>
                                                        <th>Statut</th>
                                                        <th style="width: 100px;">Action</th>
                                                   </tr>
                                              </thead>
                                              <tbody id="maintenanceTabBody">
                                                <?php if (!$initialDataLoaded && !empty($errorMessage)): // Erreur chargement BDD ?>
                                                    <tr class="error-row"><td colspan="6"><?= htmlspecialchars($errorMessage) ?></td></tr>
                                                <?php elseif (empty($demandes_maintenance)): // Chargement OK mais pas de demandes ?>
                                                    <tr class="no-results-row"><td colspan="6">Aucune demande de maintenance en attente ou en cours.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach ($demandes_maintenance as $demande):
                                                        $formattedDate = $demande['dateSoumission'] ? date("d/m/Y H:i", strtotime($demande['dateSoumission'])) : '-';
                                                        $descriptionCourte = htmlspecialchars(substr($demande['description'] ?? '', 0, 80)) . (strlen($demande['description'] ?? '') > 80 ? '...' : '');
                                                        $locataireNomAff = htmlspecialchars($demande['locataireNom'] ?? 'N/A');
                                                        $bienAdresseAff = htmlspecialchars(substr($demande['bienAdresse'] ?? 'N/A', 0, 30)) . (strlen($demande['bienAdresse'] ?? '') > 30 ? '...' : '');
                                                        $statutDemande = htmlspecialchars($demande['statutDemande'] ?? 'Inconnu');
                                                        $badgeClass = 'bg-secondary';
                                                        switch (strtolower($statutDemande)) {
                                                            case 'envoyée': $badgeClass = 'bg-warning text-dark'; break;
                                                            case 'prise en charge': case 'en cours': $badgeClass = 'bg-info'; break;
                                                            // Terminée/Annulée ne sont pas affichées ici par défaut
                                                        }
                                                    ?>
                                                    <tr>
                                                        <td><?= $formattedDate ?></td>
                                                        <td><?= $locataireNomAff ?></td>
                                                        <td title="<?= htmlspecialchars($demande['bienAdresse'] ?? '') ?>"><?= $bienAdresseAff ?></td>
                                                        <td class="description-column" title="<?= htmlspecialchars($demande['description'] ?? '') ?>"><?= $descriptionCourte ?></td>
                                                        <td><span class="badge <?= $badgeClass ?>"><?= $statutDemande ?></span></td>
                                                        <td>
                                                            <button class="btn btn-sm btn-primary" title="Traiter la demande" onclick="treatMaintenanceRequest(<?= $demande['idDemande'] ?>)">
                                                                <i class="ri-edit-line"></i> Traiter
                                                            </button>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                              </tbody>
                                         </table>
                                    </div>
                               </div>
                               <div class="card-footer bg-white border-top d-flex justify-content-between align-items-center">
                                   <div class="fs-sm text-muted">Total: <?= count($demandes_maintenance) ?> demande(s) ouverte(s)</div>
                                   <!-- Pagination si nécessaire -->
                               </div>
                          </div>
                     </div>

                     <!-- Onglet Autres Demandes (Désactivé pour l'instant) -->
                     <div class="tab-pane fade" id="requests-tab-pane" role="tabpanel" aria-labelledby="requests-tab" tabindex="0">
                          <p class="text-muted p-3">Fonctionnalité pour les autres types de demandes (renouvellement, résiliation...) bientôt disponible.</p>
                     </div>
                 </div> <!-- Fin Tab Content -->

            </div> <!-- container-fluid -->
        </div> <!-- page-content -->

         <!-- Modal Traiter Demande Maintenance (Inchangé) -->
         <div class="modal fade" id="modalTreatMaintenance" tabindex="-1" aria-labelledby="modalTreatMaintenanceLabel" aria-hidden="true">
             <div class="modal-dialog modal-lg modal-dialog-scrollable">
                 <div class="modal-content">
                     <div class="modal-header">
                         <h5 class="modal-title" id="modalTreatMaintenanceLabel">Traiter Demande Maintenance #<span id="maintenanceRequestId"></span></h5>
                         <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                     </div>
                     <div class="modal-body">
                          <div id="maintenanceRequestDetails">
                               <div class="text-center p-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Chargement...</span></div></div>
                          </div>
                         <hr>
                         <form id="formTreatMaintenance" novalidate>
                              <input type="hidden" id="treatMaintenanceId" name="idDemande">
                              <div class="mb-3">
                                   <label for="maintenanceNewStatus" class="form-label">Changer le statut <span class="text-danger">*</span>:</label>
                                   <select class="form-select" id="maintenanceNewStatus" name="newStatus" required>
                                       <option value="" disabled selected>-- Choisir un nouveau statut --</option>
                                       <option value="Prise en charge">Prise en charge</option>
                                       <option value="En cours">En cours</option>
                                       <option value="Terminée">Terminée</option>
                                       <option value="Refusée">Refusée</option>
                                       <option value="Annulée">Annulée</option>
                                   </select>
                                    <div class="invalid-feedback">Veuillez sélectionner un statut.</div>
                              </div>
                              <div class="mb-3">
                                   <label for="maintenanceNotes" class="form-label">Notes internes / Commentaire pour le locataire :</label>
                                   <textarea class="form-control" id="maintenanceNotes" name="notes" rows="3" placeholder="Ex: Artisan contacté, intervention prévue le... ou Raison du refus..."></textarea>
                              </div>
                               <div class="form-check mb-3">
                                   <input class="form-check-input" type="checkbox" value="true" id="notifyLocataireMaintenance" name="notifyLocataire" checked>
                                   <label class="form-check-label" for="notifyLocataireMaintenance">
                                       Notifier le locataire de cette mise à jour
                                   </label>
                               </div>
                         </form>
                     </div>
                     <div class="modal-footer justify-content-between">
                          <span id="modalTreatLoadingSpinner" style="display: none;"><span class="spinner-border spinner-border-sm text-primary"></span> Mise à jour...</span>
                          <div>
                             <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                             <button type="button" class="btn btn-primary" id="submitMaintenanceUpdateBtn" onclick="submitMaintenanceUpdate()">Mettre à jour</button>
                          </div>
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

    <!-- SCRIPT PERSONNALISÉ pour Notaire Support -->
    <script>
        // Fonction pour échapper HTML (sécurité)
        const escapeHTML = str => str ? String(str).replace(/[&<>'"]/g, tag => ({'&': '&', '<': '<', '>': '>', "'": ''', '"': '"'}[tag] || tag)) : '';
        const nl2br = (str) => (str + '').replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g, '$1<br>$2');

        // --- Fonction Globale pour ouvrir modal Traitement Maintenance ---
        window.treatMaintenanceRequest = function(demandeId) {
            const modalEl = document.getElementById('modalTreatMaintenance');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            const titleIdSpan = document.getElementById('maintenanceRequestId');
            const hiddenInputId = document.getElementById('treatMaintenanceId');
            const detailsDiv = document.getElementById('maintenanceRequestDetails');
            const form = document.getElementById('formTreatMaintenance');
            const statusSelect = document.getElementById('maintenanceNewStatus');
            const notesTextarea = document.getElementById('maintenanceNotes');
            const notifyCheckbox = document.getElementById('notifyLocataireMaintenance');

            // Réinitialiser et afficher chargement
            titleIdSpan.textContent = demandeId;
            hiddenInputId.value = demandeId;
            detailsDiv.innerHTML = '<p class="text-center py-3"><span class="spinner-border spinner-border-sm me-2"></span> Chargement...</p>';
            form.reset(); // Reset select, notes, checkbox
            statusSelect.value = ""; // Assurer qu'aucune option n'est présélectionnée
            form.classList.remove('was-validated');

            // Appeler l'action AJAX pour obtenir les détails
            fetch(`?action=get_maintenance_details&id=${demandeId}`)
                .then(response => response.ok ? response.json() : response.text().then(text => { throw new Error(`Erreur ${response.status}: ${text}`) }))
                .then(data => {
                    if (data.success && data.details) {
                        const d = data.details;
                        const formattedDate = d.dateSoumission ? new Date(d.dateSoumission).toLocaleString('fr-FR', {dateStyle: 'short', timeStyle: 'short'}) : '-';
                        const photoHtml = d.photoUrl ? `<dt>Photo jointe:</dt><dd><a href="${escapeHTML(d.photoUrl)}" target="_blank"><img src="${escapeHTML(d.photoUrl)}" alt="Photo Maintenance" style="max-width: 150px; height: auto;"></a></dd>` : '';

                        detailsDiv.innerHTML = `
                            <dl class="mb-0">
                                <dt>Date Soumission:</dt><dd>${formattedDate}</dd>
                                <dt>Locataire:</dt><dd>${escapeHTML(d.locataireNom || 'N/A')}</dd>
                                <dt>Bien Concerné:</dt><dd>${escapeHTML(d.bienAdresse || 'N/A')}</dd>
                                <dt>Description:</dt><dd>${nl2br(escapeHTML(d.description || 'N/A'))}</dd>
                                <dt>Statut Actuel:</dt><dd><span class="badge ${getBadgeClass(d.statutDemande)}">${escapeHTML(d.statutDemande || '?')}</span></dd>
                                ${photoHtml}
                            </dl>`;
                        // Pré-sélectionner le statut actuel si besoin (ou forcer un choix)
                        // statusSelect.value = d.statutDemande; // Optionnel
                        modal.show(); // Afficher modal seulement après succès chargement
                    } else {
                        Swal.fire('Erreur', data.message || 'Impossible de charger les détails.', 'error');
                    }
                })
                .catch(error => {
                    console.error('Erreur chargement détails maintenance:', error);
                    Swal.fire('Erreur', `Erreur technique (${error.message}).`, 'error');
                });
        }

        // --- Fonction Globale pour soumettre MAJ Statut Maintenance ---
         window.submitMaintenanceUpdate = function() {
            const form = document.getElementById('formTreatMaintenance');
            const submitBtn = document.getElementById('submitMaintenanceUpdateBtn');
            const loadingSpinner = document.getElementById('modalTreatLoadingSpinner');
            const modalInstance = bootstrap.Modal.getInstance(document.getElementById('modalTreatMaintenance'));

            // Validation simple
            if (!form.checkValidity()) {
                 form.classList.add('was-validated');
                 Swal.fire('Erreur', 'Veuillez sélectionner un nouveau statut.', 'warning');
                 return;
             }
             form.classList.remove('was-validated');

            const formData = new FormData(form);
            formData.append('action', 'update_maintenance_status'); // Ajout de l'action
            const originalButtonText = submitBtn.innerHTML;
            setModalButtonLoading(submitBtn, loadingSpinner, true, 'Mise à jour...');

            fetch('', { method: 'POST', body: formData })
                .then(response => response.ok ? response.json() : response.text().then(text => { throw new Error(`Erreur ${response.status}: ${text}`) }))
                .then(data => {
                    if (data.success) {
                        modalInstance.hide();
                        Swal.fire({ icon: 'success', title: 'Succès', text: data.message, timer: 2500, showConfirmButton: false });
                        // Recharger la liste pour refléter le changement
                         // Pour l'instant, on recharge toute la page. Mieux avec AJAX load.
                        window.location.reload();
                    } else {
                        Swal.fire('Erreur', data.message || 'La mise à jour a échoué.', 'error');
                    }
                })
                .catch(error => {
                    console.error("Erreur MAJ statut maintenance:", error);
                    Swal.fire('Erreur', `Erreur technique: ${error.message}`, 'error');
                })
                .finally(() => {
                    setModalButtonLoading(submitBtn, loadingSpinner, false, originalButtonText);
                });
         }

        // Helper pour classe de badge statut
         function getBadgeClass(status) {
             status = String(status).toLowerCase();
             if (status === 'envoyée') return 'bg-warning text-dark';
             if (status === 'prise en charge' || status === 'en cours') return 'bg-info';
             if (status === 'terminée' || status === 'résolue') return 'bg-success';
             if (status === 'refusée' || status === 'annulée') return 'bg-danger';
             return 'bg-secondary';
         }
        // Helper pour état loading bouton modal
         function setModalButtonLoading(btn, spinner, isLoading, loadingText = 'Chargement...') {
            if(!btn || !spinner) return;
            btn.disabled = isLoading;
            if (isLoading) {
                spinner.style.display = 'inline-block';
                btn.innerHTML = loadingText; // Change text
            } else {
                spinner.style.display = 'none';
                btn.innerHTML = btn.dataset.originalText || 'Mettre à jour'; // Restore original ou default
            }
         }

        // --- Script Principal ---
        document.addEventListener('DOMContentLoaded', function() {
             console.log("Notaire Support JS Initialized.");
             // Le chargement initial est fait par PHP.
             // Ajouter ici les listeners pour les filtres/pagination si implémentés en AJAX.
             // Stocker le texte original du bouton de soumission du modal
             const submitBtn = document.getElementById('submitMaintenanceUpdateBtn');
             if (submitBtn) submitBtn.dataset.originalText = submitBtn.innerHTML;
        });
    </script>
</body>
</html>