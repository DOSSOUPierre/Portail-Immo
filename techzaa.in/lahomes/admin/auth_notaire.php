<?php
// --- includes/auth_notaire.php ---

if (session_status() === PHP_SESSION_NONE) session_start();

$userId = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['user_role'] ?? null;
$userPrenom = $_SESSION['user_prenom'] ?? 'Notaire'; // Prénom pour le header

// Fonction utilitaire pour récupérer idNotaire (peut être dans un fichier helpers commun)
if (!function_exists('get_logged_in_notaire_id')) {
    function get_logged_in_notaire_id(?int $userId): ?int {
        if ($userId === null) return null;
        // Assurez-vous que $pdo est disponible ici (soit via global, soit passé en argument)
        // Pour simplifier, on suppose qu'il est inclus avant via db_connection.php
        global $pdo; // Utilisation de global (alternative: passer $pdo en argument)
        if (!isset($pdo)) { error_log("PDO non disponible dans get_logged_in_notaire_id"); return null; }
        try {
            $stmt = $pdo->prepare("SELECT idNotaire FROM notaire WHERE idUser = :userId");
            $stmt->execute([':userId' => $userId]);
            $result = $stmt->fetchColumn();
            return $result ? (int)$result : null;
        } catch (PDOException $e) {
            error_log("Erreur get_logged_in_notaire_id: " . $e->getMessage());
            return null;
        }
    }
}

// Inclure la connexion BDD si ce n'est pas déjà fait avant l'include de ce fichier
if (!isset($pdo)) {
    require_once __DIR__ . '/../db_connection.php'; // Ajuster le chemin si besoin
    if (!isset($pdo)) { die("Erreur critique: Connexion BDD impossible dans auth_notaire."); }
}

$current_notaire_id = $_SESSION['idNotaire'] ?? null;
if ($current_notaire_id === null && $userId !== null) {
    $current_notaire_id = get_logged_in_notaire_id($userId);
    if ($current_notaire_id) {
        $_SESSION['idNotaire'] = $current_notaire_id;
    }
}

// Vérification finale des droits
if ($userId === null || $userRole !== 'Notaire' || $current_notaire_id === null) {
    $logMsg = "Auth Failure Include: UserID=" . ($userId ?? 'ND') . " Role=" . ($userRole ?? 'ND') . " IDNotaire=" . ($current_notaire_id ?? 'ND');
    error_log($logMsg);
    // Détruire la session potentiellement invalide avant de rediriger
    session_unset();
    session_destroy();
    header("Location: auth-signin.php?error=auth_required"); // Rediriger vers la connexion
    exit;
}

// Si on arrive ici, l'utilisateur est authentifié comme Notaire
// Les variables $userId, $userRole, $userPrenom, $current_notaire_id sont disponibles
// $pdo est également disponible
?>