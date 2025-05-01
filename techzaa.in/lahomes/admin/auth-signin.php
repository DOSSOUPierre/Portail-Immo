<?php
// auth-signin.php (version PDO)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Inclure la connexion BD (qui DOIT fournir la variable $pdo)
require_once 'db_connection.php'; // Assurez-vous que ce fichier définit $pdo

$login_error = ''; // Pour afficher l'erreur dans le HTML
$success_message = $_SESSION['success_message'] ?? ''; // Récupérer message succès inscription
unset($_SESSION['success_message']); // Effacer après lecture

// --- TRAITEMENT DU FORMULAIRE DE CONNEXION ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');
    $motDePasse = $_POST['motDePasse'] ?? '';
    // $rememberMe = isset($_POST['rememberMe']); // Logique 'Remember Me' non implémentée ici

    if (empty($email) || empty($motDePasse)) {
        $login_error = "L'email et le mot de passe sont requis.";
    } else {
        try {
            // Récupérer l'utilisateur avec PDO
            $sql = "SELECT idUser, nom, prenom, email, motDePasse, role, statut FROM utilisateurs WHERE email = :email LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $stmt->execute();

            // Utiliser fetch() car on attend au plus une ligne
            $user = $stmt->fetch(PDO::FETCH_ASSOC); // Récupère la ligne ou false si non trouvé

            if ($user) {
                // Vérifier si le compte est Actif
                if ($user['statut'] !== 'Actif') {
                     $login_error = "Votre compte est actuellement suspendu. Veuillez contacter l'administrateur.";
                }
                // Vérifier le mot de passe
                elseif (password_verify($motDePasse, $user['motDePasse'])) {
                    // Connexion réussie
                    // Régénérer l'ID de session pour la sécurité
                    session_regenerate_id(true);

                    // Stocker les informations en session
                    $_SESSION['user_id'] = $user['idUser']; // La clé est correcte !
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_prenom'] = $user['prenom'];
                    // Ajouter d'autres infos utiles si besoin (nom, etc.)
                    $_SESSION['user_nom'] = $user['nom'];

                    // --- Redirection basée sur le rôle ---
                    $redirectUrl = 'index.php'; // Page par défaut
                    switch ($user['role']) {
                        case 'Propriétaire':
                            $redirectUrl = 'proprietaire-dashboard.php';
                            break;
                        case 'Locataire':
                            $redirectUrl = 'locataire-dashboard.php';
                            break;
                        case 'Administrateur':
                            $redirectUrl = 'admin-dashboard.php';
                            break;
                        case 'Notaire':
                            $redirectUrl = 'notaire-dashboard.php';
                            break;
                    }
                    // La connexion PDO est généralement persistante, pas besoin de la fermer ici explicitement.
                    header("Location: " . $redirectUrl); // Effectuer la redirection
                    exit;

                } else {
                    // Mot de passe incorrect
                    $login_error = "Email ou mot de passe incorrect.";
                }
            } else {
                // Email non trouvé
                $login_error = "Email ou mot de passe incorrect.";
            }
        } catch (PDOException $e) {
            // En cas d'erreur de base de données
            $login_error = "Erreur serveur lors de la connexion. Veuillez réessayer.";
            // Logguer l'erreur réelle pour le débogage (ne pas l'afficher à l'utilisateur en production)
            error_log("Erreur PDO Login: " . $e->getMessage());
        }
    }
} // Fin traitement POST

