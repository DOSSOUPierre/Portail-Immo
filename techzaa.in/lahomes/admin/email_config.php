<?php
// email_config.php

// Ce fichier contient les paramètres de configuration pour l'envoi d'emails via PHPMailer.
// --- /!\ ATTENTION SÉCURITÉ /!\ ---
// Les identifiants sont en clair ici. Ne pas versionner ce fichier (ajouter à .gitignore).
// Préférez les variables d'environnement en production.
// Si vous utilisez Gmail 2FA, utilisez un MOT DE PASSE D'APPLICATION, pas votre mot de passe principal.

// --- Configuration du Serveur SMTP ---

// Hôte du serveur SMTP pour Gmail
define('SMTP_HOST', 'smtp.gmail.com');

// Port SMTP pour TLS avec Gmail
define('SMTP_PORT', 587);

// Nom d'utilisateur SMTP (votre adresse Gmail complète)
define('SMTP_USERNAME', 'soulemanenouroudine41@gmail.com');

// Mot de passe SMTP
// /!\ REMPLACEZ 'VOTRE_MOT_DE_PASSE_APPLICATION_ICI' par votre mot de passe d'application Gmail /!\
// /!\ Si vous n'utilisez pas 2FA, mettez 'Nourou@20' mais activez "Accès moins sécurisé" (NON RECOMMANDÉ) /!\
define('SMTP_PASSWORD', 'VOTRE_MOT_DE_PASSE_APPLICATION_ICI'); // <-- METTEZ LE BON MOT DE PASSE ICI !

// Type de Sécurité (TLS pour le port 587 avec Gmail)
define('SMTP_SECURE', 'tls'); // Utiliser la chaîne, PHPMailer comprend

// Activer l'authentification SMTP (Obligatoire pour Gmail)
define('SMTP_AUTH', true);


// --- Informations sur l'Expéditeur ---

// Adresse email qui apparaîtra comme expéditeur
// Gmail forcera souvent l'utilisation de SMTP_USERNAME comme expéditeur réel.
define('EMAIL_FROM_ADDRESS', 'soulemanenouroudine41@gmail.com'); // Mettre la même que SMTP_USERNAME pour Gmail

// Nom qui apparaîtra comme expéditeur
define('EMAIL_FROM_NAME', 'Gestion Locative Notariale');


// --- Optionnel: Configuration Avancée ---

// Activer le débogage SMTP (0 = off, 2 = détaillé pour le diagnostic)
// Mettre à 2 si les emails ne partent pas, puis vérifier les logs PHP. Mettre 0 en production.
define('SMTP_DEBUG_LEVEL', 0); // Mettre 2 pour tester/déboguer

?>