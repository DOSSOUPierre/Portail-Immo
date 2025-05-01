-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : mar. 29 avr. 2025 à 20:58
-- Version du serveur : 10.4.32-MariaDB
-- Version de PHP : 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `template`
--

-- --------------------------------------------------------

--
-- Structure de la table `administrateur`
--

CREATE TABLE `administrateur` (
  `idAdmin` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `administrateur`
--

INSERT INTO `administrateur` (`idAdmin`) VALUES
(200);

-- --------------------------------------------------------

--
-- Structure de la table `bienimmobiliers`
--

CREATE TABLE `bienimmobiliers` (
  `idBien` int(11) NOT NULL,
  `adresse` varchar(255) DEFAULT NULL,
  `statut` enum('Libre','Occupé') DEFAULT 'Libre',
  `loyerMensuel` decimal(10,2) DEFAULT NULL,
  `image_profil` varchar(255) DEFAULT NULL COMMENT 'Nom du fichier image principal',
  `supervisionStatut` enum('En attente','Validé','Suspendu') NOT NULL DEFAULT 'En attente' COMMENT 'Statut de validation par l''administrateur',
  `idProprietaire` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `bienimmobiliers`
--

INSERT INTO `bienimmobiliers` (`idBien`, `adresse`, `statut`, `loyerMensuel`, `image_profil`, `supervisionStatut`, `idProprietaire`) VALUES
(69, 'Calavi', 'Occupé', 30000.00, 'bien_profil_5_1745063550_e8d97f6d.jpeg', 'Validé', 5),
(70, 'zogbadjè', 'Libre', 14000.00, 'bien_profil_5_1745079919_40a19ccd.jpeg', 'Validé', 5),
(71, 'Kouandé', 'Libre', 8000.00, 'bien_profil_5_1745081755_0bca943a.jpeg', 'Validé', 5),
(72, 'ITAA', 'Occupé', 1000.00, 'bien_profil_5_1745478557_16951ade.jpeg', 'Validé', 5),
(73, 'Birni', 'Occupé', 35000.00, 'bien_profil_5_1745508685_10c5cd34.jpg', 'Validé', 5),
(74, 'Birni', 'Occupé', 35000.00, 'bien_profil_5_1745508705_f0b4b889.jpg', 'Validé', 5),
(75, 'Parakou', 'Libre', 8000.00, 'bien_profil_5_1745509524_32c0f489.jpg', 'Validé', 5),
(76, 'HECM PROCHE', 'Occupé', 69.00, 'bien_profil_5_1745597367_6d0291bb.jpeg', 'Validé', 5),
(77, 'zagba', 'Libre', 457.00, 'bien_profil_5_1745597413_4a71a826.jpeg', 'Validé', 5),
(78, 'Parak', 'Libre', 55.00, 'bien_profil_5_1745767060_a4bb4ce5.jpeg', 'Suspendu', 5),
(79, 'HIOFHID', 'Libre', 59000.00, 'bien_profil_5_1745910842_70845f4c.jpeg', 'Suspendu', 5),
(80, 'LOME BARO', 'Occupé', 150000.00, 'bien_profil_5_1745950094_8b8167a2.jpeg', 'Validé', 5);

-- --------------------------------------------------------

--
-- Structure de la table `cabinet`
--

CREATE TABLE `cabinet` (
  `idCabinet` int(11) NOT NULL,
  `nomCabinet` varchar(150) DEFAULT NULL,
  `adresseCabinet` varchar(255) DEFAULT NULL,
  `idNotaire` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `contrat`
--

CREATE TABLE `contrat` (
  `idContrat` int(11) NOT NULL,
  `dateDebut` date DEFAULT NULL,
  `dateFin` date DEFAULT NULL,
  `montantLoyer` float DEFAULT NULL,
  `caution` float DEFAULT NULL COMMENT 'Montant caution (si différent de 1 mois)',
  `moisAvance` int(11) DEFAULT 3 COMMENT 'Nombre de mois payés en avance',
  `dateCreation` timestamp NOT NULL DEFAULT current_timestamp(),
  `statutContrat` enum('En attente signatures','Signé par les parties','Actif','Résilié','Expiré') DEFAULT 'En attente signatures',
  `signatureLocataireDate` datetime DEFAULT NULL,
  `signatureProprioDate` datetime DEFAULT NULL,
  `signatureNotaireDate` datetime DEFAULT NULL COMMENT 'Date activation par notaire',
  `signature_proprio_image` varchar(255) DEFAULT NULL COMMENT 'Nom fichier signature uploadée par proprio',
  `signature_locataire_image` varchar(255) DEFAULT NULL COMMENT 'Nom fichier signature uploadée par locataire',
  `tokenSignatureLocataire` varchar(64) DEFAULT NULL,
  `tokenSignatureProprietaire` varchar(64) DEFAULT NULL,
  `tokenExpireAt` datetime DEFAULT NULL COMMENT 'Expiration des tokens de signature',
  `fichierContratSigneFinal` varchar(255) DEFAULT NULL COMMENT 'Chemin PDF final généré',
  `idBien` int(11) DEFAULT NULL,
  `idLocataire` int(11) DEFAULT NULL,
  `idProprietaire` int(11) DEFAULT NULL,
  `idNotaire` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `contrat`
--

INSERT INTO `contrat` (`idContrat`, `dateDebut`, `dateFin`, `montantLoyer`, `caution`, `moisAvance`, `dateCreation`, `statutContrat`, `signatureLocataireDate`, `signatureProprioDate`, `signatureNotaireDate`, `signature_proprio_image`, `signature_locataire_image`, `tokenSignatureLocataire`, `tokenSignatureProprietaire`, `tokenExpireAt`, `fichierContratSigneFinal`, `idBien`, `idLocataire`, `idProprietaire`, `idNotaire`) VALUES
(16, '2025-04-27', '2025-04-30', 12, NULL, NULL, '2025-04-27 17:34:53', 'Actif', '2025-04-25 20:18:39', '2025-04-24 20:18:39', '2025-04-26 20:18:39', NULL, NULL, NULL, NULL, NULL, NULL, 76, 2, 5, 1),
(17, '2025-04-29', '2025-06-26', 17000, NULL, 3, '2025-04-29 18:21:25', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 80, 4, 5, 4);

-- --------------------------------------------------------

--
-- Structure de la table `images_description_bien`
--

CREATE TABLE `images_description_bien` (
  `id` int(11) NOT NULL,
  `id_bien` int(11) NOT NULL COMMENT 'Clé étrangère vers bienimmobiliers.idBien',
  `nom_fichier` varchar(255) NOT NULL COMMENT 'Nom du fichier image de description',
  `date_ajout` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stocke les chemins des images secondaires des biens';

--
-- Déchargement des données de la table `images_description_bien`
--

INSERT INTO `images_description_bien` (`id`, `id_bien`, `nom_fichier`, `date_ajout`) VALUES
(6, 69, 'bien_sec_69_1745063550_19725a0.jpeg', '2025-04-19 11:52:30'),
(7, 71, 'bien_sec_71_1745081882_149c9b0.jpeg', '2025-04-19 16:58:02'),
(8, 72, 'bien_sec_72_1745478557_3e8c050.png', '2025-04-24 07:09:17'),
(9, 73, 'bien_sec_73_1745508685_fb824e0.jpeg', '2025-04-24 15:31:25'),
(10, 74, 'bien_sec_74_1745508705_352ca30.jpeg', '2025-04-24 15:31:45'),
(11, 75, 'bien_sec_75_1745509524_895d840.jpeg', '2025-04-24 15:45:24'),
(12, 76, 'bien_sec_76_1745597367_33f6020.png', '2025-04-25 16:09:27'),
(13, 77, 'bien_sec_77_1745597413_d8cb9a0.png', '2025-04-25 16:10:13'),
(14, 79, 'bien_sec_79_1745910842_5113e30.jpeg', '2025-04-29 07:14:02'),
(15, 79, 'bien_sec_79_1745910842_608b171.jpeg', '2025-04-29 07:14:02'),
(16, 79, 'bien_sec_79_1745910842_3e112a2.jpeg', '2025-04-29 07:14:02');

-- --------------------------------------------------------

--
-- Structure de la table `locataire`
--

CREATE TABLE `locataire` (
  `idLocataire` int(11) NOT NULL,
  `adresse` varchar(255) DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `statutPaiement` tinyint(1) DEFAULT NULL,
  `idUser` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `locataire`
--

INSERT INTO `locataire` (`idLocataire`, `adresse`, `telephone`, `statutPaiement`, `idUser`) VALUES
(2, NULL, '0166582913', NULL, 199),
(3, NULL, '0166582913', NULL, 204),
(4, 'Cotonou', '+229 98848605', NULL, 207);

-- --------------------------------------------------------

--
-- Structure de la table `notaire`
--

CREATE TABLE `notaire` (
  `idNotaire` int(11) NOT NULL,
  `numeroCNI` varchar(100) DEFAULT NULL,
  `adresse` varchar(255) DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `idUser` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `notaire`
--

INSERT INTO `notaire` (`idNotaire`, `numeroCNI`, `adresse`, `telephone`, `idUser`) VALUES
(1, NULL, NULL, NULL, 202),
(2, NULL, NULL, NULL, 203),
(3, NULL, NULL, NULL, 205),
(4, '987455841025', 'Cotonou', '+226 5895958945', 208);

-- --------------------------------------------------------

--
-- Structure de la table `notification`
--

CREATE TABLE `notification` (
  `idNotif` int(11) NOT NULL,
  `dateNotif` date DEFAULT NULL,
  `contenu` text DEFAULT NULL,
  `statutNotif` enum('Envoyé','Lu') DEFAULT NULL,
  `typeNotif` enum('email','appel de paiement','autre') DEFAULT NULL,
  `idUser` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `notification`
--

INSERT INTO `notification` (`idNotif`, `dateNotif`, `contenu`, `statutNotif`, `typeNotif`, `idUser`) VALUES
(1, '2025-04-25', 'Nouveau contrat (#11) créé pour le bien à Birni. Vérifiez votre espace.', 'Envoyé', 'autre', 204),
(2, '2025-04-25', 'Nouveau contrat (#11) établi pour votre bien: Birni.', 'Envoyé', 'autre', 198),
(3, '2025-04-25', 'Nouveau contrat (#12) créé pour le bien à Birni. Vérifiez votre espace.', 'Envoyé', 'autre', 199),
(4, '2025-04-25', 'Nouveau contrat (#12) établi pour votre bien: Birni.', 'Envoyé', 'autre', 198),
(5, '2025-04-25', 'Nouveau contrat (#13) créé pour le bien à HECM PROCHE. Vérifiez votre espace.', 'Envoyé', 'autre', 199),
(6, '2025-04-25', 'Nouveau contrat (#13) établi pour votre bien: HECM PROCHE.', 'Envoyé', 'autre', 198),
(7, '2025-04-26', 'Nouveau contrat (#14) créé pour le bien à zagba. Vérifiez votre espace.', 'Envoyé', 'autre', 199),
(8, '2025-04-26', 'Nouveau contrat (#14) établi pour votre bien: zagba.', 'Envoyé', 'autre', 198),
(9, '2025-04-26', 'Nouveau contrat (#15) créé pour le bien à zogbadjè. Vérifiez votre espace.', 'Envoyé', 'autre', 199),
(10, '2025-04-26', 'Nouveau contrat (#15) établi pour votre bien: zogbadjè.', 'Envoyé', 'autre', 198),
(11, '2025-04-27', 'Nouveau contrat (#16) créé pour le bien à HECM PROCHE. Vérifiez votre espace.', 'Envoyé', 'autre', 199),
(12, '2025-04-27', 'Nouveau contrat (#16) établi pour votre bien: HECM PROCHE.', 'Envoyé', 'autre', 198);

-- --------------------------------------------------------

--
-- Structure de la table `paiementloyer`
--

CREATE TABLE `paiementloyer` (
  `idPaiement` int(11) NOT NULL,
  `typePaiement` enum('caution','avance','loyer') DEFAULT NULL,
  `statutPaiement` enum('validé','en attente') DEFAULT NULL,
  `montant` float DEFAULT NULL,
  `moisDebut` date DEFAULT NULL,
  `moisFin` date DEFAULT NULL,
  `modalitePaiement` enum('mensuel','trimestriel','annuel') DEFAULT NULL,
  `methodePaiement` enum('Mobile Money','Virement','Espèce','Autre') DEFAULT NULL,
  `datePaiement` date DEFAULT NULL,
  `idContrat` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `proprietaire`
--

CREATE TABLE `proprietaire` (
  `idProprietaire` int(11) NOT NULL,
  `numeroCNI` varchar(100) DEFAULT NULL,
  `adresse` varchar(255) DEFAULT NULL,
  `idUser` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `proprietaire`
--

INSERT INTO `proprietaire` (`idProprietaire`, `numeroCNI`, `adresse`, `idUser`) VALUES
(5, NULL, NULL, 198),
(6, NULL, NULL, 201),
(7, '987455841025', 'Cotonou', 206);

-- --------------------------------------------------------

--
-- Structure de la table `quittance`
--

CREATE TABLE `quittance` (
  `idQuittance` int(11) NOT NULL,
  `dateEmission` date DEFAULT NULL,
  `montant` float DEFAULT NULL,
  `idContrat` int(11) DEFAULT NULL,
  `idPaiement` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `signatures`
--

CREATE TABLE `signatures` (
  `idSignature` int(11) NOT NULL,
  `idContrat` int(11) NOT NULL COMMENT 'Lien vers le contrat concerné',
  `idUserSignataire` int(11) NOT NULL COMMENT 'Lien vers l utilisateur qui a signé',
  `roleSignataire` enum('Locataire','Proprietaire','Notaire') NOT NULL COMMENT 'Rôle de l utilisateur au moment de la signature',
  `typeSignature` enum('UploadImage','OTP','Autre') NOT NULL DEFAULT 'UploadImage',
  `signatureData` varchar(255) NOT NULL COMMENT 'Chemin vers fichier image OU ref code OTP vérifié',
  `ipAdresse` varchar(45) DEFAULT NULL COMMENT 'Adresse IP lors de la signature',
  `userAgent` text DEFAULT NULL COMMENT 'Infos navigateur/appareil',
  `dateSignature` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Enregistrement des signatures électroniques';

-- --------------------------------------------------------

--
-- Structure de la table `utilisateurs`
--

CREATE TABLE `utilisateurs` (
  `idUser` int(11) NOT NULL,
  `nom` varchar(100) DEFAULT NULL,
  `prenom` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `motDePasse` varchar(255) DEFAULT NULL,
  `role` enum('Administrateur','Notaire','Propriétaire','Locataire') NOT NULL,
  `statut` enum('Actif','Suspendu') NOT NULL DEFAULT 'Actif',
  `signature_image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `utilisateurs`
--

INSERT INTO `utilisateurs` (`idUser`, `nom`, `prenom`, `email`, `motDePasse`, `role`, `statut`, `signature_image`) VALUES
(198, 'SOULEMANE', 'Nouroudine', 'soulemanenouroudine41@gmail.com', '$2y$10$RLg6IuWrlc/5BH1UkjAv.ujszHS38Pnz/uBwHT0RKWySzYeybCQ/u', 'Propriétaire', 'Actif', NULL),
(199, 'OROUFEROU', 'Abdoul', 'Orouferou@gmail.com', '$2y$10$SIo2hwa0HjsjFLC.g3SHfebvQB3M3KcnXfSeibWkwYvBVR.j2XDKi', 'Locataire', 'Actif', NULL),
(200, 'SOULEMANE', 'Nouroudine', 'soulemanenouroudine5@gmail.com', '$2y$10$eShxp8UL9umJkpmwoKATp.gWx7Q63DWr9UiXlluZ3DiXamxOwIk0a', 'Administrateur', 'Actif', NULL),
(201, 'DANSOU', 'Junior', 'dansou@gmail.com', '$2y$10$c5q9AN/3f56e1EPxeTOs/.IuLAZPsGOPQiBlOl2aClKxfDeZ.6ycG', 'Propriétaire', 'Actif', NULL),
(202, 'notaire', 'notaire', 'notaire@gmail.com', '$2y$10$tcdUJsOb59CU1paw9c9mg.wYWB1.qV9cHlPiVa36TnwxFlbSO2Ab.', 'Notaire', 'Actif', NULL),
(203, 'notaire1', 'notaire1', 'notaire1@gmail.com', '$2y$10$AtGS.r4.kiR0Ecr6iF3E.ucCL8k18ER.CClAeFk/0o4h0cJND7DhC', 'Notaire', 'Suspendu', NULL),
(204, 'OROUFEROU', 'Abdoul1', 'Orouferou1@gmail.com', '$2y$10$RYXWtlk.LPpsNdAeunLvK.pE1PdZaln7ixUa5xqsvwFIHueoRupnS', 'Locataire', 'Actif', NULL),
(205, 'notaire', 'notaire2', 'notaire2@gmail.com', '$2y$10$vdUGdI7KULOTnDxOrmJIXOjYBaw0o5I68UD/fGK6hZy4i7rkO2Lue', 'Notaire', 'Actif', NULL),
(206, 'proprio', 'propio', 'proprio@gmail.com', '$2y$10$SZv7l2L4vOMCl9VeXdFLa..Mo2w9ADS38Zp/OQOiNMscxQlzTw1tC', 'Propriétaire', 'Actif', 'signature_propritaire_1745943215_182c32fd.jpeg'),
(207, 'locataire', 'locataire', 'loca@gmail.com', '$2y$10$O7dzHynGbSLNN7YUPRA.LOx9tqCN9WLwEB1nX5pCzbplN0gA5oNIK', 'Locataire', 'Actif', 'signature_locataire_1745947423_d3fe9610.jpeg'),
(208, 'notariat', 'notairiat', 'notariat@gmail.com', '$2y$10$GMxuVu42g5q3vyzs/pmtl.dPNMSb5rOKr0JD5YsydC1f8NBm4/hmG', 'Notaire', 'Actif', 'signature_notaire_1745947483_34da682b.jpeg');

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `administrateur`
--
ALTER TABLE `administrateur`
  ADD PRIMARY KEY (`idAdmin`);

--
-- Index pour la table `bienimmobiliers`
--
ALTER TABLE `bienimmobiliers`
  ADD PRIMARY KEY (`idBien`),
  ADD KEY `idProprietaire` (`idProprietaire`);

--
-- Index pour la table `cabinet`
--
ALTER TABLE `cabinet`
  ADD PRIMARY KEY (`idCabinet`),
  ADD KEY `idNotaire` (`idNotaire`);

--
-- Index pour la table `contrat`
--
ALTER TABLE `contrat`
  ADD PRIMARY KEY (`idContrat`),
  ADD KEY `idBien` (`idBien`),
  ADD KEY `idLocataire` (`idLocataire`),
  ADD KEY `fk_contrat_proprietaire` (`idProprietaire`),
  ADD KEY `fk_contrat_notaire` (`idNotaire`),
  ADD KEY `idx_token_locataire` (`tokenSignatureLocataire`),
  ADD KEY `idx_token_proprietaire` (`tokenSignatureProprietaire`);

--
-- Index pour la table `images_description_bien`
--
ALTER TABLE `images_description_bien`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_images_bien` (`id_bien`);

--
-- Index pour la table `locataire`
--
ALTER TABLE `locataire`
  ADD PRIMARY KEY (`idLocataire`),
  ADD UNIQUE KEY `idUser` (`idUser`);

--
-- Index pour la table `notaire`
--
ALTER TABLE `notaire`
  ADD PRIMARY KEY (`idNotaire`),
  ADD UNIQUE KEY `idUser` (`idUser`);

--
-- Index pour la table `notification`
--
ALTER TABLE `notification`
  ADD PRIMARY KEY (`idNotif`),
  ADD KEY `idUser` (`idUser`);

--
-- Index pour la table `paiementloyer`
--
ALTER TABLE `paiementloyer`
  ADD PRIMARY KEY (`idPaiement`),
  ADD KEY `idContrat` (`idContrat`);

--
-- Index pour la table `proprietaire`
--
ALTER TABLE `proprietaire`
  ADD PRIMARY KEY (`idProprietaire`),
  ADD UNIQUE KEY `idUser` (`idUser`);

--
-- Index pour la table `quittance`
--
ALTER TABLE `quittance`
  ADD PRIMARY KEY (`idQuittance`),
  ADD KEY `idContrat` (`idContrat`),
  ADD KEY `idPaiement` (`idPaiement`);

--
-- Index pour la table `signatures`
--
ALTER TABLE `signatures`
  ADD PRIMARY KEY (`idSignature`),
  ADD UNIQUE KEY `uq_contrat_user_role` (`idContrat`,`idUserSignataire`,`roleSignataire`),
  ADD KEY `idx_signature_contrat` (`idContrat`),
  ADD KEY `idx_signature_user` (`idUserSignataire`);

--
-- Index pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  ADD PRIMARY KEY (`idUser`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `bienimmobiliers`
--
ALTER TABLE `bienimmobiliers`
  MODIFY `idBien` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=81;

--
-- AUTO_INCREMENT pour la table `cabinet`
--
ALTER TABLE `cabinet`
  MODIFY `idCabinet` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `contrat`
--
ALTER TABLE `contrat`
  MODIFY `idContrat` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT pour la table `images_description_bien`
--
ALTER TABLE `images_description_bien`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT pour la table `locataire`
--
ALTER TABLE `locataire`
  MODIFY `idLocataire` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT pour la table `notaire`
--
ALTER TABLE `notaire`
  MODIFY `idNotaire` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT pour la table `notification`
--
ALTER TABLE `notification`
  MODIFY `idNotif` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT pour la table `paiementloyer`
--
ALTER TABLE `paiementloyer`
  MODIFY `idPaiement` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `proprietaire`
--
ALTER TABLE `proprietaire`
  MODIFY `idProprietaire` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT pour la table `quittance`
--
ALTER TABLE `quittance`
  MODIFY `idQuittance` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `signatures`
--
ALTER TABLE `signatures`
  MODIFY `idSignature` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  MODIFY `idUser` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=209;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `administrateur`
--
ALTER TABLE `administrateur`
  ADD CONSTRAINT `administrateur_ibfk_1` FOREIGN KEY (`idAdmin`) REFERENCES `utilisateurs` (`idUser`) ON DELETE CASCADE;

--
-- Contraintes pour la table `bienimmobiliers`
--
ALTER TABLE `bienimmobiliers`
  ADD CONSTRAINT `bienimmobiliers_ibfk_1` FOREIGN KEY (`idProprietaire`) REFERENCES `proprietaire` (`idProprietaire`) ON DELETE CASCADE;

--
-- Contraintes pour la table `cabinet`
--
ALTER TABLE `cabinet`
  ADD CONSTRAINT `cabinet_ibfk_1` FOREIGN KEY (`idNotaire`) REFERENCES `notaire` (`idNotaire`) ON DELETE SET NULL;

--
-- Contraintes pour la table `contrat`
--
ALTER TABLE `contrat`
  ADD CONSTRAINT `contrat_ibfk_1` FOREIGN KEY (`idBien`) REFERENCES `bienimmobiliers` (`idBien`) ON DELETE CASCADE,
  ADD CONSTRAINT `contrat_ibfk_2` FOREIGN KEY (`idLocataire`) REFERENCES `locataire` (`idLocataire`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_contrat_notaire` FOREIGN KEY (`idNotaire`) REFERENCES `notaire` (`idNotaire`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_contrat_proprietaire` FOREIGN KEY (`idProprietaire`) REFERENCES `proprietaire` (`idProprietaire`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Contraintes pour la table `images_description_bien`
--
ALTER TABLE `images_description_bien`
  ADD CONSTRAINT `fk_images_bien` FOREIGN KEY (`id_bien`) REFERENCES `bienimmobiliers` (`idBien`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `locataire`
--
ALTER TABLE `locataire`
  ADD CONSTRAINT `locataire_ibfk_1` FOREIGN KEY (`idUser`) REFERENCES `utilisateurs` (`idUser`) ON DELETE CASCADE;

--
-- Contraintes pour la table `notaire`
--
ALTER TABLE `notaire`
  ADD CONSTRAINT `notaire_ibfk_1` FOREIGN KEY (`idUser`) REFERENCES `utilisateurs` (`idUser`) ON DELETE CASCADE;

--
-- Contraintes pour la table `notification`
--
ALTER TABLE `notification`
  ADD CONSTRAINT `notification_ibfk_1` FOREIGN KEY (`idUser`) REFERENCES `utilisateurs` (`idUser`) ON DELETE CASCADE;

--
-- Contraintes pour la table `paiementloyer`
--
ALTER TABLE `paiementloyer`
  ADD CONSTRAINT `paiementloyer_ibfk_1` FOREIGN KEY (`idContrat`) REFERENCES `contrat` (`idContrat`) ON DELETE CASCADE;

--
-- Contraintes pour la table `proprietaire`
--
ALTER TABLE `proprietaire`
  ADD CONSTRAINT `proprietaire_ibfk_1` FOREIGN KEY (`idUser`) REFERENCES `utilisateurs` (`idUser`) ON DELETE CASCADE;

--
-- Contraintes pour la table `quittance`
--
ALTER TABLE `quittance`
  ADD CONSTRAINT `quittance_ibfk_1` FOREIGN KEY (`idContrat`) REFERENCES `contrat` (`idContrat`) ON DELETE CASCADE,
  ADD CONSTRAINT `quittance_ibfk_2` FOREIGN KEY (`idPaiement`) REFERENCES `paiementloyer` (`idPaiement`) ON DELETE CASCADE;

--
-- Contraintes pour la table `signatures`
--
ALTER TABLE `signatures`
  ADD CONSTRAINT `fk_signature_contrat` FOREIGN KEY (`idContrat`) REFERENCES `contrat` (`idContrat`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_signature_user` FOREIGN KEY (`idUserSignataire`) REFERENCES `utilisateurs` (`idUser`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
