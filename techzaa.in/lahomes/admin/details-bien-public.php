<?php
// --- details-bien-public.php ---

require_once __DIR__ . '/db_connection.php'; // Connexion $mysqli

// 1. Récupérer et Valider l'ID du Bien depuis l'URL
$idBien = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$pageAlerts = [];
$bienDetails = null; // Contiendra les détails du bien

if (!$idBien) {
    $pageAlerts[] = ['type' => 'danger', 'message' => 'Identifiant du bien manquant ou invalide.'];
} else {
    // 2. Requête pour récupérer les détails du bien, propriétaire et images secondaires
    $sql = "SELECT
                b.idBien, b.adresse, b.ville, b.statut, b.loyerMensuel, b.image_profil,
                b.description_longue, -- Supposons que vous ayez cette colonne
                b.supervisionStatut,
                CONCAT(u.prenom, ' ', u.nom) AS nomProprietaire,
                GROUP_CONCAT(DISTINCT img.nom_fichier SEPARATOR '||') AS images_secondaires_list
            FROM bienimmobiliers b
            JOIN proprietaire p ON b.idProprietaire = p.idProprietaire
            JOIN utilisateurs u ON p.idUser = u.idUser
            LEFT JOIN images_description_bien img ON b.idBien = img.id_bien
            WHERE b.idBien = ?
              AND b.supervisionStatut = 'Validé' -- Afficher seulement si validé
              -- On pourrait aussi vérifier b.statut = 'Libre' ici si on ne veut VRAIMENT pas montrer les détails d'un bien occupé
            GROUP BY b.idBien"; // Grouper par bien pour GROUP_CONCAT

    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $idBien);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result->num_rows === 1) {
                $bienDetails = $result->fetch_assoc();
                // Traiter les images secondaires
                $bienDetails['images_secondaires'] = [];
                if (!empty($bienDetails['images_secondaires_list'])) {
                     // Utiliser un séparateur moins courant comme ||
                     $images = explode('||', $bienDetails['images_secondaires_list']);
                     // Nettoyer chaque nom de fichier (trim) et filtrer les vides potentiels
                     $bienDetails['images_secondaires'] = array_filter(array_map('trim', $images));
                }
                unset($bienDetails['images_secondaires_list']); // Nettoyer

                 // Sécurité supplémentaire: Ne pas afficher si statut n'est pas 'Libre'
                 if ($bienDetails['statut'] !== 'Libre') {
                     $bienDetails = null; // Considérer comme non trouvé pour le public
                     $pageAlerts[] = ['type' => 'warning', 'message' => 'Ce bien n\'est actuellement pas disponible à la location.'];
                 }

            } else {
                $pageAlerts[] = ['type' => 'warning', 'message' => 'Bien immobilier non trouvé ou non disponible.'];
            }
        } else {
            $pageAlerts[] = ['type' => 'danger', 'message' => 'Erreur lors de la récupération des détails du bien.'];
            error_log("Erreur SQL (details bien public exec): " . $stmt->error);
        }
        $stmt->close();
    } else {
        $pageAlerts[] = ['type' => 'danger', 'message' => 'Erreur serveur lors de la préparation de la requête.'];
        error_log("Erreur SQL (details bien public prep): " . $mysqli->error);
    }
}

$mysqli->close();

