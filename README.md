# École Pilot

Application web légère de gestion scolaire compatible avec un hébergement PHP/MySQL Hostinger.

## Fonctions disponibles

- Comptes administrateur, professeur principal/instituteur et enseignant.
- Années scolaires et périodes libres (trimestres, semestres ou autres).
- Classes, matières, élèves et inscriptions.
- Dossiers familiaux, deux responsables légaux, coordonnées et situation familiale.
- Formule d’adressage configurable sur les bulletins, avec gestion des noms différents.
- Plusieurs enseignants par cours avec rôles distincts.
- Qualifications cumulables : enseignant et professeur principal/instituteur.
- Accès aux notes limité aux matières et groupes explicitement affectés.
- Groupes d'élèves configurables et reliés aux cours.
- Modification administrative des comptes, matières et coefficients.
- Évaluations titrées, coefficients et barèmes libres.
- Normalisation sur 10 ou 20, ou notation sur 20 imposée par l'administration.
- Statuts absent, dispensé, non rendu et non noté.
- Grille de saisie rapide des notes et calcul des moyennes.
- Conseil de classe en plein écran avec mode confidentiel.
- Appréciation générale, mention, décision, validation et verrouillage.
- Bulletin scolaire et relevé de notes imprimables ou enregistrables en PDF.
- Journal d'audit des opérations sensibles.

## Prérequis

- PHP 8.2 ou plus récent avec PDO MySQL.
- MySQL 8 ou MariaDB 10.5 ou plus récent.
- Apache avec `mod_rewrite` (facultatif dans cette version, les URL utilisent aussi `index.php?page=...`).
- HTTPS en production.

L'application n'a aucune dépendance Composer ou Node.js en production.

## Installation locale

1. Créer une base MySQL vide.
2. Copier `.env.example` vers `.env` et renseigner la connexion.
3. Lancer `php -S 127.0.0.1:8080 -t public` depuis ce dossier.
4. Ouvrir `http://127.0.0.1:8080/install.php`.
5. Créer le premier compte administrateur.
6. Renommer ou supprimer `public/install.php` après l'installation.

## Déploiement Git sur Hostinger

Le guide détaillé se trouve dans [`DEPLOIEMENT_HOSTINGER.md`](DEPLOIEMENT_HOSTINGER.md).

## Important avant une utilisation réelle

Cette livraison est un socle MVP. Avant de l'utiliser avec de vraies données scolaires, prévoir une recette métier, une revue de sécurité, des sauvegardes testées, la conformité réglementaire du pays, ainsi qu'un véritable moteur PDF côté serveur si des archives PDF binaires immuables sont exigées. La version actuelle produit une mise en page A4 que le navigateur enregistre en PDF.
