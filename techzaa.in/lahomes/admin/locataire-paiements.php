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
$locataire_id = null; // Initialiser
$locataire_info = []; // Initialiser pour éviter les erreurs si locataire non trouvé
$contrat_actif = null;
$montant_du = 0;
$historique_paiements = [];
$pageAlerts = [];
$errorMessage = '';
$initialDataLoaded = false; // Drapeau

// Fonction pour récupérer l'ID Locataire lié à l'ID User (utilise ta table)
function get_locataire_id_from_user(PDO $pdo, int $userId): ?int {
    try {
        $stmt = $pdo->prepare("SELECT idLocataire FROM locataire WHERE idUser = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() ?: null; // Retourne l'ID ou null
    } catch (PDOException $e) { error_log("Erreur get_locataire_id_from_user: " . $e->getMessage()); return null; }
}

// --- Chargement des données initiales ---
try {
    if (!isset($pdo) || !$pdo instanceof PDO) { throw new Exception("Connexion à la base de données non disponible."); }

    $locataire_id = get_locataire_id_from_user($pdo, $user_id);

    if ($locataire_id === null) { throw new Exception("Impossible de trouver les informations du locataire associé à ce compte."); }

    // Récupérer les infos de base du locataire
    $stmtLocataire = $pdo->prepare("SELECT u.prenom, u.nom, u.email, l.telephone FROM utilisateurs u JOIN locataire l ON u.idUser = l.idUser WHERE u.idUser = ?");
    $stmtLocataire->execute([$user_id]);
    $locataire_info = $stmtLocataire->fetch(PDO::FETCH_ASSOC);
    if (!$locataire_info) { throw new Exception("Informations utilisateur locataire non trouvées."); }
    $locataire_nom_complet = trim(($locataire_info['prenom'] ?? '') . ' ' . ($locataire_info['nom'] ?? 'Locataire'));
    $locataire_tel_mf = preg_replace('/[^0-9]/', '', $locataire_info['telephone'] ?? '');

    // Récupérer le contrat ACTIF (IMPORTANT: Vérifiez la valeur 'Actif' dans votre ENUM statutContrat)
    $stmtContrat = $pdo->prepare("SELECT idContrat, montantLoyer FROM contrat WHERE idLocataire = ? AND statutContrat = 'Actif' ORDER BY dateDebut DESC LIMIT 1");
    $stmtContrat->execute([$locataire_id]);
    $contrat_actif = $stmtContrat->fetch(PDO::FETCH_ASSOC);

    // Définir le montant dû
    $montant_du = ($contrat_actif && isset($contrat_actif['montantLoyer'])) ? (float)$contrat_actif['montantLoyer'] : 0;
    if ($montant_du <= 0 && $contrat_actif) { $pageAlerts[] = ['type' => 'info', 'message' => 'Loyer du contrat actif non défini.']; }
    elseif (!$contrat_actif) { $pageAlerts[] = ['type' => 'info', 'message' => 'Aucun contrat de location actif trouvé.']; }

    // Récupérer l'historique des paiements (table 'paiementloyer')
    $annee_filtre = isset($_GET['annee']) && is_numeric($_GET['annee']) ? (int)$_GET['annee'] : null;

    // *** Requête Historique - Vérifiez les noms de colonnes ***
    //    Colonnes utilisées : idPaiement, datePaiement, typePaiement, montant, statutPaiement
    //    Colonnes optionnelles (si elles existent et si vous voulez les afficher) : motif, moisDebut, moisFin
    $sqlHistorique = "
        SELECT idPaiement, datePaiement, typePaiement, montant, statutPaiement
        -- , motif -- Décommentez si la colonne 'motif' existe dans paiementloyer
        -- , moisDebut, moisFin -- Décommentez si ces colonnes existent
        FROM paiementloyer
        WHERE idContrat IN (SELECT idContrat FROM contrat WHERE idLocataire = :idLocataire)
        ";
    $paramsHistorique = [':idLocataire' => $locataire_id];

    if ($annee_filtre) {
        $sqlHistorique .= " AND YEAR(datePaiement) = :annee ";
        $paramsHistorique[':annee'] = $annee_filtre;
    }

    $sqlHistorique .= " ORDER BY datePaiement DESC LIMIT 20"; // Limite pour affichage

    $stmtHistorique = $pdo->prepare($sqlHistorique);
    $stmtHistorique->execute($paramsHistorique);
    $historique_paiements = $stmtHistorique->fetchAll(PDO::FETCH_ASSOC);

    $initialDataLoaded = true; // Tout s'est bien passé

} catch (PDOException $e) {
    $errorMessage = 'Erreur base de données lors du chargement des informations.';
    error_log("Err PDO locataire-paiements Init UserID {$user_id}: " . $e->getMessage());
} catch (Exception $e) {
    $errorMessage = $e->getMessage(); // Affiche l'erreur spécifique (ex: locataire non trouvé)
    error_log("Err General locataire-paiements Init UserID {$user_id}: " . $e->getMessage());
}

// --- Gestion Action Paiement Espece (POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'process_payment_espece') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Erreur traitement paiement espèce.'];
    if ($locataire_id === null) { $response['message'] = "Locataire non identifié."; echo json_encode($response); exit; }

    // Récupérer TOUTES les données du formulaire modal
    $montantPaye = filter_input(INPUT_POST, 'montant', FILTER_VALIDATE_FLOAT);
    $typePaiement = $_POST['typePaiement'] ?? null;
    $periodeDebutStr = $_POST['periodeDebut'] ?? null; // Format YYYY-MM
    $periodeFinStr = $_POST['periodeFin'] ?? null;
    $modalitePaiement = $_POST['modalitePaiement'] ?? null;
    $datePaiementStr = $_POST['datePaiement'] ?? null; // Format YYYY-MM-DD
    $contratIdAssocie = filter_input(INPUT_POST, 'idContrat', FILTER_VALIDATE_INT);
    $motifPaiementForm = !empty($_POST['motif']) ? trim(htmlspecialchars($_POST['motif'])) : null; // Motif caché généré par JS

    // Validation
    if ($montantPaye === false || $montantPaye <= 0 || empty($typePaiement) || empty($periodeDebutStr) || empty($periodeFinStr) || empty($modalitePaiement) || empty($datePaiementStr) || !$contratIdAssocie) {
         $response['message'] = "Données manquantes ou invalides."; echo json_encode($response); exit;
    }
    $moisDebut = date('Y-m-01', strtotime($periodeDebutStr . '-01'));
    $moisFin = date('Y-m-t', strtotime($periodeFinStr . '-01')); // Dernier jour

    try {
        // *** ADAPTEZ cette requête à votre table paiementloyer ***
        // Assurez-vous que les colonnes moisDebut, moisFin, modalitePaiement, motif existent ou retirez-les
        $sqlInsert = "INSERT INTO paiementloyer (
                        idContrat, datePaiement, montant, typePaiement, methodePaiement,
                        statutPaiement, moisDebut, moisFin, modalitePaiement, motif
                      ) VALUES (
                        :idContrat, :datePaiement, :montant, :typePaiement, 'Espèce',
                        'en attente', :moisDebut, :moisFin, :modalitePaiement, :motif
                      )";
        $stmtInsert = $pdo->prepare($sqlInsert);
        $success = $stmtInsert->execute([
            ':idContrat' => $contratIdAssocie,
            ':datePaiement' => $datePaiementStr,
            ':montant' => $montantPaye,
            ':typePaiement' => $typePaiement,
            ':moisDebut' => $moisDebut,
            ':moisFin' => $moisFin,
            ':modalitePaiement' => $modalitePaiement,
            ':motif' => $motifPaiementForm // Motif généré par JS
        ]);

        if ($success) { $response = ['success' => true, 'message' => 'Déclaration enregistrée (en attente validation).']; }
        else { $response['message'] = "Échec enregistrement."; }
    } catch (PDOException $e) { error_log("Erreur PDO paiement espèce UserID {$user_id}: " . $e->getMessage()); $response['message'] = "Erreur BDD."; }
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        /* Vos styles CSS */
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
         input.flatpickr-input.is-invalid { border-color: #dc3545 !important; }
         .was-validated .flatpickr-input:invalid ~ .invalid-feedback, input.flatpickr-input.is-invalid ~ .invalid-feedback { display: block; } /* Afficher feedback pour flatpickr */
         .content-requires-data.loading-error { display: none; } /* Cache le contenu si erreur PHP */
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- ========== Topbar, Sidebar ========== -->
         <header class=""> <div class="topbar"> <div class="container-fluid"> <div class="navbar-header"> <div class="d-flex align-items-center gap-2"> <div class="topbar-item"><button type="button" class="button-toggle-menu topbar-button"><i class="ri-menu-2-line fs-24"></i></button></div> <form class="app-search d-none d-md-block me-auto"><div class="position-relative"><input type="search" class="form-control border-0" placeholder="Rechercher..." autocomplete="off" value=""><i class="ri-search-line search-widget-icon"></i></div></form> </div> <div class="d-flex align-items-center gap-1"> <div class="topbar-item"><button type="button" class="topbar-button" id="light-dark-mode"><i class="ri-moon-line fs-24 light-mode"></i><i class="ri-sun-line fs-24 dark-mode"></i></button></div> <div class="dropdown topbar-item d-none d-lg-flex"><button type="button" class="topbar-button" data-toggle="fullscreen"><i class="ri-fullscreen-line fs-24 fullscreen"></i><i class="ri-fullscreen-exit-line fs-24 quit-fullscreen"></i></button></div> <div class="dropdown topbar-item"> <button type="button" class="topbar-button position-relative" id="page-header-notifications-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="ri-notification-3-line fs-24"></i><span id="topbarUnreadCount" class="position-absolute topbar-badge fs-10 translate-middle badge bg-danger rounded-pill">0<span class="visually-hidden">notifications non lues</span></span></button> <div class="dropdown-menu py-0 dropdown-lg dropdown-menu-end" aria-labelledby="page-header-notifications-dropdown"> <div class="p-3 border-top-0 border-start-0 border-end-0 border-dashed border"><div class="row align-items-center"><div class="col"><h6 class="m-0 fs-16 fw-semibold"> Notifications</h6></div><div class="col-auto"><a href="javascript: void(0);" class="text-dark text-decoration-underline" onclick="markAllAsRead()"> <small>Marquer tout comme lu</small></a></div></div></div> <div data-simplebar style="max-height: 280px;" id="topbarNotificationList"></div> <div class="text-center py-3"><a href="locataire-notifications.php" class="btn btn-primary btn-sm">Voir toutes les notifications <i class="ri-arrow-right-line ms-1"></i></a></div> </div> </div> <div class="topbar-item d-none d-md-flex"><button type="button" class="topbar-button" id="theme-settings-btn" data-bs-toggle="offcanvas" data-bs-target="#theme-settings-offcanvas" aria-controls="theme-settings-offcanvas"><i class="ri-settings-4-line fs-24"></i></button></div> <div class="dropdown topbar-item"> <a type="button" class="topbar-button" id="page-header-user-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><span class="d-flex align-items-center"><img class="rounded-circle" width="32" src="assets/images/users/avatar-placeholder.png" alt="avatar-locataire"></span></a> <div class="dropdown-menu dropdown-menu-end"> <h6 class="dropdown-header" id="userDropdownHeader">Bienvenue <?= htmlspecialchars($locataire_info['prenom'] ?? 'Locataire') ?> !</h6> <a class="dropdown-item" href="locataire-profil.php"><i class="ri-user-line align-middle me-1"></i> <span class="align-middle">Mon Profil</span></a> <a class="dropdown-item active" href="locataire-paiements.php"><i class="ri-secure-payment-line align-middle me-1"></i> <span class="align-middle">Paiements & Quittances</span></a> <div class="dropdown-divider my-1"></div> <a class="dropdown-item text-danger" href="auth-signout.php"><i class="ri-logout-box-line align-middle me-1"></i> <span class="align-middle">Déconnexion</span></a> </div> </div> </div> </div> </div> </header>
         <div> <div class="offcanvas offcanvas-end border-0 rounded-start-4 overflow-hidden" tabindex="-1" id="theme-settings-offcanvas"> <div class="d-flex align-items-center bg-primary p-3 offcanvas-header"><h5 class="text-white m-0">Theme Settings</h5><button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="offcanvas" aria-label="Close"></button></div><div class="offcanvas-body p-0"><div data-simplebar class="h-100"><div class="p-3 settings-bar"></div></div></div><div class="offcanvas-footer border-top p-3 text-center"><div class="row"><div class="col"><button type="button" class="btn btn-danger w-100" id="reset-layout">Reset</button></div></div></div> </div> </div>
         <div class="main-nav"> <div class="logo-box"> <a href="locataire-dashboard.php" class="logo-dark"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-dark.png" class="logo-lg"></a> <a href="locataire-dashboard.php" class="logo-light"><img src="assets/images/logo-sm.png" class="logo-sm"><img src="assets/images/logo-light.png" class="logo-lg"></a> </div> <button type="button" class="button-sm-hover" aria-label="Show Full Sidebar"><i class="ri-menu-2-line fs-24 button-sm-hover-icon"></i></button> <div class="scrollbar" data-simplebar> <ul class="navbar-nav" id="navbar-nav"> <li class="menu-title">Menu Locataire</li> <li class="nav-item"><a class="nav-link" href="locataire-dashboard.php"><span class="nav-icon"><i class="ri-dashboard-line"></i></span><span class="nav-text">Tableau de Bord</span></a></li> <li class="nav-item"><a class="nav-link" href="locataire-contrat.php"><span class="nav-icon"><i class="ri-file-text-line"></i></span><span class="nav-text">Mon Contrat</span></a></li> <li class="nav-item"><a class="nav-link active" href="locataire-paiements.php"><span class="nav-icon"><i class="ri-secure-payment-line"></i></span><span class="nav-text">Paiements & Quittances</span></a></li> <li class="nav-item"><a class="nav-link" href="locataire-maintenance.php"><span class="nav-icon"><i class="ri-tools-line"></i></span><span class="nav-text">Maintenance</span></a></li> <li class="nav-item"><a class="nav-link" href="locataire-notifications.php"><span class="nav-icon"><i class="ri-notification-3-line"></i></span><span class="nav-text">Notifications</span><span class="badge bg-danger badge-pill text-end" id="sidebarUnreadCount" style="display: none;"></span></a></li> </ul> </div> </div>
        <!-- ============================================================== -->

        <div class="page-content">
            <div class="container-fluid">
                <!-- Titre de Page -->
                <div class="row"> <div class="col-12"> <div class="page-title-box"> <h4 class="mb-0 fw-semibold">Paiements & Quittances</h4> <ol class="breadcrumb mb-0"> <li class="breadcrumb-item"><a href="locataire-dashboard.php">Espace Locataire</a></li> <li class="breadcrumb-item active">Paiements</li> </ol> </div> </div> </div>

                <!-- Affichage Erreurs Initiales -->
                <div id="pageAlertPlaceholder">
                    <?php if (!empty($errorMessage)): ?>
                         <div class="alert alert-danger alert-dismissible fade show" role="alert">
                             <i class="ri-error-warning-line me-2"></i><?php echo htmlspecialchars($errorMessage); ?>
                              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                         </div>
                    <?php endif; ?>
                    <?php foreach ($pageAlerts as $alert): ?>
                         <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                              <?php echo htmlspecialchars($alert['message']); ?>
                              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                         </div>
                    <?php endforeach; ?>
                 </div>

                <!-- Contenu Principal (Conditionné par le chargement initial) -->
                <div class="content-requires-data <?= !$initialDataLoaded ? 'loading-error' : '' ?>">

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
                                     <button type="button" id="payNowBtnTrigger" class="btn btn-success btn-pay <?= !$initialDataLoaded || ($montant_du <= 0 && !$contrat_actif) ? 'disabled' : '' ?>"
                                             data-bs-toggle="modal" data-bs-target="#paymentModal"
                                             <?= !$initialDataLoaded || ($montant_du <= 0 && !$contrat_actif) ? 'aria-disabled="true"' : '' ?>>
                                         <i class="ri-secure-payment-fill me-1"></i> Effectuer un Paiement
                                     </button>
                                     <p id="paymentMessage" class="mt-3 text-muted small" style="<?= $montant_du <= 0 && $initialDataLoaded && $contrat_actif ? '' : 'display: none;' ?>">
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
                                                    <th>Motif / Période</th>
                                                    <th class="text-end">Montant (FCFA)</th>
                                                    <th>Statut</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="paymentsHistoryBody">
                                                <?php if (empty($historique_paiements) && $initialDataLoaded): // Afficher "aucun" seulement si chargement OK et vide ?>
                                                    <tr class="no-results-row"><td colspan="6">Aucun paiement enregistré <?= isset($_GET['annee']) ? 'pour l\'année ' . htmlspecialchars($_GET['annee']) : 'pour le moment' ?>.</td></tr>
                                                <?php elseif ($initialDataLoaded): // Afficher historique si chargement OK et non vide ?>
                                                    <?php foreach ($historique_paiements as $p):
                                                        $formattedDate = $p['datePaiement'] ? date("d/m/Y", strtotime($p['datePaiement'])) : '-';
                                                        $formattedAmount = number_format($p['montant'] ?? 0, 0, ',', ' ');
                                                        $typePaiement = htmlspecialchars($p['typePaiement'] ?? 'N/A');
                                                        $statutPaiement = htmlspecialchars($p['statutPaiement'] ?? 'Indéfini');
                                                        $motifComplet = htmlspecialchars($p['motif'] ?? '-'); // Utilise la colonne motif si elle existe
                                                        // Sinon, tente de reconstruire à partir des dates
                                                        if ($motifComplet === '-' && isset($p['moisDebut']) && isset($p['moisFin'])) {
                                                            $dateDebutFmt = date("M Y", strtotime($p['moisDebut']));
                                                            $dateFinFmt = date("M Y", strtotime($p['moisFin']));
                                                            $motifComplet = ($dateDebutFmt == $dateFinFmt) ? $typePaiement . " " . $dateDebutFmt : $typePaiement . " " . $dateDebutFmt . " - " . $dateFinFmt;
                                                        }

                                                        $typeBadge = '<span class="badge bg-secondary">' . $typePaiement . '</span>';
                                                        if ($typePaiement) { switch (strtolower($typePaiement)) { case 'loyer': $typeBadge = '<span class="badge bg-info">Loyer</span>'; break; case 'caution': $typeBadge = '<span class="badge bg-warning text-dark">Caution</span>'; break; case 'avance': $typeBadge = '<span class="badge bg-primary">Avance</span>'; break; } }
                                                        $statusBadge = '<span class="badge bg-secondary">Indéfini</span>'; $downloadDisabled = true;
                                                        if ($statutPaiement) { switch (strtolower($statutPaiement)) { case 'validé': $statusBadge = '<span class="badge bg-success">Validé</span>'; $downloadDisabled = false; break; case 'en attente': case 'en attente validation': $statusBadge = '<span class="badge bg-warning text-dark">En attente</span>'; break; case 'échoué': case 'refusé': $statusBadge = '<span class="badge bg-danger">Échoué</span>'; break; } }
                                                    ?>
                                                        <tr>
                                                            <td><?= $formattedDate ?></td>
                                                            <td><?= $typeBadge ?></td>
                                                            <td><?= $motifComplet ?></td>
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
                                <?php if ($initialDataLoaded): // Affiche le footer seulement si chargement OK ?>
                                <div class="card-footer bg-white border-top d-flex justify-content-between align-items-center">
                                    <div class="fs-sm text-muted"> Total: <?= count($historique_paiements) ?> paiement(s) affiché(s) </div>
                                    <!-- Pagination à implémenter si besoin -->
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div> <!-- Fin div content-requires-data -->

            </div> <!-- container-fluid -->
        </div> <!-- page-content -->

        <!-- ========== MODAL DE PAIEMENT ========== -->
        <div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="paymentModalLabel">Effectuer un Paiement</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="modalPaymentError" class="alert alert-danger d-none" role="alert"></div>
                        <form id="formModalPaiement" novalidate>
                            <input type="hidden" name="idContrat" id="paymentContractId" value="<?= htmlspecialchars($contrat_actif['idContrat'] ?? '') ?>">
                             <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="paymentType" class="form-label">Type de paiement <span class="text-danger">*</span></label>
                                    <select class="form-select" id="paymentType" name="typePaiement" required>
                                        <option value="loyer" selected>Loyer</option>
                                        <option value="caution">Caution</option>
                                        <option value="avance">Avance</option>
                                    </select>
                                    <div class="invalid-feedback">Choisissez le type.</div>
                                </div>
                                 <div class="col-md-6 mb-3">
                                     <label for="paymentAmount" class="form-label">Montant Payé (FCFA) <span class="text-danger">*</span></label>
                                     <input type="number" class="form-control" id="paymentAmount" name="montant" value="<?= htmlspecialchars($montant_du) ?>" required min="1" step="any">
                                     <div class="invalid-feedback">Montant invalide.</div>
                                 </div>
                             </div>
                             <div class="row">
                                <div class="col-md-6 mb-3">
                                     <label for="paymentPeriodStart" class="form-label">Mois Début Période <span class="text-danger">*</span></label>
                                     <input type="month" class="form-control" id="paymentPeriodStart" name="periodeDebut" required>
                                     <div class="invalid-feedback">Choisissez le mois de début.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                     <label for="paymentPeriodEnd" class="form-label">Mois Fin Période <span class="text-danger">*</span></label>
                                     <input type="month" class="form-control" id="paymentPeriodEnd" name="periodeFin" required>
                                     <div class="invalid-feedback">Choisissez le mois de fin (>= début).</div>
                                </div>
                             </div>
                             <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="paymentModality" class="form-label">Modalité <span class="text-danger">*</span></label>
                                    <select class="form-select" id="paymentModality" name="modalitePaiement" required>
                                        <option value="" disabled selected>-- Choisir --</option>
                                        <option value="mensuel">Mensuel</option>
                                        <option value="trimestriel">Trimestriel</option>
                                        <option value="annuel">Annuel</option>
                                    </select>
                                    <div class="invalid-feedback">Choisissez la modalité.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                     <label for="paymentDate" class="form-label">Date du Paiement <span class="text-danger">*</span></label>
                                     <input type="text" class="form-control" id="paymentDate" name="datePaiement" required placeholder="YYYY-MM-DD">
                                     <div class="invalid-feedback">Choisissez la date du paiement.</div>
                                </div>
                             </div>
                             <input type="hidden" id="paymentMotifHidden" name="motif">
                             <div class="mb-3">
                                 <label class="form-label mb-2">Moyen de Paiement <span class="text-danger">*</span></label>
                                 <div class="form-check">
                                     <input class="form-check-input" type="radio" name="moyenPaiement" id="payMoneyFusion" value="moneyfusion" required checked>
                                     <label class="form-check-label" for="payMoneyFusion">
                                         <img src="assets/images/payment/moneyfusion_logo.png" alt="Money Fusion" height="20" class="me-1 align-middle"> Money Fusion
                                     </label>
                                 </div>
                                 <div class="form-check">
                                     <input class="form-check-input" type="radio" name="moyenPaiement" id="payEspece" value="espece" required>
                                     <label class="form-check-label" for="payEspece">
                                         <i class="ri-hand-coin-line me-1 align-middle"></i> Espèce (Déclaration)
                                     </label>
                                      <div class="invalid-feedback">Choisissez un moyen de paiement.</div>
                                 </div>
                             </div>
                             <p id="especeInfo" class="form-text text-muted small" style="display: none;">
                                 Déclaration de paiement en attente de validation par le notaire.
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
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://npmcdn.com/flatpickr/dist/l10n/fr.js"></script>

    <!-- SCRIPT PERSONNALISÉ -->
    <script>
        window.downloadQuittance = function(paiementId) { const url = `backend/download_quittance.php?idPaiement=${paiementId}`; Swal.fire('Info', 'Fonctionnalité de téléchargement à implémenter (URL: ' + url + ')', 'info'); };

        document.addEventListener('DOMContentLoaded', function() {
            const paymentModalEl = document.getElementById('paymentModal');
            const paymentModal = bootstrap.Modal.getOrCreateInstance(paymentModalEl);
            const formModal = document.getElementById('formModalPaiement');
            const modalErrorDiv = document.getElementById('modalPaymentError');
            const modalSubmitBtn = document.getElementById('modalSubmitPaymentBtn');
            const modalLoadingSpinner = document.getElementById('modalLoadingSpinner');
            const paymentTypeSelect = document.getElementById('paymentType');
            const amountInput = document.getElementById('paymentAmount');
            const periodStartInput = document.getElementById('paymentPeriodStart');
            const periodEndInput = document.getElementById('paymentPeriodEnd');
            const modalitySelect = document.getElementById('paymentModality');
            const paymentDateInput = document.getElementById('paymentDate');
            const especeRadio = document.getElementById('payEspece');
            const moneyFusionRadio = document.getElementById('payMoneyFusion');
            const especeInfoPara = document.getElementById('especeInfo');
            const motifHiddenInput = document.getElementById('paymentMotifHidden');

            // Initialiser Flatpickr
            const fpPaymentDate = flatpickr(paymentDateInput, { dateFormat: "Y-m-d", locale: "fr", defaultDate: "today", maxDate: "today" });

            // Pré-remplissage Montant et Période
            const initialAmountDue = parseFloat(document.getElementById('amountDueValue')?.dataset.amount || 0);
            if (amountInput && initialAmountDue > 0) amountInput.value = initialAmountDue;
            const today = new Date(); const currentYear = today.getFullYear(); const currentMonth = (today.getMonth() + 1).toString().padStart(2, '0'); const currentMonthYear = `${currentYear}-${currentMonth}`;
            if(periodStartInput && !periodStartInput.value) periodStartInput.value = currentMonthYear; // Pré-remplir si vide
            if(periodEndInput && !periodEndInput.value) periodEndInput.value = currentMonthYear;     // Pré-remplir si vide


            // Afficher/Masquer Info Espèce + Texte Bouton
            function toggleEspeceInfo() { especeInfoPara.style.display = especeRadio.checked ? 'block' : 'none'; modalSubmitBtn.textContent = especeRadio.checked ? 'Confirmer Paiement Espèce' : 'Payer via Money Fusion'; modalSubmitBtn.classList.toggle('btn-warning', especeRadio.checked); modalSubmitBtn.classList.toggle('btn-success', !especeRadio.checked); }
             especeRadio.addEventListener('change', toggleEspeceInfo); moneyFusionRadio.addEventListener('change', toggleEspeceInfo); toggleEspeceInfo();

             // Soumission Modal
             modalSubmitBtn.addEventListener('click', function() {
                 generateMotif(); // Générer le motif avant validation
                 const startPeriod = periodStartInput.value; const endPeriod = periodEndInput.value; let periodValid = true;
                 periodStartInput.classList.remove('is-invalid'); periodEndInput.classList.remove('is-invalid');
                 if (startPeriod && endPeriod && endPeriod < startPeriod) { periodEndInput.classList.add('is-invalid'); periodEndInput.nextElementSibling.textContent = "Mois de fin >= mois de début."; periodValid = false; }
                 else if (endPeriod) { periodEndInput.classList.remove('is-invalid'); }

                 if (!formModal.checkValidity() || !periodValid) { formModal.classList.add('was-validated'); if (!periodValid && !modalErrorDiv.textContent) { displayModalError("Veuillez corriger la période."); } else if (!modalErrorDiv.textContent) { displayModalError("Veuillez remplir tous les champs requis."); } return; }
                 formModal.classList.remove('was-validated'); clearModalError();
                 const moyenPaiement = formModal.querySelector('input[name="moyenPaiement"]:checked').value;
                 if (moyenPaiement === 'espece') processPaymentEspece();
                 else if (moyenPaiement === 'moneyfusion') processPaymentMoneyFusion();
             });

            // Fonction pour générer le motif et le mettre dans l'input caché
            function generateMotif() {
                const typeVal = paymentTypeSelect.options[paymentTypeSelect.selectedIndex].text; const startVal = periodStartInput.value; const endVal = periodEndInput.value;
                let motifGenere = typeVal;
                if (startVal && endVal) { const dateDebutFmt = formatDateMonthYear(startVal); const dateFinFmt = formatDateMonthYear(endVal); if (dateDebutFmt === dateFinFmt) { motifGenere += ` ${dateDebutFmt}`; } else { motifGenere += ` (${dateDebutFmt} - ${dateFinFmt})`; } }
                if(motifHiddenInput) motifHiddenInput.value = motifGenere; return motifGenere;
            }
            // Helper pour formater YYYY-MM en "Mois Année"
             function formatDateMonthYear(yyyyMM) { if (!yyyyMM) return ''; try { const [year, month] = yyyyMM.split('-'); const dateObj = new Date(year, month - 1); const monthNames = ["Janv", "Févr", "Mars", "Avr", "Mai", "Juin", "Juil", "Août", "Sept", "Oct", "Nov", "Déc"]; return `${monthNames[dateObj.getMonth()]} ${dateObj.getFullYear()}`; } catch (e) { return yyyyMM; } }

             // --- Fonction pour paiement Money Fusion (INCHANGÉE par rapport à la dernière correction) ---
             async function processPaymentMoneyFusion() {
                setModalLoading(true, 'Initialisation du paiement...');
                const montant = amountInput.value; const motifDescriptif = generateMotif();
                const emailLocataire = <?= json_encode($locataire_info['email'] ?? '') ?>; const nomClient = <?= json_encode($locataire_nom_complet ?? 'Client GLN') ?>; const telClientMF = <?= json_encode($locataire_tel_mf ?? '') ?> || "01010101"; const userId = <?= json_encode($user_id) ?>; const orderId = `GLN-${userId}-${Date.now()}`;
                const apiUrl = "https://www.pay.moneyfusion.net/IZI_COMPTE/01b08855515c4491/pay"; // TON URL API
                const returnUrl = window.location.origin + '/confirmation-paiement.php?orderId=' + orderId; // ADAPTEZ
                const paymentData = { totalPrice: parseFloat(montant), article: [{ description: motifDescriptif, prix: parseFloat(montant) }], personal_Info: [{ userId: userId, orderId: orderId }], numeroSend: telClientMF, nomclient: nomClient, return_url: returnUrl };
                console.log("Envoi à Money Fusion:", JSON.stringify(paymentData)); console.log("URL API:", apiUrl);
                try {
                    const response = await axios.post(apiUrl, paymentData, { headers: { "Content-Type": "application/json" } });
                    console.log("Réponse Money Fusion:", response.data);
                    if (response.data.statut && response.data.url) {
                         paymentModal.hide(); setModalLoading(false);
                         Swal.fire({ title: 'Redirection...', text: 'Vers la page de paiement.', icon: 'info', timer: 2000, showConfirmButton: false, willClose: () => { window.location.href = response.data.url; } });
                    } else { throw new Error(response.data.message || "Erreur API inconnue."); }
                } catch (error) {
                     console.error("Erreur Money Fusion:", error); let errorMsg = "Erreur initialisation paiement."; if (error.response?.data?.message) { errorMsg = `Erreur API: ${error.response.data.message}`; } else if (error.request) { errorMsg = "Erreur réseau."; } else if (error.message) { errorMsg = error.message; } displayModalError(errorMsg); setModalLoading(false);
                }
            }

            // --- Fonction pour paiement Espèce ---
            async function processPaymentEspece() { setModalLoading(true, "Enregistrement..."); const formData = new FormData(formModal); formData.append('action', 'process_payment_espece'); try { const response = await fetch('', { method: 'POST', body: formData }); const data = await response.json(); if (!response.ok || !data.success) { throw new Error(data.message || `Erreur serveur ${response.status}`); } paymentModal.hide(); Swal.fire({ icon: 'success', title: 'Déclaration Enregistrée', text: data.message, timer: 4000, timerProgressBar: true }).then(() => { window.location.reload(); }); } catch (error) { console.error("Erreur Paiement Espèce:", error); displayModalError(error.message || "Erreur enregistrement."); setModalLoading(false); } }

            // --- Helpers pour le modal ---
            function displayModalError(message) { modalErrorDiv.textContent = message; modalErrorDiv.classList.remove('d-none'); }
            function clearModalError() { modalErrorDiv.textContent = ''; modalErrorDiv.classList.add('d-none'); }
            function setModalLoading(isLoading, message = 'Traitement...') { modalSubmitBtn.disabled = isLoading; if (isLoading) { modalLoadingSpinner.style.display = 'inline-block'; } else { modalLoadingSpinner.style.display = 'none'; toggleEspeceInfo(); } }

             // --- Gestion Filtre Année ---
              const filterYearSelect = document.getElementById('filterYear');
              if(filterYearSelect) { const urlParams = new URLSearchParams(window.location.search); const currentYearFilter = urlParams.get('annee'); if(currentYearFilter) filterYearSelect.value = currentYearFilter; filterYearSelect.addEventListener('change', function() { this.form.submit(); }); }

        }); // Fin DOMContentLoaded
    </script>

</body>
</html>