?>
<!DOCTYPE html>
<html lang="fr">
<head>
     <meta charset="utf-8" />
     <title>Connexion | Portail Immobilier Notarial</title>
     <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <meta name="description" content="Page de connexion." />
     <meta http-equiv="X-UA-Compatible" content="IE=edge" />
     <link rel="shortcut icon" href="assets/images/favicon.ico">
     <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
     <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
     <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
     <script src="assets/js/config.min.js"></script>
     <style>.auth-logo img{max-height:40px;}.card.auth-card{border:none;box-shadow:0 0 35px rgba(0,0,0,.1);}.form-control::placeholder{color:#98a6ad;}.auth-card .form-label{font-weight:500;}</style>
</head>
<body class="authentication-bg">
     <div class="account-pages pt-2 pt-sm-5 pb-4 pb-sm-5">
          <div class="container">
               <div class="row justify-content-center">
                    <div class="col-xl-5 col-lg-6">
                         <div class="card auth-card">
                              <div class="card-body px-sm-4 py-5">
                                   <div class="mx-auto mb-4 text-center auth-logo">
                                        <a href="index.html" class="logo-dark"><img src="assets/images/logo-dark.png" height="100" alt="Logo"></a>
                                        <a href="index.html" class="logo-light"><img src="assets/images/logo-dark.png" height="100" alt="Logo Light"></a>
                                   </div>
                                   <h2 class="fw-bold text-uppercase text-center fs-18">Connexion</h2>
                                   <p class="text-muted text-center mt-1 mb-4">Accédez à votre espace.</p>

                                   <div class="px-sm-3">
                                        <!-- Affichage Succès Inscription -->
                                        <?php if (!empty($success_message)): ?>
                                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                                <?php echo htmlspecialchars($success_message); ?>
                                                <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert" aria-label="Close"></button>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Affichage Erreur Connexion -->
                                        <?php if (!empty($login_error)): ?>
                                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                                <?php echo htmlspecialchars($login_error); ?>
                                                <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert" aria-label="Close"></button>
                                            </div>
                                        <?php endif; ?>

                                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="authentication-form" id="loginForm">
                                             <div class="mb-3">
                                                  <label class="form-label" for="email">Adresse e-mail</label>
                                                  <input type="email" id="email" name="email" class="form-control bg-light bg-opacity-50 border-light py-2" placeholder="Email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; // Garder l'email si erreur ?>">
                                             </div>
                                             <div class="mb-3">
                                                  <a href="auth-password.php" class="float-end text-muted text-decoration-underline ms-1 small">Mdp oublié ?</a>
                                                  <label class="form-label" for="motDePasse">Mot de Passe</label>
                                                  <div class="input-group input-group-merge">
                                                       <input type="password" id="motDePasse" name="motDePasse" class="form-control bg-light bg-opacity-50 border-light py-2" placeholder="Mot de passe" required>
                                                        <span class="input-group-text bg-light bg-opacity-50 border-light" id="password-toggle" style="cursor: pointer;"><i class="ri-eye-off-line"></i></span>
                                                  </div>
                                             </div>
                                             <!-- La logique "Remember Me" n'est pas implémentée ici
                                             <div class="mb-3">
                                                  <div class="form-check">
                                                       <input type="checkbox" class="form-check-input" id="rememberMe" name="rememberMe">
                                                       <label class="form-check-label" for="rememberMe">Se souvenir de moi</label>
                                                  </div>
                                             </div> -->
                                             <div class="mb-1 text-center d-grid">
                                                  <button class="btn btn-primary py-2 fw-medium" type="submit">Se Connecter</button>
                                             </div>
                                        </form>
                                   </div>
                              </div>
                         </div>
                         <p class="mb-0 text-center text-muted">Pas encore de compte ? <a href="auth-signup.php" class="text-reset text-decoration-underline fw-bold ms-1">Inscrivez-vous</a></p>
                    </div>
               </div>
          </div>
     </div>

     <script src="assets/js/vendor.js"></script>
     <script src="assets/js/app.js"></script>
     <script>
        // Script JS pour afficher/masquer mot de passe (inchangé)
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('motDePasse');
            const passwordToggle = document.getElementById('password-toggle');
            if (passwordToggle && passwordInput) {
                passwordToggle.addEventListener('click', function() {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    this.querySelector('i').classList.toggle('ri-eye-line');
                    this.querySelector('i').classList.toggle('ri-eye-off-line');
                });
            }
        });
    </script>
</body>
</html>