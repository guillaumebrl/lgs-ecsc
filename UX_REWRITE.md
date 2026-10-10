# Réécriture UX — branche `codex/ux-rewrite`

Cette branche fait évoluer l’interface sans modifier le modèle de données existant. La branche `main` reste la référence de la version précédente.

## Changements livrés

- changement de fonction accessible depuis chaque écran, avec retour à la page courante ;
- navigation active cohérente pour les sous-écrans ;
- recherche globale des élèves, évaluations et familles, limitée par les droits ;
- tableau de bord orienté actions selon les responsabilités ;
- filtres d’évaluations et préremplissage du contexte de création ;
- fiche élève transversale et en lecture seule pour les enseignants autorisés ;
- accès direct à la fiche depuis « Mes élèves », la vie scolaire et les documents ;
- saisie rapide d’une absence ou d’un retard de cinq minutes ;
- filtres de suivi des absences et retards ;
- progression et compteurs de caractères pour les appréciations ;
- avertissement avant de quitter une saisie non enregistrée ;
- navigation au clavier dans la grille de notes ;
- navigation entre évaluations ;
- progression, élève précédent/suivant et validation-enchaînement en conseil ;
- filtre des bulletins par état ;
- sélection multiple pour valider ou rouvrir plusieurs bulletins ;
- filtres de qualité des dossiers élèves ;
- contrôles d’accès rendus côté serveur à la place de masquages JavaScript ;
- amélioration des cibles tactiles, du focus clavier et de la réduction des animations.

## Recette fonctionnelle recommandée

Tester au minimum un compte de chaque profil : administrateur, enseignant, professeur principal et direction.

1. Changer de fonction depuis la barre supérieure et vérifier le retour sur la page courante.
2. Rechercher un élève et vérifier que seuls les dossiers autorisés apparaissent.
3. Créer une évaluation, saisir les notes au clavier, enregistrer puis naviguer vers l’évaluation suivante.
4. Modifier une appréciation, vérifier le compteur et l’avertissement de sortie, puis enregistrer.
5. Ouvrir un élève depuis « Mes élèves » et contrôler ses moyennes, événements et contacts visibles.
6. Enregistrer une absence et un retard rapide, puis filtrer les événements à justifier.
7. Parcourir un conseil avec les boutons précédent/suivant et « Valider et ouvrir le suivant ».
8. Filtrer les bulletins à valider et ouvrir la fiche élève depuis la liste.
9. Vérifier les écrans à 360 px, 768 px et sur ordinateur, ainsi que la navigation complète au clavier.

## Contrôles automatisés disponibles

Le projet ne contient pas encore de suite de tests métier. Les contrôles réalisés sur cette branche couvrent :

- la syntaxe de tous les fichiers PHP avec `php -l` ;
- la syntaxe JavaScript avec `node --check` ;
- les erreurs d’espacement Git avec `git diff --check` ;
- le démarrage du serveur PHP et le rendu HTTP de la page de connexion.

Une recette avec une copie anonymisée de la base reste nécessaire avant fusion dans `main`.
