<?php
// --- ajax_get_bien_details.php (CORRIGÉ - Jointure contrat) ---
ob_start();
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

$response = ['success' => false, 'message' => 'Erreur inconnue.'];

// 1. Vérifier Session Propriétaire et ID Bien
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'Propriétaire') {
    http_response_code(401); $response['message'] = 'Accès refusé.';
} elseif (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    http_response_code(400); $response['message'] = 'ID bien invalide.';
} else {
    $idBienAjax = (int)$_GET['id'];
    $idProprietaire = $_SESSION['idProprietaire'] ?? null;

    if ($idProprietaire === null) {
         http_response_code(403); $response['message'] = 'Erreur ID propriétaire.';
    } else {
        require_once __DIR__ . '/db_connection.php'; // Connexion PDO ($pdo)
        if (!isset($pdo)) { http_response_code(500); $response['message'] = 'Erreur BDD.'; }
        else {
            try {
                // ** REQUETE SQL MODIFIÉE **
                $sql = "SELECT
                            b.*, -- Toutes les colonnes de bienimmobiliers
                            GROUP_CONCAT(DISTINCT img.nom_fichier SEPARATOR '|||') as images_secondaires_list,
                            -- Sous-requête (ou LEFT JOIN modifié) pour trouver UN contrat ACTIF pour ce bien
                            -- Utilisons un LEFT JOIN avec la condition dans le ON
                            c.idContrat AS contrat_id,
                            c.statutContrat AS contrat_statut,
                            CONCAT(u_loc.prenom, ' ', u_loc.nom) AS contrat_locataire_nom
                        FROM bienimmobiliers b
                        LEFT JOIN images_description_bien img ON b.idBien = img.id_bien
                        -- Jointure sur contrat MAIS conditionnée par le statut Actif ET l'idBien
                        LEFT JOIN contrat c ON c.idBien = b.idBien AND c.statutContrat = 'Actif'
                        -- Jointures pour le locataire SI un contrat actif existe
                        LEFT JOIN locataire l ON c.idLocataire = l.idLocataire
                        LEFT JOIN utilisateurs u_loc ON l.idUser = u_loc.idUser
                        WHERE b.idBien = :idBien AND b.idProprietaire = :idProp -- Vérifier l'appartenance
                        GROUP BY b.idBien"; // Group by pour GROUP_CONCAT et car on ne veut qu'une ligne par bien

                $stmt = $pdo->prepare($sql);
                if(!$stmt) throw new Exception("Erreur prep SQL: ". implode(", ", $pdo->errorInfo()));

                $stmt->execute([':idBien' => $idBienAjax, ':idProp' => $idProprietaire]);
                $details = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($details) {
                    $details['images_secondaires'] = !empty($details['images_secondaires_list']) ? explode('|||', $details['images_secondaires_list']) : [];
                    unset($details['images_secondaires_list']);
                    // Simplifier infos contrat
                    if($details['contrat_id'] && $details['contrat_statut'] === 'Actif') { // Vérifier si un contrat ACTIF a été trouvé
                        $details['contratActif'] = [
                            'id' => $details['contrat_id'],
                            'statut' => $details['contrat_statut'],
                            'locataireNom' => $details['contrat_locataire_nom']
                        ];
                    } else {
                        $details['contratActif'] = null; // Pas de contrat actif trouvé
                    }
                     // Nettoyer les colonnes brutes du contrat de la réponse principale
                    unset($details['contrat_id'], $details['contrat_statut'], $details['contrat_locataire_nom']);

                    $response = ['success' => true, 'details' => $details];
                } else {
                    http_response_code(404); // Not Found
                    $response['message'] = "Bien non trouvé ou accès refusé.";
                }

            } catch (Exception $e) {
                http_response_code(500); // Internal Server Error
                $response['message'] = "Erreur serveur: " . $e->getMessage();
                error_log("AJAX get_details Error: " . $e->getMessage());
            } finally {
                 $pdo = null; // Fermer la connexion PDO
            }
        }
    }
}

ob_end_clean(); // Nettoyer avant d'envoyer
echo json_encode($response);
exit; // Terminer le script
?>