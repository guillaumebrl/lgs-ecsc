# Architecture de lgs-ecsc

## Point d’entrée

`public/index.php` est l’unique contrôleur frontal de l’application. Il :

1. charge le socle technique ;
2. contrôle l’authentification et le changement obligatoire de mot de passe ;
3. charge les modules ;
4. transmet les requêtes POST aux gestionnaires d’actions ;
5. sélectionne la fonction de rendu correspondant à la route demandée.

## Socle partagé

- `app/bootstrap.php` : environnement, connexion PDO, session, CSRF et fonctions communes ;
- `app/query.php` : exécution des requêtes préparées retournant une ou plusieurs lignes ;
- `app/authentication.php` : connexion et déconnexion ;
- `app/authorization.php` : contrôle des niveaux, enseignements et conseils accessibles ;
- `app/layout.php` : navigation, en-tête et pied de page ;
- `app/application_actions.php` : actions transversales encore partagées par plusieurs modules.

## Modules fonctionnels

Chaque fichier fonctionnel placé dans `app/` expose généralement :

- une fonction `handle_*_actions()` pour les modifications POST ;
- une ou plusieurs fonctions `render_*_page()` pour l’affichage.

Les contrôles d’accès sont effectués côté serveur dans les fonctions de rendu et les gestionnaires d’actions. Masquer un onglet dans l’interface ne constitue jamais le seul contrôle d’autorisation.

## Base de données et sécurité

- Les entrées utilisateur sont envoyées à MySQL par requêtes préparées PDO.
- Les formulaires POST utilisent un jeton CSRF.
- Les mots de passe sont enregistrés avec `password_hash()` et vérifiés avec `password_verify()`.
- Les sorties HTML dynamiques doivent passer par `e()`.
- Toute nouvelle action sensible doit vérifier le rôle, le mode de travail ou le périmètre pédagogique concerné.

## Formatage

La configuration `.php-cs-fixer.dist.php` applique PSR-12 au code PHP de `app/` et `public/`. Le formatage ne doit pas déplacer ou restructurer automatiquement les blocs HTML lorsque cela risque d’altérer les documents imprimés.
