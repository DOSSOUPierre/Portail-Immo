<?php
// --- proprietaire-modifier-bien.php ---
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Propriétaire') { ob_end_clean(); header("Location: auth-signin.php"); exit; }
require_once __DIR__ . '/db_connection.php';
if (!isset($pdo)) { ob_end_clean(); die("Erreur BDD."); }
$idProprietaire = $_SESSION['idProprietaire'] ?? null;
if ($idProprietaire === null) { ob_end_clean(); die("ID Propriétaire session manquant."); }

$idBienAModifier = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$bienData = null;
$pageAlerts = [];
$formValues = $_POST; // Pour garder les valeurs soumises en cas d'erreur POST

// --- Récupérer données du bien à modifier (si ID fourni) ---
if ($idBienAModifier) {
    try {
        $sqlGet = "SELECT * FROM bienimmobiliers WHERE idBien = :idBien AND idProprietaire = :idProp";
        $stmtGet = $pdo->prepare($sqlGet);
        $stmtGet->execute([':idBien' => $idBienAModifier, ':idProp' => $idProprietaire]);
        $bienData = $stmtGet->fetch(PDO::FETCH_ASSOC);
        if (!$bienData) { $pageAlerts[] = ['type' => 'danger', 'message' => 'Bien introuvable ou accès refusé.']; }
         // Vérifier si modifiable
         elseif (!in_array($bienData['supervisionStatut'], ['En attente', 'Suspendu'])) {
              $pageAlerts[] = ['type' => 'warning', 'message' => 'Ce bien ne peut pas être modifié car son statut est : ' . htmlspecialchars($bienData['supervisionStatut'])];
              // Optionnel: désactiver le formulaire ou rediriger
         } else {
              // Si pas de POST, pré-remplir $formValues avec les données de la BDD
              if($_SERVER['REQUEST_METHOD'] !== 'POST') {
                  $formValues = $bienData;
              }
         }
    } catch (PDOException $e) { $pageAlerts[] = ['type'=>'danger', 'message'=>'Erreur BDD chargement bien.']; error_log("PDO Get Modif Bien: ".$e->getMessage()); }
} else {
    $pageAlerts[] = ['type' => 'danger', 'message' => 'Aucun ID de bien spécifié pour la modification.'];
}


// --- Traitement du POST pour la mise à jour ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'update_bien' && $idBienAModifier) {
     // Récupération des données
     $adressePost = trim($_POST['adresse'] ?? '');
     $loyerMensuelPost = filter_input(INPUT_POST, 'loyerMensuel', FILTER_VALIDATE_FLOAT);
     $errors = [];
     if (empty($adressePost)) $errors[] = "Adresse requise."; if ($loyerMensuelPost === false || $loyerMensuelPost < 0) $errors[] = "Loyer invalide.";
     // Validation/Gestion fichier image principale (comme dans save_bien POST original)
     // ...
     // Validation/Gestion fichiers images secondaires (comme dans save_bien POST original)
     // ...

     if (empty($errors) && $bienData && in_array($bienData['supervisionStatut'], ['En attente', 'Suspendu'])) { // Double check permission/statut
          try {
              $pdo->beginTransaction();
              // Requête UPDATE
              $sqlUpdate = "UPDATE bienimmobiliers SET adresse = :adr, loyerMensuel = :loy /*, image_profil = :img*/ WHERE idBien = :idb AND idProprietaire = :idp";
              $stmtUpdate = $pdo->prepare($sqlUpdate);
              $paramsUpdate = [':adr'=>$adressePost, ':loy'=>$loyerMensuelPost, ':idb'=>$idBienAModifier, ':idp'=>$idProprietaire];
               // Ajouter ':img'=>$imageProfilName si géré
              if($stmtUpdate->execute($paramsUpdate)) {
                  // Gérer images secondaires (suppr anciennes, ajout nouvelles) comme dans save_bien
                  // ...
                  $pdo->commit();
                  $_SESSION['flash_success'] = "Bien #$idBienAModifier mis à jour avec succès.";
                  header("Location: proprietaire-mes-biens.php"); // Rediriger vers la liste
                  exit;
              } else { throw new Exception("Echec mise à jour BDD."); }
          } catch (Exception $e) { $pdo->rollBack(); $pageAlerts[] = ['type' => 'danger', 'message' => "Erreur MàJ: " . $e->getMessage()]; }
     } else {
         if (!empty($errors)) $pageAlerts[] = ['type' => 'danger', 'message' => "Erreurs validation: <br>- " . implode('<br>- ', $errors)];
         elseif(!$bienData || !in_array($bienData['supervisionStatut'], ['En attente', 'Suspendu'])) $pageAlerts[]=['type'=>'warning', 'message'=>'Modification non autorisée.'];
     }
}

