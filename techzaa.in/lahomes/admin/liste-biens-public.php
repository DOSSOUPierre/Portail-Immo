<?php
// --- liste-biens-public.php (Corrigé et Connecté à votre BDD) ---

// error_reporting(E_ALL); ini_set('display_errors', 1); // Activer pour débogage si besoin
ob_start(); // Démarrer tampon de sortie

// 1. Connexion BDD (mysqli)
require_once __DIR__ . '/db_connection.php'; // Assurez-vous que ce chemin est correct
if (!isset($mysqli) || $mysqli->connect_error) {
    error_log("DB Connect Error (liste-biens): " . ($mysqli->connect_error ?? 'Unknown'));
    // Afficher un message d'erreur plus discret sur la page publique
    $pageAlerts[] = ['type' => 'warning', 'message' => 'Impossible de charger les biens pour le moment. Veuillez réessayer plus tard.'];
    $mysqli = null; // Empêcher les requêtes suivantes si la connexion a échoué
} else {
    $mysqli->set_charset("utf8mb4");
}

// 2. Initialisations
$pageAlerts = $pageAlerts ?? []; // Conserver les alertes de connexion si elles existent
$biens_disponibles = [];
$defaultImage = 'assets/images/property/default.jpg'; // Chemin vers votre image par défaut
$uploadDirRelative = 'uploads/biens/'; // Chemin RELATIF depuis ce fichier PHP vers le dossier des images

// 3. Récupération des Biens Disponibles (SEULEMENT SI CONNEXION OK)
if ($mysqli) { // Exécuter seulement si la connexion est établie
    // Critères : Validé par l'admin/notaire ET statut 'Libre'
    $sql = "SELECT idBien, adresse, ville, loyerMensuel, image_profil
            FROM bienimmobiliers
            WHERE supervisionStatut = 'Validé' AND statut = 'Libre'
            ORDER BY idBien DESC"; // Ou autre critère de tri pertinent

    $result = $mysqli->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $biens_disponibles[] = $row;
        }
        $result->free();
         // Si aucun bien n'est trouvé, on n'ajoute pas d'alerte ici, le HTML gérera l'affichage "Aucun bien"
    } else {
        // Erreur pendant l'exécution de la requête
        $pageAlerts[] = ['type' => 'danger', 'message' => 'Une erreur technique est survenue lors du chargement des biens.'];
        error_log("Erreur SQL (liste biens public): " . $mysqli->error);
    }

    $mysqli->close(); // Fermer la connexion ici après usage
} // Fin if ($mysqli)

