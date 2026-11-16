# nyuRuns: translation glossary

French (France), with **tu**; Spanish (Spain), with **tú**. The counts are
the occurrences in the English docs, outside code, on 2026-11-16.

## Files

- The English sources: `docs/en/` (User Guide, FuncSpecs, TechSpecs,
  Requirements) and `HOSTING.md` at the root.
- The translations: `docs/fr/` and `docs/es/`, with the same file names,
  and `hosting.md` for `HOSTING.md`. In a translation, a link to
  `../../HOSTING.md` becomes `hosting.md`, beside it.
- `README.md`, `SECURITY.md` and `CONTRIBUTING.md` stay in English, in the
  repository only.
- The HTML: `pwsh .phpdoc/build.ps1` writes `docs/online/` (not tracked), put
  online at www.nyuark.com/nyuRuns/docs/. The code reference stays in English.

## Never translated

Everything in backticks or in a code block stays as it is: code, commands,
keys, codes, paths, file names, and the server's messages (`Not Found`,
`Invalid credentials`), since they are what a client receives. The product
names stay too: nyuRuns, nyuTron, makerun. The tutorial's sample data
(Shopping, Milk, bread) is translated.

## Terms

| # | English | Uses | French | Spanish | Note |
|---|---|---|---|---|---|
| 1 | run (an application) | 295 | le **run**, les runs | el **run**, los runs | kept as the product's word, defined once in each document; "application" is taken by another meaning (an application calling with a key) |
| 2 | action | 193 | action | acción | |
| 3 | route | 182 | route | ruta | |
| 4 | request | 209 | requête | petición | |
| 5 | reply | 65 | réponse | respuesta | |
| 6 | preset / profile | 162 | profil (« preset » named once, as in the code) | perfil (idem) | the English uses both; one word each in FR and ES |
| 7 | entry point | 9 | point d'entrée | punto de entrada | |
| 8 | input(s) | 72 | entrée(s) | entrada(s) | "the inputs of an action": les entrées d'une action |
| 9 | store (of users) | 57 | base d'utilisateurs | base de usuarios | the native one is « base d'utilisateurs native » / « base de usuarios nativa » (#11) |
| 10 | users file | 30 | fichier des utilisateurs | archivo de usuarios | |
| 11 | native userbase | 15 | base d'utilisateurs native | base de usuarios nativa | |
| 12 | account | 130 | compte | cuenta | |
| 13 | role | 111 | rôle | rol | role codes stay in English (`standard`…) |
| 14 | manager (the person) | 58 | gestionnaire | gestor | the role code stays `managers` |
| 14a | delegate (the person) | | délégué | delegado | the role code stays `delegate` |
| 15 | status (of an account) | 53 | statut | estado | |
| 16 | sign in / sign out | 17+ | se connecter / se déconnecter | iniciar sesión / cerrar sesión | |
| 17 | sign-up | 54 | inscription | alta (darse de alta) | ES "registro" is kept for a record (31) |
| 18 | credential | 36 | identifiant(s) | credencial(es) | |
| 19 | carrier | 23 | porteur | portador | what carries the credential in the request |
| 20 | check (a credential check) | 125 | vérification | verificación | the health check is 31 |
| 21 | token | 174 | token | token | the usual word in both |
| 22 | key pair | 7 | paire de clés | par de claves | |
| 23 | secret | 76 | secret | secreto | |
| 24 | two-factor code | 21 | code à deux facteurs | código de doble factor | "2FA" kept as is |
| 25 | hold (an account or an address held) | 69 | en attente (un compte mis en attente) | en espera (una cuenta en espera) | the status `awaiting` is a code and stays in English |
| 25a | pending (an account waiting for its code, `awaiting`) | | en attente d'activation | pendiente de activación | never « en attente » alone, which is a hold (#25) |
| 25b | waiting for a manager (a sign-up, `inReview`) | | en attente de validation | pendiente de aprobación | |
| 25c | count (of attempts, of sign-ups) | | décompte | recuento | never « compte » / « cuenta », which is always an account; the verb "counts against" stays « compte contre » / « cuenta contra » |
| 26 | attempt (wrong attempts) | 23 | tentative | intento | |
| 27 | login mark | 7 | marque de connexion | marca de inicio de sesión | |
| 28 | device | 53 | appareil | dispositivo | |
| 29 | application key | 13 | clé d'application | clave de aplicación | |
| 30 | issuer / audience (of a token) | 13 / 13 | émetteur / audience | emisor / audiencia | as in the JWT RFC translations |
| 31 | mail (an e-mail) | 156 | e-mail | correo electrónico, then correo | FR "courriel" is Québec usage |
| 32 | access mails | | e-mails d'accès | correos de acceso | |
| 33 | mail account | | compte de messagerie | cuenta de correo | |
| 34 | template (of a mail) | 14 | gabarit | plantilla | FR "modèle" is kept for a model (34) |
| 35 | model (data) | 63 | modèle | modelo | |
| 36 | record | 55 | enregistrement | registro | |
| 37 | list (a model, a listing) | 106 | liste | lista | |
| 38 | filter | 18 | filtre | filtro | |
| 39 | field / column / table | 49 | champ / colonne / table | campo / columna / tabla | |
| 40 | placeholder (of a page) | 7 | emplacement | marcador | "%name%" |
| 41 | page / static file | 75 / 13 | page / fichier statique | página / archivo estático | |
| 42 | bundle (a copy for one environment) | 24 | bundle | bundle | kept in both; the command `bundles` stays |
| 43 | bundle mode | 20 | mode de bundle | modo de bundle | |
| 44 | environment | 24 | environnement | entorno | |
| 45 | health check | 16 | diagnostic | diagnóstico | the command `install checkAll` stays |
| 46 | command line / sub-command | 39 / 16 | ligne de commande / sous-commande | línea de comandos / subcomando | |
| 47 | mount (a run's routes) | 5 | monter | montar | |
| 48 | config cache | 29 | cache de configuration | caché de configuración | |
| 49 | front end / back end | 31 | front-end / back-end | front-end / back-end | the usual loanwords in both |
| 50 | web server / reverse proxy / load balancer | 42 / 31 / 3 | serveur web / proxy inverse / répartiteur de charge | servidor web / proxy inverso / balanceador de carga | |
| 51 | owner (of a route) | 17 | propriétaire | propietario | |
| 52 | logs | 54 | journaux | registros | the channels (`access`, `runner`…) stay |
| 53 | Met / Partly met / Not met (a need, a requirement) | | Atteint / En partie / Non atteint | Cumplido / En parte / No cumplido | the statuses of FuncSpecs 2.2 and of the Requirements |
| 54 | actor (who uses a service) | | acteur | actor | Standard user, Advanced user: Utilisateur standard, Utilisateur avancé / Usuario estándar, Usuario avanzado |
| 55 | settings | | réglages | ajustes | |
| 56 | reset (a password, an account) | | réinitialiser, réinitialisation | restablecer, restablecimiento | |
| 57 | path (of a URL) | | chemin | ruta de la URL | ES « ruta » alone is a route (#3); « ruta de la URL » where both could be meant |
| 58 | test suite | | suite de tests | batería de pruebas | |
| 59 | benchmark | | banc d'essai | banco de pruebas | |
| 60 | warning / notice (log levels) | | avertissement / notice | aviso / notificación | |
| 61 | helper (a method) | | méthode utilitaire | método auxiliar | |
| 62 | hook (`openEachAction()`) | | crochet | gancho | |
| 63 | cap (a device's or an application's highest role) | | plafond | tope | |
| 64 | preflight (CORS) | | pré-requête | petición previa | |
| 65 | join (of tables) | | jointure | unión | |
| 66 | firewall | | pare-feu | cortafuegos | |

## Typography

- French: a non-breaking space before `:` `;` `!` `?` and inside « »;
  « » quotes; the docs' headings in sentence case, as in English.
- Spanish: ¿…? and ¡…!; « » quotes, as the RAE prefers (no space inside them, unlike French).
- French: the apostrophe is ’, in prose and headings.
- Both: the same chapter numbers as the English. A heading's anchor is made
  from its text (accents kept, punctuation dropped, spaces as `-`), so a link
  into another document uses that document's translated heading: the
  headings linked across documents are fixed below.

## Headings linked across documents

| Document and heading | French | Spanish |
|---|---|---|
| HOSTING 1. Purpose and split of duties | 1. Rôle et partage des tâches | 1. Propósito y reparto de tareas |
| HOSTING 2. › Web server (a subsection, named in the User Guide 8.3) | Serveur web | Servidor web |
| HOSTING 3. Reverse proxy | 3. Proxy inverse | 3. Proxy inverso |
| HOSTING 5. Limit by address and account holds | 5. Limite par adresse et comptes en attente | 5. Límite por dirección y cuentas en espera |
| HOSTING 6. Password files | 6. Fichiers de mots de passe | 6. Archivos de contraseñas |
| HOSTING 7. Two-factor codes | 7. Codes à deux facteurs | 7. Códigos de doble factor |
| HOSTING 8. Tokens | 8. Tokens | 8. Tokens |
| HOSTING 9. PHP sessions | 9. Sessions PHP | 9. Sesiones PHP |
| HOSTING 10. Other sites calling in | 10. Les autres sites qui appellent | 10. Otros sitios que llaman |
| HOSTING 11. Request sizes | 11. Taille des requêtes | 11. Tamaño de las peticiones |
| HOSTING 12. Errors and logs | 12. Erreurs et journaux | 12. Errores y registros |
| HOSTING 13. Routing notes | 13. Notes sur le routage | 13. Notas sobre el enrutamiento |
| HOSTING 15. Access mails | 15. E-mails d’accès | 15. Correos de acceso |
| FuncSpecs 3.1 The principles, as a service shows them | 3.1 Les principes, tels qu’un service les montre | 3.1 Los principios, tal como los muestra un servicio |
| FuncSpecs 7. Performance results | 7. Résultats de performance | 7. Resultados de rendimiento |
| FuncSpecs 8. Development guidelines | 8. Recommandations de développement | 8. Pautas de desarrollo |
| TechSpecs 1.4 What version 1.0 holds | 1.4 Ce que contient la version 1.0 | 1.4 Lo que incluye la versión 1.0 |
| TechSpecs 1.5 What is left for later | 1.5 Ce qui reste pour plus tard | 1.5 Lo que queda para más adelante |
| TechSpecs 2. Guidelines and recommendations | 2. Lignes directrices et recommandations | 2. Pautas y recomendaciones |
| TechSpecs 3. System requirements | 3. Configuration requise | 3. Requisitos del sistema |
| TechSpecs 6. Configuration files | 6. Fichiers de configuration | 6. Archivos de configuración |
| TechSpecs 11. Directory structure | 11. Structure des dossiers | 11. Estructura de carpetas |
| TechSpecs 12. Coding conventions | 12. Conventions de code | 12. Convenciones de código |