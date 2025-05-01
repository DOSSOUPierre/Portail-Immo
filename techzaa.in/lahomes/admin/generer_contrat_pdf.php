<?php
// --- generer_contrat_pdf.php (v3 - Adapté BDD + Signatures Images Base64) ---

// error_reporting(E_ALL); ini_set('display_errors', 1); // Pour débogage
ob_start();

// 1. Charger l'autoloader de Composer (CHEMIN A ADAPTER !)
require_once __DIR__ . '/mon-projet/vendor/autoload.php'; // Ajustez si besoin

use Dompdf\Dompdf;
use Dompdf\Options;

// 2. Connexion BDD ($pdo)
require_once __DIR__ . '/db_connection.php';
if (!isset($pdo)) { ob_end_clean(); die("Erreur BDD."); }

// 3. Récupérer l'ID du Contrat
$idContrat = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$idContrat) {
     // Pour test:
     //$idContrat = 17; // REMPLACEZ par un ID valide pour tester
     // En production :
     ob_end_clean(); die("Erreur: ID de contrat manquant ou invalide.");
}

// --- Définir les chemins (adaptez si nécessaire) ---
$uploadDirSignaturesServer = __DIR__ . '/uploads/signatures/'; // Chemin ABSOLU serveur où sont les images PNG/JPG
$logoCabinetPath = __DIR__ . '/assets/images/logo-cabinet-pdf.png'; // Chemin ABSOLU logo (optionnel)
$signaturePlaceholderPath = __DIR__ . '/assets/images/signature-placeholder.png'; // Chemin ABSOLU image si signature manque (optionnel)

// --- 4. Récupérer TOUTES les informations nécessaires (Requête adaptée BDD + signature utilisateur) ---
$contrat = null; $locataire = null; $proprietaire = null; $notaire = null; $bien = null;

try {
    // Requête pour récupérer le contrat ET les infos liées, Y COMPRIS les images signature des utilisateurs
    $sql = "SELECT
                c.*, -- Colonnes contrat (date/montant/statut/date signatures etc.)
                b.adresse AS bienAdresse, b.loyerMensuel AS bienLoyerBase, -- Colonnes bien
                CONCAT(u_loc.prenom, ' ', u_loc.nom) AS locataireNomComplet, u_loc.email AS locataireEmail, l.adresse AS locataireAdresse, l.telephone AS locataireTelephone,
                u_loc.signature_image AS locataireSignatureImage, -- <<<=== SIGNATURE LOCATAIRE DEPUIS UTILISATEURS
                CONCAT(u_prop.prenom, ' ', u_prop.nom) AS proprietaireNomComplet, u_prop.email AS proprietaireEmail, p.adresse AS proprietaireAdresse,
                u_prop.signature_image AS proprietaireSignatureImage, -- <<<=== SIGNATURE PROPRIETAIRE DEPUIS UTILISATEURS
                CONCAT(u_notaire.prenom, ' ', u_notaire.nom) AS notaireNomComplet, cab.nomCabinet, cab.adresseCabinet AS notaireAdresseCabinet
            FROM contrat c
            LEFT JOIN bienimmobiliers b ON c.idBien = b.idBien
            LEFT JOIN locataire l ON c.idLocataire = l.idLocataire LEFT JOIN utilisateurs u_loc ON l.idUser = u_loc.idUser
            LEFT JOIN proprietaire p ON c.idProprietaire = p.idProprietaire LEFT JOIN utilisateurs u_prop ON p.idUser = u_prop.idUser
            LEFT JOIN notaire n ON c.idNotaire = n.idNotaire LEFT JOIN utilisateurs u_notaire ON n.idUser = u_notaire.idUser
            LEFT JOIN cabinet cab ON n.idNotaire = cab.idNotaire
            WHERE c.idContrat = :idContrat";

    $stmt = $pdo->prepare($sql);
    if (!$stmt) throw new Exception("Erreur préparation requête principale: " . implode(", ", $pdo->errorInfo()));
    $stmt->execute([':idContrat' => $idContrat]);
    $contrat = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contrat) { throw new Exception("Contrat introuvable (ID: $idContrat)."); }

    // Simplifier les données en incluant les chemins des signatures
    $locataire = [
        'nom' => $contrat['locataireNomComplet'] ?? 'N/A',
        'email' => $contrat['locataireEmail'] ?? 'N/A',
        'adresse' => $contrat['locataireAdresse'] ?? 'N/A',
        'telephone' => $contrat['locataireTelephone'] ?? 'N/A',
        'signatureFichier' => $contrat['locataireSignatureImage'] ?? null, // <<<=== Utilise la colonne de utilisateurs
        'signatureDate' => $contrat['signatureLocataireDate'] ?? null
    ];
    $proprietaire = [
        'nom' => $contrat['proprietaireNomComplet'] ?? 'N/A',
        'email' => $contrat['proprietaireEmail'] ?? 'N/A',
        'adresse' => $contrat['proprietaireAdresse'] ?? 'N/A',
        'signatureFichier' => $contrat['proprietaireSignatureImage'] ?? null, // <<<=== Utilise la colonne de utilisateurs
        'signatureDate' => $contrat['signatureProprioDate'] ?? null
    ];
    $notaire = [
        'nom' => $contrat['notaireNomComplet'] ?? 'N/A',
        'cabinet' => $contrat['nomCabinet'] ?? 'N/A',
        'adresse' => $contrat['notaireAdresseCabinet'] ?? 'N/A',
        'signatureDate' => $contrat['signatureNotaireDate'] ?? null
    ];
     $bien = [
         'adresse' => $contrat['bienAdresse'] ?? 'N/A',
         'loyerContrat' => $contrat['montantLoyer'] ?? 0,
         'caution' => $contrat['caution'] ?? 0,
         'avanceMois' => $contrat['moisAvance'] ?? 0
     ];

} catch (Exception $e) { /* ... gestion erreur ... */ }
// --- 5. Construction du HTML pour le PDF ---

