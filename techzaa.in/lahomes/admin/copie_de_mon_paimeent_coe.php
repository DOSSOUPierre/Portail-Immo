 
 
<?php
// --- locataire-paiements.php (Adapté BDD & Corrigé Appel MF v2) ---

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. Vérification Authentification et Rôle Locataire
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'Locataire') {
    $_SESSION['error_message'] = "Accès non autorisé. Veuillez vous connecter.";
    header("Location: auth-signin.php");
    exit;
}

require_once 'db_connection.php'; // $pdo

// 2. Récupérer l'ID du Locataire et ses informations de base
$user_id = $_SESSION['user_id'];
$locataire = null;
$contrat_actif = null;
$montant_du = 0;
$historique_paiements = [];
$pageAlerts = [];
$errorMessage = '';
$debugMessage = '';
$locataire_info = []; // Initialiser pour éviter les erreurs si locataire non trouvé
$locataire_nom_complet = 'Locataire'; // Valeur par défaut
$locataire_tel_mf = ''; // Valeur par défaut

// Fonction pour récupérer l'ID Locataire lié à l'ID User (utilise ta table)
function get_locataire_id_from_user(PDO $pdo, int $userId): ?int {
    try {
        $stmt = $pdo->prepare("SELECT idLocataire FROM locataire WHERE idUser = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() ?: null;
    } catch (PDOException $e) { error_log("Erreur get_locataire_id_from_user: " . $e->getMessage()); return null; }
}

$locataire_id = get_locataire_id_from_user($pdo, $user_id);

if ($locataire_id === null) {
    $errorMessage = "Impossible de trouver les informations du locataire associé à ce compte.";
    $pageAlerts[] = ['type' => 'danger', 'message' => $errorMessage];
} else {
    try {
        // Récupérer les infos de base du locataire
        $stmtLocataire = $pdo->prepare("SELECT u.prenom, u.nom, u.email, l.telephone FROM utilisateurs u JOIN locataire l ON u.idUser = l.idUser WHERE u.idUser = ?");
        $stmtLocataire->execute([$user_id]);
        $locataire_info = $stmtLocataire->fetch(PDO::FETCH_ASSOC);
        if (!$locataire_info) { throw new Exception("Informations utilisateur locataire non trouvées."); }
        $locataire_nom_complet = trim(($locataire_info['prenom'] ?? '') . ' ' . ($locataire_info['nom'] ?? 'Locataire'));
        $locataire_tel_mf = preg_replace('/[^0-9]/', '', $locataire_info['telephone'] ?? '');

        // Récupérer le contrat ACTIF
        $stmtContrat = $pdo->prepare("SELECT idContrat, montantLoyer FROM contrat WHERE idLocataire = ? AND statutContrat = 'Actif' ORDER BY dateDebut DESC LIMIT 1");
        $stmtContrat->execute([$locataire_id]);
        $contrat_actif = $stmtContrat->fetch(PDO::FETCH_ASSOC);

        // Définir le montant dû
        if ($contrat_actif && isset($contrat_actif['montantLoyer'])) { $montant_du = (float)$contrat_actif['montantLoyer']; }
        else { $montant_du = 0; if (empty($errorMessage)) { $pageAlerts[] = ['type' => 'info', 'message' => 'Aucun contrat de location actif trouvé.']; } }

        // Récupérer l'historique des paiements (table 'paiementloyer')
        $annee_filtre = isset($_GET['annee']) && is_numeric($_GET['annee']) ? (int)$_GET['annee'] : null;

        // *** CORRECTION: Retire 'motif' du SELECT ***
        $sqlHistorique = "
            SELECT idPaiement, datePaiement, typePaiement, montant, statutPaiement
            FROM paiementloyer
            WHERE idContrat IN (SELECT idContrat FROM contrat WHERE idLocataire = :idLocataire)
            ";
        $paramsHistorique = [':idLocataire' => $locataire_id];

        if ($annee_filtre) {
            $sqlHistorique .= " AND YEAR(datePaiement) = :annee ";
            $paramsHistorique[':annee'] = $annee_filtre;
        }

        $sqlHistorique .= " ORDER BY datePaiement DESC LIMIT 20";

        $stmtHistorique = $pdo->prepare($sqlHistorique);
        $stmtHistorique->execute($paramsHistorique);
        $historique_paiements = $stmtHistorique->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        // ... (Gestion des erreurs inchangée) ...
        $errorMessage = 'Erreur base de données lors du chargement de l\'historique.'; // Message plus précis
        error_log("Err PDO locataire-paiements historique UserID {$user_id}: " . $e->getMessage());
        $pageAlerts[] = ['type' => 'danger', 'message' => $errorMessage];
    }
    // ... (Fin du bloc try...catch et else inchangés) ...
}

// --- Gestion Action Paiement Espece (via POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'process_payment_espece') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Erreur traitement paiement espèce.'];
    if ($locataire_id === null) { $response['message'] = "Locataire non identifié."; echo json_encode($response); exit; }
    $montantPaye = filter_input(INPUT_POST, 'montant', FILTER_VALIDATE_FLOAT);
    $motifPaiement = !empty($_POST['motif']) ? trim(htmlspecialchars($_POST['motif'])) : 'Paiement déclaré en espèce';
    $contratIdAssocie = $contrat_actif ? $contrat_actif['idContrat'] : null;
    if ($montantPaye === false || $montantPaye <= 0) { $response['message'] = "Montant invalide."; echo json_encode($response); exit; }
    try {
        // *** Vérifiez que votre table paiementloyer a bien une colonne 'motif' ou retirez-la de l'INSERT ***
        $sqlInsert = "INSERT INTO paiementloyer (idContrat, datePaiement, montant, typePaiement, methodePaiement, statutPaiement, motif) VALUES (:idContrat, NOW(), :montant, :typePaiement, 'Espèce', 'en attente', :motif)";
        $typeP = 'loyer'; if (stripos($motifPaiement, 'caution') !== false) $typeP = 'caution'; elseif (stripos($motifPaiement, 'avance') !== false) $typeP = 'avance';
        $stmtInsert = $pdo->prepare($sqlInsert);
        $success = $stmtInsert->execute([':idContrat' => $contratIdAssocie, ':montant' => $montantPaye, ':typePaiement' => $typeP, ':motif' => $motifPaiement]);
        if ($success) { $response = ['success' => true, 'message' => 'Votre déclaration de paiement en espèce a été enregistrée et est en attente de validation.']; }
        else { $response['message'] = "Échec de l'enregistrement."; }
    } catch (PDOException $e) { error_log("Erreur PDO paiement espèce UserID {$user_id}: " . $e->getMessage()); $response['message'] = "Erreur base de données."; }
     echo json_encode($response);
     exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <title>Paiements & Quittances | Espace Locataire</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Gestion des paiements et consultation des quittances pour les locataires." />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
    <script src="assets/js/config.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        /* Vos styles CSS ici */
        .payment-action-card .amount-due { font-size: 1.5rem; font-weight: 600; color: #dc3545; }
        .payment-action-card .amount-due.zero { color: #198754; }
        .payment-action-card .btn-pay { font-size: 1.1rem; }
        .table th, .table td { vertical-align: middle; font-size: 0.875rem;}
        .history-table th { font-weight: 500; }
        .history-table td { font-size: 0.875rem; }
        #paymentsHistoryBody .no-results-row td, #paymentsHistoryBody .error-row td { text-align: center; font-style: italic; color: #6c757d; padding: 1.5rem; }
        #paymentsHistoryBody .error-row td { color: var(--bs-danger); font-style: normal; font-weight: 500; }
        .btn.loading { position: relative; pointer-events: none; color: transparent !important; }
        .btn.loading::after { content: ''; position: absolute; top: 50%; left: 50%; width: 1rem; height: 1rem; margin-top: -0.5rem; margin-left: -0.5rem; border: 2px solid rgba(255, 255, 255, 0.6); border-top-color: #ffffff; border-radius: 50%; animation: button-spinner .6s linear infinite; }
        @keyframes button-spinner { to { transform: rotate(360deg); } }
        #paymentModalLabel { font-family: var(--font-titre); }
        #paymentModal .form-check-label { font-weight: 500; }
         body { padding-top: 70px; } @media (min-width: 992px) { body { padding-top: 80px; } }
         #payMoneyFusion + label img { vertical-align: middle; height: 20px; margin-right: 5px;}
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- ========== Topbar, Sidebar (Votre HTML Locataire) ========== -->
         <header class=""> <div class="topbar"> <div class="container-fluid"> <div class="navbar-header"> <div class="d-flex align-items-center gap-2"> <div class="topbar-item"><button type="button" class="button-toggle-menu topbar-button"><i class="ri-menu-2-line fs-24"></i></button></div> <form class="app-search d-none d-md-block me-auto"><div class="position-relative"><input type="search" class="form-control border-0" placeholder="Rechercher..." autocomplete="off" value=""><i class="ri-search-line search-widget-icon"></i></div></form> </div> <div class="d-flex align-items-center gap-1"> <div class="topbar-item"><button type="button" class="topbar-button" id="light-dark-mode"><i class="ri-moon-line fs-24 light-mode"></i><i class="ri-sun-line fs-24 dark-mode"></i></button></div> <div class="dropdown topbar-item d-none d-lg-flex"><button type="button" class="topbar-button" data-toggle="fullscreen"><i class="ri-fullscreen-line fs-24 fullscreen"></i><i class="ri-fullscreen-exit-line fs-24 quit-fullscreen"></i></button></div> <div class="dropdown topbar-item"> <button type="button" class="topbar-button position-relative" id="page-header-notifications-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="ri-notification-3-line fs-24"></i><span id="topbarUnreadCount" class="position-absolute topbar-badge fs-10 translate-middle badge bg-danger rounded-pill">0<span class="visually-hidden">notifications non lues</span></span></button> <div class="dropdown-menu py-0 dropdown-lg dropdown-menu-end" aria-labelledby="page-header-notifications-dropdown"> <div class="p-3 border-top-0 border-start-0 border-end-0 border-dashed border"><div class="row align-items-center"><div class="col"><h6 class="m-0 fs-16 fw-semibold"> Notifications</h6></div><div class="col-auto"><a href="javascript: void(0);" class="text-dark text-decoration-underline" onclick="markAllAsRead()"> <small>Marquer tout comme lu</small></a></div></div></div> <div data-simplebar style="max-height: 280px;" id="topbarNotificationList"></div> <div class="text-center py-3"><a href="locataire-notifications.php" class="btn btn-primary btn-sm">Voir toutes les notifications <i class="ri-arrow-right-line ms-1"></i></a></div> </div> </div> <div class="topbar-item d-none d-md-flex"><button type="button" class="topbar-button" id="theme-settings-btn" data-bs-toggle="offcanvas" data-bs-target="#theme-settings-offcanvas" aria-controls="theme-settings-offcanvas"><i class="ri-settings-4-line fs-24"></i></button></div> <div class="dropdown topbar-item"> <a type="button" class="topbar-button" id="page-header-user-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><span class="d-flex align-items-center"><img class="rounded-circle" width="32" src="assets/images/users/avatar-placeholder.png" alt="avatar-locataire"></span></a> <div class="dropdown-menu dropdown-menu-end"> <h6 class="dropdown-header" id="userDropdownHeader">Bienvenue <?= htmlspecialchars($locataire_info['prenom'] ?? 'Locataire') ?> !</h6> <a class="dropdown-item" href="locataire-profil.php"><i class="ri-user-line align-middle me-1"></i> <span class="align-middle">Mon Profil</span></a> <a class="dropdown-item active" href="locataire-paiements.php"><i class="ri-secure-payment-line align-middle me-1"></i> <span class="align-middle">Paiements & Quittances</span></a> <div class="dropdown-divider my-1"></div> <a class="dropdown-item text-danger" href="auth-signout.php"><i class="ri-logout-box-line align-middle me-1"></i> <span class="align-middle">Déconnexion</span></a> </div> </div> </div> </div> </div> </header>
         <div> <div class="offcanvas offcanvas-end border-0 rounded-start-4 overflow-hidden" tabindex="-1" id="theme-settings-offcanvas"> <div class="d-flex align-items-center bg-primary p-3 offcanvas-header"><h5 class="text-white m-0">Theme Settings</h5><button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="offcanvas" aria-label="Close"></button></div><div class="offcanvas-body p-0"><div data-simplebar class="h-100"><div class="p-3 settings-bar"></div></div></div><div class="offcanvas-footer border-top p-3 text-center"><div class="row"><div class="col"><button type="button" class="btn btn-danger w-100" id="reset-layout">Reset</button></div></div></div> </div> </div>
         <div class="main-nav"> <div class="logo-box"> <a href="locataire-dashboard.php" class="logo-dark"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-dark.png" class="logo-lg"></a> <a href="locataire-dashboard.php" class="logo-light"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-light.png" class="logo-lg"></a> </div> <button type="button" class="button-sm-hover" aria-label="Show Full Sidebar"><i class="ri-menu-2-line fs-24 button-sm-hover-icon"></i></button> <div class="scrollbar" data-simplebar> <ul class="navbar-nav" id="navbar-nav"> <li class="menu-title">Menu Locataire</li> <li class="nav-item"><a class="nav-link" href="locataire-dashboard.php"><span class="nav-icon"><i class="ri-dashboard-line"></i></span><span class="nav-text">Tableau de Bord</span></a></li> <li class="nav-item"><a class="nav-link" href="locataire-contrat.php"><span class="nav-icon"><i class="ri-file-text-line"></i></span><span class="nav-text">Mon Contrat</span></a></li> <li class="nav-item"><a class="nav-link active" href="locataire-paiements.php"><span class="nav-icon"><i class="ri-secure-payment-line"></i></span><span class="nav-text">Paiements & Quittances</span></a></li> <li class="nav-item"><a class="nav-link" href="locataire-maintenance.php"><span class="nav-icon"><i class="ri-tools-line"></i></span><span class="nav-text">Maintenance</span></a></li> <li class="nav-item"><a class="nav-link" href="locataire-notifications.php"><span class="nav-icon"><i class="ri-notification-3-line"></i></span><span class="nav-text">Notifications</span><span class="badge bg-danger badge-pill text-end" id="sidebarUnreadCount" style="display: none;"></span></a></li> </ul> </div> </div>
        <!-- ============================================================== -->

        <div class="page-content">
            <div class="container-fluid">
                <!-- Titre de Page -->
                <div class="row"> <div class="col-12"> <div class="page-title-box"> <h4 class="mb-0 fw-semibold">Paiements & Quittances</h4> <ol class="breadcrumb mb-0"> <li class="breadcrumb-item"><a href="locataire-dashboard.php">Espace Locataire</a></li> <li class="breadcrumb-item active">Paiements</li> </ol> </div> </div> </div>
                 <div id="pageAlertPlaceholder"> <?php foreach ($pageAlerts as $alert): ?> <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show"> <?php echo htmlspecialchars($alert['message']); ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button> </div> <?php endforeach; ?> </div>

                <!-- Section Effectuer un Paiement -->
                <div class="row">
                    <div class="col-12">
                        <div class="card payment-action-card">
                             <div class="card-body text-center">
                                 <h5 class="card-title">Prochain Paiement</h5>
                                 <p class="text-muted mb-2">Montant estimé dû :</p>
                                 <div class="amount-due my-3 <?= $montant_du <= 0 ? 'zero' : '' ?>">
                                     <span id="amountDueValue" data-amount="<?= htmlspecialchars($montant_du) ?>">
                                         <?= number_format($montant_du, 0, ',', ' ') ?> FCFA
                                     </span>
                                 </div>
                                 <button type="button" id="payNowBtnTrigger" class="btn btn-success btn-pay <?= $montant_du <= 0 ? 'disabled' : '' ?>"
                                         data-bs-toggle="modal" data-bs-target="#paymentModal"
                                         <?= $montant_du <= 0 ? 'aria-disabled="true"' : '' ?>>
                                     <i class="ri-secure-payment-fill me-1"></i> Effectuer un Paiement
                                 </button>
                                 <p id="paymentMessage" class="mt-3 text-muted small" style="<?= $montant_du <= 0 && empty($errorMessage) ? '' : 'display: none;' ?>">
                                     Vos paiements semblent à jour.
                                 </p>
                             </div>
                        </div>
                    </div>
                </div>

                <!-- Historique des Paiements -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Historique des Paiements</h5>
                                <form id="filterPaymentsForm" class="ms-auto d-flex align-items-center" method="GET" action="locataire-paiements.php">
                                    <label for="filterYear" class="form-label me-2 mb-0 small">Année :</label>
                                    <select class="form-select form-select-sm" id="filterYear" name="annee" style="width: auto;" onchange="this.form.submit()">
                                        <option value="" <?= empty($_GET['annee']) ? 'selected' : '' ?>>Toutes</option>
                                        <?php $currentY = date("Y"); for ($y = $currentY; $y >= $currentY - 5; $y--) { $selected = (isset($_GET['annee']) && $_GET['annee'] == $y) ? 'selected' : ''; echo "<option value=\"$y\" $selected>$y</option>"; } ?>
                                    </select>
                                </form>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table id="paymentsHistoryTable" class="table table-hover table-centered history-table mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date Paiement</th>
                                                <th>Type</th>
                                                <th>Motif</th>
                                                <th class="text-end">Montant (FCFA)</th>
                                                <th>Statut</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="paymentsHistoryBody">
                                            <?php if (!empty($errorMessage) && empty($historique_paiements)): ?>
                                                <tr class="error-row"><td colspan="6"><?= htmlspecialchars($errorMessage) ?></td></tr>
                                            <?php elseif (empty($historique_paiements)): ?>
                                                <tr class="no-results-row"><td colspan="6">Aucun paiement enregistré <?= isset($_GET['annee']) ? 'pour l\'année ' . htmlspecialchars($_GET['annee']) : 'pour le moment' ?>.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($historique_paiements as $p):
                                                    $formattedDate = $p['datePaiement'] ? date("d/m/Y", strtotime($p['datePaiement'])) : '-'; // Format Date seulement
                                                    $formattedAmount = number_format($p['montant'] ?? 0, 0, ',', ' ');
                                                    $typePaiement = htmlspecialchars($p['typePaiement'] ?? 'N/A');
                                                    $statutPaiement = htmlspecialchars($p['statutPaiement'] ?? 'Indéfini');
                                                    $motif = htmlspecialchars($p['motif'] ?? '-');

                                                    $typeBadge = '<span class="badge bg-secondary">' . $typePaiement . '</span>';
                                                    if ($typePaiement) { switch (strtolower($typePaiement)) { case 'loyer': $typeBadge = '<span class="badge bg-info">Loyer</span>'; break; case 'caution': $typeBadge = '<span class="badge bg-warning text-dark">Caution</span>'; break; case 'avance': $typeBadge = '<span class="badge bg-primary">Avance</span>'; break; } }
                                                    $statusBadge = '<span class="badge bg-secondary">Indéfini</span>'; $downloadDisabled = true;
                                                    if ($statutPaiement) { switch (strtolower($statutPaiement)) { case 'validé': $statusBadge = '<span class="badge bg-success">Validé</span>'; $downloadDisabled = false; break; case 'en attente': case 'en attente validation': $statusBadge = '<span class="badge bg-warning text-dark">En attente</span>'; break; case 'échoué': case 'refusé': $statusBadge = '<span class="badge bg-danger">Échoué</span>'; break; } }
                                                ?>
                                                    <tr>
                                                        <td><?= $formattedDate ?></td>
                                                        <td><?= $typeBadge ?></td>
                                                        <td><?= $motif ?></td>
                                                        <td class="text-end"><?= $formattedAmount ?></td>
                                                        <td><?= $statusBadge ?></td>
                                                        <td><button class="btn btn-sm btn-outline-primary" title="Télécharger Quittance" onclick="downloadQuittance(<?= $p['idPaiement'] ?>)" <?= $downloadDisabled ? 'disabled' : '' ?>><i class="ri-download-2-line"></i></button></td>
                                                   </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="card-footer bg-white border-top d-flex justify-content-between align-items-center"> <div class="fs-sm text-muted"> Total: <?= count($historique_paiements) ?> paiement(s) affiché(s) </div> <!-- Pagination --> </div>
                        </div>
                    </div>
                </div>

            </div> <!-- container-fluid -->
        </div> <!-- page-content -->

        <!-- ========== MODAL DE PAIEMENT ========== -->
        <div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="paymentModalLabel">Effectuer un Paiement</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="modalPaymentError" class="alert alert-danger d-none" role="alert"></div>
                        <form id="formModalPaiement" novalidate>
                             <div class="mb-3">
                                 <label for="paymentAmount" class="form-label">Montant à Payer (FCFA) <span class="text-danger">*</span></label>
                                 <input type="number" class="form-control" id="paymentAmount" name="montant" value="<?= htmlspecialchars($montant_du) ?>" required min="1" step="any">
                                 <div class="invalid-feedback">Veuillez entrer un montant valide.</div>
                             </div>
                             <div class="mb-3">
                                 <label for="paymentMotif" class="form-label">Motif du Paiement <span class="text-danger">*</span></label>
                                 <input type="text" class="form-control" id="paymentMotif" name="motif" required placeholder="Ex: Loyer Mois Année, Caution...">
                                 <div class="invalid-feedback">Veuillez préciser le motif.</div>
                             </div>
                             <div class="mb-3">
                                 <label class="form-label mb-2">Moyen de Paiement <span class="text-danger">*</span></label>
                                 <div class="form-check">
                                     <input class="form-check-input" type="radio" name="moyenPaiement" id="payMoneyFusion" value="moneyfusion" required checked>
                                     <label class="form-check-label" for="payMoneyFusion">
                                         <img src="assets/images/payment/moneyfusion_logo.png" alt="Money Fusion" height="20" class="me-1 align-middle"> Money Fusion (Mobile Money / Carte)
                                     </label>
                                 </div>
                                 <div class="form-check">
                                     <input class="form-check-input" type="radio" name="moyenPaiement" id="payEspece" value="espece" required>
                                     <label class="form-check-label" for="payEspece">
                                         <i class="ri-hand-coin-line me-1 align-middle"></i> Espèce (Déclaration)
                                     </label>
                                      <div class="invalid-feedback">Veuillez choisir un moyen de paiement.</div>
                                 </div>
                             </div>
                             <p id="especeInfo" class="form-text text-muted small" style="display: none;">
                                 En choisissant "Espèce", vous déclarez avoir effectué le paiement. Celui-ci sera en attente de validation par le notaire.
                             </p>
                        </form>
                    </div>
                    <div class="modal-footer justify-content-between">
                         <span id="modalLoadingSpinner" style="display: none;"><span class="spinner-border spinner-border-sm text-primary"></span> Traitement...</span>
                        <div>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                            <button type="button" class="btn btn-success" id="modalSubmitPaymentBtn">Valider</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- ========== FIN MODAL DE PAIEMENT ========== -->


        <!-- Footer -->
        <footer class="footer"> <div class="container-fluid"> <div class="row"> <div class="col-12 text-center"> <script>document.write(new Date().getFullYear())</script> © Gestion Locative Notariale. </div> </div> </div> </footer>
    </div> <!-- wrapper -->

    <!-- JS Vendor, Libs et App -->
    <script src="assets/js/vendor.js"></script>
    <script src="assets/js/app.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Axios pour Money Fusion -->
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>

    <!-- ============================================================== -->
    <!-- SCRIPT PERSONNALISÉ pour Paiements Locataire (API MF comme exemple simple) -->
    <!-- ============================================================== -->
    <script>
        // Fonction globale pour télécharger quittance
        window.downloadQuittance = function(paiementId) {
            const url = `backend/download_quittance.php?idPaiement=${paiementId}`;
            Swal.fire('Info', 'Fonctionnalité de téléchargement à implémenter (URL: ' + url + ')', 'info');
        }

        document.addEventListener('DOMContentLoaded', function() {
            const paymentModalEl = document.getElementById('paymentModal');
            const paymentModal = bootstrap.Modal.getOrCreateInstance(paymentModalEl);
            const formModal = document.getElementById('formModalPaiement');
            const modalErrorDiv = document.getElementById('modalPaymentError');
            const modalSubmitBtn = document.getElementById('modalSubmitPaymentBtn');
            const modalLoadingSpinner = document.getElementById('modalLoadingSpinner');
            const amountInput = document.getElementById('paymentAmount');
            const motifInput = document.getElementById('paymentMotif');
            const especeRadio = document.getElementById('payEspece');
            const moneyFusionRadio = document.getElementById('payMoneyFusion');
            const especeInfoPara = document.getElementById('especeInfo');

            // --- Pré-remplissage Motif ---
            if (motifInput && !motifInput.value) {
                 const now = new Date(); const monthNames = ["Janvier", "Février", "Mars", "Avril", "Mai", "Juin", "Juillet", "Août", "Septembre", "Octobre", "Novembre", "Décembre"]; const currentMonthYear = `${monthNames[now.getMonth()]} ${now.getFullYear()}`; motifInput.value = `Loyer ${currentMonthYear}`;
            }
            // Pré-remplissage Montant
            const initialAmountDue = parseFloat(document.getElementById('amountDueValue')?.dataset.amount || 0);
            if (amountInput && initialAmountDue > 0) amountInput.value = initialAmountDue;

            // --- Afficher/Masquer Info Espèce + Texte Bouton ---
            function toggleEspeceInfo() { /* ... (code inchangé) ... */ especeInfoPara.style.display = especeRadio.checked ? 'block' : 'none'; modalSubmitBtn.textContent = especeRadio.checked ? 'Confirmer Paiement Espèce' : 'Payer via Money Fusion'; modalSubmitBtn.classList.toggle('btn-warning', especeRadio.checked); modalSubmitBtn.classList.toggle('btn-success', !especeRadio.checked); }
             especeRadio.addEventListener('change', toggleEspeceInfo);
             moneyFusionRadio.addEventListener('change', toggleEspeceInfo);
             toggleEspeceInfo();

             // --- Soumission Modal ---
             modalSubmitBtn.addEventListener('click', function() { /* ... (code inchangé) ... */ if (!formModal.checkValidity()) { formModal.classList.add('was-validated'); displayModalError("Veuillez remplir tous les champs requis."); return; } formModal.classList.remove('was-validated'); clearModalError(); const moyenPaiement = formModal.querySelector('input[name="moyenPaiement"]:checked').value; if (moyenPaiement === 'espece') processPaymentEspece(); else if (moyenPaiement === 'moneyfusion') processPaymentMoneyFusion(); });

             // --- Fonction pour paiement Money Fusion (ADAPTÉE À L'EXEMPLE SIMPLE) ---
             async function processPaymentMoneyFusion() {
                setModalLoading(true, 'Redirection vers la page de paiement...'); // Message ajusté

                const montant = amountInput.value; // Montant du formulaire
                const motifSaisi = motifInput.value; // Motif saisi
                // Récupérer infos locataire depuis PHP
                const emailLocataire = <?= json_encode($locataire_info['email'] ?? '') ?>;
                const nomClient = <?= json_encode($locataire_nom_complet ?? 'Client GLN') ?>;
                const telClientMF = <?= json_encode($locataire_tel_mf ?? '') ?> || "01010101";
                const userId = <?= json_encode($user_id) ?>;
                const orderId = `GLN-${userId}-${Date.now()}`;

                // *** 1. URL API de l'exemple simple ***
                 const apiUrl = "https://www.pay.moneyfusion.net/IZI_COMPTE/01b08855515c4491/pay";

                // *** 2. URL de Retour (ADAPTEZ !) ***
                 const returnUrl = window.location.origin + '/confirmation-paiement.php?orderId=' + orderId; // Exemple

                // *** 3. Construction de paymentData comme dans l'exemple simple ***
                 //    Mais en utilisant les données dynamiques quand c'est possible.
                 //    La structure 'article' est simplifiée ici.
                 const paymentData = {
                     totalPrice: parseFloat(montant), // Dynamique
                     // Structure article simplifiée pour correspondre à l'exemple minimal
                     // On met le motif dans une description générique.
                     article: [{ description: motifSaisi, prix: parseFloat(montant) }],
                     // Ou essayez la structure de l'exemple :
                     // article: [{ "Loyer/Autre": parseFloat(montant) }], // Clé fixe

                     // Structure personal_Info simplifiée
                     personal_Info: [{ userId: userId, orderId: orderId }], // Dynamique
                     numeroSend: telClientMF, // Dynamique
                     nomclient: nomClient,     // Dynamique
                     return_url: returnUrl    // Dynamique
                 };

                console.log("Envoi à Money Fusion (Structure Simple):", JSON.stringify(paymentData));
                console.log("URL API utilisée:", apiUrl);

                try {
                    const response = await axios.post(apiUrl, paymentData, { headers: { "Content-Type": "application/json" } });
                    console.log("Réponse Money Fusion:", response.data);

                    if (response.data.statut && response.data.url) {
                         paymentModal.hide();
                         setModalLoading(false);
                         // Afficher un message avant de rediriger
                         Swal.fire({
                              title: 'Redirection...',
                              text: 'Vous allez être redirigé vers la page de paiement sécurisée.',
                              icon: 'info',
                              timer: 3000, // 3 secondes avant redirection
                              showConfirmButton: false,
                              willClose: () => {
                                   window.location.href = response.data.url;
                              }
                          });
                    } else {
                         throw new Error(response.data.message || "L'API Money Fusion a retourné une erreur inconnue.");
                    }
                } catch (error) {
                     console.error("Erreur Money Fusion:", error);
                     let errorMsg = "Une erreur est survenue lors de l'initialisation du paiement.";
                      if (error.response?.data?.message) { errorMsg = `Erreur API: ${error.response.data.message}`; }
                      else if (error.message) { errorMsg = error.message; }
                     displayModalError(errorMsg);
                     setModalLoading(false);
                }
            }

            // --- Fonction pour paiement Espèce ---
            async function processPaymentEspece() { /* ... (code inchangé) ... */ setModalLoading(true, "Enregistrement..."); const formData = new FormData(); formData.append('action', 'process_payment_espece'); formData.append('montant', amountInput.value); formData.append('motif', motifInput.value); try { const response = await fetch('', { method: 'POST', body: formData }); const data = await response.json(); if (!response.ok || !data.success) { throw new Error(data.message || `Erreur serveur ${response.status}`); } paymentModal.hide(); Swal.fire({ icon: 'success', title: 'Déclaration Enregistrée', text: data.message, timer: 4000, timerProgressBar: true }).then(() => { window.location.reload(); }); } catch (error) { console.error("Erreur Paiement Espèce:", error); displayModalError(error.message || "Erreur lors de l'enregistrement."); setModalLoading(false); } }

            // --- Helpers pour le modal ---
            function displayModalError(message) { /* ... (code inchangé) ... */ modalErrorDiv.textContent = message; modalErrorDiv.classList.remove('d-none'); }
            function clearModalError() { /* ... (code inchangé) ... */ modalErrorDiv.textContent = ''; modalErrorDiv.classList.add('d-none'); }
            function setModalLoading(isLoading, message = 'Traitement...') { /* ... (code inchangé) ... */ modalSubmitBtn.disabled = isLoading; if (isLoading) { modalLoadingSpinner.style.display = 'inline-block'; } else { modalLoadingSpinner.style.display = 'none'; toggleEspeceInfo(); } }

             // --- Gestion Filtre Année ---
              const filterYearSelect = document.getElementById('filterYear');
              if(filterYearSelect) { const urlParams = new URLSearchParams(window.location.search); const currentYearFilter = urlParams.get('annee'); if(currentYearFilter) filterYearSelect.value = currentYearFilter; filterYearSelect.addEventListener('change', function() { this.form.submit(); }); }

        }); // Fin DOMContentLoaded
    </script>

</body>
</html>