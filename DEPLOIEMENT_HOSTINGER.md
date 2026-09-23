# Déployer lgs-ecsc depuis Git sur Hostinger

## 1. Choisir l'offre

Cette application utilise PHP et MySQL et ne requiert pas de serveur Node.js permanent. Une offre Hostinger permettant un site PHP personnalisé, une base MySQL, HTTPS et idéalement Git/SSH convient. Vérifiez les quotas et fonctions exactes de votre abonnement dans hPanel.

## 2. Préparer le dépôt Git

Depuis la racine du dépôt local :

```bash
git add school-app
git commit -m "Ajoute l'application de gestion scolaire"
git branch -M main
git remote add origin URL_DE_VOTRE_DEPOT
git push -u origin main
```

Ne commitez jamais le fichier `school-app/.env`. Il contient les mots de passe et secrets de production.

## 3. Créer le site et la base dans hPanel

1. Dans **Sites web**, ajoutez un site personnalisé PHP/HTML et associez le domaine.
2. Activez le certificat SSL et forcez HTTPS.
3. Dans **Bases de données → Gestion MySQL**, créez une base et un utilisateur.
4. Conservez le nom de la base, l'utilisateur, le mot de passe et l'hôte MySQL affichés par hPanel.

## 4. Déployer le dépôt

### Méthode A — Intégration Git de hPanel

1. Ouvrez **Sites web → Gérer → Git**.
2. Ajoutez le dépôt et la branche `main`.
3. Pour un dépôt privé, ajoutez à GitHub/GitLab la clé de déploiement fournie par Hostinger.
4. Choisissez `public_html` comme dossier racine de déploiement. Le fichier `.htaccess` placé à la racine protège les fichiers internes et redirige les requêtes vers `public`.
5. Lancez le déploiement.
6. Vérifiez après déploiement que le domaine ouvre l'application et qu'une URL telle que `/database/schema.sql` renvoie bien une interdiction.

### Méthode B — SSH et clone Git

Dans le terminal Hostinger, adaptez les chemins donnés par hPanel :

```bash
cd /home/VOTRE_COMPTE/domains/VOTRE_DOMAINE
git clone URL_DE_VOTRE_DEPOT school-app-source
```

Configurez ensuite le document root sur `school-app-source/school-app/public`, ou copiez/déployez ce sous-projet dans la structure attendue par le domaine. Les chemins exacts varient selon l'offre et le domaine ; utilisez ceux affichés dans le gestionnaire de fichiers Hostinger.

## 5. Créer le fichier `.env`

Créez `school-app/.env` à partir de `.env.example`, sans le placer dans `public` :

```dotenv
APP_NAME="lgs-ecsc"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ecole.votre-domaine.fr
APP_KEY=UNE_LONGUE_VALEUR_ALEATOIRE_UNIQUE
DB_HOST=HOTE_MYSQL_HOSTINGER
DB_PORT=3306
DB_DATABASE=NOM_BASE_HOSTINGER
DB_USERNAME=UTILISATEUR_HOSTINGER
DB_PASSWORD=MOT_DE_PASSE_FORT
```

Générez `APP_KEY` avec un gestionnaire de mots de passe ou une source aléatoire sûre. Même si cette première version ne l'utilise pas encore pour chiffrer des données, elle est réservée aux évolutions futures.

## 6. Vérifier les permissions

- Le serveur web doit pouvoir lire `app`, `database` et `.env`.
- Le dossier `storage` doit être inscriptible par PHP.
- `.env` ne doit jamais être accessible depuis le Web.
- Le document root doit impérativement être le dossier `public`.

En général, utilisez des permissions restrictives : dossiers `755`, fichiers `644` et `.env` `600` ou `640` si l'environnement le permet. N'utilisez pas `777`.

## 7. Initialiser l'application

1. Ouvrez `https://votre-domaine.fr/install.php`.
2. Créez le premier administrateur avec un mot de passe d'au moins 12 caractères.
3. Vérifiez la connexion.
4. Renommez ou supprimez immédiatement `public/install.php` depuis le gestionnaire de fichiers ou Git.
5. Dans l'application, créez l'année, les périodes, les comptes, les classes et les matières.

## 8. Déploiements suivants

