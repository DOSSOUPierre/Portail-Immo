<?php
session_start();
header('Content-Type: application/json');

$responseAjax = ['success' => false, 'message' => 'Erreur inconnue'];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Mauvaise méthode d\'appel.');
    }

    if (!isset($_FILES['signatureImageFile'])) {
        throw new Exception('Aucun fichier reçu.');
    }

    $imgFile = $_FILES['signatureImageFile'];
    $maxSize = 3 * 1024 * 1024;
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $imgFile['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedMimeTypes)) {
        throw new Exception('Format non supporté.');
    }

    if ($imgFile['size'] > $maxSize) {
        throw new Exception('Fichier trop gros (> 3Mo).');
    }

    $signatureUploadDirServer = 'uploads/signatures/';
    if (!is_dir($signatureUploadDirServer)) {
        mkdir($signatureUploadDirServer, 0775, true);
    }
    if (!is_writable($signatureUploadDirServer)) {
        throw new Exception('Dossier upload inaccessible.');
    }

    $filename = 'signature_' . time() . '_' . uniqid() . '.' . pathinfo($imgFile['name'], PATHINFO_EXTENSION);
    $targetPath = $signatureUploadDirServer . $filename;

    if (!move_uploaded_file($imgFile['tmp_name'], $targetPath)) {
        throw new Exception('Erreur lors du transfert.');
    }

    $responseAjax['success'] = true;
    $responseAjax['message'] = 'Signature enregistrée.';
    $responseAjax['imagePath'] = $targetPath;
    $responseAjax['signedDate'] = date('d/m/Y H:i');

} catch (Exception $e) {
    $responseAjax['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($responseAjax);
exit;
?>
