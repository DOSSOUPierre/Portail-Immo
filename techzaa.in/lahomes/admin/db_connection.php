<?php
// db_connection.php

$db_host = '127.0.0.1'; // ou 'localhost'
$db_name = 'template';
$db_user = 'root';       // Votre utilisateur MySQL
$db_pass = '';           // Votre mot de passe MySQL (laisser vide si pas de mdp)
$charset = 'utf8mb4';

$dsn = "mysql:host=$db_host;dbname=$db_name;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Important pour voir les erreurs SQL
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Récupérer les résultats en tableaux associatifs
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Utiliser les vraies requêtes préparées
];

try {
     $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (\PDOException $e) {
     // En production, logguer l'erreur plutôt que l'afficher
     // Pour le développement :
     error_log("Erreur de connexion à la base de données : " . $e->getMessage());
     // Afficher un message générique à l'utilisateur ou rediriger vers une page d'erreur
     die("Erreur de connexion à la base de données. Veuillez réessayer plus tard.");
     // Alternative: throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// La variable $pdo est maintenant disponible pour les scripts qui incluent ce fichier.
?>