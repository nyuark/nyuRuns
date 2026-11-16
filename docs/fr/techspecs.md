# nyuRuns — Spécifications techniques

Version 1.0

## Sommaire

1. [Introduction](#1-introduction)
2. [Lignes directrices et recommandations](#2-lignes-directrices-et-recommandations)
3. [Configuration requise](#3-configuration-requise)
4. [Le cycle de vie d’une requête](#4-le-cycle-de-vie-dune-requête)
5. [Composants](#5-composants)
6. [Fichiers de configuration](#6-fichiers-de-configuration)
7. [Ligne de commande](#7-ligne-de-commande)
8. [Construction des runs (applications)](#8-construction-des-runs-applications)
9. [Sécurité](#9-sécurité)
10. [Couche de données](#10-couche-de-données)
11. [Structure des dossiers](#11-structure-des-dossiers)
12. [Conventions de code](#12-conventions-de-code)
13. [Tests](#13-tests)

---

## 1. Introduction

### 1.1 Ce qu’est nyuRuns

nyuRuns est un micro-framework PHP pour construire des services d’API
RESTful : le back-end d’une application, de la requête HTTP qu’il reçoit à
la réponse qu’il envoie.

L’idée, c’est que les développeurs écrivent ce que fait leur application,
et laissent au framework le traitement de la requête. Pour chaque requête,
nyuRuns trouve la route, vérifie qui demande et ce qu’il a le droit de
faire, ne garde que les entrées que déclare l’action (chacune dans son
format), appelle l’action, et écrit la réponse dans le format demandé,
erreurs comprises. Autour de ça, il apporte ce dont la plupart des services
ont besoin : les bases de données à travers des modèles, des documents lus
et écrits dans de nombreux formats, les images, les sons, les e-mails, les
journaux, et une ligne de commande pour installer, configurer, tester et
livrer un projet.

Quelques principes le façonnent :

- **MDD** (le développement dirigé par les modèles) : une application
  travaille à travers des modèles de ses données, des enregistrements et
  des listes, plutôt qu’à travers des requêtes.
- **KISS** (rester simple) : rien n’est chargé avant d’être nécessaire, et
  une première installation n’a besoin d’aucune base de données.
- **REST** : les ressources sont atteintes par leur chemin, la méthode dit
  ce qui est fait, et le statut dit comment ça s’est passé.
- **CRUDL** : créer, lire, modifier, supprimer et lister, les opérations
  que reçoit chaque source de données.

### 1.2 À qui s’adresse ce document

Il est écrit pour les développeurs : ceux qui construisent des applications
sur nyuRuns, et ceux qui travaillent sur nyuRuns lui-même. Il décrit
comment le framework est construit : ses composants, les fichiers qui le
configurent, et ses conventions.

Les autres documents couvrent le reste :

| Document | Couvre | Pour |
|---|---|---|
| Exigences | ce que nyuRuns doit atteindre, et pourquoi | les développeurs, les analystes, les responsables produit |
| Spécifications fonctionnelles | ce que fait nyuRuns, et comment se déroulent ses processus | les développeurs, les analystes |
| Guide de l’utilisateur | l’installer et construire une première application | les développeurs |
| Référence du code (en anglais) | chaque classe et chaque méthode, générées à partir du code | les développeurs |
| [Hébergement](hosting.md) | ce que doit fournir le serveur, et comment le mettre en place | la personne qui héberge nyuRuns |

Quand un sujet est couvert en entier ailleurs, ce document y renvoie au
lieu de le répéter.

### 1.3 Les mots de ce document

- **Run** : une application construite sur nyuRuns, dans un dossier de
  `runs/`.
- **Action** : ce que fait un run pour une requête, une méthode publique
  de son `Actions.php`, atteinte par un chemin comme `shows/view/12` ou par
  une route que déclare le run.
- **Profil** (« preset » dans le code) : un profil du framework (comment
  les requêtes sont vérifiées, routées et reçoivent leur réponse), choisi
  pour chaque requête par la carte des profils servis
  (`conf/doorways.lst`).
- **Base** : là où les données sont gardées : une base de données, ou un
  fichier des utilisateurs.
- **Bundle** : une copie d’un projet faite pour un environnement.
- Les noms écrits `comme ceci` sont des noms du code, de la configuration
  ou de la ligne de commande, exactement tels qu’ils y sont écrits.

### 1.4 Ce que contient la version 1.0

**Requêtes et réponses.** Tout le cycle de vie, du point d’entrée à la
réponse. Les routes sont trouvées à partir du chemin, ou déclarées par le
site et par chaque run, et peuvent mener à une action, une redirection, un
fichier ou un dossier statique, ou une page HTML. La définition de chaque
action garde et met en forme ses entrées, et un champ obligatoire manquant
reçoit un 400. Les réponses arrivent en JSON, YAML, XML, CSV, HTML ou en
sortie brute. Les erreurs sont écrites dans le format de la réponse,
journalisées, et jamais détaillées en production.

**Configuration.** Des profils, chacun un profil du framework ; un fichier
système pour les journaux, les erreurs, les caches, les proxys et les
limites ; des secrets gardés dans un fichier d’environnement, à l’écart de
la configuration.

**Accès.**
- Des façons de porter une identité : l’authentification Basic, une clé
  d’API, un token signé, une session PHP, un certificat client.
- Des façons de la vérifier : un fichier de mots de passe, des clés d’API,
  une table des utilisateurs, un fichier des utilisateurs, des tokens
  d’appareils, des clés d’application, ou une vérification qu’apporte le
  projet.
- Des rôles, et une limite de mauvaises tentatives par adresse et par
  compte.
- Des codes à deux facteurs, depuis une app d’authentification ou par
  e-mail.
- Des tokens signés avec une paire de clés, liés à un émetteur et à une
  audience une fois ceux-ci réglés, avec leurs clés publiques publiées
  (`/.well-known/jwks.json`).
- Des utilisateurs gérés par une application ou en ligne de commande, des
  inscriptions validées par un gestionnaire, et des mots de passe
  réinitialisés par les utilisateurs eux-mêmes.

**Données.** Des bases de données nommées sur MySQL, MariaDB, PostgreSQL,
SQLite et MongoDB ; des enregistrements et des listes, avec des filtres
écrits de la même façon pour toutes ; les transactions ; des valeurs
binaires lues et envoyées morceau par morceau ; des identifiants qui se
trient par date (UUID v7).

**Documents et médias.** La lecture et l’écriture de documents texte,
Markdown, HTML, XML, JSON, YAML, CSV, iCalendar, PDF, Word, Excel,
OpenDocument et ePub ; les images, les dessins et les images contenues dans
les documents ; les sons, coupés et convertis par ffmpeg ; les QR codes.

**E-mails.** Des comptes d’e-mail qui envoient par SMTP, par PHPMailer, ou
dans des fichiers ; les e-mails d’accès (activation, inscription,
réinitialisation, codes de connexion), écrits à partir de gabarits dans la
langue de chaque utilisateur.

**Ligne de commande.** `php nyuRun`, pour installer un projet sur son
serveur, changer sa configuration, lancer les tests, nettoyer ce qu’il
laisse derrière lui, gérer ses utilisateurs, ses appareils et ses clés
d’application, et en construire une copie pour chaque environnement.

**Qualité.** Une suite de tests d’environ 4 500 vérifications, lancée sur
SQLite et sur de vrais serveurs MySQL, MariaDB, PostgreSQL et MongoDB, et
un audit des conventions de code.

### 1.5 Ce qui reste pour plus tard

**Version 1.1 :**
- les connecteurs SSO et LDAP, et un serveur d’authentification ;
- les codes par SMS comme second facteur, et une connexion approuvée depuis
  le téléphone ;
- les certificats clients présentés à travers un proxy, un profil pour
  eux, et l’épinglage des certificats ;
- les limites par route ;
- d’autres sources d’entrées que la requête : des fichiers, des
  enregistrements, d’autres API, la session, un flux ;
- les sockets ;
- plusieurs jointures dans une même liste, et l’encodage des entrées ;
- la lecture du texte enrichi et du code source, de l’écriture manuscrite
  et du texte scanné ;
- le mixage des sons : forme d’onde, volume, fondus, tempo.

**Un projet séparé, nom de code nyuTron :** un assistant visuel autour de
`makerun`, un assistant d’installation, un gestionnaire des réglages
avancés, et des kits de données prêts à l’emploi (membres, clients,
commandes, tâches, projets). Ce sont des outils autour de nyuRuns, pas
des parties de nyuRuns.

### 1.6 Là où la 1.0 diffère de la première vision

La première description de nyuRuns, dans ses Exigences, promettait par
endroits plus que ce que livre la 1.0. Ce qui a changé :

- **Le CRUDL prêt à l’emploi.** Les modèles donnent à chaque source de
  données ses opérations CRUDL, mais un run déclare toujours ses actions :
  aucun point d’accès ne sert une table par la seule configuration.
  `makerun` écrit plutôt ces actions à partir d’une table SQL (7.10).
- **Les fichiers comme sources de données.** Une action lit et écrit des
  fichiers comme des documents. Les utiliser comme source d’entrées
  configurée, à côté de la requête, est laissé à la version 1.1.
- **Les sockets.** Ils figuraient d’abord parmi les gestionnaires de
  données, et sont laissés à la version 1.1. Les grosses données sont tout
  de même lues et envoyées morceau par morceau.
- **Le cache.** Les fichiers statiques partent avec leur version et leur
  date, et un client qui en a déjà un reçoit un 304. Les réponses de
  données ne sont pas mises en cache : les profils les envoient avec des
  en-têtes no-cache. Ce qui est mis en cache à la place, c’est ce que le
  framework lit à chaque requête : la configuration, les routes et les
  descriptions des tables (6.2, `CNF_CACH`, `TBL_CACH`).

---

## 2. Lignes directrices et recommandations

Ce qu’un développeur doit faire, et éviter, quand il construit et livre un
projet sur nyuRuns. Ce que doit fournir le serveur lui-même (le serveur
web, le proxy inverse, TLS, `php.ini`) est décrit dans
[Hébergement](hosting.md).

### 2.1 Commencer léger, grandir quand il le faut

Un nouveau projet n’a besoin d’aucune base de données. Sa configuration
d’accès d’exemple connecte les utilisateurs par un fichier des
utilisateurs (`conf/users.lst`), que `php nyuRun install userBase` crée
avec ses utilisateurs de base : deux (le rôle le plus bas et les
gestionnaires), ou un par rôle. L’application ne fait jamais que lire ce
fichier. Quand les utilisateurs doivent changer leur propre mot de passe,
recevoir leurs codes par e-mail, s’inscrire eux-mêmes, ou avoir des
appareils, une commande les déplace vers une table des utilisateurs dans
une base de données :

```
php nyuRun install moveUser nativeDb
```

Chaque utilisateur garde son identifiant, son mot de passe, son rôle, son
statut, son adresse, sa langue et son secret à deux facteurs. Le fichier
des utilisateurs reste tel qu’il était : le déplacement peut donc être
annulé. Les appareils, et leur table, ne viennent qu’avec un bloc
`dvcToken` dans `conf/access.json`.

### 2.2 Un bundle par environnement

Ne copie jamais un projet sur un serveur à la main : le dossier du projet
contient ses secrets, ses tests et ses documents. Livre plutôt un bundle,
construit pour l’environnement où il va :

```
php nyuRun bundles final
```

Un bundle contient le code, les exemples de configuration et les
bibliothèques, et rien d’autre, et son `system.ini` est réglé pour son
environnement. Sur le serveur, `php nyuRun install complete` crée ses
fichiers locaux, ses secrets et ses utilisateurs, et
`php nyuRun install checkAll` dit ce qui n’est pas prêt. Le diagnostic se
termine avec un code d’échec quand une ligne échoue : un script de
déploiement peut donc s’y arrêter.

Le framework connaît quatre environnements, réglés par `ENV_NAME`. Ses
propres modes de bundle les règlent ainsi :

| Mode | `ENV_NAME` | `ERR_LEVL` | `ERR_SHOW` | `LOG_MODE` | `CNF_CACH` | Carte des classes |
|---|---|---|---|---|---|---|
| `local` | `DEVS` | `E_ALL` | `TRUE` | `FULL` | `FALSE` | celle de Composer, telle qu’installée |
| `stage` | `PREV` | `E_ALL` | `FALSE` | `BASE` | `TRUE` | complète, qui fait autorité |
| `final` | `PROD` | `E_ALL` | `FALSE` | `MINI` | `TRUE` | complète, qui fait autorité |

En production, les journaux gardent les erreurs, les avertissements et les
notices : les connexions, les déconnexions et les refus, mais aucune ligne
pour chaque accès d’un utilisateur standard.

Un projet qui a ses propres serveurs entre les deux (un serveur de test ou
d’intégration, un aperçu pour un client) déclare leurs modes avec
`php nyuRun bundles makeMode`, et les construit comme les autres. Un mode
se nomme librement, comme une action : des lettres minuscules et des
chiffres, de n’importe quelle longueur (`qa`, `preview`, `integration`).
Ce à quoi il sert, il le porte comme son environnement, `ENV_NAME`
(`DEVS`, `TEST`, `PREV`, `PROD`), que `makeMode` demande.

### 2.3 Garder les secrets à un seul endroit

Chaque secret vit dans `.env` : le secret ou les clés des tokens, les clés
d’API, la clé des secrets à deux facteurs, les mots de passe de la base de
données et des e-mails. Les fichiers de configuration ne font que les
nommer (`env:NYU_DB_PASS`, `env:NYU_MAIL_PASS`).

- Ne commite jamais `.env`, ni la configuration locale créée à partir de
  ses exemples : `conf/access.json`, `conf/database.json`,
  `conf/mailer.json`, `conf/users.lst`, `conf/keys/`. Git les ignore, et
  les bundles les laissent de côté.
- Crée les secrets avec la ligne de commande plutôt qu’à la main. Elle les
  fait assez longs, et les écrit là où la configuration les lit
  (`php nyuRun setting mkSecret jwtToken`, `keysList`, `tfaCrypt`,
  `markSecr`).
- Donne un `tfaCrypt` à la base d’utilisateurs. Sans lui, les secrets à
  deux facteurs sont gardés lisibles, et le diagnostic le dit.

### 2.4 Envoyer les codes par e-mail plutôt que les remettre en main propre

Un utilisateur activé ou réinitialisé par un gestionnaire a besoin d’un
code pour choisir un mot de passe. Sans compte d’e-mail, le code revient
dans la réponse au gestionnaire (par l’application ou la ligne de
commande), qui le transmet. Les inscriptions et les réinitialisations que
les utilisateurs demandent eux-mêmes sont alors fermées (404). Avec un
compte d’e-mail, les utilisateurs de la base d’utilisateurs native
reçoivent leurs codes directement. `install complete` demande tout cela
une fois la base d’utilisateurs native choisie (le compte, le lien, les
inscriptions), et `install testMail <address>` vérifie que le compte
envoie :

- Nomme un compte de `conf/mailer.json` dans la clé `mailAcnt` du bloc
  `nativeDb`. Un code envoyé par e-mail dure `codeMins` minutes (15). Un
  code remis par un gestionnaire dure plus longtemps, puisqu’il passe par
  une personne.
- Règle `linkTmpl` sur la page du front-end qui prend le code
  (`%sentCode%`, plus `%mailKind%` et `%userName%` au besoin), pour que
  l’e-mail contienne un lien en plus du code. Fais-en une adresse `https`.
- En développement, envoie par un compte `emlFiles` : les e-mails sont
  écrits dans `temp/mails`, et aucun ne quitte la machine.
- N’ouvre les inscriptions (`signupOn`) qu’avec un compte d’e-mail :
  l’adresse confirme l’inscription, puis un gestionnaire la valide. Le bloc
  `tryLimit` les borne par client (`maxPerIp`) et au total (`maxQueue`), et
  prévient les gestionnaires par e-mail quand elles s’accumulent
  (`alertsAt`). Ouvre `auth/signup`, `auth/active`, `auth/forgot` et
  `auth/recover` dans `openList`, et rien de plus du run `Auth` que ce dont
  le front-end a besoin.
- Les réponses à une inscription et à un mot de passe oublié sont les
  mêmes quel que soit l’utilisateur. Garde-les ainsi dans le front-end, qui
  ne doit pas révéler si un nom ou une adresse est connu.
- Chaque e-mail part dans la langue de son utilisateur, sinon dans
  `DEF_LANG`. Le framework livre ses gabarits en anglais et en français
  (`conf/mails/<language>/`). Un run écrit les siens, avec sa formulation
  et son apparence, dans `runs/<App>/mails/<language>/`, pour chaque langue
  que parlent ses utilisateurs.

### 2.5 Signer les tokens avec une paire de clés

La configuration d’exemple signe les tokens en RS256 : une clé privée les
signe, et sa clé publique les vérifie. Garde la clé privée sur le serveur
qui émet les tokens, et nulle part ailleurs. Une application qui ne fait
que vérifier des tokens reçoit la seule clé publique : elle n’en émet
aucun, et sa connexion répond 501. HS256, un seul secret partagé par tous
ceux qui vérifient les tokens, reste là pour un projet fait d’une seule
application.

Ce partage est le profil que `install complete` demande en premier, et
que `install checkAll` relit à partir de `conf/access.json` et de la carte
des profils servis (ligne `profile`) : aucun réglage ne le garde.

| Profil | Écrit par l’installation | Signalé par le diagnostic quand |
|---|---|---|
| `allInOne` | comme avant : la base d’utilisateurs, la base de données, les comptes, les clés des tokens | — |
| `authServ` | comme `allInOne`, plus RS256 avec sa paire de clés, l’`issuerId` (le nom de l’hôte proposé) et l’`audience` (les projets qui acceptent ses tokens) | il n’a pas d’`issuerId` ou pas d’`audience` |
| `dataServ` | pas de base d’utilisateurs et pas d’utilisateurs ; la clé publique du serveur d’authentification copiée dans `conf/keys/<block>.pub`, nommée par `publFile` seul avec `"external": true`, son `issuerId`, l’`audience` qui nomme ce projet, et un `loginSet` sans `authWith` | il n’a pas d’`issuerId` ou pas d’`audience`, ou le fichier de sa clé publique manque |
| `openSite` | pas de base d’utilisateurs, pas de comptes, pas de secrets, un `loginSet` sans `authWith` ; la carte propose `/=statics`, et chacun de ses profils est ouvert (`"required": false`, `"isPublic": true`), jamais un profil qui peut atteindre une action | échoue quand un profil ouvert de la carte peut atteindre une action (une route déclarée vers une action, ou les chemins automatiques avec un run qui n’est pas fermé par ses routes) ; avertit quand un profil de la carte vérifie l’accès |

Une clé publique seule à côté d’une base d’utilisateurs ne fait aucun des
quatre, et c’est signalé aussi. `statics` ne sert aucun run :
`"runMount": { "*": false }`, où `*` désigne chaque run qui n’a pas de
ligne à lui. Quand l’installation donne une base d’utilisateurs à un site
ouvert, les profils de sa carte marqués `isPublic` sont refermés
(`"required": true`, `isPublic` retiré) et nommés. Sans
`conf/access.json`, un profil qui vérifie l’accès répond 500
`Access misconfigured`. La clé copiée ne suit pas le serveur
d’authentification : quand ce serveur crée de nouvelles clés, copie de
nouveau la nouvelle clé publique, et sa précédente (`<block>.old.pub`) en
tant que `prevFile` en attendant ([Hébergement §8](hosting.md#8-tokens)).

Un token reste valide jusqu’à son expiration, et ne peut pas être retiré
avant. Garde sa durée de vie courte (`lifeSecs`, une heure dans la
configuration d’exemple), ou nomme une base d’utilisateurs dans le profil,
pour qu’un utilisateur bloqué dans la base soit refusé tout de suite.

### 2.6 Montrer les erreurs là où elles aident, jamais en production

En développement, les erreurs sont montrées dans la réponse et journalisées
en entier. En production, un client ne voit jamais rien d’une erreur, quoi
que dise `ERR_SHOW`, et le journal garde le détail. Deux réglages décident
de ce qui est signalé tout court :

- `ERR_LEVL` choisit les erreurs que signale PHP. Ce qu’il laisse de côté
  n’est ni montré ni journalisé. Garde `E_ALL` en développement, et en
  production ne laisse de côté que ce qui n’est pas une faute
  (`"E_ALL & ~E_DEPRECATED"`).
- `LOG_MODE` choisit quelle part de ce qui est signalé est écrite : `FULL`
  ajoute les lignes de debug et leur contexte, `BASE` s’arrête aux
  informations, et `MINI` garde les erreurs, les avertissements et les
  notices.

### 2.7 Déclarer les entrées de chaque action

Une action ne reçoit que les champs que liste sa définition, dans
`runs/<App>/input/<action>.json`, chacun avec son format ; les autres sont
laissés de côté. Donne une définition à chaque action, même à une action
sans entrées.

- Marque un champ comme obligatoire avec un `!` à la fin de son nom
  (`"userName!"`) quand l’action ne peut pas marcher sans lui : une requête
  sans lui reçoit un 400.
- Liste un mot de passe avec un format vide, pour qu’il soit gardé
  exactement tel qu’il a été envoyé.
- `OutText` est fait pour ce qui est affiché, pas pour ce qui va dans une
  requête : il encode les guillemets et retire les balises.
- Un filtre compare une valeur telle quelle. Une correspondance avec joker
  se demande par le nom du champ (`title~`), et seul `*` est un joker. Une
  recherche prise dans une requête y entre protégée :
  `'*'.Filter::quoteMatchText($search).'*'`, pour qu’elle ne soit jamais un
  motif.

### 2.8 Donner à chaque route le moindre accès dont elle a besoin

- N’ouvre une route à tous qu’exprès, dans l’`openList` du profil. Toutes
  les autres routes demandent un identifiant.
- Demande un rôle par route dans le profil (`minRoles`), ou dans l’action
  elle-même (`needsActionRle()`). Les deux doivent passer. Un utilisateur
  sans le rôle reçoit un 404, comme pour une route qui n’existe pas : un
  utilisateur standard ne peut donc pas dresser la carte des routes des
  gestionnaires. Empêche le front-end de les proposer.
- Garde la limite par adresse activée (le bloc `tryLimit`) : les mauvais
  mots de passe, les mauvaises clés et les mauvais codes d’une adresse la
  font alors mettre en attente un moment.
- Ne fonde jamais une décision d’accès sur ce que le client dit de lui-même
  (son agent, son système, sa langue). Seule son adresse est une chose que
  le serveur lit par lui-même.

### 2.9 Choisir le format des réponses

Un profil écrit ses réponses dans un seul format (`jsonData`, sauf
indication contraire). Une action qui en a besoin d’un autre, un export CSV
par exemple, choisit le format de sa propre réponse
(`setReplyFormat('csvTable')`). Nomme les sites autorisés à appeler depuis
un navigateur (`corsList`) plutôt que de les autoriser tous.

---

## 3. Configuration requise

Ce dont un serveur a besoin pour faire tourner un projet nyuRuns, et ce
qu’ajoute chaque partie facultative. Sur le serveur lui-même,
`php nyuRun install checkAll` dit ce qui manque.

### 3.1 PHP

PHP 8.2 ou suivant, sur le serveur web et en ligne de commande, la même
version pour les deux. La suite de tests tourne sur la plus ancienne
version prise en charge et sur la plus récente (13.5).

Une version qui corrige php2shell (CVE-2026-109618, CVE-2026-109619) est
attendue : 8.2.35, 8.3.36, 8.4.27, 8.5.12 ou plus récente, ou le paquet
d’une distribution avec le correctif reporté. Il vaut mieux désactiver
`session.upload_progress.enabled` dans `php.ini` : le framework ne s’en
sert pas, et il porte CVE-2026-115560. `install checkAll` avertit pour les
deux.

La ligne de commande lance d’autres programmes (ffmpeg, les tests) par
`proc_open()`. Un serveur qui la désactive dans `php.ini`
(`disable_functions`) perd les sons et le lanceur de tests, et rien
d’autre.

### 3.2 Extensions PHP

| Extension | Nécessaire pour | Sans elle |
|---|---|---|
| `json` | tout : la configuration, les entrées, les réponses | rien n’est lu ni répondu |
| `dom` | les documents HTML et XML, l’écriture des PDF | ces documents ne peuvent être ni lus ni écrits |
| `iconv` | les encodages de texte des documents et des sons | les bibliothèques ne peuvent pas être installées |
| `zlib` | les parties compressées des documents PDF | les bibliothèques ne peuvent pas être installées |
| `mbstring` | la lecture et l’écriture des documents PDF | les documents PDF ne peuvent être ni lus ni écrits |
| `openssl` | les tokens signés en RS256, les secrets à deux facteurs chiffrés, les e-mails chiffrés | la configuration d’exemple ne peut pas signer de tokens ; les secrets à deux facteurs sont gardés lisibles |
| `pdo_mysql` | les bases MySQL et MariaDB | ces bases ne peuvent pas servir |
| `pdo_pgsql` | les bases PostgreSQL | pareil |
| `pdo_sqlite` | les bases SQLite, et les groupes de tests `data` et `http` | pareil, et ces tests sont sautés |
| `mongodb` | les bases MongoDB | pareil |
| `zip` | les documents Office, OpenDocument et ePub | ces documents ne peuvent être ni lus ni écrits |
| `gd` | la modification des images : redimensionner, recadrer, tourner, nettoyer | les images sont lues, pas modifiées |
| `imagick` | le dessin des images SVG en PNG, JPEG ou WebP | les images SVG ne sont pas dessinées |
| `exif` | ce que garde une photo : la date, l’appareil, le lieu | ces informations ne sont pas lues |

`json`, `iconv`, `zlib` et `dom` viennent avec la plupart des versions de
PHP. Les bibliothèques du framework exigent `dom`, `mbstring`, `iconv` et
`zlib` : Composer ne les installe donc que sur un PHP qui a les quatre. Un
bundle porte ses bibliothèques : le serveur où il va ne lance donc jamais
Composer.

### 3.3 Bibliothèques

Installées par Composer dans `vendor/`, et portées par chaque bundle :

| Bibliothèque | Version | Sert à |
|---|---|---|
| `level-2/dice` | 4.0 | le conteneur d’injection de dépendances |
| `firebase/php-jwt` | 7.2 | les tokens signés |
| `dompdf/dompdf` | 3.1 | l’écriture des documents PDF |
| `smalot/pdfparser` | 2.12 | la lecture des documents PDF |

`phpmailer/phpmailer` est suggéré, pas exigé : un compte d’e-mail
`phpMailr` en a besoin pour envoyer des pièces jointes. Les comptes
`selfSmtp` et `emlFiles` n’ont besoin de rien de plus. Les bibliothèques
et leurs licences sont listées dans `NOTICE`.

### 3.4 Autres programmes

- **ffmpeg**, pour les sons : les décompresser, les couper et les
  convertir. Son chemin complet est donné par `SND_TOOL` (dans `.env`, par
  `env:NYU_SND_TOOL`), et `SND_SECS` limite le temps qu’il peut tourner
  (60 secondes). Sans lui, un son est tout de même lu (son type, ses tags
  et son image de couverture), ses tags peuvent être retirés, et un son WAV
  ou MP3 peut être coupé ; les autres types ne peuvent être ni convertis ni
  coupés.
- **Un serveur d’e-mail** qui parle SMTP, pour les comptes `selfSmtp` et
  `phpMailr` : sur le port 465 avec TLS, ou sur un autre port avec
  STARTTLS. Seul un serveur sur la même machine reçoit des échanges non
  chiffrés.

### 3.5 Bases de données

Aucune n’est nécessaire : un projet connecte ses utilisateurs à partir du
fichier des utilisateurs jusqu’à ce qu’il les déplace vers une base de
données (2.1). La passe de tests de la 1.0 a tourné sur ces serveurs :

| Serveur | Versions |
|---|---|
| MySQL | 5.7, 8.4 |
| MariaDB | 13.0 |
| PostgreSQL | 18 |
| SQLite | celle du `pdo_sqlite` de PHP |
| MongoDB | 9.0 |

Les tables livrées (`conf/schema`) sont écrites pour MySQL (MariaDB
compris), PostgreSQL et SQLite. Les migrations des tables des utilisateurs
et des appareils renomment des colonnes avec `RENAME COLUMN`, que MySQL 5.7
n’a pas : chaque fichier de migration MySQL porte une variante pour lui.

### 3.6 Serveur web

N’importe quel serveur web qui passe la requête à PHP fait l’affaire :
Apache, nginx avec PHP-FPM, ou un autre. Il doit :

- servir le seul dossier `www`, et rien au-dessus (`conf`, `runs`, `src`,
  `temp`, `test`, `vendor`) ;
- envoyer chaque chemin vers `www/index.php`, qui lit la route à partir du
  chemin entier de l’URL. Un projet est servi à la racine de son hôte, pas
  dans un sous-dossier d’un autre site (les réglages d’Apache et de nginx
  sont dans [Hébergement](hosting.md#serveur-web)) ;
- gérer TLS lui-même, ou le laisser à un proxy placé devant lui (voir
  [Hébergement](hosting.md#3-proxy-inverse)).

### 3.7 Dossiers écrits

Le framework écrit dans `temp/` : les journaux (`temp/logs`, ou
`LOG_PATH`), les décomptes de la limite de tentatives (`temp/limits`), le
cache de configuration (`temp/cache`), les versions précédentes des
fichiers de configuration que change la ligne de commande
(`temp/archives`), et les e-mails d’un compte `emlFiles` (`temp/mails`).
Les sessions PHP sont gardées là où `php.ini` les met, sauf si le bloc
`phpSessn` nomme un dossier (`dataPath`). La ligne de commande écrit dans
`conf/` et `.env` quand elle change la configuration. L’utilisateur sous
lequel tourne le serveur web n’a besoin d’écrire que dans `temp/`.

---

## 4. Le cycle de vie d’une requête

Chaque requête passe par un seul objet, le Runner (`src/Runner.php`),
toujours dans le même ordre. N’importe quelle étape peut terminer la
requête avec sa propre réponse, et les étapes suivantes ne tournent alors
jamais.

```
www/index.php
  1. Démarrage       profil, configuration, objets partagés, journaux, erreurs
  2. Requête         limites de taille, entrées
  3. Route           pré-requête, routes déclarées, chemin
  4. Accès           configuration, adresse en attente, identifiant, rôle
  5. Action          réponse de la route, ou application, entrées, modèles, action
  6. Réponse         statut, corps, format, en-têtes
```

### 4.1 Le démarrage

Le point d’entrée, `www/index.php`, définit les dossiers du projet et crée
le Runner. Sans profil nommé, le Runner lit d’abord `conf/doorways.lst` et
prend la ligne qui correspond à l’hôte et au début du chemin
(`Runner::findPresetName()`) ; le `basePath` de cette ligne est retiré du
chemin avant le routage, et gardé comme `routBase`. Sans carte ou sans
correspondance, c’est `default`.

1. **Le profil** (`conf/presets/<name>.rps`) est lu et vérifié : une clé
   qu’il ne connaît pas l’empêche de se charger (`Preset::KEYS_LIST`).
2. **La configuration** est chargée par le Loader : `conf/system.ini`,
   chaque fichier `.json` de `conf/`, et les valeurs de `.env` qu’ils
   nomment. Avec `CNF_CACH`, le profil, ces fichiers et les routes sont lus
   depuis `temp/cache` après la première requête ; `.env` est toujours lu à
   chaque fois. Les valeurs que l’appelant donne au Runner passent
   par-dessus.
3. **Les objets partagés** sont construits par le conteneur
   (`Setup\Builder`, sur Dice) : le registre du serveur (`$_SERVER`, vidé
   une fois lu), le registre du client (son adresse, vue à travers les
   proxys de confiance), la réponse et la requête.
4. **Les journaux** sont branchés avec la configuration (`LOG_PATH`,
   `LOG_MODE`), ainsi que le programme qui décompresse les sons, quand
   `SND_TOOL` en nomme un.
5. **Le gestionnaire d’erreurs** est installé, au niveau de `ERR_LEVL`.

Une panne avant que le gestionnaire d’erreurs ne soit en place ne peut
être ni mise en forme ni journalisée par le framework : elle va dans le
journal d’erreurs propre à PHP, et le client reçoit un simple 500 en JSON.

### 4.2 La lecture de la requête

La requête est lue une fois, quand elle est construite : sa méthode, son
URL, ses en-têtes, sa chaîne de requête et son corps (du JSON, un
formulaire, ou un corps multipart avec des fichiers, pour toutes les
méthodes).

- Un corps plus gros que `REQ_SIZE`, ou un corps multipart avec plus de
  parties que `REQ_PART`, reçoit un 413, et un avertissement est
  journalisé. Un corps annoncé plus gros n’est pas lu du tout ; un corps
  qui s’avère plus gros n’est pas lu au-delà de la limite.
- Le bloc `inputSet` du profil dit de quelles sources viennent les
  entrées. Les valeurs du serveur et du client ne sont jamais des entrées :
  une action les lit à travers la requête.

### 4.3 Trouver la route

Le Router (`src/Tools/Router.php`) change le chemin en route, dans cet
ordre :

1. **Une pré-requête CORS**, une requête `OPTIONS` qui se renseigne sur une
   méthode, reçoit un 204 avec les en-têtes du profil, sur n’importe quel
   chemin.
2. **Les routes du point d’entrée**, dans un profil `mapping`.
3. **Les routes déclarées** : d’abord celles du site, dans
   `conf/routes/*.rte` (ou le dossier propre au profil), puis celles que
   déclare chaque run dans `runs/<App>/routes.rte`, montées sous le nom du
   run ou là où le dit le `runMount` du profil. Une route égale au chemin
   l’emporte, puis le premier motif qui lui correspond. Un chemin vide est
   cherché comme `index`.
4. **Le chemin lui-même**, dans un profil `autopath` : ses parties
   reçoivent les noms du `pathForm` du profil, par position
   (`$appToRun/$doAction/$argument`, donc `shows/view/12`). Un run qui
   déclare ses propres routes n’est pas atteint de cette façon : son chemin
   reçoit un 404, journalisé sur `runner`.

Une route déclarée nomme ce qui répond : une action, une redirection, un
fichier ou un dossier statique, ou une page HTML. Une méthode qu’elle
n’autorise pas reçoit un 405 avec son en-tête `Allow`, et une requête
`OPTIONS` reçoit les méthodes qu’elle autorise.

### 4.4 La vérification de l’accès

Le Checker (`src/Access/Checker.php`) décide si la requête continue. Il
échoue fermé : une configuration qu’il ne peut pas appliquer refuse toutes
les requêtes, et il ne se replie jamais sur une vérification plus faible.

1. **Les rôles des routes** (`minRoles`) sont lus en premier. Un rôle qui
   ne peut pas s’appliquer donne un 500 (`Access misconfigured`).
2. **Une route ouverte à tous** (`openList`), ou un profil qui ne demande
   aucun accès, laisse passer la requête, sans personne de connecté. Une
   route ouverte qui demande aussi un rôle est un 500.
3. **Le porteur et la vérification** (`carrWith`, `authWith`) doivent
   exister et marcher ensemble, et la route de connexion doit être un
   chemin du site ; sinon, un 500.
4. **Une adresse en attente** après trop de mauvais identifiants reçoit un
   429, avec `Retry-After`, avant qu’aucun identifiant ne soit lu.
5. **L’appel d’une application** pour l’un de ses utilisateurs est vérifié
   par sa propre clé et son propre token, quand le profil nomme les clés
   d’application.
6. **L’identifiant** est lu par le porteur et vérifié. S’il manque ou est
   refusé, c’est un 401, avec `WWW-Authenticate` pour Basic et Bearer ; un
   visiteur qui demande une page est plutôt envoyé vers la route de
   connexion (302). Seul un identifiant présenté et faux compte contre
   l’adresse.
7. **Le rôle** de la route est comparé à celui de l’utilisateur. Un
   utilisateur qui ne l’a pas reçoit un 404, comme si la route n’existait
   pas, et le refus est journalisé sur `access` avec le rôle nécessaire.

À partir de là, les événements tracés dans les journaux portent
l’utilisateur et l’adresse de la requête, et chaque fichier reçu est
tracé, que l’accès ait été accordé ou non. Une requête refusée à cette
étape ne charge jamais l’application.

### 4.5 Lancer l’action

Une route qui répond par elle-même reçoit sa réponse ici : une redirection
part avec son statut, un fichier statique avec sa version et sa date (un
304 quand le client l’a déjà, une partie quand `Range` le demande), une
page avec ses emplacements remplis. Un fichier ou une page introuvable est
un 404, avec le même `Not Found` que tous les autres.

Sinon, la route nomme une application, et son Controller
(`src/App/Controller.php`) prend le relais :

1. **L’application** doit être un dossier de `runs/` nommé avec des
   lettres et des chiffres seulement, qui contient un `Actions.php` qui
   déclare son actionset.
2. **L’action** est celle que nomme la route, ou celle par défaut de
   l’application quand la route n’en nomme aucune : le `$dfltAction` de son
   actionset, et rien d’autre (sans lui, un 500, journalisé). Elle doit
   être l’une des actions de l’application : une méthode publique de son
   actionset, pas une méthode utilitaire ni une méthode du framework. Une
   action qu’une route déclarée limite n’est pas atteinte par son chemin.
3. **Les entrées** reçoivent la définition de l’action
   (`input/<action>.json`) : les champs listés sont gardés, chacun dans son
   format, et les autres sont laissés de côté. Un champ obligatoire
   manquant ou vide est un 400, et l’action ne tourne pas.
4. **Les modèles** de l’application sont chargés (`ModelStack`).
5. **L’actionset** est créé avec un `RunContext` en lecture seule (ce dont
   il a besoin pour cette requête), lance son crochet `openEachAction()`,
   puis lance l’action, avec le paramètre de la route quand il y en a un.

Tout ce qui échoue aux étapes 1 et 2 reçoit la même réponse,
`404 Not Found`, qu’un chemin auquel aucune route ne répond : un client ne
peut pas distinguer une application qui n’existe pas d’une action qu’il
n’a pas le droit d’atteindre. La raison est journalisée sur `runner`. Une
action par défaut qui ne nomme aucune action est l’erreur de
l’application, pas du client : c’est un 500, journalisé comme une erreur.

Une action règle son statut et sa sortie. Elle peut s’arrêter à tout
moment, d’elle-même, depuis une méthode utilitaire ou depuis
`openEachAction()`, avec `stopThisAction()` : s’arrêter n’est pas une
erreur, et son statut devient celui de la réponse. `needsActionRle()`
l’arrête avec un 404, exactement comme le rôle d’une route, et
`redirectClient()` avec une redirection.

### 4.6 L’écriture de la réponse

Le statut décide du corps :

| Statut | Corps |
|---|---|
| 204 sans sortie | aucun |
| 204 avec une sortie | la sortie, envoyée en 200 |
| 2xx | la sortie de l’action |
| 3xx | aucun : la réponse est son en-tête `Location` |
| 4xx, 5xx | `{"error": <message>, "code": <status>}` |

Les avertissements que PHP a levés pendant la requête sont ajoutés au
corps (`data` et `warnings`) quand les erreurs peuvent être montrées, sauf
dans une réponse CSV ou brute, où ils restent dans le journal. Une sortie
qui ne peut pas s’écrire dans le format de la réponse change la réponse en
500.

Une erreur sur une page, sur un fichier statique, ou sur un chemin auquel
aucune route ne répond dans un profil qui sert des pages, reçoit la page
d’erreur du site (`<fileRoot>/errors/<status>.html`) quand il en a une.
Une action garde le format de son profil.

La réponse écrit ensuite ses en-têtes : ceux du profil, l’origine
autorisée quand le profil la liste, et `X-Content-Type-Options: nosniff`
sur chaque réponse. Une requête `HEAD` ne reçoit que les en-têtes. Le corps
est envoyé, et la requête se termine.

### 4.7 Les erreurs en route

Une exception qu’aucun code n’attrape, ou une erreur fatale, est attrapée
par le gestionnaire d’erreurs (`src/Error/handling.php`) : elle est
journalisée sur le canal `error`, et reçoit un 500 dans le format de la
réponse. Son détail n’est montré que quand `ERR_SHOW` le permet, et jamais
en production. Ce que `ERR_LEVL` laisse de côté n’est ni montré ni
journalisé.

---

## 5. Composants

Le framework vit dans `src/`, sous l’espace de noms `nyu\`, chargé par
Composer (PSR-4). Ses parties sont regroupées par espace de noms :

| Espace de noms | Contient | Utilisé par |
|---|---|---|
| `nyu\` | le Runner | le point d’entrée |
| `Setup` | le profil, la configuration, le conteneur | le Runner, la ligne de commande |
| `Tools` | la requête, la route, les entrées, les formats, les journaux | toutes les parties |
| `Access` | la vérification de l’accès, ses porteurs et ses vérifications, la base d’utilisateurs | le Runner, les runs `Auth` et `Users` |
| `App` | le contrôleur et l’actionset des runs | le Runner, les runs |
| `Output` | la réponse | le Runner, les actions |
| `Error` | le gestionnaire d’erreurs | le Runner |
| `Data` | les bases de données, les documents, les médias, les e-mails | les runs, la couche d’accès |
| `Shell` | la ligne de commande | `php nyuRun` |

Une requête va du haut de ce tableau vers le bas. `Data` n’est chargé que
quand un run ou la couche d’accès le demande.

### 5.1 Le Runner et la mise en place

- **`Runner`** : la séquence du chapitre 4, du profil à la réponse.
- **`Setup\Preset`** : lit un profil (`conf/presets/<name>.rps`), vérifie
  ses clés (`Preset::KEYS_LIST`), et passe ses blocs au Loader.
- **`Setup\Loader`** : la configuration de la requête : les fichiers de
  `conf/`, les valeurs de `.env` qu’ils nomment, et les réglages de chaque
  partie (journaux, erreurs, limites des requêtes, proxys, sons).
- **`Setup\ConfCache`** : le cache de configuration : le profil vérifié,
  les fichiers de configuration, les routes et les descriptions des
  tables, gardés dans `temp/cache/config-<preset>.cache` et lus à leur
  place.
- **`Setup\Builder`** : le conteneur d’injection de dépendances, sur Dice.
  Il construit les objets que partage toute la requête : les registres du
  serveur et du client, la requête et la réponse.

### 5.2 Les outils

| Classe | Rôle |
|---|---|
| `Registry` | un dictionnaire de valeurs, la base de chaque registre |
| `ServerReg` | `$_SERVER`, lu une fois puis vidé, pour qu’aucun code ne le lise autrement qu’à travers la requête |
| `ClientReg` | ce que la requête dit de son client : son adresse (à travers les proxys de confiance), son agent, son système, son type d’appareil et sa langue ; seule l’adresse est lue par le serveur lui-même |
| `Request` | la requête telle qu’envoyée : la méthode, le chemin, les en-têtes, la chaîne de requête, le corps, les fichiers, dans les limites de taille |
| `Router` | la route de la requête (4.3), les liens vers les routes déclarées, les pages d’erreur |
| `Input` | les entrées d’une action, gardées et mises en forme par sa définition |
| `Variable` | les formats qu’une valeur peut recevoir : textes, nombres, prix, dates, adresses, numéros de téléphone, liens, HTML |
| `Logger` | un fichier de journal par canal (`error`, `access`, `runner`, `shell`, un par run), les niveaux PSR-3, le renouvellement |
| `Language` | la langue dans laquelle un texte est écrit pour une personne, et le fichier trouvé pour elle |
| `StrKit` | des outils pour les chaînes |
| `UniqueId` | des identifiants qu’un enregistrement garde où qu’il soit copié : des UUID de version 7 |

### 5.3 L’accès

**`Access\Checker`** décide si une requête continue (4.4). Il associe un
**porteur**, qui lit l’identifiant dans la requête (`carrWith`), à une
**vérification**, qui le contrôle (`authWith`) :

| Porteur | Classe | Porte |
|---|---|---|
| `httpBasc` | `Carr\Basic` | un nom et un mot de passe, par HTTP Basic |
| `apiHeadr` | `Carr\Header` | une clé dans un en-tête |
| `jwtToken` | `Carr\JWT` | un token signé (HS256, RS256) |
| `phpSessn` | `Carr\PHPSess` | une session PHP, par son cookie |
| `clntCert` | `Carr\ClCert` | un certificat client, vérifié par le serveur web |

| Vérification | Classe | Contrôle avec |
|---|---|---|
| `htpasswd` | `Auth\PassList` | un fichier de mots de passe au format d’Apache |
| `regdKeys` | `Auth\RegKey` | une liste de clés d’API |
| `userFile` | `Auth\UserList` | le fichier des utilisateurs, en lecture seule |
| `nativeDb` | `Auth\Native` | la table des utilisateurs d’une base de données |
| `dvcToken` | `Auth\Device` | les tokens des appareils |

Un porteur et une vérification qui ne peuvent pas marcher ensemble
refusent toutes les requêtes (`Checker::PAIR_LIST`). À côté d’eux :

- **`Limiter`** : compte les mauvais identifiants de chaque adresse, et la
  met en attente un moment une fois la limite dépassée.
- **`Totp`** : les codes à deux facteurs (RFC 6238), et le chiffrement de
  leurs secrets.
- **`AppKey`** : les appels qu’une application fait pour l’un de ses
  utilisateurs (`appsKeys`).
- **`Auth\UserBase`** : la gestion de la base d’utilisateurs native : la
  création, l’activation, les rôles, les statuts, les mots de passe, les
  tokens, les inscriptions, les réinitialisations.
- **`AccessMail`** : les e-mails des processus d’accès (activation,
  inscription, réinitialisation, code de connexion), à partir de leurs
  gabarits, dans la langue de leur utilisateur.

### 5.4 Les applications

- **`App\Controller`** : trouve l’application et son action, applique la
  définition de ses entrées, charge ses modèles, construit son
  `RunContext`, et la lance (4.5).
- **`App\RunContext`** : ce qu’un actionset reçoit pour une requête, en
  lecture seule : l’action et son paramètre, les entrées, le résultat de
  l’accès, la requête, la réponse, le routeur, les modèles, le nom et le
  dossier de l’application.
- **`App\Actionset`** : le parent de la classe `Actions` de chaque run.
  Chacune des méthodes publiques propres au run est une action.
  L’actionset leur passe leurs entrées, leurs modèles, l’accès, la requête
  et la réponse, lance d’abord le crochet `openEachAction()`, et offre les
  méthodes utilitaires `serveFileReply()`, `servePageReply()`,
  `redirectClient()`, `makeUrlOfRoute()`, `setReplyFormat()`,
  `getLoggerOfApp()`, `needsActionRle()`, `hasRoleOrAbove()` et
  `stopThisAction()`.
- **`App\ModelStack`** : les modèles d’un run, chaque `<Name>.php` de son
  dossier `mods/` (`<Name>Model.php` en repli, `<Name>.php` l’emportant
  quand les deux sont là), puis ceux partagés dans `runs/Shared`, trouvés
  de la même façon.
- **`App\RunLoader`** : charge les classes des runs quand on les demande
  pour la première fois : les actions et la commande d’un run, ses
  modèles, une classe dans un fichier à son nom, et les classes de
  `runs/Shared`. Il est enregistré après le chargeur propre à Composer par
  `src/App/loadruns.php` (l’`autoload.files` de Composer) : un projet mis à
  jour depuis une copie plus ancienne lance donc
  `composer dump-autoload` une fois. Aucun code n’inclut lui-même un
  fichier d’un run.
- **`App\StopRun`** : ce que lance `stopThisAction()` pour arrêter une
  action exprès, avec son statut et sa sortie.

### 5.5 La sortie et les erreurs

- **`Output\Response`** : la réponse : son statut, ses en-têtes (ceux du
  profil, l’origine autorisée, `nosniff`), et son corps dans son format
  (`jsonData`, `yamlData`, `xmlNodes`, `csvTable`, `htmlView`,
  `rawBytes`), un fichier envoyé par parties, ou une page avec ses
  emplacements remplis.
- **`Error/handling.php`** : les gestionnaires des erreurs, des exceptions
  et de l’arrêt : ils journalisent sur `error`, rassemblent les
  avertissements, et répondent un 500 par le Runner.

### 5.6 Les données

**Bases de données.** Un run travaille à travers des modèles, pas des
requêtes :

- **`DBRec`** : un enregistrement d’une table, chargé par sa clé ou trouvé
  par ses champs, sauvegardé, ou supprimé avec ses enfants. **`DBLst`** :
  une liste d’enregistrements lue à travers un filtre, ses champs choisis,
  regroupée, triée, limitée, jointe à une autre table, ou les enfants d’un
  enregistrement.
- **`DBStore\Store`** : ce dont un enregistrement et une liste ont besoin
  d’une base de données, fourni par **`SqlStore`** pour les types SQL et
  **`MongoStore`** pour MongoDB. **`DBStore\Filter`** lit le filtre, écrit
  de la même façon pour tous (`field`, `field!`, `field<=`, `field~`, …).
- **`DBCon`** : une connexion par base de données configurée, ouverte à la
  première utilisation, par son gestionnaire : **`DBHndlr\MySQL`** (MariaDB
  compris), **`PgSQL`** et **`SQLite`**, tous sur PDO.

**Documents.** Un document est lu dans l’une de trois formes, et écrit à
partir de l’une d’elles (`Data\Shape`) : `nodes` (un arbre de clés et de
valeurs), `sheet` (des tableaux nommés de lignes et de cellules) et
`block` (des blocs de texte dans l’ordre de lecture).

- **`Dcmnt`** : un fichier sur le serveur, son type déduit de ses premiers
  octets, lu par l’analyseur que demande son extension.
- **`Parsers\*`**, choisis par `Parsers\Index` : texte, Markdown, HTML,
  XML, JSON, YAML, INI, CSV et TSV, iCalendar, PDF, Word, Excel, le texte
  et le tableur OpenDocument, ePub.
- **`Writers\*`**, choisis par `Writers\Index` : les mêmes formats sauf
  INI, avec le PDF écrit par dompdf.
- **`Fluxd`** : une source lue morceau par morceau (un fichier, le corps
  de la requête), ou des octets déjà en mémoire (une valeur binaire lue
  dans une table).
- **`Archv`** : une archive zip lue entrée par entrée, en mémoire.

**Médias.**

- **`Image`** : une image, son type, sa taille et ce que garde une photo ;
  redimensionnée, recadrée, tournée, nettoyée (`gd`) ; un dessin changé en
  image (`imagick`).
- **`Sound`** : un son, son type, sa durée, ses tags et son image de
  couverture ; ses tags retirés, un WAV ou un MP3 coupé. **`Codec`** lance
  ffmpeg pour le reste.
- **`QrCode`** : le QR code d’un texte, dessiné en SVG ou en texte pour un
  terminal.

**E-mails.** **`Mail\Mailer`** envoie un **`Message`** par un compte de
`conf/mailer.json`, avec son pilote : **`Drivers\Native`** (le client SMTP
propre au framework, `selfSmtp`), **`PHMail`** (PHPMailer, pour les pièces
jointes, `phpMailr`) ou **`ToFile`** (des fichiers `.eml`, `emlFiles`).
**`Template`** construit un message à partir de ses fichiers texte et
HTML, avec chaque `%name%` rempli.

### 5.7 La ligne de commande

- **`Shell\Console`** : lit les arguments de `php nyuRun`, trouve la
  commande, et la lance.
- **`Shell\Command`** : le parent de chaque commande, avec ses
  sous-commandes (`listSubCmndDct()`), ainsi que **`Options`**,
  **`Printer`** (du texte, ou du JSON pour un script) et **`ShellStop`**
  (le code de sortie).
- **`Shell\Cmds\*`** : `install`, `setting`, `testing`, `cleanup`,
  `showlog`, `cronjob`, `account`, `devices`, `bundles`, `appkeys`,
  `makerun` (chapitre 7).
- **`HealthChk`** lance les vérifications de `install checkAll`.
  **`ConfFile`** écrit les fichiers de configuration, chacun à sa façon, et
  **`UserFile`** le fichier des utilisateurs. **`ActCaller`** appelle une
  action d’un run depuis la ligne de commande, avec les rôles du profil.

### 5.8 Là où le framework s’étend

| Pour ajouter | Écris | Déclaré dans |
|---|---|---|
| une vérification d’identifiants | `runs/Shared/<Name>Auth.php`, qui implémente `Access\Auth\Handler` | le bloc `plugLibs` de `access.json`, sous `authWith` |
| un porteur | `runs/Shared/<Name>Carr.php`, qui implémente `Access\Carr\Handler` | le bloc `plugLibs`, sous `carrWith` |
| un modèle partagé par des runs | `runs/Shared/<Name>.php` (ou `<Name>Model.php`) | rien : trouvé par son nom |
| un profil | `conf/presets/<name>.rps` | le point d’entrée qui le nomme |
| un gabarit d’e-mail | `runs/<App>/mails/<language>/<kind>.txt` et `.html` | rien : il remplace celui du framework |
| une commande de la ligne de commande | `runs/<App>/Shell.php`, la classe `Shell` qui étend `Shell\Command` | rien : la commande est le nom du run en minuscules |

Le nom d’un plug-in fait six caractères, des lettres et des chiffres, et
commence par une lettre. Un plug-in listé mais inutilisable est
journalisé, et la requête est refusée comme mal configurée.

---

## 6. Fichiers de configuration

### 6.1 Comment la configuration est lue

À chaque requête, le Loader lit chaque fichier `.json` et `.ini` de
`conf/` (ou du dossier que nomme le profil), et garde chacun sous le nom
de son fichier en majuscules : `system.ini` devient `SYSTEM`,
`access.json` devient `ACCESS`. Avec `CNF_CACH`, la première requête d’un
profil écrit ce qu’elle a lu dans `temp/cache`, et les suivantes lisent ce
fichier à la place. Chaque commande qui écrit dans `conf/` supprime le
cache, tout comme `install complete` et `setting clrCache`. Après une
modification à la main, `php nyuRun setting clrCache` le supprime, et
`install checkAll` avertit pour un cache plus ancien que ses fichiers.
Aucun secret ne va dans le cache : `.env` est lu à chaque requête. Un
fichier de `conf/` qui n’est pas une configuration ne doit donc pas finir
en `.json` ou en `.ini` : les exemples finissent en `.dist`, les listes en
`.lst`.

Une valeur écrite `env:NAME` est remplacée par la valeur de `NAME` dans
`.env` (ou dans le fichier que nomme le profil), sinon dans
l’environnement du processus. Les secrets ne s’écrivent jamais autrement
(2.3).

Les fichiers créés à partir d’un exemple (`access.json`, `database.json`,
`mailer.json`, `users.lst`) et `.env` sont propres à chaque installation :
git les ignore, et les bundles les laissent de côté.
`php nyuRun install complete` les crée à partir de leurs exemples, et
`php nyuRun setting` les lit et les change, en vérifiant chaque valeur.

Tous les noms suivent la même règle : les clés d’un même bloc ont toutes
la même longueur, 8 caractères pour les fichiers JSON, et 8 sous la forme
`XXX_YYYY` pour `system.ini`.

### 6.2 `system.ini`

| Clé | Valeur | Par défaut |
|---|---|---|
| `ENV_NAME` | l’environnement : `DEVS`, `TEST`, `PREV`, `PROD` | `DEVS` |
| `LOG_MODE` | les niveaux écrits : `MINI` (erreurs, avertissements, notices), `BASE` (plus les informations), `FULL` (plus le debug, avec le contexte) | `FULL` |
| `LOG_PATH` | le dossier des journaux, depuis la racine du projet | `temp/logs` |
| `LOG_SIZE` | la taille d’un journal, en Ko, avant son renouvellement ; 0 pour aucune limite | 1024 |
| `LOG_ROTA` | le renouvellement : `INDX` (des copies numérotées au-delà de `LOG_SIZE`), `WEEK`, `MNTH` (des noms de fichiers datés), `NONE` | `INDX` |
| `LOG_PRFX` | le format `date()` au début de chaque ligne | `Y-m-d H:i` |
| `ERR_LEVL` | les erreurs que signale PHP, des noms `E_*` joints par `\|`, `&` et `~` | `E_ALL` |
| `ERR_SHOW` | si les erreurs sont montrées dans la réponse, `TRUE` ou `FALSE` ; jamais en `PROD` | |
| `DEF_ROWS` | les lignes que lit une liste quand on ne lui dit pas combien | 100 |
| `DEF_LANG` | la langue des e-mails d’un utilisateur qui n’en a pas | `en` |
| `REQ_SIZE` | le plus gros corps lu, en Ko ; 0 pour aucune limite | |
| `REQ_PART` | le plus de parties d’un corps multipart ; 0 pour aucune limite | |
| `CNF_CACH` | si le profil, les fichiers de configuration et les routes sont mis en cache (`TRUE`, `FALSE`) | `FALSE` ; `TRUE` dans les bundles `stage` et `final` |
| `TBL_CACH` | si les descriptions des tables sont mises en cache aussi, avec `CNF_CACH` ; une requête qui échoue sur une table en cache supprime le cache | `FALSE` |
| `SND_TOOL` | le chemin complet de ffmpeg, vide sans lui | |
| `SND_SECS` | les secondes pendant lesquelles ffmpeg peut tourner | 60 |
| `PRX_LIST` | les adresses ou les plages des proxys inverses de confiance | vide |
| `PRX_HEAD` | l’en-tête dans lequel ils donnent l’adresse du client | |
| `PRX_SCHM` | l’en-tête dans lequel ils donnent son schéma | |

### 6.3 `.env`

`.env.example` liste les noms qu’utilisent les exemples de configuration :

| Nom | Contient |
|---|---|
| `NYU_JWT_SECRET` | le secret des tokens HS256 (32 caractères au moins) ; vide avec RS256 |
| `NYU_API_KEY` | une clé d’API du bloc `regdKeys` |
| `NYU_TFA_KEY` | la clé qui chiffre les secrets à deux facteurs (32 caractères au moins) |
| `NYU_MARK_KEY` | la clé qui signe les marques de connexion (`tryLimit.markSecr`) |
| `NYU_DB_PASS` | le mot de passe de la base de données |
| `NYU_MAIL_PASS` | le mot de passe du compte d’e-mail |
| `NYU_SND_TOOL` | le chemin de ffmpeg |
| `NYU_TEST_*` | les serveurs de test pour MySQL, PostgreSQL et MongoDB (chapitre 13) |

On peut ajouter n’importe quel nom : il suffit que la configuration
l’écrive `env:NAME`.

### 6.4 Profils

Un profil (`conf/presets/<name>.rps`, du JSON avec des lignes de
commentaire) est un profil du framework, choisi par le point d’entrée. Ses
blocs et leurs clés :

| Bloc | Clés | Règle |
|---|---|---|
| (en tête) | `presetId`, `synopsis` | son nom, et ce à quoi il sert |
| `settings` | `confType`, `confList`, `confPath`, `envrFile` | les fichiers de configuration lus : leurs types (`all`, `ini`, `json`), leurs noms (`any` ou une liste), leur dossier, le fichier des secrets |
| `security` | `required`, `carrWith`, `carrConf`, `authWith`, `authConf`, `openList`, `signPath`, `minRoles`, `appsConf`, `isPublic` | la vérification de l’accès : si elle est nécessaire, le porteur et la vérification avec les blocs de `access.json` qu’ils lisent, les routes ouvertes à tous, la route de connexion d’un site, les rôles des routes, le bloc des clés d’application, et un site fait pour être ouvert |
| `inputSet` | `sourceId`, `readFrom`, `fieldSet` | les sources des entrées (`QUERY`), et les champs gardés |
| `dispatch` | `detectBy`, `pathForm`, `routeDir`, `fileRoot`, `runMount` | le routage : `autopath` ou `manually`, les noms des parties d’un chemin, le dossier des fichiers de routes, le dossier des pages et des fichiers statiques, l’endroit où sont montées les routes de chaque run (ou `false` pour ne plus le servir) |
| `response` | `outputAs`, `corsList`, `headList` | la réponse : son format, les sites autorisés à appeler, les en-têtes envoyés |

Une clé qu’un profil ne connaît pas l’empêche de se charger. Le framework
livre sept profils :

| Profil | Pour |
|---|---|
| `default` | le profil quand la carte n’en nomme aucun, ou qu’il n’y a pas de carte : fermé, des tokens Bearer, du JSON |
| `mapping` | un site qui déclare ses propres routes : fermé, des tokens Bearer |
| `service` | un service appelé par d’autres programmes : une clé d’API dans un en-tête |
| `restapi` | une API web : des tokens Bearer, la connexion laissée ouverte |
| `website` | un site utilisé depuis un navigateur : un cookie de session, la table des utilisateurs, ses propres routes |
| `statics` | un site de pages, de fichiers et de redirections : ouvert, sans entrées, ses propres routes |
| `example` | jamais chargé : chaque réglage d’un profil, avec ce qu’il fait |

### 6.5 `access.json`

Les blocs qu’un profil nomme par `carrConf`, `authConf` et `appsConf`, et
les réglages de la couche d’accès. Un bloc porte le nom de sa vérification
ou de son porteur : une valeur qui nomme un bloc se lit donc toute seule
(`"carrConf": "jwtToken"`) :

| Bloc | Clés | Règle |
|---|---|---|
| `loginSet` | `authWith`, `authConf`, `carrWith`, `carrConf` | comment le run `Auth` connecte un utilisateur, et ce qu’il émet |
| `plugLibs` | `authWith`, `carrWith` | les vérifications et les porteurs en plug-in autorisés (5.8) |
| `htpasswd` | `passFile`, `weakHash`, `maxTries`, `holdSecs` | le fichier de mots de passe de la vérification `htpasswd`, et sa limite par compte |
| `nativeDb` | `linkName`, `useTable`, `idleSecs`, `maxTries`, `holdSecs`, `minChars`, `tfaCrypt`, `mailAcnt`, `signupOn`, `codeMins`, `linkTmpl` | la base d’utilisateurs native : sa base de données et sa table, le temps après lequel un utilisateur n’est plus en ligne, les mauvais mots de passe avant que le compte ne soit mis en attente et pour combien de temps, le mot de passe le plus court, la clé des secrets à deux facteurs, les e-mails d’accès |
| `userFile` | `listFile`, `tfaCrypt`, `maxTries`, `holdSecs` | le fichier des utilisateurs, la clé de ses secrets à deux facteurs, et sa limite par compte |
| `dvcToken` | `useTable`, `usersTbl`, `seenSecs` | la table des appareils, celle de leurs utilisateurs, et la fréquence à laquelle la dernière utilisation d’un appareil est enregistrée ; le plafond de chaque appareil, le rôle le plus haut avec lequel il agit, est une colonne de sa table (`deviceTops`) |
| `tryLimit` | `maxTries`, `holdSecs`, `dataPath`, `maxQueue`, `maxPerIp`, `alertsAt`, `markSecr`, `markDays` | la limite par adresse, les bornes des inscriptions, et les marques de connexion |
| `regdKeys` | `headName`, `keysList` | l’en-tête des clés d’API, et les clés |
| `jwtToken` | `hmacSecr`, `lifeSecs`, `signAlgo`, `privFile`, `publFile`, `prevFile`, `retireAt`, `issuerId`, `audience`, `external` | les tokens : leur algorithme, leur secret ou leurs clés, la clé publique précédente et l’heure de son retrait, leur durée de vie, leur émetteur et leur audience ; `external` quand la clé publique est celle d’un autre serveur |
| `phpSessn` | `cookName`, `lifeSecs`, `fullSecs`, `sameSite`, `secureOn`, `dataPath` | les sessions PHP : le cookie, le temps sans utilisation et en tout |
| `clntCert` | `checkVar`, `ownerVar`, `issueVar`, `issuerId` | les valeurs du serveur où se lit un certificat client, et l’émetteur accepté |
| `appsKeys` | `headName`, `appsList`, `audience` | les clés des applications qui appellent pour leurs utilisateurs (`keyDigst`, `routeSet`, `publFile`, `roleTops`, `madeDate` pour chacune) |

Un projet peut avoir plusieurs blocs d’une même sorte sous des noms à lui,
chaque profil nommant celui qu’il utilise.

### 6.6 `database.json`

Une base de données, ou plusieurs par leur nom, la première étant celle
par défaut :

| Clé | Valeur |
|---|---|
| `dbEngine` | `mysql_db` (MariaDB compris), `postgres`, `sqlitedb`, `mongo_db` |
| `hostName`, `portNumb` | le serveur ; vides pour SQLite |
| `baseName` | la base de données, ou le fichier d’une base SQLite |
| `userName`, `passWord` | le compte, avec le mot de passe sous la forme `env:NAME` |
| `encoding` | le jeu de caractères (`utf8`) |
| `authBase` | la base de données qui contient le compte, quand c’en est une autre |
| `waitSecs` | les secondes laissées au serveur pour répondre |

### 6.7 `mailer.json`

Les comptes d’e-mail par leur nom, le premier étant celui par défaut,
chacun avec les clés `sendWith` (`selfSmtp`, `phpMailr`, `emlFiles`),
`hostName`, `portNumb`, `userName`, `passWord`, `fromAddr`, `fromName`,
`waitSecs` (les secondes que peut prendre un serveur), `mailsDir` (le
dossier d’un compte `emlFiles`) et `copiesTo` (des adresses auxquelles
chaque e-mail est envoyé une seconde fois).

### 6.8 Fichiers de routes

Les routes du site sont déclarées dans `conf/routes/*.rte` (ou le dossier
du profil), du JSON avec des lignes de commentaire, par leur chemin :

```json
{
    "profiles/$id": {
        "mthdList": "GET, POST",
        "pointsTo": { "appToRun": "client", "doAction": "edit" }
    },
    "assets/*": {
        "pointsTo": { "fldrPath": "assets" }
    }
}
```

- Une partie écrite `$name` capture une valeur : la première est le
  paramètre de l’action, et toutes remplissent les emplacements d’une
  page. Un `*` final prend le reste du chemin.
- `mthdList` liste les méthodes de la route (`GET` seul sinon) ; un bloc
  `pointsTo` peut aussi contenir un bloc par méthode.
- Une cible nomme une seule sorte : une action (`appToRun`, `doAction`),
  une redirection (`redirect`, avec son statut dans `httpCode`), un
  fichier (`filePath`) ou un dossier (`fldrPath`) statique, ou une page
  HTML (`pageFile`).

Un run déclare ses propres routes de la même façon, dans
`runs/<App>/routes.rte`, avec une cible qui nomme l’une de ses actions par
`doAction` seul. Ses chemins sont montés sous le nom du run en minuscules
(`""` est `/notes`, `$id` est `/notes/$id`), un chemin qui commence par une
barre oblique part de la racine du site, et le `runMount` du profil peut
monter le run ailleurs, ou pas du tout. Une clé qu’aucun code ne lit est
journalisée comme un avertissement. Un fichier de routes ne contient aucun
réglage d’accès : les rôles des routes sont dans le profil.

### 6.9 Définitions des entrées

Les entrées d’une action sont déclarées dans
`runs/<App>/input/<action>.json` :

```json
{ "fieldSet": { "user!": "OutText", "pass": "", "mail": "EmlAddr" } }
```

Chaque champ est nommé avec son format (l’un de ceux de
`Tools\Variable`). Un `!` après le nom le rend obligatoire, et un format
vide le garde tel qu’il a été envoyé. Un format inconnu arrête la requête
avec un 500 (un 400 pour un champ obligatoire). Une action sans ce fichier
ne reçoit aucune entrée ; `{ "fieldSet": "all" }` garde chaque entrée
telle que le profil l’a construite.

### 6.10 Autres fichiers

| Fichier | Contient |
|---|---|
| `conf/users.lst` | le fichier des utilisateurs : `{ "users": [ ... ] }`, avec les noms des colonnes de la table des utilisateurs |
| `conf/bundle.lst` | les modes de bundle du projet (chapitre 7) |
| `conf/.htpasswd` | le fichier de mots de passe de la vérification `htpasswd` |
| `conf/keys/` | les clés des tokens (`.key`, `.pub`), créées par `setting mkSecret jwtToken` |
| `conf/schema/` | les tables livrées, un fichier par table et par type de base (`users.sqlitedb.sql`), et leurs migrations |
| `conf/mails/<language>/` | les gabarits des e-mails d’accès |

---

## 7. Ligne de commande

### 7.1 Utilisation

```
php nyuRun <command> <sub-command> [arguments] [--options]
```

Chaque commande est un mot de sept lettres, et chaque sous-commande un mot
de huit, reconnu quelle que soit sa casse. Une commande sans
sous-commande, ou avec `--helpText`, liste ce qu’elle fait ; `showlog` et
`cronjob` n’en ont pas, et prennent leurs arguments directement.

| Option | Effet |
|---|---|
| `--noPrompt` | aucune question n’est posée : les réponses par défaut sont prises |
| `--yesToAll` | ce qui supprime ou remplace est confirmé d’avance ; avec `--noPrompt` seul, c’est refusé |
| `--outputAs=json` | le résultat en un seul objet JSON, pour qu’un script le lise |
| `--silently` | aucune remarque, seulement le résultat |
| `--helpText` | ce que fait la commande |
| `--versions` | la version du framework |

| Code de sortie | Signifie |
|---|---|
| 0 | fait |
| 1 | échoué |
| 2 | mauvaise utilisation : un mot inconnu, un argument manquant |
| 3 | refusé ou décliné |
| 4 | fait, avec des problèmes trouvés (`install checkAll`) |

Ce que change une commande est tracé sur le canal de journal `shell`,
signé `cli:<system user>`. Ce qu’elle change chez les utilisateurs est
tracé sur `access`, comme le même changement fait par HTTP.

### 7.2 `install` : installer et vérifier

| Sous-commande | Fait |
|---|---|
| `complete` | toute l’installation : le profil du projet demandé en premier (`allInOne`, `authServ`, `dataServ`, `openSite` ; `--projKind` y répond), le serveur vérifié, les fichiers locaux créés, les secrets créés, les utilisateurs créés (leurs noms demandés), le diagnostic ; elle demande ce dont elle a besoin, en proposant une valeur par défaut (`--noPrompt` les prend toutes, dont `allInOne`, `--useStore` choisit la base d’utilisateurs). Avec la base d’utilisateurs native, les e-mails d’accès sont demandés après la base : envoyés ou non, le compte d’e-mail écrit dans `mailer.json`, `linkTmpl`, `signupOn`, un e-mail de test (`--sendWith=<selfSmtp\|phpMailr\|emlFiles\|none>` et `--signupOn` répondent d’avance ; `--noPrompt` prend `none`) |
| `testHost` | vérifie le serveur : PHP, ses extensions, les dossiers écrits |
| `makeConf` | crée `.env`, `access.json`, `database.json` et `mailer.json` à partir de leurs exemples |
| `userBase` | crée la table des utilisateurs et les utilisateurs de base, ou le fichier des utilisateurs ; la table des appareils avec un bloc `dvcToken` |
| `checkAll` | le diagnostic : chaque ligne `fine`, `warn` ou `fail` ; code de sortie 4 sur un `fail` |
| `moveUser <nativeDb\|userFile>` | déplace les utilisateurs vers l’autre base, et la connexion avec eux (`--dropsAll`, `--yesToAll`) |
| `testMail [address]` | envoie un e-mail de test par le compte que nomme `nativeDb.mailAcnt`, et dit s’il est parti ou pourquoi il ne l’est pas ; rien n’est changé |

Rien de ce qui existe n’est remplacé : un fichier, un secret, une table,
une base qui contient des utilisateurs.

### 7.3 `setting` : lire et changer la configuration

| Sous-commande | Fait |
|---|---|
| `showConf <coreConf\|accsConf\|dataConf\|psetConf> [path]` | montre un fichier, l’un de ses blocs ou de ses clés, avec les secrets masqués |
| `setValue <coreConf\|accsConf\|dataConf\|psetConf> <path> <value>` | change une valeur (`KEY`, `block.key`, `preset.block.key`), vérifiée par sa règle (`--overrule`) |
| `entryPts [basePath] [preset]` (`--hostName`, `--removeIt`) | les profils servis et où (`conf/doorways.lst`) : listés, réglés ou supprimés |
| `mkSecret <jwtToken\|keysList\|tfaCrypt\|markSecr\|htpasswd>` | crée un secret, la paire de clés RS256, ou un mot de passe du fichier de mots de passe, et l’écrit là où la configuration le lit (`--useBlock`, `--showSecr`, `--typeItIn`) |
| `fullList [preset]` | les routes d’un profil : chemin, méthodes, sorte, cible, propriétaire, et à qui elles sont servies |
| `clrCache` | supprime le cache de configuration : la prochaine requête de chaque profil relit ses fichiers |

Un fichier est écrit en gardant ses commentaires et sa mise en page, et sa
version précédente est copiée dans `temp/archives`. Un changement qui le
rend inutilisable est annulé.

### 7.4 `account`, `cronjob` : les utilisateurs et les actions

Les utilisateurs de la base qu’utilise la connexion (`--useStore`, ou
`--useBlock` pour un autre bloc) :

| Sous-commande | Fait |
|---|---|
| `fullList`, `viewUser` | liste les utilisateurs (`--statusIs`, `--roleName`, `--rowCount`, `--startRow`), en montre un |
| `makeUser <user> [role]` | crée un utilisateur en attente d’activation, et montre une fois son code d’activation (`--noExpiry`, `--language`) |
| `activate <user> <code>` | active un utilisateur avec son code, et lui donne un mot de passe |
| `lockUser`, `freeUser` | bloque un utilisateur, le débloque |
| `giveRole <user> <role>` | donne un autre rôle |
| `dropUser <user>` | supprime un utilisateur |
| `resetPwd <user>` | un nouveau code d’activation, pour un mot de passe perdu |
| `password <user>` | un nouveau mot de passe, créé et montré une fois (`--typeItIn` pour le taper) |
| `apiToken <user>` | un token d’API personnel, montré une fois (`--removeIt` le retire) |
| `setTwoFa <user>` | un secret à deux facteurs, avec le QR code de l’app d’authentification (`--noQrCode` sans lui, `--emailOtp` pour des codes par e-mail, `--removeIt` le retire) |
| `setEmail <user> [address]` | règle ou supprime l’adresse |
| `language <user> [language]` | montre, règle ou supprime la langue |
| `approves [user]` | liste les inscriptions à examiner, ou en valide une |

`php nyuRun cronjob <app/action[/param]> [field=value]`, une commande à
part entière sans sous-commande, appelle une action d’un run pour des
tâches automatiques, en tant qu’utilisateur `cli:<system user>`, avec les
rôles d’un profil (`--presetId`, `--roleName`, `--bodyFrom`, `--httpVerb`,
`--saveInto`).

### 7.5 `devices`, `appkeys` : les appareils et les applications

| Commande | Sous-commandes |
|---|---|
| `devices` | `newToken <name> <user> <role>` donne un token à un appareil, montré une fois ; `fullList [user]` ; `revokeIt <name> <user>` |
| `appkeys` | `makeKeys <app>` avec `--routeSet`, `--publFile`, `--roleTops`, donne une clé montrée une fois ; `fullList` ; `setRoute <app>` remplace ses routes ; `revokeIt <app>` |

Seule l’empreinte d’un token ou d’une clé est gardée. Un appareil révoqué
reste dans la liste avec l’heure de sa révocation ; une clé révoquée est
refusée dès la requête suivante.

### 7.6 `bundles` : une copie pour chaque environnement

| Sous-commande | Fait |
|---|---|
| `local`, `stage`, `final` | construit la copie pour le développement, pour un serveur avant la production, pour la production |
| `<mode>` | construit un mode du projet |
| `fullList` | liste les modes, ceux du framework et ceux du projet |
| `makeMode <mode>` | déclare un mode du projet dans `conf/bundle.lst`, ou le change |
| `dropMode <mode>` | en supprime un |

Un bundle est écrit dans `dist/<mode>`, ou dans le dossier vide que nomme
`--destPath`. `--archived` y ajoute son `.tar.gz`, et `--planOnly` liste ce
qui serait copié. Seul ce que nomme `Bundle::KEEP_LIST` est copié, moins ce
que nomme `DROP_LIST` : aucun secret, aucun réglage local, aucune clé. Les
réglages du mode sont écrits dans le `system.ini` de la copie. Une copie
pour `stage` ou `final` reçoit une carte des classes complète, qui fait
autorité, créée en elle par Composer (`--composer` le nomme, sinon
`composer.phar` à la racine, ou celui du chemin). Sans Composer, la copie
garde le chargeur automatique du projet, et le dit.

Un mode du projet, dans `conf/bundle.lst`, se nomme librement (des lettres
minuscules et des chiffres, de n’importe quelle longueur, ni un mode du
framework ni une sous-commande). Il contient `settings` (des clés de
`system.ini`, dont son environnement `ENV_NAME` : `DEVS`, `TEST`, `PREV`
ou `PROD`, que demande `makeMode`) et `addPaths` (des chemins copiés en
plus de la liste autorisée).

### 7.7 `cleanup`, `showlog` : les fichiers temporaires et les journaux

| Sous-commande | Fait |
|---|---|
| `cleanDir <cacheDir\|logFiles\|tryLimit\|archives\|all>` | supprime les fichiers de ces dossiers de `temp` (`--keepDays=<days>`) |
| `fullList` | les adresses que la limite de tentatives compte ou met en attente |
| `freeAddr <address\|all>` | libère une adresse |

Rien n’est supprimé hors du dossier `temp` du projet.

`php nyuRun showlog <channel>`, une commande à part entière sans
sous-commande, montre les dernières lignes d’un journal (`--numLines`,
`--watching` pour le suivre).

### 7.8 `testing` : la suite de tests

`php nyuRun testing` lance les groupes `unit`, `audit` et `data`. Un
groupe ou un mot lance ces scripts-là, et `all` lance tous les groupes.
Les extensions dont les tests ont besoin et que PHP ne charge pas sont
données pour le lancement (`--addExtns`). Le chapitre 13 décrit la suite.

### 7.9 Les commandes d’un run

Un run apporte sa propre commande avec `runs/<App>/Shell.php`, qui déclare
la classe `Shell` de son espace de noms, qui étend `Shell\Command`. La
commande est le nom du run en minuscules, et chaque sous-commande lance
une méthode nommée `invoke` suivi de son nom (`invokeSayHello` pour
`sayHello`), listée dans son `listSubCmndDct()`.

### 7.10 `makerun` : un run à partir d’une table

| Sous-commande | Fait |
|---|---|
| `crudlRun <App>` | demande la base de données (SQL seulement), la table, sa clé, les champs et leurs formats, les actions (`index`, `view`, `create`, `update`, `remove`), les tables enfants, le rôle minimum et le montage ; écrit `runs/<App>/runspec.json`, puis le run ; un run qui existe est refusé |
| `fromSpec <App>` | réécrit le run à partir de son `runspec.json`, sans question ; un fichier qui existe n’est remplacé qu’avec un accord (`--yesToAll`), et les fichiers changés à la main ne sont pas fusionnés |
| `fullList` | liste les runs qui ont une spec : base de données, table, actions, enfants |

Le run reçoit son `routes.rte`, son `Actions.php` et un fichier d’entrées
par action. Les questions remplissent la spec, et le générateur ne lit que
la spec : un run peut donc être réécrit après une modification de la
spec. Chaque fichier écrit est tracé sur `shell`, et la ligne `minRoles` à
ajouter au profil est affichée. Un bundle laisse `runspec.json` de côté.

---

## 8. Construction des runs (applications)

### 8.1 De quoi est fait un run

Un run est un dossier de `runs/`, nommé avec des lettres et des chiffres,
sa première lettre en majuscule. Seul `Actions.php` est obligatoire :

```
runs/Notes/
  Actions.php                 les actions                  namespace nyu\Runs\Notes
  mods/<Name>.php             les modèles                  namespace nyu\Runs\Notes\mods
  input/<action>.json         les entrées de chaque action
  routes.rte                  ses propres routes
  pages/<name>.html           les pages de ses réponses
  mails/<language>/<kind>.*   ses propres gabarits d’e-mail
  Shell.php                   sa propre commande de php nyuRun
```

Sans `routes.rte`, le run est atteint par son nom en minuscules
(`notes/view/12`), et par les routes que le site déclare pour lui. Avec un
`routes.rte`, il n’est atteint que par ses propres routes (6.8).

### 8.2 Les actions

`Actions.php` déclare la classe `Actions` de l’espace de noms du run, qui
étend `nyu\App\Actionset`. Chacune de ses méthodes publiques est une
action, atteinte par son nom ; les méthodes du framework, les méthodes
statiques et les méthodes protégées ne le sont pas.

```php
<?php

namespace nyu\Runs\Notes;

use nyu\App\Actionset;

class Actions extends Actionset {

	protected $dfltAction = 'index';

	/**
	 * Lists the notes, those whose name holds the text asked for.
	 */
	public function index(): Void {
		$listModl = $this->modelStack->getModelObject('listing');
		$nameText = strval( $this->inputsRegr->getValueOrDflt('name', '') );
		if( $nameText!='' ){
			$listModl->setListFilters( Array('noteName~'=>'*'.$nameText.'*') );
		}
		$this->statusCode = 200;
		$this->replyValue = $listModl->readRowsOfList();
	}

	/**
	 * Shows one note.
	 *
	 * @param String $recordIdText Identifier of the note, from the path.
	 */
	public function view( String $recordIdText ): Void {
		$rcrdVals = $this->modelStack->getModelObject('single')->loadRecordList($recordIdText);
		if( empty($rcrdVals) ){
			$this->stopThisAction(404, 'Not Found');
		}
		$this->statusCode = 200;
		$this->replyValue = $rcrdVals;
	}
}
```

- **`$dfltAction`** nomme l’action qui tourne quand le chemin n’en nomme
  aucune ; sans lui, une telle requête est un 500, journalisé sur
  `runner`, et `install checkAll` le signale (`default <Run>`). Un run qui
  déclare ses routes n’en a pas besoin : sa route `""` dit à quoi répond
  son chemin seul.
- **`openEachAction()`**, quand le run la déclare, tourne avant chaque
  action : une vérification que partage tout le run (un rôle, un réglage)
  va là, et elle peut arrêter la requête avec `stopThisAction()`.
- **Le paramètre** d’une action est la partie du chemin que le `pathForm`
  nomme `$argument` (la troisième dans les profils du framework :
  `notes/view/12`), ou la première valeur que capture une route déclarée.
  Une action qui en a besoin le déclare ; une action qui n’en prend aucun
  l’ignore.
- Une action suit les conventions du projet (chapitre 12), sauf pour son
  nom, qui est le mot du chemin, de n’importe quelle longueur.

### 8.3 Ce que reçoit une action

| Propriété | Contient |
|---|---|
| `$this->inputsRegr` | les entrées, définies par `input/<action>.json` : `getValueOrDflt('name', default)`, `readLinesArray()` |
| `$this->modelStack` | les modèles du run : `getModelObject('name')` |
| `$this->accessRegr` | le résultat de l’accès : `loggedIn`, `userName`, `userRole`, `identity` (ce que la base d’utilisateurs dit de l’utilisateur), `appsName` pour l’appel d’une application |
| `$this->requestObj` | la requête : `isSentByMethod()`, `getHeaderValue()`, `getCookieValue()`, `getUploadFiles()`, `getUploadEntry()`, `getServerValue()`, `getClientOfRqt()` |
| `$this->respondObj` | la réponse, pour ses en-têtes |
| `$this->routingObj` | le routeur, pour les liens vers les routes déclarées |
| `$this->runContext` | le `RunContext` d’où viennent tous ceux-là, en lecture seule |

Les entrées sont les seules valeurs envoyées par un client qu’une action
doit utiliser comme des données. Les valeurs du serveur, et ce que le
client dit de lui-même, se lisent à travers la requête, et ne décident
jamais d’un accès (2.8).

### 8.4 Répondre

Une action règle trois propriétés :

- **`statusCode`** : le statut de la réponse (200 quand elle n’en règle
  aucun) ;
- **`statusText`** : le message d’un statut d’erreur ;
- **`replyValue`** : les données de la réponse, écrites dans le format du
  profil : un tableau, un enregistrement, une liste, un texte, un nombre.

Les méthodes utilitaires de l’actionset :

| Méthode | Fait |
|---|---|
| `stopThisAction($code, $message, $output)` | arrête l’action tout de suite, depuis elle-même ou une méthode utilitaire, avec ce statut |
| `serveFileReply($path, $type)` | répond avec un fichier du serveur, par parties quand `Range` le demande |
| `servePageReply($name, $values)` | répond avec `pages/<name>.html`, ses emplacements `%name%` remplis et encodés |
| `redirectClient($address, $code)` | redirige vers un chemin du site ou une adresse `http(s)` |
| `makeUrlOfRoute($route, $values, $query)` | le lien vers une route déclarée |
| `setReplyFormat($format)` | un autre format pour cette réponse : `jsonData`, `yamlData`, `xmlNodes`, `csvTable`, `htmlView`, `rawBytes` |
| `getLoggerOfApp()` | le journal du run, sur son propre canal |
| `needsActionRle($role)` | arrête l’action avec un 404, sauf si l’utilisateur a le rôle |
| `hasRoleOrAbove($role)` | dit si l’utilisateur a le rôle |

Une exception que l’action n’attrape pas est un 500, journalisé avec sa
trace (4.7) ; en production, le client n’en apprend pas plus.

### 8.5 Les modèles

Un modèle est un enregistrement ou une liste d’une table, déclaré une fois
et obtenu par son nom. `mods/Single.php` déclare la classe `Single` de
`nyu\Runs\Notes\mods`, obtenue par `getModelObject('single')`. L’ancienne
forme, `SingleModel.php`, est toujours trouvée quand il n’y a pas de
`Single.php` :

```php
<?php

namespace nyu\Runs\Notes\mods;

use nyu\Data\DBRec;

class Single extends DBRec {

	/**
	 * Binds the record to the notes table, with its tags.
	 */
	public function __construct(){
		parent::__construct( 'notes', Array('tags') );
	}
}
```

- **Un enregistrement** (`DBRec`) nomme sa table, ses tables enfants et
  ses tables parentes. Il est chargé par sa clé (`loadRecordList()`) ou
  trouvé par ses champs (`findRecordList()`), avec ses relations chargées
  en même temps ; il est rempli (`setFieldsArray()`), sauvegardé
  (`insertOrUpdate()`) et supprimé (`dropThisRecord()`).
- **Une liste** (`DBLst`) nomme sa table. Elle est filtrée
  (`setListFilters()`), ses champs choisis (`setReadColumns()`),
  regroupée (`setGroupFields()`), triée (`setOrderFields()`), limitée
  (`setLimitOffset()`), jointe (`joinOtherTable()`), puis lue
  (`readRowsOfList()`).
- Un modèle peut nommer une autre base de données que celle par défaut.
- Les tables du framework suivent le nommage du chapitre 10 : des colonnes
  de 10 caractères, `id_<table>` pour l’identifiant, `<table>_id` pour un
  lien.

Les modèles de `runs/Shared` sont donnés à chaque run qui n’en a aucun de
ce nom. Un modèle est créé la première fois qu’une action le demande, puis
gardé pour la requête : un run ne paie que pour les modèles qu’utilise son
action.

### 8.6 Les entrées

Chaque action déclare ses entrées dans `input/<action>.json` (6.9). Une
action sans définition ne reçoit aucune entrée : les entrées sont fermées
par défaut. `{ "fieldSet": "all" }` est le choix explicite qui garde
chaque entrée que le profil a construite.

### 8.7 Les runs livrés

| Run | Contient |
|---|---|
| `Auth` | la connexion et la déconnexion, l’activation, les inscriptions, les mots de passe oubliés, le changement d’un mot de passe ou d’une langue, les clés publiées : `login`, `logout`, `active`, `signup`, `forgot`, `recover`, `setpass`, `usrlang`, `keyset` |
| `Users` | la gestion des utilisateurs, pour un gestionnaire : `index`, `view`, `create`, `approve`, `setmail`, `langs`, `lock`, `unlock`, `role`, `remove`, `reset` |
| `Shows` | un exemple : une liste de spectacles filtrée, triée et limitée (`index`), et un spectacle avec ses événements et ses articles (`view`) |
| `Shared` | vide : les modèles et les plug-ins que partagent les runs |

`Auth` déclare ses routes dans son propre `routes.rte` (`/auth/login`, …,
et `/.well-known/jwks.json`). `Auth` et `Users` travaillent sur la base
d’utilisateurs que nomme le bloc `loginSet`, et chaque action de `Users`
demande elle-même le rôle `managers`. `Users` est REST : son `routes.rte`
nomme l’utilisateur dans le chemin, donné à l’action comme son option, et
la méthode choisit l’action : `GET`/`POST /users` (index, create),
`GET`/`DELETE /users/<user>`, `PUT /users/<user>/role`, `/email`,
`/langs`, `PUT`/`DELETE /users/<user>/lock`, `POST /users/<user>/reset` et
`/approve` ; une autre méthode reçoit un 405.

---

## 9. Sécurité

Ce que fait le framework pour protéger un projet, en bref. Les réglages
sont décrits dans [Hébergement](hosting.md), les conseils au chapitre 2,
et l’ordre des vérifications au chapitre 4.

### 9.1 Principes

- **Échouer fermé.** Une configuration qui ne peut pas s’appliquer refuse
  toutes les requêtes avec un 500 (`Access misconfigured`) ; elle ne se
  replie jamais sur une vérification plus faible.
- **Ne rien dire au client qu’il n’a pas besoin de savoir.** Tout ce qu’il
  ne peut pas atteindre est le même 404 ; un mauvais nom et un mauvais mot
  de passe reçoivent le même refus ; la réponse à une inscription ou à un
  mot de passe oublié est la même quel que soit le demandeur ; la
  production ne montre aucun détail d’une erreur.
- **Ne croire que ce que lit le serveur.** Les seules entrées sont celles
  qu’une action déclare. Ce que le client dit de lui-même est un indice,
  jamais une raison d’accorder un accès. L’adresse d’un client est lue à
  travers les proxys auxquels la configuration fait confiance, et aucun
  autre.
- **Garder les secrets hors des fichiers.** Les secrets vivent dans
  `.env` ; les clés, les tokens et les codes ne sont gardés que sous forme
  d’empreintes ; un bundle n’en porte aucun.
- **Partager les tâches.** Le framework prend la requête en main dès que
  PHP la reçoit. Le serveur web, TLS, le pare-feu et `php.ini` reviennent
  à l’hébergeur ([Hébergement §1](hosting.md#1-rôle-et-partage-des-tâches)).

### 9.2 Requêtes et entrées

| Risque | Ce que fait le framework | Décrit dans |
|---|---|---|
| un corps trop gros pour être gardé | refusé avec un 413 au-delà de `REQ_SIZE` ou de `REQ_PART`, avant d’être lu | [Hébergement §11](hosting.md#11-taille-des-requêtes) |
| des valeurs que l’action n’attendait pas | seuls les champs de `input/<action>.json` sont gardés, chacun dans son format | 6.9 |
| une adresse de client falsifiée | les en-têtes de transfert ne sont lus que depuis les proxys de `PRX_LIST` | [Hébergement §3](hosting.md#3-proxy-inverse) |
| un mot de passe dans un lien, gardé dans les journaux et les historiques | les actions `Auth` refusent un mot de passe envoyé dans la chaîne de requête (400) | |
| une valeur de filtre utilisée comme motif | comparée telle quelle ; une correspondance se demande par le nom du champ (`field~`) | 2.7 |
| l’injection SQL | les bases envoient les valeurs comme des paramètres, et n’acceptent que des noms faits de lettres, de chiffres et de tirets bas | 10 |

### 9.3 Routes et réponses

| Risque | Ce que fait le framework | Décrit dans |
|---|---|---|
| dresser la carte de ce qui existe | un seul 404 `Not Found` pour une application ou une action inconnue, une route fermée, un run atteint par son chemin simple, un fichier manquant, un rôle non détenu | 4.5 |
| sortir d’un dossier | un fichier demandé dans un dossier n’en sort jamais ; seules les extensions de `Response::TYPE_LIST` sont servies | [Hébergement §13](hosting.md#13-notes-sur-le-routage) |
| des scripts dans une page | les emplacements des pages et des e-mails sont encodés en HTML | 8.4 |
| une redirection ailleurs | une route de connexion doit être un chemin du site ; `redirectClient()` ne prend qu’un chemin ou une adresse `http(s)` | |
| la détection du type de contenu | `X-Content-Type-Options: nosniff` sur chaque réponse | 4.6 |
| les appels d’autres sites | `Access-Control-Allow-Origin` ne nomme qu’une origine que liste `corsList` | [Hébergement §10](hosting.md#10-les-autres-sites-qui-appellent) |

### 9.4 Identifiants

- **Les mots de passe** sont hachés avec bcrypt. Un nom inconnu coûte tout
  de même un hachage : le temps que prend un refus ne révèle donc pas
  quels noms existent. Un fichier de mots de passe n’accepte des formats
  faibles que si on le lui dit (`weakHash`,
  [Hébergement §6](hosting.md#6-fichiers-de-mots-de-passe)).
- **Les comptes qui ne sont pas actifs** sont refusés exactement comme un
  mauvais mot de passe ; la raison n’est que dans le journal.
- **Les tokens** portent une expiration (`exp`) et un utilisateur, sinon
  ils sont refusés ; une fois réglés, leur émetteur (`iss`) et leur
  audience (`aud`) sont vérifiés aussi. Les clés RS256 de moins de 2048
  bits sont refusées, et un token nomme sa clé (`kid`)
  ([Hébergement §8](hosting.md#8-tokens)).
- **Les sessions** prennent fin après `lifeSecs` sans utilisation ou
  `fullSecs` en tout. Une connexion reçoit un nouvel id de session et
  ferme celui avec lequel elle est arrivée, et le cookie est `HttpOnly`,
  `Secure` et `SameSite` selon le réglage
  ([Hébergement §9](hosting.md#9-sessions-php)).
- **Les codes à deux facteurs** sont acceptés une fois chacun, et leurs
  secrets sont gardés chiffrés avec `tfaCrypt`
  ([Hébergement §7](hosting.md#7-codes-à-deux-facteurs)). Un code envoyé
  par e-mail pour une connexion part une fois par minute au plus.
- **Les clés, les tokens personnels, les tokens d’appareils, les clés
  d’application, les codes d’activation et de réinitialisation** sont
  montrés une fois, gardés sous forme d’empreintes, et comparés par leur
  empreinte.
- **Les codes envoyés par e-mail** durent `codeMins` minutes, et une
  réinitialisation garde le mot de passe jusqu’à ce que son code serve
  ([Hébergement §15](hosting.md#15-e-mails-daccès)).
- **Les e-mails** sont envoyés chiffrés, avec le certificat du serveur
  vérifié.

### 9.5 Les tentatives

La limite par adresse (`tryLimit`) compte les mauvais mots de passe, les
mauvaises clés et les mauvais codes, les tokens falsifiés, et chaque
inscription et chaque réinitialisation demandée : au-delà de `maxTries`,
l’adresse est mise en attente pendant `holdSecs` secondes et reçoit un
429. Un token expiré, un identifiant manquant ou un rôle non détenu ne
sont pas comptés. Les bases qui vérifient des mots de passe (`nativeDb`,
`userFile`, `htpasswd`) mettent aussi un compte en attente après ses
propres `maxTries` mauvais mots de passe. Un client qui s’est déjà
connecté avec succès porte une marque de connexion, signée avec
`markSecr`, et entre toujours tant que son compte est en attente : un
attaquant ne peut donc pas tenir le titulaire à l’écart. Les inscriptions
sont bornées par client et au
total ([Hébergement §5](hosting.md#5-limite-par-adresse-et-comptes-en-attente)).

### 9.6 Erreurs et journaux

- En production, rien d’une erreur n’est montré, quoi que dise `ERR_SHOW`.
- Une trace est journalisée sans les arguments de ses appels : aucun mot
  de passe ne se retrouve donc dans un journal.
- Les noms donnés par un client sont écrits dans les journaux comme des
  chaînes JSON : aucune ligne ne peut donc être falsifiée.
- Les utilisateurs qui ont un rôle au-dessus de `standard` sont tracés à
  chaque accès, et chaque refus est tracé avec sa raison
  ([Hébergement §12](hosting.md#12-erreurs-et-journaux)).

### 9.7 La livraison

Un bundle ne copie que ce que nomme `Bundle::KEEP_LIST` : pas de `.env`,
pas de configuration locale, pas de clé, pas de test (7.6).
`php nyuRun install checkAll` échoue sur ce qui exposerait un serveur ou
l’arrêterait (un secret ou une clé à deux facteurs trop courts, une liste
de proxys ouverte à tous, des réglages d’accès ou un profil qui ne peuvent
pas s’appliquer, des clés de tokens inutilisables ou qui ne vont pas
ensemble), et avertit sur ce qui mérite un coup d’œil (des erreurs
montrées sur un serveur de préproduction, une version de PHP sans ses
derniers correctifs de sécurité, un fichier de mots de passe qui accepte
des empreintes faibles, des origines ouvertes à toutes, des tokens liés à
aucun émetteur ni à aucune audience).

### 9.8 Ce qui revient à l’hébergeur, ou à plus tard

- Le serveur web, TLS, le pare-feu et `php.ini`
  ([Hébergement](hosting.md)).
- L’hôte transmis (`X-Forwarded-Host`) n’est pas lu.
- Les certificats clients dont un proxy gère le TLS, l’épinglage des
  certificats et les limites par route sont laissés à la version 1.1
  (1.5).

---

## 10. Couche de données

Un run atteint ses données à travers des modèles, des enregistrements et
des listes (8.5), jamais à travers des requêtes. Ce chapitre décrit ce
qu’il y a dessous.

```
DBRec, DBLst            les modèles des runs
   |
DBStore\Store           pour un enregistrement et une liste — trouver, insérer, modifier, supprimer, transactions
   |-- SqlStore         les types SQL, par leur gestionnaire
   |      |-- DBHndlr\MySQL, PgSQL, SQLite (PDO)
   |-- MongoStore       MongoDB, par son pilote
   |
DBCon                   une connexion par base de données configurée
```

### 10.1 Connexions

Les bases de données sont celles de `conf/database.json` (6.6) : une, ou
plusieurs par leur nom, la première étant celle par défaut. Une connexion
est ouverte la première fois qu’elle sert, et gardée pour la requête :
`DBCon::linkNamedDbCon('name')` la donne, et `''` donne la base par
défaut. Un modèle nomme une autre base que celle par défaut par sa
connexion.

Chaque type SQL a son gestionnaire, sur PDO, qui écrit les conditions dans
son propre dialecte : MySQL (MariaDB compris), PostgreSQL et SQLite. Le
gestionnaire SQLite active les clés étrangères pour chaque connexion,
comme les autres bases les ont. MongoDB est une base à part (10.8).

### 10.2 Nommage

Les tables du framework suivent un seul schéma de nommage, sur lequel
comptent les enregistrements et les listes pour trouver leurs relations
sans qu’on les leur dise :

| Élément | Règle | Exemple |
|---|---|---|
| table | un nom au pluriel | `shows`, `events` |
| colonne | 10 caractères | `systemName`, `createDate` |
| identifiant | `id_` et le nom de la table au singulier | `id_show` |
| lien vers une autre table (un à plusieurs) | le nom de cette table au singulier, et `_id` | `show_id` |
| lien vers un parent dans la même table | `parent_id` | |

La longueur des colonnes est la convention du framework : les propres
tables d’un projet peuvent utiliser d’autres longueurs, tant que les
identifiants et les liens suivent le nommage. Une relation peut toujours
nommer ses champs quand une table ne le suit pas.

### 10.3 Enregistrements

Un enregistrement (`DBRec`) est lié à sa table quand il est créé : sa clé
et ses champs sont lus dans la base, une fois par table et par requête, ou
dans le cache de configuration avec `TBL_CACH`.

- **Le chargement** : `loadRecordList($key)` par sa clé,
  `findRecordList($filter)` pour le premier qui correspond à un filtre
  (10.5). Les deux donnent les champs de l’enregistrement, ou `false`, et
  chargent ses relations déclarées.
- **Les relations** : les tables enfants qu’un enregistrement déclare sont
  chargées avec lui, chacune sous le nom de sa table avec une majuscule au
  début (`Events`) ; un parent, sous son nom au singulier (`Show`). Un
  enfant peut être déclaré avec son ordre de tri.
- **La sauvegarde** : `setFieldsArray($values)` prend les valeurs des
  champs que contient la table et laisse les autres. `insertOrUpdate()`
  modifie la ligne de la clé, ou en insère une nouvelle, avec la clé
  donnée ou créée par la base. La clé elle-même n’est jamais modifiée. La
  méthode rend la clé, ou `false` : telle que la base l’a donnée pour une
  modification, comme un nombre quand la base l’a numérotée, ou telle que
  l’application l’a fixée sinon.
- **La suppression** : `dropThisRecord($children)` supprime
  l’enregistrement et les lignes des tables enfants nommées dans l’appel,
  en une seule transaction, et rien d’autre. La suppression est tracée sur
  `runner`, avec l’utilisateur et l’adresse de la requête. Une base sans
  transactions supprime un enregistrement seul, et refuse une suppression
  qui nomme des enfants.

### 10.4 Listes

Une liste (`DBLst`) est liée à sa table, réglée, puis lue :

| Méthode | Règle |
|---|---|
| `setListFilters($filter)` | le filtre (10.5), à la place du précédent |
| `setReadColumns('a, b, count(*) as n')` | les champs lus, et des fonctions sur eux, avec des alias |
| `setGroupFields($fields)` | les champs par lesquels les lignes sont regroupées |
| `setOrderFields($fields)` | l’ordre ; un champ écrit `^name` est trié par ordre décroissant |
| `setLimitOffset($count)` | combien de lignes, à partir d’un nombre ou de `"count,offset"` ; `DEF_ROWS` quand le nombre n’en est pas un |
| `joinOtherTable([table, field, field])` | une table jointe (`LEFT JOIN`), SQL seulement |
| `readRowsOfList()` | lit les lignes ; `listColumnVals('field')` lit un champ de chacune |
| `loadChildRecrd($table)` | rattache à chaque ligne lue ses lignes d’une table enfant |

### 10.5 Filtres

Un filtre est un tableau de noms de champs et de valeurs. Il se lit de la
même façon pour toutes les bases (`DBStore\Filter`), et chaque base
l’écrit dans son propre langage :

| Écrit | Choisit les lignes où le champ |
|---|---|
| `'field'=>value` ou `'field='=>value` | est égal à la valeur |
| `'field!'=>value` | en diffère |
| `'field<'`, `'field<='`, `'field>'`, `'field>='` | est en dessous ou au-dessus |
| `'field'=>[a, b]`, `'field!'=>[a, b]` | est l’une des valeurs, ou aucune d’elles |
| `'field~'=>'*text*'`, `'field!~'=>'*text*'` | correspond au texte, ou non ; `*` est n’importe quel texte, `\*` une étoile et `\\` une barre oblique inverse |
| `'field?'=>false`, `'field?'=>true` | ne contient aucune valeur, ou en contient une |

Une ligne doit remplir chaque condition du filtre. Une valeur est comparée
telle quelle : aucun de ses caractères n’est un joker, donc une valeur
prise dans une requête ne peut pas élargir une recherche. Seul un champ
nommé avec `~` cherche une correspondance, et seul `*` y est un joker ;
`Filter::quoteMatchText()` protège un texte pris dans une requête, pour
qu’il ne corresponde qu’à lui-même. Le motif est lu une fois, dans
`Filter` : chaque base écrit ce qui a été lu (`LIKE … ESCAPE`, une
expression régulière). Une valeur `null` ne correspond à rien : une entrée
vide ne peut donc pas choisir les lignes restées sans valeur. Un nom de
champ est fait de lettres, de chiffres et de tirets bas, avec une table en
option (`shows.title`) ; tout le reste est refusé.

### 10.6 Transactions

Le stockage d’une base de données (son `Store`) dit si elle a des transactions
(`canTransaction()`), et en ouvre une (`addTransaction()`), la valide
(`setTransaction()`) ou l’annule (`dropWholeTrnsc()`). Une action les
atteint par la connexion :

```php
$dbStore = DBCon::linkNamedDbCon()->getStoreObject();
$dbStore->addTransaction();
// records saved and removed
$dbStore->setTransaction();
```

Les types SQL ont des transactions ; MongoDB, tel que nyuRuns l’utilise,
n’en a pas.

### 10.7 Valeurs binaires

Une image, un son ou n’importe quel fichier est gardé dans un champ comme
une valeur binaire :

- elle est écrite à partir d’un flux (`Data\Fluxd`) donné comme valeur du
  champ : un fichier sur le serveur, le corps de la requête, des octets en
  mémoire. Entre le flux et la base de données, elle est gardée entière,
  écrite d’un seul bloc, et relue jusqu’à sa limite (une valeur plus
  grosse est refusée) ;
- elle est relue comme un flux, seulement quand le champ est nommé parmi
  les champs demandés : une lecture qui ne le nomme pas la laisse de côté ;
- une action l’envoie telle quelle avec le format de réponse `rawBytes`.

### 10.8 MongoDB

MongoDB est atteint par l’extension `mongodb` de PHP, comme la base
`MongoStore`. Une table est une collection, une ligne un document, et la
clé de chaque table est l’`_id` que donne MongoDB. Ce qui diffère des
types SQL :

- une collection n’a pas de liste fixe de champs ;
- les valeurs sont comparées avec leur type : le texte `'2'` n’est pas le
  nombre `2` ;
- les fonctions, les jointures et les regroupements sont refusés ;
- il n’y a pas de transactions (il leur faudrait un replica set) : un
  enregistrement est donc supprimé seul, et nommer ses enfants dans la
  suppression est refusé.

### 10.9 Tables livrées

`conf/schema/` contient les tables qu’utilise le framework, un fichier par
table et par type de base : `users` et `devices`, en `.mysql_db.sql`
(MariaDB compris), `.postgres.sql` et `.sqlitedb.sql`.
`php nyuRun install userBase` les crée ; une table qui existe reste telle
qu’elle est. Les fichiers `migrate-<table>-<version>.<type>.sql` font
évoluer une table créée par une version plus ancienne, en renommant ses
colonnes au besoin : ils s’appliquent à la main, après une sauvegarde.

### 10.10 Des identifiants qui voyagent

Un enregistrement copié dans une autre base de données ou une autre
application garde son identité grâce à un UUID de version 7
(`Tools\UniqueId`, RFC 9562). Il commence par l’heure de sa création, donc
les identifiants se trient dans cet ordre, et finit par des bits
aléatoires. En 1.0, le framework les crée, mais n’en donne pas un tout
seul à un enregistrement : une table qui en a besoin le garde dans un
champ à lui, à côté de sa clé, rempli par l’action qui crée
l’enregistrement.

---

## 11. Structure des dossiers

### 11.1 Le projet

```
nyuRuns/
  www/                    le seul dossier que sert le serveur web
    index.php             le seul point d’entrée : lance le Runner, qui choisit son profil dans conf/doorways.lst
  src/                    le framework, espace de noms nyu\ (chapitre 5)
  runs/                   les applications (chapitre 8)
    Auth/  Users/  Shows/
    Shared/               les modèles et les plug-ins partagés par les runs
  conf/                   la configuration (chapitre 6)
    presets/              les profils (.rps)
    routes/               les routes déclarées du site (.rte), un dossier par profil qui a les siennes
    schema/               les tables livrées et leurs migrations (.sql)
    mails/<language>/     les gabarits des e-mails d’accès
    keys/                 les clés des tokens (locales)
  pages/                  les pages et les fichiers statiques des sites (dispatch.fileRoot)
  media/                  les fichiers médias du projet, gardés hors de git
  temp/                   ce qu’écrit le framework (local)
  test/                   la suite de tests (chapitre 13)
  vendor/                 les bibliothèques, installées par Composer (locales)
  docs/                   la documentation, dans docs/en (fr, es) ; docs/online, son HTML et la référence du code (produits, non suivis)
  nyuRun                  la ligne de commande : php nyuRun <command>
  composer.json, composer.lock
  .env.example            les noms des secrets
  README.md, HOSTING.md, CONTRIBUTING.md, LICENSE, NOTICE
```

Le serveur web sert `www` et rien au-dessus : la configuration, le code,
les journaux et les tests ne sont jamais atteignables par HTTP. Les pages
et les fichiers d’un site sont servis par le framework depuis `pages/` (ou
le dossier que nomme son profil), seulement par les routes déclarées pour
eux.

### 11.2 Suivi, local et produit

| Sorte | Où | Dans git | Dans un bundle |
|---|---|---|---|
| le framework et ses runs | `src/`, `runs/`, `www/`, `nyuRun` | oui | oui |
| les exemples de configuration et les profils, routes, schémas, gabarits | `conf/` | oui | oui |
| les pages des sites | `pages/` | oui | oui |
| la configuration locale | `.env`, `conf/access.json`, `database.json`, `mailer.json`, `users.lst`, `.htpasswd` | non | non |
| les clés des tokens | `conf/keys/` | non | non, sauf si les `addPaths` d’un mode nomment un fichier |
| les modes de bundle du projet | `conf/bundle.lst` | oui | non |
| les bibliothèques | `vendor/` | non | oui |
| ce qu’écrit le framework | `temp/` | non | non |
| les tests, les documents | `test/`, `docs/` | oui | non |
| le HTML de la documentation et la référence du code | `docs/online/`, produit par `.phpdoc/build.ps1` | non | non |
| les bundles | `dist/` | non | |

Un bundle ne copie que les chemins de `Bundle::KEEP_LIST` (`src`, `runs`,
`www`, `pages`, `conf`, `vendor`, les fichiers de Composer, `nyuRun`,
`LICENSE`, `NOTICE`, `.htaccess`), moins ceux de `DROP_LIST` (la
configuration locale, chaque fichier de `conf/keys`, `bundle.lst`), plus
les chemins qu’ajoute son mode. Chaque serveur crée ses propres clés avec
`install complete`.

### 11.3 `temp/`

| Dossier | Contient | Supprimé par |
|---|---|---|
| `logs/` | les journaux, un fichier par canal (`LOG_PATH`) | `cleanup cleanDir logFiles` |
| `limits/` | les décomptes de la limite par adresse (`tryLimit.dataPath`) | `cleanup cleanDir tryLimit`, `cleanup freeAddr` |
| `archives/` | les fichiers de configuration tels qu’ils étaient avant qu’une commande ne les change | `cleanup cleanDir archives` |
| `cache/` | le cache de configuration, un fichier par profil (`CNF_CACH`) | `cleanup cleanDir cacheDir`, `setting clrCache`, toute commande qui écrit dans `conf/` |
| `mails/` | les e-mails d’un compte `emlFiles` | à la main |

Seul l’utilisateur sous lequel tourne le serveur web a besoin d’y écrire
(3.7).

### 11.4 Un run

```
runs/<App>/
  Actions.php             les actions, classe Actions de nyu\Runs\<App>
  mods/<Name>.php         les modèles, classe <Name> de nyu\Runs\<App>\mods
  input/<action>.json     les entrées de chaque action
  routes.rte              ses propres routes
  pages/<name>.html       les pages de ses réponses
  mails/<language>/       ses propres gabarits d’e-mail
  Shell.php               sa propre commande
```

### 11.5 Les tests

```
test/
  run.php                 lance la suite, ou les groupes nommés
  unit/  audit/  data/  http/   les groupes (chapitre 13)
  lib/                    les outils des tests
  fixtures/               les configurations, routes, pages, runs et fichiers qu’utilisent les tests
```

---

## 12. Conventions de code

nyuRuns suit un seul ensemble de conventions, dans le framework et dans
les runs qu’il livre. Une contribution les suit aussi, et le groupe de
tests `audit` vérifie la plupart d’entre elles (12.5).

### 12.1 Des noms de longueur fixe

Chaque nom est en `camelCase` et a la longueur de sa sorte : un nom dit
donc d’un coup d’œil ce qu’il est. Les longueurs laissent la place à des
mots courts entiers plutôt qu’à un seul mot comprimé. Les classes sont
l’exception : `PascalCase`, de n’importe quelle longueur.

| Élément | Longueur | Exemple |
|---|---|---|
| méthode | 14 | `makeAccessCode()`, `getModelObject()` |
| paramètre | 12 | `$requiredRole`, `$appNameToRun` |
| propriété | 10 | `$requestObj`, `$modelStack` |
| variable locale | 8 | `$fileName`, `$rowsList` |
| variable de boucle | 6, `one` ou `nth` suivi de l’élément | `$oneRow`, `$nthRow` |
| constante | 9, sous la forme `XXXX_YYYY` | `KEYS_LIST`, `TYPE_LIST` |

Échappent aux longueurs :

- les actions de chaque run, livré ou propre au projet (`login`, `index`,
  `view`, `card`) : leur nom est le mot du chemin, et l’audit laisse
  tranquille chaque méthode de `runs/*/Actions.php` ;
- les modes de bundle, nommés librement comme une action (`local`,
  `stage`, `final`, et ceux de `conf/bundle.lst`) : ce à quoi sert un
  mode, il le porte comme `ENV_NAME` ;
- les méthodes PSR-3 de `Tools\Logger` (`error`, `warning`, `notice`,
  `info`, `debug`) et ses constantes de niveau (`LEVL_ERROR`, …) ;
- la variable d’un `catch`, `$e` ;
- les méthodes magiques de PHP, et les méthodes qu’impose une interface
  PHP (`jsonSerialize`, `getIterator`, `count`, …) ;
- les noms standard, quelle que soit leur longueur : les méthodes HTTP,
  les extensions de fichiers, les types MIME, les noms `E_*`, les noms
  d’en-têtes ;
- pour l’instant, les variables de boucle de 3 lettres listées dans
  `test/audit/looplabels.txt`.

Un nom se construit à partir d’emplacements, toujours dans le même ordre,
et `NAMERULES.md` contient toute la grammaire. En bref :

- une méthode est un verbe d’une liste fermée, puis ce sur quoi elle agit,
  puis la sorte de ce qu’elle rend quand ce n’est pas évident
  (`readRouteList()`, `makeAccessCode()`) ;
- un paramètre est son rôle, son sujet et sa sorte (`$requiredRole`,
  `$givenUserName`), une propriété son sujet et sa sorte (`$accessConf`),
  une variable locale un ou deux mots simples (`$fileName`, `$response`) ;
- un oui ou non est toujours une question : `is`, `has`, `can`, `needs` ou
  `was`, puis le sujet (`hasWrongSecret()`, `$isLoggedIn`) ;
- chaque mot est écrit en entier quand il tient, ou sous sa seule forme
  courte de 3, 4 ou 5 lettres tirée du glossaire (`Rqst`, `Mssg`, `Modl`) ;
  la longueur s’atteint par une écriture plus longue d’un emplacement,
  jamais par un mot en plus.

Un concept a un seul mot partout (`$requestObj` est la requête dans
chaque classe), et un mot a un seul sens.

La même règle vaut au-delà du code :

| Élément | Règle | Exemple |
|---|---|---|
| clés d’un bloc de configuration | 8, dans les fichiers JSON et dans la cible d’une route | `carrWith`, `appToRun` |
| clés de `system.ini` | 8, sous la forme `XXX_YYYY` ; les codes parmi ses valeurs font 4, en majuscules | `LOG_MODE`, `DEVS`, `INDX` |
| codes produits à l’exécution | 8 ; en minuscules quand ils nomment un fichier (un profil, une sorte d’e-mail, un type de base) | `standard`, `advanced`, `delegate`, `managers` |
| colonnes d’une table | 10 | `systemName` |
| commandes de `php nyuRun`, et leurs sous-commandes | 7 pour une commande, 8 pour une sous-commande | `install complete`, `cleanup cleanDir` |
| options de `php nyuRun` | 8 | `--noPrompt`, `--yesToAll` |
| formats de `Tools\Variable` | 7, pour que chaque méthode `formats…()` fasse 14 | `OutText`, `formatsOutText()` |

### 12.2 Les types

- Chaque paramètre et chaque valeur de retour est typé, les types écrits
  avec une majuscule : `String`, `Array`, `Bool`, `Int`, `Float`, `Mixed`,
  `Void`, `Never`.
- Une valeur de plusieurs types reçoit son union précise (`Array|Bool`,
  `?String`) ; `Mixed` est gardé pour ce qui peut vraiment être n’importe
  quoi.
- Un paramètre dont la valeur par défaut est `null` est déclaré nullable
  (`?String $userNameText=null`).
- `declare(strict_types=1)` n’est pas utilisé.

### 12.3 La documentation

- Chaque méthode publique a un docblock : ce qu’elle fait, puis `@param`
  pour chaque paramètre, `@return`, et `@throws` pour ce qu’elle laisse
  passer.
- Une classe s’ouvre sur un docblock qui dit ce qu’elle est et comment
  s’en servir.
- Un commentaire dit pourquoi, pas quoi, et s’écrit en anglais, comme les
  noms.
- La référence du code est construite à partir des docblocks par
  phpDocumentor (`phpdoc.dist.xml`), dans `docs/online/reference`.
  `pwsh .phpdoc/build.ps1` la construit avec les pages de ces documents
  dans chacune de leurs langues (`docs/en`, `fr`, `es`), écrites par
  l’analyseur Markdown et le générateur HTML propres au framework
  (`.phpdoc/site`) : un seul dossier dans `docs/online`, que git ne suit
  pas, à mettre en ligne tel quel.

### 12.4 Les erreurs

- Une erreur du framework est une `RuntimeException`, ou une classe qui
  l’étend (`StopRun`, `ShellStop`, `ParseError`, `WriteError`,
  `MailFailure`, `QrCodeError`).
- Un message dit ce qui s’est mal passé avec des mots qu’un développeur
  lit dans un journal. Il ne contient jamais de secret, et les noms donnés
  par un client y sont écrits comme des chaînes JSON.
- Ce qu’un client peut apprendre est décidé par le statut, pas par le
  message d’une exception (4.7).

### 12.5 L’audit

`php test/run.php audit` lit `src/` et `runs/` avec le tokenizer de PHP,
et échoue sur :

- un fichier qui ne s’analyse pas ;
- une classe qui n’est pas en `PascalCase`, ou une constante qui n’est pas
  en `XXXX_YYYY` ;
- une méthode, un paramètre, une propriété, une variable locale ou de
  boucle qui n’a pas sa longueur ;
- une méthode publique sans docblock ;
- un paramètre ou une valeur de retour sans type.

`php test/audit/ConventionTest.php --list` affiche chaque constat, ceux
qui sont acceptés compris. Les longueurs des clés de configuration, des
colonnes et des codes ne sont pas auditées : elles sont vérifiées quand un
changement est relu.

### 12.6 Les contributions

Une contribution est signée selon le Developer Certificate of Origin
(`git commit -s`), apporte ses tests, et passe la suite et l’audit, comme
l’explique [`CONTRIBUTING.md`](../../CONTRIBUTING.md) (en anglais). Un nom
nouveau ou renommé est proposé avant d’être écrit, puisqu’il change la
façon dont le code se lit.

---

## 13. Tests

La suite est faite de scripts PHP qui se vérifient eux-mêmes, sans autre
dépendance que PHP et ses extensions : il n’y a aucun framework de test à
installer. Elle contient environ 4 500 vérifications, et
[`test/README.md`](../../test/README.md) (en anglais) donne le détail.

### 13.1 La lancer

```
php test/run.php                 tous les groupes
php test/run.php unit audit      les groupes nommés
php test/run.php Variable        les scripts dont le nom contient le mot
php nyuRun testing              unit, audit et data, depuis la ligne de commande
```

Chaque `*Test.php` tourne dans son propre processus PHP, et peut être
lancé seul (`php test/unit/VariableTest.php`). Le lanceur affiche chaque
script, puis le total des vérifications réussies, échouées et sautées, et
se termine avec un code d’échec quand une vérification échoue.

### 13.2 Les groupes

| Groupe | Scripts | Vérifie |
|---|---|---|
| `unit` | 95 | chaque partie seule : les formats, la requête, le routeur, l’accès, les porteurs et les vérifications, les tokens, les limites, les journaux, la réponse, le gestionnaire d’erreurs, les documents, les images, les sons, les e-mails, la ligne de commande |
| `data` | 24 | les bases de données : les gestionnaires, les bases, les filtres, les enregistrements, les listes, la base d’utilisateurs, les appareils, les déplacements entre bases, les runs qui s’en servent ; sur des bases SQLite temporaires, et sur de vrais serveurs quand ils sont configurés |
| `http` | 1 | des requêtes entières par le serveur web intégré à PHP : statuts, en-têtes, corps, routes, accès, pages, fichiers |
| `audit` | 1 | les conventions de code dans `src/` et `runs/` (12.5) |

### 13.3 Ce dont les tests ont besoin

- **PHP et ses extensions.** Les groupes `data` et `http` ont besoin de
  `pdo_sqlite`, et se sautent eux-mêmes sans lui ; les vérifications à
  deux facteurs ont besoin de `openssl` ; les images, les sons et les
  documents sautent ce que leur extension (`gd`, `imagick`, `zip`) ou
  ffmpeg ne permet pas. Un PHP en ligne de commande sans `php.ini` reçoit
  ses extensions par `PHPRC`, qui pointe vers un dossier dont le `php.ini`
  les liste : le lanceur et le test `http` démarrent d’autres processus
  PHP, qui ne reçoivent aucune option `-d`.
- **De vrais serveurs de bases de données, au choix.** `MySQLTest`,
  `PgSQLTest` et `MongoDBTest` lancent les mêmes vérifications sur de vrais
  serveurs, à partir des noms `NYU_TEST_*` de `.env`. Chacun travaille dans
  une base de données à lui dont le nom contient `test` (`nyuruns_test`),
  refuse toute autre, et se saute lui-même quand son serveur n’est pas
  configuré ou ne répond pas.
- **Rien hors du projet.** Les tests écrivent dans le dossier temporaire
  du système ou dans leurs propres dossiers ; le groupe `http` écrit dans
  le `temp/logs` du projet. Un test qui compte les mauvais identifiants
  règle ses propres limites : le `temp/limits` du projet reste donc
  tranquille.

### 13.4 Écrire un test

```php
<?php
require __DIR__.'/../lib/harness.php';

checkIdentical('label', $actual, $expected);          // strict comparison
checkTrueValue('label', $condition);
checkPregMatch('label', $text, '/pattern/');
checkForThrows('label', function(){ ... }, 'RuntimeException');

finishChecking();
```

- Un script vit dans `test/<group>/<Name>Test.php`, et suit les
  conventions du chapitre 12.
- Une notice ou un avertissement levé par le code testé fait échouer le
  script, sauf si le test le recueille (`takePhpNotices()`).
- `recordSkipping()` dit pourquoi une vérification ne peut pas tourner
  ici ; `inTemporaryDir()` donne un chemin dans le dossier temporaire ;
  `mockRequestObj()` et `makeFakeServer()` construisent une requête sans
  serveur web.
- Les fixtures (des configurations, des routes, des pages, un run de test
  nommé `Probe`, un fichier des utilisateurs, des documents) vivent dans
  `test/fixtures`.
- Un test qui lit les journaux branche son propre dossier
  (`Logger::loadLogsConfig()`), et le débranche à la fin.

### 13.5 Avant une version

Une version est close par une passe complète de la suite : tous les
groupes, `http` compris, sur la plus ancienne version de PHP prise en
charge (8.2) et sur la plus récente, avec les serveurs SQL que la version
prend en charge (chapitre 3) et MongoDB.

---