ob_end_flush(); // Envoyer le contenu mis en tampon (y compris les éventuels messages d'erreur avant le HTML)
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <title>Biens Immobiliers Disponibles | Portail Immobilier Notarial</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Consultez nos biens immobiliers disponibles à la location." />
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <!-- CSS -->
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" id="app-style" />
    <script src="assets/js/config.min.js"></script>
    <style>
        /* Styles pour les cartes de biens et la navbar (identiques à la version précédente) */
        .property-card { margin-bottom: 24px; box-shadow: 0 0 24px 0 rgba(15, 34, 58, 0.05); transition: all .3s ease-in-out; border: none; }
        .property-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px 0 rgba(15, 34, 58, 0.1); }
        .property-card img.card-img-top { height: 220px; object-fit: cover; border-top-left-radius: calc(0.25rem - 1px); border-top-right-radius: calc(0.25rem - 1px); }
        .property-card .card-body { padding: 1.25rem; }
        .property-card .card-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem; }
        .property-card .card-title a { text-decoration: none; color: var(--bs-dark); transition: color .2s; }
        .property-card .card-title a:hover { color: var(--bs-primary); }
        .property-card .location { color: var(--bs-secondary); font-size: 0.9rem; margin-bottom: 1rem; display: block; }
        .property-card .price { font-size: 1.2rem; font-weight: 700; color: var(--bs-primary); }
        body { padding-top: 70px; } /* Ajuster si navbar fixe */
        .navbar-public { background-color: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <div class="wrapper">

        <!-- ========== Navbar Publique ========== -->
        <header id="topnav" class="navbar-fixed-top navbar-public">
            <div class="container-fluid">
                 <nav class="navbar navbar-expand-lg">
                     <a class="navbar-brand me-auto" href="index.php"> <img src="assets/images/logo-dark.png" alt="Logo" height="28"> </a>
                     <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent"><i class="ri-menu-line"></i></button>
                     <div class="collapse navbar-collapse" id="navbarContent">
                         <ul class="navbar-nav ms-auto align-items-center">
                             <li class="nav-item"><a class="nav-link" href="index.php">Accueil</a></li>
                             <li class="nav-item"><a class="nav-link active" href="liste-biens-public.php">Biens Disponibles</a></li>
                             <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
                             <li class="nav-item ms-lg-2"><a class="btn btn-primary btn-sm" href="auth-signin.php">Connexion</a></li>
                         </ul>
                     </div>
                 </nav>
            </div>
        </header>
        <!-- ========== Navbar End ========== -->

        <div class="page-content">
            <div class="container-fluid">
                <!-- Titre Page -->
                <div class="row mt-4">
                    <div class="col-12 text-center">
                        <h2 class="mb-2 fw-semibold">Biens Immobiliers Disponibles</h2>
                        <p class="text-muted mb-4">Trouvez votre prochaine location parmi nos offres.</p>
                    </div>
                </div>

                <!-- Affichage Alertes PHP -->
                 <div class="row justify-content-center"> <div class="col-lg-10">
                     <?php if (!empty($pageAlerts)): ?>
                         <?php foreach ($pageAlerts as $alert): ?>
                         <div class="alert alert-<?= htmlspecialchars($alert['type']); ?> alert-dismissible fade show" role="alert">
                             <?= htmlspecialchars($alert['message']); ?>
                             <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                         </div>
                         <?php endforeach; ?>
                     <?php endif; ?>
                 </div> </div>

                <!-- Grille des Biens -->
                <div class="row">
                    <?php if (empty($biens_disponibles)): ?>
                        <div class="col-12">
                            <div class="text-center py-5 my-5">
                                <i class="ri-home-smile-2-line fs-1 text-muted"></i>
                                <p class="mt-3 text-muted fs-5">Aucun bien disponible à la location pour le moment.</p>
                                <p class="text-muted">Revenez bientôt !</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($biens_disponibles as $bien):
                            // Déterminer le chemin de l'image, utiliser l'image par défaut si non trouvé
                            $imagePath = (!empty($bien['image_profil']) && file_exists($uploadDirRelative . $bien['image_profil']))
                                        ? $uploadDirRelative . htmlspecialchars($bien['image_profil'])
                                        : $defaultImage;
                            // Formater le loyer
                            $loyerFormatte = number_format($bien['loyerMensuel'] ?? 0, 0, ',', ' ') . ' FCFA/mois';
                            // Créer l'URL des détails
                            $detailsUrl = 'details-bien-public.php?id=' . urlencode($bien['idBien']);
                            $adresseCourte = htmlspecialchars($bien['adresse'] ?? 'Adresse inconnue');
                            $ville = htmlspecialchars($bien['ville'] ?? ''); // Utiliser la colonne 'ville' si elle existe
                        ?>
                        <div class="col-lg-4 col-md-6">
                            <div class="card property-card overflow-hidden"> <!-- overflow-hidden pour arrondis image -->
                                <a href="<?= $detailsUrl ?>">
                                    <img src="<?= $imagePath ?>" class="card-img-top" alt="Photo de <?= $adresseCourte ?>" onerror="this.onerror=null; this.src='<?= $defaultImage ?>';"> <!-- onerror pour image cassée -->
                                </a>
                                <div class="card-body">
                                    <h5 class="card-title mb-1">
                                        <a href="<?= $detailsUrl ?>"><?= $adresseCourte ?></a>
                                    </h5>
                                    <?php if ($ville): ?>
                                        <span class="location"><i class="ri-map-pin-2-line me-1 opacity-75"></i><?= $ville ?></span>
                                    <?php else: ?>
                                         <span class="location"> </span> <!-- Placeholder si pas de ville -->
                                    <?php endif; ?>
                                    <p class="price mt-2 mb-3"><?= $loyerFormatte ?></p>
                                    <a href="<?= $detailsUrl ?>" class="btn btn-primary btn-sm w-100">Voir les Détails</a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div> <!-- Fin row grille biens -->

                 <!-- Pagination (à rendre dynamique si besoin) -->
                 <div class="row mt-4 mb-5"> <div class="col-12"> <nav> <ul class="pagination justify-content-center"> <!-- ... Liens pagination ... --> </ul> </nav> </div> </div>

            </div> <!-- container-fluid -->
        </div> <!-- page-content -->

        <!-- Footer -->
         <footer class="footer footer-alt"> <div class="container-fluid"> <div class="row"> <div class="col-12 text-center"> <script>document.write(new Date().getFullYear())</script> © Portail Immobilier Notarial. </div> </div> </div> </footer>

    </div> <!-- wrapper -->

    <!-- JS Core -->
    <script src="assets/js/vendor.js"></script>
    <script src="assets/js/app.js"></script>
</body>
</html>