Avec hPanel Git, cliquez sur **Déployer** après chaque push. Avec SSH :

```bash
cd /home/VOTRE_COMPTE/domains/VOTRE_DOMAINE/school-app-source
git pull --ff-only origin main
```

Sauvegardez toujours la base et les documents avant une mise à jour. Les évolutions de schéma devront être fournies sous forme de migrations versionnées ; ne réexécutez pas aveuglément des scripts destructifs.

### Mise à jour 002 — Familles et responsables légaux

Après avoir sauvegardé la base, ouvrez **phpMyAdmin** dans hPanel, sélectionnez la base de l'application, puis utilisez l'onglet **Importer** pour exécuter une seule fois :

`database/migrations/002_families.sql`

Cette migration ajoute les familles, les responsables légaux et le lien entre une famille et ses enfants. Ne la relancez pas après son exécution réussie.

### Mise à jour 003 — Qualifications, droits et groupes

Avant de téléverser le code de cette version, sauvegardez la base puis importez une seule fois dans phpMyAdmin :

`database/migrations/003_permissions_groups_admin_edit.sql`

Cette migration ajoute les qualifications cumulables des personnels, les groupes d'élèves et l'affectation d'un groupe à un cours. Après le déploiement, utilisez **Modifier → Comptes** pour vérifier les qualifications, puis **Modifier → Groupes** pour constituer les groupes et les associer aux cours.

### Mise à jour 004 — Profil Direction

Avant de téléverser le code de cette version, sauvegardez la base puis importez une seule fois dans phpMyAdmin :

`database/migrations/004_direction_role.sql`

Le profil Direction peut consulter toutes les classes, valider les bulletins individuellement ou en lot, et imprimer ou enregistrer un PDF par élève ou par classe. Il n'accède pas aux réglages administratifs, aux comptes ni à la structure.

### Mise à jour 005 — Nom et prénom des comptes

Avant de téléverser le code de cette version, sauvegardez la base puis importez une seule fois dans phpMyAdmin :

`database/migrations/005_user_first_last_name.sql`

Cette migration sépare le nom et le prénom des comptes. Pour éviter toute perte, l’ancien nom complet est placé dans le champ **Nom** ; après le déploiement, ouvrez **Comptes → Modifier les comptes** pour renseigner correctement le prénom des comptes existants. Ne relancez pas cette migration après son exécution réussie.

### Mise à jour 006 — N° INE facultatif

Avant de téléverser le code de cette version, sauvegardez la base puis importez une seule fois dans phpMyAdmin :

`database/migrations/006_optional_ine.sql`

Cette migration remplace l’ancien matricule par un N° INE facultatif. Elle permet de créer un élève avant l’obtention de son INE, puis de compléter cette information ultérieurement depuis sa fiche. Ne relancez pas cette migration après son exécution réussie.

### Mise à jour 007 — Affectations pédagogiques

Avant de téléverser le code de cette version, sauvegardez la base puis importez une seule fois dans phpMyAdmin :

`database/migrations/007_teaching_assignments.sql`

Cette migration ajoute à chaque affectation un coefficient de matière et un ordre d’affichage sur le bulletin. Les affectations existantes sont conservées et reprennent automatiquement le coefficient par défaut de leur matière. Après le déploiement, utilisez **Structure → Affectations** pour attribuer les classes ou groupes aux enseignants et co-professeurs. Ne relancez pas cette migration après son exécution réussie.

### Mise à jour 008 — Refonte lgs-ecsc

Sauvegardez la base puis importez une seule fois dans phpMyAdmin, avant de téléverser le nouveau code :

`database/migrations/008_lgs_ecsc_core.sql`

Cette migration ajoute les qualifications cumulables Direction et Vie scolaire, l’obligation de changement de mot de passe et l’ordre pédagogique des niveaux. Les comptes et données existants sont conservés. Après le déploiement, reconnectez-vous et vérifiez les qualifications depuis **Comptes**. Ne relancez pas cette migration après son exécution réussie.

### Mise à jour 009 — Adresse e-mail facultative

Sauvegardez la base puis importez une seule fois dans phpMyAdmin, avant de téléverser le nouveau code :