$defaultImage = 'assets/images/property/default.jpg';
$uploadDirRelative = 'uploads/biens/';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <title><?php echo $bienDetails ? htmlspecialchars($bienDetails['adresse']) : 'Détails Bien'; ?> | Portail Immobilier Notarial</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <!-- CSS -->
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" id="app-style" />
    <script src="assets/js/config.min.js"></script>
    <style>
        body { padding-top: 70px; }
        .navbar-public { background-color: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .property-detail-img { max-height: 500px; width: 100%; object-fit: cover; }
        .carousel-indicators [data-bs-target] { width: 70px; height: 50px; opacity: 0.6; text-indent: -999px; background-color: transparent; background-position: center; background-size: cover; }
        .carousel-indicators .active { opacity: 1; }
        .details-section { margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid #eee; }
        .details-section h4 { margin-bottom: 1rem; font-weight: 600; }
        .price-tag { font-size: 1.8rem; font-weight: 700; color: var(--#{$prefix}primary); }
        .owner-info { background-color: #f8f9fa; padding: 1rem; border-radius: 0.25rem; }
        .contact-section { margin-top: 2rem; }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- ========== Navbar Publique Simple ========== -->
         <header id="topnav" class="navbar-fixed-top navbar-public">
             <div class="container-fluid"> <nav class="navbar navbar-expand-lg"> <a class="navbar-brand me-auto" href="index.php"><img src="assets/images/logo-dark.png" alt="Logo" height="28"></a> <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent"><i class="ri-menu-line"></i></button> <div class="collapse navbar-collapse" id="navbarContent"> <ul class="navbar-nav ms-auto align-items-center"> <li class="nav-item"><a class="nav-link" href="index.php">Accueil</a></li> <li class="nav-item"><a class="nav-link active" href="liste-biens-public.php">Biens Disponibles</a></li> <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li> <li class="nav-item ms-lg-2"><a class="btn btn-primary btn-sm" href="auth-signin.php">Connexion</a></li> </ul> </div> </nav> </div>
         </header>
        <!-- ========== Navbar End ========== -->

        <div class="page-content">
            <div class="container-fluid">

                <!-- Affichage Alertes -->
                 <?php if (!empty($pageAlerts)): ?>
                 <div class="row justify-content-center mt-4"> <div class="col-lg-10">
                     <?php foreach ($pageAlerts as $alert): ?> <div class="alert alert-<?= $alert['type']; ?>" role="alert"><?= htmlspecialchars($alert['message']); ?></div> <?php endforeach; ?>
                 </div> </div>
                 <?php endif; ?>


                <?php if ($bienDetails): // Afficher seulement si le bien a été trouvé et est libre ?>
                <!-- Titre du bien -->
                <div class="row mt-4">
                    <div class="col-12">
                        <h2 class="mb-1 fw-semibold"><?= htmlspecialchars($bienDetails['adresse']) ?></h2>
                        <?php if (!empty($bienDetails['ville'])): ?>
                            <p class="text-muted fs-5"><i class="ri-map-pin-line me-1"></i><?= htmlspecialchars($bienDetails['ville']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-8">
                        <!-- Carousel Images -->
                        <div id="propertyCarousel" class="carousel slide carousel-fade mb-4" data-bs-ride="carousel">
                            <div class="carousel-inner rounded">
                                <?php
                                $mainImage = (!empty($bienDetails['image_profil']) && file_exists($uploadDirRelative . $bienDetails['image_profil'])) ? $uploadDirRelative . $bienDetails['image_profil'] : $defaultImage;
                                $allImages = array_merge([$mainImage], array_map(function($imgName) use ($uploadDirRelative, $defaultImage) {
                                    $path = $uploadDirRelative . $imgName;
                                    return (!empty($imgName) && file_exists($path)) ? $path : null;
                                }, $bienDetails['images_secondaires']));
                                $allImages = array_filter($allImages); // Enlever les images non trouvées (null)
                                ?>
                                <?php foreach ($allImages as $index => $imgPath): ?>
                                    <div class="carousel-item <?php echo ($index === 0) ? 'active' : ''; ?>">
                                        <img src="<?= htmlspecialchars($imgPath) ?>" class="d-block property-detail-img" alt="Photo du bien <?= $index + 1 ?>">
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($allImages)): // Cas où même l'image par défaut n'existe pas ?>
                                     <div class="carousel-item active">
                                        <img src="<?= $defaultImage ?>" class="d-block property-detail-img" alt="Image par défaut">
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if (count($allImages) > 1): ?>
                                <button class="carousel-control-prev" type="button" data-bs-target="#propertyCarousel" data-bs-slide="prev">
                                    <span class="carousel-control-prev-icon" aria-hidden="true"></span><span class="visually-hidden">Précédent</span>
                                </button>
                                <button class="carousel-control-next" type="button" data-bs-target="#propertyCarousel" data-bs-slide="next">
                                    <span class="carousel-control-next-icon" aria-hidden="true"></span><span class="visually-hidden">Suivant</span>
                                </button>
                            <?php endif; ?>
                        </div>

                        <!-- Description -->
                        <div class="details-section">
                            <h4>Description</h4>
                            <p>
                                <?= !empty($bienDetails['description_longue']) ? nl2br(htmlspecialchars($bienDetails['description_longue'])) : 'Aucune description détaillée disponible pour ce bien.' ?>
                            </p>
                            <!-- Ajouter d'autres détails si disponibles: superficie, nb pièces, etc. -->
                        </div>

                        <!-- Autres caractéristiques (Exemple) -->
                        <!-- <div class="details-section"> <h4>Caractéristiques</h4> <div class="row"> <div class="col-md-4"><i class="ri-ruler-2-line me-1"></i> Superficie: XX m²</div> <div class="col-md-4"><i class="ri-hotel-bed-line me-1"></i> Chambres: X</div> <div class="col-md-4"><i class="ri-showers-line me-1"></i> Salles de bain: X</div> </div> </div> -->

                    </div> <!-- Fin col-lg-8 -->

                    <div class="col-lg-4">
                        <!-- Carte latérale avec Prix, Statut, Propriétaire -->
                        <div class="card">
                            <div class="card-body">
                                <div class="text-center mb-3">
                                    <span class="price-tag"><?= number_format($bienDetails['loyerMensuel'] ?? 0, 0, ',', ' ') ?> FCFA</span> / mois
                                </div>
                                <hr>
                                <div class="mb-3">
                                    <p class="mb-1"><strong>Statut :</strong> <span class="badge bg-success">Disponible</span></p>
                                    <p class="mb-1"><strong>Référence :</strong> #<?= htmlspecialchars($bienDetails['idBien']) ?></p>
                                </div>
                                <hr>
                                <div class="owner-info text-center">
                                    <h5 class="mb-1">Proposé par :</h5>
                                    <p class="mb-0"><?= htmlspecialchars($bienDetails['nomProprietaire'] ?? 'Propriétaire') ?></p>
                                    <!-- Ne pas afficher email/tél public sans consentement -->
                                </div>
                                <div class="contact-section mt-4">
                                    <h5 class="text-center mb-3">Intéressé par ce bien ?</h5>
                                    <!-- Option 1: Lien vers une page de contact -->
                                    <a href="contact.php?bienId=<?= $bienDetails['idBien'] ?>" class="btn btn-primary w-100">Contacter le Notaire</a>
                                    <!-- Option 2: Afficher infos contact notaire (si pertinent) -->
                                    <!-- <p class="text-center mt-2"><small>Contactez Maître [Nom Notaire] au [Tel]</small></p> -->
                                    <!-- Option 3: Lien vers connexion/inscription locataire -->
                                     <p class="text-center mt-3"><small>Déjà locataire ? <a href="auth-signin.php">Connectez-vous</a> pour faire une demande.</small></p>
                                </div>
                            </div>
                        </div>
                    </div> <!-- Fin col-lg-4 -->
                </div> <!-- Fin row principal -->

                <?php else: ?>
                    <!-- Afficher ce message si $bienDetails est null (non trouvé ou non libre) -->
                    <div class="row mt-5">
                        <div class="col-12 text-center">
                             <i class="ri-error-warning-line fs-1 text-warning"></i>
                             <h3 class="mt-3">Bien Introuvable</h3>
                             <p class="text-muted">Le bien que vous cherchez n'existe pas ou n'est plus disponible à la location.</p>
                             <a href="liste-biens-public.php" class="btn btn-primary mt-3">Retour à la liste des biens</a>
                        </div>
                    </div>
                <?php endif; ?>

            </div> <!-- container-fluid -->
        </div> <!-- page-content -->

        <!-- Footer -->
         <footer class="footer footer-alt"> <div class="container-fluid"> <div class="row"> <div class="col-12 text-center"> <script>document.write(new Date().getFullYear())</script> © Portail Immobilier Notarial </div> </div> </div> </footer>

    </div> <!-- wrapper -->

    <!-- JS -->
    <script src="assets/js/vendor.js"></script>
    <script src="assets/js/app.js"></script>
</body>
</html>