# Cahier des charges — gestion de l’association dans lgs-ecsc

Version d’analyse : 17 octobre 2026  
Dépôt étudié : `benoitvandame/annuaire-sainte-charlotte`, commit `8b86996`  
Cible : dépôt `guillaumebrl/lgs-ecsc`, branche locale `main`, commit `ce849c5`

## 1. Objet et périmètre

Ce document décrit la transposition dans **lgs-ecsc** des fonctions de gestion associative observées dans le dépôt de référence. Il couvre :

- l’analyse fonctionnelle et technique de l’existant de référence ;
- les écarts avec l’architecture de lgs-ecsc ;
- le modèle de données cible ;
- les fonctionnalités et parcours utilisateurs ;
- les règles métier, notamment le calcul automatique des statuts associatifs ;
- les droits, contrôles, audits, imports et exports ;
- une stratégie de réalisation et des critères d’acceptation.

Le périmètre recommandé pour une première version comprend l’annuaire des adultes, les rôles, les fonctions et mandats, le conseil d’administration, le bureau, l’historique, les filtres et exports. La comptabilité, les paiements de cotisations, les assemblées générales, les votes et la gestion documentaire juridique sont présentés comme extensions ultérieures : ils ne sont pas présents dans le dépôt analysé.

Le dépôt contient également un sous-système substantiel d’**échanges avec les logiciels administratifs** : imports CSV du premier degré, imports XML BEE du collège, imports génériques CSV/XLSX et génération de registres officiels à partir de trames XLSX. Ce domaine fait partie du présent cahier des charges et doit être conçu comme un module sécurisé, versionné et auditable.

## 2. Synthèse exécutive

Le dépôt de référence fournit un bon noyau métier :

- une personne adulte peut recevoir des **rôles explicites** datés ;
- certaines qualités sont **automatiques**, car déduites d’un lien familial, de la scolarité d’un enfant ou d’un mandat ;
- une **fonction** (président, représentant des parents, curé, etc.) est attribuée sur une période ;
- une fonction peut exiger un rôle préalable et conférer automatiquement la qualité de membre du bureau, du conseil d’administration ou de membre de droit ;
- toutes les périodes sont inclusives et peuvent être interrogées à une date de référence ;
- les incohérences sont bloquées à la saisie et peuvent aussi être recherchées par une commande de contrôle globale.

La transposition ne doit pas copier le modèle Laravel à l’identique. lgs-ecsc est une application PHP/PDO sans ORM, possédant déjà :

- `students` pour les élèves ;
- `families` pour les foyers ;
- `guardians` pour les adultes responsables ;
- `users` pour les comptes applicatifs ;
- `audit_logs` pour la traçabilité.

La conception recommandée utilise donc `guardians` comme annuaire associatif des adultes, après enrichissement de cette table. Les rôles et mandats sont ajoutés dans des tables dédiées et temporalisées. Les statuts calculés ne sont pas stockés : ils sont produits à la volée pour une date donnée afin d’éviter les désynchronisations.

## 3. Analyse du dépôt de référence

### 3.1 Architecture technique

Le dépôt de référence est une application Laravel 12 / PHP 8.4 utilisant notamment :

- Eloquent et les migrations pour le modèle relationnel ;
- Filament 4 pour l’administration ;
- Spatie Permission et Filament Shield pour les autorisations ;
- PHPUnit avec une base SQLite de test ;
- des services de cohérence métier et des validateurs appelés au niveau des modèles ;
- des imports XML/CSV, un annuaire filtrable, des exports CSV/XLSX et des impressions.

La logique associative est principalement répartie entre :

- `Person` : identité, relations, calcul des statuts et fonctions actifs ;
- `AssociationRole` et `AssociationRoleAssignment` : rôles et périodes ;
- `AssociationPosition` et `AssociationPositionAssignment` : fonctions/mandats et périodes ;
- `AssociationAssignmentValidation` : invariants métier ;
- `SystemReferenceData` : référentiels canoniques ;
- les gestionnaires Filament liés à la fiche personne ;
- les tests de gestion et de cohérence associative.

### 3.2 Modèle conceptuel observé

Le modèle distingue correctement quatre notions :

1. **Personne** : identité civile et coordonnées.
2. **Lien entre personnes** : père, mère, conjoint, représentant légal ; relation éventuellement datée.
3. **Rôle associatif** : qualité explicite ou automatique, par exemple enseignant, membre actif ou représentant légal.
4. **Fonction/mandat** : responsabilité institutionnelle exercée pendant une période, par exemple président ou représentant des parents.

Cette séparation empêche de confondre :

- le fait d’être adhérent avec le fait d’occuper une fonction ;
- la fonction de président avec la qualité calculée de membre du bureau ;
- la qualité de représentant légal avec un mandat de représentant des parents ;
- l’identité d’une personne avec son compte de connexion.

### 3.3 Référentiel métier observé

#### Rôles

| Code | Libellé | Origine | Donne la qualité d’adhérent |
|---|---|---:|---:|
| `legal_representative` | Représentant légal | automatique | oui |
| `teacher` | Enseignant | explicite | oui |
| `intervenant` | Intervenant | explicite | non |
| `active_member` | Membre actif | explicite | oui |
| `statutory_member` | Membre de droit | automatique | oui |
| `executive_board_member` | Membre du bureau | automatique | non, par lui-même |
| `board_member` | Membre du CA | automatique | non, par lui-même |

Seuls `teacher`, `intervenant` et `active_member` peuvent être attribués manuellement. Les rôles automatiques apparaissent dans le statut calculé mais ne doivent pas être proposés dans les formulaires d’attribution.

#### Fonctions et mandats

| Code | Fonction | Prérequis | Effets automatiques |
|---|---|---|---|
| `president` | Président | membre actif | bureau + CA |
| `vice_president` | Vice-président | membre actif | bureau + CA |
| `finance_director` | Directeur financier | membre actif | bureau + CA |
| `communications_director` | Directeur de la communication | membre actif | bureau + CA |
| `intendant` | Intendant | membre actif | bureau + CA |
| `parent_representative` | Représentant des parents | représentant légal | CA |
| `teacher_representative` | Représentant des enseignants | enseignant | CA |
| `parish_priest` | Curé | aucun | membre de droit + CA |
| `bishop` | Évêque | aucun | membre de droit + CA |
| `parish_priest_representative` | Représentant du curé | aucun | aucun effet automatique configuré |
| `bishop_representative` | Représentant de l’évêque | aucun | aucun effet automatique configuré |

Le dépôt n’implémente pas de délégation automatique entre un titulaire ecclésiastique et son représentant. Si cette règle est souhaitée, elle devra être explicitée avant développement.

### 3.4 Règles de calcul observées

À une date `D`, un rôle explicite ou un mandat est actif lorsque :

```text
date_de_début <= D
ET (date_de_fin est vide OU date_de_fin >= D)
```

Les bornes sont inclusives. Une période d’un seul jour est donc valide.

Le statut associatif d’une personne à `D` est l’union ordonnée et sans doublon de :

