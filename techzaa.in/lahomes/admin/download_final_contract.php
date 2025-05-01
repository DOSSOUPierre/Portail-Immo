<?php
// --- index.php (Page d'Accueil - TOUT EN UN SEUL FICHIER - V2 avec corrections & nouveau menu) ---

// IMPORTANT : Pour le débogage des biens non affichés, décommentez ces lignes temporairement.
// N'oubliez PAS de les commenter ou supprimer en production !
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();

// --- Récupération des Biens à la Une ---
// Assurez-vous que ce chemin est correct
require_once __DIR__ . '/db_connection.php';

$biensAlaUne = [];
$errorMessage = ''; // Message d'erreur pour l'utilisateur
$debugMessage = ''; // Message pour le développeur (logs ou conditionnel)

try {
    // Vérifie si la connexion PDO ($pdo) a bien été créée dans db_connection.php
    if (!isset($pdo) || !$pdo instanceof PDO) {
         // $pdo n'est pas défini ou n'est pas un objet PDO valide
         throw new Exception("La variable de connexion PDO n'est pas valide après inclusion de db_connection.php.");
    }

    // *** VÉRIFIEZ ATTENTIVEMENT CES NOMS DE TABLES ET COLONNES ***
    // Ils doivent correspondre EXACTEMENT à votre base de données (y compris la casse si nécessaire)
    $sql = "SELECT
                b.idBien,
                b.adresse,
                b.loyerMensuel,
                b.image_profil,
                b.statut,           -- Colonne 'statut' dans 'bienimmobiliers'
                b.supervisionStatut,-- Colonne 'supervisionStatut' dans 'bienimmobiliers'
                tb.nomType AS typeBien -- Colonne 'nomType' dans 'typebiens' (via jointure)
            FROM
                bienimmobiliers b    -- Nom de la table des biens
            LEFT JOIN
                typebiens tb ON b.idTypeBien = tb.idTypeBien -- Jointure basée sur 'idTypeBien' dans les deux tables
            WHERE
                b.statut = 'Libre'           -- Vérifiez si la valeur 'Libre' est correcte
                AND b.supervisionStatut = 'Validé' -- Vérifiez si la valeur 'Validé' est correcte
            ORDER BY
                b.idBien DESC -- Ou b.dateAjout DESC si vous avez une colonne de date
            LIMIT 3";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $biensAlaUne = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Message de débogage si aucun bien n'est trouvé MAIS pas d'erreur SQL
    if (empty($biensAlaUne) && $stmt->rowCount() === 0) {
        $debugMessage = "Aucun bien trouvé correspondant aux critères (Statut='Libre', Supervision='Validé'). Vérifiez les données en base.";
        // Vous pouvez choisir d'afficher un message plus doux à l'utilisateur
        // $errorMessage = "Aucun bien à la une disponible pour le moment."; // Déjà géré dans le HTML
    }

} catch (PDOException $e) {
    // Erreur spécifique à la base de données (connexion, SQL, etc.)
    error_log("Erreur PDO chargement biens accueil: " . $e->getMessage());
    // Message générique pour l'utilisateur
    $errorMessage = "Impossible de charger les informations des biens actuellement. Veuillez réessayer plus tard.";
    // Message détaillé pour le debug (ne pas afficher à l'utilisateur)
    $debugMessage = "Erreur PDO: " . $e->getMessage();
} catch (Exception $e) {
    // Autre type d'erreur (ex: $pdo non défini)
    error_log("Erreur générale chargement biens accueil: " . $e->getMessage());
    $errorMessage = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
    $debugMessage = "Erreur Générale: " . $e->getMessage();
}

// Optionnel: Afficher le message de débogage si on est en mode développement
// if (!empty($debugMessage) && filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN)) {
//     echo '<div class="alert alert-danger m-3">Debug Info: ' . htmlspecialchars($debugMessage) . '</div>';
// }