// Fonction helper pour encoder les images locales en Base64
function embed_image_base64($path) {
    // Vérifie si le chemin est non vide ET si le fichier existe ET est lisible
    if (!empty($path) && file_exists($path) && is_readable($path)) {
        try {
            $imageData = base64_encode(file_get_contents($path));
            // Tenter de déterminer le type MIME (plus fiable)
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $imageMime = finfo_file($finfo, $path);
            finfo_close($finfo);
            // Utiliser un type MIME générique si la détection échoue mais qu'on sait que c'est une image
            if (!$imageMime || strpos($imageMime, 'image/') !== 0) {
                 // Fallback basé sur l'extension (moins fiable)
                 $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                 if (in_array($ext, ['jpg', 'jpeg'])) $imageMime = 'image/jpeg';
                 elseif ($ext == 'png') $imageMime = 'image/png';
                 elseif ($ext == 'gif') $imageMime = 'image/gif';
                 elseif ($ext == 'webp') $imageMime = 'image/webp';
                 else return null; // Type non supporté
            }
            return 'data:' . $imageMime . ';base64,' . $imageData;
        } catch (Exception $e) { error_log("Erreur encodage image $path: ".$e->getMessage()); }
    }
    return null; // Retourne null si erreur, fichier non trouvé ou non lisible
}