1. **Représentant légal**, si la personne représente légalement au moins un enfant scolarisé à `D` ;
2. tous ses rôles explicites actifs à `D` ;
3. **Membre de droit**, si un mandat actif le confère ;
4. **Membre du bureau**, si un mandat actif le confère ;
5. **Membre du CA**, si un mandat actif le confère ;
6. **Adhérent**, si au moins un rôle actif possède `grants_membership = true`.

Le représentant légal n’est donc pas automatiquement membre parce qu’un simple lien existe : il faut également que l’enfant soit effectivement scolarisé à la date demandée. Le calcul recoupe la période du lien, celle de l’année scolaire, celle de l’inscription et celle de l’affectation scolaire.

### 3.5 Invariants observés

- Un enfant ne peut recevoir ni rôle ni mandat associatif.
- Toute attribution possède une date de début obligatoire.
- La fin est facultative et ne peut précéder le début.
- Deux attributions du même rôle à la même personne ne peuvent se chevaucher.
- Une fonction institutionnelle n’a qu’un titulaire à la fois : deux affectations de la même fonction ne peuvent se chevaucher, même pour des personnes différentes.
- Le rôle préalable doit couvrir la totalité du mandat, pas seulement son premier jour.
- La suppression ou le raccourcissement d’un rôle est interdit s’il invalide un mandat dépendant.
- Toute modification familiale ou scolaire qui ferait perdre un prérequis à un mandat valide est interdite.
- Une personne ayant des attributions associatives ne peut être requalifiée en enfant.
- Les fonctions `parish_priest` et `bishop` imposent respectivement les civilités « Abbé » et « Monseigneur » tant que le mandat n’est pas terminé.
- Les dates et contrôles sont appliqués au modèle, pas uniquement à l’interface.
- Un verrou transactionnel sérialise l’attribution d’une même fonction afin d’éviter deux titulaires concurrents.

### 3.6 Fonctionnalités d’interface observées

- Annuaire de personnes avec recherche, tri et filtres.
- Date de référence modifiable et bouton « Aujourd’hui ».
- Filtres multivalués sur les statuts associatifs et les mandats.
- Fiche personne affichant les statuts et mandats actuels.
- Onglets séparés pour les rôles explicites et les fonctions/mandats.
- Création, modification et suppression par fenêtres modales.
- Messages métier explicites en cas d’incohérence.
- Export CSV, export XLSX et impression de l’annuaire filtré.
- Masquage des sections associatives pour les enfants.

### 3.7 Forces du dépôt de référence

- Bonne séparation entre rôle, fonction et statut dérivé.
- Historisation native par périodes.
- Calcul à une date de référence, indispensable pour reconstituer un ancien CA.
- Prévention des incohérences sur les modifications indirectes.
- Référentiels stables identifiés par des codes techniques.
- Couverture de tests riche : chevauchements, prérequis, suppression, concurrence, rôles automatiques, affichage et autorisations.
- Contrôle global en lecture seule permettant de détecter les données anciennes incohérentes.

### 3.8 Limites et points à améliorer

- Les statuts automatiques sont codés dans des booléens sur les fonctions. Le modèle est simple, mais moins extensible qu’une table générique « effets d’un mandat ».
- La règle « une fonction = un seul titulaire » est globale et rigide. Elle ne convient pas à des fonctions naturellement multiples sans modifier le référentiel.
- Les tables d’attribution ne portent pas de décision, d’organe, de mandat électoral, de motif de fin ni de pièce justificative.
- La notion de cotisation et de membre à jour n’existe pas.
- Il n’y a pas de cycle d’assemblée générale, quorum, procuration, vote ou procès-verbal.
- Les fonctions ecclésiastiques utilisent la civilité comme invariant métier, ce qui couple fortement identité et fonction.
- Les contraintes de non-chevauchement sont principalement applicatives ; elles doivent être protégées par transactions et tests de concurrence.
- Le rôle « enseignant » est explicite dans ce dépôt, alors que lgs-ecsc peut le déduire de `course_teachers` et des périodes d’affectation.

## 4. État de lgs-ecsc et stratégie d’intégration

### 4.1 Architecture actuelle utile au projet

lgs-ecsc est une application PHP 8.2/PDO/MySQL légère, sans dépendance de production. Elle utilise :

- un routeur par `index.php?page=...` ;
- des modules PHP fonctionnels dans `app/` ;
- des requêtes préparées PDO ;
- des transactions explicites pour les écritures multiples ;
- un contrôle d’accès par rôle et capacités ;
- une protection CSRF ;
- un journal `audit_logs` ;
- un schéma central `database/schema.sql` et un installateur.

Les données proches du besoin sont :

```text
families 1 ─── N guardians
families 1 ─── N students
students N ─── N classes (via enrollments)
users N ─── N courses (via course_teachers)
```

### 4.2 Décision d’architecture recommandée

La table `guardians` doit devenir l’annuaire canonique des adultes associés aux familles et à l’association. Une personne doit exister une seule fois même si elle :

- est responsable de plusieurs élèves ou familles ;
- est membre actif ;
- occupe plusieurs mandats successifs ;
- possède également un compte `users`.

Pour atteindre ce résultat sans refonte brutale :

1. enrichir `guardians` avec les champs d’identité nécessaires ;
2. rendre `family_id` facultatif à terme ;
3. remplacer progressivement le lien direct `guardians.family_id` par une table de rattachement `family_guardians` ;
4. permettre un lien facultatif `guardians.user_id` vers le compte applicatif ;
5. ajouter les tables de référentiel et d’attribution associatives ;
6. conserver `students` séparé : un élève n’est pas une personne associative éligible.

Cette trajectoire évite une table `people` concurrente, les doublons et une migration risquée de tout le domaine scolaire.

## 5. Modèle de données cible

### 5.1 Vue d’ensemble

```text
users 0..1 ───── 0..1 guardians
families N ───── N guardians       via family_guardians
guardians 1 ──── N association_role_assignments
association_roles 1 ─ N association_role_assignments
guardians 1 ──── N association_position_assignments
association_positions 1 ─ N association_position_assignments
association_roles 0..1 ─ N association_positions (prérequis)
association_positions N ─ N association_roles (effets automatiques, option V2)
```

### 5.2 Évolution de `guardians`

Champs existants conservés : identité, e-mail, téléphone, indicateurs de représentation légale et de destinataire.

Champs recommandés :

| Champ | Type | Règle |
|---|---|---|
| `user_id` | BIGINT NULL, UNIQUE, FK users | compte lié facultatif |
| `birth_name` | VARCHAR(100) NULL | nom de naissance |
| `other_first_names` | VARCHAR(255) NULL | autres prénoms |
| `birth_date` | DATE NULL | date de naissance |
| `birth_place` | VARCHAR(150) NULL | lieu de naissance |
| `address_line1` | VARCHAR(255) NULL | adresse personnelle si différente de la famille |
| `address_line2` | VARCHAR(255) NULL | complément |
| `postal_code` | VARCHAR(20) NULL | code postal |
| `city` | VARCHAR(120) NULL | commune |
| `country` | VARCHAR(100) NULL | défaut France |
| `notes` | TEXT NULL | notes internes, accès restreint |
| `active` | BOOLEAN NOT NULL DEFAULT TRUE | archivage fonctionnel |
| `created_at` | TIMESTAMP | création |
| `updated_at` | TIMESTAMP | modification |

