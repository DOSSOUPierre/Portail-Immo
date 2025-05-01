<?php
// --- biens-disponibles.php (CORRIGÉ - Lien vers details_bien.php) ---
if (session_status() === PHP_SESSION_NONE) session_start();

// --- Débogage (Décommentez si besoin) ---
/*
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

require_once __DIR__ . '/db_connection.php'; // $pdo

// --- Récupération des filtres (Fourchette de Loyer Uniquement) ---
$loyer_min_filter = isset($_GET['loyer_min']) && is_numeric($_GET['loyer_min']) && $_GET['loyer_min'] >= 0 ? (int)$_GET['loyer_min'] : null;
$loyer_max_filter = isset($_GET['loyer_max']) && is_numeric($_GET['loyer_max']) && $_GET['loyer_max'] >= 0 ? (int)$_GET['loyer_max'] : null;

// --- Construction de la requête SQL de base ---
$sql = "SELECT
            b.idBien,
            b.adresse,
            b.loyerMensuel,
            b.image_profil,
            b.statut,
            b.supervisionStatut
            -- La colonne typeBien n'est pas sélectionnée car non utilisée ici
        FROM
            bienimmobiliers b
        WHERE
            b.statut = 'Libre'
            AND b.supervisionStatut = 'Validé'";

$params = []; // Tableau pour les paramètres

// --- Ajout dynamique des filtres (FOURCHETTE DE LOYER) ---
if ($loyer_min_filter !== null) {
    $sql .= " AND b.loyerMensuel >= :loyer_min";
    $params[':loyer_min'] = $loyer_min_filter;
}

if ($loyer_max_filter !== null) {
    if ($loyer_min_filter === null || $loyer_max_filter >= $loyer_min_filter) {
        $sql .= " AND b.loyerMensuel <= :loyer_max";
        $params[':loyer_max'] = $loyer_max_filter;
    }
}

// --- Ajout du tri ---
$sql .= " ORDER BY b.idBien DESC";

// --- Exécution de la requête ---
$biens = [];
$errorMessage = '';
$debugMessage = '';
try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new Exception("Connexion PDO non valide.");
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $biens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($biens) && $stmt->rowCount() === 0) {
         $debugMessage = "Aucun bien trouvé correspondant aux critères.";
    }

} catch (PDOException $e) {
    error_log("Erreur PDO BDD dispos: " . $e->getMessage() . " SQL: " . $sql . " Params: " . print_r($params, true));
    $errorMessage = "Erreur lors de la récupération des biens.";
    $debugMessage = "PDO Error: " . $e->getMessage();
} catch (Exception $e) {
    error_log("Erreur Générale BDD dispos: " . $e->getMessage());
    $errorMessage = "Erreur technique.";
    $debugMessage = "General Error: " . $e->getMessage();
}

?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Biens Immobiliers Disponibles à la Location | Gestion Locative Notariale</title>
    <meta name="description" content="Consultez tous nos biens immobiliers disponibles à la location au Bénin, avec la sécurité de la gestion notariale.">
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
             padding-top: 80px; /* AJOUT: Espace pour la navbar fixe */
        }
        @media (min-width: 992px) {
            body { padding-top: 90px; } /* Ajuster si la navbar change de taille */
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
           Navbar v2 - Nouveau Style
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
           Page Header
           ========================================================================== */
        .page-header { background-color: var(--couleur-primaire); padding: 50px 0; text-align: center; color: var(--couleur-fond-blanc); margin-bottom: 0; }
        .page-header h1 { color: var(--couleur-fond-blanc); font-size: clamp(2rem, 5vw, 3rem); margin-bottom: 0.2em; }
        .page-header p { color: var(--couleur-texte-secondaire); font-size: 1.1rem; max-width: 600px; margin: 0 auto; }

        /* ==========================================================================
           Section Filtres
           ========================================================================== */
        .filters-section { background-color: var(--couleur-fond-section); padding: 30px 0; border-bottom: 1px solid var(--couleur-border); }
        .filters-form label { font-weight: 500; margin-bottom: 0.5rem; font-size: 0.9rem; color: var(--couleur-primaire); }
        .filters-form .form-control, .filters-form .form-select { border-radius: var(--border-radius-md); font-size: 0.95rem; border-color: var(--couleur-border); }
         .filters-form .form-control:focus, .filters-form .form-select:focus { border-color: var(--couleur-accent); box-shadow: 0 0 0 0.2rem rgba(var(--couleur-accent-rgb), 0.25); }
        .filters-form .btn-primary { background-color: var(--couleur-primaire); border-color: var(--couleur-primaire); color: white; font-weight: 600; padding: 0.6rem 1.5rem; }
        .filters-form .btn-primary:hover { background-color: var(--couleur-secondaire); border-color: var(--couleur-secondaire); }
        .filters-form .btn-secondary { padding: 0.6rem 1.5rem; font-weight: 500; }

        /* ==========================================================================
           Liste des Biens
           ========================================================================== */
        #property-listings { padding-top: 60px; padding-bottom: 60px; /* Espace avant footer */ }
        .no-results-message { border: 1px dashed var(--couleur-border); padding: 40px; text-align: center; border-radius: var(--border-radius-lg); background-color: #fff; margin-top: 30px; }
         .no-results-message i { font-size: 3rem; color: var(--couleur-tertiaire); margin-bottom: 1rem; display: block; }
         .no-results-message p { color: var(--couleur-texte-secondaire); margin-bottom: 0; }

        /* Styles .property-card */
        .property-card { background-color: var(--couleur-fond-blanc); border-radius: var(--border-radius-lg); box-shadow: var(--shadow-md); overflow: hidden; position: relative; transition: transform var(--transition-base) var(--easing-smooth), box-shadow var(--transition-base) var(--easing-smooth); border: 1px solid var(--couleur-border); display: flex; flex-direction: column; height: 100%; }
        .property-card:hover { box-shadow: var(--shadow-lg); transform: translateY(-8px); }
        .property-card .img-container { overflow: hidden; position: relative; height: 240px; }
        .property-card .card-img-top { display: block; width: 100%; height: 100%; object-fit: cover; transition: transform 0.6s var(--easing-smooth); }
        .property-card:hover .card-img-top { transform: scale(1.08); }
        .property-card .card-body { padding: 20px; position: relative; z-index: 2; background: var(--couleur-fond-blanc); border-radius: 0 0 var(--border-radius-lg) var(--border-radius-lg); display: flex; flex-direction: column; flex-grow: 1; }
        .property-card .card-title { margin-bottom: 0.3rem !important; }
        .property-card .card-title a { color: var(--couleur-primaire); text-decoration: none; font-weight: 700; font-size: 1.15rem; transition: color var(--transition-fast); line-height: 1.4; display: block; }
        .property-card .card-title a:hover { color: var(--couleur-accent); }
        /* Lien qui couvre toute la carte via le titre */
        .property-card .card-title a.stretched-link::after { position: absolute; top: 0; right: 0; bottom: 0; left: 0; z-index: 1; pointer-events: auto; content: ""; background-color: rgba(0,0,0,0); }
        .property-card .card-text.text-muted { color: var(--couleur-texte-secondaire) !important; font-size: 0.85rem; line-height: 1.5; margin-bottom: 1rem; }
        .property-card .card-text i { vertical-align: middle; font-size: 1rem; position: relative; top: -1px; margin-right: 0.25rem; }
        .property-card .price { font-size: 1.4rem; font-weight: 700; color: var(--couleur-accent); font-family: var(--font-titre); line-height: 1; }
        .property-card .price small { font-size: 0.75rem; color: var(--couleur-texte-secondaire); font-weight: 400; font-family: var(--font-texte); margin-left: 4px; }
        .property-card .detail-arrow { color: var(--couleur-tertiaire); font-size: 1.3rem; transition: transform var(--transition-fast), color var(--transition-fast); opacity: 0; transform: translateX(-5px); /* z-index: 2; */ /* Au-dessus du stretched-link si besoin mais normalement pas */ }
        .property-card:hover .detail-arrow { opacity: 1; color: var(--couleur-accent); transform: translateX(0px); }
         /* Le badge n'est plus utilisé ici */

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
        @media (max-width: 991.98px) {
            .filters-form .row > div { margin-bottom: 0.75rem; }
            .filters-form .d-grid { margin-bottom: 0 !important; }
        }
        @media (max-width: 767.98px) {
            .page-header { padding: 40px 0; }
            .filters-section { padding: 20px 0; }
            .section-padding { padding: 60px 0; }
            #property-listings { padding-top: 40px; padding-bottom: 40px; }
            .footer-heading { margin-top: 2rem; }
            .footer-heading:first-child { margin-top: 0; }
            .footer-contact { margin-top: 2rem; }
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
                             <li class="nav-item"><a class="nav-link active" href="biens-disponibles.php">Biens à Louer</a></li>
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
            <!-- En-tête de la page -->
            <section class="page-header">
                <div class="container">
                    <h1>Nos Biens Disponibles</h1>
                    <p>Trouvez le logement idéal parmi nos offres sécurisées par notaire.</p>
                </div>
            </section>

            <!-- Section Filtres (UNIQUEMENT Fourchette de Loyer) -->
            <section class="filters-section">
                <div class="container">
                    <form action="biens-disponibles.php" method="GET" class="filters-form">
                        <div class="row g-3 align-items-end justify-content-center">
                             <div class="col-lg-4 col-md-5">
                                 <label for="filter-loyer_min" class="form-label">Loyer Min (FCFA)</label>
                                 <input type="number" id="filter-loyer_min" name="loyer_min" class="form-control" placeholder="Min" min="0" step="5000" value="<?= htmlspecialchars($loyer_min_filter ?? '') ?>">
                             </div>
                             <div class="col-lg-4 col-md-5">
                                 <label for="filter-loyer_max" class="form-label">Loyer Max (FCFA)</label>
                                 <input type="number" id="filter-loyer_max" name="loyer_max" class="form-control" placeholder="Max" min="0" step="5000" value="<?= htmlspecialchars($loyer_max_filter ?? '') ?>">
                             </div>
                             <div class="col-lg-2 col-md-2 d-grid">
                                 <button type="submit" class="btn btn-primary"><i class="ri-filter-3-line me-1"></i>Filtrer</button>
                             </div>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Liste des Biens -->
            <section id="property-listings" class="section-padding pt-5">
                <div class="container">

                    <!-- Affichage Erreur Générale -->
                    <?php if (!empty($errorMessage)): ?>
                        <div class="alert alert-danger text-center" role="alert">
                            <i class="ri-error-warning-line me-2"></i><?php echo htmlspecialchars($errorMessage); ?>
                             <?php if (!empty($debugMessage) /* && MODE_DEBUG_ACTIF */): ?>
                                <hr><p class="small mb-0"><strong>Détails techniques :</strong> <?php echo htmlspecialchars($debugMessage); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Grille des résultats -->
                    <div class="row g-4">
                        <?php if (empty($errorMessage) && empty($biens)): ?>
                            <!-- Message Aucun Résultat -->
                            <div class="col-12">
                                <div class="no-results-message">
                                     <i class="ri-search-eye-line"></i>
                                     <h4>Aucun bien trouvé</h4>
                                     <p>Nous n'avons trouvé aucun bien correspondant à votre fourchette de loyer.</p>
                                     <?php if ($loyer_min_filter !== null || $loyer_max_filter !== null): ?>
                                        <a href="biens-disponibles.php" class="btn btn-sm btn-outline-secondary mt-3">Voir tous les biens</a>
                                     <?php endif; ?>
                                </div>
                            </div>
                        <?php elseif (!empty($biens)): ?>
                            <!-- Boucle d'affichage des biens -->
                            <?php foreach ($biens as $index => $bien): ?>
                                <?php
                                    // Préparation des données
                                    $bienId = htmlspecialchars($bien['idBien'] ?? '');
                                    $bienAdresse = htmlspecialchars($bien['adresse'] ?? 'N/A');
                                    $imageName = htmlspecialchars($bien['image_profil'] ?? '');
                                    $imagePathCheck = __DIR__ . '/uploads/biens/' . $imageName;
                                    $webImagePathPrefix = 'uploads/biens/';
                                    $defaultImagePath = 'assets/images/property/default.jpg';
                                    $imageUrl = (!empty($imageName) && file_exists($imagePathCheck)) ? $webImagePathPrefix . $imageName : $defaultImagePath;
                                    $loyer = (float)($bien['loyerMensuel'] ?? 0);
                                    $loyerFormatte = number_format($loyer, 0, ',', ' ') . ' FCFA';

                                    // *** CORRECTION DU LIEN ICI ***
                                    $detailsUrl = "details_bien.php?id=" . urlencode($bienId); // Utilise details_bien.php

                                    $adresseCourte = function_exists('mb_substr') ? mb_substr($bienAdresse, 0, 45) : substr($bienAdresse, 0, 45);
                                    if ( (function_exists('mb_strlen') ? mb_strlen($bienAdresse) : strlen($bienAdresse)) > 45) { $adresseCourte .= '...'; }
                                ?>
                                <div class="col-lg-4 col-md-6 property-card-item">
                                    <div class="card property-card h-100">
                                        <div class="img-container position-relative">
                                             <!-- L'image elle-même pointe maintenant vers details_bien.php -->
                                             <a href="<?= $detailsUrl ?>" class="property-link-img" aria-label="Voir les détails du bien à <?= $bienAdresse ?>">
                                                <img src="<?= $imageUrl ?>" class="card-img-top" alt="Photo du bien à <?= $bienAdresse ?>" loading="lazy">
                                             </a>
                                             <!-- Pas de badge type -->
                                        </div>
                                        <div class="card-body d-flex flex-column">
                                            <h3 class="card-title h5 mb-1">
                                                <!-- Le titre pointe maintenant vers details_bien.php -->
                                                <a href="<?= $detailsUrl ?>" class="text-decoration-none stretched-link"><?= $adresseCourte ?></a>
                                            </h3>
                                            <p class="card-text text-muted small mb-3 flex-grow-1">
                                                <i class="ri-map-pin-fill"></i><?= $bienAdresse ?>
                                            </p>
                                            <div class="mt-auto d-flex justify-content-between align-items-center">
                                                <span class="price"><?= $loyerFormatte ?> <small>/ mois</small></span>
                                                <!-- La flèche est juste visuelle, le clic est géré par stretched-link -->
                                                <span class="detail-arrow"><i class="ri-arrow-right-line"></i></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; // Fin condition affichage ?>
                    </div>

                    <!-- Pagination (à implémenter si besoin) -->

                </div>
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
    <!-- tsParticles n'est pas nécessaire ici -->

    <!-- JavaScript Intégré -->
    <script>
        /**
         * GestLocative - Animations et Interactions Page Listing Biens (Intégré)
         */
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Biens Disponibles JS (Intégré): Initialized.");
            if (typeof gsap === 'undefined') console.error("GSAP n'est pas chargé.");
            if (typeof ScrollTrigger === 'undefined') console.error("ScrollTrigger n'est pas chargé.");
            if (typeof ScrollToPlugin === 'undefined') console.error("ScrollToPlugin n'est pas chargé.");

            gsap.registerPlugin(ScrollTrigger, ScrollToPlugin);

            initPropertyListAnimation();
            initSmoothScroll();
        });

        function initPropertyListAnimation() {
            const propertyItems = gsap.utils.toArray('.property-card-item');
            if (propertyItems.length === 0) return;
            gsap.from(propertyItems, {
                delay: 0.2, y: 60, opacity: 0, duration: 0.7, stagger: 0.1, ease: "power2.out"
            });
            const enable3DEffect = false; const cards = document.querySelectorAll('.property-card');
            cards.forEach(card => { if (enable3DEffect) { /* Effet 3D JS ici */ } });
        }

        function initSmoothScroll() {
            document.querySelectorAll('a[href^="#"]').forEach(anchor => { anchor.addEventListener('click', function (e) { const href = anchor.getAttribute('href'); if (!href || href === '#' || href.startsWith('#!') || href === '#0' || document.querySelector(href)?.closest('.collapse')) return; const targetElement = document.querySelector(href); if (targetElement) { e.preventDefault(); let navbarHeight = 0; const fixedNavbar = document.querySelector('.navbar.fixed-top'); if(fixedNavbar) navbarHeight = fixedNavbar.offsetHeight; gsap.to(window, { duration: 1.2, scrollTo: { y: targetElement, offsetY: navbarHeight + 10 }, ease: "power2.inOut" }); } }); });
        }
    </script>
    <!-- Fin JavaScript Intégré -->
</body>
</html>