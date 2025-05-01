<?php
// --- admin-supervision-biens.php (Version PDO) ---

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Administrateur') { header("Location: auth-signin.php"); exit; }

// Utilisation de PDO via db_connection.php
require_once 'db_connection.php'; // Assure-toi que $pdo est défini ici

$pageAlerts = [];
$defaultImage = 'assets/images/property/default.jpg';
$uploadDirRelative = 'uploads/biens/'; // Chemin pour l'affichage HTML
$uploadDirServer = __DIR__ . '/uploads/biens/'; // Chemin serveur (pour file_exists)

// --- GESTION ACTIONS AJAX (GET pour update_status - Version PDO) ---
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    $responseAjax = ['success' => false, 'message' => 'Action inconnue ou erreur serveur.'];
    $idBienAjax = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;

    if ($action === 'update_status' && $idBienAjax && isset($_GET['status'])) {
        $newStatus = $_GET['status'];
        $allowedStatuses = ['En attente', 'Validé', 'Suspendu'];

        if (!in_array($newStatus, $allowedStatuses)) {
            $responseAjax['message'] = 'Statut invalide fourni.';
        } else {
            try {
                $sql_update = "UPDATE bienimmobiliers SET supervisionStatut = :newStatus WHERE idBien = :idBien";
                $stmt_update = $pdo->prepare($sql_update);
                $stmt_update->bindParam(':newStatus', $newStatus, PDO::PARAM_STR);
                $stmt_update->bindParam(':idBien', $idBienAjax, PDO::PARAM_INT);
                $stmt_update->execute();

                if ($stmt_update->rowCount() > 0) { // Vérifie si au moins une ligne a été modifiée
                    $responseAjax['success'] = true;
                    $responseAjax['message'] = "Statut mis à jour avec succès.";
                    $responseAjax['newStatus'] = $newStatus;
                    // --- TODO : Implémenter l'envoi de notification ici ---
                    // Exemple: send_notification_to_proprietaire($pdo, $idBienAjax, $newStatus);
                } else {
                    // Soit l'ID n'existe pas, soit le statut était déjà celui demandé
                    $responseAjax['success'] = true; // Considéré comme succès car l'état désiré est atteint
                    $responseAjax['message'] = "Aucun changement nécessaire ou bien introuvable.";
                    // Pour être précis, on pourrait revérifier le statut actuel
                    $stmt_check = $pdo->prepare("SELECT supervisionStatut FROM bienimmobiliers WHERE idBien = :idBien");
                    $stmt_check->execute([':idBien' => $idBienAjax]);
                    $currentStatusAfterCheck = $stmt_check->fetchColumn();
                    $responseAjax['newStatus'] = $currentStatusAfterCheck ?: $newStatus; // Renvoyer le statut actuel si trouvé
                }
            } catch (PDOException $e) {
                error_log("Err PDO update_status bien: " . $e->getMessage());
                $responseAjax['message'] = 'Erreur base de données lors de la mise à jour.';
            }
        }
    } elseif ($action === 'update_status') {
        $responseAjax['message'] = 'ID du bien ou nouveau statut manquant.';
    }
    // Aucune fermeture explicite de connexion nécessaire pour PDO généralement

    echo json_encode($responseAjax);
    exit; // Fin script AJAX
}

// --- CHARGEMENT INITIAL DES BIENS (Version PDO) ---
$tous_les_biens = [];
try {
    // Utilisation de FIELD() pour trier par statut spécifique
    $sql_get_biens = "SELECT b.idBien, b.adresse, b.statut as statutLocation, b.loyerMensuel,
                             b.image_profil, b.supervisionStatut,
                             u.nom as propNom, u.prenom as propPrenom
                      FROM bienimmobiliers b
                      JOIN proprietaire p ON b.idProprietaire = p.idProprietaire
                      JOIN utilisateurs u ON p.idUser = u.idUser
                      ORDER BY FIELD(b.supervisionStatut, 'En attente', 'Validé', 'Suspendu'), b.idBien DESC";
    $stmt_get_biens = $pdo->query($sql_get_biens); // Simple query, pas de param user ici
    $tous_les_biens = $stmt_get_biens->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $pageAlerts[] = ['type' => 'danger', 'message' => 'Erreur lors du chargement des biens.'];
    error_log("Err PDO get_biens admin: " . $e->getMessage());
}