La civilité ne devrait pas être un `ENUM` fermé si l’on veut gérer « Abbé », « Monseigneur » ou d’autres formes. Utiliser `VARCHAR(50)` ou une table de référence.

### 5.3 `family_guardians`

Cette table remplace le rattachement rigide d’un responsable à une seule famille.

| Champ | Type | Règle |
|---|---|---|
| `family_id` | BIGINT FK | famille |
| `guardian_id` | BIGINT FK | adulte |
| `relationship_label` | VARCHAR(80) | parent, tuteur, tiers digne de confiance… |
| `legal_guardian` | BOOLEAN | représentant légal pour ce foyer/enfant |
| `receives_bulletin` | BOOLEAN | destinataire scolaire |
| `display_order` | TINYINT | ordre d’affichage |
| `starts_on` | DATE NULL | début du lien |
| `ends_on` | DATE NULL | fin du lien |

Clé primaire recommandée : `(family_id, guardian_id, starts_on)` ou identifiant technique avec index de recherche. La migration initiale crée une ligne par enregistrement `guardians` existant.

### 5.4 `association_roles`

| Champ | Type | Règle |
|---|---|---|
| `id` | BIGINT PK | identifiant |
| `code` | VARCHAR(50) UNIQUE | identifiant stable non modifiable |
| `name` | VARCHAR(100) | libellé utilisateur |
| `assignment_mode` | ENUM(`manual`,`derived`,`both`) | mode d’obtention |
| `grants_membership` | BOOLEAN | confère la qualité d’adhérent |
| `sort_order` | SMALLINT | ordre d’affichage |
| `active` | BOOLEAN | référentiel actif |
| `created_at`, `updated_at` | TIMESTAMP | traçabilité |

`assignment_mode` est préférable au seul booléen `is_automatic`, car un rôle peut un jour être obtenu automatiquement ou sur justification manuelle.

### 5.5 `association_role_assignments`

| Champ | Type | Règle |
|---|---|---|
| `id` | BIGINT PK | identifiant |
| `guardian_id` | BIGINT FK guardians | bénéficiaire adulte |
| `association_role_id` | BIGINT FK | rôle |
| `starts_on` | DATE NOT NULL | début inclusif |
| `ends_on` | DATE NULL | fin inclusive |
| `source` | ENUM(`manual`,`import`,`migration`) | origine ; jamais `derived` |
| `decision_reference` | VARCHAR(150) NULL | AG, CA, convention… |
| `notes` | TEXT NULL | commentaire interne |
| `created_by`, `updated_by` | BIGINT FK users | auteur |
| `created_at`, `updated_at` | TIMESTAMP | traçabilité |

Index : `(guardian_id, association_role_id, starts_on, ends_on)` et `(association_role_id, starts_on, ends_on)`.

### 5.6 `association_positions`

| Champ | Type | Règle |
|---|---|---|
| `id` | BIGINT PK | identifiant |
| `code` | VARCHAR(60) UNIQUE | code stable |
| `name` | VARCHAR(150) | libellé |
| `required_role_id` | BIGINT NULL FK roles | prérequis |
| `cardinality` | ENUM(`single`,`multiple`) | nombre de titulaires simultanés |
| `grants_statutory_member` | BOOLEAN | effet |
| `grants_executive_board_member` | BOOLEAN | effet |
| `grants_board_member` | BOOLEAN | effet |
| `required_title` | VARCHAR(50) NULL | règle paramétrable, si conservée |
| `sort_order` | SMALLINT | ordre |
| `active` | BOOLEAN | référentiel actif |
| `created_at`, `updated_at` | TIMESTAMP | traçabilité |

L’ajout de `cardinality` corrige la rigidité du dépôt source. Par défaut, président, vice-président, trésorier/directeur financier et secrétaire sont `single`; les représentants peuvent être `multiple` selon les statuts.

### 5.7 `association_position_assignments`

| Champ | Type | Règle |
|---|---|---|
| `id` | BIGINT PK | identifiant |
| `guardian_id` | BIGINT FK guardians | titulaire |
| `association_position_id` | BIGINT FK | fonction |
| `starts_on` | DATE NOT NULL | début inclusif |
| `ends_on` | DATE NULL | fin inclusive |
| `elected_on` | DATE NULL | date de décision |
| `decision_reference` | VARCHAR(150) NULL | AG/CA/PV |
| `end_reason` | VARCHAR(150) NULL | échéance, démission, révocation… |
| `notes` | TEXT NULL | commentaire |
| `created_by`, `updated_by` | BIGINT FK users | auteur |
| `created_at`, `updated_at` | TIMESTAMP | traçabilité |

Index : `(association_position_id, starts_on, ends_on)` et `(guardian_id, starts_on, ends_on)`.

### 5.8 Option d’extensibilité : effets de mandat

Pour une V1 fidèle au dépôt, les trois booléens d’effet suffisent. Pour une solution générique, créer plutôt :

```text
association_position_granted_roles
- association_position_id
- association_role_id
- PRIMARY KEY(position, role)
```

Cette table permet à une fonction de conférer n’importe quel rôle automatique sans migration de schéma. Elle est recommandée si le référentiel doit évoluer fréquemment.

### 5.9 Ne pas stocker les statuts calculés

Les qualités « représentant légal », « membre du bureau », « membre du CA », « membre de droit » et « adhérent » doivent être calculées à la date de référence. Les stocker dans une table provoquerait des écarts lors :

- d’une modification rétroactive de période ;
- du passage automatique à une nouvelle journée ;
- d’une désinscription scolaire ;
- d’une correction de lien familial ;
- d’une fin ou suppression de mandat.

Une vue SQL ou un service PHP peut fournir le calcul. Si les volumes deviennent élevés, une vue matérialisée/cache daté peut être ajoutée, avec invalidation explicite.

## 6. Règles métier cibles

### 6.1 Règles communes de période

**RM-PER-01** — La date de début est obligatoire pour toute attribution explicite ou tout mandat.  
**RM-PER-02** — La date de fin est facultative et inclusive.  
**RM-PER-03** — `ends_on >= starts_on`.  
**RM-PER-04** — Deux périodes `[A, B]` et `[C, D]` se chevauchent lorsque `(B est NULL ou B >= C) ET (D est NULL ou D >= A)`.  
**RM-PER-05** — Une période sans fin est ouverte vers le futur.  
**RM-PER-06** — Tous les calculs reçoivent une date de référence explicite ; l’interface utilise aujourd’hui par défaut.

### 6.2 Éligibilité

