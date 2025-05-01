<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

// Vérifie la présence du fichier
if (!isset($_FILES['signatureImageFile'])) {
    echo json_encode(['success' => false, 'message' => 'Fichier de signature manquant']);
    exit;
}

// Dossier cible pour stocker les signatures
$targetDir = 'uploads/signatures/'; // ➔ tu dois créer ce dossier s’il n’existe pas
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

$file = $_FILES['signatureImageFile'];
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$maxSize = 3 * 1024 * 1024; // 3 Mo

// Vérifications format et taille
if (!in_array($file['type'], $allowedTypes)) {
    echo json_encode(['success' => false, 'message' => 'Type de fichier non autorisé']);
    exit;
}
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'message' => 'Fichier trop volumineux (max 3 Mo)']);
    exit;
}

// Générer un nom unique pour éviter les collisions
$filename = 'signature_' . time() . '_' . uniqid() . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
$targetFile = $targetDir . $filename;

// Déplacer le fichier uploadé
if (move_uploaded_file($file['tmp_name'], $targetFile)) {
    echo json_encode([
        'success' => true,
        'message' => 'Signature enregistrée avec succès',
        'imagePath' => $targetFile,
        'signedDate' => date('d/m/Y H:i')
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Erreur lors du transfert du fichier']);
}
?>