// Pas besoin de fermer $pdo explicitement
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <title>Supervision des Biens | Espace Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
    <script src="assets/js/config.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        /* Styles CSS identiques */
        .table th, .table td { vertical-align: middle; font-size: 0.875rem; }
        .table th { font-weight: 500; }
        .btn-sm i { font-size: 1rem; }
        .img-thumbnail-tiny { max-width: 60px; height: 40px; object-fit: cover; }
        .no-results-row td { text-align: center; font-style: italic; color: #6c757d; padding: 1rem; }
        .table td:last-child { text-align: right; } /* Aligner actions à droite */
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Topbar, Right Sidebar, App Menu (Admin - HTML inchangé, collez le vôtre) -->
         <header class=""> <div class="topbar"> <div class="container-fluid"> <div class="navbar-header"> <div class="d-flex align-items-center gap-2"> <div class="topbar-item"><button type="button" class="button-toggle-menu topbar-button"><i class="ri-menu-2-line fs-24"></i></button></div> <form class="app-search d-none d-md-block me-auto"><div class="position-relative"><input type="search" class="form-control border-0" placeholder="Rechercher..." autocomplete="off" value=""><i class="ri-search-line search-widget-icon"></i></div></form> </div> <div class="d-flex align-items-center gap-1"> <div class="topbar-item"><button type="button" class="topbar-button" id="light-dark-mode"><i class="ri-moon-line fs-24 light-mode"></i><i class="ri-sun-line fs-24 dark-mode"></i></button></div> <div class="dropdown topbar-item d-none d-lg-flex"><button type="button" class="topbar-button" data-toggle="fullscreen"><i class="ri-fullscreen-line fs-24 fullscreen"></i><i class="ri-fullscreen-exit-line fs-24 quit-fullscreen"></i></button></div> <div class="dropdown topbar-item"> <button type="button" class="topbar-button position-relative" id="page-header-notifications-dropdown" data-bs-toggle="dropdown"><i class="ri-notification-3-line fs-24"></i><span id="topbarUnreadCount" class="position-absolute topbar-badge fs-10 translate-middle badge bg-danger rounded-pill">0<span class="visually-hidden">unread</span></span></button> <div class="dropdown-menu py-0 dropdown-lg dropdown-menu-end"></div> </div> <div class="topbar-item d-none d-md-flex"><button type="button" class="topbar-button" id="theme-settings-btn" data-bs-toggle="offcanvas" data-bs-target="#theme-settings-offcanvas"><i class="ri-settings-4-line fs-24"></i></button></div> <div class="dropdown topbar-item"> <a type="button" class="topbar-button" id="page-header-user-dropdown" data-bs-toggle="dropdown"><span class="d-flex align-items-center"><img class="rounded-circle" width="32" src="assets/images/users/avatar-admin.png" alt="admin"></span></a> <div class="dropdown-menu dropdown-menu-end"> <h6 class="dropdown-header">Admin <?php echo htmlspecialchars($_SESSION['user_prenom'] ?? ''); ?></h6> <a class="dropdown-item" href="#admin-profil"><i class="ri-user-line me-1"></i> Profil</a> <div class="dropdown-divider"></div> <a class="dropdown-item text-danger" href="auth-signin.php?logout=1"><i class="ri-logout-box-line me-1"></i> Déconnexion</a> </div> </div> </div> </div> </div> </div> </header>
         <div> <div class="offcanvas offcanvas-end border-0 rounded-start-4 overflow-hidden" tabindex="-1" id="theme-settings-offcanvas"> <div class="d-flex align-items-center bg-primary p-3 offcanvas-header"><h5 class="text-white m-0">Theme Settings</h5><button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="offcanvas"></button></div><div class="offcanvas-body p-0"><div data-simplebar class="h-100"><div class="p-3 settings-bar"></div></div></div><div class="offcanvas-footer border-top p-3 text-center"><button type="button" class="btn btn-danger w-100" id="reset-layout">Reset</button></div> </div> </div>
          <div class="main-nav">
             <div class="logo-box"> <a href="admin-dashboard.php" class="logo-dark"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-dark.png" class="logo-lg"></a> <a href="admin-dashboard.php" class="logo-light"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-light.png" class="logo-lg"></a> </div>
             <button type="button" class="button-sm-hover" aria-label="Show Full Sidebar"><i class="ri-menu-2-line fs-24 button-sm-hover-icon"></i></button>
             <div class="scrollbar" data-simplebar>
                  <ul class="navbar-nav" id="navbar-nav">
                       <li class="menu-title">Menu Admin</li>
                       <li class="nav-item"><a class="nav-link" href="admin-dashboard.php"><span class="nav-icon"><i class="ri-dashboard-line"></i></span><span class="nav-text">Dashboard</span></a></li>
                        <!-- Lien corrigé pour la page de gestion utilisateurs -->
                       <li class="nav-item"><a class="nav-link" href="admin-gestion-utilisateurs.php"><span class="nav-icon"><i class="ri-account-box-line"></i></span><span class="nav-text">Gestion Utilisateurs</span></a></li>
                       <li class="nav-item"><a class="nav-link" href="admin-supervision-biens.php"><span class="nav-icon"><i class="ri-building-line"></i></span><span class="nav-text">Supervision Biens</span></a></li>
                  </ul>
             </div>
        </div>


        <div class="page-content">
            <div class="container-fluid">
                <!-- Titre, Alertes (HTML inchangé) -->
                <div class="row"> <div class="col-12"> <div class="page-title-box"> <h4 class="mb-0 fw-semibold">Supervision des Biens Immobiliers</h4> <ol class="breadcrumb mb-0"> <li class="breadcrumb-item"><a href="admin-dashboard.php">Admin</a></li> <li class="breadcrumb-item active">Supervision Biens</li> </ol> </div> </div> </div>
                 <div id="pageAlertPlaceholder"> <?php foreach ($pageAlerts as $alert): ?> <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show"> <?php echo htmlspecialchars($alert['message']); ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button> </div> <?php endforeach; ?> </div>

                <!-- Tableau des Biens (HTML ajusté pour aligner actions) -->
                <div class="row"> <div class="col-12"> <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Biens Immobiliers Soumis</h5></div>
                    <div class="card-body p-0"> <div class="table-responsive">
                        <table id="tableSupervisionBiens" class="table table-hover table-centered mb-0">
                            <thead class="table-light"><tr><th>Image</th><th>Adresse</th><th>Propriétaire</th><th>Loyer (FCFA)</th><th>Statut Supervision</th><th style="width: 180px; text-align: right;">Actions</th></tr></thead>
                            <tbody id="supervisionTableBody">
                                <tr id="noResultsRowSupervision" <?php echo !empty($tous_les_biens) ? 'style="display: none;"' : ''; ?>> <td colspan="6" class="text-center text-muted py-3">Aucun bien trouvé.</td> </tr>
                                <?php foreach ($tous_les_biens as $bien):
                                    // Vérifier si l'image existe avant de générer le chemin
                                    $imageServerPath = !empty($bien['image_profil']) ? $uploadDirServer . $bien['image_profil'] : null;
                                    $imagePath = ($imageServerPath && file_exists($imageServerPath)) ? $uploadDirRelative . htmlspecialchars($bien['image_profil']) : $defaultImage;
                                    $loyerFormatte = number_format($bien['loyerMensuel'] ?? 0, 0, ',', ' ');
                                    $statutActuel = htmlspecialchars($bien['supervisionStatut'] ?? 'Inconnu');
                                    $badgeClass = 'bg-secondary';
                                    $actionsHtml = '';

                                    switch ($statutActuel) {
                                        case 'En attente':
                                            $badgeClass = 'bg-info text-dark'; // Améliorer contraste
                                            $actionsHtml = '<button onclick="updateBienStatus('.$bien['idBien'].', \'Validé\')" class="btn btn-sm btn-success me-1" title="Valider"><i class="ri-check-line"></i></button> ' .
                                                           '<button onclick="updateBienStatus('.$bien['idBien'].', \'Suspendu\')" class="btn btn-sm btn-danger" title="Suspendre"><i class="ri-close-line"></i></button>';
                                            break;
                                        case 'Validé':
                                            $badgeClass = 'bg-success';
                                            $actionsHtml = '<button onclick="updateBienStatus('.$bien['idBien'].', \'Suspendu\')" class="btn btn-sm btn-warning" title="Suspendre"><i class="ri-stop-circle-line"></i></button>';
                                            break;
                                        case 'Suspendu':
                                            $badgeClass = 'bg-danger';
                                            $actionsHtml = '<button onclick="updateBienStatus('.$bien['idBien'].', \'Validé\')" class="btn btn-sm btn-success" title="Réactiver/Valider"><i class="ri-play-circle-line"></i></button>';
                                            break;
                                    }
                                ?>
                                <tr id="bienRowAdmin-<?php echo $bien['idBien']; ?>">
                                    <td><img src="<?php echo $imagePath; ?>" alt="Img Bien" class="img-thumbnail-tiny" onerror="this.onerror=null; this.src='<?php echo $defaultImage; ?>';"></td>
                                    <td><?php echo htmlspecialchars($bien['adresse']); ?></td>
                                    <td><small><?php echo htmlspecialchars(($bien['propPrenom'] ?? '') . ' ' . ($bien['propNom'] ?? '')); ?></small></td>
                                    <td><?php echo $loyerFormatte; ?></td>
                                    <td><span class="badge <?php echo $badgeClass; ?> status-badge"><?php echo $statutActuel; ?></span></td>
                                    <td><div class="d-flex gap-1 justify-content-end action-buttons"><?php echo $actionsHtml; ?></div></td>
                                </tr>
                               <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div> </div>
                </div> </div> </div>
            </div> <!-- container-fluid -->
        </div> <!-- page-content -->
        <!-- Footer (HTML inchangé) -->
        <footer class="footer"> <div class="container-fluid"> <div class="row"> <div class="col-12 text-center"> <script>document.write(new Date().getFullYear())</script> © Gestion Locative Notariale. </div> </div> </div> </footer>
    </div> <!-- wrapper -->

    <!-- JS (inchangé car la logique AJAX reste la même) -->
    <script src="assets/js/vendor.js"></script>
    <script src="assets/js/app.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // --- Fonction Globale pour màj statut (inchangée) ---
        window.updateBienStatus = function(bienId, newStatus) {
            const actionText = {'Validé':'Valider','Suspendu':'Suspendre'}; const verb=actionText[newStatus]||`changer statut en '${newStatus}'`;
            const swalWithBootstrapButtons = Swal.mixin({ customClass: { confirmButton: 'btn btn-success ms-2', cancelButton: 'btn btn-secondary' }, buttonsStyling: false }); // Couleurs standard ici

            swalWithBootstrapButtons.fire({
                title: `Confirmer`,
                text: `Voulez-vous ${verb} le bien ID ${bienId} ?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: `Oui, ${verb}`,
                cancelButtonText: 'Annuler',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    // Pas de spinner pour une action rapide
                    fetch(`<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?action=update_status&id=${bienId}&status=${encodeURIComponent(newStatus)}`)
                        .then(response => response.ok ? response.json() : Promise.reject('Network error'))
                        .then(data => {
                            if (data.success) {
                                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: data.message || 'Statut mis à jour!', showConfirmButton: false, timer: 2500 });
                                const row = document.getElementById(`bienRowAdmin-${bienId}`);
                                if (row && data.newStatus) updateRowUI(row, data.newStatus); // Fonction renommée pour clarté
                            } else {
                                Swal.fire('Erreur', data.message || 'Mise à jour échouée.', 'error');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error); Swal.fire('Erreur', 'Erreur réseau ou réponse invalide.', 'error');
                        });
                }
            });
        }

        // --- Fonction MàJ ligne UI (anciennement updateRowStatus) ---
        function updateRowUI(tableRow, newStatus) {
            const badge = tableRow.querySelector('.status-badge');
            const actionCell = tableRow.querySelector('.action-buttons');
            const bienId = tableRow.id.split('-')[1];
            if (!badge || !actionCell || !bienId) return;

            let badgeClass = 'bg-secondary';
            let actionsHtml = '';

            switch (newStatus) {
                case 'En attente':
                    badgeClass = 'bg-info text-dark';
                    actionsHtml = `<button onclick="updateBienStatus(${bienId}, 'Validé')" class="btn btn-sm btn-success me-1" title="Valider"><i class="ri-check-line"></i></button> ` +
                                  `<button onclick="updateBienStatus(${bienId}, 'Suspendu')" class="btn btn-sm btn-danger" title="Suspendre"><i class="ri-close-line"></i></button>`;
                    break;
                case 'Validé':
                    badgeClass = 'bg-success';
                    actionsHtml = `<button onclick="updateBienStatus(${bienId}, 'Suspendu')" class="btn btn-sm btn-warning" title="Suspendre"><i class="ri-stop-circle-line"></i></button>`;
                    break;
                case 'Suspendu':
                    badgeClass = 'bg-danger';
                    actionsHtml = `<button onclick="updateBienStatus(${bienId}, 'Validé')" class="btn btn-sm btn-success" title="Réactiver/Valider"><i class="ri-play-circle-line"></i></button>`;
                    break;
                 default: // Au cas où un statut inconnu serait renvoyé
                     badgeClass = 'bg-secondary';
                     actionsHtml = '<span>Statut inconnu</span>';
                     break;
            }
            badge.className = `badge ${badgeClass} status-badge`;
            badge.textContent = newStatus;
            actionCell.innerHTML = actionsHtml;
         }

        // --- Vérif table vide ---
        function checkIfTableIsEmptyAdmin() {
            const tableBody = document.getElementById('supervisionTableBody');
            const noResultsRow = document.getElementById('noResultsRowSupervision');
            if(tableBody && noResultsRow) {
                noResultsRow.style.display = tableBody.querySelectorAll('tr:not(#noResultsRowSupervision)').length === 0 ? 'table-row' : 'none';
            }
         }
        document.addEventListener('DOMContentLoaded', function() {
             checkIfTableIsEmptyAdmin(); // Vérifier au chargement initial
             // Ajouter un observer si la table est modifiée dynamiquement par autre chose que notre script
             // const observer = new MutationObserver(checkIfTableIsEmptyAdmin);
             // const tableBody = document.getElementById('supervisionTableBody');
             // if(tableBody) observer.observe(tableBody, { childList: true });
         });
    </script>
</body>
</html>