**RM-ELI-01** — Seul un adulte de l’annuaire `guardians` peut recevoir un rôle ou mandat.  
**RM-ELI-02** — Un compte utilisateur n’est pas requis pour être membre de l’association.  
**RM-ELI-03** — Un responsable archivé conserve son historique, mais ne peut recevoir une nouvelle attribution.  
**RM-ELI-04** — Les rôles `derived` ne sont jamais proposés à l’attribution manuelle.

### 6.3 Rôles explicites

**RM-ROL-01** — Une personne ne peut avoir deux attributions chevauchantes du même rôle.  
**RM-ROL-02** — Des rôles différents peuvent se chevaucher.  
**RM-ROL-03** — La suppression/réduction d’un rôle est refusée si elle découvre une partie d’un mandat qui l’exige.  
**RM-ROL-04** — Le rôle enseignant devrait être dérivé des affectations `course_teachers` lorsque celles-ci disposent d’une période exploitable ; à défaut, il reste manuel en V1.  
**RM-ROL-05** — Un intervenant n’est pas adhérent sauf s’il possède parallèlement un rôle conférant l’adhésion.

### 6.4 Mandats

**RM-MAN-01** — Le prérequis doit être satisfait chaque jour de la période du mandat.  
**RM-MAN-02** — Pour une fonction `single`, aucune autre attribution de cette fonction ne peut chevaucher la période, quelle que soit la personne.  
**RM-MAN-03** — Pour une fonction `multiple`, le chevauchement est autorisé entre personnes, mais pas deux fois pour la même personne et la même fonction.  
**RM-MAN-04** — Une fonction inactive ne peut être nouvellement attribuée, mais son historique reste visible.  
**RM-MAN-05** — La modification d’un référentiel de fonction doit revalider tous les mandats existants concernés.  
**RM-MAN-06** — Les écritures d’une fonction `single` utilisent transaction et verrou de ligne avant le test de chevauchement.  
**RM-MAN-07** — Une fin de mandat passée ne modifie plus la civilité actuelle ; l’historique reste inchangé.

### 6.5 Statuts automatiques

#### Représentant légal

**RM-AUT-01** — Une personne est « Représentant légal » à `D` lorsqu’elle est rattachée comme représentant légal à une famille contenant au moins un élève dont l’inscription scolaire est active à `D`.

Dans le modèle actuel de lgs-ecsc, `enrollments` ne porte pas de dates mais référence une classe, elle-même rattachée à une année scolaire. La règle initiale devient :

```text
family_guardians.legal_guardian = 1
ET lien familial actif à D
ET il existe un student de la famille, actif
ET il existe une enrollment vers une classe dont l’année scolaire couvre D
```

Si `students.family_id` reste le seul rattachement, cette règle s’applique à tous les responsables légaux de la famille. Une future table adulte-enfant serait plus précise pour les familles recomposées.

#### Membre de droit, bureau et CA

**RM-AUT-02** — « Membre de droit » si au moins un mandat actif à `D` possède l’effet correspondant.  
**RM-AUT-03** — « Membre du bureau » si au moins un mandat actif à `D` possède l’effet correspondant.  
**RM-AUT-04** — « Membre du CA » si au moins un mandat actif à `D` possède l’effet correspondant.  
**RM-AUT-05** — La disparition d’un effet ne supprime aucun historique : le statut est simplement absent après la date de fin.

#### Adhérent

**RM-AUT-06** — « Adhérent » si au moins un rôle effectif à `D` possède `grants_membership = 1`.  
**RM-AUT-07** — Un rôle effectif peut être explicite ou dérivé.  
**RM-AUT-08** — Les statuts sont dédupliqués et triés selon `sort_order`.  
**RM-AUT-09** — Un mandat conférant « membre du bureau » n’accorde pas implicitement l’adhésion sauf si son prérequis ou un autre effet la confère.

### 6.6 Modifications indirectes

Avant toute opération modifiant famille, responsable, élève, inscription, année scolaire ou affectation enseignante :

1. identifier les personnes dont les rôles dérivés pourraient changer ;
2. lister leurs mandats actuellement cohérents dépendant de ces rôles ;
3. effectuer la modification dans une transaction ;
4. revalider ces mandats ;
5. annuler la transaction si un mandat valide devient invalide.

Comme dans le dépôt de référence, une incohérence historique préexistante ne doit pas empêcher une correction partielle qui ne l’aggrave pas. Elle doit néanmoins rester signalée par le contrôle global.

### 6.7 Suppression et archivage

**RM-SUP-01** — Les adultes, rôles et fonctions utilisés historiquement ne sont pas supprimés physiquement ; ils sont archivés.  
**RM-SUP-02** — Une attribution peut être supprimée uniquement si elle résulte d’une erreur et si aucun invariant dépendant n’est cassé. Sinon, renseigner une date de fin.  
**RM-SUP-03** — Toute suppression, restauration ou fin anticipée est auditée avec état avant/après.  
**RM-SUP-04** — Les clés étrangères historiques utilisent `RESTRICT`, pas `CASCADE`.

## 7. Fonctionnalités attendues

### 7.1 Annuaire associatif

- Liste paginée des adultes avec nom, prénom, coordonnées, famille(s), statuts et mandats actifs.
- Recherche sur nom, nom de naissance, prénom, e-mail et téléphone.
- Tri par nom, statut et fonction.
- Date de référence globale, initialisée à aujourd’hui.
- Filtres multivalués : statut, rôle, mandat, famille, actif/archivé.
- Filtres rapides : adhérents, CA, bureau, représentants légaux, enseignants, membres sans mandat.
- Accès à la fiche détaillée.
- Exports CSV et impression respectant exactement les filtres et la date de référence.

### 7.2 Fiche adulte

- Identité et coordonnées.
- Compte applicatif lié, le cas échéant, sans exposer de secret.
- Familles et élèves représentés.
- Badges de statuts calculés à la date choisie, avec explication de la source.
- Mandats actifs et historique complet.
- Rôles explicites actifs et historique complet.
- Journal des modifications concernant cette personne.
- Actions selon droits : modifier, attribuer rôle, attribuer mandat, clore, corriger, archiver.

Une information « pourquoi ce statut ? » est fortement recommandée, par exemple :

```text
Membre du CA — via le mandat « Président », du 01/09/2026, sans date de fin.
Adhérent — via le rôle « Membre actif », du 01/09/2026 au 31/08/2027.
Représentant légal — via la famille Martin et l’inscription active de Léa Martin.
```

### 7.3 Gestion des rôles

- Attribution d’un rôle manuel avec début, fin, référence de décision et note.
- Modification et clôture.
- Refus des doublons et chevauchements.
- Prévisualisation des mandats impactés avant raccourcissement/suppression.
- Historique lisible avec états « à venir », « actif », « terminé ».

### 7.4 Gestion des mandats

- Attribution avec fonction, titulaire, début, fin prévue, date et référence de décision.
- Affichage immédiat du prérequis et de ses effets automatiques.
- Vérification de la couverture du prérequis avant enregistrement.
- Refus d’un second titulaire si la fonction est unique.
- Clôture avec date et motif.
- Vue « composition du bureau » et « composition du CA » à une date donnée.
- Reconstitution historique et export d’une composition passée.