$pdo = null; ob_end_flush();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Modifier Bien #<?php echo htmlspecialchars($idBienAModifier ?? ''); ?> | Propriétaire</title>
    <!-- Mêmes CSS/JS que proprietaire-mes-biens.php -->
    <style>/* Styles */</style>
</head>
<body>
    <div class="wrapper">
        <?php include './includes/proprietaire_topbar.php'; ?>
        <?php include './includes/right_sidebar.php'; ?>
        <?php include './includes/proprietaire_left_sidebar.php'; ?>

        <div class="page-content"> <div class="container-fluid">
            <!-- Titre, Breadcrumb -->
             <div class="row"> <div class="col-12"> <div class="page-title-box"> <h4 class="page-title">Modifier Bien #<?php echo htmlspecialchars($idBienAModifier ?? ''); ?></h4>...</div> </div> </div>
            <!-- Alertes -->
            <div id="pageAlertPlaceholder"><?php foreach ($pageAlerts as $a): ?><div class="alert alert-<?= $a['type'] ?>">...</div><?php endforeach; ?></div>

            <?php if ($bienData && in_array($bienData['supervisionStatut'], ['En attente', 'Suspendu'])): // Afficher form si OK ?>
                <div class="card"> <div class="card-body">
                    <h5 class="card-title mb-3">Informations du Bien</h5>
                     <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . urlencode($idBienAModifier); ?>" method="POST" enctype="multipart/form-data" novalidate>
                          <input type="hidden" name="action" value="update_bien">
                          <!-- Pré-remplir avec $formValues -->
                          <div class="mb-3"> <label for="adresseBien">Adresse <span class="text-danger">*</span></label> <textarea class="form-control" id="adresseBien" name="adresse" required><?= htmlspecialchars($formValues['adresse'] ?? ''); ?></textarea></div>
                          <div class="mb-3"> <label for="loyerMensuelBien">Loyer (FCFA) <span class="text-danger">*</span></label> <input type="number" class="form-control" id="loyerMensuelBien" name="loyerMensuel" required min="0" value="<?= htmlspecialchars($formValues['loyerMensuel'] ?? ''); ?>"></div>
                          <!-- Ajouter champs image principale + secondaire + previews -->
                          <!-- ... -->
                          <button type="submit" class="btn btn-primary">Enregistrer les Modifications</button>
                          <a href="proprietaire-mes-biens.php" class="btn btn-secondary">Annuler</a>
                     </form>
                </div> </div>
            <?php elseif ($bienData): // Si bien trouvé mais non modifiable ?>
                <div class="alert alert-warning">Ce bien (Statut: <?= htmlspecialchars($bienData['supervisionStatut']) ?>) ne peut pas être modifié actuellement.</div>
                <a href="proprietaire-mes-biens.php" class="btn btn-secondary">Retour</a>
            <?php endif; ?>

        </div> </div>
        <footer class="footer">...</footer>
    </div>
    <script src="assets/js/vendor.js"></script> <script src="assets/js/app.js"></script>
    <!-- JS pour validation client si besoin -->
</body>
</html>