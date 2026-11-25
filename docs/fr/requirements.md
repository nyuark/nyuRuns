# nyuRuns — Exigences du projet

Version 1.0

## Sommaire

1. [Vue d’ensemble](#1-vue-densemble)
2. [Buts et objectifs](#2-buts-et-objectifs)
3. [Principes appliqués](#3-principes-appliqués)
4. [Public visé](#4-public-visé)
5. [Fonctionnalités de base](#5-fonctionnalités-de-base)
6. [Exigences détaillées](#6-exigences-détaillées)
7. [Objectifs de performance](#7-objectifs-de-performance)
8. [Notes complémentaires](#8-notes-complémentaires)

---

## 1. Vue d’ensemble

### 1.1 Le projet

nyuRuns est un micro-framework pour construire et déployer des services
d’API RESTful. Il permet aux programmeurs et aux équipes de développer et
de livrer le back-end complet d’une application.

Son but principal est d’offrir une base pour des composants dirigés par les
modèles de données, pour que les développeurs se concentrent sur le métier
qu’ils construisent. Les opérations habituelles sur les données (créer,
lire, modifier, supprimer et lister) sont données à chaque source de
données, de façon configurable : n’importe quel programmeur peut ainsi
construire des services web sur un jeu de données ou un ensemble de
modèles. Une fois quelques réglages en place, les utilisateurs d’un service
peuvent lister, lire, écrire et supprimer les éléments d’une source de
données, en général une base de données. D’autres sources, comme des
fichiers (XML, JSON, INI et texte brut), peuvent s’y ajouter en cours de
route.

Le framework est fait pour prendre en charge toute la requête HTTP, et
réduire le travail que demande son traitement. Il apporte aussi des outils
intégrés qui simplifient le développement : le routage de chaque requête
entrante, avec des solutions de repli ; le contrôle d’accès et les tokens
pour la sécurité ; et des gestionnaires de données pour les bases, les
fichiers, les images et les sockets. La sortie vient avec des fonctions de
cache et la gestion des erreurs. Au bout du compte, nyuRuns donne aux
développeurs une architecture en couches pour les services web, et une base
pour construire des API back-end en un rien de temps.

### 1.2 L’ambition

nyuRuns vise à être aussi efficace que les micro-frameworks, tout en
offrant les fonctionnalités clés, et une partie des capacités, des plus
lourds. Il est fait pour s’appuyer sur des technologies modernes, et pour
être un outil complet, prêt à l’emploi.

### 1.3 Ce que couvre ce document

Ce document dit ce que nyuRuns doit atteindre, et pourquoi : ses buts, les
principes qu’il applique, à qui il s’adresse, ses fonctionnalités, ses
exigences détaillées et ses objectifs de performance. Pour chaque exigence,
il dit où en est la version 1.0 : atteinte, atteinte en partie, laissée à
la version 1.1, déplacée vers nyuTron (le projet séparé d’outils autour de
nyuRuns), ou abandonnée.

Ce que fait nyuRuns est décrit dans les
[Spécifications fonctionnelles](funcspecs.md), la façon dont il est
construit dans les [Spécifications techniques](techspecs.md), et la façon
de s’en servir dans le [Guide de l’utilisateur](userguide.md).

---

## 2. Buts et objectifs

### 2.1 Buts

Le but de nyuRuns est de simplifier la construction des API en partageant
le travail qu’elles demandent. On peut construire dessus des applications
complexes, mais son premier but est de garder les choses simples.

1. **Partager le travail.** Le framework prend en charge la requête, et le
   développeur n’écrit que ce dont le métier a besoin.
2. **Rester simple.** Un service démarre petit, ne charge que ce qu’il
   utilise, et grandit sans être réécrit.
3. **Travailler à travers des modèles.** Une application atteint ses
   données à travers des modèles de ces données, quelle que soit la source.
4. **Être efficace.** Une requête ne coûte pas plus qu’avec un
   micro-framework.
5. **Être complet.** Les fonctionnalités clés des frameworks plus lourds,
   et une partie de leurs capacités, sont prêtes à l’emploi, construites sur
   des technologies modernes.

### 2.2 Objectifs

Chaque but mène à des objectifs que l’on peut vérifier :

| # | Objectif | But | 1.0 |
|---|---|---|---|
| O1 | Une application déclare ses actions, leurs entrées et leurs URL ; elle n’a aucun code qui lit une requête, vérifie l’accès ou écrit une réponse | 1 | Atteint |
| O2 | Chaque source de données reçoit la création, la lecture, la modification, la suppression et la liste, à travers les mêmes modèles | 3 | Atteint pour les bases de données |
| O3 | Une table est servie par les seuls réglages, sans action écrite pour elle | 1, 3 | Atteint autrement : `makerun` écrit le run d’une table (routes, actions, entrées), donc aucune n’est écrite à la main ; nyuTron l’entoure d’un assistant visuel |
| O4 | Les fichiers sont une source de données comme les bases, configurée dans les réglages | 3 | Version 1.1 ; pour l’instant, les fichiers sont lus et écrits comme des documents par une action |
| O5 | Une première installation tient en une commande et n’a besoin d’aucune base de données | 2 | Atteint |
| O6 | Un service passe d’un fichier des utilisateurs à une base de données, d’une base à plusieurs, et d’une application à plusieurs qui travaillent ensemble, sans réécrire son code | 2 | Atteint |
| O7 | Une requête ne coûte pas plus de CPU qu’avec les micro-frameworks habituels, et bien moins qu’avec les frameworks complets | 4 | Atteint (chapitre 7), mesuré sous Linux les 24 et 25 novembre 2026, après le cycle de vie et les routes des runs, la révision de sécurité et le renommage |
| O8 | La connexion, les rôles, les limites de tentatives, les codes à deux facteurs, les tokens signés et le cycle de vie des comptes viennent avec le framework, sans bibliothèque à ajouter | 5 | Atteint |
| O9 | Les documents, les images, les sons et les e-mails sont lus, écrits et envoyés par le framework lui-même | 5 | Atteint |
| O10 | Un projet est installé, configuré, vérifié, testé et livré depuis une seule ligne de commande | 5 | Atteint |
| O11 | Le framework repose sur les standards actuels : PHP 8.2 et suivants, des tokens signés (RS256), des codes à usage unique (RFC 6238), des identifiants en version 7 (RFC 9562), les formats bureautiques d’aujourd’hui | 5 | Atteint |
| O12 | Les erreurs arrivent au client dans le format du service, et jamais en détail en production | 1 | Atteint |

Les objectifs que la 1.0 n’atteint pas entièrement reviennent, exigence par
exigence, au chapitre 6.

---

## 3. Principes appliqués

Quatre principes guident chaque choix de nyuRuns. Chacun est énoncé ici
avec ce à quoi il engage le framework. La façon dont un service les montre
est dans les
[Spécifications fonctionnelles, 3.1](funcspecs.md#31-les-principes-tels-quun-service-les-montre).

### 3.1 MDD : le développement dirigé par les modèles

Une application est décrite par ses modèles de données, et travaille à
travers eux.

- Une application lit et écrit ses données à travers des modèles (des
  enregistrements et des listes), jamais à travers des requêtes qu’elle
  écrit elle-même.
- Un modèle est déclaré une fois, par la table qu’il représente : sa clé,
  ses champs et ses relations sont déduits de la table et de son nommage.
- Changer de base de données, ou de type de base, ne touche pas aux
  applications, dans la limite de ce que ce type de base sait faire.
- `makerun` s’appuie sur les modèles : il écrit les actions du run d’une
  table, pour qu’une table soit servie sans code écrit à la main. nyuTron,
  les outils autour du framework, l’entoure d’un assistant visuel.

### 3.2 KISS : rester simple

Ce dont un service n’a pas besoin n’est ni installé, ni chargé, ni
configuré.

- Une première installation tient en une commande, sans base de données.
- Une requête ne charge que ce qu’elle utilise : une requête qui n’atteint
  aucune base de données n’en ouvre aucune, et un modèle n’est créé que
  quand une action le demande.
- Les réglages sont de simples fichiers, changés une valeur à la fois, et
  chaque valeur est vérifiée quand elle est écrite.
- Chaque partie fait une seule chose, et un projet peut ajouter les
  siennes (une vérification d’identifiants, un porteur, une commande) à
  côté de celles du framework, sans les changer.
- Un service grandit en ajoutant, jamais en réécrivant ce qu’il a.

### 3.3 REST

Chaque ressource a sa propre URL, la méthode de la requête dit ce qu’on en
fait, et le statut de la réponse dit comment ça s’est passé.

- La méthode décide de l’action : une route peut donner à chaque méthode
  d’une URL sa propre action.
- Le statut est toujours celui que HTTP donne pour le cas (201 pour ce qui
  est créé, 404 pour ce qui est introuvable, 409 pour un conflit, …), quel
  que soit le format du corps.
- Une requête porte tout ce dont le service a besoin pour y répondre : un
  token ou une clé, jamais un état gardé entre deux requêtes, sauf la
  session qu’un site choisit d’utiliser.
- Un client apprend ce dont il a besoin, et rien de plus : ce qu’il ne peut
  pas atteindre est « introuvable », exactement comme ce qui n’existe pas.

### 3.4 CRUDL : créer, lire, modifier, supprimer, lister

Chaque source de données reçoit les cinq mêmes opérations, écrites de la
même façon.

- Un enregistrement est créé, lu, modifié et supprimé ; une liste est lue,
  filtrée, triée, limitée et jointe.
- Le filtre d’une liste s’écrit de la même façon pour tous les types de
  base, et une valeur venue d’une requête n’est jamais lue comme un motif.
- La vision d’origine appelait ces opérations CRUDI, le I désignant
  l’index d’une liste. C’est le nom CRUDL qui est resté.

### 3.5 Les règles qui en découlent

Les principes mènent à quelques règles qui valent dans tout le framework :

- **Déclaré, pas codé** : les routes, les entrées, les rôles et les
  réglages sont déclarés dans des fichiers ; on peut donc savoir ce que
  fait un service sans lire son code. Chaque run déclare ses propres
  routes, à côté de ses actions.
- **Fermé par défaut** : dans un profil qui demande des identifiants,
  chaque route en exige un, sauf si elle est ouverte exprès, et un réglage
  qui ne peut pas s’appliquer refuse toutes les requêtes.
- **Un seul nommage** : chaque nom du code, des réglages et des tables a la
  longueur de sa sorte, pour qu’un nom dise ce qu’il est
  ([Spécifications techniques, chapitre 12](techspecs.md#12-conventions-de-code)).

---

## 4. Public visé

### 4.1 Qui construit avec nyuRuns

| Lecteur | A besoin que nyuRuns lui permette de |
|---|---|
| **Un développeur PHP** | écrire un back-end sans en écrire la tuyauterie : le routage, l’accès, les entrées, les réponses, les erreurs |
| **Une équipe** | partager une seule façon d’écrire des services : la même organisation, le même nommage et les mêmes conventions dans chaque projet, vérifiés par les tests |
| **Une petite entreprise, ou son prestataire** | faire tourner son propre back-end sur un hébergement PHP ordinaire, sans une lourde pile à installer, surveiller et mettre à jour |
| **Un analyste ou un responsable produit** | savoir ce que fera un service, et ce que chaque utilisateur peut atteindre, à partir de ses spécifications et de ses réglages plutôt que de son code |
| **La personne qui l’héberge** | installer, vérifier et mettre à jour un projet depuis une seule ligne de commande, avec ce que doit fournir le serveur listé à un seul endroit |

nyuRuns attend de ses développeurs qu’ils connaissent PHP et HTTP. Il n’a
besoin d’aucun langage de gabarits, ses modèles se résument à une poignée
de méthodes, et son conteneur ne se met pas en travers des applications.

### 4.2 Ce qu’on construit avec

- **Des back-ends métier** : les dossiers d’une entreprise (membres,
  contacts, commandes, tâches, documents) et les personnes qui travaillent
  dessus, avec leurs rôles.
- **Des API pour des front-ends** : une app web ou mobile, écrite dans
  n’importe quel langage, qui parle au service en JSON.
- **Des services qui travaillent ensemble** : plusieurs services, chacun
  propriétaire de ses données, dont l’un connecte les utilisateurs pour les
  autres, et qui s’appellent tous entre eux avec des clés.
- **Des services pour des machines** : des appareils et des programmes qui
  appellent avec leurs propres identifiants, sans que personne ne se
  connecte.
- **Des sites légers** : des pages et des fichiers servis à côté d’une API,
  sans système de gestion de contenu.

### 4.3 Ce pour quoi il n’est pas fait

- **Les sites centrés sur les pages**, avec des gabarits, des formulaires
  et un thème : nyuRuns sert des pages, mais il est construit pour les
  données et les API.
- **Les échanges en temps réel** (messageries, flux en direct) : chaque
  échange est une requête et sa réponse, et les sockets sont laissés à la
  version 1.1.
- **Un service qui doit tenir une très forte charge sur un seul serveur,
  sans mesures à lui** : nyuRuns n’a été mesuré sous charge que sur une
  petite machine, bien en dessous du serveur de référence (chapitre 7).

---

## 5. Fonctionnalités de base

Les fonctionnalités que nyuRuns doit avoir, par domaine. **1.0** : dans la
version 1.0 ; **1.1** : laissée à la version 1.1 ; **nyuTron** : dans le
projet séparé d’outils autour de nyuRuns ; **abandonnée** : mise de côté
pour de bon.

### 5.1 Requêtes et réponses

| Fonctionnalité | Statut |
|---|---|
| Toute la requête prise en charge : route, accès, entrées, action, réponse | 1.0 |
| Des entrées gardées selon la définition de chaque action, chacune dans son format ; des champs obligatoires | 1.0 |
| Des réponses en JSON, YAML, XML, CSV, HTML, ou brutes | 1.0 |
| Des erreurs dans le format de la réponse, journalisées, jamais détaillées en production | 1.0 |
| Des limites sur la taille des requêtes | 1.0 |
| Des appels depuis d’autres sites (CORS), pour les origines listées | 1.0 |
| L’encodage des entrées | 1.1 |
| Des entrées venues d’autres sources que la requête : des fichiers, des enregistrements, d’autres API, la session, un flux | 1.1 |
| Les sockets | 1.1 |

### 5.2 Routage

| Fonctionnalité | Statut |
|---|---|
| Des routes trouvées à partir du chemin, ou déclarées dans des fichiers de routes, par le site ou par chaque run | 1.0 |
| Des runs montés là où le dit le profil, ou pas servis | 1.0 |
| Une action par méthode d’une URL | 1.0 |
| Des redirections, des fichiers et des dossiers statiques, des pages HTML | 1.0 |
| Les propres pages d’erreur d’un site | 1.0 |
| Des fichiers statiques mis en cache par les clients (version et date, 304) | 1.0 |
| Des réponses de données mises en cache | pas encore prévu |

### 5.3 Accès

| Fonctionnalité | Statut |
|---|---|
| Des porteurs : l’authentification Basic, une clé d’API dans un en-tête, un token signé, une session PHP, un certificat client | 1.0 |
| Des vérifications : un fichier de mots de passe, des clés d’API, un fichier des utilisateurs, une table des utilisateurs, des tokens d’appareils, une vérification propre au projet | 1.0 |
| Des rôles classés, par route et par action | 1.0 |
| Une limite de mauvaises tentatives, par adresse et par compte | 1.0 |
| Des codes à deux facteurs, depuis une app d’authentification ou par e-mail | 1.0 |
| Des tokens signés avec une paire de clés (RS256), vérifiés par d’autres services | 1.0 |
| L’émetteur et l’audience des tokens, et les clés publiques publiées | 1.0 |
| Des applications qui appellent pour leurs utilisateurs, avec une clé | 1.0 |
| Des connecteurs SSO et LDAP | 1.1 |
| Des codes par SMS comme second facteur ; une connexion approuvée depuis le téléphone | 1.1 |
| Des certificats clients présentés à travers un proxy, et un profil pour eux ; l’épinglage des certificats | 1.1 |
| Des limites par route | 1.1 |
| L’authentification Digest et un porteur par cookie | abandonnée |

### 5.4 Utilisateurs

| Fonctionnalité | Statut |
|---|---|
| Des utilisateurs dans un fichier ou une table de base de données, déplacés de l’un à l’autre | 1.0 |
| Des comptes créés, activés par un code, bloqués, réinitialisés, supprimés ; des rôles | 1.0 |
| Des mots de passe changés et réinitialisés par les utilisateurs eux-mêmes | 1.0 |
| Des inscriptions, confirmées par e-mail et validées par un gestionnaire | 1.0 |
| Des e-mails d’accès, dans la langue de chaque utilisateur | 1.0 |
| Des appareils, avec leurs propres tokens | 1.0 |
| Des utilisateurs gérés par une application et en ligne de commande | 1.0 |

### 5.5 Données

| Fonctionnalité | Statut |
|---|---|
| Des bases de données : MySQL, MariaDB, PostgreSQL, SQLite, MongoDB ; plusieurs à la fois | 1.0 |
| Des enregistrements et des listes, avec un seul filtre pour tous les types de base | 1.0 |
| Des relations : les enfants et le parent d’un enregistrement | 1.0 |
| Les transactions | 1.0 |
| Des valeurs binaires, lues et envoyées morceau par morceau | 1.0 |
| Des identifiants qui voyagent avec un enregistrement (UUID version 7) | 1.0 |
| Plusieurs jointures dans une même liste | 1.1 |
| Une table servie sans code écrit à la main (`makerun`) | 1.0 |

### 5.6 Documents et médias

| Fonctionnalité | Statut |
|---|---|
| Des documents lus et écrits : texte, Markdown, HTML, XML, JSON, YAML, INI (en lecture), CSV et TSV, iCalendar, PDF, Word, Excel, OpenDocument, ePub | 1.0 |
| Des images : lues, redimensionnées, recadrées, tournées, nettoyées ; des dessins changés en images | 1.0 |
| Des sons : lus, débarrassés de leurs tags, coupés ; convertis par ffmpeg | 1.0 |
| Les QR codes | 1.0 |
| Le texte enrichi et le code source lus comme des documents | 1.1 |
| L’écriture manuscrite et le texte scanné lus | 1.1 |
| Des sons mixés : forme d’onde, volume, fondus, tempo | 1.1 |

### 5.7 E-mails

| Fonctionnalité | Statut |
|---|---|
| Des comptes d’e-mail qui envoient par SMTP, par PHPMailer, ou dans des fichiers | 1.0 |
| Des e-mails écrits à partir de gabarits, en plusieurs langues | 1.0 |

### 5.8 Ligne de commande et outils

| Fonctionnalité | Statut |
|---|---|
| L’installation et le diagnostic d’un projet | 1.0 |
| Des réglages lus et changés, des secrets créés | 1.0 |
| Des utilisateurs, des appareils et des clés d’application gérés | 1.0 |
| Une copie du projet construite pour chaque environnement | 1.0 |
| La suite de tests et l’audit des conventions | 1.0 |
| Un run écrit à partir d’une table : routes, actions, entrées (`makerun`) | 1.0 |
| Un assistant visuel autour de `makerun` | nyuTron |
| Un assistant d’installation | nyuTron |
| Un gestionnaire des réglages avancés | nyuTron |
| Des kits de données prêts à l’emploi : membres, clients, commandes, tâches, projets | nyuTron |

---

## 6. Exigences détaillées

Chaque exigence est une règle que le framework respecte. « Atteinte dans »
nomme la section qui explique comment : **ST** pour les
[Spécifications techniques](techspecs.md), **SF** pour les
[Spécifications fonctionnelles](funcspecs.md).

### 6.1 Requêtes et entrées

| # | Exigence | Statut | Atteinte dans |
|---|---|---|---|
| R-01 | Chaque requête passe par une seule entrée, en une seule séquence : route, accès, entrées, action, réponse | 1.0 | ST 4 |
| R-02 | Un corps plus gros que la taille réglée, ou qui contient plus de parties que réglé, est refusé (413) : un corps annoncé plus gros n’est pas lu, et un corps qui s’avère plus gros n’est pas lu au-delà de la limite | 1.0 | ST 4.2 |
| R-03 | Une action ne reçoit que les entrées que liste sa définition, chacune dans son format ; s’il manque une entrée obligatoire, la requête est refusée (400) avant que l’action ne tourne | 1.0 | ST 6.9, SF 5.8 |
| R-04 | Les valeurs du serveur et du client ne sont jamais des entrées ; ce qu’un client dit de lui-même ne donne jamais d’accès | 1.0 | ST 9.2 |
| R-05 | Une valeur venue d’une requête et utilisée dans un filtre est comparée telle quelle, jamais lue comme un motif | 1.0 | ST 10.5 |
| R-06 | Les entrées viennent d’autres sources que la requête : des fichiers, des enregistrements, d’autres API, la session, un flux | 1.1 | |
| R-07 | L’encodage des entrées est réglé, et converti | 1.1 | |

### 6.2 Routage et réponses

| # | Exigence | Statut | Atteinte dans |
|---|---|---|---|
| R-08 | Une route est trouvée à partir du chemin, ou des routes que déclarent le site ou chaque run (un run est monté là où le dit le profil) ; une route déclarée nomme une action pour chaque méthode | 1.0 | ST 4.3 |
| R-09 | Une route déclarée redirige, ou sert un fichier, un dossier ou une page ; un fichier ne sort jamais de son dossier | 1.0 | ST 6.8, ST 9.3 |
| R-10 | Tout ce qu’un client ne peut pas atteindre reçoit la même réponse, 404, que ça n’existe pas ou que ce soit inatteignable ; le seul 403 restant est celui d’un token d’appareil sur une action qui modifie le compte de son utilisateur | 1.0 | ST 4.5 |
| R-11 | Une réponse est écrite dans le format du profil, ou de l’action : JSON, YAML, XML, CSV, HTML, brut | 1.0 | ST 4.6 |
| R-12 | Une erreur est écrite dans le format de la réponse, journalisée, et jamais détaillée en production | 1.0 | ST 4.7 |
| R-13 | Chaque réponse interdit la détection du type de contenu ; d’autres sites n’appellent depuis un navigateur que depuis les origines listées | 1.0 | ST 9.3 |
| R-14 | Un fichier statique est envoyé avec sa version et sa date, et un client qui l’a déjà reçoit un 304 | 1.0 | ST 1.6 |
| R-15 | Un client et le service échangent par des sockets | 1.1 | |

### 6.3 Accès

| # | Exigence | Statut | Atteinte dans |
|---|---|---|---|
| R-16 | Dans un profil qui demande des identifiants, chaque route en exige un, sauf si le profil l’ouvre exprès ; un réglage d’accès qui ne peut pas s’appliquer refuse toutes les requêtes | 1.0 | ST 4.4 |
| R-17 | Un identifiant est porté par l’authentification Basic, une clé dans un en-tête, un token signé, une session PHP ou un certificat client, et vérifié avec un fichier de mots de passe, une liste de clés, un fichier des utilisateurs, une table des utilisateurs, des tokens d’appareils, ou une vérification propre au projet | 1.0 | ST 5.3 |
| R-18 | Les rôles sont classés ; une route, ou une action, demande le rôle minimum dont elle a besoin | 1.0 | ST 2.8, SF 5.2 |
| R-19 | Les mauvais identifiants sont comptés par adresse, et l’adresse est mise en attente au-delà d’une limite (429) ; un compte est mis en attente après trop de mauvais mots de passe | 1.0 | ST 9.5 |
| R-20 | Un mauvais nom et un mauvais mot de passe reçoivent le même refus, et, là où les mots de passe sont hachés avec bcrypt, dans le même temps | 1.0 | ST 9.4 |
| R-21 | Un utilisateur avec des codes à deux facteurs se connecte avec un code à usage unique, depuis une app d’authentification ou envoyé par e-mail, chacun accepté une seule fois | 1.0 | SF 5.2 |
| R-22 | Les tokens sont signés et expirent ; les tokens signés avec une paire de clés sont vérifiés par d’autres services avec la seule clé publique | 1.0 | ST 2.5, SF 4.4 |
| R-23 | Une application appelle pour ses propres utilisateurs avec une clé, limitée aux routes qu’on lui donne et à un rôle maximum | 1.0 | SF 5.7 |
| R-24 | Les utilisateurs se connectent par SSO ou LDAP | 1.1 | |
| R-25 | Un second facteur est envoyé par SMS, ou une connexion est approuvée depuis le téléphone | 1.1 | |
| R-26 | Les tokens nomment leur émetteur et leur audience, et les clés qui les vérifient sont publiées | 1.0 | SF 5.7, SF A.1 |
| R-27 | Les certificats clients sont lus à travers un proxy, avec un profil à eux ; les certificats sont épinglés | 1.1 | |
| R-28 | Les limites sont réglées route par route | 1.1 | |

### 6.4 Utilisateurs

| # | Exigence | Statut | Atteinte dans |
|---|---|---|---|
| R-29 | Les utilisateurs sont gardés dans un fichier ou dans une table, et déplacés de l’un à l’autre avec leurs mots de passe, leurs rôles et leurs secrets | 1.0 | SF 6.2 |
| R-30 | Un compte passe par les statuts en attente d’activation, en attente de validation, actif et bloqué ; un code l’active | 1.0 | SF 5.3 |
| R-31 | Les utilisateurs changent leur propre mot de passe, et réinitialisent un mot de passe oublié par e-mail, leur mot de passe étant gardé jusqu’à ce que le code serve | 1.0 | SF 5.5 |
| R-32 | Les visiteurs s’inscrivent, confirment leur adresse, et attendent la validation d’un gestionnaire ; les inscriptions sont limitées par client et au total | 1.0 | SF 5.4 |
| R-33 | Les e-mails d’accès partent dans la langue de chaque utilisateur | 1.0 | SF 5.10 |
| R-34 | Un service n’est jamais laissé sans gestionnaire actif | 1.0 | SF 5.3 |
| R-35 | Un appareil agit pour un utilisateur avec un token à lui, révoqué seul | 1.0 | SF 5.6 |
| R-36 | Les clés, les tokens et les codes sont affichés une fois et gardés sous forme d’empreintes ; les secrets à deux facteurs sont gardés chiffrés | 1.0 | ST 9.4 |

### 6.5 Données

| # | Exigence | Statut | Atteinte dans |
|---|---|---|---|
| R-37 | Une application atteint ses données à travers des modèles, enregistrements et listes, jamais à travers des requêtes qu’elle écrit | 1.0 | ST 10 |
| R-38 | Les bases de données sont MySQL, MariaDB, PostgreSQL, SQLite et MongoDB, plusieurs à la fois | 1.0 | ST 10.1 |
| R-39 | Un filtre s’écrit de la même façon pour tous les types de base | 1.0 | ST 10.5 |
| R-40 | Un enregistrement est supprimé avec les enfants nommés, en une seule transaction, ou pas du tout | 1.0 | ST 10.3 |
| R-41 | Une valeur binaire est envoyée au client, et écrite dans un fichier, morceau par morceau ; entre le flux et la base de données, elle est gardée entière, jusqu’à sa limite | 1.0 | ST 10.7 |
| R-42 | La clé d’un enregistrement sauvegardé a le même type que quand il est relu : une clé numérotée par la base est un nombre | 1.0 | ST 10.3 |
| R-43 | Une liste joint plusieurs tables | 1.1 | |
| R-44 | Une table est servie sans code écrit à la main : `makerun` écrit son run à partir de la table | 1.0 | ST 7.10 |

### 6.6 Documents, médias et e-mails

| # | Exigence | Statut | Atteinte dans |
|---|---|---|---|
| R-45 | Un document est lu dans l’une de trois formes (un arbre, des feuilles, des blocs de texte), et écrit à partir d’elle, dans les formats de 5.6 | 1.0 | ST 5.6, SF 5.9 |
| R-46 | Un document dont le contenu ne correspond pas à son nom est refusé | 1.0 | SF 5.9 |
| R-47 | Les images sont lues et modifiées ; les sons sont lus, coupés, et convertis par ffmpeg | 1.0 | SF 5.9 |
| R-48 | Un e-mail est envoyé par SMTP, chiffré et avec le certificat de son serveur vérifié, par PHPMailer, ou dans des fichiers, écrit à partir de ses gabarits | 1.0 | SF 5.10 |
| R-49 | Le texte enrichi, le code source, l’écriture manuscrite et le texte scanné sont lus ; les sons sont mixés | 1.1 | |

### 6.7 Ligne de commande et exploitation

| # | Exigence | Statut | Atteinte dans |
|---|---|---|---|
| R-50 | Un projet est installé en une commande, qui ne remplace rien de ce qui existe déjà ; son diagnostic arrête un script de déploiement en cas d’échec | 1.0 | ST 7.2 |
| R-51 | Un réglage est changé une valeur à la fois, vérifié, et le fichier précédent est gardé | 1.0 | ST 7.3 |
| R-52 | Une copie du projet est construite pour chaque environnement, sans secrets, sans réglages locaux et sans clés | 1.0 | ST 7.6 |
| R-53 | Les utilisateurs, les appareils et les clés d’application sont gérés en ligne de commande, et chaque changement est tracé | 1.0 | ST 7.4, ST 7.5 |
| R-54 | Chaque sujet a son journal ; les accès privilégiés et les refus sont tracés ; une trace ne contient aucun argument de ses appels | 1.0 | ST 9.6 |

### 6.8 Qualité

| # | Exigence | Statut | Atteinte dans |
|---|---|---|---|
| R-55 | Le framework tourne sur PHP 8.2 et suivants ; ses tests passent sur la plus ancienne version prise en charge et sur la plus récente | 1.0 | ST 3.1, ST 13.5 |
| R-56 | Une suite de tests vérifie chaque partie, sur SQLite et sur de vrais serveurs de bases de données | 1.0 | ST 13 |
| R-57 | Chaque nom a la longueur de sa sorte, chaque méthode publique son docblock, chaque paramètre et chaque retour son type ; un audit les vérifie | 1.0 | ST 12 |
| R-58 | Une référence du code est produite à partir de ses docblocks | 1.0 | ST 12.3 |

---

## 7. Objectifs de performance

### 7.1 L’objectif

nyuRuns vise à être aussi efficace que les micro-frameworks, tout en
offrant les fonctionnalités clés, et une partie des capacités, des plus
lourds. Il ne doit pas payer ce côté complet par le coût de chaque requête.

### 7.2 Les objectifs, et où en est la 1.0

| # | Objectif | 1.0 |
|---|---|---|
| P-01 | Une requête coûte au serveur au plus 1,2 fois le CPU qu’elle coûte avec Slim, sur la même machine, pour le même travail | Atteint : 1,02 fois (4,39 ms contre 4,28 ms pour Slim ; Flight 3,70 ms) |
| P-02 | Une requête coûte au plus la moitié du CPU qu’elle coûte avec Symfony, sur la même machine, pour le même travail | Atteint, de peu : 0,45 fois, pour un objectif de 0,5 (9,70 ms pour Symfony ; Laravel 13,13 ms) |
| P-03 | Elle n’envoie pas plus d’instructions à la base de données qu’eux pour le même travail | Atteint : une pour lire un enregistrement, cinq pour un enregistrement avec ses relations dans le cas complexe du banc d’essai |
| P-04 | Une requête ne charge que ce qu’elle utilise : aucune connexion sans lecture de données, aucun modèle sans qu’une action le demande | Atteint |
| P-05 | Ce que le framework lit à chaque requête (les réglages, les routes, les tables) est lu une fois en production, puis depuis un cache | Atteint : `CNF_CACH`, `TBL_CACH`, activés par la copie de production |
| P-06 | Les grosses données sont lues et envoyées morceau par morceau, jamais gardées entières en mémoire | En partie : les fichiers et les valeurs binaires sont envoyés morceau par morceau ; un corps de requête est gardé entier, jusqu’à `REQ_SIZE`, et une valeur binaire jusqu’à sa limite, entre le flux et la base de données |
| P-07 | Sur le serveur de référence (7.3), avec 50 clients à la fois, les lectures légères du banc d’essai (une liste, un enregistrement, les projets d’un utilisateur) traitent 800 requêtes par seconde ou plus, avec le 1 % le plus lent servi en moins de 150 ms et aucune en échec | Pas mesuré sur le serveur de référence. Sur une machine plus petite (7.3), une borne basse : à 32 clients, les lectures légères dépassent 1 040 requêtes par seconde, le 1 % le plus lent servi en 36,4 ms au plus, aucune en échec |
| P-08 | Sur le serveur de référence, avec 50 clients à la fois, les écritures du banc d’essai (un enregistrement créé, une tâche, un commentaire) traitent 500 requêtes par seconde ou plus, dans les mêmes conditions | Pas mesuré sur le serveur de référence. Sur la même machine plus petite, une borne basse : à 32 clients, les écritures dépassent 780 requêtes par seconde, le 1 % le plus lent servi en 52 ms au plus, aucune en échec derrière nginx |
| P-09 | Une requête d’API utilise au plus 4 Mo de mémoire à son pic | Pas encore mesuré |

Les objectifs relatifs (P-01, P-02) valent sur n’importe quelle machine.
Les chiffres de la 1.0 viennent de la série des 24 et 25 novembre 2026 :
un Intel NUC sous Linux, derrière nginx puis derrière Apache, à 1, 8 et
32 clients à la fois, chaque chiffre étant la médiane de trois répétitions.
Ce qu’elle ne couvre pas : PHP 8.2 (la série a utilisé la 8.5), le serveur
de référence, 50 clients, et la mémoire par requête. La méthode, les résultats scénario par scénario et leurs
limites sont dans les
[Spécifications fonctionnelles, chapitre 7](funcspecs.md#7-résultats-de-performance).

### 7.3 Le serveur de référence

Un chiffre en requêtes par seconde ne veut rien dire sans sa machine. P-07
à P-09 sont fixés pour celle-ci :

| | Serveur de référence | La machine mesurée |
|---|---|---|
| Processeur | 6 cœurs, de la classe d’un AMD Ryzen 5 5500 | Intel Core i3-7100U, 2 cœurs / 4 threads, 2,4 GHz : en dessous |
| Mémoire | 16 Go | 4 Go, 3,2 utilisables : en dessous |
| Système | Linux | Ubuntu 26.04 |
| PHP | 8.2 ou suivant, par PHP-FPM, OPcache activé sans vérification des fichiers, JIT désactivé | 8.5.4, par PHP-FPM, OPcache sans vérification des fichiers |
| Base de données | MySQL, sur la même machine | MySQL 8.4, sur la même machine |
| nyuRuns | sa copie `final` : réglages de production, caches de la configuration et des tables activés | `bundles final`, `CNF_CACH` et `TBL_CACH` activés |

Le logiciel correspond ; le matériel est loin du compte, avec environ un
tiers du processeur. Les chiffres de cette machine sont donc une borne
basse pour P-07 et P-08, jamais une mesure de ceux-ci.

**D’où viennent les chiffres.** Les objectifs ont été fixés d’après la
série du 7 novembre, sous Windows : une requête coûtait 2,67 ms de CPU dans le
serveur web et 1,47 ms dans MySQL, environ 4,1 ms en tout. Six cœurs
entièrement occupés répondraient à quelque 1 450 requêtes par seconde. Les
objectifs de charge en prennent à peu près la moitié, pour laisser de la
place à ce qu’ajoute la charge : l’attente sur les verrous, le passage
d’un processus à l’autre, le réseau. Les écritures coûtent environ un
dixième de CPU en plus, mais elles attendent aussi la base de données, ses
verrous et son disque, ce qu’une mesure à une requête à la fois ne montre
pas : leur objectif est donc fixé plus bas.

### 7.4 Ce qui n’est pas encore fixé

- **Les documents et les médias** : la lecture, l’écriture et la
  conversion de gros fichiers, d’images et de sons n’ont pas été mesurées.
  Leurs objectifs seront fixés une fois qu’une première mesure donnera une
  base.

Un objectif est fixé avant d’être mesuré, pour qu’une mesure puisse
l’atteindre, ou non. Les objectifs de charge ont maintenant une première
borne basse, sur une machine en dessous du serveur de référence ; la mesure
elle-même, à 50 clients sur cette classe de machine, reste à faire, tout
comme la mémoire par requête (P-09).

---

## 8. Notes complémentaires

### 8.1 Plateforme

- **PHP 8.2 ou suivant.** 8.0 et 8.1 ne reçoivent plus de correctifs de
  sécurité. Une version qui corrige php2shell (CVE-2026-109618,
  CVE-2026-109619) est recommandée, et le diagnostic te dit quand elle
  manque.
- **Les extensions** dont les bibliothèques ont besoin (`json`, `dom`,
  `iconv`, `zlib`, `mbstring`) sont obligatoires. Les autres ne servent
  qu’aux parties qui les utilisent
  ([Spécifications techniques, chapitre 3](techspecs.md#3-configuration-requise)).
- **N’importe quel serveur web** qui passe la requête à PHP et envoie
  chaque URL vers le point d’entrée. Le serveur lui-même revient à son
  hébergeur ([Hébergement](hosting.md)).

### 8.2 Licence et contributions

- nyuRuns est publié sous la **licence MIT**, copyright 2021-2026 Jason
  JOHNSTON (`LICENSE`).
- Les **contributions** sont signées selon le Developer Certificate of
  Origin (`git commit -s`), comme l’explique `CONTRIBUTING.md`.
- Les **bibliothèques** qu’il utilise gardent leurs propres licences,
  listées dans `NOTICE`. PHPMailer, suggéré et non obligatoire, est sous
  LGPL 2.1.

### 8.3 Versions

| Version | Contient |
|---|---|
| 0.88 | la dernière version avant que le code ne soit complet, étiquetée le 21 octobre 2026 |
| 0.9 | le code complet : la table des appareils renommée, les tokens RS256, le format des profils, la copie construite pour chaque environnement, les réglages de livraison |
| **1.0** | la première version publique : la documentation, la licence, l’optimisation des performances, les codes par e-mail, les tokens liés à un émetteur et à une audience, les propres routes de chaque run, la révision de sécurité, et la dernière passe de tests |
| 1.1 | ce que les chapitres 5 et 6 lui laissent : SSO et LDAP, un second facteur par SMS ou approuvé depuis le téléphone, les certificats à travers un proxy, les limites par route, d’autres sources d’entrées, les sockets, plusieurs jointures, le texte enrichi et le code, l’écriture manuscrite, le mixage des sons |
| nyuTron (nom de code) | un projet séparé d’outils autour de nyuRuns : un assistant visuel autour de `makerun`, un assistant d’installation, un gestionnaire des réglages avancés, des kits de données prêts à l’emploi |

Chaque version est close par une passe complète de la suite de tests, sur
les versions de PHP et les serveurs de bases de données pris en charge.

### 8.4 Ce qui a changé depuis la vision d’origine

- **CRUDI** est devenu **CRUDL** : les mêmes opérations, avec la liste
  nommée pour ce qu’elle est.
- **Une table servie par les seuls réglages** est plutôt générée : `makerun`
  écrit son run à partir de la table, au lieu que le framework la lise
  depuis les réglages.
- **Les fichiers comme sources de données**, **les sockets** et **un cache
  pour les réponses de données** ne sont pas dans la 1.0. Les deux premiers
  viennent avec la version 1.1, et le dernier n’est pas encore prévu.
- **L’authentification Digest et un porteur par cookie** ont été
  abandonnés.

---