### 7.5 Référentiels

- Consultation des rôles et fonctions canoniques.
- Modification des libellés et ordres réservée à l’administrateur.
- Création de rôles/fonctions personnalisés avec code stable.
- Impossibilité de changer le code après utilisation.
- Désactivation plutôt que suppression.
- Alerte et simulation avant modification d’un prérequis ou d’un effet.

### 7.6 Contrôle de cohérence

Écran et commande technique en lecture seule listant :

- périodes invalides ;
- rôles manuels interdits ;
- chevauchements ;
- fonctions uniques avec plusieurs titulaires ;
- mandats sans prérequis sur toute leur durée ;
- attributions liées à une personne archivée ;
- références manquantes ;
- écarts éventuels entre liens scolaires et statuts dérivés.

Chaque anomalie doit indiquer la personne, l’enregistrement, la période et une action de correction.

### 7.7 Import et export

- Import CSV d’adultes avec aperçu, correspondance des colonnes et détection des doublons.
- Import optionnel des rôles explicites et mandats.
- Clé de rapprochement prioritaire : identifiant interne ; sinon e-mail normalisé ; sinon nom + prénom + téléphone/date de naissance.
- Aucun rapprochement ambigu automatique : l’utilisateur choisit créer, fusionner ou ignorer.
- Export de l’annuaire, des adhérents, du CA, du bureau et de l’historique des mandats.
- Conservation de la date de référence et des filtres dans les métadonnées d’export.

### 7.8 Échanges de fichiers avec les logiciels administratifs

Cette fonction constitue un domaine à part entière et ne doit pas être réduite à un simple import CSV. Le dépôt de référence met en œuvre trois filières entrantes et une filière sortante officielle.

#### Flux entrants observés dans le dépôt de référence

**Import « primaire » par paire de fichiers CSV**

- Deux fichiers obligatoires et complémentaires : **Élèves** et **Responsables**.
- Contrôle strict des en-têtes et de la structure attendue.
- Le fichier Élèves alimente identité, date de naissance, sexe, INE, adresse, niveau et scolarité.
- Le fichier Responsables alimente identité, coordonnées et liens avec un ou plusieurs enfants.
- Les fichiers peuvent contenir des variantes de largeur connues et certains pieds de page d’export reconnus.
- Les encodages et séparateurs sont détectés et consignés.

**Import collège BEE par paire de fichiers XML**

- Un fichier racine `BEE_ELEVES`, version observée `5.0`.
- Un fichier racine `BEE_RESPONSABLES`, version observée `2.1`.
- Vérification que les deux fichiers concernent le même établissement UAI/RNE et la même année scolaire.
- Lecture de l’INE, de l’état civil, des dates d’entrée/sortie, du MEF, des responsables et des liens élève-responsable.
- Conversion des codes MEF supportés vers les niveaux internes.
- Conversion des codes de lien familial, notamment mère et père.
- Rejet explicite des versions, racines, identifiants dupliqués ou références croisées inconnues.
- Analyse XML sécurisée, sans résolution de ressources externes.

**Import tabulaire générique CSV/XLSX**

- Choix de la feuille XLSX et de la ligne d’en-tête.
- Association interactive des colonnes source aux champs internes.
- Transformations configurables : formats de date, population adulte/enfant, normalisation de valeurs.
- Modèles de correspondance réutilisables par utilisateur.
- Import possible de personnes, profils élèves, scolarité, rôles et mandats associatifs selon le mapping.

#### Flux sortant officiel observé

Le dépôt génère un **registre officiel XLSX** à partir d’une trame fournie par l’administration :

- chargement d’un modèle XLSX conservé en stockage privé ;
- détection des colonnes par libellés et alias, sans dépendre de lettres de colonnes fixes ;
- remplacement de variables telles que `{school_year}`, `{school_name}`, `{rne}` et `{month}` ;
- conservation de la mise en forme de la trame ;
- création/suppression des lignes nécessaires ;
- regroupement des responsables multiples dans les cellules prévues ;
- production d’un contrôle préalable avec avertissements et erreurs bloquantes ;
- calcul d’une empreinte SHA-256 du jeu de données exporté.

Les données de sortie observées couvrent notamment : numéro de ligne, nom, prénom, sexe, date et lieu de naissance, niveau/classe, établissement précédent, responsables et coordonnées, date d’entrée, date de sortie et nouvel établissement.

#### Portée à retenir pour lgs-ecsc

Le futur module doit être nommé **Échanges administratifs** et traiter séparément :

1. les formats natifs connus et versionnés ;
2. les fichiers tabulaires génériques configurables ;
3. les exports officiels basés sur une trame ;
4. l’historique, les contrôles et la résolution des conflits.

Il ne faut pas annoncer une compatibilité générique avec « SIECLE » ou tout autre logiciel national sans disposer d’un fichier d’exemple, d’une version et d’une spécification précise. Chaque connecteur est identifié par un code, une version supportée et un jeu de tests de conformité.

### 7.9 Cycle sécurisé d’un import administratif

Tout import suit obligatoirement les états suivants :

```text
Téléversé → Analysé → À résoudre → Prêt → En cours → Terminé
                    ↘ Conflit              ↘ Échec
```

Le parcours fonctionnel est :

1. choisir le logiciel source, le format, l’année scolaire et la date de référence ;
2. téléverser tous les fichiers attendus ;
3. conserver temporairement les originaux en stockage privé ;
4. calculer leur empreinte SHA-256 ;
5. valider structure, version, encodage et cohérence de la paire ;
6. normaliser les données vers un modèle canonique interne ;
7. produire un plan sans modifier la base ;
8. afficher créations, mises à jour, valeurs inchangées, avertissements et conflits ;
9. résoudre manuellement les identités ou valeurs ambiguës ;
10. recalculer le plan et son empreinte ;
11. appliquer en une transaction après verrouillage et vérification que le plan n’est pas périmé ;
12. produire un bilan et supprimer les fichiers temporaires selon la politique de conservation.

Une analyse n’autorise jamais implicitement l’application du fichier. Une confirmation distincte est requise.

### 7.10 Rapprochement et conflits d’import

La même stratégie de rapprochement doit être partagée par tous les connecteurs afin qu’un adulte importé depuis un CSV générique, puis depuis un export primaire ou BEE, reste une seule personne.

Ordre indicatif pour un élève :

1. identifiant source déjà connu ;
2. INE exact normalisé ;
3. nom, prénom et date de naissance ;
4. résolution manuelle si plusieurs candidats demeurent.

Ordre indicatif pour un adulte :

1. identifiant source déjà connu ;
2. combinaison forte d’identité et coordonnées ;
3. nom d’usage/nom de naissance, prénom, e-mail et téléphone normalisés ;
4. résolution manuelle si le score n’est pas décisif.

Règles indispensables :

