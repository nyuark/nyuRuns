# nyuRuns — Guide de l’utilisateur

Version 1.0

## Sommaire

1. [Présentation du produit](#1-présentation-du-produit)
2. [Configuration minimale](#2-configuration-minimale)
3. [Étapes d’installation](#3-étapes-dinstallation)
4. [Étapes de configuration](#4-étapes-de-configuration)
5. [Ta première application (run)](#5-ta-première-application-run)
6. [Structure de ton projet](#6-structure-de-ton-projet)
7. [Référence du code](#7-référence-du-code)
8. [Recommandations](#8-recommandations)

A. [Dépannage](#a-dépannage)

---

## 1. Présentation du produit

nyuRuns est un framework PHP pour le back-end de ton application : la partie
qui reçoit les requêtes d’une page web, d’une app mobile ou d’un autre
programme, et qui renvoie des données. Tu écris ce que ton service fait
vraiment ; nyuRuns s’occupe du reste. Il :

- trouve quelle action une URL demande ;
- vérifie qui appelle, et s’il en a le droit ;
- ne garde que les entrées que ton action attend, chacune dans le bon format ;
- écrit la réponse, en JSON ou dans un autre format, erreurs comprises.

Il apporte aussi ce dont la plupart des services finissent par avoir
besoin de toute façon : des utilisateurs et des rôles, des tokens signés et
des sessions, des modèles de base de données, des documents, des images,
des sons, des e-mails, des journaux, et une ligne de commande
(`php nyuRun`) pour installer, configurer, tester et livrer ton projet.

### 1.1 Ce que tu vas construire

Pas à pas, tu vas installer nyuRuns sur ta machine et construire un petit
service appelé `Notes` :

- des notes que toute personne connectée peut lire et rechercher ;
- chaque note à sa propre URL, où tu peux la lire, la créer, la modifier et
  la supprimer ;
- l’écriture réservée à l’équipe, grâce à un rôle ;
- une page pour navigateur qui affiche une note.

À la fin, tu auras un service qui répond à de vraies requêtes, et tu
sauras où se trouve chaque partie d’un projet.

### 1.2 Avant de commencer

Il te faut connaître PHP et les bases de HTTP : les méthodes, les codes de
statut, les en-têtes. Pas besoin de serveur de base de données : le guide
utilise SQLite, qui garde toute la base dans un seul fichier. Les commandes
sont écrites pour un terminal et marchent tout aussi bien dans PowerShell,
sous Windows.

Un mot de vocabulaire : nyuRuns appelle **run** chacune des applications
d’un projet, rangée dans son dossier de `runs/`. Le mot revient partout dans
ce guide.

### 1.3 Les autres documents

| Document | Pour y trouver |
|---|---|
| [Spécifications fonctionnelles](funcspecs.md) | ce que fait nyuRuns, et comment se déroulent ses processus |
| [Spécifications techniques](techspecs.md) | comment il est construit : composants, fichiers, commandes, conventions |
| [Hébergement](hosting.md) | mettre un projet sur un vrai serveur |
| Référence du code | n’importe quelle classe ou méthode, en anglais (chapitre 7) |

---

## 2. Configuration minimale

### 2.1 Pour ce guide

| Il te faut | Pourquoi | À vérifier avec |
|---|---|---|
| **PHP 8.2** ou plus récent, en ligne de commande | il fait tourner nyuRuns et sa ligne de commande | `php -v` |
| les extensions **json**, **dom**, **iconv**, **zlib**, **mbstring** | sans elles, Composer refuse d’installer les bibliothèques de nyuRuns | `php -m` |
| l’extension **openssl** | elle signe les tokens avec lesquels tes utilisateurs se connectent | `php -m` |
| l’extension **pdo_sqlite** | la base de données du tutoriel | `php -m` |
| **Composer** | il installe les bibliothèques | `composer --version` |
| **Git** | il récupère le code | `git --version` |

Le serveur web intégré de PHP suffit pour le développement : pas besoin
d’Apache ni de nginx pour ce guide.

La plupart des builds de PHP ont déjà ces extensions. S’il en manque une
dans `php -m`, active-la dans ton `php.ini` (`extension=pdo_sqlite`). Si ton
PHP ne charge aucun `php.ini`, fais pointer la variable d’environnement
`PHPRC` vers le dossier qui en contient un. L’annexe A montre ce que donne
chaque extension manquante.

### 2.2 Pour un vrai serveur

Un serveur qui fait tourner ton projet pour de vrais utilisateurs a aussi
besoin de HTTPS, et d’un serveur web qui envoie chaque URL à nyuRuns. Le
reste dépend de ce que fait ton projet : une autre base de données, un
serveur d’e-mails, ffmpeg pour les sons, ou des extensions pour les images
et les documents bureautiques.

- [Spécifications techniques, chapitre 3](techspecs.md#3-configuration-requise)
  liste ce dont chaque partie a besoin.
- [Hébergement](hosting.md) te guide dans la mise en place du serveur.

Sur n’importe quelle machine, `php nyuRun install checkAll` te dit ce qui
manque.

---

## 3. Étapes d’installation

### 3.1 Récupérer le code

```
git clone <repository address> notes
cd notes
composer install
```

Composer place les bibliothèques dans `vendor/`. À partir de maintenant,
lance chaque commande depuis le dossier `notes`, la racine de ton projet.

### 3.2 Installer le projet

```
php nyuRun install complete
```

La commande pose quelques questions : ce qu’est ton projet, l’environnement,
où vivent tes utilisateurs, la base de données, et le nom des comptes de
base. Chacune propose une réponse, et Entrée l’accepte. Pour ce guide,
réponds :

| Question | Réponse | Pourquoi |
|---|---|---|
| `What this project is (allInOne, authServ, dataServ, openSite) [allInOne]` | Entrée | les utilisateurs, la connexion et les notes vivent tous dans ce seul projet |
| `Environment (DEVS, TEST, PREV, PROD) [DEVS]` | Entrée | tu développes : les erreurs s’affichent et tout est journalisé |
| `Store of the users (nativeDb, userFile, none) [userFile]` | `nativeDb` | les utilisateurs vont dans une base de données, dont le tutoriel a de toute façon besoin |
| `Database type (mysql_db, postgres, sqlitedb) [mysql_db]` | `sqlitedb` | SQLite : la base n’est qu’un fichier, sans serveur |
| `Database file [nyuRuns]` | `notes.sqlite` | le fichier, à la racine de ton projet |
| `Accounts to create: simple (userDemo and bossDemo) or full (userDemo, powrDemo, deptDemo, bossDemo) [simple]` | `full` | un compte par rôle : le tutoriel se connecte aussi en tant que `powrDemo` |
| `Name of the standard account [userDemo]`, puis ceux des rôles `advanced`, `delegate` et `managers` | Entrée, quatre fois | les noms qu’utilise ce guide |

La commande s’occupe ensuite de tout le reste :

```
check            state  found
php              fine   PHP 8.5.12, 8.2 at least is needed
…
file              state
.env              created from .env.example
conf/access.json  created from conf/access.json.dist
file                state
conf/database.json  created from conf/database.json.dist
written: conf/keys/jwtToken.key, conf/keys/jwtToken.pub
written: .env NYU_API_KEY
API key (shown this once): df28c6bde54149d839d52e751165268d…
written: .env NYU_MARK_KEY
written: .env NYU_TFA_KEY
Table users created.
Users created. Their passwords are shown this once:
Password of userDemo (standard) (shown this once): 501dfc2edfd1d66323e4
Password of powrDemo (advanced) (shown this once): f5f01d6b34b3427a48b6
Password of deptDemo (delegate) (shown this once): 9c41e07a2d5b6f38e1a4
Password of bossDemo (managers) (shown this once): 586182c0d12ce1893394
```

Voici ce qui vient de se passer :

- **PHP est vérifié en premier**, avant que quoi que ce soit ne soit écrit.
- **Tes réglages locaux** ont été créés à partir de leurs exemples. `.env`
  garde les secrets, `conf/access.json` règle la façon dont les
  utilisateurs se connectent, et `conf/database.json` dit où se trouve la
  base de données. Aucun d’eux n’est jamais commité.
- **Les clés des tokens** ont été générées dans `conf/keys`.
- **Quatre utilisateurs** ont été créés, un par rôle : `userDemo`
  (`standard`), `powrDemo` (`advanced`), `deptDemo` (`delegate`) et
  `bossDemo` (`managers`). La version simple, celle que prend `--noPrompt`,
  ne crée que `userDemo` et `bossDemo`. **Copie leurs mots de passe tout de
  suite** : tu ne les reverras plus, et tu en auras besoin aux étapes
  suivantes. Les tiens seront différents de ceux ci-dessus.
- **Un diagnostic** conclut le tout (3.5).

Relance-la, rien ne casse : `install complete` ne remplace jamais ce qui
existe déjà, qu’il s’agisse d’un fichier, d’un secret, d’une table ou d’un
utilisateur.

> Pas envie d’une base de données ? Réponds `userFile`. Tes utilisateurs
> sont alors gardés dans un fichier, `conf/users.lst`, aucun réglage de base
> de données n’est créé, et le service tourne sans aucune base. Tu pourras
> les déplacer dans une base plus tard, en une seule commande (4.8).

> Tu veux séparer la connexion de tes données ? Fais d’un projet un
> **serveur d’authentification** (`authServ`) : il garde les utilisateurs,
> les connecte et émet les tokens. L’installation demande aussi qui émet
> les tokens (ton nom d’hôte est proposé) et quels projets les accepteront.
> Tes autres projets sont alors des **serveurs de ressources** (`dataServ`) :
> ils n’ont pas d’utilisateurs, et acceptent les tokens du serveur
> d’authentification. Leur installation saute les utilisateurs et demande
> le fichier de la clé publique du serveur d’authentification
> (`conf/keys/jwtToken.pub` là-bas), son émetteur, et le nom de ce projet
> dans l’audience. Leur propre connexion répond 501 : les utilisateurs se
> connectent sur le serveur d’authentification. `install checkAll` indique
> le profil d’un projet, dans sa ligne `profile`.

> Juste un site, sans rien à quoi se connecter ? Réponds `openSite`. Pas
> d’utilisateurs, pas de comptes, pas de secrets : l’installation sert le
> profil `statics`, tes pages et tes fichiers, ouverts à tous. Elle n’ouvre
> jamais un profil qui pourrait lancer une action : un run que tu ajoutes
> plus tard ne deviendra donc pas public dans ton dos. Si tu relances
> ensuite l’installation avec une base d’utilisateurs, elle referme les
> profils qu’elle avait ouverts, et te dit lesquels.

### 3.3 Le servir

PHP a son propre serveur web, largement suffisant pour le développement.
Lance-le depuis le dossier `www`, dans un second terminal, et laisse-le
tourner :

```
cd www
php -S localhost:8000 index.php
```

Chaque requête arrive à `index.php`, qui la passe à nyuRuns. Ctrl+C arrête
le serveur.

### 3.4 Envoyer une première requête

Connecte-toi en tant que `userDemo` avec son mot de passe (sous Windows
PowerShell 5, tape `curl.exe` au lieu de `curl`) :

```
curl -X POST http://localhost:8000/auth/login -d "userName=userDemo&passWord=501dfc2edfd1d66323e4"
```

```json
{"token":"eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIsImtpZCI6…"}
```

Le service répond avec un token, signé par les clés créées à
l’installation. D’origine, chaque URL demande ce token, sauf la connexion
et ce que font les visiteurs avant d’avoir un compte. Ton front-end le
renvoie avec chaque requête, dans un en-tête `Authorization: Bearer <token>`.
Tant que tu y es, essaie de tout casser :

| Requête | Réponse |
|---|---|
| un mauvais mot de passe | `401 {"error":"Invalid credentials","code":401}` |
| `curl http://localhost:8000/nothing/here` | `401 {"error":"Missing Bearer Authorization header","code":401}` |
| la même, avec `-H "Authorization: Bearer <token>"` | `404 {"error":"Not Found","code":404}` |
| `curl http://localhost:8000/auth/login` avec le token (un `GET`) | `405 {"error":"Method Not Allowed","code":405}` |

Le chapitre 4 montre comment décider qui entre où.

### 3.5 Le vérifier

```
php nyuRun install checkAll
```

```
check                  state  found
php                    fine   PHP 8.5.12, 8.2 at least is needed
upload progress        fine   off
extension json         fine   loaded
extensions             warn   optional, not loaded: zip, gd, exif, imagick
folder temp/logs       fine   writable
origins default        warn   every origin is allowed while the preset reads tokens: list the origins under "corsList"
preset default         fine   loads
preset example         fine   its keys are known; never loaded, it only explains them
route clash            fine   no two applications declare one path
access route           fine   every entry of openList and minRoles names a route
entry index            fine   starts default, which checks access
access login           fine   nativeDb through jwtToken
token keys jwtToken    fine   set, 4096 bits
token claims jwtToken  warn   no issuer or no audience: the tokens are not bound to this project; …
database nyuruns       fine   answers (sqlitedb)
users                  fine   the table users is there
errors                 fine   ERR_SHOW is on in the environment DEVS
…
fail: 0
warn: 6
```

Chaque ligne vaut `fine`, `warn` (à regarder) ou `fail` (le service ne
peut pas servir, ou il est exposé). Sur une installation toute neuve,
quelques avertissements sont normaux : `extensions` (des extensions
facultatives dont tu n’as peut-être pas besoin), `origins` pour chaque
profil qui lit des tokens (n’importe quel site peut appeler l’API depuis un
navigateur), `token claims` (tes tokens ne sont pas encore liés à ton
projet) et, sous Windows, `file rights`. Le profil `example` ne fait
qu’expliquer les réglages : il n’est jamais chargé, et un point d’entrée
qui le nomme fait échouer le diagnostic. Un `warn` sur `upload progress`
vient des réglages de PHP lui-même : mets
`session.upload_progress.enabled = 0` dans ton `php.ini`. L’annexe A
explique les autres lignes.

### 3.6 Sur un vrai serveur

On ne copie pas un projet tel quel sur un serveur. Tu construis une copie
faite pour ce serveur (`php nyuRun bundles final`), tu l’y installes avec
`install complete`, et tu la places derrière un serveur web qui envoie
chaque URL à `www/index.php`. Le chapitre 8 donne la liste à vérifier avant
la mise en ligne, et [Hébergement](hosting.md) couvre la mise en place du
serveur.

---

## 4. Étapes de configuration

Chaque réglage vit dans un fichier de `conf/`, sauf les secrets, qui vont
dans `.env`. `php nyuRun setting` les affiche et change une valeur à la
fois : il vérifie d’abord la nouvelle valeur, et garde le fichier précédent
dans `temp/archives`. Chaque étape ci-dessous se termine par un moyen de
vérifier qu’elle a marché.

### 4.1 Choisir le profil

D’origine, chaque requête démarre le profil `default` : des tokens, avec la
connexion laissée ouverte. Le profil `restapi` fonctionne de la même façon,
et c’est celui qui est prévu pour une API web : sers-le donc à la racine :

```
php nyuRun setting entryPts / restapi
```

Un même projet peut servir plusieurs profils : `setting entryPts /api restapi`
confie à `restapi` les chemins sous `/api`, et `--hostName=api.example.org`
lui donne un hôte à lui. La carte se trouve dans `conf/doorways.lst`.

Regarde ensuite qui peut atteindre quoi :

```
php nyuRun setting fullList restapi
```

```
preset: restapi
detectBy: autopath
others: logged
path                    methods  kind      target          owner  access
/index                  GET      toAction  shows/index     site   logged
/auth/login             POST     toAction  Auth/login      Auth   open
/auth/logout            POST     toAction  Auth/logout     Auth   logged
/auth/active            POST     toAction  Auth/active     Auth   open
…
                        any      toAction  users/*                delegate
                        any      toAction  users/create           managers
```

La colonne `owner` dit qui déclare chaque route : le site (`conf/routes`)
ou un run (son propre `routes.rte`). Tout demande un token
(`others: logged`). Les exceptions sont la connexion et ce que font les
visiteurs avant d’avoir un compte : l’activer, s’inscrire, récupérer un mot
de passe oublié. Le run `Users` est réservé aux délégués et aux
gestionnaires : un délégué (`deptDemo`) lit les utilisateurs et valide les
inscriptions, et toutes les autres actions reviennent aux gestionnaires
(`bossDemo`). Essaie avec `curl http://localhost:8000/users` : sans token tu
obtiens un 401, avec le token de `userDemo` un 404, et avec celui de
`deptDemo` ou de `bossDemo` la liste des utilisateurs.

Les autres profils sont `service` (des programmes qui appellent avec une
clé d’API), `website` (un site avec un cookie de session), `statics` (des
pages et des fichiers seulement), `mapping` (uniquement les routes que tu
déclares) et `example`, qui n’est jamais chargé mais montre tous les
réglages qu’un profil peut contenir.

### 4.2 Décider qui entre où

Le rôle minimum de chaque route se règle dans le profil, sous `minRoles`.
Ouvre `conf/presets/restapi.rps` et cherche :

```json
"minRoles": { "users/*": "delegate", "users/create": "managers", "users/remove": "managers", "users/role": "managers", "users/setmail": "managers", "users/langs": "managers", "users/lock": "managers", "users/unlock": "managers", "users/reset": "managers" }
```

Quand plusieurs entrées nomment une route, c’est le rôle le plus élevé qui
s’applique : `users/*` ouvre `Users` à un délégué, et chaque action qui
modifie un compte est nommée pour rester aux gestionnaires.

Nomme une route `run/action`, `run/*` pour toutes les actions d’un run, ou
`/chemin` pour une route déclarée, montage compris. Les rôles sont classés
(`standard` < `advanced` < `delegate` < `managers`) : une route qui demande
`advanced` laisse donc aussi entrer les deux rôles au-dessus. Le chapitre 5
ajoute ici les routes de `Notes`. Après une modification,
`setting fullList restapi` montre le résultat, et `install checkAll` te dit
si le profil se charge toujours.

Les blocs comme `minRoles` se modifient à la main dans le fichier, car
`setting setValue` ne change que des valeurs isolées.

### 4.3 Gérer les utilisateurs

```
php nyuRun account fullList
```

```
id_user  systemName  accessRole  userStatus  contactEml  userLocale  signupDate  createDate           accessDate
4        bossDemo    managers    approved                                        2026-11-10 01:13:00
3        deptDemo    delegate    approved                                        2026-11-10 01:13:00
2        powrDemo    advanced    approved                                        2026-11-10 01:13:00
1        userDemo    standard    approved                                        2026-11-10 01:13:00
```

| Pour | Lance |
|---|---|
| créer un utilisateur en attente d’activation | `php nyuRun account makeUser ann advanced` (le code d’activation n’est affiché qu’une fois) |
| définir son adresse e-mail | `php nyuRun account setEmail ann ann@example.org` |
| réinitialiser son mot de passe | `php nyuRun account password ann` (affiché une fois) |
| changer son rôle | `php nyuRun account giveRole ann managers` |
| le bloquer ou le débloquer | `php nyuRun account lockUser ann`, `php nyuRun account freeUser ann` |
| voir ce qu’une commande accepte | `php nyuRun account --helpText` |

Un gestionnaire peut aussi faire tout cela en HTTP, avec le run `Users`
(`POST /users`, `PUT /users/<user>/lock`… : ses routes nomment
l’utilisateur dans le chemin). Chaque changement est tracé dans
`temp/logs/access.log`.

### 4.4 Envoyer les e-mails d’accès

Sans compte de messagerie, le code d’activation d’un nouvel utilisateur va
au gestionnaire, qui le lui transmet. Avec un compte, l’utilisateur le
reçoit directement. Pour essayer sans rien envoyer pour de vrai, utilise un
compte qui écrit les e-mails dans des fichiers :

```
cp conf/mailer.json.dist conf/mailer.json
php nyuRun setting setValue accsConf nativeDb.mailAcnt devbox
```

`devbox` est le troisième compte de l’exemple, et il enregistre chaque
e-mail comme un fichier dans `temp/mails`. Maintenant, connecté en tant que
`bossDemo`, crée un utilisateur avec une adresse e-mail :

```
curl -X POST http://localhost:8000/users -H "Authorization: Bearer <token of bossDemo>" -d "userName=bob&saveRole=standard&userMail=bob@example.org"
```

```json
{"user":"bob","mailed":true}
```

L’e-mail attend dans `temp/mails`, code compris, et `temp/logs/runner.log`
confirme qu’il est parti. Pour de vrais e-mails, remplis le premier compte
de `mailer.json` avec ton serveur SMTP, mets son mot de passe dans `.env`
(`NYU_MAIL_PASS`), et nomme ce compte dans `nativeDb.mailAcnt`.

Pas besoin de le faire à la main : avec la base d’utilisateurs native,
`php nyuRun install complete` demande s’il faut envoyer les e-mails
d’accès, puis ton serveur SMTP, l’expéditeur, le lien du front-end et si
les inscriptions sont ouvertes, et écrit le tout (le mot de passe va dans
`.env`, jamais dans `mailer.json`). Dans les deux cas, vérifie que le
compte envoie vraiment :

```
php nyuRun install testMail you@example.org
```

La commande te dit si l’e-mail est parti, ou pourquoi il n’est pas parti,
et ne change rien.

Une fois les e-mails en place, deux autres portes s’ouvrent :

- **Les mots de passe oubliés** marchent tout de suite : `auth/forgot`, puis
  `auth/recover`.
- **Les inscriptions** s’ouvrent avec
  `php nyuRun setting setValue accsConf nativeDb.signupOn true`. Chacune
  reste ensuite en attente de validation par un gestionnaire
  (`account approves`).

Règle `nativeDb.linkTmpl` sur la page de ton front-end qui reçoit un code
(`https://…/activate?code=%sentCode%`), et les e-mails contiendront un lien
cliquable.

### 4.5 Choisir la langue

Les e-mails partent dans la langue de chaque utilisateur, ou à défaut dans
la langue par défaut :

```
php nyuRun setting setValue coreConf DEF_LANG fr
```

nyuRuns livre ses e-mails en anglais et en français. Règle la langue d’un
utilisateur avec `account language ann fr`, ou laisse les utilisateurs
régler la leur (`auth/usrlang`).

### 4.6 Garder les secrets

`install complete` a mis les secrets dans `.env` et les clés des tokens dans
`conf/keys`. Ne les commite jamais. Pour créer de nouvelles clés :

```
php nyuRun setting mkSecret jwtToken
```

La commande demande avant de remplacer des clés en service, car de
nouvelles clés annulent tous les tokens signés avec les anciennes, et tout
le monde doit se reconnecter.

### 4.7 Journaux et environnement

| Réglage | Commande | Effet |
|---|---|---|
| ce qui est journalisé | `php nyuRun setting setValue coreConf LOG_MODE BASE` | `FULL` tout, `BASE` tout sauf le debug, `MINI` les erreurs, les avertissements et les notices |
| l’affichage des erreurs | `php nyuRun setting setValue coreConf ERR_SHOW FALSE` | jamais affichées en production, quoi que dise ce réglage |
| l’environnement | `php nyuRun setting setValue coreConf ENV_NAME PROD` | `DEVS`, `TEST`, `PREV`, `PROD` |

Pour lire un journal en ligne de commande :

```
php nyuRun showlog access --numLines=3
```

```
[2026-11-10 01:16] [NOTICE] Privileged login granted for user "bossDemo" (managers) from 127.0.0.1
[2026-11-10 01:16] [NOTICE] Privileged access granted for user "bossDemo" (managers) from 127.0.0.1 to users/create
[2026-11-10 01:16] [NOTICE] User "bob" created (standard) by user "bossDemo" from 127.0.0.1
```

### 4.8 Du fichier des utilisateurs à une base de données

Si tu as répondu `userFile` pendant l’installation, tes utilisateurs vivent
dans `conf/users.lst`. Pour les déplacer dans une base de données :

```
php nyuRun install makeConf
php nyuRun install moveUser nativeDb --yesToAll
```

La première commande crée `conf/database.json` à partir de son exemple. La
seconde demande où se trouve la base, crée la table des utilisateurs, et y
déplace chaque utilisateur, avec son mot de passe, son rôle et son secret à
deux facteurs. Elle bascule ensuite la connexion sur la base. Le fichier
des utilisateurs reste intact : tu peux donc y ramener la connexion en
réglant `authWith` et `authConf` sur `userFile` dans le bloc `loginSet` de
`access.json`. Garde seulement en tête que ce qui a changé dans la base
entre-temps ne sera pas dans le fichier.

---

## 5. Ta première application (run)

Place à `Notes` : une liste de notes que tout utilisateur connecté peut
lire et que seule l’équipe (le rôle `advanced`) peut écrire, avec chaque
note à sa propre URL et une page pour les navigateurs. Il suffit d’une
poignée de fichiers courts :

```
runs/Notes/
  notes.sql                 la table
  mods/Listing.php          la liste des notes
  mods/Single.php           une note
  Actions.php               ce que fait le run
  input/<action>.json       les entrées de chaque action
  pages/note.html           la page d’une note
  routes.rte                ses URL
test/data/NotesTest.php     son test
```

Le profil est `restapi` (4.1) et la base de données est `notes.sqlite`
(3.2).

### 5.1 La table

`runs/Notes/notes.sql` :

```sql
CREATE TABLE notes (
	id_note  INTEGER PRIMARY KEY AUTOINCREMENT,
	noteName VARCHAR(80) NOT NULL,
	noteText TEXT,
	noteUser VARCHAR(40),
	madeDate DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

L’identifiant est `id_` suivi du nom de la table au singulier. nyuRuns
donne à ses propres colonnes des noms d’exactement 10 caractères ; les
tiennes sont libres, et rien ne les vérifie : ici, elles en font 8. Crée la
table dans la base de données de ton projet :

```
php -r "(new PDO('sqlite:notes.sqlite'))->exec(file_get_contents('runs/Notes/notes.sql'));"
```

### 5.2 Les modèles

D’abord la liste des notes, `runs/Notes/mods/Listing.php` :

```php
<?php

namespace nyu\Runs\Notes\mods;

use nyu\Data\DBLst;

class Listing extends DBLst {

	/**
	 * Binds the list to the notes table.
	 */
	public function __construct(){
		parent::__construct( 'notes' );
	}
}
```

Puis une note seule, `runs/Notes/mods/Single.php` : la même chose, sauf
que `Single` étend `nyu\Data\DBRec`. Chaque fichier porte le nom de sa
classe. Les actions les obtiennent par leur nom, `getModelObject('listing')`
et `getModelObject('single')`, et les modèles trouvent seuls la clé et les
champs de la table. Un fichier nommé `ListingModel.php` serait trouvé lui
aussi : c’est l’ancienne forme, gardée en solution de repli.

### 5.3 Les actions

`runs/Notes/Actions.php` : chaque méthode publique est une action.

```php
<?php

namespace nyu\Runs\Notes;

use nyu\App\Actionset;

class Actions extends Actionset {

	protected $dfltAction = 'index';

	/**
	 * Lists the notes, the newest first: those whose name holds the 'name'
	 * input, 'limit' of them at most.
	 */
	public function index(): Void {
		$listModl = $this->modelStack->getModelObject('listing');
		$nameText = strval( $this->inputsRegr->getValueOrDflt('name', '') );
		if( $nameText!='' ){
			$listModl->setListFilters( Array('noteName~'=>'*'.$nameText.'*') );
		}
		$listModl->setOrderFields('^id_note');
		$listModl->setLimitOffset( $this->inputsRegr->getValueOrDflt('limit', 20) );
		$rowsList = $listModl->readRowsOfList();
		if( $rowsList===false ){
			$this->stopThisAction(500, 'Notes not read');
		}
		$this->statusCode = empty($rowsList) ? 204 : 200;
		$this->replyValue = empty($rowsList) ? null : $rowsList;
	}

	/**
	 * Shows one note.
	 *
	 * @param String $recordIdText Identifier of the note, from the path.
	 */
	public function view( String $recordIdText ): Void {
		$this->statusCode = 200;
		$this->replyValue = $this->loadNote($recordIdText)->loadRecordList($recordIdText);
	}

	/**
	 * Creates a note from the 'name' and 'text' inputs, written by the
	 * user signed in.
	 */
	public function create(): Void {
		$rcrdModl = $this->modelStack->getModelObject('single');
		$rcrdModl->setFieldsArray( Array(
			'noteName'=>$this->inputsRegr->getValueOrDflt('name'),
			'noteText'=>$this->inputsRegr->getValueOrDflt('text', ''),
			'noteUser'=>strval($this->accessRegr->readValueByKey('userName'))
		) );
		$savedKey = $rcrdModl->insertOrUpdate();
		if( $savedKey===false ){
			$this->stopThisAction(500, 'Note not saved');
		}
		$this->statusCode = 201;
		$this->replyValue = Array('id_note'=>$savedKey);
	}

	/**
	 * Changes the name or the text of a note, those of the inputs given.
	 *
	 * @param String $recordIdText Identifier of the note, from the path.
	 */
	public function update( String $recordIdText ): Void {
		$rcrdModl = $this->loadNote($recordIdText);
		$sentVals = Array();
		foreach( Array('name'=>'noteName', 'text'=>'noteText') as $oneKey => $oneFld ){
			$sentValu = $this->inputsRegr->getValueOrDflt($oneKey);
			if( $sentValu!==null && $sentValu!=='' ){
				$sentVals[$oneFld] = $sentValu;
			}
		}
		$rcrdModl->setFieldsArray($sentVals);
		if( $rcrdModl->insertOrUpdate()===false ){
			$this->stopThisAction(500, 'Note not saved');
		}
		$this->statusCode = 200;
		$this->replyValue = $rcrdModl->loadRecordList($recordIdText);
	}

	/**
	 * Removes a note.
	 *
	 * @param String $recordIdText Identifier of the note, from the path.
	 */
	public function remove( String $recordIdText ): Void {
		if( !$this->loadNote($recordIdText)->dropThisRecord() ){
			$this->stopThisAction(500, 'Note not removed');
		}
		$this->statusCode = 204;
	}

	/**
	 * Shows a note as a page, for a browser.
	 *
	 * @param String $recordIdText Identifier of the note, from the path.
	 */
	public function card( String $recordIdText ): Void {
		$rcrdVals = $this->loadNote($recordIdText)->loadRecordList($recordIdText);
		$this->servePageReply( 'note', Array('name'=>$rcrdVals['noteName'], 'text'=>$rcrdVals['noteText'], 'user'=>$rcrdVals['noteUser'], 'date'=>$rcrdVals['madeDate']) );
	}

	/**
	 * Gives the record of a note, or ends the action with a 404.
	 *
	 * @param String $recordIdText Identifier of the note.
	 * @return Object The record, loaded.
	 */
	protected function loadNote( String $recordIdText ): Object {
		$rcrdModl = $this->modelStack->getModelObject('single');
		if( empty($rcrdModl->loadRecordList($recordIdText)) ){
			$this->stopThisAction(404, 'Not Found');
		}
		return $rcrdModl;
	}
}
```

Quelques points à remarquer :

- Une action fixe son statut (`statusCode`) et ses données (`replyValue`),
  et le framework écrit la réponse.
- `stopThisAction()` arrête une action sur-le-champ, qu’elle soit appelée
  depuis l’action elle-même ou depuis une méthode d’aide. C’est ainsi que
  `loadNote()` répond 404 pour chaque action qui a besoin d’une note.
- `loadNote()` est protégée : ce n’est donc pas une action, et aucun client
  ne peut l’appeler.
- `noteName~` avec `*` de chaque côté demande une correspondance. Une valeur
  simple serait comparée telle quelle.
- Les noms suivent les conventions du projet : 8 caractères pour une
  variable locale, 12 pour un paramètre, 6 pour une variable de boucle
  (`one` ou `nth` suivi de l’élément : `oneFld`). `recordIdText` et
  `rowsList` sont les noms que l’exemple `Shows` utilise pour les mêmes
  choses, et `listModl`, `rcrdModl` disent qu’ils contiennent des modèles.
  L’audit vérifie aussi tes runs (5.9).

### 5.4 Les entrées

Chaque action déclare ce qu’elle accepte, dans `runs/Notes/input/` :

| Fichier | Contenu |
|---|---|
| `index.json` | `{ "fieldSet": { "name": "", "limit": "Integer" } }` |
| `create.json` | `{ "fieldSet": { "name!": "", "text": "" } }` |
| `update.json` | `{ "fieldSet": { "name": "", "text": "" } }` |
| `view.json`, `remove.json`, `card.json` | `{ "fieldSet": {} }` |

Seuls les champs listés arrivent jusqu’à l’action. `name!` est obligatoire :
une note envoyée sans nom est donc refusée (400) avant même que l’action ne
tourne. Un format vide garde la valeur exactement telle qu’elle a été
envoyée, tandis que `Integer` fait de `limit` un nombre. Les textes sont
enregistrés tels qu’envoyés et encodés là où ils sont affichés : par la page
(5.8), ou par ton front-end.

### 5.5 Les URL

`runs/Notes/routes.rte` relie chaque méthode de chaque URL à son action :

```json
{
	"" : { "pointsTo": {
		"GET":  { "doAction": "index" },
		"POST": { "doAction": "create" }
	} },
	"$id" : { "pointsTo": {
		"GET":    { "doAction": "view" },
		"PATCH":  { "doAction": "update" },
		"DELETE": { "doAction": "remove" }
	} },
	"/note/$id" : { "pointsTo": { "doAction": "card" } }
}
```

Les routes d’un run sont montées sous son nom en minuscules : `""` devient
donc `/notes`, et `$id` devient `/notes/$id`. Un chemin qui commence par une
barre oblique part au contraire de la racine du site : `/note/$id`. Chaque
cible nomme l’une des actions du run avec `doAction` seul, et `$id` capture
cette partie du chemin pour la donner à l’action en paramètre.

Dès qu’un run a son propre fichier de routes, ce fichier est la seule porte
d’entrée : `notes/view/1` reçoit un 404. Le profil peut monter un run
ailleurs (`"runMount": { "notes": "api/notes" }` dans son bloc `dispatch`),
ou cesser de le servir (`false`).

### 5.6 Les rôles

Dans `conf/presets/restapi.rps`, ouvre la route de la page à tout le monde,
et garde l’écriture pour l’équipe :

```json
"openList": ["auth/login", "auth/active", "auth/signup", "auth/forgot", "auth/recover", "/.well-known/jwks.json", "notes/card"],
"minRoles": { "users/*": "delegate", "users/create": "managers", "users/remove": "managers", "users/role": "managers", "users/setmail": "managers", "users/langs": "managers", "users/lock": "managers", "users/unlock": "managers", "users/reset": "managers", "notes/create": "advanced", "notes/update": "advanced", "notes/remove": "advanced" }
```

Nommer la page par son action la garde ouverte où que le run soit monté.
Vérifie-le :

```
php nyuRun setting fullList restapi
```

```
/notes                  GET      toAction  Notes/index     Notes  logged
/notes                  POST     toAction  Notes/create    Notes  advanced
/notes/$id              GET      toAction  Notes/view      Notes  logged
/notes/$id              PATCH    toAction  Notes/update    Notes  advanced
/notes/$id              DELETE   toAction  Notes/remove    Notes  advanced
/note/$id               GET      toAction  Notes/card      Notes  open
```

### 5.7 L’essayer

Connecte-toi en tant que `userDemo` et en tant que `powrDemo` (3.4), et
garde les deux tokens. Ajoute ensuite `-H "Authorization: Bearer <token>"`
à chaque appel :

| Requête | En tant que | Réponse |
|---|---|---|
| `GET /notes` | userDemo | `204` : pas encore de note |
| `POST /notes` avec `name=Courses` | userDemo | `404 {"error":"Not Found","code":404}` : l’écriture est réservée à l’équipe |
| `POST /notes` avec `name=Courses&text=Lait, pain` | powrDemo | `201 {"id_note":1}` |
| `POST /notes` avec `text=Sans nom` | powrDemo | `400 {"error":"Missing or invalid input: name","code":400}` |
| `GET /notes` | userDemo | `200 [{"id_note":1,"noteName":"Courses","noteText":"Lait, pain","noteUser":"powrDemo","madeDate":"2026-11-10 01:14:55"}]` |
| `GET /notes?name=cour` | userDemo | les notes dont le nom contient « cour » |
| `GET /notes/1` | userDemo | `200` la note |
| `PATCH /notes/1` avec `text=Lait, pain, œufs` | powrDemo | `200` la note, modifiée |
| `DELETE /notes/1` | powrDemo | `204` |
| `GET /notes/1` | userDemo | `404` |
| `GET /notes` | personne | `401 {"error":"Missing Bearer Authorization header","code":401}` |

Par exemple :

```
curl -X POST http://localhost:8000/notes -H "Authorization: Bearer <token of powrDemo>" -d "name=Courses&text=Lait, pain"
curl -X PATCH http://localhost:8000/notes/1 -H "Authorization: Bearer <token of powrDemo>" -d "text=Lait, pain, œufs"
```

Quand un utilisateur sans le rôle est refusé, on lui répond que l’URL
n’existe pas, comme si elle n’existait vraiment pas. Ton front-end ne doit
donc pas proposer ce que le rôle d’un utilisateur ne peut pas atteindre.

### 5.8 Une page

`runs/Notes/pages/note.html` :

```html
<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>%name%</title></head>
<body>
	<h1>%name%</h1>
	<p>%text%</p>
	<p><small>Écrit par %user%, le %date%</small></p>
</body>
</html>
```

L’action `card` remplit chaque emplacement `%name%` grâce à
`servePageReply()`, et chaque valeur est encodée en HTML. Tu as supprimé la
note 1 à la fin de 5.7 : crée-en donc d’abord une nouvelle, puis ouvre
`http://localhost:8000/note/<son numéro>` dans un navigateur. Pas besoin de
te connecter : cette route est ouverte. Une note nommée
`Salle "B" <à midi>` s’affiche exactement comme ça, ses caractères encodés
une fois et une seule.

### 5.9 Un test à lui

`test/data/NotesTest.php` fait tourner les actions sur une base de données
à lui. Un run reçoit un `RunContext`, ce dont il a besoin pour une requête,
et `treatAskedActn()` lance l’action :

```php
<?php

// runs/Notes : its actions, on a SQLite database of their own made from
// runs/Notes/notes.sql.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Notes', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\App\ModelStack;
use nyu\App\RunContext;
use nyu\Tools\Registry;

$basePath = inTemporaryDir('notes.sqlite');
if( is_file($basePath) ){
	unlink($basePath);
}
(new PDO('sqlite:'.$basePath))->exec( strval(file_get_contents(RUN_DIR.DS.'Notes'.DS.'notes.sql')) );
switchToSqlite($basePath);
require RUN_DIR.DS.'Notes'.DS.'Actions.php';

/**
 * Runs an action of Notes, as a user signed in.
 *
 * @param String  $actionToTest Action to run.
 * @param ?String $recordIdText The identifier of the note, when the action takes one.
 * @param Array   $inputsToSend Inputs of the action.
 * @return Array The status and the output of the action.
 */
function noteCall( String $actionToTest, ?String $recordIdText=null, Array $inputsToSend=Array() ): Array {
	$runCntxt = new RunContext( $actionToTest, $recordIdText, new Registry($inputsToSend), new Registry(Array('loggedIn'=>true, 'userName'=>'tester')), null, null, null, new ModelStack('Notes'), 'Notes', RUN_DIR.DS.'Notes' );
	$actnInst = new nyu\Runs\Notes\Actions($runCntxt);
	$actnInst->treatAskedActn();
	return Array($actnInst->statusCode, $actnInst->replyValue);
}

checkIdentical('an empty list', noteCall('index'), Array(204, null));
checkIdentical('a note is created', noteCall('create', null, Array('name'=>'Shopping', 'text'=>'Milk'))[0], 201);
checkIdentical('it is listed, with its author', array_column(noteCall('index')[1], 'noteUser', 'noteName'), Array('Shopping'=>'tester'));
checkIdentical('it is changed', noteCall('update', '1', Array('text'=>'Milk, eggs'))[1]['noteText'] ?? null, 'Milk, eggs');
checkIdentical('it is removed, then not found', Array(noteCall('remove', '1')[0], noteCall('view', '1')[0]), Array(204, 404));

finishChecking();
```

Lance-le avec l’audit des conventions :

```
php test/run.php Notes audit
```

```
17 checks passed, 0 failed
```

### 5.10 Ce que tu as construit

Tu as maintenant un service avec une ressource à sa propre URL, lue par
tous les utilisateurs et écrite par l’équipe. Chaque méthode a son action,
chaque action déclare ses entrées, et il y a en plus une page pour
navigateur et un test. Et ensuite :

- ajouter un champ : une colonne dans la table, et l’entrée qui la remplit ;
- relier les notes à une autre table avec une colonne qui porte son nom au
  singulier suivi de `_id` (comme `user_id`), et la déclarer comme enfant de
  l’enregistrement (Spécifications techniques, 10.3) ;
- envoyer un e-mail chaque fois qu’une note est écrite, grâce à un compte
  de `mailer.json` ;
- livrer le projet sur un serveur (chapitre 8).

---

## 6. Structure de ton projet

Voici ton projet une fois le chapitre 5 terminé :

```
notes/
  www/index.php               le seul fichier que le serveur web peut atteindre
  nyuRun                      la ligne de commande
  runs/
    Notes/                    ton run, avec ses propres routes (chapitre 5)
    Auth/  Users/  Shows/     les runs livrés avec nyuRuns : connexion, utilisateurs, un exemple
    Shared/                   ce que tes runs partagent : modèles, vérifications d’identifiants
  conf/
    system.ini                journaux, erreurs, caches, limites
    access.json               comment les utilisateurs se connectent   (à toi, non commité)
    database.json             où se trouve la base de données          (à toi, non commité)
    mailer.json               les comptes de messagerie                (à toi, non commité)
    presets/restapi.rps       le profil du service : ses routes ouvertes et ses rôles
    routes/                   les routes de tout le site
    keys/                     les clés des tokens                      (à toi, non commité)
  .env                        les secrets                              (à toi, non commité)
  notes.sqlite                la base de données du tutoriel
  temp/                       journaux, caches, e-mails écrits dans des fichiers
  test/data/NotesTest.php     ton test
  src/                        nyuRuns lui-même
  vendor/                     les bibliothèques
```

Ce que tu écris va dans `runs/`, le profil et `test/`, plus `conf/routes`
pour les routes de tout le site. Ce qu’apporte nyuRuns se trouve dans
`src/` et dans les runs livrés avec lui. Tout ce qui appartient à une
machine (les fichiers marqués « à toi », plus `temp/`) y reste : git
l’ignore, et la copie construite pour un serveur le laisse de côté. Rien de
`conf/keys` ne part avec elle, pas même une clé publique, car le serveur
crée les siennes. Si ton serveur vérifie des tokens émis par un autre,
nomme la clé publique de ce serveur dans un mode de projet (ses chemins
`addPaths`, voir `bundles makeMode`) pour l’emporter.

`notes.sqlite` est lui aussi laissé hors de cette copie, mais git ne le
sait pas. Ajoute-le à `.gitignore`, ou sors la base de données du projet et
donne son chemin complet dans `conf/database.json`.

L’arborescence complète, et ce qu’un bundle en garde, se trouvent dans les
[Spécifications techniques, chapitre 11](techspecs.md#11-structure-des-dossiers).

---

## 7. Référence du code

Chaque classe et chaque méthode de nyuRuns a un docblock qui dit ce
qu’elle fait, ce qu’elle prend et ce qu’elle renvoie. La référence du code
est un site construit à partir de ces docblocks : une page par classe, ses
méthodes avec leurs paramètres, et une recherche. Comme le code, elle est
en anglais.

### 7.1 La construire

La référence est construite par phpDocumentor 3. Ce n’est pas une des
bibliothèques d’un projet : tu la récupères toi-même. Télécharge son
`.phar` sur le site de phpDocumentor et place-le dans `temp/tools`. Puis,
depuis la racine du projet :

```
php -d memory_limit=1G temp/tools/phpDocumentor.phar run
```

Il lit `phpdoc.dist.xml`, qui couvre les classes de `src` avec leurs
méthodes publiques et protégées, et écrit la référence dans
`docs/online/reference`, que git ne suit pas. phpDocumentor a besoin de
l’extension `mbstring`.

Avec PowerShell, `pwsh .phpdoc/build.ps1` construit toute la documentation
d’un coup. D’abord la référence, puis les pages des documents que tu es en
train de lire, dans chaque langue où ils sont écrits, rendues avec
l’apparence du projet par l’analyseur Markdown et l’éditeur HTML de nyuRuns
lui-même. Les fichiers `.md` restent la source, et `docs/online` est
construit à partir d’eux : un dossier par langue, la référence à côté, et
un `index.html` qui envoie le visiteur vers la langue de son navigateur.

### 7.2 La lire

Ouvre `docs/online/index.html` dans un navigateur, ou sers le dossier
(`php -S localhost:8790 -t docs/online`). La référence du code est la
dernière entrée du menu, `docs/online/reference/index.html`, en anglais
dans toutes les langues. Les classes y sont rangées par espace de noms, et
le chapitre 5 des Spécifications techniques dit ce que contient chacune.
Celles dont tu te serviras le plus dans un run sont :

| Classe | Pour |
|---|---|
| `nyu\App\Actionset` | ce que reçoit une action, et ses méthodes d’aide (`servePageReply()`, `stopThisAction()`, `needsActionRle()`, …) |
| `nyu\Data\DBRec`, `nyu\Data\DBLst` | un enregistrement et une liste d’une table |
| `nyu\Data\DBStore\Filter` | la façon d’écrire un filtre |
| `nyu\Tools\Variable` | les formats des entrées (`OutText`, `Integer`, `EmlAddr`, …) |
| `nyu\Tools\Request` | la requête : en-têtes, cookies, fichiers, client |
| `nyu\Data\Mail\Mailer` | l’envoi d’un e-mail depuis une action |

### 7.3 Écrire pour elle

Les docblocks de tes propres runs suivent la même règle que ceux du
framework : chaque méthode publique en a un, avec `@param` et `@return`. Ils
n’apparaissent pas dans la référence, qui ne couvre que `src`. Pour les y
inclure, ajoute `<path>runs</path>` à côté de `<path>src</path>` dans
`phpdoc.dist.xml`.

---

## 8. Recommandations

Une liste à cocher pour le jour où ton service passe en ligne sur un vrai
serveur. Chaque point renvoie à l’endroit où il est traité en détail : les
conseils dans les
[Spécifications techniques, chapitre 2](techspecs.md#2-lignes-directrices-et-recommandations),
la conception dans les
[Spécifications fonctionnelles, chapitre 8](funcspecs.md#8-recommandations-de-développement),
et le serveur dans [Hébergement](hosting.md).

### 8.1 Avant de livrer

- [ ] La suite de tests passe, tests de tes runs et audit compris :
      `php test/run.php`.
- [ ] Chaque action a son `input/<action>.json` et chaque route son rôle
      minimum : `php nyuRun setting fullList <profile>` ne montre rien
      d’ouvert qui ne devrait pas l’être.
- [ ] Ton front-end cache ce que le rôle d’un utilisateur ne peut pas
      atteindre, puisque le service y répond « introuvable ».

### 8.2 Livrer une copie, pas le dossier

- [ ] Construis la copie de production : `php nyuRun bundles final --archived`.
      Elle ne contient ni secrets, ni réglages locaux, ni clé privée, ni
      tests. Son `system.ini` est réglé pour la production (`PROD`, erreurs
      masquées, journaux `MINI`, cache de configuration activé), et ses
      classes sont cartographiées quand Composer est disponible.
- [ ] Installe-la sur le serveur avec `php nyuRun install complete` : les
      secrets et les clés y sont créés et n’en sortent jamais.
- [ ] Rends `.env` lisible par son seul propriétaire (`chmod 600 .env`).

### 8.3 Le serveur

- [ ] Le serveur web sert `www` et rien d’autre, et envoie chaque URL à
      `www/index.php` (Hébergement, « Serveur web »).
- [ ] HTTPS est assuré par le serveur web ou par un proxy placé devant lui.
      Derrière un proxy, `PRX_LIST` nomme ce proxy, et lui seul.
- [ ] PHP est une version avec ses derniers correctifs de sécurité, et
      `php.ini` contient `session.upload_progress.enabled = 0`.
- [ ] L’utilisateur sous lequel tourne le serveur web peut écrire dans
      `temp/`, et nulle part ailleurs.

### 8.4 Les réglages

- [ ] Un vrai compte de messagerie se trouve dans `mailer.json`, avec son
      mot de passe dans `.env`, et `nativeDb.linkTmpl` est une URL `https`
      de ton front-end.
- [ ] `php nyuRun install testMail <your address>` t’a bien envoyé un
      e-mail.
- [ ] La limite de tentatives reste activée (`tryLimit` dans `access.json`).
- [ ] Tu as au moins deux gestionnaires, car un gestionnaire ne peut ni
      réinitialiser, ni bloquer, ni supprimer son propre compte.
- [ ] Après avoir modifié un fichier de `conf/` à la main, tu as lancé
      `php nyuRun setting clrCache`. Avec le cache activé, le service
      continue de lire les anciens réglages tant que tu ne l’as pas fait.
      `install checkAll` prévient quand un cache est plus ancien que ses
      fichiers.

### 8.5 Avant la mise en ligne, et après

- [ ] `php nyuRun install checkAll` se termine par `fail: 0`. En cas
      d’échec, la commande sort avec un code d’erreur : un script de
      déploiement s’arrête donc tout de suite.
- [ ] Sauvegarde ce qui ne peut pas être reconstruit : la base de données,
      `.env`, `conf/keys` et tes fichiers locaux de `conf/`.
- [ ] Lis les journaux de temps en temps (`php nyuRun showlog access`), et
      fais le ménage dans les anciens
      (`php nyuRun cleanup cleanDir logFiles --keepDays=30`).
- [ ] Pour mettre à jour, construis une nouvelle copie et pose-la sur le
      code. `.env`, les clés, tes fichiers locaux de `conf/` et les données
      restent tels quels ; lance ensuite `php nyuRun setting clrCache`. La
      copie apporte son propre `system.ini`, ses profils et ses routes : un
      réglage que tu as changé sur le serveur doit donc être changé à
      nouveau, ou gardé dans un mode de projet (`bundles makeMode`).

---

## A. Dépannage

### A.1 Où regarder

1. **La réponse.** En développement (`ERR_SHOW = TRUE`, et jamais en
   `PROD`), une erreur du code apparaît directement dans la réponse, avec
   son fichier et sa ligne.
2. **Les journaux** dans `temp/logs`, un fichier par sujet : `error.log`
   (les erreurs du code), `access.log` (les connexions, les refus et leur
   raison), `runner.log` (ce qui n’a pas été trouvé et pourquoi, les
   e-mails, les suppressions, les envois de fichiers), `shell.log` (ce que
   les commandes ont changé). `php nyuRun showlog <channel>` montre les
   dernières lignes.
3. **`php nyuRun install checkAll`** pour les réglages (A.3).
4. **`php nyuRun setting fullList <profile>`** pour savoir qui peut
   atteindre quoi.

### A.2 Les réponses qui surprennent

| Tu obtiens | Pourquoi | Que faire |
|---|---|---|
| `404` sur une action que tu viens d’écrire | le client n’en connaît jamais la raison. Peut-être que l’action n’est pas une méthode publique d’`Actions`, que le dossier ou la classe de son run est mal nommé, que son run a son propre fichier de routes et que seules ces routes l’atteignent, ou que l’utilisateur n’a pas le rôle | regarde `runner.log` (`Not found: "notes/view": …`) ou `access.log` (`role … needed`) |
| `500 Internal Server Error` sur l’URL d’un run sans action | l’action par défaut (`$dfltAction`) ne nomme aucune action, ou n’est pas définie | `runner.log` la nomme |
| `500 Access misconfigured` sur chaque requête | un réglage d’accès ne peut pas s’appliquer : un rôle inconnu dans `minRoles`, une route à la fois ouverte et dotée d’un rôle, un porteur et une vérification qui ne vont pas ensemble, ou une route de connexion qui n’est pas un chemin du site | `access.log` dit lequel (`Access misconfigured: …`) |
| `500` qui nomme `Input misconfigured` | un `input/<action>.json` qui n’est pas `{ "fieldSet": … }`, ou qui nomme un format inexistant | corrige le fichier ; les formats viennent de `nyu\Tools\Variable` |
| `400 Missing or invalid input: …` | un champ obligatoire (`name!`) manquait ou était vide | envoie-le, et vérifie son nom |
| `401 Missing Bearer Authorization header` | le profil veut un token | connecte-toi et envoie `Authorization: Bearer <token>` |
| `401 Invalid credentials` avec le bon mot de passe | le compte n’est pas actif (`awaiting`, `inReview` ou `disabled`) | `php nyuRun account fullList` montre son statut |
| `400 Password sent in the query string` | le mot de passe est arrivé dans l’URL | envoie-le dans le corps, en `POST` |
| `405 Method Not Allowed` | la route n’accepte pas cette méthode | son en-tête `Allow` liste celles qu’elle accepte |
| `413 Payload Too Large` | le corps dépasse `REQ_SIZE`, ou contient plus de parties que `REQ_PART` | augmente-les dans `system.ini` si c’est voulu |
| `429 Too many attempts` | cette adresse a envoyé trop de mauvais identifiants | attends `Retry-After` secondes, ou lance `php nyuRun cleanup freeAddr <address>` |
| `501 Tokens are issued by another server` | le profil ne fait que vérifier des tokens signés ailleurs | connecte-toi sur le service qui les émet |
| un changement de `conf/` ne se voit pas | le cache de configuration est activé (`CNF_CACH`) | `php nyuRun setting clrCache` |
| `"mailed": true`, mais rien n’arrive | l’e-mail a échoué après le départ de la réponse | `runner.log` dit pourquoi ; avec un compte `emlFiles`, regarde dans `temp/mails` |
| chaque requête : `Class "nyu\Runner" not found` | le serveur intégré de PHP a été lancé hors de `www` | lance-le depuis `www` (3.3) |

### A.3 Les lignes de `install checkAll`

Un `fail` signifie que le service ne peut pas servir, ou qu’il est exposé.
Un `warn` mérite un coup d’œil.

| Ligne | Ce qu’elle dit | Que faire |
|---|---|---|
| `php` | plus ancien que 8.2 | mets PHP à jour |
| `php patch`, `upload progress` | il manque à PHP ses derniers correctifs, ou la progression des envois est activée | mets PHP à jour ; mets `session.upload_progress.enabled = 0` dans `php.ini` |
| `extension json`, `extensions` | une extension obligatoire ou facultative manque | voir A.4 |
| `folder temp/…` | absent ou non accessible en écriture | laisse l’utilisateur du serveur web écrire dans `temp/` |
| `access` | `conf/access.json` manque | `php nyuRun install makeConf` |
| `access login` | aucune connexion n’est réglée, ou elle nomme une vérification ou un bloc qui n’existe pas | corrige le bloc `loginSet` de `access.json` |
| `preset <name>` | le profil ne se charge pas (une clé inconnue, un rôle qui n’en est pas un, une route ouverte dotée d’un rôle) | la ligne dit laquelle ; corrige le fichier `.rps` |
| `entry index` | le point d’entrée démarre un profil qui ne demande aucun identifiant | nomme un profil qui vérifie l’accès (4.1), ou mets `"isPublic": true` dans son bloc `security` si le site doit être ouvert |
| `origins <profile>` | n’importe quel site peut appeler l’API depuis un navigateur | liste les origines autorisées sous `corsList` |
| `route clash`, `route shadow` | deux runs déclarent le même chemin, ou une route du site cache la route d’un run | renomme l’un des chemins, ou change le montage d’un run |
| `access route` | `openList` ou `minRoles` nomme une route qui n’existe pas | corrige l’entrée dans le profil |
| `token claims …` | les tokens ne portent ni émetteur ni audience : ils ne sont donc pas liés à ce projet | `php nyuRun setting setValue accsConf jwtToken.issuerId …` et `.audience` |
| `login marks` | aucune clé pour les marques de connexion : mettre un compte en attente bloque donc aussi son propriétaire | `php nyuRun setting mkSecret markSecr` |
| `file rights` | les droits des fichiers secrets ne peuvent pas être vérifiés (Windows) | limite `conf` et `temp` au compte du serveur web (`icacls`) |
| `secret …`, `token keys …`, `2FA key …` | un secret ou une clé manque, est trop court, n’est pas une clé valide, ou une clé publique ne correspond pas à sa clé privée | `php nyuRun setting mkSecret jwtToken` (ou `keysList`, `tfaCrypt`) |
| `database …` | la base de données ne répond pas, ou son extension n’est pas chargée | vérifie `conf/database.json`, le serveur, et A.4 |
| `users` | la table ou le fichier des utilisateurs manque | `php nyuRun install userBase` |
| `access mails …`, `access link …`, `access codes …` | le compte de messagerie, le lien ou la longueur des codes n’est pas valide | voir 4.4 |
| `sign-ups …` | les inscriptions sont ouvertes, mais aucun e-mail ne peut les confirmer | nomme un compte de messagerie dans `nativeDb.mailAcnt`, ou ferme les inscriptions |
| `config cache` | le cache est plus ancien qu’un fichier modifié depuis | `php nyuRun setting clrCache` |
| `table cache` | `TBL_CACH` est activé sans `CNF_CACH` | active les deux, ou aucun |
| `error level` | `ERR_LEVL` contient autre chose qu’un niveau d’erreur | utilise des noms `E_*` (`E_ALL & ~E_DEPRECATED`, entre guillemets) |
| `mail language` | `DEF_LANG` n’est pas une étiquette de langue | `en`, `fr`, `fr-ch`, … |
| `proxies` | `PRX_LIST` fait confiance à toutes les adresses | ne liste que tes proxys |
| `.env`, `key rights …` | d’autres utilisateurs du système peuvent les lire | `chmod 600` |
| `cookie …`, `origins …` | un cookie de session sur du HTTP simple en production, ou tous les sites autorisés à appeler | mets `secureOn` à true, et liste les origines (`corsList`) |
| `limits` | la limite de tentatives est désactivée | donne un nombre à `tryLimit.maxTries` |
| `requests` | `REQ_SIZE` vaut 0 : un corps de n’importe quelle taille est donc lu | donne-lui une taille |

### A.4 Une extension manquante

| Il manque | Ce que tu vois |
|---|---|
| `json` | rien ne reçoit de réponse, et `install checkAll` échoue tout de suite |
| `dom`, `iconv`, `zlib`, `mbstring` | `composer install` refuse d’installer les bibliothèques, et les documents PDF et HTML ne peuvent pas être lus |
| `openssl` | les tokens RS256 ne peuvent pas être créés (`token keys …: RS256 needs the openssl extension`), et les secrets à deux facteurs ne peuvent pas être chiffrés |
| `pdo_sqlite`, `pdo_mysql`, `pdo_pgsql` | `database …: the extension … is not loaded` ; sans `pdo_sqlite`, les tests `data` et `http` sont sautés |
| `gd`, `imagick`, `zip`, `exif` | les images ne peuvent pas être modifiées, le SVG n’est pas dessiné, et les documents bureautiques et les détails des photos ne peuvent pas être lus ; `install checkAll` les liste sous `extensions` |

Active une extension dans `php.ini` (`extension=openssl`) et vérifie-la
avec `php -m`. Un PHP en ligne de commande qui ne charge aucun `php.ini`
peut en recevoir un grâce à la variable d’environnement `PHPRC`.

---