?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestion Locative Notariale | Sécurité et Simplicité au Bénin</title> <!-- Titre plus spécifique -->
    <meta name="description" content="Découvrez une gestion locative moderne et sécurisée au Bénin, supervisée par votre notaire. Trouvez votre prochain logement ou optimisez la gestion de vos biens.">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <!-- Assurez-vous que ce chemin est correct depuis la racine -->
    <link rel="shortcut icon" href="assets/images/favicon.ico">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Syne:wght@700;800&display=swap" rel="stylesheet">

    <!-- Librairies CSS (CDN) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.2.0/fonts/remixicon.css" rel="stylesheet"/>

    <!-- === CSS Intégré === -->
    <style>
        /* ==========================================================================
           Base & Variables (Identique)
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
           Reset & Global Styles (Identique)
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
            background-color: transparent; /* Commence transparent */
            position: fixed;
            width: 100%;
            z-index: 1030;
            border: none;
        }

        .navbar-brand {
            color: var(--couleur-fond-blanc) !important;
            font-weight: 800;
            font-size: 1.8rem;
            font-family: var(--font-titre);
            transition: color var(--transition-fast);
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.9) !important; /* Légèrement plus visible */
            font-weight: 500;
            margin-left: 10px; /* Moins d'espace */
            margin-right: 10px;
            transition: color var(--transition-fast), background-color var(--transition-fast);
            position: relative;
            padding: 0.5rem 0.8rem; /* Padding pour hover */
            border-radius: var(--border-radius-sm); /* Coins arrondis pour hover */
            font-size: 0.95rem;
        }
        /* Pas de soulignement ::after */
        /* .nav-link::after { display: none; } */

        /* Hover/Active sur Navbar transparente */
        .nav-link:hover,
        .nav-link.active {
            color: var(--couleur-fond-blanc) !important;
            background-color: rgba(255, 255, 255, 0.1); /* Léger fond blanc transparent */
        }
        .nav-link.active {
             font-weight: 600; /* Un peu plus gras si actif */
        }

        /* Navbar au scroll */
        .navbar.scrolled {
            background-color: var(--couleur-fond-blanc) !important;
            box-shadow: var(--shadow-md);
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
        }
        .navbar.scrolled .navbar-brand {
            color: var(--couleur-primaire) !important;
        }
        .navbar.scrolled .nav-link {
            color: var(--couleur-texte-dark) !important;
        }
        /* Hover/Active sur Navbar scrollée (blanche) */
        .navbar.scrolled .nav-link:hover,
        .navbar.scrolled .nav-link.active {
            color: var(--couleur-primaire) !important; /* Texte plus sombre */
            background-color: rgba(var(--couleur-accent-rgb), 0.1); /* Fond léger couleur accent */
        }
        .navbar.scrolled .nav-link.active {
            font-weight: 600;
            color: var(--couleur-accent) !important; /* Texte actif en couleur accent */
            background-color: rgba(var(--couleur-accent-rgb), 0.15); /* Fond actif un peu plus prononcé */
        }


        /* Boutons Navbar */
        .navbar .btn { padding: 0.4rem 1rem; font-weight: 600; transition: all var(--transition-fast); border-radius: var(--border-radius-sm); }

        .navbar .btn-accent {
            background-color: var(--couleur-accent); border-color: var(--couleur-accent); color: var(--couleur-primaire);
        }
        .navbar .btn-accent:hover {
            background-color: var(--couleur-accent-hover); border-color: var(--couleur-accent-hover); transform: translateY(-2px);
        }

        /* Bouton Outline Initial (transparent) */
        .navbar .btn-outline-light {
            border-color: rgba(255, 255, 255, 0.7); color: var(--couleur-fond-blanc);
        }
        .navbar .btn-outline-light:hover {
            background-color: rgba(255, 255, 255, 0.1); color: var(--couleur-fond-blanc); border-color: var(--couleur-fond-blanc);
        }

        /* Bouton Outline au Scroll (blanc) -> Devient outline-primary */
        .navbar.scrolled .btn-outline-light {
            border-color: var(--couleur-primaire) !important; /* Couleur primaire pour contour */
            color: var(--couleur-primaire) !important;
            background-color: transparent !important; /* Assurer pas de fond */
        }
        .navbar.scrolled .btn-outline-light:hover {
            background-color: rgba(var(--couleur-primaire-rgb), 0.05) !important; /* Léger fond bleu au survol */
            color: var(--couleur-primaire) !important;
            border-color: var(--couleur-primaire) !important;
        }

        /* Dropdown Menu (Identique) */
        .dropdown-menu { border-radius: var(--border-radius-md); border: none; box-shadow: var(--shadow-lg); padding: 0.5rem 0; margin-top: 0.5rem; }
        .dropdown-item { padding: 0.6rem 1.2rem; font-size: 0.95rem; color: var(--couleur-texte-dark); transition: all var(--transition-fast); }
        .dropdown-item i { color: var(--couleur-tertiaire); margin-right: 0.75rem; transition: color var(--transition-fast); width: 1.1em; text-align: center; }
        .dropdown-item:hover, .dropdown-item:focus { background-color: var(--couleur-fond-section); color: var(--couleur-accent); }
        .dropdown-item:hover i, .dropdown-item:focus i { color: var(--couleur-accent); }
        .dropdown-divider { margin: 0.5rem 0; border-top: 1px solid var(--couleur-border); }

        /* Navbar Toggler (Identique) */
        .navbar-toggler { border-color: rgba(255, 255, 255, 0.3); padding: 0.3rem 0.6rem; }
        .navbar-toggler:focus { box-shadow: none; }
        .navbar-toggler-icon { background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255, 255, 255, 0.9%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e"); height: 1.8em; width: 1.8em; }
        .navbar.scrolled .navbar-toggler { border-color: rgba(0,0,0, 0.1); }
        .navbar.scrolled .navbar-toggler-icon { background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%2810, 25, 47, 0.8%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e"); }

        /* Styles pour la version mobile du menu (dans le collapse) */
        @media (max-width: 991.98px) {
             .navbar-collapse {
                 background-color: var(--couleur-fond-blanc);
                 padding: 1rem;
                 margin-top: 0.5rem;
                 border-radius: var(--border-radius-md);
                 box-shadow: var(--shadow-lg);
                 border: 1px solid var(--couleur-border);
             }
            .navbar .nav-link {
                color: var(--couleur-texte-dark) !important;
                margin-left: 0;
                margin-right: 0;
                padding: 0.8rem 0.5rem;
                border-bottom: 1px solid var(--couleur-border); /* Séparateurs */
            }
             .navbar .nav-link:hover, .navbar .nav-link.active {
                 color: var(--couleur-accent) !important; /* Accent sur hover/active */
                 background-color: rgba(var(--couleur-accent-rgb), 0.08); /* Fond accent léger */
             }
            .navbar .nav-item:last-child .nav-link { border-bottom: none; } /* Pas de bordure pour le dernier lien direct */

             .navbar .dropdown-menu { box-shadow: none; margin-top: 0; border-radius: 0; border-top: 1px solid var(--couleur-border); }
             .navbar .dropdown-item { padding-left: 1.5rem; } /* Indentation */

             /* Styles pour les boutons Connexion/Inscription dans le menu mobile */
             .navbar .navbar-nav .btn { width: 100%; margin-top: 0.75rem; display: flex; align-items: center; justify-content: center; }
             .navbar .navbar-nav .btn i { margin-right: 0.5rem; }
             .navbar .navbar-nav .btn-outline-light { /* Devient outline-primary dans le menu blanc */
                 border-color: var(--couleur-primaire); color: var(--couleur-primaire);
             }
             .navbar .navbar-nav .btn-outline-light:hover {
                 background-color: var(--couleur-primaire); color: var(--couleur-fond-blanc);
             }
             .navbar .navbar-nav .btn-accent { /* Reste accent */
                 background-color: var(--couleur-accent); color: var(--couleur-primaire); border-color: var(--couleur-accent);
             }
              .navbar .navbar-nav .btn-accent:hover {
                 background-color: var(--couleur-accent-hover); border-color: var(--couleur-accent-hover);
             }
        }

        /* ==========================================================================
           Hero Section (Identique)
           ========================================================================== */
        .hero-section { position: relative; background: var(--couleur-primaire); color: var(--couleur-texte-primaire); min-height: 100vh; display: flex; align-items: center; text-align: center; overflow: hidden; }
        #particles-js { position: absolute; width: 100%; height: 100%; top: 0; left: 0; z-index: 0; }
        .hero-content { position: relative; z-index: 1; max-width: 900px; margin: 0 auto; padding: 0 15px; }
        .hero-section .hero-title { font-size: clamp(2.5rem, 6vw, 4.5rem); line-height: 1.2; color: var(--couleur-fond-blanc); font-weight: 800; margin-bottom: 20px; }
        .hero-section .highlight { color: var(--couleur-accent); } /* Sera sur 'Sécurité Notariale' */
        .hero-section .hero-subtitle { font-size: clamp(1.1rem, 2.5vw, 1.3rem); color: var(--couleur-texte-secondaire); margin-bottom: 40px; font-weight: 300; letter-spacing: 0.5px; max-width: 700px; margin-left: auto; margin-right: auto; }
        .search-bar-hero { background: rgba(23, 42, 69, 0.85); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); padding: 25px; border-radius: var(--border-radius-lg); border: 1px solid var(--couleur-tertiaire); margin-top: 50px; box-shadow: 0 5px 25px rgba(0,0,0,0.2); }
        .search-bar-hero .form-control, .search-bar-hero .form-select { background-color: rgba(255, 255, 255, 0.1); border: 1px solid var(--couleur-tertiaire); color: var(--couleur-texte-primaire); border-radius: var(--border-radius-md); padding: 0.8rem 1.2rem; font-size: 1rem; height: calc(1.5em + 1.6rem + 2px); }
        .search-bar-hero .form-control::placeholder { color: var(--couleur-texte-secondaire); opacity: 0.8; }
        .search-bar-hero .form-control:focus, .search-bar-hero .form-select:focus { background-color: rgba(255, 255, 255, 0.15); border-color: var(--couleur-accent); box-shadow: 0 0 0 0.2rem rgba(var(--couleur-accent-rgb), 0.25); color: var(--couleur-fond-blanc); outline: none; }
        .search-bar-hero .form-select { appearance: none; background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%238892b0' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e"); background-repeat: no-repeat; background-position: right 1rem center; background-size: 16px 12px; padding-right: 3rem; }
        .search-bar-hero select option[disabled] { color: var(--couleur-texte-secondaire); }
        .search-bar-hero select option { color: var(--couleur-texte-dark); background-color: var(--couleur-fond-blanc); }
        .search-bar-hero .btn-primary { background-color: var(--couleur-accent); border-color: var(--couleur-accent); color: var(--couleur-primaire); font-weight: 600; padding: 0.8rem 1.5rem; font-size: 1.05rem; border-radius: var(--border-radius-md); transition: all var(--transition-fast); width: 100%; height: calc(1.5em + 1.6rem + 2px); display: flex; align-items: center; justify-content: center; }
        .search-bar-hero .btn-primary:hover { background-color: var(--couleur-accent-hover); border-color: var(--couleur-accent-hover); transform: translateY(-2px); box-shadow: 0 4px 10px rgba(var(--couleur-accent-rgb), 0.3); }

        /* ==========================================================================
           Featured Properties Section (Identique)
           ========================================================================== */
        #featured-properties { background-color: var(--couleur-fond-blanc); }
        .property-card { background-color: var(--couleur-fond-blanc); border-radius: var(--border-radius-lg); box-shadow: var(--shadow-md); overflow: hidden; position: relative; transition: transform var(--transition-base) var(--easing-smooth), box-shadow var(--transition-base) var(--easing-smooth); border: 1px solid var(--couleur-border); display: flex; flex-direction: column; }
        .property-card:hover { box-shadow: var(--shadow-lg); transform: translateY(-8px); }
        .property-card .img-container { overflow: hidden; position: relative; height: 240px; }
        .property-card .card-img-top { display: block; width: 100%; height: 100%; object-fit: cover; transition: transform 0.6s var(--easing-smooth); }
        .property-card:hover .card-img-top { transform: scale(1.08); }
        .property-card .card-body { padding: 20px; position: relative; z-index: 2; background: var(--couleur-fond-blanc); border-radius: 0 0 var(--border-radius-lg) var(--border-radius-lg); display: flex; flex-direction: column; flex-grow: 1; }
        .property-card .card-title { margin-bottom: 0.3rem !important; }
        .property-card .card-title a { color: var(--couleur-primaire); text-decoration: none; font-weight: 700; font-size: 1.15rem; transition: color var(--transition-fast); line-height: 1.4; display: block; }
        .property-card .card-title a:hover { color: var(--couleur-accent); }
        .property-card .card-title a.stretched-link::after { position: absolute; top: 0; right: 0; bottom: 0; left: 0; z-index: 1; pointer-events: auto; content: ""; background-color: rgba(0,0,0,0); }
        .property-card .card-text.text-muted { color: var(--couleur-texte-secondaire) !important; font-size: 0.85rem; line-height: 1.5; margin-bottom: 1rem; }
        .property-card .card-text i { vertical-align: middle; font-size: 1rem; position: relative; top: -1px; margin-right: 0.25rem; }
        .property-card .price { font-size: 1.4rem; font-weight: 700; color: var(--couleur-accent); font-family: var(--font-titre); line-height: 1; }
        .property-card .price small { font-size: 0.75rem; color: var(--couleur-texte-secondaire); font-weight: 400; font-family: var(--font-texte); margin-left: 4px; }
        .property-card .detail-arrow { color: var(--couleur-tertiaire); font-size: 1.3rem; transition: transform var(--transition-fast), color var(--transition-fast); opacity: 0; transform: translateX(-5px); }
        .property-card:hover .detail-arrow { opacity: 1; color: var(--couleur-accent); transform: translateX(0px); }
        .property-badge { position: absolute; top: 15px; left: 15px; background-color: rgba(var(--couleur-primaire-rgb), 0.8); color: var(--couleur-fond-blanc); padding: 5px 12px; border-radius: var(--border-radius-sm); font-size: 0.75rem; font-weight: 500; z-index: 3; pointer-events: none; }
        #featured-properties .btn-primary { background-color: var(--couleur-primaire); border-color: var(--couleur-primaire); color: white; padding: 0.8rem 2rem; font-weight: 600; transition: all var(--transition-fast); }
        #featured-properties .btn-primary:hover { background-color: var(--couleur-secondaire); border-color: var(--couleur-secondaire); transform: translateY(-2px); box-shadow: var(--shadow-sm); }
        #featured-properties .btn-primary i { vertical-align: middle; margin-left: 0.25rem; }

        /* ==========================================================================
           Services Section (Identique)
           ========================================================================== */
        #services { background-color: var(--couleur-fond-section); }
        .service-item { background-color: var(--couleur-fond-blanc); border-radius: var(--border-radius-lg); padding: 30px; padding-top: 40px; box-shadow: var(--shadow-sm); text-align: center; transition: all var(--transition-base) var(--easing-smooth); border: 1px solid transparent; display: flex; flex-direction: column; }
        .service-item:hover { transform: translateY(-10px); box-shadow: var(--shadow-lg); border-color: rgba(var(--couleur-accent-rgb), 0.5); }
        .service-icon { font-size: 3rem; line-height: 1; margin-bottom: 20px; display: inline-block; color: var(--couleur-accent); transition: transform 0.5s ease; }
        .service-item .service-title { color: var(--couleur-primaire); margin-bottom: 15px; font-weight: 700; font-size: 1.25rem; }
        .service-item p { color: var(--couleur-texte-secondaire); font-size: 0.9rem; line-height: 1.6; flex-grow: 1; margin-bottom: 0; }

        /* ==========================================================================
           CTA Section (Identique)
           ========================================================================== */
        .cta-section { background-color: var(--couleur-primaire); color: var(--couleur-fond-blanc); padding: 80px 0; } @media (min-width: 992px) { .cta-section { padding: 100px 0; } }
        .cta-section h2.cta-title { color: var(--couleur-fond-blanc); font-weight: 800; margin-bottom: 1rem; }
        .cta-section .lead.cta-subtitle { color: var(--couleur-texte-primaire); opacity: 0.9; margin-bottom: 2.5rem; }
        .cta-section .btn { padding: 0.8rem 2rem; font-weight: 600; transition: all var(--transition-fast); margin: 0.5rem; }
        .cta-section .btn-accent { background-color: var(--couleur-accent); border-color: var(--couleur-accent); color: var(--couleur-primaire); }
        .cta-section .btn-accent:hover { background-color: var(--couleur-accent-hover); border-color: var(--couleur-accent-hover); transform: translateY(-3px); box-shadow: 0 5px 15px rgba(var(--couleur-accent-rgb), 0.2); }
        .cta-section .btn-outline-light { border-color: rgba(255, 255, 255, 0.6); color: var(--couleur-fond-blanc); }
        .cta-section .btn-outline-light:hover { background-color: var(--couleur-fond-blanc); color: var(--couleur-primaire); border-color: var(--couleur-fond-blanc); transform: translateY(-3px); }
        .cta-section .btn i { vertical-align: middle; position: relative; top: -1px; margin-right: 0.3rem;}

        /* ==========================================================================
           Footer (Identique)
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
           Ajustements Responsives Spécifiques (Identique)
           ========================================================================== */
        @media (max-width: 991.98px) {
            .search-bar-hero .row > div { margin-bottom: 0.75rem; }
            .search-bar-hero .d-grid { margin-bottom: 0 !important; }
        }
        @media (max-width: 767.98px) {
            .hero-section { min-height: 90vh; }
            .search-bar-hero { padding: 20px; }
            .section-padding { padding: 60px 0; }
            .section-title { font-size: 2.2rem; }
            .section-subtitle { margin-bottom: 40px; }
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
             <!-- Navbar v2 -->
             <nav class="navbar navbar-expand-lg fixed-top">
                 <div class="container">
                     <a class="navbar-brand" href="index.php">
                         GestionLocative<span class="accent">.</span>
                     </a>
                     <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                         <span class="navbar-toggler-icon"></span>
                     </button>
                     <div class="collapse navbar-collapse" id="navbarNav">
                         <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
                             <li class="nav-item"><a class="nav-link active" href="index.php">Accueil</a></li>
                             <li class="nav-item"><a class="nav-link" href="biens-disponibles.php">Biens à Louer</a></li>
                             <li class="nav-item"><a class="nav-link" href="#services">Nos Services</a></li>
                             <li class="nav-item"><a class="nav-link" href="contact.php">Contact</a></li>
                             <li class="nav-item dropdown">
                                 <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownAccount" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                     Espace Membre
                                 </a>
                                 <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdownAccount">
                                     <li><a class="dropdown-item" href="auth-signin.php"><i class="ri-login-box-line"></i>Se Connecter</a></li>
                                     <li><a class="dropdown-item" href="auth-signup.php"><i class="ri-user-add-line"></i>S'inscrire</a></li>
                                     <li><hr class="dropdown-divider"></li>
                                     <li><a class="dropdown-item" href="proprietaire-dashboard.php"><i class="ri-user-settings-line"></i>Espace Propriétaire</a></li>
                                     <li><a class="dropdown-item" href="locataire-dashboard.php"><i class="ri-user-line"></i>Espace Locataire</a></li>
                                     <li><a class="dropdown-item" href="notaire-dashboard.php"><i class="ri-shield-user-line"></i>Espace Notaire</a></li>
                                 </ul>
                             </li>
                             <!-- Boutons visibles seulement sur mobile DANS le menu burger -->
                             <li class="nav-item mt-3 d-lg-none"> <!-- Ajout Marge haute -->
                                 <a href="auth-signin.php" class="btn btn-outline-light w-100 btn-sm"><i class="ri-login-box-line"></i> Connexion</a>
                             </li>
                             <li class="nav-item mt-2 mb-2 d-lg-none"> <!-- Ajout Marge haute/basse -->
                                 <a href="auth-signup.php" class="btn btn-accent w-100 btn-sm"><i class="ri-user-add-line"></i> Inscription</a>
                             </li>
                              <!-- Boutons visibles seulement sur Desktop HORS du menu burger -->
                              <li class="nav-item ms-lg-2 d-none d-lg-block">
                                 <a href="auth-signin.php" class="btn btn-outline-light btn-sm">Connexion</a>
                             </li>
                             <li class="nav-item ms-lg-2 d-none d-lg-block">
                                 <a href="auth-signup.php" class="btn btn-accent btn-sm">Inscription</a>
                             </li>
                         </ul>
                     </div>
                 </div>
             </nav>
        </header>

        <main>
            <!-- Hero Section (Identique) -->
            <section class="hero-section">
                <div id="particles-js"></div>
                <div class="container">
                     <div class="hero-content">
                        <h1 class="hero-title mb-3"></h1> <!-- Sera rempli par JS -->
                        <p class="lead hero-subtitle mb-5"></p> <!-- Sera rempli par JS -->
                        <div class="search-container mt-5">
                            <form class="search-bar-hero p-4" action="biens-disponibles.php" method="GET">
                                 <div class="row g-3 align-items-center">
                                     <div class="col-lg-4 col-md-6">
                                         <label for="hero-search-location" class="visually-hidden">Localisation</label>
                                         <input type="text" id="hero-search-location" name="location" class="form-control form-control-lg" placeholder="Ville, Quartier...">
                                     </div>
                                     <div class="col-lg-3 col-md-6">
                                          <label for="hero-search-type" class="visually-hidden">Type de bien</label>
                                         <select id="hero-search-type" name="type" class="form-select form-select-lg">
                                             <option selected disabled value="">Type de bien</option>
                                             <!-- Remplir dynamiquement si possible -->
                                             <option value="Appartement">Appartement</option>
                                             <option value="Maison">Maison</option>
                                             <option value="Bureau">Bureau</option>
                                             <option value="Villa">Villa</option>
                                         </select>
                                     </div>
                                     <div class="col-lg-3 col-md-6">
                                          <label for="hero-search-loyer" class="visually-hidden">Loyer maximum</label>
                                         <input type="number" id="hero-search-loyer" name="loyer_max" class="form-control form-control-lg" placeholder="Loyer max (FCFA)" min="0" step="5000">
                                     </div>
                                     <div class="col-lg-2 col-md-6 d-grid">
                                         <button type="submit" class="btn btn-primary btn-lg">Trouver</button>
                                     </div>
                                 </div>
                             </form>
                         </div>
                     </div>
                </div>
            </section>

            <!-- Featured Properties Section -->
            <section id="featured-properties" class="section-padding">
                <div class="container">
                    <h2 class="text-center section-title">Nos Dernières Offres</h2>
                    <p class="text-center lead mb-5 section-subtitle">Découvrez les biens immobiliers récemment ajoutés à notre catalogue.</p>

                    <!-- Affichage du message d'erreur PHP s'il y en a un -->
                    <?php if (!empty($errorMessage)): ?>
                        <div class="alert alert-danger text-center" role="alert">
                            <i class="ri-error-warning-line me-2"></i><?php echo htmlspecialchars($errorMessage); ?>
                            <!-- Affichage du message de debug SI on est en mode debug (à adapter selon votre méthode) -->
                            <?php if (!empty($debugMessage) /* && MODE_DEBUG_ACTIF */): ?>
                                <hr><p class="small mb-0"><strong>Détails techniques :</strong> <?php echo htmlspecialchars($debugMessage); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="row g-4 justify-content-center">
                        <?php /* Condition déplacée: on affiche le message "aucun bien" seulement s'il n'y a PAS d'erreur ET que le tableau est vide */ ?>
                        <?php if (empty($errorMessage) && empty($biensAlaUne)): ?>
                             <div class="col-12">
                                <p class='text-center text-muted mt-4'>
                                    <i class="ri-information-line me-1"></i>Aucun bien à la une disponible pour le moment.
                                    <?php if (!empty($debugMessage)): ?>
                                        <br><small>(<?php echo htmlspecialchars($debugMessage); ?>)</small>
                                     <?php endif; ?>
                                </p>
                             </div>
                        <?php elseif (!empty($biensAlaUne)): ?>
                            <?php foreach ($biensAlaUne as $index => $bien): ?>
                                <?php
                                    // Débogage : Décommentez pour voir le contenu de $bien
                                    // if($index === 0) { echo '<pre>'; var_dump($bien); echo '</pre>'; }

                                    // Préparation des données (sécurisation et formatage)
                                    $bienId = htmlspecialchars($bien['idBien'] ?? '');
                                    $bienAdresse = htmlspecialchars($bien['adresse'] ?? 'Adresse non spécifiée');
                                    $imageName = htmlspecialchars($bien['image_profil'] ?? '');

                                    // *** VÉRIFIEZ CE CHEMIN D'ACCÈS AUX IMAGES ***
                                    // Chemin sur le serveur pour file_exists()
                                    $imagePathCheck = __DIR__ . '/uploads/biens/' . $imageName;
                                    // Chemin accessible par le navigateur pour src=""
                                    $webImagePathPrefix = 'uploads/biens/'; // Doit être accessible depuis la racine du site
                                    $defaultImagePath = 'assets/images/property/default.jpg'; // Assurez-vous que ce fichier existe

                                    $imageUrl = (!empty($imageName) && file_exists($imagePathCheck))
                                                 ? $webImagePathPrefix . $imageName
                                                 : $defaultImagePath;

                                    $loyer = (float)($bien['loyerMensuel'] ?? 0);
                                    $loyerFormatte = number_format($loyer, 0, ',', ' ') . ' FCFA';
                                    $typeBien = htmlspecialchars($bien['typeBien'] ?? 'Propriété');
                                    $detailsUrl = "details-bien.php?id=" . urlencode($bienId);
                                    // Utilise mb_substr pour l'UTF-8 si l'extension mbstring est activée
                                    $adresseCourte = function_exists('mb_substr') ? mb_substr($bienAdresse, 0, 45) : substr($bienAdresse, 0, 45);
                                    if ( (function_exists('mb_strlen') ? mb_strlen($bienAdresse) : strlen($bienAdresse)) > 45) {
                                        $adresseCourte .= '...';
                                    }
                                ?>
                                <div class="col-lg-4 col-md-6 property-card-item">
                                    <div class="card property-card h-100">
                                        <div class="img-container position-relative">
                                             <a href="<?= $detailsUrl ?>" class="property-link-img" aria-label="Voir les détails de <?= $typeBien ?> à <?= $bienAdresse ?>">
                                                <img src="<?= $imageUrl ?>" class="card-img-top" alt="Photo de : <?= $typeBien ?> à <?= $bienAdresse ?>" loading="lazy">
                                             </a>
                                             <?php if ($typeBien !== 'Propriété' && !empty($typeBien)): ?>
                                                <div class="property-badge"><?= $typeBien ?></div>
                                             <?php endif; ?>
                                        </div>
                                        <div class="card-body d-flex flex-column">
                                            <h3 class="card-title h5 mb-1">
                                                <a href="<?= $detailsUrl ?>" class="text-decoration-none stretched-link"><?= $adresseCourte ?></a>
                                            </h3>
                                            <p class="card-text text-muted small mb-3 flex-grow-1">
                                                <i class="ri-map-pin-fill"></i><?= $bienAdresse ?>
                                            </p>
                                            <div class="mt-auto d-flex justify-content-between align-items-center">
                                                <span class="price"><?= $loyerFormatte ?> <small>/ mois</small></span>
                                                <span class="detail-arrow"><i class="ri-arrow-right-line"></i></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; // Fin de la condition d'affichage des biens ?>
                    </div>

                     <div class="text-center mt-5 section-footer">
                         <a href="biens-disponibles.php" class="btn btn-primary btn-lg">
                             Voir tous les biens <i class="ri-arrow-right-line"></i>
                         </a>
                     </div>
                </div>
            </section>

            <!-- Services Section (Identique) -->
            <section id="services" class="section-padding bg-light">
                 <div class="container">
                     <h2 class="text-center section-title">Votre Sérénité, Notre Engagement</h2>
                     <p class="text-center lead mb-5 section-subtitle">La garantie notariale au service de votre tranquillité d'esprit.</p>
                     <div class="row text-center g-4">
                        <div class="col-lg-3 col-md-6 service-item-wrapper"> <div class="service-item p-4 h-100"> <div class="service-icon mb-4"><i class="ri-shield-check-line"></i></div> <h3 class="h5 service-title">Sécurité Juridique</h3> <p>Des contrats de bail conformes, validés et sécurisés par l'étude notariale.</p> </div> </div>
                        <div class="col-lg-3 col-md-6 service-item-wrapper"> <div class="service-item p-4 h-100"> <div class="service-icon mb-4"><i class="ri-file-list-3-line"></i></div> <h3 class="h5 service-title">Transparence Totale</h3> <p>Accédez à vos documents, paiements et suivis en temps réel via votre espace sécurisé.</p> </div> </div>
                        <div class="col-lg-3 col-md-6 service-item-wrapper"> <div class="service-item p-4 h-100"> <div class="service-icon mb-4"><i class="ri-secure-payment-line"></i></div> <h3 class="h5 service-title">Transactions Fiables</h3> <p>Gestion rigoureuse et traçabilité complète des flux financiers (loyers, charges, dépôts).</p> </div> </div>
                        <div class="col-lg-3 col-md-6 service-item-wrapper"> <div class="service-item p-4 h-100"> <div class="service-icon mb-4"><i class="ri-scales-3-line"></i></div> <h3 class="h5 service-title">Cadre Légal Maîtrisé</h3> <p>Une expertise notariale pour prévenir les litiges et assurer la conformité légale.</p> </div> </div>
                     </div>
                 </div>
            </section>

            <!-- CTA Section (Identique) -->
            <section class="cta-section section-padding text-center">
                 <div class="container">
                     <h2 class="mb-3 cta-title">Prêt à Simplifier Votre Gestion Locative ?</h2>
                     <p class="lead mb-4 mx-auto cta-subtitle" style="max-width: 700px;">Que vous soyez propriétaire, locataire ou notaire partenaire, notre plateforme est conçue pour vous.</p>
                     <div class="cta-buttons mt-4">
                         <a href="biens-disponibles.php" class="btn btn-accent btn-lg"> <i class="ri-search-line"></i> Explorer les Biens </a>
                         <a href="auth-signup.php" class="btn btn-outline-light btn-lg"> <i class="ri-user-add-line"></i> Créer un Compte </a>
                     </div>
                 </div>
            </section>
        </main>

        <!-- Footer (Identique) -->
        <footer class="site-footer">
              <div class="container">
                  <div class="row g-4 g-lg-5 pb-5">
                      <div class="col-lg-4 col-md-6"> <h5 class="footer-brand mb-3">GestionLocative<span class="accent">.</span></h5> <p class="footer-tagline">La gestion locative sécurisée par l'expertise notariale au Bénin.</p> <div class="social-icons mt-4"> <a href="#" aria-label="Facebook" class="social-icon"><i class="ri-facebook-fill"></i></a> <a href="#" aria-label="Twitter" class="social-icon"><i class="ri-twitter-x-line"></i></a> <a href="#" aria-label="LinkedIn" class="social-icon"><i class="ri-linkedin-fill"></i></a> </div> </div>
                      <div class="col-lg-2 col-md-3 col-6"> <h6 class="footer-heading">Navigation</h6> <ul class="list-unstyled footer-links"> <li><a href="index.php">Accueil</a></li> <li><a href="biens-disponibles.php">Biens à louer</a></li> <li><a href="#services">Nos services</a></li> <li><a href="contact.php">Contact</a></li> </ul> </div>
                      <div class="col-lg-2 col-md-3 col-6"> <h6 class="footer-heading">Espaces</h6> <ul class="list-unstyled footer-links"> <li><a href="auth-signin.php">Connexion</a></li> <li><a href="auth-signup.php">Inscription</a></li> <li><a href="locataire-dashboard.php">Espace Locataire</a></li> <li><a href="proprietaire-dashboard.php">Espace Propriétaire</a></li> <li><a href="notaire-dashboard.php">Espace Notaire</a></li> </ul> </div>
                      <div class="col-lg-4 col-md-12 order-md-first order-lg-last"> <h6 class="footer-heading">Nous Contacter</h6> <ul class="list-unstyled footer-contact"> <li><i class="ri-map-pin-line"></i> <span>123 Rue Imaginaire, Cotonou, Bénin</span></li> <li><i class="ri-phone-line"></i> <a href="tel:+229XXXXXXXX">+229 XX XX XX XX</a></li> <li><i class="ri-mail-line"></i> <a href="mailto:contact@gestionlocative-notaire.bj">contact@gestionlocative-notaire.bj</a></li> <li><i class="ri-time-line"></i> <span>Lun - Ven : 9h00 - 17h00</span></li> </ul> </div>
                  </div>
                  <div class="footer-bottom text-center pt-4"> <p class="mb-0">© <script>document.write(new Date().getFullYear())</script> GestionLocative Notariale. Tous droits réservés.</p> </div>
              </div>
        </footer>
    </div><!-- /.wrapper -->

    <!-- Scripts JS (Librairies via CDN - Identique) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollToPlugin.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tsparticles@3.4.0/tsparticles.bundle.min.js"></script>

    <!-- === JavaScript Intégré (Identique à la version précédente) === -->
    <script>
        /**
         * GestLocative - Animations et Interactions Page d'Accueil (Intégré)
         */
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Accueil JS (Intégré): Initialized.");
            if (typeof gsap === 'undefined') console.error("GSAP n'est pas chargé.");
            if (typeof ScrollTrigger === 'undefined') console.error("ScrollTrigger n'est pas chargé.");
            if (typeof ScrollToPlugin === 'undefined') console.error("ScrollToPlugin n'est pas chargé.");
            if (typeof tsParticles === 'undefined') console.warn("tsParticles n'est pas chargé.");

            gsap.registerPlugin(ScrollTrigger, ScrollToPlugin);

            initNavbarScrollEffect();
            initHeroAnimation();
            if (typeof tsParticles !== 'undefined') initParticles();
            initFeaturedPropertiesAnimation();
            initServicesAnimation();
            initCtaAnimation();
            initSmoothScroll();
        });

        function initNavbarScrollEffect() {
            const navbar = document.querySelector('.navbar'); if (!navbar) return;
            ScrollTrigger.create({ start: "top top-=70", end: 99999, toggleClass: { className: "scrolled", targets: navbar } });
        }

        function initHeroAnimation() {
            const heroTitle = document.querySelector('.hero-title'); const heroSubtitle = document.querySelector('.hero-subtitle'); const searchContainer = document.querySelector('.search-container');
            const mainTitleText = "La Location Immobilière,<br> Réinventée par la <span class='highlight accent'>Sécurité Notariale.</span>";
            const subtitleText = "La plateforme béninoise qui allie expertise notariale et technologie moderne pour une gestion locative simple, transparente et sans faille.";
            const tlHero = gsap.timeline({ delay: 0.2 });
            if (heroTitle) { heroTitle.innerHTML = mainTitleText; tlHero.from(heroTitle, { duration: 1, y: 60, opacity: 0, ease: "power3.out" }); }
            if (heroSubtitle) { heroSubtitle.textContent = subtitleText; tlHero.from(heroSubtitle, { duration: 0.9, y: 40, opacity: 0, ease: "power2.out" }, "-=0.7"); }
            if (searchContainer) { tlHero.from(searchContainer, { duration: 0.8, y: 50, opacity: 0, ease: "power2.out" }, "-=0.6"); }
        }

        function initParticles() {
             if (typeof tsParticles === 'undefined') return;
            const particlesConfig = { fpsLimit: 60, particles: { number: { value: 40, density: { enable: true, value_area: 800 } }, color: { value: "#ffffff" }, shape: { type: "circle" }, opacity: { value: { min: 0.1, max: 0.3 }, animation: { enable: true, speed: 0.8, minimumValue: 0.1, sync: false } }, size: { value: { min: 1, max: 3 } }, links: { enable: false }, move: { enable: true, speed: 0.6, direction: "none", random: true, straight: false, outModes: { default: "out" } } }, interactivity: { detectsOn: "canvas", events: { onHover: { enable: false }, onClick: { enable: false }, resize: { enable: true } } }, detectRetina: true, background: { color: "transparent" } };
            try { tsParticles.load("particles-js", particlesConfig).then(c => console.log("tsParticles chargé:", c?.id)).catch(e => console.error("Erreur tsParticles:", e)); } catch (e) { console.error("Erreur appel tsParticles:", e); }
        }

        function initFeaturedPropertiesAnimation() {
            const propertyItems = gsap.utils.toArray('.property-card-item'); if (propertyItems.length === 0) return;
            gsap.from(propertyItems, { scrollTrigger: { trigger: "#featured-properties", start: "top 85%", end: "bottom center", toggleActions: "play none none none" }, y: 80, opacity: 0, duration: 0.8, stagger: 0.15, ease: "power3.out" });
            // Effet 3D désactivé par défaut pour la simplicité/perf. L'effet CSS de base (translateY) est appliqué.
            const enable3DEffect = false; const cards = document.querySelectorAll('.property-card');
            cards.forEach(card => { if (enable3DEffect) { /* Code de l'effet 3D ici si besoin */ } });
        }

        function initServicesAnimation() {
            const serviceItems = gsap.utils.toArray('.service-item-wrapper'); if (serviceItems.length === 0) return;
            gsap.from(serviceItems, { scrollTrigger: { trigger: "#services", start: "top 85%", end: "bottom center", toggleActions: "play none none none" }, y: 70, opacity: 0, duration: 0.7, stagger: 0.15, ease: "power2.out" });
            serviceItems.forEach(itemWrapper => { const icon = itemWrapper.querySelector('.service-icon i'); if(icon) { const hoverTimeline = gsap.timeline({ paused: true, defaults: { duration: 0.3, ease: "back.out(1.7)" } }); hoverTimeline.to(icon, { scale: 1.1, rotate: -10 }); itemWrapper.addEventListener('mouseenter', () => { if (!window.matchMedia("(prefers-reduced-motion: reduce)").matches) hoverTimeline.play(); }); itemWrapper.addEventListener('mouseleave', () => { if (!window.matchMedia("(prefers-reduced-motion: reduce)").matches) hoverTimeline.reverse(); }); } });
        }

        function initCtaAnimation() {
            const ctaSection = document.querySelector('.cta-section'); if (!ctaSection) return;
            const ctaTimeline = gsap.timeline({ scrollTrigger: { trigger: ctaSection, start: "top 80%", end: "center center", toggleActions: "play none none none" } });
            ctaTimeline.from(ctaSection.querySelector(".cta-title"), { y: 50, opacity: 0, duration: 0.8, ease: "power3.out" }) .from(ctaSection.querySelector(".cta-subtitle"), { y: 40, opacity: 0, duration: 0.8, ease: "power3.out" }, "-=0.5") .from(ctaSection.querySelector(".cta-buttons"), { y: 30, opacity: 0, duration: 0.8, ease: "power3.out" }, "-=0.6");
        }

        function initSmoothScroll() {
            document.querySelectorAll('a[href^="#"]').forEach(anchor => { anchor.addEventListener('click', function (e) { const href = anchor.getAttribute('href'); if (!href || href === '#' || href.startsWith('#!') || href === '#0' || document.querySelector(href)?.closest('.collapse')) return; const targetElement = document.querySelector(href); if (targetElement) { e.preventDefault(); let navbarHeight = 0; const fixedNavbar = document.querySelector('.navbar.fixed-top'); if(fixedNavbar) navbarHeight = fixedNavbar.offsetHeight; gsap.to(window, { duration: 1.2, scrollTo: { y: targetElement, offsetY: navbarHeight + 10 }, ease: "power2.inOut" }); } }); });
        }
    </script>
    <!-- Fin JavaScript Intégré -->

</body>
</html>





<?php
// --- index.php (Page d'Accueil - Design "Ouf" avec GSAP) ---
if (session_status() === PHP_SESSION_NONE) session_start();
// $userPrenom = $_SESSION['user_prenom'] ?? null;

// --- Récupération des Biens à la Une (Identique) ---
require_once __DIR__ . '/db_connection.php'; // $pdo
$biensAlaUne = [];
$errorMessage = '';
try {
    if (isset($pdo)) {
        // *** ADAPTER CETTE REQUETE SI BESOIN (table types, colonne date) ***
        $stmt = $pdo->prepare(
            "SELECT b.idBien, b.adresse, b.loyerMensuel, b.image_profil
             FROM bienimmobiliers b
             WHERE b.statut = 'Libre' AND b.supervisionStatut = 'Validé'
             ORDER BY b.idBien DESC -- Ou dateAjout DESC
             LIMIT 3"
        );
        $stmt->execute();
        $biensAlaUne = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else { throw new Exception("Connexion PDO non disponible."); }
} catch (Exception $e) { error_log("Erreur chargement biens accueil: " . $e->getMessage()); $errorMessage = "Erreur chargement des biens."; }
// Ne pas fermer $pdo ici si d'autres includes l'utilisent plus tard
?>