- un e-mail ou téléphone familial partagé ne suffit jamais à fusionner deux personnes clairement différentes ;
- une date de naissance différente est une donnée sensible nécessitant un choix explicite ;
- aucune valeur non vide existante n’est écrasée silencieusement ;
- pour chaque différence, l’opérateur choisit « conserver l’existant » ou « prendre la source » ;
- les décisions de résolution sont persistées avec l’utilisateur et la date ;
- elles sont invalidées si la personne candidate ou la source évolue avant application ;
- importer deux fois les mêmes fichiers pour la même année déclenche une alerte et une confirmation renforcée ;
- un plan devenu périmé est refusé et doit être réanalysé.

### 7.11 Modèle de données des échanges

Ajouter conceptuellement les tables suivantes.

#### `data_exchange_connectors`

| Champ | Usage |
|---|---|
| `code` | `primary_csv`, `college_bee_xml`, `generic_tabular`, etc. |
| `name` | nom affiché |
| `direction` | `inbound`, `outbound` ou `both` |
| `supported_versions_json` | versions explicitement supportées |
| `active` | connecteur disponible |

#### `data_exchange_runs`

| Champ | Usage |
|---|---|
| `connector_id` | connecteur employé |
| `direction` | import ou export |
| `school_year_id` | année concernée |
| `reference_date` | date métier du traitement |
| `user_id` | initiateur |
| `status` | état du cycle |
| `filenames_json` | noms d’origine nettoyés |
| `file_checksums_json` | empreintes SHA-256 |
| `mapping_json` | correspondance appliquée |
| `transformations_json` | règles de conversion |
| `statistics_json` | créations, mises à jour, conflits, avertissements |
| `plan_fingerprint` | empreinte du plan analysé |
| `failure_message` | erreur fonctionnelle nettoyée |
| `started_at`, `completed_at` | chronologie |

#### `data_exchange_resolutions`

| Champ | Usage |
|---|---|
| `run_id` | traitement concerné |
| `source_key` | ligne/objet source stable |
| `resolution_type` | identité, champ ou action |
| `decision_json` | choix détaillé |
| `resolved_by` | opérateur |
| `resolved_at` | horodatage |

#### `data_exchange_mapping_templates`

Conserve les mappings réutilisables, leur propriétaire, le type de population, les transformations et une version. Les mots de passe, chemins absolus et secrets ne doivent jamais y figurer.

#### `official_document_templates`

Conserve le type de document, la version métier, le fichier privé, son empreinte, son état actif et les dates d’utilisation. Une nouvelle trame officielle crée une nouvelle version au lieu d’écraser l’ancienne.

### 7.12 Gestion des trames et exports officiels

- Un administrateur dépose une trame XLSX officielle et indique son type/version.
- Le système valide immédiatement les en-têtes obligatoires et variables reconnues.
- Une trame invalide ne peut pas devenir active.
- L’opérateur sélectionne année scolaire et mois de situation.
- Le système fixe la date de référence au dernier jour du mois, bornée par la fin de l’année scolaire.
- Un écran de prévisualisation affiche lignes, avertissements et bloqueurs.
- Une commune ou un code postal manquant peut être bloquant selon le document.
- Une date d’entrée ou un établissement précédent manquant peut être un avertissement selon les règles de la trame.
- Le fichier n’est généré que si aucun bloqueur ne subsiste.
- Le résultat reçoit un nom maîtrisé, une empreinte, l’utilisateur, la date de référence et la version de trame.
- Le téléchargement est autorisé uniquement aux profils habilités et journalisé.
- Toute régénération avec des données différentes crée un nouvel historique d’export.

### 7.13 Sécurité spécifique des fichiers

- Limite configurable de taille, nombre de lignes, feuilles et cellules.
- Liste blanche d’extensions et contrôle du type réel ; ne jamais faire confiance au seul nom.
- Stockage hors répertoire public avec nom interne aléatoire.
- Nettoyage de `../`, caractères de contrôle et chemins contenus dans les archives.
- Refus des fichiers XLSM et macros pour les flux ne les exigeant pas.
- Protection contre les bombes ZIP des fichiers XLSX.
- XML : interdiction des DTD, entités externes, XInclude et accès réseau.
- CSV : protection contre les formules lors des réexports (`=`, `+`, `-`, `@`).
- Limitation du temps et de la mémoire d’analyse.
- Messages d’erreur sans chemin serveur ni trace technique.
- Suppression automatique des fichiers temporaires après succès ou échec ; conservation éventuelle selon durée RGPD explicitement définie.
- Antivirus en option si l’infrastructure le permet.

### 7.14 Critères d’acceptation des échanges

**CA-ECH-01** — Une paire Élèves/Responsables incomplète ou concernant deux années différentes est refusée sans écriture.  
**CA-ECH-02** — Un XML BEE de racine ou version inconnue est refusé avec un message exploitable.  
**CA-ECH-03** — Un fichier générique permet de choisir feuille, ligne d’en-tête et mapping, puis de sauvegarder ce mapping.  
**CA-ECH-04** — L’analyse n’écrit aucune donnée métier.  
**CA-ECH-05** — L’application est entièrement transactionnelle : une erreur tardive ne laisse aucune écriture partielle.  
**CA-ECH-06** — Toute évolution de la base après analyse invalide le plan concerné.  
**CA-ECH-07** — Deux personnes partageant e-mail et téléphone familial ne sont pas fusionnées si leurs identités divergent.  
**CA-ECH-08** — Les résolutions manuelles indiquent l’opérateur, la date et les valeurs choisies.  
**CA-ECH-09** — Un doublon exact de fichiers est détecté par empreinte.  
**CA-ECH-10** — Une trame XLSX privée est rejetée si une colonne obligatoire manque.  
**CA-ECH-11** — Le registre officiel respecte les styles et renseigne toutes les colonnes attendues.  
**CA-ECH-12** — Un export avec erreur bloquante ne peut pas être téléchargé.  
**CA-ECH-13** — Les fichiers source et résultat ne sont jamais accessibles directement par URL publique.  
**CA-ECH-14** — Un utilisateur sans droit reçoit HTTP 403 sur analyse, résolution, application et téléchargement.  
**CA-ECH-15** — Les flux primaire, BEE et générique partagent le même moteur de rapprochement et réutilisent la même personne.

## 8. Parcours utilisateurs

### PU-01 — Consulter la composition actuelle du CA

1. L’utilisateur ouvre « Association » puis « Conseil d’administration ».
2. La date de référence vaut aujourd’hui.
3. Le système affiche chaque membre, sa source d’éligibilité et son mandat.
4. L’utilisateur filtre, imprime ou exporte.
5. Il change la date pour reconstituer un ancien CA.

**Résultat attendu :** aucune saisie de statut n’est nécessaire ; la composition provient des mandats actifs.

### PU-02 — Nommer un président

1. Le gestionnaire ouvre la fiche de la personne.
2. Il choisit « Ajouter un mandat » puis « Président ».
3. Le formulaire annonce : prérequis « Membre actif », effets « Bureau » et « CA », fonction unique.
4. Il saisit début, fin éventuelle et référence de décision.
5. Le système vérifie la couverture du rôle et l’absence d’autre président sur la période.
6. Le mandat est enregistré et audité.

