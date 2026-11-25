# nyuRuns — Spécifications fonctionnelles

Version 1.0

## Sommaire

1. [Introduction](#1-introduction)
2. [Points clés des exigences](#2-points-clés-des-exigences)
3. [Vue d’ensemble de la conception](#3-vue-densemble-de-la-conception)
4. [Architecture du système](#4-architecture-du-système)
5. [Mise en œuvre des processus](#5-mise-en-œuvre-des-processus)
6. [Configuration et montée en charge](#6-configuration-et-montée-en-charge)
7. [Résultats de performance](#7-résultats-de-performance)
8. [Recommandations de développement](#8-recommandations-de-développement)

A. [Réponses et statuts](#a-réponses-et-statuts)

---

## 1. Introduction

### 1.1 Ce que couvre ce document

Ce document décrit ce que fait nyuRuns et comment se déroulent ses
processus : qui utilise un service construit avec lui, ce que chacun peut
y faire, et les étapes de chaque processus, de la connexion à la livraison
d’un projet. Il parle de comportement, pas de code. Les noms du code
n’apparaissent que là où il en faut un pour trouver un réglage ou une
commande.

Il s’adresse aux développeurs, et aux analystes et responsables produit
qui décident de ce que doit faire un service construit avec nyuRuns. La
façon dont le framework lui-même est construit est décrite dans les
[Spécifications techniques](techspecs.md).

### 1.2 nyuRuns en quelques mots

nyuRuns est le back-end d’une application. Un front-end (une page web, une
app mobile, un autre programme) lui envoie des requêtes en HTTP, et nyuRuns
répond avec des données, un fichier ou une page. Entre les deux, il prend
en charge toute la requête. Il :

- trouve ce qui est demandé, à partir de l’URL de la requête ;
- vérifie qui demande, et s’il en a le droit ;
- ne garde que les valeurs que la requête peut porter, chacune dans le bon
  format ;
- lance l’application demandée, qui lit et écrit ses données ;
- écrit la réponse dans le format qu’attend le front-end, erreurs
  comprises.

Autour de tout cela, il fournit les outils dont un service a besoin : les
utilisateurs et leurs accès, les bases de données, les documents, les
images, les sons, les e-mails, les journaux, et une ligne de commande pour
installer, configurer, tester et livrer un projet.

### 1.3 Qui utilise un service construit avec nyuRuns

| Acteur | Qui | Ce qu’il fait |
|---|---|---|
| **Visiteur** | toute personne non connectée | atteint ce qui est ouvert à tous : une page publique, la connexion, l’inscription, la réinitialisation d’un mot de passe oublié |
| **Utilisateur standard** | un utilisateur connecté avec le rôle `standard` | utilise le service avec les droits du rôle le plus bas |
| **Utilisateur avancé** | le rôle `advanced` | obtient ce que le service réserve à son équipe |
| **Délégué** | le rôle `delegate` | lit les utilisateurs et valide les inscriptions (`users/index`, `view`, `approve`) ; ne modifie jamais un compte |
| **Gestionnaire** | le rôle `managers` | gère les utilisateurs : les crée, les bloque, les réinitialise et les supprime, change leur rôle, leur adresse et leur langue, et les valide aussi ; toutes les autres actions de `Users` sont à lui seul |
| **Appareil** | une machine avec son propre token, qui agit pour un utilisateur | parle au service sans que personne ne se connecte : une borne, un capteur, un serveur domestique |
| **Application** | un autre programme qui détient une clé | appelle le service au nom de l’un de ses propres utilisateurs |
| **Administrateur** | la personne qui gère le serveur | installe, configure, vérifie et met à jour le projet en ligne de commande |

Les rôles sont classés : chacun a tous les droits des rôles qui le
précèdent, de `standard` à `advanced`, `delegate` puis `managers`. Ce que
chaque rôle peut atteindre dépend du projet, route par route.

### 1.4 Ce que couvre la version 1.0

La version 1.0 prend en charge toute la requête et propose plusieurs
façons de se connecter et de porter une identité. Elle gère les
utilisateurs et leurs e-mails d’accès, et prend en charge cinq types de
base de données, les documents aux formats texte, données et bureautiques
habituels, les images, les sons, les e-mails et la ligne de commande.
Certaines parties de la vision d’origine sont laissées à la version 1.1, et
quelques-unes ont été abandonnées. La liste complète se trouve à un seul
endroit, les
[Spécifications techniques, 1.4 à 1.6](techspecs.md#14-ce-que-contient-la-version-10).
Le chapitre 2 la parcourt sous l’angle des besoins.

### 1.5 Les termes de ce document

| Terme | Signifie |
|---|---|
| **service** | un projet construit avec nyuRuns, tel que ses utilisateurs le voient |
| **front-end** | ce qui envoie les requêtes : une page web, une app mobile, un programme |
| **run** | une application du projet, comme `Users` ou `Shows` |
| **action** | ce que fait un run pour une requête : lister, afficher, créer, modifier, supprimer |
| **route** | l’URL qui mène à une action, une page ou un fichier |
| **profil** (« preset » dans le code) | le profil du service : comment on l’atteint, comment l’accès est vérifié, et comment il répond |
| **base d’utilisateurs** | l’endroit où les utilisateurs sont gardés : un fichier des utilisateurs, ou une table de base de données |
| **rôle** | ce qu’un utilisateur peut atteindre : `standard`, `advanced`, `delegate`, `managers` |
| **statut** | où en est un compte : `awaiting`, `inReview`, `approved`, `disabled` |

---

## 2. Points clés des exigences

### 2.1 La vision d’origine

Les Exigences de nyuRuns le décrivent comme un micro-framework pour
construire et déployer le back-end d’applications entières sous forme de
services RESTful. Le but est de simplifier la construction des API en
partageant le travail : les développeurs écrivent ce dont leur métier a
besoin, et le framework s’occupe du reste. Il repose sur quatre principes :
le développement dirigé par les modèles (MDD), la simplicité (KISS), REST,
et les opérations que reçoit chaque source de données (créer, lire,
modifier, supprimer et lister, ou CRUDL).

Ce chapitre passe en revue les besoins que nomme cette vision, et dit où en
est la version 1.0 sur chacun. Les Exigences elles-mêmes ont leur propre
document.

### 2.2 Les besoins, et où en est la 1.0

| Statut | Signifie |
|---|---|
| **Atteint** | la 1.0 fait ce que demande le besoin |
| **En partie** | la 1.0 en fait une partie, et le tableau dit laquelle |
| **Version 1.1** | laissé à la version suivante |
| **nyuTron** | déplacé vers nyuTron, le projet séparé d’outils autour de nyuRuns |

| Besoin | Statut | Dans la 1.0 |
|---|---|---|
| Construire et déployer le back-end d’une application entière, sous forme de services RESTful | Atteint | des ressources atteintes par leur chemin, des méthodes qui disent ce qui est fait, des statuts qui disent comment ça s’est passé ; une ligne de commande installe, vérifie et livre le projet |
| Prendre en charge toute la requête HTTP, pour que les développeurs écrivent moins | Atteint | le routage, l’accès, les entrées, l’action, la réponse et ses erreurs, en une seule séquence (5.1) |
| Des composants dirigés par les modèles de données (MDD) | Atteint | une application travaille à travers des modèles de ses données, enregistrements et listes, jamais à travers des requêtes SQL |
| Les opérations CRUD prêtes à l’emploi | En partie | chaque source de données reçoit ses opérations CRUDL à travers les modèles, et `makerun` écrit les actions du run d’une table SQL : aucune n’est écrite à la main. Servir une table par la seule configuration n’est pas dans la 1.0 |
| D’autres sources de données que les bases : fichiers XML, JSON, INI et texte | En partie | les fichiers dans ces formats, et bien d’autres, sont lus et écrits comme des documents par une action. Les utiliser comme source d’entrées configurée est laissé à la 1.1 |
| Le routage de chaque requête, avec des solutions de repli | Atteint | les routes sont trouvées à partir du chemin, ou déclarées par le site ou par chaque run ; une route déclarée peut aussi rediriger, ou servir un fichier, un dossier ou une page ; un site peut avoir ses propres pages d’erreur |
| Le contrôle d’accès (ACL) et les tokens | Atteint | des rôles classés, demandés par route ou par action ; des tokens signés, des sessions, des clés d’API, des certificats clients ; plusieurs bases d’utilisateurs |
| Des gestionnaires de données : bases, fichiers, images, sockets | En partie | cinq types de base de données, des documents, des images, et des sons, que la vision ne mentionnait pas. Les sockets sont laissés à la 1.1 |
| Une sortie avec des fonctions de cache | En partie | les fichiers statiques sont envoyés avec leur version et leur date, et un client qui les a déjà reçoit un 304. Les réponses de données ne sont pas mises en cache |
| La gestion des erreurs | Atteint | les erreurs sont écrites dans le format de la réponse, journalisées, et jamais détaillées en production |
| Une architecture en couches (multi-tiers) | Atteint | la requête, l’accès, l’application, ses modèles et leurs stockages sont des couches séparées (chapitre 4) |
| Rester simple | Atteint | une première installation n’a besoin d’aucune base de données, et ce qu’un projet n’utilise pas n’est pas chargé |

### 2.3 Ce que la 1.0 ajoute à la vision d’origine

La construction du framework a fait apparaître des besoins que la vision
d’origine ne mentionnait pas. La version 1.0 les couvre :

- **Les utilisateurs et leur cycle de vie** : l’activation par un code, les
  statuts, le blocage, les rôles, les mots de passe choisis et
  réinitialisés, les inscriptions validées par un gestionnaire, le tout par
  une application ou en ligne de commande (5.3 à 5.5).
- **Les e-mails d’accès**, dans la langue de chaque utilisateur.
- **Les codes à deux facteurs**, depuis une app d’authentification, ou
  envoyés par e-mail (base d’utilisateurs native seulement).
- **Les appareils**, et **les applications qui appellent pour leurs
  utilisateurs**, chacun avec son propre identifiant (5.6 et 5.7).
- **Une limite de tentatives**, par adresse et par compte.
- **Les documents** aux formats texte, données et bureautiques habituels,
  lus dans une même forme et réécrits à partir d’elle.
- **Les images et les sons**, lus, modifiés et convertis.
- **Les e-mails**, par un serveur SMTP, par PHPMailer, ou dans des
  fichiers.
- **La ligne de commande**, pour installer, configurer, tester, gérer et
  livrer un projet, avec une copie construite pour chaque environnement.

### 2.4 Ce qui a changé, et pourquoi

Les parties de la vision que la 1.0 n’atteint pas entièrement ont été
mises de côté pour terminer la version plus tôt, pas abandonnées. Les
fichiers comme sources configurées et les sockets viennent avec la version
1.1, et la génération des actions CRUDL est déjà dans la 1.0 (`makerun`),
en attendant l’assistant visuel de nyuTron autour d’elle. Le cache des
réponses de données est le seul besoin sans version prévue pour l’instant.
La liste complète de ce qui reste pour plus tard se trouve dans les
[Spécifications techniques, 1.5 et 1.6](techspecs.md#15-ce-qui-reste-pour-plus-tard).

---

## 3. Vue d’ensemble de la conception

### 3.1 Les principes, tels qu’un service les montre

**Le développement dirigé par les modèles (MDD).** Un service est décrit
par ses données. Chaque sorte de chose qu’il garde (un utilisateur, un
spectacle, une facture) est un modèle : un enregistrement d’une table, ou
une liste d’enregistrements. Les applications lisent et écrivent à travers
les modèles et n’écrivent jamais de requête SQL. Passer à une autre base
de données, ou à un autre type de base, ne touche pas aux applications,
dans la limite de ce que ce type sait faire (MongoDB, par exemple, n’a pas
de jointures).

**La simplicité (KISS).** Un service démarre avec ce dont il a besoin, et
rien de plus. Une première installation garde ses utilisateurs dans un
fichier et n’a besoin d’aucune base de données, et ce qu’un projet
n’utilise pas n’est pas chargé. Chaque partie fait une seule chose, et les
réglages sont de simples fichiers.

**REST.** Chaque ressource a sa propre URL, et la méthode de la requête dit
ce qu’on en fait. Le statut de la réponse dit comment ça s’est passé, quel
que soit le format de son corps :

| Méthode | Ce qu’on y fait | Statuts qu’on retourne (en général) |
|---|---|---|
| `GET` | lire une ressource, ou les lister | 200 avec les données, 204 quand la liste est vide, 404 |
| `POST` | créer une ressource, ou lancer une opération | 201 créée, 202 acceptée, 400 |
| `PUT`, `PATCH` | modifier une ressource | 200, 404, 409 quand la modification entre en conflit |
| `DELETE` | supprimer une ressource | 200 ou 204, 404 |

Une route peut donner à chaque méthode sa propre action : une seule URL
couvre alors toute la vie d’une ressource.

**CRUDL.** Chaque source de données reçoit les cinq mêmes opérations :
créer, lire, modifier, supprimer et lister. Une liste peut être filtrée,
triée, limitée et jointe à une autre table, et le filtre s’écrit de la
même façon pour tous les types de base.

### 3.2 Les choix derrière la conception

- **Une entrée, un profil.** Chaque requête arrive par une seule porte, et
  l’entrée dit quel profil (« preset ») s’applique : comment on atteint le
  service, qui entre, et comment il répond. Le profil se choisit une fois
  pour le service, et se change en une commande.
- **Déclaré, pas codé.** Ce qu’un service accepte est déclaré dans des
  fichiers : ses routes, les entrées de chaque action, les rôles de chaque
  route, ses réglages. On peut savoir ce que fait un service sans lire son
  code.
- **Fermé par défaut.** Dans un profil qui demande des identifiants, chaque
  route en exige un, sauf si le profil l’ouvre. Un réglage qui ne peut pas
  s’appliquer refuse toutes les requêtes plutôt que d’en laisser passer
  une. Même le profil d’une première installation demande un token : seul
  celui d’un site de pages (`statics`) est ouvert à tous.
- **Discret avec les clients.** Un client apprend ce dont il a besoin, et
  rien de plus. Ce qu’il ne peut pas atteindre est « introuvable », un
  mauvais nom et un mauvais mot de passe reçoivent le même refus, et une
  erreur en production ne donne aucun détail. Le journal a le reste.
- **Une seule forme de réponse.** Les données arrivent dans le format du
  profil (JSON, sauf indication contraire), et une erreur arrive sous la
  forme `{"error": ..., "code": ...}` dans ce même format.
- **Démarrer léger, grandir ensuite.** Un service passe d’un fichier des
  utilisateurs à une base de données, d’une base à plusieurs, et d’une
  application à plusieurs, sans être réécrit.
- **L’hébergeur gère le serveur.** Le framework prend la requête en main
  dès que PHP la reçoit. Le serveur web, TLS et le pare-feu reviennent à
  celui qui gère le serveur.

### 3.3 Les concepts

```
  front-end ──requête──▶ entrée ──▶ profil ──▶ route ──▶ run / action ──▶ modèles ──▶ stockage
                                       │         │            │
                                     accès    entrées      réponse ──▶ front-end
                               (rôles, utilisateurs)
```

| Concept | Ce que c’est | Exemple |
|---|---|---|
| **Projet** | un service : ses applications, ses réglages, ses données | un service de réservation |
| **Run** | une application du projet, qui regroupe les actions sur un même sujet | `Shows`, `Users` |
| **Action** | une chose que fait un run pour une requête | lister les spectacles, en afficher un, créer un utilisateur |
| **Route** | l’URL qui mène à une action, une page, un fichier ou une redirection | `shows/view/12`, `/notes/12` |
| **Profil** | le profil d’une entrée : l’accès, les entrées, le routage, les réponses | `restapi` pour des programmes, `website` pour un navigateur |
| **Entrées** | les valeurs que porte une requête, gardées seulement quand l’action les déclare, chacune dans le bon format | un nom, une date, une adresse |
| **Modèle** | un enregistrement ou une liste d’une table | un spectacle, la liste des spectacles |
| **Base d’utilisateurs** | l’endroit où les utilisateurs sont gardés | le fichier des utilisateurs, la table des utilisateurs |
| **Rôle** | ce qu’un utilisateur peut atteindre, par ordre : `standard`, `advanced`, `delegate`, `managers` | un gestionnaire a tous les droits des rôles qui le précèdent |
| **Statut** | où en est un compte | voir ci-dessous |
| **Réponse** | le statut, plus des données, un fichier, une page ou une erreur | `200` avec la liste des spectacles |

Les statuts d’un compte :

| Statut | Signification | Peut se connecter |
|---|---|---|
| `awaiting` | créé, en attente d’activation : son code n’a pas encore servi | non |
| `inReview` | inscrit par lui-même et confirmé, en attente de validation par un gestionnaire | non |
| `approved` | en service | oui |
| `disabled` | arrêté par un gestionnaire | non |

Le chapitre 5.3 montre comment un compte passe d’un statut à l’autre.

---

## 4. Architecture du système

### 4.1 Le service et ce qui l’entoure

```
                    ┌───────────────────────────────────────────────────────────┐
  front-ends        │                     service nyuRuns                       │
  (web, mobile,     │                                                           │
   programmes)      │  entrée ─▶ routage ─▶ accès ─▶ applications ─▶ réponse    │
      │             │              │          │           │                     │
      ▼             │              │      utilisateurs,  modèles, documents,    │
  serveur web ─────▶│              │      rôles, limites, médias, e-mails       │
  (TLS, proxy)      │              ▼      2FA │           │                     │
                    │       pages, fichiers   ▼           ▼                     │
                    │                     journaux ◀────────                    │
                    │                                                           │
                    │  ligne de commande : installer, configurer, gérer,        │
                    │  tester, livrer                                           │
                    └──────┬──────────────┬──────────────┬──────────────┬───────┘
                           ▼              ▼              ▼              ▼
                    bases de données  serveur       ffmpeg (sons)  autres applications
                  (MySQL, MariaDB,    d’e-mails                    (clés, tokens)
                   PostgreSQL,        (SMTP)
                   SQLite, MongoDB)
```

### 4.2 Les blocs fonctionnels

| Bloc | Rôle | Chapitre |
|---|---|---|
| **Entrée** | reçoit chaque requête du serveur web et applique le profil du service | 5.1 |
| **Routage** | trouve ce que demande l’URL : une action, une page, un fichier, une redirection | 5.1 |
| **Accès** | décide qui demande et s’il peut entrer : identifiants, rôles, limites de tentatives, codes à deux facteurs | 5.2 |
| **Utilisateurs** | garde les comptes et leur cycle de vie : création, activation, statuts, rôles, mots de passe, inscriptions, e-mails d’accès | 5.3 à 5.5 |
| **Applications** | les runs du projet : leurs actions, les entrées qu’ils acceptent, les modèles de leurs données | 5.8 |
| **Données** | les bases de données à travers les modèles ; les documents, images et sons lus, modifiés et écrits | 5.8, 5.9 |
| **E-mails** | envoie les e-mails par les comptes du projet | 5.10 |
| **Réponse** | écrit la réponse dans son format : des données, un fichier, une page ou une erreur | 5.1, annexe A |
| **Journaux** | trace ce qui se passe, un fichier par sujet : les erreurs, les accès, le framework, la ligne de commande, chaque application | 6 |
| **Ligne de commande** | installe, configure, gère les utilisateurs et les appareils, teste et livre le projet | 5.11 |

Seul ce dont une requête a besoin est chargé : une requête qui ne touche à
aucune base de données n’en ouvre aucune, et les blocs des données et des
e-mails ne se chargent que pour les applications qui s’en servent.

### 4.3 Les systèmes extérieurs

| Système | Nécessaire | Pour |
|---|---|---|
| **Serveur web** | toujours | reçoit les requêtes et les passe à PHP ; gère TLS, ou le laisse à un proxy placé devant |
| **Proxy inverse, CDN** | facultatif | se place devant le serveur web ; le service ne croit l’adresse qu’il transmet que si on le lui dit |
| **Serveurs de bases de données** | facultatif | les données des applications, et les utilisateurs une fois sortis du fichier des utilisateurs |
| **Serveur d’e-mails** | facultatif | les e-mails d’accès, les codes à deux facteurs envoyés par e-mail, et les e-mails propres aux applications |
| **ffmpeg** | facultatif | convertir et couper des sons |
| **App d’authentification** | facultatif, sur le téléphone de l’utilisateur | les codes à deux facteurs, sauf s’ils sont envoyés par e-mail |
| **Autres applications** | facultatif | appeler le service pour leurs utilisateurs, ou vérifier les tokens qu’il émet |

### 4.4 Plusieurs services ensemble

Un projet peut être un service à lui seul, ou l’un de plusieurs services
qui travaillent ensemble :

- **Une connexion pour plusieurs services.** Un service émet les tokens,
  signés avec sa clé privée. Les autres n’ont que sa clé publique, et s’en
  servent pour les vérifier. Ils n’émettent eux-mêmes aucun token : leur
  connexion répond que les tokens viennent d’un autre serveur.
- **Une application qui appelle pour ses utilisateurs.** Un service donne à
  une autre application une clé et les routes qu’elle peut appeler. Cette
  application appelle alors avec sa clé et un token qu’elle signe pour l’un
  de ses propres utilisateurs, qui ne se connecte jamais directement au
  service. Le rôle avec lequel elle peut agir est plafonné.
- **Un seul propriétaire par donnée**, la règle conseillée quand des
  services partagent des données. Chaque sorte de donnée appartient à un
  service, et les autres l’atteignent par les routes de ce service, jamais
  par sa base de données. Le framework ne l’impose pas, mais les clés
  d’application la rendent facile à suivre.

### 4.5 Les environnements

Un projet vit à plusieurs endroits : la machine du développeur, un serveur
de préproduction, la production, et tout serveur à lui entre les deux
(l’intégration, un aperçu pour un client). Il est livré à chacun sous la
forme d’une copie construite pour cet endroit. La copie contient le code et
les réglages d’exemple, jamais les secrets ni les réglages d’un autre
endroit. Sur chaque serveur, l’administrateur installe la copie, crée ses
secrets et ses clés, et la vérifie avant la mise en ligne (5.11).

---

## 5. Mise en œuvre des processus

Chaque processus ci-dessous est décrit comme les étapes que franchissent
un client et le service, avec la réponse à chaque sortie. L’annexe A liste
les statuts.

### 5.1 Une requête, de l’URL à la réponse

```
requête
  │
  ├─ corps trop gros ─────────────────────────────────────────────▶ 413
  ├─ pré-requête d’un autre site (OPTIONS) ───────────────────────▶ 204, avec les en-têtes autorisés
  │
  ▼ route
  ├─ rien ne répond à l’URL ──────────────────────────────────────▶ 404
  ├─ une méthode que la route n’autorise pas ─────────────────────▶ 405, avec les méthodes autorisées
  │
  ▼ accès
  ├─ des réglages impossibles à appliquer ────────────────────────▶ 500
  ├─ client en attente après trop de mauvaises tentatives ────────▶ 429, avec le délai à attendre
  ├─ aucun identifiant, ou un mauvais ────────────────────────────▶ 401 (une page : renvoi vers la connexion)
  ├─ le rôle de la route n’est pas détenu ────────────────────────▶ 404
  │
  ▼ la route répond elle-même, ou lance une action
  ├─ redirection ─────────────────────────────────────────────────▶ 301 à 308
  ├─ fichier ou page ─────────────────────────────────────────────▶ 200, 206 (une partie), 304 (déjà détenu), 404
  ├─ l’application ou l’action est inatteignable ─────────────────▶ 404
  ├─ une entrée obligatoire manque ou est invalide ───────────────▶ 400
  │
  ▼ action
  └─ le statut et les données que donne l’action ─────────────────▶ 2xx avec les données, ou une erreur
```

- Une route ouverte à tous saute l’étape de l’accès : tout le monde passe.
- Ce que le client ne peut pas atteindre est toujours le même 404, que
  l’URL n’existe pas ou que le rôle ne soit pas détenu. Ainsi, un client ne
  peut pas dresser la carte de ce que contient le service.
- La réponse est écrite dans le format du profil. Une erreur est
  `{"error": "<message>", "code": <status>}`, et un site y répond par sa
  propre page d’erreur quand il en a une.

### 5.2 Se connecter et se déconnecter

**La connexion** est une action ouverte à tous, `auth/login` :

1. Le front-end envoie le nom et le mot de passe (`userName`, `passWord`)
   en `POST`, dans le corps de la requête. Un mot de passe dans l’URL est
   refusé (400), car les URL finissent dans les journaux et dans
   l’historique des navigateurs.
2. Une adresse cliente mise en attente après trop de mauvaises tentatives
   est refoulée tout de suite (429).
3. Le nom et le mot de passe sont vérifiés dans la base d’utilisateurs. Un
   mauvais nom, un mauvais mot de passe et un compte inactif reçoivent tous
   la même réponse, `Invalid credentials` (401), et comptent contre
   l’adresse du client. Le temps de réponse ne trahit pas l’existence du
   nom.
4. Un utilisateur qui a des codes à deux facteurs doit aussi envoyer un
   code (`sentCode`) :
   - **depuis une app d’authentification** : sans code, la réponse est
     `Verification code required` (401) ;
   - **par e-mail** : une première tentative sans code en envoie un à
     l’adresse de l’utilisateur (un e-mail par minute au plus), et reçoit le
     même `Verification code required` (401). Le front-end envoie ensuite
     le nom, le mot de passe et ce code.

   Un code faux ou expiré reçoit `Invalid verification code` (401) et
   compte contre l’adresse. Chaque code n’est accepté qu’une fois.
5. Le service remet ensuite l’identité que portera le front-end : un
   **token** dans le corps de la réponse, ou une **session**, dont le cookie
   part avec la réponse. Un service qui ne fait que vérifier des tokens
   émis ailleurs n’en émet aucun (501).

Dans la base d’utilisateurs native, un compte dont le mot de passe est faux
trop de fois de suite est mis en attente un moment, quelle que soit
l’adresse d’où viennent les tentatives.

**Chaque requête suivante** porte le token (`Authorization: Bearer …`) ou le
cookie. Un token est valable jusqu’à son expiration, une heure dans les
réglages d’exemple. Une session se termine après une période d’inactivité,
ou après une durée totale. Un utilisateur bloqué par un gestionnaire est
refusé aussitôt quand le profil vérifie la base d’utilisateurs à chaque
requête.

**La déconnexion**, `auth/logout`, termine la session et fait expirer son
cookie. Un token ne peut pas être retiré : le front-end l’oublie, tout
simplement.

**Les utilisateurs connectés** peuvent aussi changer leur propre mot de
passe (`auth/setpass`, avec l’actuel) et la langue de leurs e-mails
(`auth/usrlang`).

La connexion avec un rôle au-dessus de `standard` est tracée, ainsi que
chacun de ses accès. Chaque refus est tracé avec sa raison.

### 5.3 La vie d’un compte

```
     POST users, account makeUser           auth/signup (5.4)
   (par un gestionnaire)                    (par le visiteur)
               │                                     │
               ▼  code envoyé, ou donné              ▼  code envoyé
          ┌──────────┐                          ┌──────────┐
    ┌────▶│ awaiting │                          │ awaiting │── code expiré : supprimé
    │     └──────────┘                          └──────────┘
    │          │ auth/active :                       │ auth/active :
    │          │ code, mot de passe choisi           │ code, mot de passe choisi
    │          │                                     ▼
    │          │                                ┌──────────┐
    │          │                                │ inReview │── refusé : supprimé
    │          │                                └──────────┘
    │          │                                     │ POST users/<user>/approve, account approves
    │          ▼                                     │ (par un gestionnaire)
    │     ┌──────────┐◀──────────────────────────────┘
    └─────│ approved │  bloquer  ┌──────────┐
  réinit. │          │──────────▶│ disabled │
(gestion.)└──────────┘◀──────────└──────────┘
                       débloquer
       supprimer : depuis n’importe quel statut, avec les appareils du compte
```

- **Création** : un gestionnaire crée le compte (`POST users`, ou
  `account makeUser` en ligne de commande) avec son nom et son rôle, et
  éventuellement une adresse e-mail et une langue. Il démarre en
  `awaiting`, avec un code d’activation. Quand le service envoie des
  e-mails et que l’utilisateur a une adresse, le code lui est envoyé.
  Sinon, la réponse le donne une fois au gestionnaire, qui le transmet.
- **Inscription** : un visiteur ouvre lui-même un compte `awaiting`, quand
  le service le permet (5.4).
- **Activation** : l’utilisateur envoie son nom, le code et le mot de passe
  qu’il choisit (`auth/active`). Un compte créé par un gestionnaire devient
  `approved`, et celui d’un visiteur inscrit passe en `inReview`. Un code
  faux est refusé exactement comme un mauvais nom, et compté.
- **Validation** : un gestionnaire approuve le compte
  (`POST users/<user>/approve`, ou `account approves`), qui devient
  `approved`, ou le refuse en le supprimant. Un compte en `inReview` ne peut
  ni se connecter, ni être bloqué.
- **Blocage** : un utilisateur bloqué ne peut pas se connecter, et sa
  session se termine à sa requête suivante quand le profil vérifie la base
  d’utilisateurs (5.2). Seul un compte `approved` peut être bloqué.
- **Réinitialisation** : pour un mot de passe perdu, un gestionnaire remet
  le compte en `awaiting` avec un nouveau code. L’ancien mot de passe et le
  token personnel cessent aussitôt de fonctionner. Un compte bloqué doit
  d’abord être débloqué (409 sinon).
- **Rôle, adresse, langue** : un gestionnaire peut les changer à tout
  moment. Un nom et une adresse n’appartiennent chacun qu’à un seul compte
  (409).
- **Suppression** : le compte s’en va, avec ses appareils.
- **Approbation** : un délégué ou un gestionnaire approuve une inscription.
  Elle garde son rôle, `standard` ; seul un gestionnaire le change.
- **Garde-fous** : un gestionnaire ne peut ni bloquer, ni réinitialiser, ni
  supprimer son propre compte, ni en changer le rôle, et le dernier
  gestionnaire actif ne peut pas perdre ce statut.

**Les codes** font dix caractères, `XXXX-XXXX-XX`, sans caractères qui se
ressemblent, et sont lus quelles que soient leur casse et leurs tirets.
Chacun ne sert qu’une fois, et seule son empreinte est gardée. Un code
remis par un gestionnaire dure 300 minutes. Un code envoyé par e-mail dure
autant de minutes que le disent les réglages (15 par défaut).

### 5.4 S’inscrire

Quand le service le permet et envoie des e-mails, les visiteurs peuvent
ouvrir leur propre compte, et un gestionnaire décide s’il reste.

```
visiteur                           service                                   gestionnaire
   │  auth/signup : nom, adresse         │                                              │
   │────────────────────────────────────▶│ 202, toujours                                │
   │                                     │ libre : compte awaiting, code envoyé         │
   │                                     │ nom pris : l’adresse donnée prévenue         │
   │                                     │ adresse prise : son titulaire prévenu        │
   │  auth/active : code, mot de passe   │                                              │
   │────────────────────────────────────▶│ le compte en validation                      │
   │                                     │ gestionnaires prévenus si ça s’accumule ────▶│
   │                                     │     POST users/<user>/approve                │
   │                                     │◀─────────────────────────────────────────────│
   │                                     │ le compte approuvé                           │
```

1. Le visiteur donne un nom et une adresse e-mail. La réponse est 202 quoi
   qu’il arrive : personne ne peut ainsi découvrir quels noms ou quelles
   adresses sont pris. Un nom et une adresse libres créent un compte
   `awaiting`, et un code part à l’adresse. Si le nom est déjà pris,
   l’adresse donnée en est prévenue ; si c’est l’adresse, son titulaire
   reçoit un e-mail disant que quelqu’un a essayé de l’utiliser.
2. Le visiteur confirme l’adresse avec le code et choisit un mot de passe
   (`auth/active`), et le compte passe en `inReview`.
3. Un gestionnaire l’approuve (`POST users/<user>/approve`, ou
   `account approves`), et il devient `approved`. Le refuser, c’est le
   supprimer.

**Limites** : un client ne peut s’inscrire qu’un certain nombre de fois sur
une période donnée (429 au-delà), et seul un certain nombre d’inscriptions
peut attendre en même temps (503 au-delà). Les gestionnaires reçoivent un
e-mail quand le nombre d’inscriptions en attente atteint les seuils
réglés. Une inscription dont le code a expiré sans servir est supprimée.
Sans inscriptions autorisées, ou sans e-mails, `auth/signup` est
introuvable (404).

### 5.5 Un mot de passe oublié

```
utilisateur ── auth/forgot : nom ou adresse ──▶ 202, toujours
                                                un compte approuvé avec une adresse : un code envoyé,
                                                le mot de passe gardé tel quel
utilisateur ── auth/recover : nom, code, nouveau mot de passe ──▶ le nouveau mot de passe enregistré
```

1. L’utilisateur donne son nom ou son adresse (`auth/forgot`). La réponse
   est 202, que l’utilisateur existe ou non, et chaque demande compte contre
   l’adresse du client.
2. Un compte approuvé qui a une adresse reçoit un code. Son mot de passe
   continue de marcher : une demande faite par quelqu’un d’autre ne change
   donc rien tant que le code n’a pas servi.
3. L’utilisateur envoie son nom, le code et son nouveau mot de passe
   (`auth/recover`). Un code faux ou expiré est refusé (401), et compté.

Sans e-mails, `auth/forgot` est introuvable (404). Un gestionnaire
réinitialise alors le compte à la place (5.3), ce qui arrête aussitôt
l’ancien mot de passe.

### 5.6 Les appareils

Un appareil est une machine qui appelle le service sans que personne ne se
connecte : une borne, un capteur, un serveur domestique. Il agit au nom
d’un utilisateur de la base d’utilisateurs native, avec son propre token.

1. L’administrateur donne un token à l’appareil, avec un nom, l’étiquette
   que l’application lui donne, et un plafond : le rôle le plus élevé avec
   lequel il agit, `standard` sauf indication contraire, jamais au-dessus
   de celui de son utilisateur
   (`php nyuRun devices newToken <name> <user> <label> --roleTops=<role>`).
   Le token n’est affiché qu’une fois, et seule son empreinte est gardée.
2. L’appareil envoie son token dans un en-tête avec chaque requête. Il est
   accepté tant que l’appareil n’est pas révoqué et que son utilisateur est
   actif, et l’heure de sa dernière visite est enregistrée.
3. À chaque requête, l’appareil agit avec le plus bas entre son plafond et
   le rôle actuel de son utilisateur : rétrograder l’utilisateur abaisse
   donc d’un coup tous ses appareils. Un appareil créé avant les plafonds
   n’en a pas, et agit avec le rôle de son utilisateur ; `install checkAll`
   nomme ceux dont l’utilisateur est au-dessus de `standard`
   (`device caps`). Ce que ce rôle peut atteindre est décidé par les
   routes, exactement comme pour un utilisateur.
4. Un appareil ne modifie jamais le compte de son utilisateur : changer le
   mot de passe, ou l’adresse de son propre utilisateur, demande la
   connexion par mot de passe, et un token d’appareil y est refusé avec un
   403.
5. L’administrateur liste les appareils (`devices fullList`) et peut en
   révoquer un (`devices revokeIt`). Un appareil révoqué est refusé dès sa
   requête suivante, et reste dans la liste avec l’heure de sa révocation.
   Un appareil dont l’utilisateur est bloqué est refusé, et celui dont
   l’utilisateur est supprimé part avec lui.

Les appareils demandent la base d’utilisateurs native : un service qui a
des appareils en service ne peut pas déplacer ses utilisateurs vers le
fichier des utilisateurs tant qu’ils ne sont pas révoqués.

### 5.7 Une application qui appelle pour l’un de ses utilisateurs

Une autre application (une application mobile, le portail d’un partenaire)
appelle le service au nom de ses propres utilisateurs, qui ne se
connectent jamais au service lui-même.

```
administrateur ── php nyuRun appkeys makeKeys <app> --routeSet=… --publFile=… --roleTops=… ──▶ clé, affichée une fois
application    ── requête : sa clé dans X-App-Key, et un token qu’elle signe pour son utilisateur ──▶ service
service        ── clé connue ? token signé par cette application ? route listée ? rôle détenu ? ──▶ action
```

1. **L’administrateur enregistre l’application** : son nom, les routes
   qu’elle peut appeler, la clé publique qui vérifie ses tokens, et le rôle
   le plus élevé avec lequel ses utilisateurs peuvent agir. Le service
   remet une clé, affichée une fois, et n’en garde que l’empreinte.
2. **L’application appelle** avec sa clé dans un en-tête à elle
   (`X-App-Key` par défaut). Comme token porteur, elle envoie un token
   qu’elle a signé avec sa clé privée pour l’un de ses utilisateurs, qui
   porte le nom et le rôle de cet utilisateur, et une expiration.
3. **Le service vérifie**, dans cet ordre :
   - la clé : une mauvaise clé donne un 401, compté contre l’adresse ;
   - le token : il doit être signé par cette application, non expiré, et
     nommer l’audience de ce projet quand elle est réglée ;
   - la route : une route que la clé ne liste pas donne un 404 ;
   - le rôle de la route : un 404 quand il n’est pas détenu.

   Le rôle n’est jamais plus élevé que le plafond réglé pour l’application.
4. **L’action tourne** pour cet utilisateur, et sait quelle application a
   appelé. L’utilisateur appartient à l’application : le service ne le
   cherche pas dans sa propre base d’utilisateurs.

Une écriture (toute méthode autre qu’une lecture) est tracée comme une
notice, une lecture comme une information, et une route refusée comme un
avertissement. L’administrateur peut changer les routes d’une
application, et révoquer sa clé : la requête suivante qui s’en sert est
alors refusée. Une route ouverte à tous ignore la clé.

### 5.8 Travailler avec les données

Un run donne à ses données les cinq opérations à travers ses actions. Avec
une route pour chaque méthode, une seule URL couvre une ressource :

| Le front-end envoie | L’action | La réponse |
|---|---|---|
| `GET /shows?filters[search]=jazz&sorting=^date&indexes=20,40` | lit une liste, filtrée, triée et découpée | 200 avec les lignes, 204 quand aucune ne correspond |
| `GET /shows/12` | charge un enregistrement et ses lignes liées | 200 avec l’enregistrement, 404 quand il n’y en a pas |
| `POST /shows` avec les champs | remplit un nouvel enregistrement et l’enregistre | 201 avec son identifiant, 400 pour un champ non valide |
| `PATCH /shows/12` avec les champs | le charge, le modifie et l’enregistre | 200, 404, 409 quand la modification entre en conflit |
| `DELETE /shows/12` | le supprime, avec les lignes enfants nommées | 200 ou 204, 404 |

Ce qui se passe en chemin :

- **Les entrées** : seuls les champs que déclare l’action lui parviennent,
  chacun dans le bon format, et un champ obligatoire manquant donne un 400
  avant que l’action ne tourne. Une valeur non valide pour son format est
  vidée.
- **Les filtres** : une valeur venue de la requête est comparée telle
  quelle, jamais comme un motif. Une recherche avec joker se demande par le
  nom du champ, et une valeur envoyée pour elle est d’abord protégée : un
  client ne peut donc pas élargir ce qu’on lui montre.
- **Les listes** : les lignes sont lues un nombre fixé à la fois (une valeur
  par défaut quand aucun n’est demandé), à partir d’un décalage, triées par
  n’importe quel champ.
- **Les enregistrements** : leurs lignes liées viennent avec eux (un
  spectacle et ses représentations). Une suppression avec ses enfants se
  fait dans une seule transaction : tout, ou rien. Chaque suppression est
  tracée, avec l’utilisateur qui l’a demandée.
- **Les fichiers dans un enregistrement** : une image ou un fichier est
  enregistré exactement tel qu’envoyé, et renvoyé de la même façon, lu
  morceau par morceau.

Les noms de ce tableau viennent d’un exemple : chaque run nomme ses propres
actions, et les routes qu’il déclare décident quelle méthode atteint
laquelle.

### 5.9 Documents et médias

**Les documents.** Une action lit un document (un fichier envoyé avec la
requête, ou un fichier du serveur) et obtient son contenu sous l’une de
trois formes, quel que soit son format :

| Forme | Pour | À partir de |
|---|---|---|
| un arbre de clés et de valeurs | les réglages, les échanges de données | JSON, YAML, XML, INI |
| des tableaux de lignes et de cellules | les feuilles de calcul | CSV, TSV, Excel, tableurs OpenDocument |
| des blocs de texte dans l’ordre | les documents à lire | texte, Markdown, HTML, Word, textes OpenDocument, PDF, ePub |

Un agenda (iCalendar) donne ses événements, ses tâches et ses notes.
L’action travaille sur la forme, puis l’écrit dans n’importe quel format
qui l’accepte : une feuille lue depuis Excel peut être écrite en CSV, un
texte Markdown en Word, en PDF ou en ePub. Un document dont le contenu ne
correspond pas à son nom est refusé.

**Les images** : leur type, leur taille et ce qu’enregistre une photo
(date, appareil, lieu) peuvent être lus. Elles peuvent être
redimensionnées, recadrées, tournées, et débarrassées de ces données
intégrées, et un dessin (SVG) peut être transformé en image.

**Les sons** : leur type, leur durée, leurs tags et leur image de pochette
peuvent être lus, et leurs tags retirés. Un son WAV ou MP3 peut être coupé.
Avec ffmpeg sur le serveur, n’importe quel son peut être converti dans un
autre type, ou coupé.

**Les codes QR** peuvent être créés à partir d’un texte, comme l’adresse que
scanne une app d’authentification.

Chaque fichier qu’apporte une requête est tracé, qu’il ait été reçu ou
non, et que la requête ait été autorisée ou non. Un corps plus gros que ce
qu’accepte le service est refusé (413) : celui annoncé plus gros n’est pas
lu du tout, et celui qui s’avère plus gros n’est pas lu au-delà de la
limite.

### 5.10 Envoyer des e-mails

1. **Les comptes** : le projet liste ses comptes de messagerie, et le
   premier est celui par défaut. Chacun envoie par un serveur SMTP, par
   PHPMailer (pour les pièces jointes), ou dans des fichiers, ce qui garde
   chaque e-mail sur la machine pendant le développement.
2. **Un e-mail** a un expéditeur, des destinataires, un sujet, une version
   texte et une version HTML. Il est écrit à partir d’un gabarit : un
   fichier texte dont la première ligne est le sujet, plus un fichier HTML à
   côté, avec leurs emplacements remplis et encodés.
3. **L’envoi** : la connexion est chiffrée et le certificat du serveur
   vérifié. Seul un serveur d’e-mails sur la même machine reçoit une
   connexion non chiffrée. Un compte peut envoyer une copie de chaque
   e-mail à d’autres adresses, et une copie qui échoue est journalisée, pas
   signalée.
4. **Ce qui est tracé** : chaque e-mail envoyé, ou refusé, dans le journal
   du framework.

**Les e-mails d’accès** sont ceux de 5.2 à 5.5 :

| E-mail | Envoyé quand | À |
|---|---|---|
| `activate` | un compte avec une adresse est créé ou réinitialisé | l’utilisateur |
| `register` | un visiteur s’inscrit | l’adresse donnée |
| `password` | un utilisateur demande un nouveau mot de passe | l’utilisateur |
| `passcode` | un utilisateur avec des codes à deux facteurs par e-mail se connecte | l’utilisateur |
| `usrtaken` | une inscription demande un nom déjà pris | l’adresse donnée |
| `emltaken` | une inscription utilise une adresse déjà prise | le titulaire de l’adresse |
| `waitlist` | le nombre d’inscriptions en attente atteint un seuil réglé | les gestionnaires |

Chacun part dans la langue de l’utilisateur. S’il n’en a pas, c’est la
langue de la requête quand c’est cet utilisateur qui demande, et sinon la
langue par défaut du service. Le framework les livre en anglais et en
français, et un run peut les remplacer par sa propre formulation et sa
propre apparence.

### 5.11 Installer, configurer et livrer un projet

```
machine du développeur                      serveur
──────────────────────                      ──────────────────────────────────────
php nyuRun bundles final ──▶ copie ────────▶ php nyuRun install complete
  (code, exemples,                            vérifie le serveur, crée les réglages
   bibliothèques ; aucun secret,              locaux à partir des exemples, crée les
   aucun réglage local)                       secrets et les clés, crée les
                                              utilisateurs, vérifie tout
                                            php nyuRun setting …  (change un réglage)
                                            php nyuRun install checkAll (avant de servir)
```

1. **Construire** une copie pour l’endroit où elle va : la machine du
   développeur, un serveur de préproduction, la production, ou un endroit
   que déclare le projet. Ses réglages (l’environnement, l’affichage des
   erreurs, ce qui est journalisé) sont ceux de cet endroit.
2. **L’installer** : `install complete` demande d’abord ce qu’est le
   projet : utilisateurs, connexion et données dans un seul (`allInOne`), un
   serveur d’authentification qui émet des tokens pour d’autres projets
   (`authServ`), un serveur de ressources sans utilisateurs à lui, qui
   accepte ces tokens (`dataServ`), ou un site sans rien à quoi se
   connecter, qui ne sert que des pages et des fichiers (`openSite`).
   Ensuite, il vérifie PHP et ses extensions, crée les réglages locaux à
   partir de leurs exemples, crée les secrets et les clés de tokens qui
   manquent, crée les utilisateurs (un fichier des utilisateurs, ou des
   tables de base de données), et lance le diagnostic. Avec des
   utilisateurs dans une base de données, il demande aussi s’il faut
   envoyer les e-mails d’accès (activation, réinitialisation, inscriptions),
   par quel compte de messagerie, et si les inscriptions sont ouvertes ; un
   e-mail de test peut partir tout de suite, ou plus tard avec
   `install testMail`. Il demande ce dont il a besoin, en proposant une
   réponse par défaut à chaque question, et un script de déploiement peut
   toutes les sauter. Rien de ce qui existe déjà n’est remplacé.
3. **Configurer** : `setting` affiche et change les réglages. Chaque valeur
   est vérifiée, le fichier précédent est gardé, et un changement qui
   rendrait le service inutilisable est annulé.
4. **Vérifier** : `install checkAll` dit, ligne par ligne, ce qui va, ce qui
   mérite un coup d’œil et ce qui échoue. La commande sort avec un code
   d’échec quand une ligne échoue : un script de déploiement s’arrête donc
   là.
5. **Faire tourner** : les utilisateurs se gèrent en ligne de commande ou
   par le run `Users`. `showlog` montre les journaux ; `cleanup` libère une
   adresse mise en attente et fait le ménage dans les vieux fichiers
   temporaires.
6. **Mettre à jour** : une nouvelle copie remplace le code, tandis que les
   réglages locaux du serveur, ses secrets, ses clés et ses données restent
   tels quels.

---

## 6. Configuration et montée en charge

### 6.1 Ce que choisit un projet

Chaque choix ci-dessous est un réglage dans un fichier, changé avec
`php nyuRun setting` et vérifié par `php nyuRun install checkAll`. Les
fichiers et leurs clés sont listés dans les
[Spécifications techniques, chapitre 6](techspecs.md#6-fichiers-de-configuration).

| Choix | Possibilités | Par défaut |
|---|---|---|
| **Profil** du service | une API ouverte, une API pour des programmes avec des clés, une API web avec des tokens, un site avec des sessions, un site de pages, ou un profil propre au projet | tokens, JSON |
| **Comment un utilisateur se connecte** | un mot de passe vérifié dans un fichier de mots de passe, le fichier des utilisateurs ou la table des utilisateurs ; une clé d’API ; un token d’appareil ; un certificat client ; une clé d’application, pour un utilisateur d’une autre application | le fichier des utilisateurs |
| **Ce dans quoi voyage l’identité** | un token signé par le service, un cookie de session, un en-tête, l’authentification Basic | un token |
| **Qui atteint quoi** | les routes ouvertes à tous, le rôle minimum de chaque route, le rôle qu’une action demande elle-même | réglé par le profil : les profils par défaut, d’API web et de site ferment tout sauf la connexion et ce qui va avec, et gardent `Users` pour les gestionnaires |
| **Utilisateurs** | le fichier des utilisateurs (en lecture seule) ou une table de base de données ; des inscriptions ouvertes ou non ; des codes envoyés par e-mail ou remis en main propre ; la longueur des mots de passe ; des codes à deux facteurs, par app ou par e-mail | le fichier des utilisateurs, sans inscriptions |
| **Limites** | les mauvaises tentatives avant qu’une adresse ou un compte ne soit mis en attente, et pour combien de temps ; les inscriptions par client, et en attente à la fois ; la taille des requêtes | une adresse mise en attente après 10 mauvaises tentatives et un compte après 5, pendant 10 minutes |
| **Réponses** | JSON, YAML, XML, CSV, HTML, brut ; les sites autorisés à appeler depuis un navigateur | JSON, aucun autre site |
| **Langues** | la langue des e-mails des utilisateurs qui n’en ont pas ; les gabarits de chaque langue | l’anglais |
| **Journaux** | combien est écrit, où, et quand un journal est renouvelé | tout, dans `temp/logs` |
| **Environnement** | développement, test, préproduction, production : ce que montre une erreur | développement |

Un choix qui ne peut pas s’appliquer arrête le service avec une erreur,
plutôt que de le laisser tourner dans un état affaibli : un rôle inconnu,
un profil avec une clé qu’il ne connaît pas, une connexion qui nomme une
base d’utilisateurs qui n’existe pas.

### 6.2 Comment un service grandit

Un service démarre petit et grandit sans être réécrit :

| De | Vers | Comment |
|---|---|---|
| des utilisateurs dans un fichier | des utilisateurs dans une base de données, avec des mots de passe gérés par eux-mêmes, des inscriptions, des e-mails, des appareils | une commande les déplace, avec leurs mots de passe, leurs rôles et leurs secrets à deux facteurs (`install moveUser nativeDb`). Le fichier reste tel qu’il était : la connexion peut donc y revenir, sans ce qui a changé depuis |
| une base de données | plusieurs, de plusieurs types | chacune est nommée dans les réglages, et chaque modèle nomme celle qu’il utilise |
| une application | plusieurs | un dossier chacune dans `runs/`, et des modèles partagés par toutes dans `runs/Shared` |
| un service | plusieurs qui travaillent ensemble | l’un émet les tokens et les autres les vérifient ; les applications s’appellent entre elles avec des clés (4.4) |
| un serveur | développement, préproduction, production, intégration | une copie construite pour chacun, avec ses propres réglages (5.11) |
| les outils du framework | ceux du projet | sa propre vérification d’identifiants ou son propre porteur, ses propres commandes, ses propres gabarits d’e-mails et ses propres pages |

### 6.3 Tourner sur plusieurs serveurs

Le service ne garde aucun état à lui entre deux requêtes, à part ce qui
est listé ci-dessous. Un projet qui tourne sur plusieurs serveurs derrière
un répartiteur de charge doit prévoir chacun de ces points :

| État | Gardé dans | Sur plusieurs serveurs |
|---|---|---|
| **Tokens** | le token lui-même, signé | n’importe quel serveur peut le vérifier, tant que tous ont la même clé |
| **Sessions** | les fichiers de PHP sur le serveur | un dossier ou un stockage partagé pour les sessions, ou un répartiteur qui renvoie chaque utilisateur vers le même serveur |
| **Compteurs de mauvaises tentatives** | des fichiers dans `temp/limits` sur chaque serveur | chaque serveur compte les siennes : une adresse est donc mise en attente par le serveur qui a vu les tentatives |
| **Comptes en attente, utilisateurs, appareils** | la base de données | partagés par tous |
| **Journaux** | les fichiers de chaque serveur | rassemblés par l’hébergeur, si besoin |
| **Réglages, secrets et clés des tokens** | les fichiers de chaque serveur | les mêmes partout : créés une fois et copiés par l’hébergeur. Lancer `install complete` sur chaque serveur donnerait à chacun ses propres clés, et leurs tokens ne marcheraient pas sur les autres |
| **Cache de configuration** | `temp/cache` sur chaque serveur | chaque serveur crée le sien ; après un changement de réglage, lance `php nyuRun setting clrCache` sur chacun |

### 6.4 Ce qui n’est pas encore là

- **Pas de cache pour les réponses de données** : chaque requête lit ses
  données. Seuls les fichiers statiques sont mis en cache par les clients
  (304).
- **Pas de limites par route** : la limite de tentatives s’applique par
  adresse et par compte, pour tout le service.
- **Pas de sockets** : chaque échange est une requête et sa réponse.
- **Pas d’hôte transmis** : derrière un proxy, l’hôte que le client a
  demandé n’est pas lu. Le service connaît l’hôte par lequel son proxy
  l’appelle, pas celui qu’a utilisé le client.

Ils sont listés avec tout ce qui reste pour plus tard dans les
[Spécifications techniques, 1.5](techspecs.md#15-ce-qui-reste-pour-plus-tard).

---

## 7. Résultats de performance

### 7.1 Ce qui a été mesuré

Cinq frameworks ont reçu les deux mêmes applications, chacune écrite comme
on utilise normalement ce framework : son propre routeur, sa validation,
sa couche de base de données et ses réglages de production. nyuRuns sert
de référence : chacun des autres donne les mêmes statuts et les mêmes
données pour les mêmes requêtes.

| Framework | Version | Couche de base de données |
|---|---|---|
| nyuRuns | 1.0, bundle `final` | ses modèles, `DBLst` et `DBRec` |
| Slim | 4.15.3 | PDO simple, PHP-DI |
| Flight | 3.19.5 | sa propre surcouche de PDO |
| Laravel | 13.40.0 | modèles Eloquent |
| Symfony | 8.1.11 | entités et dépôts Doctrine ORM |

**Les applications.** Une légère, une liste de tâches sans connexion, et
une complexe : des projets partagés par plusieurs utilisateurs, avec
chaque requête signée par un token (HS256). Dix scénarios :

| | Scénario | Fait |
|---|---|---|
| L1 | liste | 50 tâches, page 10 |
| L2 | lecture | une tâche |
| L3 | création | une tâche, validée, relue |
| L4 | modification | une tâche, relue |
| C1 | mes projets | les projets de l’utilisateur, avec son rôle dans chacun |
| C2 | tâches, filtrées et triées | 50 tâches d’un projet, par statut et par ordre de tri |
| C3 | statistiques d’un projet | les tâches d’un projet comptées par statut et par utilisateur |
| C4 | tâche avec commentaires | une tâche, son responsable, ses sous-tâches, ses 20 derniers commentaires |
| C5 | création d’une tâche | dans un projet, avec ses champs vérifiés et le responsable membre du projet |
| C6 | ajout d’un commentaire | sur une tâche, relu |

**Les machines.** Le serveur : un Intel NUC, Core i3-7100U (2 cœurs,
4 threads, 2,4 GHz), 4 Go de mémoire, Ubuntu 26.04. PHP 8.5.4 par PHP-FPM,
un pool statique de 8 workers, OPcache activé sans vérification des
fichiers ; MySQL 8.4 sur la même machine (buffer pool de 512 Mo, sans
journal binaire). Chaque framework tournait derrière nginx, puis derrière
Apache (MPM event), dans son mode de production : cartes de classes
optimisées, caches préchauffés, pas de debug. nyuRuns tournait comme le
construit son bundle `final` : `PROD`, journaux `MINI`, caches de
configuration et des tables activés. La charge venait d’une deuxième
machine, en gigabit filaire.

**La mesure.** k6 garde occupé un nombre fixe de clients, chacun envoyant
sa requête suivante dès que la précédente a reçu sa réponse, en HTTP/1.1
keep-alive. Une réponse autre que 2xx compte comme un échec. Chaque passage
est mesuré pendant 30 s, après 10 s de préchauffage au début de chaque
scénario et 3 s entre deux paliers de charge, à 1, 8 et 32 clients. La
série complète a tourné trois fois, les frameworks dans un nouvel ordre
aléatoire à chaque fois, les bases de données réinitialisées avant chaque
framework ; chaque chiffre est la médiane des trois. Le CPU est lu sur le
serveur : le temps utilisé par PHP-FPM, MySQL et le serveur web pendant un
passage, divisé par ses requêtes. La machine de charge n’a jamais dépassé
62 % de son propre CPU.

### 7.2 Résultats

Requêtes par seconde, et le temps dans lequel 99 % d’entre elles ont reçu
leur réponse (p99), derrière nginx, d’après la série des 24 et 25 novembre
2026.

**Un client à la fois** : requêtes par seconde (p99 en ms)

| Scénario | Flight | nyuRuns | Slim | Symfony | Laravel |
|---|---|---|---|---|---|
| L1 liste | 430 (3,8) | 318 (4,5) | 357 (4,3) | 203 (7,7) | 120 (11,6) |
| L2 lecture | 500 (3,4) | 393 (3,9) | 429 (3,8) | 260 (6,7) | 162 (9,6) |
| L3 création | 309 (5,6) | 265 (6,6) | 241 (6,7) | 143 (9,6) | 117 (12,2) |
| L4 modification | 290 (5,7) | 235 (7,5) | 236 (7,5) | 180 (7,4) | 134 (10,5) |
| C1 mes projets | 436 (3,8) | 346 (4,6) | 381 (4,1) | 204 (7,1) | 152 (9,2) |
| C2 tâches, filtrées | 330 (4,4) | 264 (6,8) | 262 (6,2) | 117 (11,8) | 98 (11,7) |
| C3 statistiques d’un projet | 284 (5,8) | 235 (6,0) | 267 (6,8) | 146 (9,6) | 120 (12,0) |
| C4 tâche avec commentaires | 331 (4,9) | 257 (7,0) | 309 (4,8) | 137 (10,1) | 91 (13,8) |
| C5 création d’une tâche | 254 (7,1) | 219 (6,9) | 204 (7,9) | 105 (12,6) | 91 (14,3) |
| C6 ajout d’un commentaire | 262 (6,8) | 222 (7,4) | 209 (6,6) | 109 (12,0) | 90 (14,1) |

**32 clients à la fois** : requêtes par seconde (p99 en ms)

| Scénario | Flight | nyuRuns | Slim | Symfony | Laravel |
|---|---|---|---|---|---|
| L1 liste | 1 407 (27) | 1 041 (36) | 1 200 (32) | 551 (67) | 313 (118) |
| L2 lecture | 1 785 (21) | 1 318 (29) | 1 475 (26) | 712 (52) | 428 (86) |
| L3 création | 1 014 (45) | 992 (44) | 865 (49) | 429 (87) | 334 (110) |
| L4 modification | 914 (48) | 835 (50) | 840 (49) | 479 (77) | 352 (104) |
| C1 mes projets | 1 512 (25) | 1 147 (33) | 1 270 (30) | 549 (67) | 395 (94) |
| C2 tâches, filtrées | 1 066 (36) | 810 (46) | 797 (47) | 300 (122) | 251 (145) |
| C3 statistiques d’un projet | 869 (44) | 715 (53) | 796 (48) | 395 (92) | 307 (117) |
| C4 tâche avec commentaires | 1 055 (35) | 799 (46) | 953 (39) | 376 (97) | 240 (152) |
| C5 création d’une tâche | 813 (55) | 784 (52) | 712 (56) | 303 (121) | 259 (140) |
| C6 ajout d’un commentaire | 824 (52) | 796 (52) | 761 (53) | 317 (115) | 261 (139) |

Le serveur est entièrement occupé dès 8 clients : passer à 32 ajoute de
l’attente, pas des requêtes (nyuRuns gagne moins de 1 % sur chaque
scénario).

**Le CPU par requête**, en ms, la moyenne des dix scénarios à 32 clients :

| | Flight | nyuRuns | Slim | Symfony | Laravel |
|---|---|---|---|---|---|
| PHP | 1,61 | 2,63 | 2,56 | 7,76 | 10,66 |
| MySQL | 1,87 | 1,52 | 1,49 | 1,68 | 2,21 |
| Serveur web | 0,22 | 0,23 | 0,23 | 0,26 | 0,26 |
| **Total, nginx** | **3,70** | **4,39** | **4,28** | **9,70** | **13,13** |
| Total, Apache | 3,81 | 4,62 | 4,50 | 9,96 | 13,42 |

**Derrière Apache**, le serveur web coûte environ 0,43 ms par requête,
contre 0,24 ms pour nginx, et le temps de PHP reste le même. Ces 0,2 ms
pèsent plus sur les frameworks légers : nyuRuns sert 3,8 à 7,1 % de
requêtes de moins que derrière nginx, et ses rapports bougent d’un ou deux
centièmes (1,03 fois Slim, 1,21 fois Flight, 0,46 fois Symfony, 0,34 fois
Laravel). Le classement ne change pas.

Ce que ça montre :

- **nyuRuns coûte ce que coûte un micro-framework** : 1,02 fois Slim et
  1,19 fois Flight, qui en fait le moins autour d’une requête.
- **Scénario par scénario face à Slim**, nyuRuns sert plus sur C2 et sur
  trois écritures (L3, C5, C6), à peu près autant sur L4 (0,6 % de moins),
  et 9,6 à 16,2 % de moins sur les autres lectures (L1, L2, C1, C3, C4).
- **Flight reste devant** : nyuRuns sert 17,7 à 26,2 % de requêtes de moins
  sur les lectures, 2,1 à 3,5 % de moins sur trois écritures (L3, C5, C6),
  et 8,7 % de moins sur la modification (L4).
- **Symfony et Laravel coûtent 2,21 et 2,99 fois plus de CPU** : nyuRuns
  sert 1,74 à 3,33 fois leurs requêtes, tout en faisant ce qu’ils font pour
  ces applications : le routage, la validation des entrées, les tokens
  signés, des modèles au lieu de requêtes SQL.
- **MySQL coûte à peu près la même chose pour tous**, Laravel un peu plus.
  Le chiffre de Flight est relevé par son C5 (7.4).

### 7.3 Comment nyuRuns en est arrivé là

La première série complète, le 5 novembre, plaçait nyuRuns à 6,03 ms par
requête en moyenne. Entre cette série et celle ci-dessus, le framework a
été optimisé sans changer ce qu’il fait :

- moins d’instructions par requête : chaque table décrite une seule fois,
  et un modèle créé seulement quand une action le demande ;
- une carte de classes complète dans les bundles `stage` et `final` ;
- aucune ligne de journal pour l’accès d’un utilisateur standard en
  production (les connexions, les déconnexions, les refus et les accès
  privilégiés sont toujours écrits) ;
- la configuration, les routes et les descriptions des tables lues une
  fois, puis depuis un cache (`CNF_CACH`, `TBL_CACH`).

La moyenne est passée de 6,03 ms à 2,67 ms : moins de la moitié.

Ces chiffres viennent de la machine Windows des 5 et 7 novembre, une
requête à la fois. La série des 24 et 25 novembre a mesuré le résultat à
nouveau, sous Linux et sous charge, avec tout ce qui a été ajouté depuis :
les routes propres à chaque run, un contexte pour chaque action, la
révision de sécurité, le renommage.

### 7.4 Ce que ces chiffres ne disent pas

- **En dessous du serveur de référence.** Deux cœurs et 4 Go, contre six
  cœurs et 16 Go (Exigences, 7.3). Les rapports entre frameworks valent sur
  n’importe quelle machine ; les requêtes par seconde sont une borne basse,
  pas les objectifs P-07 et P-08.
- **32 clients au plus.** Les objectifs en demandent 50 ; au-delà de
  8 clients, cette machine n’ajoute que de l’attente.
- **PHP 8.5, pas 8.2.** La plus ancienne version que nyuRuns prend en
  charge n’a pas été mesurée.
- **La mémoire n’a pas été mesurée** : P-09 reste ouvert.
- **Deux applications.** D’autres sortes de travail (de gros documents, des
  images, de nombreuses jointures) n’ont pas été mesurées.
- **16 requêtes en échec**, sur 15,4 millions, toutes derrière Apache, à 32
  clients, sur des POST et des PATCH seulement : des connexions que le
  serveur a fermées au moment où partait la requête suivante, très
  probablement le MPM event d’Apache qui ferme des connexions keep-alive.
  Chaque framework en a eu (nyuRuns 5, Symfony 4, Flight 3, Slim 2,
  Laravel 2), nginx aucune. Elles viennent du serveur web, et aucun chiffre
  ne change.
- **Une anomalie reste inexpliquée.** C5 a été relancé le 25 novembre pour
  nyuRuns et Flight : les chiffres ont tenu à 2 % près (nyuRuns 780 et 779
  par seconde derrière nginx, contre 784). Le C5 de Flight coûte toujours à
  MySQL environ 1 ms de plus derrière nginx que derrière Apache, pour les
  mêmes instructions, sans attente de verrou ; couper le spinning du redo
  log de MySQL n’a rien changé. Ça déplace la moyenne de Flight derrière
  nginx d’environ 1 % et ne touche pas nyuRuns, dont le temps MySQL est le
  même derrière les deux serveurs.

---

## 8. Recommandations de développement

Comment concevoir un service avec nyuRuns, de ce dont le métier a besoin à
ce qu’appelle le front-end. Les règles techniques (les secrets, les
bundles, les entrées, le nommage) sont dans les
[Spécifications techniques, chapitres 2 et 12](techspecs.md#2-lignes-directrices-et-recommandations).

### 8.1 Du métier aux runs

1. **Liste ce que garde le métier** : des membres, des commandes, des
   spectacles, des factures. Chacun devient une table, et un modèle de
   cette table.
2. **Regroupe-les par sujet** : ce qui se gère ensemble, par les mêmes
   personnes, va dans un même run (`Members`, `Orders`, `Shows`). Un
   run qui finit par contenir des choses sans lien doit être découpé.
3. **Donne un propriétaire à chaque chose** : un seul run l’écrit (et, dans
   un groupe de services, un seul service), et les autres la lisent par les
   routes de ce propriétaire.
4. **Mets ce dont chaque run a besoin dans `runs/Shared`** : un modèle
   utilisé par plusieurs runs, ou une vérification d’identifiants propre au
   projet.

### 8.2 Des runs aux routes

- **Une URL par ressource**, avec la méthode qui dit ce qui est fait :
  `GET` lit ou liste, `POST` crée, `PUT` ou `PATCH` modifie, `DELETE`
  supprime (3.1). Déclare une route par ressource dans le propre
  `routes.rte` du run, avec une cible par méthode, plutôt qu’une URL par
  verbe. Le run n’est alors atteint que par ces routes.
- **Nomme ce que voit le front-end** : les routes sont le contrat avec le
  front-end. Un run peut être réorganisé derrière elles, ou monté ailleurs
  par le profil, sans que le front-end ne s’en aperçoive.
- **Réponds avec le bon statut** : 201 pour ce qui est créé, 204 pour une
  liste vide, 404 pour ce qui n’existe pas, 409 pour une modification qui
  entre en conflit, 400 pour une entrée non valide. Le front-end décide
  d’après le statut, pas d’après le message.
- **Renvoie ce que montre le front-end**, et rien de plus : une liste donne
  les champs d’une liste, un enregistrement ceux de sa page. Une empreinte
  de mot de passe, un code ou un token ne quitte jamais le service.

### 8.3 Les entrées

- **Déclare les entrées de chaque action**, chacune avec son format. Ce qui
  n’est pas déclaré n’arrive pas jusqu’à l’action.
- **Marque comme obligatoire** ce dont l’action ne peut pas se passer : la
  requête est alors refusée avant que l’action ne tourne.
- **Garde une recherche une recherche** : une valeur venue du front-end est
  comparée telle quelle. N’offre une correspondance que sur les champs où
  elle a du sens.
- **Envoie les mots de passe dans le corps**, jamais dans l’URL.

### 8.4 L’accès

1. **Choisis le profil** selon qui appelle : des programmes avec des clés,
   une API web avec des tokens, ou un site avec des sessions.
2. **Ouvre les routes exprès** : liste les routes que tout le monde peut
   atteindre (la connexion, l’inscription, une page publique). Toutes les
   autres demandent un identifiant.
3. **Prévois les rôles** : `standard` pour les utilisateurs, `advanced`
   pour l’équipe, `managers` pour ceux qui gèrent les utilisateurs. Donne à
   chaque route le rôle minimum dont elle a besoin, dans le profil. Une
   action qui demande plus le demande elle-même, et tout un run peut le
   vérifier une fois, dans `openEachAction()`.
4. **Laisse le front-end cacher ce qu’un utilisateur ne peut pas
   atteindre** : le service répond 404 pour un rôle non détenu, exactement
   comme pour une URL qui n’existe pas ; le front-end ne doit donc pas le
   proposer.
5. **Donne aux machines et aux partenaires leurs propres identifiants** :
   un token d’appareil pour une machine, une clé d’application pour
   l’application d’un partenaire. Chacun peut être révoqué seul, et aucun
   n’est jamais le mot de passe d’une personne.

### 8.5 Les utilisateurs

- **Commence avec le fichier des utilisateurs** tant qu’ils sont peu
  nombreux et fixes. Passe à la base de données quand ils doivent changer
  leur mot de passe, s’inscrire, recevoir des e-mails ou avoir des
  appareils (6.2).
- **Envoie les codes par e-mail** quand le service a des utilisateurs hors
  de l’équipe : l’activation, les inscriptions et les réinitialisations se
  passent alors de gestionnaire au milieu.
- **N’ouvre les inscriptions qu’avec une validation** : chaque inscription
  attend un gestionnaire, alors règle les limites sur ce que les
  gestionnaires peuvent valider.
- **Écris les e-mails dans les langues des utilisateurs** : donne à chaque
  run ses propres gabarits, avec sa formulation et son apparence, pour
  chaque langue qu’ont ses utilisateurs.
- **Garde au moins deux gestionnaires** : le dernier ne peut être ni bloqué
  ni supprimé, mais un gestionnaire ne peut pas non plus réinitialiser son
  propre compte. Un mot de passe perdu demande alors l’e-mail de mot de
  passe oublié, un autre gestionnaire, ou la ligne de commande.

### 8.6 Les données

- **Nomme les tables comme les modèles s’y attendent** (des colonnes de 10
  caractères, `id_<singular>` pour l’identifiant, `<singular>_id` pour un
  lien, comme `user_id`) : les relations sont alors trouvées sans être
  déclarées.
- **Déclare les enfants** avec lesquels un enregistrement est montré, et
  ceux qui sont supprimés avec lui : une suppression est alors tout ou
  rien.
- **Borne les listes** : lis toujours une page de lignes, avec un tri que
  l’utilisateur peut suivre, plutôt qu’une table entière.
- **Utilise un format de document pour les échanges** : lis un import (CSV,
  Excel) dans sa forme, vérifie-le, puis enregistre-le à travers les
  modèles. Écris un export de la même façon.

### 8.7 Du développement à la production

- **Développe avec les e-mails écrits dans des fichiers**, et les erreurs
  affichées.
- **Teste chaque run** : ses actions, ses statuts, et les refus de ses
  rôles. La suite du framework lui-même montre comment (Spécifications
  techniques, chapitre 13).
- **Livre une copie construite pour chaque endroit**, vérifie-la sur son
  serveur avant sa mise en ligne, et garde les secrets et les clés sur ce
  serveur seulement (5.11).
- **Lis les journaux** : les refus, les suppressions et les e-mails non
  envoyés montrent ce que les utilisateurs ont rencontré.

---

## A. Réponses et statuts

Ce que reçoit un client du framework et des runs livrés avec lui. Une
erreur s’écrit `{"error": "<message>", "code": <status>}` dans le format du
service, et un site peut y répondre plutôt par sa propre page d’erreur. Les
messages sont en anglais et s’adressent aux développeurs : un front-end
décide d’après le statut, et écrit son propre message pour l’utilisateur.
Un run ajoute ses propres messages à ses statuts.

### A.1 Succès

| Statut | Quand | Corps |
|---|---|---|
| **200** | la requête est faite | les données de l’action, ou un fichier ou une page |
| **201** | une ressource est créée | ce que renvoie l’action : pour un utilisateur, son nom, plus son code quand aucun e-mail ne le porte |
| **202** | une demande est acceptée, quelle que soit son issue : une inscription, un mot de passe oublié | `{"asked": true}` |
| **204** | fait, avec rien à envoyer : une liste vide, une pré-requête d’un autre site | aucun |
| **206** | une partie d’un fichier, comme `Range` l’a demandé | cette partie |

Les réponses du run `Auth` :

| Action | Corps en cas de succès |
|---|---|
| `auth/login` | `{"token": "<the token>"}`, ou `{"logged": true}` avec le cookie de session |
| `auth/logout` | `{"logged": false}` |
| `auth/active` | `{"active": true, "stat": "approved"}`, ou `{"active": false, "stat": "inReview"}` pour une inscription |
| `auth/setpass`, `auth/recover` | `{"changed": true}` |
| `auth/usrlang` | `{"lang": "<the language>"}` |
| `auth/keyset`, à `/.well-known/jwks.json` | les clés publiques qui vérifient les tokens (RS256) ; un 404 quand les tokens sont signés avec un secret (HS256) |

### A.2 Redirections

| Statut | Quand |
|---|---|
| **301, 302, 303, 307, 308** | une route ou une action envoie le client ailleurs (`Location`) |
| **302** | un visiteur sans identifiant demande une page d’un site qui a une page de connexion, et y est envoyé |
| **304** | le client a déjà le fichier statique qu’il demande |

### A.3 Erreurs du client

| Statut | Message | Quand |
|---|---|---|
| **400** | `Missing or invalid input: <fields>` | une entrée obligatoire manque ou n’est pas valide |
| | `Password sent in the query string` | un mot de passe est arrivé dans l’URL |
| | `Invalid userName or passWord`, `Invalid userName, sentCode or passWord`, `Invalid userName or userMail`, `Invalid oldValue or passWord`, `Invalid userName` | une entrée d’une action `Auth` ou `Users` n’est pas du texte |
| | `Password too short: …`, `Password too long: …`, `Password holding a control character`, `Password identical to the user name`, `Password identical to the current one` | un nouveau mot de passe enfreint une règle |
| | `Invalid user name`, `Invalid mail address`, `Invalid role`, `Invalid status`, `Invalid language`, `Invalid filter` | une valeur donnée au run `Users` n’est pas valide |
| **401** | `Invalid credentials` | un mauvais nom ou mot de passe à la connexion, ou un compte inactif |
| | `Verification code required`, `Invalid verification code` | le code à deux facteurs manque ou est faux |
| | `Invalid token`, `Expired token` | le token est falsifié ou a expiré |
| | `Account not active` | le compte derrière une session ou un token a été bloqué entre-temps |
| | `Invalid activation`, `Invalid code` | un mauvais nom ou code à l’activation ou à la récupération, ou un code expiré |
| | `Invalid API key`, `No API key provided`, `Invalid app key`, `Not logged in`, `No active session`, `Missing Bearer Authorization header`, … | l’identifiant que demande le profil manque ou est faux |
| **403** | `Sign in with the password: a device cannot change its account` | un token d’appareil sur une action qui modifie le compte de son utilisateur (`needsPassLogin()`) ; une action peut aussi en répondre un elle-même (`stopThisAction(403, …)`). Ce qu’un utilisateur, une clé d’application ou un appel en ligne de commande ne peut pas atteindre est un 404 |
| **404** | `Not Found` | rien ne répond à l’URL ; l’application, l’action, le fichier ou la page n’existe pas ; un run qui a ses propres routes est appelé par son chemin simple ; le rôle n’est pas détenu (par un utilisateur, une clé d’application ou un appel en ligne de commande) ; une clé d’application atteint une route qu’elle ne liste pas ; une fonction est désactivée (les inscriptions, les mots de passe oubliés) |
| | `User not found` | on demande au run `Users` un utilisateur qui n’existe pas |
| **405** | `Method Not Allowed` | la route ou l’action n’accepte pas cette méthode ; l’en-tête `Allow` liste celles qu’elle accepte |
| **409** | `User name already taken`, `Mail address already used` | le nom ou l’adresse appartient à un autre compte |
| | `A manager cannot do this to its own account` | un gestionnaire bloque, réinitialise ou supprime son propre compte, ou change son propre rôle |
| | `The last active manager cannot be locked` (… `removed`, `reset`, `given another role`) | le service se retrouverait sans gestionnaire |
| | `A locked user is unlocked before it is reset`, `A pending user is neither locked nor unlocked`, `A user in review is approved or removed`, `User not awaiting review`, `User already …`, `No reset for this user` | le changement ne correspond pas au statut du compte |
| **413** | `Payload Too Large` | le corps est plus gros que ce qu’accepte le service |
| **429** | `Too many attempts` | l’adresse est en attente après trop de mauvaises tentatives, ou a trop demandé (inscriptions, réinitialisations) ; `Retry-After` dit combien de temps attendre |

### A.4 Erreurs du service

| Statut | Message | Quand |
|---|---|---|
| **500** | `Internal Server Error` | une erreur dans le code, sans détail en production |
| | `Access misconfigured` | les réglages d’accès ne peuvent pas s’appliquer |
| | `Login unavailable`, `User management unavailable`, `Credential store unavailable`, `User cannot be written` | la base d’utilisateurs ne peut pas être atteinte ou écrite |
| **501** | `Tokens are issued by another server` | le service ne fait que vérifier des tokens, et n’en émet aucun |
| **503** | `Sign-ups closed for now` | trop d’inscriptions attendent une validation |

---
