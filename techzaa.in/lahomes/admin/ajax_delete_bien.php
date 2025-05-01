<?php
// --- ajax_delete_bien.php ---
ob_start();
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

$response = ['success' => false, 'message' => 'Erreur inconnue.'];

// 1. Vérifier Méthode POST et Session Propriétaire
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    $response['message'] = 'Méthode POST requise.';
} elseif (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'Propriétaire') {
    http_response_code(401); // Unauthorized
    $response['message'] = 'Accès refusé : Non connecté en tant que propriétaire.';
} elseif (!isset($_POST['id']) || !filter_var($_POST['id'], FILTER_VALIDATE_INT)) {
    http_response_code(400); // Bad Request
    $response['message'] = 'ID du bien manquant ou invalide.';
} else {
    $idBienDelete = (int)$_POST['id'];
    $idProprietaire = $_SESSION['idProprietaire'] ?? null;
    $uploadDirServer = __DIR__ . '/uploads/biens/'; // ** VÉRIFIER CE CHEMIN **

    if ($idProprietaire === null) {
         http_response_code(403); $response['message'] = 'Erreur interne : ID propriétaire.';
    } else {
        require_once __DIR__ . '/db_connection.php'; // Connexion PDO ($pdo)
        if (!isset($pdo)) { http_response_code(500); $response['message'] = 'Erreur connexion BDD.'; }
        else {
            $pdo->beginTransaction();
            try {
                 // 1. Vérifier si suppression possible (appartient au proprio, pas de contrat actif, bon statut admin)
                 $sql_check = "SELECT image_profil, supervisionStatut, idContratActif FROM bienimmobiliers WHERE idBien = :idBien AND idProprietaire = :idProp FOR UPDATE"; // FOR UPDATE pour verrouiller
                 $stmt_check = $pdo->prepare($sql_check);
                 if(!$stmt_check) throw new Exception("Echec Prep Check Del: ".implode(", ",$pdo->errorInfo()));
                 $stmt_check->execute([':idBien' => $idBienDelete, ':idProp' => $idProprietaire]);
                 $row_check = $stmt_check->fetch(PDO::FETCH_ASSOC);

                 if (!$row_check) throw new Exception("Bien non trouvé ou accès refusé.", 404);

                 $hasActiveContract = false; if (!empty($row_check['idContratActif'])) { $stmtCheckCt = $pdo->prepare("SELECT 1 FROM contrat WHERE idContrat = :cid AND statutContrat = 'Actif'"); $stmtCheckCt->execute([':cid' => $row_check['idContratActif']]); if($stmtCheckCt->fetchColumn()) $hasActiveContract = true; }
                 if ($hasActiveContract) throw new Exception("Suppression impossible: contrat actif lié.", 409); // 409 Conflict
                 // Autoriser suppression seulement si 'En attente' ou 'Suspendu' par admin
                 if (!in_array($row_check['supervisionStatut'], ['En attente', 'Suspendu'])) throw new Exception("Suppression impossible: statut '".$row_check['supervisionStatut']."' l'interdit.", 403);

                 // 2. Supprimer images serveur et BDD
                 $imgP = $row_check['image_profil']; if ($imgP && file_exists($uploadDirServer . $imgP)) @unlink($uploadDirServer . $imgP);
                 $sql_imgs = "SELECT nom_fichier FROM images_description_bien WHERE id_bien = :idBien"; $stmt_imgs = $pdo->prepare($sql_imgs); $stmt_imgs->execute([':idBien' => $idBienDelete]); while ($imgSecName = $stmt_imgs->fetchColumn()) { if (!empty($imgSecName) && file_exists($uploadDirServer . $imgSecName)) @unlink($uploadDirServer . $imgSecName); }
                 $sql_del_desc = "DELETE FROM images_description_bien WHERE id_bien = :idBien"; $stmt_del_desc = $pdo->prepare($sql_del_desc); $stmt_del_desc->execute([':idBien' => $idBienDelete]);

                 // 3. Supprimer bien
                 $sql_del_bien = "DELETE FROM bienimmobiliers WHERE idBien = :idBien AND idProprietaire = :idProp"; $stmt_del_bien = $pdo->prepare($sql_del_bien); $stmt_del_bien->execute([':idBien' => $idBienDelete, ':idProp' => $idProprietaire]);

                 if ($stmt_del_bien->rowCount() > 0) { $pdo->commit(); $response = ['success' => true, 'message' => "Bien #{$idBienDelete} supprimé."]; }
                 else { throw new Exception("Echec suppression BDD.", 500); } // RowCount = 0 peut aussi dire non trouvé

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $response['message'] = $e->getMessage();
                $httpCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
                http_response_code($httpCode);
                error_log("AJAX delete_bien Error: " . $e->getMessage());
            } finally {
                 $pdo = null; // Fermer la connexion
            }
        }
    }
}

ob_end_clean();
echo json_encode($response);
exit;
?>