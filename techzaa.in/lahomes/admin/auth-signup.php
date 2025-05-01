<?php
// --- auth-signup.php (MODIFIÉ: Champs spécifiques + Upload Signature) ---

// Démarrer la session seulement si elle n'est pas déjà active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db_connection.php'; // Fournit $pdo

$errors = [];
$register_data = []; // Pour pré-remplir
$uploadDirSignaturesServer = __DIR__ . '/uploads/signatures/'; // Dossier pour images signature
if (!is_dir($uploadDirSignaturesServer)) @mkdir($uploadDirSignaturesServer, 0775, true); // Créer si besoin

// --- TRAITEMENT DU FORMULAIRE ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Récupérer données communes
    $nom = trim($_POST['nom'] ?? ''); $prenom = trim($_POST['prenom'] ?? ''); $email = trim($_POST['email'] ?? ''); $motDePasse = $_POST['motDePasse'] ?? ''; $motDePasseConfirm = $_POST['motDePasseConfirm'] ?? ''; $role = $_POST['role'] ?? ''; $termsAccepted = isset($_POST['terms']);

    // Récupérer données spécifiques (communes à plusieurs rôles)
    $telephone = trim($_POST['telephone'] ?? '');
    $adresse = trim($_POST['adresse'] ?? ''); // Adresse (pour tous sauf admin)
    $numeroCNI = trim($_POST['numeroCNI'] ?? ''); // CNI (pour tous sauf admin)

    // Stocker données soumises
    $register_data = ['nom' => $nom, 'prenom' => $prenom, 'email' => $email, 'role' => $role, 'telephone' => $telephone, 'adresse' => $adresse, 'numeroCNI' => $numeroCNI];

    // --- Validation ---
    if (empty($nom)) $errors[] = "Nom requis."; if (empty($prenom)) $errors[] = "Prénom requis."; if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email invalide."; if (empty($motDePasse) || strlen($motDePasse) < 8) $errors[] = "Mot de passe invalide (min 8 caractères)."; if ($motDePasse !== $motDePasseConfirm) $errors[] = "Mots de passe différents."; $allowed_roles = ['Propriétaire', 'Locataire', 'Notaire', 'Administrateur']; if (empty($role) || !in_array($role, $allowed_roles)) $errors[] = "Rôle invalide."; if (!$termsAccepted) $errors[] = "Acceptation des termes requise.";

    // Validation spécifique au rôle (SAUF Admin)
    if ($role !== 'Administrateur') {
        if (empty($telephone)) $errors[] = "Téléphone requis pour ce rôle.";
        // elseif (!preg_match('/^\+?[0-9\s\-()]{8,}$/', $telephone)) $errors[] = "Format téléphone invalide."; // Validation format optionnelle
        
        if (empty($numeroCNI)) $errors[] = "Numéro CNI requis pour ce rôle.";
    }

    // --- Validation & Préparation Upload Signature Image (SAUF Admin) ---
    $signatureFileNameDB = null; // Nom du fichier à stocker en BDD
    $tempFilePath = null;
    if ($role !== 'Administrateur') {
        if (!isset($_FILES['signature_image']) || $_FILES['signature_image']['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = "L'image de votre signature est requise.";
        } elseif ($_FILES['signature_image']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Erreur lors de l'upload de l'image signature (code: ".$_FILES['signature_image']['error'].").";
        } else {
            $file = $_FILES['signature_image']; $maxSize = 1 * 1024 * 1024; // 1MB max pour signature
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']; $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
            if ($file['size'] > $maxSize) $errors[] = "Image signature trop lourde (max 1Mo).";
            if (!in_array($mime, $allowedMimes)) $errors[] = "Format image signature invalide (JPG, PNG, GIF, WEBP).";
            if(empty($errors)) $tempFilePath = $file['tmp_name']; // Prêt à être déplacé si tout le reste est OK
        }
    }

    // --- Vérification unicité Email ---
    if (empty($errors)) { try { /* ... (Code vérif email identique) ... */ } catch (PDOException $e) { /*...*/ } }

    // --- Insertion BDD ---
    if (empty($errors)) {
        $hashed_password = password_hash($motDePasse, PASSWORD_DEFAULT);
        $pdo->beginTransaction();
        try {
            // 1. Déplacer le fichier signature si présent et prêt
            if ($tempFilePath && $role !== 'Administrateur') {
                 $extension = strtolower(pathinfo($_FILES['signature_image']['name'], PATHINFO_EXTENSION));
                 // Nom basé sur rôle et timestamp pour unicité (ID User pas encore connu)
                 $safeRole = preg_replace('/[^a-z0-9_]/i', '', strtolower($role));
                 $signatureFileNameDB = "signature_{$safeRole}_" . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                 $destinationPath = $uploadDirSignaturesServer . $signatureFileNameDB;
                 if (!move_uploaded_file($tempFilePath, $destinationPath)) {
                     throw new Exception("Échec de l'enregistrement de l'image signature.");
                 }
             }

            // 2. Insérer dans `utilisateurs` (avec signature_image si applicable)
            $sql_user = "INSERT INTO utilisateurs (nom, prenom, email, motDePasse, role, statut, signature_image) VALUES (:nom, :prenom, :email, :password, :role, 'Actif', :signature)";
            $stmt_user = $pdo->prepare($sql_user);
            // Le paramètre :signature sera null pour l'admin
            $stmt_user->execute([ ':nom' => $nom, ':prenom' => $prenom, ':email' => $email, ':password' => $hashed_password, ':role' => $role, ':signature' => ($role !== 'Administrateur' ? $signatureFileNameDB : null) ]);
            $idUser = $pdo->lastInsertId();
            if (!$idUser) throw new Exception("Impossible de récupérer l'ID utilisateur créé.");

            // 3. Insérer dans la table spécifique au rôle
            $roleTable = ''; $rolePK = ''; $roleFK = 'idUser'; // FK par défaut vers utilisateurs
            $specificFields = []; // Champs spécifiques à insérer

            switch ($role) {
                case 'Propriétaire':
                    $roleTable = 'proprietaire'; $rolePK = 'idProprietaire';
                    $specificFields = ['numeroCNI' => $numeroCNI, 'adresse' => $adresse];
                    break;
                case 'Locataire':
                    $roleTable = 'locataire'; $rolePK = 'idLocataire';
                    $specificFields = ['telephone' => $telephone, 'adresse' => $adresse]; // CNI pas dans locataire
                    break;
                case 'Notaire':
                    $roleTable = 'notaire'; $rolePK = 'idNotaire';
                    $specificFields = ['telephone' => $telephone, 'numeroCNI' => $numeroCNI, 'adresse' => $adresse];
                    break;
                case 'Administrateur':
                    $roleTable = 'administrateur'; $rolePK = 'idAdmin'; $roleFK = 'idAdmin'; // PK/FK spéciale pour admin
                    break;
                default: throw new Exception("Rôle non géré pour insertion spécifique.");
            }

            // Construire et exécuter l'insertion spécifique (si ce n'est pas admin qui a juste PK)
            if (!empty($roleTable) && $role !== 'Administrateur') {
                $cols = [$roleFK]; $placeholders = [':idUserFK']; $params = [':idUserFK' => $idUser];
                foreach($specificFields as $key => $val) {
                    $cols[] = "`" . $key . "`"; // Protéger noms colonnes
                    $ph = ':' . $key;
                    $placeholders[] = $ph;
                    $params[$ph] = !empty($val) ? $val : null; // Gérer valeurs vides comme NULL
                }
                $sql_role = "INSERT INTO `$roleTable` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $stmt_role = $pdo->prepare($sql_role);
                if (!$stmt_role) throw new Exception("Erreur préparation table rôle: " . $pdo->errorInfo()[2]);
                if (!$stmt_role->execute($params)) throw new Exception("Erreur insertion table rôle: " . $stmt_role->errorInfo()[2]);
            } elseif ($role === 'Administrateur') {
                 // Insertion simple pour Admin
                 $sql_admin = "INSERT INTO administrateur (idAdmin) VALUES (:idAdminFK)";
                 $stmt_admin = $pdo->prepare($sql_admin);
                 if (!$stmt_admin || !$stmt_admin->execute([':idAdminFK' => $idUser])) {
                     throw new Exception("Erreur insertion table admin: ".($pdo->errorInfo()[2] ?? 'Inconnue'));
                 }
            }

            $pdo->commit(); // Valider transaction

            $_SESSION['success_message'] = "Inscription réussie ! Vous pouvez maintenant vous connecter.";
            header("Location: auth-signin.php"); exit;

        } catch (PDOException $e) {
            $pdo->rollBack(); // Annuler transaction BDD
            // Supprimer l'image si elle a été uploadée mais que la BDD a échoué
            if ($signatureFileNameDB && isset($destinationPath) && file_exists($destinationPath)) { @unlink($destinationPath); }
            $errors[] = "Erreur inscription BDD."; error_log("PDOException Signup Tx: " . $e->getMessage());
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack(); // Assurer rollback
            if ($signatureFileNameDB && isset($destinationPath) && file_exists($destinationPath)) { @unlink($destinationPath); }
            $errors[] = "Erreur serveur: " . $e->getMessage(); error_log("Exception Signup Tx: " . $e->getMessage());
        }
    } // Fin if (empty($errors)) pour insertion
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
     <meta charset="utf-8" />
     <title>Inscription | Portail Immobilier Notarial</title>
     <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <link rel="shortcut icon" href="assets/images/favicon.ico">
     <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
     <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
     <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" id="app-style" />
     <script src="assets/js/config.js"></script>
     <style>
        .auth-logo img{max-height:40px;} .card.auth-card{border:none;box-shadow:0 0 35px rgba(0,0,0,.1);}
        .form-control::placeholder{color:#98a6ad;} #passwordHelp{font-size:.8em;margin-top:.25rem;}
        .alert ul{margin-bottom:0;padding-left:1.5rem;}
        /* Cacher les champs spécifiques par défaut */
        .specific-fields { display: none; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eee; }
        /* Style pour l'aperçu signature */
        #signaturePreviewContainer { margin-top: 0.5rem; border: 1px dashed #ced4da; padding: 0.5rem; min-height: 80px; display: flex; justify-content: center; align-items: center; background-color: #f8f9fa; }
        #signaturePreview { max-width: 200px; max-height: 100px; display: none; }
        #signaturePreview.active { display: block; }
        #signaturePreview.active + #signaturePreviewPlaceholder { display: none; }
        .form-control.is-invalid ~ .invalid-feedback, .form-select.is-invalid ~ .invalid-feedback, .was-validated .form-control:invalid ~ .invalid-feedback, .was-validated .form-select:invalid ~ .invalid-feedback { display: block; }
     </style>
</head>
<body class="authentication-bg">
     <div class="account-pages pt-2 pt-sm-5 pb-4 pb-sm-5"> <div class="container"> <div class="row justify-content-center"> <div class="col-xl-7 col-lg-8"> <!-- Augmenté largeur -->
        <div class="card auth-card"> <div class="card-body px-sm-4 py-5">
            <div class="mx-auto mb-4 text-center auth-logo"> <a href="index.php"><img src="assets/images/logo-dark.png" height="35"></a> </div>
            <h2 class="fw-bold text-uppercase text-center fs-18">Inscription</h2>
            <p class="text-muted text-center mt-1 mb-4">Créez votre compte sécurisé.</p>
            <div class="px-sm-3">
                <?php if (!empty($errors)): ?> <div class="alert alert-danger alert-dismissible fade show" role="alert"> <strong>Erreur(s) :</strong> <ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul> <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert" aria-label="Close"></button> </div> <?php endif; ?>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="authentication-form needs-validation" id="signupForm" novalidate enctype="multipart/form-data"> <!-- Ajout enctype -->
                    <!-- Champs Communs -->
                    <div class="row"> <div class="col-md-6 mb-3"> <label class="form-label" for="nom">Nom<span class="text-danger">*</span></label> <input type="text" id="nom" name="nom" class="form-control" required value="<?php echo htmlspecialchars($register_data['nom'] ?? ''); ?>"> <div class="invalid-feedback">Requis.</div> </div> <div class="col-md-6 mb-3"> <label class="form-label" for="prenom">Prénom<span class="text-danger">*</span></label> <input type="text" id="prenom" name="prenom" class="form-control" required value="<?php echo htmlspecialchars($register_data['prenom'] ?? ''); ?>"> <div class="invalid-feedback">Requis.</div> </div> </div>
                    <div class="mb-3"> <label class="form-label" for="email">E-mail<span class="text-danger">*</span></label> <input type="email" id="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($register_data['email'] ?? ''); ?>"> <div class="invalid-feedback">Email valide requis.</div> </div>
                    <div class="row"> <div class="col-md-6 mb-3"> <label class="form-label" for="motDePasse">Mot de Passe<span class="text-danger">*</span></label> <input type="password" id="motDePasse" name="motDePasse" class="form-control" required minlength="8" placeholder="Min 8 caractères"> <div class="invalid-feedback">Min 8 caractères.</div> </div> <div class="col-md-6 mb-3"> <label class="form-label" for="motDePasseConfirm">Confirmer Mdp<span class="text-danger">*</span></label> <input type="password" id="motDePasseConfirm" name="motDePasseConfirm" class="form-control" required minlength="8"> <div class="invalid-feedback">Confirmation requise.</div> <div id="passwordHelp" class="text-danger d-none small mt-1">Ne correspondent pas.</div> </div> </div>
                    <!-- Sélection Rôle -->
                    <div class="mb-3"> <label class="form-label" for="role">Vous êtes ?<span class="text-danger">*</span></label> <select class="form-select" id="role" name="role" required> <option value="" disabled <?php echo empty($register_data['role']) ? 'selected' : ''; ?>>-- Choisir --</option> <option value="Propriétaire" <?php echo ($register_data['role'] ?? '') === 'Propriétaire' ? 'selected' : ''; ?>>Propriétaire</option> <option value="Locataire" <?php echo ($register_data['role'] ?? '') === 'Locataire' ? 'selected' : ''; ?>>Locataire</option> <option value="Notaire" <?php echo ($register_data['role'] ?? '') === 'Notaire' ? 'selected' : ''; ?>>Notaire</option> <option value="Administrateur" <?php echo ($register_data['role'] ?? '') === 'Administrateur' ? 'selected' : ''; ?>>Administrateur</option> </select> <div class="invalid-feedback">Requis.</div> </div>

                     <!-- === NOUVEAU: Champs Spécifiques COMMUNS (sauf Admin) === -->
                     <div id="commonSpecificFields" class="specific-fields">
                          <h6 class="text-muted">Informations Supplémentaires</h6>
                          <div class="row">
                              <div class="col-md-6 mb-3">
                                  <label for="telephone" class="form-label">Téléphone<span class="text-danger">*</span></label>
                                  <input type="tel" id="telephone" name="telephone" class="form-control" placeholder="Ex: +229 XX XX XX XX" value="<?php echo htmlspecialchars($register_data['telephone'] ?? ''); ?>">
                                  <div class="invalid-feedback">Téléphone requis.</div>
                              </div>
                              <div class="col-md-6 mb-3">
                                  <label for="numeroCNI" class="form-label">Numéro CNI / Passeport<span class="text-danger">*</span></label>
                                  <input type="text" id="numeroCNI" name="numeroCNI" class="form-control" value="<?php echo htmlspecialchars($register_data['numeroCNI'] ?? ''); ?>">
                                   <div class="invalid-feedback">Numéro d'identité requis.</div>
                               </div>
                          </div>
                          <div class="mb-3">
                              <label for="adresse" class="form-label">Adresse Postale<span class="text-danger">*</span></label>
                              <input type="text" id="adresse" name="adresse" class="form-control" value="<?php echo htmlspecialchars($register_data['adresse'] ?? ''); ?>">
                              <div class="invalid-feedback">Adresse requise.</div>
                          </div>
                           <!-- Champ Upload Signature -->
                           <div class="mb-3">
                               <label for="signature_image" class="form-label">Image de votre Signature<span class="text-danger">*</span></label>
                               <input class="form-control" type="file" id="signature_image" name="signature_image" accept="image/png, image/jpeg, image/gif, image/webp" required>
                               <div class="form-text">Prenez une photo ou scannez votre signature manuscrite sur fond blanc. (Max 1Mo)</div>
                               <div class="invalid-feedback">Image de signature requise (JPG, PNG, GIF, WEBP, max 1Mo).</div>
                               <!-- Aperçu -->
                               <div id="signaturePreviewContainer" class="mt-2">
                                    <img id="signaturePreview" src="#" alt="Aperçu signature" />
                                    <span id="signaturePreviewPlaceholder" class="text-muted">Aperçu apparaîtra ici</span>
                               </div>
                           </div>
                     </div>
                     <!-- === FIN NOUVEAU === -->


                     <!-- Conditions & Submit -->
                     <div class="mb-3 mt-3"> <div class="form-check"> <input type="checkbox" class="form-check-input" id="checkbox-signup" name="terms" required> <label class="form-check-label" for="checkbox-signup">J'accepte les <a href="/conditions" target="_blank" class="text-muted">Termes et Conditions</a><span class="text-danger">*</span></label> <div class="invalid-feedback">Requis.</div> </div> </div>
                     <div class="mb-1 text-center d-grid"> <button class="btn btn-primary py-2 fw-medium" type="submit">Créer Mon Compte</button> </div>
                </form>
            </div>
        </div> </div> <p class="mb-0 text-center text-muted">Déjà un compte ? <a href="auth-signin.php" class="text-reset text-decoration-underline fw-bold ms-1">Connectez-vous</a></p>
    </div> </div> </div> </div>

     <!-- JS -->
     <script src="assets/js/vendor.js"></script>
     <script src="assets/js/app.js"></script>
     <script>
        // Validation Client Side (Bootstrap)
        (function () { 'use strict'; var forms=document.querySelectorAll('.needs-validation'); Array.prototype.slice.call(forms).forEach(function(form){ form.addEventListener('submit', function(event){ if(!form.checkValidity()){ event.preventDefault(); event.stopPropagation(); } form.classList.add('was-validated'); }, false); }); })();

        // Validation spécifique Mots de Passe
        const passwordInput = document.getElementById('motDePasse'); const confirmPasswordInput = document.getElementById('motDePasseConfirm'); const passwordHelpText = document.getElementById('passwordHelp'); const signupForm = document.getElementById('signupForm');
        function validatePasswordsMatch() { if (passwordInput.value !== confirmPasswordInput.value && confirmPasswordInput.value) { confirmPasswordInput.classList.add('is-invalid'); confirmPasswordInput.classList.remove('is-valid'); passwordHelpText.classList.remove('d-none'); } else if (confirmPasswordInput.value) { confirmPasswordInput.classList.remove('is-invalid'); confirmPasswordInput.classList.add('is-valid'); passwordHelpText.classList.add('d-none'); } else { confirmPasswordInput.classList.remove('is-invalid', 'is-valid'); passwordHelpText.classList.add('d-none'); } }
        if(passwordInput && confirmPasswordInput && passwordHelpText && signupForm) { passwordInput.addEventListener('input', validatePasswordsMatch); confirmPasswordInput.addEventListener('input', validatePasswordsMatch); /* L'écouteur submit de Bootstrap gère le reste */ }

        // --- **MODIFIÉ** : Afficher/Masquer champs spécifiques & Gérer 'required' ---
        const roleSelect = document.getElementById('role');
        const commonFieldsDiv = document.getElementById('commonSpecificFields');
        const telInput = document.getElementById('telephone');
        const cniInput = document.getElementById('numeroCNI');
        const adresseInput = document.getElementById('adresse');
        const signatureInput = document.getElementById('signature_image');
        const signaturePreviewContainer = document.getElementById('signaturePreviewContainer');

        function toggleSpecificFields() {
            const selectedRole = roleSelect ? roleSelect.value : null;

            if (selectedRole && selectedRole !== 'Administrateur') {
                 // Afficher les champs communs spécifiques
                 if (commonFieldsDiv) commonFieldsDiv.style.display = 'block';
                 // Rendre les champs requis
                 if (telInput) telInput.required = true;
                 if (cniInput) cniInput.required = true;
                 if (adresseInput) adresseInput.required = true;
                 if (signatureInput) signatureInput.required = true;

            } else {
                 // Cacher les champs communs spécifiques pour Admin ou si rien n'est sélectionné
                 if (commonFieldsDiv) commonFieldsDiv.style.display = 'none';
                 // Rendre les champs non requis
                 if (telInput) telInput.required = false;
                 if (cniInput) cniInput.required = false;
                 if (adresseInput) adresseInput.required = false;
                 if (signatureInput) signatureInput.required = false;
            }
        }

        // --- **NOUVEAU** : Preview Image Signature ---
        const signaturePreview = document.getElementById('signaturePreview');
        const signaturePreviewPlaceholder = document.getElementById('signaturePreviewPlaceholder');
        if(signatureInput && signaturePreview && signaturePreviewPlaceholder) {
             signatureInput.addEventListener('change', function(event) {
                 const file = event.target.files[0];
                 signaturePreview.style.display = 'none'; // Cacher par défaut
                 signaturePreview.classList.remove('active');
                 signaturePreviewPlaceholder.style.display = 'block'; // Afficher placeholder
                 signatureInput.classList.remove('is-invalid'); // Reset validité
                 document.querySelector('#signature_image ~ .invalid-feedback').textContent = 'Image de signature requise (JPG, PNG, GIF, WEBP, max 1Mo).'; // Reset message

                 if (file && file.type.startsWith('image/')) {
                     if (file.size > 1 * 1024 * 1024) { // 1MB Limit
                         signatureInput.classList.add('is-invalid');
                         document.querySelector('#signature_image ~ .invalid-feedback').textContent = 'Image trop lourde (max 1Mo).';
                         signatureInput.value = ''; return;
                     }
                     const reader = new FileReader();
                     reader.onload = function(e) {
                         signaturePreview.src = e.target.result;
                         signaturePreview.style.display = 'block';
                         signaturePreview.classList.add('active');
                         signaturePreviewPlaceholder.style.display = 'none';
                     }
                     reader.readAsDataURL(file);
                 } else if (file) { // Si un fichier est choisi mais pas une image
                      signatureInput.classList.add('is-invalid');
                      document.querySelector('#signature_image ~ .invalid-feedback').textContent = 'Format image invalide (JPG, PNG, GIF, WEBP).';
                      signatureInput.value = '';
                 }
             });
        }


        // Exécuter au chargement et au changement de rôle
        if(roleSelect) {
             roleSelect.addEventListener('change', toggleSpecificFields);
             toggleSpecificFields(); // Appel initial pour l'état au chargement
        }
    </script>

</body>
</html>