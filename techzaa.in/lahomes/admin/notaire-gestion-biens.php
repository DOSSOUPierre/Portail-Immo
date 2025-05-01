<?php
// --- notaire-gestion-biens.php ---

if (session_status() === PHP_SESSION_NONE) { session_start(); }
// *** Vérification Rôle Notaire ***
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Notaire') {
    header("Location: auth-signin.php"); // Redirige si pas notaire
    exit;
}

require_once 'db_connection.php'; // $pdo

// --- Fonction pour récupérer l'ID Notaire (identique) ---
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

// Quitter si l'ID notaire n'est pas trouvé (sécurité supplémentaire)
if ($current_notaire_id === null) {
     // Peut-être afficher un message d'erreur plus clair ou juste rediriger
     $_SESSION['error_message'] = "Impossible d'identifier le compte notaire associé.";
     header("Location: auth-signin.php?logout=1"); // Déconnecter et rediriger
     exit;
}


$pageAlerts = [];
$defaultImage = 'assets/images/property/default.jpg';
$uploadDirRelative = 'uploads/biens/';
$uploadDirServer = __DIR__ . '/uploads/biens/';

// --- ACTION AJAX 'update_status' SUPPRIMÉE ---
// Le notaire ne modifie pas le statut de supervision.

// --- CHARGEMENT INITIAL DES BIENS POUR CE NOTAIRE ---
$biens_notaire = [];
try {
    // *** Modification Requête SQL : Sélectionne les biens où le notaire a au moins un contrat ***
    // Utilise DISTINCT pour éviter les doublons si un bien a plusieurs contrats avec le même notaire
    // Sélectionne les colonnes utiles pour le notaire
    $sql_get_biens = "SELECT DISTINCT
                            b.idBien, b.adresse, b.statut as statutLocation, b.loyerMensuel,
                            b.image_profil, b.supervisionStatut, -- Gardé pour info
                            u_prop.nom as propNom, u_prop.prenom as propPrenom
                      FROM bienimmobiliers b
                      -- Jointure pour trouver le propriétaire associé au bien
                      LEFT JOIN proprietaire p ON b.idProprietaire = p.idProprietaire
                      LEFT JOIN utilisateurs u_prop ON p.idUser = u_prop.idUser
                      -- Jointure pour lier aux contrats de CE notaire
                      JOIN contrat c ON b.idBien = c.idBien
                      WHERE c.idNotaire = :idNotaire
                      -- Optionnel : Filtrer aussi par statut de supervision si besoin
                      -- AND b.supervisionStatut = 'Validé'
                      ORDER BY b.idBien DESC";

    $stmt_get_biens = $pdo->prepare($sql_get_biens);
    $stmt_get_biens->bindParam(':idNotaire', $current_notaire_id, PDO::PARAM_INT);
    $stmt_get_biens->execute();
    $biens_notaire = $stmt_get_biens->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $pageAlerts[] = ['type' => 'danger', 'message' => 'Erreur lors du chargement de vos biens.'];
    error_log("Err PDO get_biens notaire ID {$current_notaire_id}: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <title>Gestion de Mes Biens | Espace Notaire</title> <!-- Titre adapté -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Gestion des biens immobiliers confiés au notaire." /> <!-- Description adaptée -->
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
    <script src="assets/js/config.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        /* Styles CSS identiques à admin-supervision-biens.php */
        .table th, .table td { vertical-align: middle; font-size: 0.875rem; }
        .table th { font-weight: 500; }
        .btn-sm i { font-size: 1rem; }
        .img-thumbnail-tiny { max-width: 60px; height: 40px; object-fit: cover; }
        .no-results-row td { text-align: center; font-style: italic; color: #6c757d; padding: 1rem; }
        .table td:last-child { text-align: center; width: 150px;} /* Actions centrées et largeur fixe */
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- ========== Topbar, Sidebar (Utilisez votre HTML de notaire ici) ========== -->
        <!-- Assurez-vous que les liens de la sidebar sont corrects pour le notaire -->
         <header class=""> <div class="topbar"> <div class="container-fluid"> <div class="navbar-header"> <div class="d-flex align-items-center gap-2"> <div class="topbar-item"><button type="button" class="button-toggle-menu topbar-button"><i class="ri-menu-2-line fs-24"></i></button></div> <form class="app-search d-none d-md-block me-auto"><div class="position-relative"><input type="search" class="form-control border-0" placeholder="Rechercher..." autocomplete="off" value=""><i class="ri-search-line search-widget-icon"></i></div></form> </div> <div class="d-flex align-items-center gap-1"> <div class="topbar-item"><button type="button" class="topbar-button" id="light-dark-mode"><i class="ri-moon-line fs-24 light-mode"></i><i class="ri-sun-line fs-24 dark-mode"></i></button></div> <div class="dropdown topbar-item d-none d-lg-flex"><button type="button" class="topbar-button" data-toggle="fullscreen"><i class="ri-fullscreen-line fs-24 fullscreen"></i><i class="ri-fullscreen-exit-line fs-24 quit-fullscreen"></i></button></div> <div class="dropdown topbar-item"> <button type="button" class="topbar-button position-relative" id="page-header-notifications-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="ri-notification-3-line fs-24"></i><span id="topbarUnreadCount" class="position-absolute topbar-badge fs-10 translate-middle badge bg-danger rounded-pill">0<span class="visually-hidden">unread</span></span></button> <div class="dropdown-menu py-0 dropdown-lg dropdown-menu-end" aria-labelledby="page-header-notifications-dropdown"> <div class="p-3 border-top-0 border-start-0 border-end-0 border-dashed border"><div class="row align-items-center"><div class="col"><h6 class="m-0 fs-16 fw-semibold"> Notifications</h6></div><div class="col-auto"><a href="javascript: void(0);" class="text-dark text-decoration-underline"> <small>Marquer tout comme lu</small></a></div></div></div> <div data-simplebar style="max-height: 280px;" id="topbarNotificationList"></div> <div class="text-center py-3"><a href="notaire-notifications.html" class="btn btn-primary btn-sm">Tout voir <i class="ri-arrow-right-line ms-1"></i></a></div> </div> </div> <div class="topbar-item d-none d-md-flex"><button type="button" class="topbar-button" id="theme-settings-btn" data-bs-toggle="offcanvas" data-bs-target="#theme-settings-offcanvas" aria-controls="theme-settings-offcanvas"><i class="ri-settings-4-line fs-24"></i></button></div> <div class="dropdown topbar-item"> <a type="button" class="topbar-button" id="page-header-user-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><span class="d-flex align-items-center"><img class="rounded-circle" width="32" src="assets/images/users/avatar-notaire.png" alt="notaire"></span></a> <div class="dropdown-menu dropdown-menu-end"> <h6 class="dropdown-header" id="userDropdownHeader">Bienvenue Maître !</h6> <a class="dropdown-item" href="notaire-profil.html"><i class="ri-user-line align-middle me-1"></i> Mon Profil/Cabinet</a> <div class="dropdown-divider my-1"></div> <a class="dropdown-item text-danger" href="auth-signout.php"><i class="ri-logout-box-line align-middle me-1"></i> Déconnexion</a> </div> </div> </div> </div> </div> </header>
         <div> <div class="offcanvas offcanvas-end border-0 rounded-start-4 overflow-hidden" tabindex="-1" id="theme-settings-offcanvas"> <div class="d-flex align-items-center bg-primary p-3 offcanvas-header"><h5 class="text-white m-0">Theme Settings</h5><button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="offcanvas" aria-label="Close"></button></div><div class="offcanvas-body p-0"><div data-simplebar class="h-100"><div class="p-3 settings-bar"></div></div></div><div class="offcanvas-footer border-top p-3 text-center"><div class="row"><div class="col"><button type="button" class="btn btn-danger w-100" id="reset-layout">Reset</button></div></div></div> </div> </div>
         <div class="main-nav">
            <div class="logo-box"> <a href="notaire-dashboard.php" class="logo-dark"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-dark.png" class="logo-lg"></a> <a href="notaire-dashboard.php" class="logo-light"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-light.png" class="logo-lg"></a> </div>
            <button type="button" class="button-sm-hover" aria-label="Show Full Sidebar"><i class="ri-menu-2-line fs-24 button-sm-hover-icon"></i></button>
            <div class="scrollbar" data-simplebar>
                 <ul class="navbar-nav" id="navbar-nav">
                      <li class="menu-title">Menu Notaire</li>
                      <li class="nav-item"><a class="nav-link" href="notaire-dashboard.php"><span class="nav-icon"><i class="ri-dashboard-line"></i></span><span class="nav-text">Tableau de Bord</span></a></li>
                      <!-- *** Lien Actif pour cette page *** -->
                      <li class="nav-item"><a class="nav-link active" href="notaire-gestion-biens.php"><span class="nav-icon"><i class="ri-community-line"></i></span><span class="nav-text">Gestion Biens</span></a></li>
                      <li class="nav-item"><a class="nav-link" href="notaire-gestion-locataires.php"><span class="nav-icon"><i class="ri-group-line"></i></span><span class="nav-text">Gestion Locataires</span></a></li>
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
                 <div class="row"> <div class="col-12"> <div class="page-title-box"> <h4 class="mb-0 fw-semibold">Gestion de Mes Biens Immobiliers</h4> <ol class="breadcrumb mb-0"> <li class="breadcrumb-item"><a href="notaire-dashboard.php">Espace Notaire</a></li> <li class="breadcrumb-item active">Mes Biens</li> </ol> </div> </div> </div>
                 <div id="pageAlertPlaceholder"> <?php foreach ($pageAlerts as $alert): ?> <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show"> <?php echo htmlspecialchars($alert['message']); ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button> </div> <?php endforeach; ?> </div>

                 <!-- Optionnel: Filtres si beaucoup de biens -->
                 <!-- <div class="row mb-3"> ... Formulaire de filtre par adresse, statutLocation ... </div> -->

                <!-- Tableau des Biens du Notaire -->
                <div class="row"> <div class="col-12"> <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Biens Immobiliers sous Votre Gestion</h5></div>
                    <div class="card-body p-0"> <div class="table-responsive">
                        <table id="tableGestionBiensNotaire" class="table table-hover table-centered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Image</th>
                                    <th>Adresse</th>
                                    <th>Propriétaire</th>
                                    <th>Loyer (FCFA)</th>
                                    <th>Statut Location</th>
                                    <th>Statut Supervision</th> <!-- Gardé pour info -->
                                    <th style="width: 150px; text-align: center;">Actions</th> <!-- Ajusté pour les nouvelles actions -->
                                </tr>
                            </thead>
                            <tbody id="gestionBiensTableBody">
                                <?php if (empty($biens_notaire)): ?>
                                    <tr id="noResultsRowGestionBiens">
                                        <td colspan="7" class="text-center text-muted py-3">Aucun bien immobilier n'est actuellement associé à votre étude via un contrat.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($biens_notaire as $bien):
                                        // Préparation des données d'affichage
                                        $imageServerPath = !empty($bien['image_profil']) ? $uploadDirServer . $bien['image_profil'] : null;
                                        $imagePath = ($imageServerPath && file_exists($imageServerPath)) ? $uploadDirRelative . htmlspecialchars($bien['image_profil']) : $defaultImage;
                                        $loyerFormatte = number_format($bien['loyerMensuel'] ?? 0, 0, ',', ' ');
                                        $statutLocation = htmlspecialchars($bien['statutLocation'] ?? 'Inconnu');
                                        $statutSupervision = htmlspecialchars($bien['supervisionStatut'] ?? 'Inconnu');

                                        // Badge pour statut de location
                                        $badgeLocationClass = 'bg-secondary';
                                        if ($statutLocation === 'Libre') $badgeLocationClass = 'bg-success';
                                        elseif ($statutLocation === 'Occupé') $badgeLocationClass = 'bg-warning text-dark';

                                         // Badge pour statut de supervision (informatif)
                                        $badgeSupervisionClass = 'bg-light text-dark';
                                        if ($statutSupervision === 'Validé') $badgeSupervisionClass = 'bg-success';
                                        elseif ($statutSupervision === 'Suspendu') $badgeSupervisionClass = 'bg-danger';
                                        elseif ($statutSupervision === 'En attente') $badgeSupervisionClass = 'bg-info text-dark';

                                        // URLs pour les actions
                                        $detailsUrl = "details_bien.php?id=" . $bien['idBien']; // Lien vers la page publique de détail
                                        $contratsUrl = "notaire-gestion-contrats.php?searchTerm=" . urlencode('bien_id:' . $bien['idBien']); // Exemple de filtre
                                        // L'URL de modification pourrait ouvrir un modal ou lier vers une page dédiée
                                        // $modifierUrl = "notaire-modifier-bien.php?id=" . $bien['idBien'];
                                    ?>
                                    <tr id="bienRowNotaire-<?php echo $bien['idBien']; ?>">
                                        <td><img src="<?php echo $imagePath; ?>" alt="Img Bien" class="img-thumbnail-tiny" onerror="this.onerror=null; this.src='<?php echo $defaultImage; ?>';"></td>
                                        <td><?php echo htmlspecialchars($bien['adresse']); ?></td>
                                        <td><small><?php echo htmlspecialchars(($bien['propPrenom'] ?? '') . ' ' . ($bien['propNom'] ?? 'N/A')); ?></small></td>
                                        <td><?php echo $loyerFormatte; ?></td>
                                        <td><span class="badge <?php echo $badgeLocationClass; ?>"><?php echo $statutLocation; ?></span></td>
                                        <td><span class="badge <?php echo $badgeSupervisionClass; ?>"><?php echo $statutSupervision; ?></span></td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group" aria-label="Actions Bien">
                                                <a href="<?php echo $detailsUrl; ?>" target="_blank" class="btn btn-info" title="Voir Fiche Détail"><i class="ri-eye-line"></i></a>
                                                <a href="<?php echo $contratsUrl; ?>" class="btn btn-primary" title="Voir Contrats Associés"><i class="ri-file-list-3-line"></i></a>
                                                <!-- Optionnel: Bouton Modifier -->
                                                <!-- <button onclick="openModifierBienModal(<?php echo $bien['idBien']; ?>)" class="btn btn-warning" title="Modifier Informations"><i class="ri-pencil-line"></i></button> -->
                                            </div>
                                        </td>
                                    </tr>
                                   <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div> </div>
                </div> </div> </div>
            </div> <!-- container-fluid -->
        </div> <!-- page-content -->
        <!-- Footer -->
        <footer class="footer"> <div class="container-fluid"> <div class="row"> <div class="col-12 text-center"> <script>document.write(new Date().getFullYear())</script> © Gestion Locative Notariale. </div> </div> </div> </footer>
    </div> <!-- wrapper -->

    <!-- JS -->
    <script src="assets/js/vendor.js"></script>
    <script src="assets/js/app.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Pas de script JS spécifique nécessaire pour cette version simplifiée
        // Sauf si vous ajoutez des filtres ou un modal "Modifier"

        // Exemple: Fonction pour ouvrir un modal de modification (si vous l'ajoutez)
        /*
        window.openModifierBienModal = function(bienId) {
            console.log("Ouvrir modal modification pour bien ID:", bienId);
            // 1. Récupérer les données du bien via AJAX (nouvelle action PHP 'get_bien_details')
            // 2. Pré-remplir un formulaire dans un modal Bootstrap
            // 3. Afficher le modal
            // Exemple:
            // const modalElement = document.getElementById('modalModifierBien');
            // const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
            // // ... charger données et remplir formulaire ...
            // modal.show();
        }
        */
    </script>
</body>
</html>