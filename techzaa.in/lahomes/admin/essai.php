<?php
// --- index.php (Page d'Accueil - Design Pro) ---
// if (session_status() === PHP_SESSION_NONE) session_start();
// $userPrenom = $_SESSION['user_prenom'] ?? null;
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestion Locative Notariale | Sécurité & Simplicité</title> <!-- Titre plus engageant -->
    <meta name="description" content="Votre partenaire de confiance pour la gestion locative sécurisée par notaire. Trouvez, louez ou gérez vos biens immobiliers sereinement.">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />

    <!-- Favicon -->
    <link rel="shortcut icon" href="assets/images/favicon.ico"> <!-- Assurez-vous d'avoir un favicon -->

    <!-- Google Fonts (Exemple: Poppins & Montserrat) -->
    <link rel="preconnect" href="https://fonts.googleapis.com/">
    <link rel="preconnect" href="https://fonts.gstatic.com/" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700&family=Poppins:wght@400;500&display=swap" rel="stylesheet">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Remix Icons -->
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.2.0/fonts/remixicon.css" rel="stylesheet"/>
    <!-- AOS (Animate On Scroll) -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">

    <!-- Custom CSS -->
    <link href="assets/css/style-accueil-pro.css" rel="stylesheet"> <!-- NOUVEAU fichier CSS -->

    <!-- Styles In-Page (pour démo rapide - à déplacer dans le CSS) -->
    <style>
        :root {
            --couleur-primaire: #0A2342; /* Bleu Nuit */
            --couleur-secondaire: #6C757D; /* Gris Moyen */
            --couleur-accent: #D4AF37; /* Or/Moutarde */
            --couleur-fond-clair: #F8F9FA;
            --couleur-fond-blanc: #FFFFFF;
            --couleur-texte: #212529; /* Presque noir */
            --couleur-texte-clair: #6C757D;
        }

        body {
            font-family: 'Poppins', sans-serif;
            color: var(--couleur-texte);
            line-height: 1.7;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            color: var(--couleur-primaire);
        }

        .section-padding { padding: 100px 0; }
        .section-title { margin-bottom: 60px; font-size: 2.5rem; position: relative; padding-bottom: 15px; }
        .section-title::after { /* Soulignement subtil */
            content: ''; position: absolute; left: 50%; transform: translateX(-50%); bottom: 0; height: 4px; width: 60px; background-color: var(--couleur-accent); border-radius: 2px;
        }

        /* --- Navbar --- */
        .navbar { background-color: var(--couleur-fond-blanc); transition: background-color 0.3s ease, box-shadow 0.3s ease; padding-top: 1rem; padding-bottom: 1rem; }
        .navbar-brand { color: var(--couleur-primaire) !important; font-weight: 700; font-size: 1.5rem; }
        .nav-link { color: var(--couleur-primaire) !important; font-weight: 500; margin-left: 15px; margin-right: 15px; position: relative; padding-bottom: 8px; }
        .nav-link::after { content: ''; position: absolute; bottom: 0; left: 50%; transform: translateX(-50%); width: 0; height: 2px; background-color: var(--couleur-accent); transition: width 0.3s ease; }
        .nav-link:hover::after, .nav-link.active::after { width: 60%; }
        .navbar.scrolled { background-color: rgba(255, 255, 255, 0.98) !important; box-shadow: 0 2px 10px rgba(0,0,0,0.1); } /* Effet au scroll */
        .navbar-toggler { border: none; }
        .navbar-toggler:focus { box-shadow: none; }

        /* --- Hero Section --- */
        .hero-section {
            background: linear-gradient(rgba(10, 35, 66, 0.7), rgba(10, 35, 66, 0.8)), url('assets/images/accueil/hero-architecture-moderne.jpg') no-repeat center center; /* METTRE VOTRE IMAGE */
            background-size: cover;
            color: white;
            padding: 180px 0 120px 0; /* Plus d'espace */
            min-height: 80vh; /* Hauteur minimale */
            display: flex; align-items: center;
        }
        .hero-section h1 { font-size: calc(2.5rem + 1.5vw); /* Responsive */ font-weight: 700; color: white; margin-bottom: 20px; }
        .hero-section .lead { font-size: 1.3rem; color: rgba(255,255,255,0.9); max-width: 700px; margin: 0 auto 40px auto; font-weight: 400; }
        .search-bar-hero { background: rgba(255, 255, 255, 0.95); padding: 25px; border-radius: 10px; box-shadow: 0 5px 25px rgba(0,0,0,0.1); margin-top: 30px; }
        .search-bar-hero .form-control, .search-bar-hero .form-select { border: 1px solid #ced4da; box-shadow: none; }
        .search-bar-hero .btn-primary { background-color: var(--couleur-primaire); border-color: var(--couleur-primaire); font-weight: 500; padding: 0.75rem 1.5rem; }
        .search-bar-hero .btn-primary:hover { background-color: #071a33; border-color: #071a33; }

        /* --- Featured Properties --- */
        #featured-properties { background-color: var(--couleur-fond-blanc); }
        .property-card { border: none; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.08); transition: transform 0.3s ease, box-shadow 0.3s ease; }
        .property-card:hover { transform: translateY(-8px); box-shadow: 0 12px 25px rgba(0,0,0,0.12); }
        .property-card .card-img-top { height: 220px; object-fit: cover; border-bottom: 3px solid var(--couleur-accent); }
        .property-card .card-body { padding: 20px; }
        .property-card .card-title { color: var(--couleur-primaire); font-weight: 600; margin-bottom: 10px; }
        .property-card .card-text { color: var(--couleur-texte-clair); font-size: 0.95rem; }
        .property-card .price { font-size: 1.4rem; font-weight: 700; color: var(--couleur-primaire); }
        .property-card .btn-outline-primary { color: var(--couleur-primaire); border-color: var(--couleur-primaire); font-weight: 500; }
        .property-card .btn-outline-primary:hover { background-color: var(--couleur-primaire); color: white; }

        /* --- Services Section --- */
        #services { background-color: var(--couleur-fond-clair); }
        .service-item { background-color: var(--couleur-fond-blanc); border-radius: 8px; transition: transform 0.3s ease, box-shadow 0.3s ease; }
        .service-item:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
        .service-item i { font-size: 3.5rem; color: var(--couleur-accent); margin-bottom: 20px; display: inline-block; }
        .service-item h5 { color: var(--couleur-primaire); margin-bottom: 15px; font-weight: 600; }
        .service-item p { color: var(--couleur-texte-clair); font-size: 0.95rem; }

        /* --- CTA Section --- */
        .cta-section { background-color: var(--couleur-primaire); color: white; padding: 80px 0; }
        .cta-section h2 { color: white; }
        .cta-section p { color: rgba(255,255,255,0.8); }
        .cta-section .btn-primary { background-color: var(--couleur-accent); border-color: var(--couleur-accent); color: var(--couleur-primaire); font-weight: bold; }
        .cta-section .btn-primary:hover { background-color: #c09d2b; border-color: #c09d2b; }
        .cta-section .btn-outline-secondary { border-color: rgba(255,255,255,0.7); color: white; }
        .cta-section .btn-outline-secondary:hover { background-color: white; color: var(--couleur-primaire); }

        /* --- Footer --- */
        footer { background-color: #1c2331; /* Un peu moins noir */ color: #adb5bd; padding: 60px 0 30px 0; }
        footer h5 { color: white; margin-bottom: 20px; font-weight: 600; }
        footer ul { list-style: none; padding-left: 0; }
        footer ul li { margin-bottom: 10px; }
        footer a { color: #adb5bd; transition: color 0.3s ease; }
        footer a:hover { color: white; text-decoration: none; }
        footer .social-icons a { color: #adb5bd; margin-right: 15px; font-size: 1.3rem; transition: color 0.3s ease; }
        footer .social-icons a:hover { color: var(--couleur-accent); }
        footer .footer-bottom { border-top: 1px solid #4f5b69; padding-top: 20px; margin-top: 30px; font-size: 0.9rem; }

    </style>
</head>
<body>

    <!-- ============================================================== -->
    <!-- Header / Navigation                                            -->
    <!-- ============================================================== -->
    <header>
        <nav class="navbar navbar-expand-lg navbar-light fixed-top"> <!-- Enlevé bg-light pour le rendre transparent initialement -->
            <div class="container">
                 <a class="navbar-brand" href="index.php">
                    <!-- <img src="assets/images/logo-light.png" alt="Logo Clair" height="35"> -->
                     GestionLocative<span style="color: var(--couleur-accent);">.</span> <!-- Ajout accent -->
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
                           <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"> Espace Membre </a>
                           <ul class="dropdown-menu dropdown-menu-end"> <!-- Alignement à droite -->
                             <li><a class="dropdown-item" href="auth-signin.php"><i class="ri-login-box-line me-1"></i> Se Connecter</a></li>
                             <li><a class="dropdown-item" href="auth-signup.php"><i class="ri-user-add-line me-1"></i> S'inscrire</a></li>
                             <li><hr class="dropdown-divider"></li>
                             <li><a class="dropdown-item" href="proprietaire-dashboard.php"><i class="ri-user-settings-line me-1"></i> Espace Propriétaire</a></li>
                             <li><a class="dropdown-item" href="locataire-dashboard.php"><i class="ri-user-line me-1"></i> Espace Locataire</a></li>
                             <li><a class="dropdown-item" href="notaire-dashboard.php"><i class="ri-shield-user-line me-1"></i> Espace Notaire</a></li>
                           </ul>
                         </li>
                         <li class="nav-item ms-lg-2 d-none d-lg-block">
                             <a href="auth-signin.php" class="btn btn-outline-light btn-sm" style="--bs-btn-border-color: rgba(255,255,255,0.5); --bs-btn-hover-bg: var(--couleur-accent); --bs-btn-hover-border-color: var(--couleur-accent); --bs-btn-hover-color: var(--couleur-primaire);">Connexion</a>
                         </li>
                          <li class="nav-item ms-lg-2 d-none d-lg-block">
                             <a href="auth-signup.php" class="btn btn-sm" style="background-color: var(--couleur-accent); color: var(--couleur-primaire); --bs-btn-hover-bg: #c09d2b;">Inscription</a>
                         </li>
                    </ul>
                </div>
            </div>
        </nav>
    </header>

    <!-- ============================================================== -->
    <!-- Hero Section                                                   -->
    <!-- ============================================================== -->
    <section class="hero-section d-flex align-items-center">
        <div class="container text-center">
            <h1 data-aos="fade-up">La Location Immobilière,<br> Réinventée par la Sécurité Notariale.</h1>
            <p class="lead px-lg-5" data-aos="fade-up" data-aos-delay="100">
                Louez, gérez et investissez en toute tranquillité. Notre plateforme intègre l'expertise notariale pour sécuriser chaque étape de votre parcours locatif.
            </p>
            <div class="row justify-content-center" data-aos="fade-up" data-aos-delay="200">
                <div class="col-lg-10 col-xl-9">
                    <form class="search-bar-hero p-4">
                        <div class="row g-2">
                            <div class="col-md-4 mb-2 mb-md-0"> <input type="text" class="form-control form-control-lg" placeholder="Ville, Quartier..."> </div>
                            <div class="col-md-3 mb-2 mb-md-0"> <select class="form-select form-select-lg"> <option selected disabled value="">Type de bien</option> <option value="appartement">Appartement</option> <option value="maison">Maison</option> <option value="bureau">Bureau</option> <option value="commerce">Commerce</option> </select> </div>
                            <div class="col-md-3 mb-2 mb-md-0"> <input type="number" class="form-control form-control-lg" placeholder="Loyer max (FCFA)" min="0"> </div>
                            <div class="col-md-2 d-grid"> <button type="submit" class="btn btn-primary btn-lg">Trouver</button> </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================== -->
    <!-- Biens à la Une                                                 -->
    <!-- ============================================================== -->
    <section id="featured-properties" class="section-padding">
        <div class="container">
            <h2 class="text-center section-title" data-aos="fade-up">Nos Biens en Exclusivité</h2>
            <div class="row g-4">
                <!-- PHP: Boucle pour afficher les biens réels depuis la BDD -->
                <?php
                // --- EXEMPLE DE CODE PHP (À METTRE EN PLACE RÉELLEMENT) ---
                 require_once __DIR__ . '/db_connection.php'; // S'assurer que $pdo est défini
                 $index = 0; // Pour décalage animation AOS
                 try {
                     $stmt = $pdo->prepare("SELECT idBien, adresse, loyerMensuel, image_profil, typeBien FROM bienimmobiliers WHERE statut = 'Libre' AND supervisionStatut = 'Validé' ORDER BY dateAjout DESC LIMIT 3"); // Adapter 'dateAjout' si colonne existe
                     $stmt->execute();
                     $biens = $stmt->fetchAll(PDO::FETCH_ASSOC);

                     if ($biens) {
                         foreach ($biens as $bien) {
                             $imagePath = (!empty($bien['image_profil']) && file_exists('uploads/biens/' . $bien['image_profil'])) ? 'uploads/biens/' . htmlspecialchars($bien['image_profil']) : 'assets/images/property/default.jpg'; // Adapter chemin
                             $loyerFormatte = number_format($bien['loyerMensuel'] ?? 0, 0, ',', ' ') . ' FCFA';
                             $typeBien = htmlspecialchars($bien['typeBien'] ?? 'Propriété'); // Utiliser un champ type si existant
                             ?>
                              <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="<?php echo $index * 100; ?>">
                                  <div class="card property-card h-100">
                                      <a href="details-bien.php?id=<?php echo $bien['idBien']; ?>"><img src="<?php echo $imagePath; ?>" class="card-img-top" alt="<?php echo htmlspecialchars($typeBien . ' à ' . $bien['adresse']); ?>"></a>
                                      <div class="card-body d-flex flex-column">
                                          <h5 class="card-title mb-1"><a href="details-bien.php?id=<?php echo $bien['idBien']; ?>" class="text-decoration-none stretched-link"><?php echo $typeBien; ?></a></h5>
                                          <p class="card-text text-muted small mb-3"><i class="ri-map-pin-fill me-1 text-secondary"></i><?php echo htmlspecialchars($bien['adresse']); ?></p>
                                          <!-- Ajouter d'autres détails si disponibles -->
                                          <!-- <p class="card-text small text-muted"><i class="ri-hotel-bed-line"></i> 3 Ch. | <i class="ri-ruler-line"></i> 120 m²</p> -->
                                          <div class="mt-auto d-flex justify-content-between align-items-center">
                                              <span class="price"><?php echo $loyerFormatte; ?> <small>/ mois</small></span>
                                              <!-- <a href="details-bien.php?id=<?php// echo $bien['idBien']; ?>" class="btn btn-sm btn-outline-primary">Détails</a> -->
                                          </div>
                                      </div>
                                  </div>
                              </div>
                             <?php
                             $index++;
                         }
                     } else {
                         echo "<p class='text-center text-muted col-12'>Aucun bien à la une pour le moment.</p>";
                     }
                 } catch (PDOException $e) {
                     error_log("Erreur chargement biens accueil: " . $e->getMessage());
                     echo "<p class='text-center text-danger col-12'>Erreur lors du chargement des biens.</p>";
                 } finally { $pdo = null; } // Fermer la connexion
                ?>
                <!-- Fin Boucle PHP -->

            </div>
             <div class="text-center mt-5" data-aos="fade-up">
                 <a href="biens-disponibles.php" class="btn btn-primary btn-lg">Voir Tous Nos Biens</a>
             </div>
        </div>
    </section>

    <!-- ============================================================== -->
    <!-- Section Services                                               -->
    <!-- ============================================================== -->
    <section id="services" class="section-padding bg-light">
        <div class="container">
            <h2 class="text-center section-title" data-aos="fade-up">Vos Garanties avec Nous</h2>
            <div class="row text-center g-4">
                <div class="col-lg-3 col-md-6" data-aos="fade-up">
                    <div class="service-item p-4 pt-5 bg-white rounded shadow-sm h-100">
                        <i class="ri-shield-check-line"></i>
                        <h5 class="mt-3">Sécurité Juridique</h5>
                        <p class="text-muted">Contrats conformes et validés par notaire, protégeant locataires et propriétaires.</p>
                    </div>
                </div>
                 <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="100">
                     <div class="service-item p-4 pt-5 bg-white rounded shadow-sm h-100">
                        <i class="ri-file-list-3-line"></i>
                        <h5 class="mt-3">Gestion Centralisée</h5>
                        <p class="text-muted">Suivez vos contrats, paiements et documents importants depuis votre espace personnel.</p>
                    </div>
                </div>
                 <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="200">
                     <div class="service-item p-4 pt-5 bg-white rounded shadow-sm h-100">
                        <i class="ri-secure-payment-line"></i>
                        <h5 class="mt-3">Transactions Fiables</h5>
                        <p class="text-muted">Suivi rigoureux des loyers et cautions, avec génération de quittances claires.</p>
                    </div>
                </div>
                 <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="300">
                     <div class="service-item p-4 pt-5 bg-white rounded shadow-sm h-100">
                         <i class="ri-team-line"></i>
                        <h5 class="mt-3">Accompagnement</h5>
                        <p class="text-muted">Assistance dédiée et expertise notariale pour répondre à vos questions.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

     <!-- ============================================================== -->
    <!-- Section Appel à l'Action (CTA)                                -->
    <!-- ============================================================== -->
    <section class="cta-section section-padding text-center">
        <div class="container">
            <h2 class="mb-4" data-aos="fade-up">Simplifiez Votre Expérience Immobilière</h2>
            <p class="lead mb-4 mx-auto" style="max-width: 650px;" data-aos="fade-up" data-aos-delay="100">
                Rejoignez notre plateforme et bénéficiez d'une gestion locative moderne, transparente et sécurisée par l'autorité notariale.
            </p>
            <div data-aos="fade-up" data-aos-delay="200">
                <a href="biens-disponibles.php" class="btn btn-lg me-2 mb-2" style="background-color: var(--couleur-accent); color: var(--couleur-primaire); --bs-btn-hover-bg: #c09d2b; border:none; font-weight:bold;">Voir les Biens Disponibles</a>
                <a href="auth-signup.php" class="btn btn-outline-light btn-lg mb-2">Créer Votre Compte</a>
            </div>
        </div>
    </section>

    <!-- ============================================================== -->
    <!-- Footer                                                         -->
    <!-- ============================================================== -->
    <footer>
        <div class="container">
             <div class="row g-4 mb-5">
                 <div class="col-lg-4">
                     <h5 class="mb-3">GestionLocative<span style="color: var(--couleur-accent);">.</span></h5>
                     <p>Votre partenaire de confiance pour une gestion immobilière sécurisée et simplifiée grâce à l'intervention notariale.</p>
                     <div class="social-icons mt-3">
                         <a href="#" aria-label="Facebook"><i class="ri-facebook-fill"></i></a>
                         <a href="#" aria-label="Twitter"><i class="ri-twitter-x-line"></i></a>
                         <a href="#" aria-label="LinkedIn"><i class="ri-linkedin-fill"></i></a>
                         <a href="#" aria-label="Instagram"><i class="ri-instagram-line"></i></a>
                     </div>
                 </div>
                 <div class="col-lg-2 col-md-4 col-6">
                     <h5>Navigation</h5>
                     <ul>
                         <li><a href="index.php">Accueil</a></li>
                         <li><a href="biens-disponibles.php">Biens à Louer</a></li>
                         <li><a href="#services">Nos Services</a></li>
                         <li><a href="contact.php">Contact</a></li>
                     </ul>
                 </div>
                 <div class="col-lg-2 col-md-4 col-6">
                     <h5>Espaces</h5>
                     <ul>
                         <li><a href="auth-signin.php">Connexion</a></li>
                         <li><a href="auth-signup.php">Inscription</a></li>
                         <li><a href="locataire-dashboard.php">Locataire</a></li>
                         <li><a href="proprietaire-dashboard.php">Propriétaire</a></li>
                         <li><a href="notaire-dashboard.php">Notaire</a></li>
                     </ul>
                 </div>
                 <div class="col-lg-4 col-md-4">
                      <h5>Contactez-Nous</h5>
                      <p><i class="ri-map-pin-line me-2"></i>Votre Adresse Physique, Ville, Pays</p>
                      <p><i class="ri-phone-line me-2"></i><a href="tel:+XXXXXXXXX">+XX XXX XXX XX</a></p>
                      <p><i class="ri-mail-line me-2"></i><a href="mailto:info@votredomaine.com">info@votredomaine.com</a></p>
                 </div>
             </div>
             <div class="footer-bottom text-center">
                 <p class="mb-0">© <script>document.write(new Date().getFullYear())</script> GestionLocative Notariale. Concept par [Votre Nom/Société].</p>
                 <p><a href="#privacy">Confidentialité</a> | <a href="#terms">Conditions d'Utilisation</a></p>
             </div>
        </div>
    </footer>

    <!-- ============================================================== -->
    <!-- Scripts JS                                                     -->
    <!-- ============================================================== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
     <script>
         // Initialiser AOS
         AOS.init({ duration: 800, once: true });

        // Navbar change de couleur au scroll
        const navbar = document.querySelector('.navbar');
        if (navbar) { // Vérifier si la navbar existe
             window.addEventListener('scroll', () => {
                if (window.scrollY > 50) {
                    navbar.classList.add('scrolled', 'navbar-light', 'bg-light', 'shadow-sm'); // Ajoute fond clair et ombre
                     // Optionnel: changer le logo si besoin
                    // const logoDark = navbar.querySelector('.logo-dark');
                    // const logoLight = navbar.querySelector('.logo-light');
                    // if(logoDark && logoLight) { logoDark.style.display='inline-block'; logoLight.style.display='none'; }
                 } else {
                     navbar.classList.remove('scrolled', 'navbar-light', 'bg-light', 'shadow-sm');
                     // Optionnel: remettre le logo clair
                    // const logoDark = navbar.querySelector('.logo-dark');
                    // const logoLight = navbar.querySelector('.logo-light');
                    // if(logoDark && logoLight) { logoDark.style.display='none'; logoLight.style.display='inline-block'; }
                }
             });
         }
     </script>
</body>
</html>