**Échecs guidés :** proposer d’abord d’ajouter/prolonger le rôle membre actif ou de corriger la période du président existant.

### PU-03 — Élire un représentant des parents

1. Le gestionnaire choisit la fonction « Représentant des parents ».
2. La recherche ne propose que les personnes représentantes légales pendant toute la période envisagée, ou signale clairement les ruptures.
3. La personne et la période sont sélectionnées.
4. Le mandat confère automatiquement la qualité de membre du CA.

**Cas bloquant :** l’enfant quitte l’établissement avant la fin du mandat. Le gestionnaire doit raccourcir le mandat ou établir un autre fondement conforme aux statuts.

### PU-04 — Mettre fin à une adhésion

1. Le gestionnaire ouvre le rôle explicite « Membre actif ».
2. Il saisit une date de fin.
3. Le système liste les mandats dépendants dépassant cette date.
4. Il clôt d’abord les mandats concernés, puis enregistre la fin d’adhésion.

### PU-05 — Corriger un responsable légal

1. La direction modifie le rattachement familial ou le statut de représentant légal.
2. Le système identifie les mandats de représentant des parents affectés.
3. Si un mandat valide perd son prérequis, l’opération est bloquée avec une explication.
4. Après correction des mandats, la modification familiale peut être enregistrée.

### PU-06 — Ajouter un adulte sans enfant scolarisé

1. Le gestionnaire crée une fiche adulte indépendante.
2. Il renseigne identité et coordonnées sans famille obligatoire.
3. Il peut lui attribuer « Membre actif », « Intervenant » ou une fonction sans prérequis familial.
4. La personne apparaît dans l’annuaire associatif, mais pas dans le répertoire des élèves.

### PU-07 — Préparer un renouvellement annuel

1. Le gestionnaire filtre les rôles et mandats arrivant à échéance dans les 60 jours.
2. Il exporte la liste de préparation.
3. Après décision, il clôt les anciens mandats et crée les nouveaux.
4. Le système conserve deux périodes consécutives sans les considérer comme chevauchantes si la première finit la veille du début de la seconde.

### PU-08 — Auditer une incohérence ancienne

1. L’administrateur ouvre « Contrôle de cohérence ».
2. Il consulte les anomalies sans modification automatique.
3. Il ouvre l’enregistrement concerné depuis le rapport.
4. Il corrige, relance le contrôle et obtient un rapport sans anomalie.

## 9. Autorisations

Le modèle actuel `admin/direction/principal/teacher` et les capacités booléennes peuvent supporter une V1, mais il faut ajouter des capacités dédiées :

| Capacité | Admin | Direction | Gestionnaire association | Lecture |
|---|---:|---:|---:|---:|
| `can_view_association` | oui | oui | oui | oui |
| `can_view_association_private` | oui | oui | oui | non |
| `can_manage_association_assignments` | oui | option | oui | non |
| `can_manage_association_references` | oui | non | non | non |
| `can_export_association` | oui | oui | oui | option |
| `can_audit_association` | oui | oui | option | non |

Recommandation à moyen terme : remplacer la multiplication des colonnes `can_*` par des tables `permissions`, `roles`, `role_permissions` et `user_roles`. Cette refonte n’est pas nécessaire pour le premier lot, mais devient préférable si les profils continuent à se multiplier.

Règles d’affichage :

- les utilisateurs sans droit ne voient ni menu ni route associative ;
- les notes internes, dates de naissance et adresses sont réservées aux droits privés ;
- les exports appliquent les mêmes restrictions que l’écran ;
- les contrôles sont répétés côté serveur pour chaque action POST.

## 10. Exigences non fonctionnelles

### Sécurité et données personnelles

- Requêtes préparées exclusivement.
- CSRF sur toute mutation.
- Contrôle d’autorisation côté serveur.
- Échappement HTML systématique.
- Journalisation des créations, changements de dates, suppressions et exports sensibles.
- Minimisation des données exportées.
- Politique de conservation et procédure de droit d’accès/rectification à définir conformément au RGPD.
- Sauvegardes chiffrées, test de restauration et accès restreint.

### Intégrité

- Toutes les opérations multi-tables sont transactionnelles.
- Verrouillage de la fonction lors de l’attribution d’un mandat unique.
- Clés étrangères en `RESTRICT` pour les historiques.
- Codes de référentiel uniques, stables et non réutilisés.
- Contrôle de cohérence exécutable sans modifier les données.

### Performance

- Index sur toutes les clés étrangères et colonnes de période.
- Pagination de l’annuaire.
- Calcul SQL groupé des statuts pour les listes ; éviter une requête par personne.
- Objectif indicatif : liste filtrée de 5 000 adultes en moins de 2 secondes sur l’hébergement cible.

### Accessibilité et ergonomie

- Libellés explicites, navigation clavier, focus visible et messages reliés aux champs.
- Ne jamais coder un statut uniquement par la couleur.
- Confirmation sur clôture/suppression avec résumé de l’impact.
- Dates affichées en `JJ/MM/AAAA`, stockées en `DATE` ISO.

## 11. Écrans et navigation proposés

Ajouter une entrée latérale **Association**, visible selon `can_view_association`, avec :

1. **Vue d’ensemble** — compteurs adhérents, CA, bureau, échéances et incohérences.
2. **Annuaire** — recherche, filtres, date de référence et exports.
3. **Conseil d’administration** — composition calculée.
4. **Bureau** — composition calculée.
5. **Mandats** — liste historique et opérations.
6. **Échéances** — fins à 30/60/90 jours.
7. **Contrôle** — anomalies de cohérence.
8. **Référentiels** — réservé administrateur.

Dans la fiche famille, chaque responsable renvoie vers sa fiche adulte associative. Dans la fiche adulte, le contexte familial et associatif est présenté sans dupliquer les données.

## 12. API interne et organisation du code

Respecter l’architecture actuelle de lgs-ecsc, tout en isolant la logique métier :

- `app/association.php` : contrôleur de pages et actions ;
- `app/association_rules.php` : calculs purs et validations ;
- `app/association_queries.php` : requêtes de liste et projections ;
- `public/assets/association.css` : styles spécifiques minimaux ;
- migration SQL versionnée et idempotente pour les nouvelles tables ;
- tests PHP dédiés si un harnais de test est introduit ; à défaut, script de recette SQL/PHP non destructif sur une base de test.

Fonctions métier suggérées :

```php
association_statuses_at(int $guardianId, DateTimeImmutable $date): array
association_positions_at(int $guardianId, DateTimeImmutable $date): array
guardian_satisfies_role_at(int $guardianId, string $roleCode, DateTimeImmutable $date): bool
validate_role_assignment(array $assignment, ?int $existingId = null): array
validate_position_assignment(array $assignment, ?int $existingId = null): array
association_consistency_report(): array
```

Les fonctions de validation retournent des erreurs structurées par champ. Le contrôleur transforme ces erreurs en messages d’interface ; il ne réimplémente pas les règles.

## 13. Migration des données

### Étape 1 — Sauvegarde et diagnostic

