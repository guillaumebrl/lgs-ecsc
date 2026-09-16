# Déployer École Pilot depuis Git sur Hostinger

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
APP_NAME="École Pilot"
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