`database/migrations/009_optional_account_email.sql`

Cette migration sépare l’identifiant de connexion de l’adresse e-mail. Pour chaque compte existant, l’adresse actuelle devient automatiquement son identifiant de connexion. L’adresse e-mail peut ensuite être supprimée ou laissée vide depuis **Comptes**. Ne relancez pas cette migration après son exécution réussie.

### Mise à jour 010 — Ordre simplifié des niveaux

Après la migration 009, importez une seule fois dans phpMyAdmin :

`database/migrations/010_simple_level_order.sql`

### Mise à jour v40 — cycles scolaires

Après sauvegarde de la base, importez une seule fois :

`database/migrations/011_education_stage.sql`

Cette migration ajoute aux niveaux le cycle Maternelle, Primaire ou Collège et initialise automatiquement les niveaux existants.

Cette migration renumérote les niveaux de chaque année scolaire à partir de 1, selon leur ordre actuel, et remplace l’ancienne valeur technique 100. L’ordre peut ensuite être réglé simplement entre 1 et 12 dans **Structure → Classes**.

### Mise à jour v71 — génération PDF sur le serveur

Cette mise à jour ne nécessite aucune migration SQL. Téléversez et extrayez l’archive complète en remplaçant les fichiers existants. Le dossier `vendor` doit impérativement être copié : il contient le moteur PDF Dompdf déjà installé, il n'est donc pas nécessaire d'exécuter Composer sur Hostinger.

Vérifiez que le site utilise PHP 8.1 ou une version plus récente avec les extensions DOM, Iconv et Mbstring activées. Les boutons **Télécharger le PDF** génèrent désormais directement les bulletins et relevés de notes individuels ou par classe, sans passer par la fenêtre d'impression du navigateur.

## 9. Contrôles après déploiement

- HTTPS est forcé et aucune alerte de certificat n'apparaît.
- `.env`, `database/schema.sql`, `.git` et `storage` ne sont pas accessibles par URL.
- Les rôles ne peuvent pas ouvrir des pages interdites par URL directe.
- Création d'une évaluation et sauvegarde de notes réussies.
- Mode conseil et validation fonctionnels.
- Impression d'un bulletin et d'un relevé au format A4 réussie.
- Sauvegarde Hostinger activée et restauration testée.
- Fuseau horaire PHP configuré sur `Europe/Paris` si nécessaire.

## 10. GitHub Actions (optionnel)

Pour automatiser le déploiement, le plus simple reste l'intégration Git de Hostinger. N'ajoutez pas de mots de passe FTP ou SSH au dépôt : placez-les uniquement dans les secrets du fournisseur Git. Pour une application manipulant des données scolaires, imposez une branche protégée et une validation humaine avant production.

## 11. Sauvegarde nocturne de la base

La solution recommandée est d'activer les sauvegardes automatiques de Hostinger dans **Sites web → Tableau de bord → Fichiers → Sauvegardes**. Pour disposer en plus d'une copie SQL indépendante :

1. Créez avec le gestionnaire de fichiers un dossier hors de `public_html`, par exemple `/home/u123456789/backups/lgs-ecsc`.
2. Ajoutez dans le `.env` de production :

   ```env
   BACKUP_DIR=/home/u123456789/backups/lgs-ecsc
   BACKUP_RETENTION_DAYS=14
   ```

3. Dans **Sites web → Tableau de bord → Avancé → Tâches Cron**, créez une tâche de type **PHP** pointant vers le chemin absolu de `scripts/backup_database.php`.
4. Programmez-la chaque jour à `01:00` UTC, soit `02:00` en France l'hiver et `03:00` l'été. Hostinger planifie les tâches Cron en UTC.
5. Lancez d'abord un test, puis contrôlez **Voir la sortie**. Le message doit indiquer le chemin d'un fichier `lgs-ecsc-AAAA-MM-JJ_HH-MM-SS.sql.gz`.

Le script n'est exécutable qu'en ligne de commande, réutilise les identifiants MySQL du `.env`, refuse d'écrire dans le dossier public et supprime automatiquement les copies plus anciennes que la durée de rétention. Téléchargez régulièrement une copie sur un support extérieur à Hostinger et testez une restauration sur une base de test.