- sauvegarde complète et restauration testée ;
- détection des responsables potentiellement dupliqués ;
- vérification des e-mails et téléphones partagés ;
- inventaire des familles avec plus de deux responsables ou situations recomposées.

### Étape 2 — Extension sans rupture

- créer les tables associatives ;
- ajouter les colonnes de `guardians` ;
- créer `family_guardians` ;
- recopier chaque lien `guardians.family_id` ;
- conserver temporairement les anciennes colonnes pour compatibilité.

### Étape 3 — Référentiels

- insérer/upserter les rôles et fonctions canoniques par `code` ;
- ne jamais supprimer automatiquement une entrée inconnue ;
- produire un rapport si un code canonique existant a une définition incompatible.

### Étape 4 — Adaptation de l’application

- lire d’abord `family_guardians`, avec repli temporaire sur `guardians.family_id` ;
- basculer les formulaires vers le nouveau lien ;
- activer le module associatif ;
- vérifier les exports et bulletins existants.

### Étape 5 — Nettoyage différé

- supprimer les anciennes colonnes seulement après recette complète et une période de production stable ;
- ne jamais fusionner automatiquement deux adultes sur le seul nom ;
- conserver une table de correspondance si des identifiants changent.

## 14. Plan de réalisation

### Lot 0 — Arbitrages métier

- Valider le vocabulaire : adhérent, membre actif, membre de droit, bureau, CA.
- Fixer les fonctions réellement uniques et les quotas éventuels.
- Décider si les enseignants sont dérivés automatiquement.
- Définir précisément le représentant légal dans les familles recomposées.
- Valider les règles des représentants du curé et de l’évêque.

### Lot 1 — Socle de données et services

- Migration du modèle adulte/famille.
- Référentiels rôles/fonctions.
- Attributions temporalisées.
- Service de calcul des statuts et validations.
- Contrôle de cohérence.
- Audit.

### Lot 2 — Interface de gestion

- Annuaire, fiche adulte, rôles et mandats.
- CA, bureau, date de référence.
- Filtres, échéances et explication des statuts.
- Permissions.

### Lot 3 — Échanges administratifs

- Import primaire par paire Élèves/Responsables.
- Import collège BEE XML par versions explicitement supportées.
- Import générique CSV/XLSX et modèles de mapping.
- Analyse préalable, résolution des conflits et application transactionnelle.
- Gestion des trames XLSX et génération des registres officiels.
- Historique, empreintes et sécurité des fichiers.

### Lot 4 — Exports associatifs et qualité

- CSV et impression.
- Tests de concurrence et de non-régression.
- Recette métier et sécurité.

### Lot 5 — Extensions optionnelles

- Cotisations et paiements.
- Assemblées générales, convocations et présence.
- Quorum, pouvoirs et votes.
- Pièces et procès-verbaux.
- Notifications d’échéance.

## 15. Critères d’acceptation principaux

**CA-01** — À une date donnée, une personne avec un mandat de président actif apparaît au bureau et au CA sans saisie supplémentaire.  
**CA-02** — Une date antérieure à son mandat ne lui attribue aucun de ces statuts.  
**CA-03** — Un deuxième président sur une période chevauchante est refusé, y compris lors de deux requêtes concurrentes.  
**CA-04** — Deux représentants des parents peuvent être simultanés si la fonction est configurée `multiple`.  
**CA-05** — Un mandat exigeant « membre actif » est refusé si le rôle commence un jour après ou finit un jour avant le mandat.  
**CA-06** — Raccourcir le rôle préalable d’un mandat existant est refusé et explique quel mandat est impacté.  
**CA-07** — Un représentant légal n’est adhérent que pendant l’existence simultanée du lien légal et d’une scolarisation active.  
**CA-08** — Un intervenant seul n’est pas affiché comme adhérent.  
**CA-09** — Les statuts identiques issus de plusieurs sources ne sont affichés qu’une fois, mais leurs sources restent consultables.  
**CA-10** — Une personne sans compte utilisateur peut être membre ou mandataire.  
**CA-11** — Un utilisateur sans capacité associative reçoit HTTP 403 sur les routes, même s’il connaît l’URL.  
**CA-12** — Les exports reproduisent les filtres, la date de référence et les restrictions de données.  
**CA-13** — L’archivage d’une personne conserve ses anciens mandats dans une reconstitution historique.  
**CA-14** — Le rapport de cohérence ne modifie aucune donnée et retourne un résultat exploitable.  
**CA-15** — Les fonctions scolaires existantes (familles, élèves, bulletins, cours et comptes) continuent de fonctionner après migration.

## 16. Cas de test indispensables

- périodes contiguës, chevauchantes, ouvertes et d’un seul jour ;
- fuseau/date du serveur autour de minuit ;
- ajout, modification, clôture et suppression de chaque type d’attribution ;
- perte indirecte d’un rôle par sortie scolaire ;
- enfant appartenant à une famille avec plusieurs responsables ;
- responsable lié à plusieurs familles ;
- fonctions uniques et multiples ;
- deux créations concurrentes du même mandat unique ;
- modification du prérequis d’une fonction déjà utilisée ;
- archivage/restauration d’un adulte ;
- déduplication des statuts ;
- permissions d’affichage et d’action ;
- export avec données privées masquées ;
- compatibilité des bulletins et formules d’adressage après migration de `guardians`.

## 17. Décisions métier restant à valider

Les points suivants ne peuvent pas être déduits du code source et conditionnent la réalisation :

1. Les représentants légaux d’un élève scolarisé sont-ils tous adhérents automatiquement, ou seulement ceux désignés/à jour d’une cotisation ?
2. La qualité d’enseignant doit-elle être automatique depuis les affectations pédagogiques de lgs-ecsc ?
3. Quelles fonctions sont uniques, multiples ou soumises à un quota ?
4. Un membre du bureau doit-il toujours être membre actif, y compris pour les membres de droit ?
5. Les représentants du curé/évêque héritent-ils de la qualité de membre de droit ou de membre du CA ?
6. Faut-il gérer des mandats par année scolaire, année civile ou dates libres ?
7. Une cotisation payée est-elle une condition d’adhésion distincte du rôle ?
8. Les utilisateurs « direction » peuvent-ils gérer l’association ou faut-il un profil autonome ?
9. Faut-il gérer une personne responsable de certains enfants seulement au sein d’une même famille ?
10. Quelles données doivent figurer sur les exports officiels et quelle durée de conservation appliquer ?

## 18. Recommandation finale

Le dépôt de référence doit être repris comme **spécification de règles**, pas comme composant logiciel. Son meilleur apport est le triptyque : attributions datées, statuts dérivés, contrôle des prérequis sur toute la période.

Pour lgs-ecsc, la solution la plus robuste est d’étendre progressivement `guardians` en annuaire adulte unique, puis d’ajouter rôles et mandats temporalisés. Le premier développement doit impérativement inclure le moteur de cohérence, les transactions, les permissions et l’audit : les écrans seuls ne suffisent pas à garantir la fiabilité juridique et historique de la composition associative.