// --- Début du HTML ---
$html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Contrat Location #' . $idContrat . '</title>';
$html .= '<style>
    /* Styles CSS (identiques à la version précédente) */
    @page { margin: 100px 50px 80px 50px; } header { position: fixed; top: -70px; left: 0px; right: 0px; height: 50px; text-align: center; } footer { position: fixed; bottom: -60px; left: 0px; right: 0px; height: 50px; font-size: 9pt; text-align: center; color: #888; }
    body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 11pt; line-height: 1.4; color: #333; } h2 { text-align: center; text-decoration: underline; margin-bottom: 30px; font-size: 16pt;} p { margin-bottom: 10px; text-align: justify; } strong { font-weight: bold; } .section { margin-bottom: 20px; } .header-content img { max-height: 60px; margin-bottom: 5px;} .header-content p { margin: 0; font-size: 9pt; color: #555;} .parties p { margin-bottom: 8px; } .article-title { font-weight: bold; margin-top: 15px; margin-bottom: 5px; text-decoration: underline;}
    /* Styles Signatures CORRIGÉS */
    .signatures { margin-top: 40px; page-break-inside: avoid; width: 100%; border-spacing: 15px; border-collapse: separate; }
    .signature-block { width: 32%; text-align: center; vertical-align: top; padding: 5px; border: 1px solid #eee; display: inline-block; /* Fallback si table non supportée */ margin-right: 1%; /* Espace entre blocs */ }
    .signature-label { font-weight: bold; margin-bottom: 10px; display: block; }
    .signature-image-container { min-height: 80px; /* Hauteur minimale pour voir le placeholder */ border-bottom: 1px solid #ccc; margin-bottom: 5px; display: flex; align-items: center; justify-content: center; padding: 5px 0; }
    .signature-block img { max-width: 180px; max-height: 70px; display: block; margin: 0 auto; /* Centrer image */ }
    .signature-block p { font-size: 9pt; margin-top: 2px; margin-bottom: 2px; text-align: center; }
    .signature-placeholder { font-style: italic; color: #aaa; font-size: 10pt; }
    .date-lieu { margin-top: 30px; text-align: right; }
</style></head><body>';

// --- En-tête et Pied de page ---
$html .= '<header><p style="text-align:right; font-size: 8pt;">Contrat #' . $idContrat . '</p></header>';
$html .= '<footer>Page <span class="pagenum"></span> - Généré le ' . date('d/m/Y H:i') . '</footer>';

// --- Corps du Contrat ---
$html .= '<main>';
// Logo et Infos Cabinet (si disponibles)
$logoBase64 = embed_image_base64($logoCabinetPath);
if ($logoBase64 || !empty($notaire['cabinet'])) {
    $html .= '<div style="text-align:center; margin-bottom:30px;">';
    if ($logoBase64) $html .= '<img src="' . $logoBase64 . '" alt="Logo" style="max-height: 70px;"><br>';
    if (!empty($notaire['cabinet'])) $html .= '<h3 style="margin-bottom: 2px;">' . htmlspecialchars($notaire['cabinet']) . '</h3>';
    if (!empty($notaire['adresse'])) $html .= '<p style="font-size:9pt; margin:0;">' . htmlspecialchars($notaire['adresse']) . /* Tel notaire retiré */ '</p>';
    $html .= '</div>';
}

$html .= '<h2>CONTRAT DE BAIL À USAGE D\'HABITATION</h2>';

// Parties (Correction: retrait téléphone proprio)
$html .= '<div class="parties section">';
$html .= '<p><strong>ENTRE LES SOUSSIGNÉS :</strong></p>';
$html .= '<p><strong>I. LE BAILLEUR :</strong><br>';
$html .= 'Nom et Prénoms : <strong>' . htmlspecialchars($proprietaire['nom']) . '</strong><br>';
$html .= 'Demeurant à : ' . htmlspecialchars($proprietaire['adresse'] ?? 'Non spécifiée') . '<br>';
if(!empty($proprietaire['email'])) $html .= 'Email : ' . htmlspecialchars($proprietaire['email']) . '<br>';
$html .= 'Ci-après dénommé "Le Bailleur".</p>';
$html .= '<p><strong>II. LE PRENEUR :</strong><br>';
$html .= 'Nom et Prénoms : <strong>' . htmlspecialchars($locataire['nom']) . '</strong><br>';
$html .= 'Demeurant à : ' . htmlspecialchars($locataire['adresse'] ?? 'Non spécifiée') . '<br>';
if(!empty($locataire['telephone'])) $html .= 'Téléphone : ' . htmlspecialchars($locataire['telephone']) . '<br>'; // Tel locataire conservé
if(!empty($locataire['email'])) $html .= 'Email : ' . htmlspecialchars($locataire['email']) . '<br>';
$html .= 'Ci-après dénommé "Le Preneur".</p>';
$html .= '</div>';

// Désignation des Locaux (Retrait ville si non dispo)
$html .= '<div class="objet section">';
$html .= '<p><strong>IL A ÉTÉ ARRÊTÉ ET CONVENU CE QUI SUIT :</strong></p>';
$html .= '<p>Le Bailleur loue par les présentes au Preneur, qui accepte, les locaux ci-après désignés :</p>';
$html .= '<p>Un logement (' . htmlspecialchars($bien['type'] ?? 'type inconnu') . ') situé à :<br><strong>' . htmlspecialchars($bien['adresse']) . '</strong></p>'; // Adresse seulement
$html .= '<p>(Ci-après dénommés "Les Locaux Loués").</p>';
$html .= '</div>';

// Articles (Formatage loyer/caution/avance corrigé, fonction date FR)
$html .= '<div class="articles section">';
$html .= '<p class="article-title">ARTICLE 1 : DURÉE</p>';
$html .= '<p>Le présent bail est consenti pour une durée de <strong>' . calculerDuree($contrat['dateDebut'], $contrat['dateFin']) . '</strong>, commençant le <strong>' . formatDateFR($contrat['dateDebut']) . '</strong> pour prendre fin le <strong>' . formatDateFR($contrat['dateFin']) . '</strong>.</p>';
$html .= '<p class="article-title">ARTICLE 2 : LOYER MENSUEL</p>';
$html .= '<p>Le loyer mensuel est fixé à <strong>' . number_format($bien['loyerContrat'], 0, ',', ' ') . ' francs CFA</strong>, payable d\'avance.</p>';
$html .= '<p class="article-title">ARTICLE 3 : DÉPÔT DE GARANTIE (CAUTION)</p>';
$html .= '<p>Le Preneur verse ce jour au Bailleur la somme de <strong>' . number_format($contrat['caution'] ?? 0, 0, ',', ' ') . ' francs CFA</strong> à titre de dépôt de garantie.</p>';
$html .= '<p class="article-title">ARTICLE 4 : AVANCE SUR LOYERS</p>';
$html .= '<p>Le Preneur verse également ce jour la somme correspondant à <strong>' . ($contrat['moisAvance'] ?? 0) . ' mois</strong> de loyer d\'avance, soit <strong>' . number_format(($bien['loyerContrat'] ?? 0) * ($contrat['moisAvance'] ?? 0), 0, ',', ' ') . ' francs CFA</strong>.</p>';
// ... Autres articles ...
$html .= '</div>';

// Lieu et Date Signature
$lieuSignature = "Cotonou"; $dateDuJour = formatDateFR(date('Y-m-d'));
$html .= '<div class="date-lieu"><p>Fait à ' . htmlspecialchars($lieuSignature) . ', le ' . $dateDuJour . '</p></div>';

// --- **MODIFIÉ** : Section Signatures (HTML) ---
// --- **CORRIGÉ** : Section Signatures (HTML) ---
$html .= '<div class="signatures">';
$html .= '<table style="width: 100%; border-collapse: separate; border-spacing: 15px;"><tr>'; // Utiliser table pour alignement

// Placeholder Image (Encoder une seule fois)
$placeholderSigBase64 = embed_image_base64($signaturePlaceholderPath); // On garde le placeholder au cas où

// --- Bloc Bailleur (Propriétaire) ---
$html .= '<td class="signature-block">'; // Début cellule table pour ce bloc
$html .= '<p class="signature-label">LE BAILLEUR</p>'; // Titre du rôle
$html .= '<div class="signature-image-container">'; // Conteneur pour l'image ou placeholder

// Récupérer le chemin du fichier depuis le tableau $proprietaire (qui vient de utilisateurs.signature_image)
$signaturePropPath = !empty($proprietaire['signatureFichier']) ? $uploadDirSignaturesServer . $proprietaire['signatureFichier'] : null;
// Tenter d'encoder l'image en base64
$signaturePropBase64 = embed_image_base64($signaturePropPath);

// Afficher l'image de signature si elle existe et a pu être encodée
if ($signaturePropBase64) {
    $html .= '<img src="' . $signaturePropBase64 . '" alt="Signature Propriétaire">';
}
// Sinon, si le placeholder général existe (cas où signature est totalement manquante OU fichier introuvable)
elseif ($placeholderSigBase64) {
     $html .= '<img src="' . $placeholderSigBase64 . '" alt="Signature Manquante">';
}
// Sinon (ni image, ni placeholder), afficher un texte simple
else {
    $html .= '<span class="signature-placeholder">(Signature Manquante)</span>';
}
$html .= '</div>'; // Fin signature-image-container

// Afficher le nom du propriétaire
$html .= '<p><i>' . htmlspecialchars($proprietaire['nom']) . '</i></p>';

// Afficher la date de signature si elle existe dans la table contrat
if (!empty($contrat['signatureProprioDate'])) {
    $html .= '<p><small>Signé le: ' . date('d/m/Y H:i', strtotime($contrat['signatureProprioDate'])) . '</small></p>';
}

$html .= '</td>'; // Fin cellule table pour ce bloc

// --- Bloc Preneur (Locataire) ---
$html .= '<td class="signature-block">';
$html .= '<p class="signature-label">LE PRENEUR</p>';
$html .= '<div class="signature-image-container">';
// Utiliser le chemin depuis la table utilisateurs via le tableau $locataire
$signatureLocPath = !empty($locataire['signatureFichier']) ? $uploadDirSignaturesServer . $locataire['signatureFichier'] : null;
$signatureLocBase64 = embed_image_base64($signatureLocPath);
if ($signatureLocBase64) {
    $html .= '<img src="' . $signatureLocBase64 . '" alt="Signature Locataire">';
} elseif ($placeholderSigBase64) {
     $html .= '<img src="' . $placeholderSigBase64 . '" alt="Signature Manquante">';
} else {
    $html .= '<span class="signature-placeholder">(Signature Manquante)</span>';
}
$html .= '</div>';
$html .= '<p><i>' . htmlspecialchars($locataire['nom']) . '</i></p>';
// Afficher date de signature depuis la table contrat
if (!empty($contrat['signatureLocataireDate'])) $html .= '<p><small>Signé le: ' . date('d/m/Y H:i', strtotime($contrat['signatureLocataireDate'])) . '</small></p>';
$html .= '</td>';

// --- Bloc Notaire ---
$html .= '<td class="signature-block">';
$html .= '<p class="signature-label">LE NOTAIRE</p>';
$html .= '<div class="signature-image-container">';
// Récupérer le chemin depuis la table utilisateurs via le tableau $notaire (si vous avez ajouté la colonne)
// Supposons que $notaire['signatureFichier'] existe si vous avez modifié la requête SQL
$signatureNotairePath = !empty($notaire['signatureFichier']) ? $uploadDirSignaturesServer . $notaire['signatureFichier'] : null;
$signatureNotaireBase64 = embed_image_base64($signatureNotairePath);
if ($signatureNotaireBase64) { // Si le notaire a une image de signature associée à son compte utilisateur
    $html .= '<img src="' . $signatureNotaireBase64 . '" alt="Signature Notaire">';
}
// Sinon, on vérifie s'il a validé (date) et on affiche le placeholder
elseif (!empty($contrat['signatureNotaireDate'])) {
    // Affiche le placeholder si le contrat est validé mais pas d'image trouvée pour le notaire
    if ($placeholderSigBase64) { $html .= '<img src="' . $placeholderSigBase64 . '" alt="Validation Notaire">'; }
    else { $html .= '<span class="signature-placeholder">(Validé électroniquement)</span>'; }
}
// Si ni image ni date, afficher placeholder ou texte "attente"
elseif ($placeholderSigBase64) {
    $html .= '<img src="' . $placeholderSigBase64 . '" alt="Validation Attendue">';
} else {
    $html .= '<span class="signature-placeholder">(Validation Attendue)</span>';
}
$html .= '</div>';
$html .= '<p><i>Maître ' . htmlspecialchars($notaire['nom']) . '</i></p>';
if (!empty($notaire['cabinet'])) { $html .= '<p><small>' . htmlspecialchars($notaire['cabinet']) . '</small></p>'; }
// Afficher la date de validation/signature du notaire si elle existe
if (!empty($contrat['signatureNotaireDate'])) {
    $html .= '<p><small>Validé le: ' . date('d/m/Y H:i', strtotime($contrat['signatureNotaireDate'])) . '</small></p>';
}
$html .= '</td>';

$html .= '</tr></table>';
$html .= '</div>'; // Fin .signatures
// ... Reste du HTML ...
// --- 6. Génération et Envoi du PDF ---
try {
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true); $options->set('isRemoteEnabled', true); // Important pour base64
    $options->set('defaultFont', 'DejaVu Sans'); // Police fiable
    $options->set('chroot', __DIR__); // Sécurité chemin

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $nomFichier = 'Contrat_Location_Ref' . $idContrat . '.pdf'; // Nom fichier plus simple
    $dompdf->stream($nomFichier, ["Attachment" => true]); // Forcer téléchargement

} catch (Exception $e) { error_log("Erreur DomPDF: " . $e->getMessage()); ob_end_clean(); die("Erreur génération PDF : " . htmlspecialchars($e->getMessage())); }

// --- FIN DU SCRIPT ---
?>

<?php
// --- Fonctions Helper PHP (Mettre à la fin ou dans un fichier séparé) ---
function formatDateFR($dateStr) { /* ... (inchangé) ... */ }
function calculerDuree($dateDebut, $dateFin) { /* ... (inchangé) ... */ }
?>