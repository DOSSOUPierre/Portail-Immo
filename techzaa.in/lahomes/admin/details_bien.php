<?php
// --- details_bien.php (CORRIGÉ - SELECT simplifié) ---
if (session_status() === PHP_SESSION_NONE) session_start();

// --- Débogage (Décommentez si besoin) ---
/*
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

require_once __DIR__ . '/db_connection.php'; // $pdo

$bien = null;
$errorMessage = '';
$debugMessage = '';
$bienId = null;

// 1. Récupérer et Valider l'ID
if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
    $errorMessage = "Demande invalide. Identifiant du bien manquant ou incorrect.";
    http_response_code(400); // Bad Request
} else {
    $bienId = (int)$_GET['id'];

    // 2. Récupérer les détails essentiels du bien
    try {
        if (!isset($pdo) || !$pdo instanceof PDO) {
            throw new Exception("La connexion à la base de données n'est pas disponible.");
        }

        // *** REQUÊTE SIMPLIFIÉE : On sélectionne SEULEMENT les colonnes qu'on sait exister ***
        $sql = "SELECT
                    b.idBien, b.adresse, b.loyerMensuel, b.image_profil
                    -- Toutes les autres colonnes (description, superficie, etc.) ont été retirées
                FROM
                    bienimmobiliers b
                WHERE
                    b.idBien = :idBien
                    AND b.statut = 'Libre'
                    AND b.supervisionStatut = 'Validé'
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':idBien', $bienId, PDO::PARAM_INT);
        $stmt->execute();

        $bien = $stmt->fetch(PDO::FETCH_ASSOC);

        // 3. Vérifier si le bien a été trouvé
        if ($bien === false) {
            $errorMessage = "Le bien immobilier que vous recherchez n'existe pas ou n'est plus disponible.";
            http_response_code(404); // Not Found
            $bien = null;
        }

    } catch (PDOException $e) {
        error_log("Erreur PDO détails bien ID {$bienId}: " . $e->getMessage());
        $errorMessage = "Erreur lors de la récupération des informations du bien.";
        $debugMessage = "PDO Error: " . $e->getMessage();
        http_response_code(500);
    } catch (Exception $e) {
        error_log("Erreur Générale détails bien ID {$bienId}: " . $e->getMessage());
        $errorMessage = "Erreur technique lors de l'accès aux détails du bien.";
        $debugMessage = "General Error: " . $e->getMessage();
        http_response_code(500);
    }
}

// Titre de page
$pageTitle = "Détails du Bien | Gestion Locative Notariale";
if ($bien && isset($bien['adresse'])) {
    $pageTitle = "Détails : " . htmlspecialchars(substr($bien['adresse'], 0, 50)) . "...";
} elseif (!empty($errorMessage)) {
     $pageTitle = "Erreur | Gestion Locative Notariale";
}

?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $pageTitle ?></title>
    <meta name="description" content="Consultez les détails de ce bien immobilier géré par notre étude notariale au Bénin.">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="shortcut icon" href="assets/images/favicon.ico">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Syne:wght@700;800&display=swap" rel="stylesheet">

    <!-- Librairies CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.2.0/fonts/remixicon.css" rel="stylesheet"/>

    <!-- === CSS Intégré (Assurez-vous qu'il est complet et correct) === -->
    <style>
        /* ==========================================================================
           Base & Variables
           ========================================================================== */
        :root {
            --couleur-primaire: #0a192f;       /* Bleu Nuit très sombre */
            --couleur-secondaire: #172a45;    /* Bleu Nuit un peu moins sombre */
            --couleur-tertiaire: #304a6d;     /* Bleu/Gris pour accents doux */
            --couleur-accent: #ffc107;         /* Or vif */
            --couleur-accent-hover: #ffca2c;
            --couleur-primaire-rgb: 10, 25, 47; /* Pour RGBA */
            --couleur-accent-rgb: 255, 193, 7; /* Pour RGBA */
            --couleur-texte-primaire: #ccd6f6;   /* Texte clair sur fond sombre */
            --couleur-texte-secondaire: #8892b0; /* Texte gris clair */
            --couleur-texte-dark: #343a40;      /* Texte standard sur fond clair */
            --couleur-fond-blanc: #FFFFFF;
            --couleur-fond-section: #f8f9fa;
            --couleur-border: #dee2e6;

            --font-titre: 'Syne', sans-serif;
            --font-texte: 'Inter', sans-serif;

            --breakpoint-lg: 992px;

            --shadow-sm: 0 1px 3px rgba(0,0,0,.06);
            --shadow-md: 0 4px 12px rgba(0,0,0,.08);
            --shadow-lg: 0 10px 30px rgba(0,0,0,.1);

            --border-radius-sm: 0.25rem;
            --border-radius-md: 0.5rem;
            --border-radius-lg: 0.8rem;

            --transition-fast: 0.2s ease-in-out;
            --transition-base: 0.4s ease-in-out;
            --easing-smooth: cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        /* ==========================================================================
           Reset & Global Styles
           ========================================================================== */
        body {
            font-family: var(--font-texte);
            color: var(--couleur-texte-dark);
            line-height: 1.7;
            background-color: var(--couleur-fond-blanc);
            font-weight: 400;
            font-size: 16px;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
             padding-top: 80px; /* Espace pour la navbar fixe */
        }
        @media (min-width: 992px) {
            body { padding-top: 90px; }
        }

        h1, h2, h3, h4, h5, h6 { font-family: var(--font-titre); font-weight: 800; color: var(--couleur-primaire); margin-top: 0; margin-bottom: 0.75em; line-height: 1.3; }
        h1 { font-size: clamp(2.5rem, 5vw, 3.8rem); } h2 { font-size: clamp(2rem, 4vw, 3rem); } h3 { font-size: clamp(1.5rem, 3vw, 1.8rem); }
        a { color: var(--couleur-accent); text-decoration: none; transition: color var(--transition-fast); }
        a:hover { color: var(--couleur-accent-hover); text-decoration: none; }
        .accent { color: var(--couleur-accent) !important; }
        .section-padding { padding: 80px 0; } @media (min-width: 992px) { .section-padding { padding: 120px 0; } }
        .section-title { margin-bottom: 1.5rem; font-weight: 800; position: relative; padding-bottom: 20px; color: var(--couleur-primaire); }
        .section-title::after { content: ''; position: absolute; left: 50%; transform: translateX(-50%); bottom: 0; height: 4px; width: 70px; background: linear-gradient(90deg, var(--couleur-accent), var(--couleur-accent-hover)); border-radius: 2px; }
        .section-subtitle { max-width: 600px; margin-left: auto; margin-right: auto; color: #6c757d; font-weight: 300; margin-bottom: 60px; }
        ::selection { background: var(--couleur-accent); color: var(--couleur-primaire); }

        /* ==========================================================================
           Navbar v2 - Style pour pages internes (blanche)
           ========================================================================== */
        .navbar {
            transition: background-color 0.4s var(--easing-smooth), box-shadow 0.4s var(--easing-smooth), padding 0.4s var(--easing-smooth);
            padding-top: 1.25rem;
            padding-bottom: 1.25rem;
            background-color: var(--couleur-fond-blanc) !important;
            box-shadow: var(--shadow-md) !important;
            position: fixed;
            width: 100%;
            z-index: 1030;
            border: none;
        }
        .navbar .navbar-brand { color: var(--couleur-primaire) !important; font-weight: 800; font-size: 1.8rem; font-family: var(--font-titre); transition: color var(--transition-fast); }
        .navbar .nav-link { color: var(--couleur-texte-dark) !important; font-weight: 500; margin-left: 10px; margin-right: 10px; transition: color var(--transition-fast), background-color var(--transition-fast); position: relative; padding: 0.5rem 0.8rem; border-radius: var(--border-radius-sm); font-size: 0.95rem; }
        .navbar .nav-link:hover { color: var(--couleur-primaire) !important; background-color: rgba(var(--couleur-accent-rgb), 0.1); }
        .navbar .nav-link.active { font-weight: 600; color: var(--couleur-accent) !important; background-color: rgba(var(--couleur-accent-rgb), 0.15); }
        .navbar .btn { padding: 0.4rem 1rem; font-weight: 600; transition: all var(--transition-fast); border-radius: var(--border-radius-sm); }
        .navbar .btn-accent { background-color: var(--couleur-accent); border-color: var(--couleur-accent); color: var(--couleur-primaire); }
        .navbar .btn-accent:hover { background-color: var(--couleur-accent-hover); border-color: var(--couleur-accent-hover); transform: translateY(-2px); }
        .navbar .btn-outline-light { border-color: var(--couleur-primaire) !important; color: var(--couleur-primaire) !important; background-color: transparent !important; }
        .navbar .btn-outline-light:hover { background-color: rgba(var(--couleur-primaire-rgb), 0.05) !important; color: var(--couleur-primaire) !important; border-color: var(--couleur-primaire) !important; }
        .dropdown-menu { border-radius: var(--border-radius-md); border: none; box-shadow: var(--shadow-lg); padding: 0.5rem 0; margin-top: 0.5rem; }
        .dropdown-item { padding: 0.6rem 1.2rem; font-size: 0.95rem; color: var(--couleur-texte-dark); transition: all var(--transition-fast); }
        .dropdown-item i { color: var(--couleur-tertiaire); margin-right: 0.75rem; transition: color var(--transition-fast); width: 1.1em; text-align: center; }
        .dropdown-item:hover, .dropdown-item:focus { background-color: var(--couleur-fond-section); color: var(--couleur-accent); }
        .dropdown-item:hover i, .dropdown-item:focus i { color: var(--couleur-accent); }
        .dropdown-divider { margin: 0.5rem 0; border-top: 1px solid var(--couleur-border); }
        .navbar-toggler { border-color: rgba(0,0,0, 0.1); padding: 0.3rem 0.6rem; }
        .navbar-toggler:focus { box-shadow: none; }
        .navbar-toggler-icon { background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%2810, 25, 47, 0.8%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e"); height: 1.8em; width: 1.8em; }

        @media (max-width: 991.98px) {
             .navbar-collapse { background-color: var(--couleur-fond-blanc); padding: 1rem; margin-top: 0.5rem; border-radius: var(--border-radius-md); box-shadow: var(--shadow-lg); border: 1px solid var(--couleur-border); }
            .navbar .nav-link { color: var(--couleur-texte-dark) !important; margin-left: 0; margin-right: 0; padding: 0.8rem 0.5rem; border-bottom: 1px solid var(--couleur-border); }
             .navbar .nav-link:hover, .navbar .nav-link.active { color: var(--couleur-accent) !important; background-color: rgba(var(--couleur-accent-rgb), 0.08); }
            .navbar .nav-item:last-child .nav-link { border-bottom: none; }
             .navbar .dropdown-menu { box-shadow: none; margin-top: 0; border-radius: 0; border-top: 1px solid var(--couleur-border); }
             .navbar .dropdown-item { padding-left: 1.5rem; }
             .navbar .navbar-nav .btn { width: 100%; margin-top: 0.75rem; display: flex; align-items: center; justify-content: center; }
             .navbar .navbar-nav .btn i { margin-right: 0.5rem; }
             .navbar .navbar-nav .btn-outline-light { border-color: var(--couleur-primaire); color: var(--couleur-primaire); }
             .navbar .navbar-nav .btn-outline-light:hover { background-color: var(--couleur-primaire); color: var(--couleur-fond-blanc); }
             .navbar .navbar-nav .btn-accent { background-color: var(--couleur-accent); color: var(--couleur-primaire); border-color: var(--couleur-accent); }
              .navbar .navbar-nav .btn-accent:hover { background-color: var(--couleur-accent-hover); border-color: var(--couleur-accent-hover); }
        }

        /* ==========================================================================
           Styles Spécifiques Page Détails Bien (Simplifiés)
           ========================================================================== */
        #property-details { padding-top: 60px; padding-bottom: 80px; }

        .property-title h1 {
            font-size: clamp(1.8rem, 4vw, 2.5rem);
            margin-bottom: 0.3em;
            line-height: 1.3;
        }
        .property-address {
            font-size: 1.1rem;
            color: var(--couleur-texte-secondaire);
            margin-bottom: 1.5rem;
            font-weight: 400;
        }
        .property-address i {
            margin-right: 0.3rem;
            vertical-align: middle;
            position: relative; top: -1px;
            color: var(--couleur-tertiaire);
        }

        .property-main-image img {
            width: 100%;
            height: auto;
            max-height: 550px;
            object-fit: cover;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--couleur-border);
        }

        .property-price {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--couleur-accent);
            font-family: var(--font-titre);
            margin-bottom: 0.5rem;
            line-height: 1.2;
        }
        .property-price small {
            font-size: 0.9rem;
            color: var(--couleur-texte-secondaire);
            font-weight: 400;
            font-family: var(--font-texte);
        }

        /* Section demande de visite (simplifiée) */
        .request-visit-section {
            background-color: var(--couleur-fond-section);
            padding: 35px;
            border-radius: var(--border-radius-lg);
            margin-top: 2rem; /* Moins de marge */
            text-align: center;
            border: 1px solid var(--couleur-border);
        }
         .request-visit-section h4 {
            font-size: 1.5rem;
            margin-bottom: 0.7rem;
            font-weight: 700;
        }
        .request-visit-section p {
            color: var(--couleur-texte-secondaire);
            margin-bottom: 1.8rem;
            font-size: 1rem;
        }
        .request-visit-btn {
            background-color: var(--couleur-accent);
            border-color: var(--couleur-accent);
            color: var(--couleur-primaire);
            font-weight: 700;
            font-family: var(--font-titre);
            padding: 0.9rem 2.5rem;
            font-size: 1.1rem;
            transition: all var(--transition-fast);
            border-radius: var(--border-radius-md);
        }
        .request-visit-btn:hover {
            background-color: var(--couleur-accent-hover);
            border-color: var(--couleur-accent-hover);
            transform: translateY(-3px) scale(1.03);
            box-shadow: var(--shadow-md);
        }
        .request-visit-btn i { vertical-align: middle; margin-right: 0.5rem; position: relative; top: -1px;}

        /* Style pour message d'erreur */
        #property-details .alert { margin-top: 2rem; }

        /* ==========================================================================
           Footer
           ========================================================================== */
        .site-footer { background-color: #081424; color: var(--couleur-texte-secondaire); padding: 70px 0 30px 0; font-size: 0.9rem; }
        .footer-brand { color: var(--couleur-fond-blanc); font-weight: 800; font-size: 1.6rem; font-family: var(--font-titre); }
        .footer-tagline { margin-bottom: 1.5rem; opacity: 0.8; }
        .footer-heading { color: var(--couleur-texte-primaire); font-family: var(--font-texte); font-weight: 600; letter-spacing: 0.5px; margin-bottom: 1.5rem; font-size: 1rem; text-transform: uppercase; opacity: 0.9; }
        .footer-links li { margin-bottom: 0.7rem; }
        .footer-links a { color: var(--couleur-texte-secondaire); transition: color var(--transition-fast), padding-left var(--transition-fast); display: inline-block; }
        .footer-links a:hover { color: var(--couleur-accent); padding-left: 5px; }
        .footer-contact li { margin-bottom: 0.8rem; display: flex; align-items: flex-start; line-height: 1.6; }
        .footer-contact i { color: var(--couleur-accent); margin-right: 12px; font-size: 1.1rem; margin-top: 4px; flex-shrink: 0; width: 1.2em; }
        .footer-contact span { display: block; } .footer-contact a { color: var(--couleur-texte-secondaire); } .footer-contact a:hover { color: var(--couleur-accent); }
        .social-icons a { color: var(--couleur-texte-secondaire); font-size: 1.4rem; margin-right: 15px; transition: color var(--transition-fast), transform var(--transition-fast); display: inline-block; }
        .social-icons a:hover { color: var(--couleur-accent); transform: translateY(-3px); } .social-icons a:last-child { margin-right: 0; }
        .footer-bottom { border-top: 1px solid rgba(255, 255, 255, 0.1); padding-top: 30px; margin-top: 40px; }
        .footer-bottom p { color: rgba(204, 214, 246, 0.6); font-size: 0.85rem; } .footer-bottom a { color: rgba(204, 214, 246, 0.7); } .footer-bottom a:hover { color: var(--couleur-accent); }

        /* ==========================================================================
           Responsives
           ========================================================================== */
        @media (max-width: 767px) {
            body { padding-top: 70px; }
            .property-title h1 { font-size: 1.6rem; }
             #property-details { padding-top: 40px; padding-bottom: 60px; }
        }
    </style>
    <!-- Fin CSS Intégré -->
</head>
<body>
    <div class="wrapper">
        <header>
             <!-- Navbar -->
             <nav class="navbar navbar-expand-lg fixed-top">
                 <div class="container">
                     <a class="navbar-brand" href="index.php"> GestionLocative<span class="accent">.</span> </a>
                     <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation"> <span class="navbar-toggler-icon"></span> </button>
                     <div class="collapse navbar-collapse" id="navbarNav">
                         <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
                             <li class="nav-item"><a class="nav-link" href="index.php">Accueil</a></li>
                             <li class="nav-item"><a class="nav-link" href="biens-disponibles.php">Biens à Louer</a></li>
                             <li class="nav-item"><a class="nav-link" href="index.php#services">Nos Services</a></li>
                             <li class="nav-item"><a class="nav-link" href="contact.php">Contact</a></li>
                             <li class="nav-item dropdown"> <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownAccount" role="button" data-bs-toggle="dropdown" aria-expanded="false"> Espace Membre </a> <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdownAccount"> <li><a class="dropdown-item" href="auth-signin.php"><i class="ri-login-box-line"></i>Se Connecter</a></li> <li><a class="dropdown-item" href="auth-signup.php"><i class="ri-user-add-line"></i>S'inscrire</a></li> <li><hr class="dropdown-divider"></li> <li><a class="dropdown-item" href="proprietaire-dashboard.php"><i class="ri-user-settings-line"></i>Espace Propriétaire</a></li> <li><a class="dropdown-item" href="locataire-dashboard.php"><i class="ri-user-line"></i>Espace Locataire</a></li> <li><a class="dropdown-item" href="notaire-dashboard.php"><i class="ri-shield-user-line"></i>Espace Notaire</a></li> </ul> </li>
                             <li class="nav-item mt-3 d-lg-none"><a href="auth-signin.php" class="btn btn-outline-light w-100 btn-sm"><i class="ri-login-box-line"></i> Connexion</a></li>
                             <li class="nav-item mt-2 mb-2 d-lg-none"><a href="auth-signup.php" class="btn btn-accent w-100 btn-sm"><i class="ri-user-add-line"></i> Inscription</a></li>
                             <li class="nav-item ms-lg-2 d-none d-lg-block"><a href="auth-signin.php" class="btn btn-outline-light btn-sm">Connexion</a></li>
                             <li class="nav-item ms-lg-2 d-none d-lg-block"><a href="auth-signup.php" class="btn btn-accent btn-sm">Inscription</a></li>
                         </ul>
                     </div>
                 </div>
             </nav>
        </header>

        <main>
            <section id="property-details" class="section-padding">
                <div class="container">

                    <?php if (!empty($errorMessage)): ?>
                        <div class="alert alert-danger text-center" role="alert">
                            <h4 class="alert-heading"><i class="ri-error-warning-line me-2"></i>Oups !</h4>
                            <p><?php echo htmlspecialchars($errorMessage); ?></p>
                             <?php if (!empty($debugMessage) /* && MODE_DEBUG_ACTIF */): ?>
                                <hr><p class="small mb-0"><strong>Détails techniques :</strong> <?php echo htmlspecialchars($debugMessage); ?></p>
                            <?php endif; ?>
                            <hr>
                            <p class="mb-0">Vous pouvez retourner à la liste des biens disponibles.</p>
                            <a href="biens-disponibles.php" class="btn btn-secondary mt-3">Voir les biens</a>
                        </div>

                    <?php elseif ($bien): // Affiche le contenu seulement si $bien est défini et non vide ?>
                        <?php
                            // Préparation des données ESSENTIELLES récupérées
                            $adresse = htmlspecialchars($bien['adresse'] ?? 'N/A');
                            $loyer = (float)($bien['loyerMensuel'] ?? 0);
                            $loyerFormatte = number_format($loyer, 0, ',', ' ') . ' FCFA';
                            $imageName = htmlspecialchars($bien['image_profil'] ?? '');
                            $imagePathCheck = __DIR__ . '/uploads/biens/' . $imageName;
                            $webImagePathPrefix = 'uploads/biens/';
                            $defaultImagePath = 'assets/images/property/default.jpg';
                            $imageUrl = (!empty($imageName) && file_exists($imagePathCheck)) ? $webImagePathPrefix . $imageName : $defaultImagePath;

                            // Créer le lien pour la demande de visite
                            $contactSubject = "Demande de visite pour le bien ID " . $bienId;
                            $contactMessage = "Bonjour,\n\nJe suis intéressé(e) par le bien situé à " . urlencode($adresse) . " (Référence ID: " . $bienId . ").\n\nJ'aimerais obtenir plus d'informations et potentiellement planifier une visite.\n\nMerci de me recontacter.\n\nCordialement,";
                            $contactUrl = "contact.php?subject=" . $contactSubject . "&message=" . $contactMessage;
                        ?>

                        <div class="row g-lg-5 mb-5">
                            <!-- Colonne Image Principale -->
                            <div class="col-lg-7 order-lg-1">
                                <div class="property-main-image mb-4 mb-lg-0 position-relative">
                                    <img src="<?= $imageUrl ?>" alt="Image principale du bien à <?= $adresse ?>" class="img-fluid">
                                    <!-- On pourrait ajouter un badge 'A Louer' ou autre ici si besoin -->
                                    <!-- <span class="badge bg-success position-absolute top-0 start-0 m-3 fs-6">À Louer</span> -->
                                </div>
                            </div>

                            <!-- Colonne Détails -->
                            <div class="col-lg-5 order-lg-0 d-flex flex-column">
                                <div class="property-title">
                                    <h1><?= $adresse ?></h1>
                                </div>

                                <div class="property-price my-4">
                                    <?= $loyerFormatte ?> <small>/ mois</small>
                                </div>

                                <!-- On retire les specs détaillées (superficie, pièces...) car non sélectionnées -->
                                <!-- <h4 class="h5 mb-3 fw-bold">En Bref :</h4> -->
                                <!-- <ul class="property-specs"> ... </ul> -->

                                <p class="mt-3 text-muted">
                                    Pour plus de détails sur ce bien ou pour organiser une visite, veuillez nous contacter.
                                </p>

                                <!-- Bouton Demande de Visite -->
                                <div class="d-grid gap-2 mt-auto pt-3"> <!-- mt-auto pousse le bouton en bas -->
                                    <a href="<?= $contactUrl ?>" class="btn btn-lg request-visit-btn">
                                        <i class="ri-calendar-check-line"></i> Demander une visite
                                    </a>
                                </div>
                            </div>
                        </div><!-- / .row entete -->

                        <!-- Sections Description et Caractéristiques retirées car les colonnes ne sont pas sélectionnées -->

                    <?php endif; // Fin de la condition if($bien) ?>

                </div><!-- / .container -->
            </section>
        </main>

        <!-- Footer -->
        <footer class="site-footer">
             <div class="container"> <div class="row g-4 g-lg-5 pb-5"> <div class="col-lg-4 col-md-6"> <h5 class="footer-brand mb-3">GestionLocative<span class="accent">.</span></h5> <p class="footer-tagline">La gestion locative sécurisée par l'expertise notariale au Bénin.</p> <div class="social-icons mt-4"> <a href="#" aria-label="Facebook" class="social-icon"><i class="ri-facebook-fill"></i></a> <a href="#" aria-label="Twitter" class="social-icon"><i class="ri-twitter-x-line"></i></a> <a href="#" aria-label="LinkedIn" class="social-icon"><i class="ri-linkedin-fill"></i></a> </div> </div> <div class="col-lg-2 col-md-3 col-6"> <h6 class="footer-heading">Navigation</h6> <ul class="list-unstyled footer-links"> <li><a href="index.php">Accueil</a></li> <li><a href="biens-disponibles.php">Biens à louer</a></li> <li><a href="index.php#services">Nos services</a></li> <li><a href="contact.php">Contact</a></li> </ul> </div> <div class="col-lg-2 col-md-3 col-6"> <h6 class="footer-heading">Espaces</h6> <ul class="list-unstyled footer-links"> <li><a href="auth-signin.php">Connexion</a></li> <li><a href="auth-signup.php">Inscription</a></li> <li><a href="locataire-dashboard.php">Espace Locataire</a></li> <li><a href="proprietaire-dashboard.php">Espace Propriétaire</a></li> <li><a href="notaire-dashboard.php">Espace Notaire</a></li> </ul> </div> <div class="col-lg-4 col-md-12 order-md-first order-lg-last"> <h6 class="footer-heading">Nous Contacter</h6> <ul class="list-unstyled footer-contact"> <li><i class="ri-map-pin-line"></i> <span>123 Rue Imaginaire, Cotonou, Bénin</span></li> <li><i class="ri-phone-line"></i> <a href="tel:+229XXXXXXXX">+229 XX XX XX XX</a></li> <li><i class="ri-mail-line"></i> <a href="mailto:contact@gestionlocative-notaire.bj">contact@gestionlocative-notaire.bj</a></li> <li><i class="ri-time-line"></i> <span>Lun - Ven : 9h00 - 17h00</span></li> </ul> </div> </div> <div class="footer-bottom text-center pt-4"> <p class="mb-0">© <script>document.write(new Date().getFullYear())</script> GestionLocative Notariale. Tous droits réservés.</p> </div> </div>
        </footer>
    </div><!-- /.wrapper -->

    <!-- Scripts JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollToPlugin.min.js"></script>

    <!-- JavaScript Intégré -->
    <script>
        /**
         * GestLocative - Animations et Interactions Page Détails Bien (Intégré - Simplifié)
         */
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Détails Bien JS (Intégré): Initialized.");
            if (typeof gsap === 'undefined') console.error("GSAP n'est pas chargé.");
            if (typeof ScrollToPlugin === 'undefined') console.error("ScrollToPlugin n'est pas chargé.");

            gsap.registerPlugin(ScrollToPlugin); // ScrollTrigger n'est plus utilisé ici

            initDetailsAnimation();
            initSmoothScroll();
        });

        function initDetailsAnimation() {
            const mainContent = document.querySelector('#property-details .container > .row:first-child');
            if(mainContent) {
                gsap.from(mainContent.children, {
                    delay: 0.2, y: 50, opacity: 0, duration: 0.8, stagger: 0.15, ease: "power3.out"
                });
            }
             // Pas besoin d'animer la description/features car elles sont retirées
        }

        function initSmoothScroll() {
            document.querySelectorAll('a[href^="#"]').forEach(anchor => { anchor.addEventListener('click', function (e) { const href = anchor.getAttribute('href'); if (!href || href === '#' || href.startsWith('#!') || href === '#0' || document.querySelector(href)?.closest('.collapse')) return; const targetElement = document.querySelector(href); if (targetElement) { e.preventDefault(); let navbarHeight = 0; const fixedNavbar = document.querySelector('.navbar.fixed-top'); if(fixedNavbar) navbarHeight = fixedNavbar.offsetHeight; gsap.to(window, { duration: 1.2, scrollTo: { y: targetElement, offsetY: navbarHeight + 10 }, ease: "power2.inOut" }); } }); });
        }
    </script>
    <!-- Fin JavaScript Intégré -->
</body>
</html>