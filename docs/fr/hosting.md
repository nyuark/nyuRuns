# Héberger nyuRuns

Ce que doit régler la personne qui met un projet nyuRuns sur un serveur, et
les limites à connaître avant l’arrivée de vrais utilisateurs. Ce document
accompagne la révision de sécurité de la version 1.0.

## 1. Rôle et partage des tâches

Le framework prend une requête en main dès que PHP la reçoit : le routage,
l’accès, les entrées, la réponse. Tout ce qui vient avant revient à
l’hébergeur, et le framework n’y touche jamais :

- la configuration du serveur web (hôte virtuel, TLS, certificats clients) ;
- le pare-feu et ce qui peut atteindre le serveur PHP ;
- `php.ini` ;
- le proxy inverse ou le CDN, quand il y en a un.

Les réglages propres au framework sont dans `conf/` et `.env`, et se lisent
et s’écrivent avec `php nyuRun setting`.

## 2. Mise en ligne

```bash
php nyuRun install complete
```

Cette commande vérifie le serveur, crée les fichiers locaux à partir de
leurs exemples (`.env`, `conf/access.json`, `conf/database.json`), crée les
secrets encore vides, crée les utilisateurs, et finit par le diagnostic.
Elle demande quels comptes de base créer, la version simple (`userDemo`,
`bossDemo`) ou la complète, un par rôle (`userDemo`, `powrDemo`,
`deptDemo`, `bossDemo`), puis le nom de chacun, ceux-là proposés ;
`--noPrompt` prend la version simple avec ces noms. Relancée, elle ne
remplace rien de ce qui existe.

Avec la base d’utilisateurs native (`nativeDb`), elle pose les questions
des e-mails d’accès juste après celle de la base : s’ils sont envoyés (non
par défaut), le type du compte d’e-mail, le compte lui-même, le lien du
front-end, si les inscriptions sont ouvertes, et une adresse où envoyer un
e-mail de test (§15). `--sendWith=<type>` (`none` pour aucun e-mail) et
`--signupOn` y répondent d’avance ; `--noPrompt` seul prend `none` et pas
d’inscriptions. Aucune autre base ne reçoit ces questions.

Sa première question porte sur ce qu’est le projet ; `--projKind=<kind>`
y répond d’avance, et `--noPrompt` prend `allInOne`. Aucun réglage ne garde
la réponse : l’installation écrit les blocs de `conf/access.json` dont le
profil a besoin, et `install checkAll` relit le profil à partir d’eux (sa
ligne `profile`).

| Profil | Ce que c’est | Ce que l’installation demande en plus |
|---|---|---|
| `allInOne` | les utilisateurs, la connexion et les données dans un seul projet | rien : la base d’utilisateurs, la base de données, les comptes, les clés des tokens |
| `authServ` | un serveur d’authentification : il connecte les utilisateurs et émet des tokens pour d’autres projets | l’`issuerId` de ses tokens (le nom de l’hôte proposé) et leur `audience`, les projets qui les accepteront (séparés par des virgules) ; les tokens sont signés en RS256, la paire de clés est créée comme le fait `setting mkSecret jwtToken`, et publiée à `/.well-known/jwks.json` |
| `dataServ` | un serveur de ressources : sans utilisateurs à lui, il accepte les tokens d’un serveur d’authentification | pas la base d’utilisateurs, et aucun utilisateur n’est créé ; le fichier de la clé publique du serveur d’authentification (copié dans `conf/keys`), son `issuerId`, et l’`audience` qui nomme ce projet. Le bloc des tokens ne garde que cette clé publique (`"external": true`), et la connexion ne nomme aucune vérification d’identifiants : elle répond 501 |
| `openSite` | un site où il n’y a rien pour se connecter : des fichiers statiques et des pages seulement | ni la base d’utilisateurs, ni les comptes, ni les clés des tokens, ni aucun autre secret ; la connexion ne nomme aucune vérification d’identifiants. La carte des profils servis propose `/=statics`, et chacun de ses profils est ouvert (`"required": false`, `"isPublic": true`), sauf s’il peut atteindre une action : l’installation s’arrête alors, et rien n’est ouvert ni écrit |

Un serveur d’authentification refuse la base `none` ; un serveur de
ressources et un site ouvert refusent `--useStore`.

Un site ouvert ne rend jamais un run public par accident. `statics` ne sert
aucun run (`"runMount": { "*": false }` : `*` désigne chaque run qui n’a
pas de ligne à lui), et `install checkAll` fait échouer sa ligne `profile`
quand un profil ouvert de la carte peut atteindre une action. Un run qui
doit être public s’ouvre exprès, dans un profil qui vérifie l’accès, avec
`openList`. Le retour en arrière referme les choses : quand l’installation
est relancée et donne au projet une base d’utilisateurs, chaque profil de
la carte ouverte marqué `isPublic` est fermé (`"required": true`,
`isPublic` retiré), et nommé dans la sortie. Un profil fermé de cette façon
n’a pas de porteur : il répond 500 jusqu’à ce que son propriétaire lui en
donne un, ou le rouvre exprès. Les règles des fichiers ne changent pas avec
le profil : un fichier ne sort jamais de son dossier, seules les extensions
de `Response::TYPE_LIST` sont servies, `nosniff` est toujours envoyé.

Sans `conf/access.json`, chaque profil répond 500 `Access misconfigured` :
rien ne passe. Seul un profil ouvert exprès (`"required": false` avec
`"isPublic": true`) est encore servi.

```bash
php nyuRun install checkAll
```

Chaque ligne est `fine`, `warn` (mérite un coup d’œil) ou `fail` (les
requêtes ne peuvent pas être servies, ou sont exposées). La commande se
termine avec un code d’échec quand une ligne est en `fail` : un script de
déploiement peut donc s’y arrêter.

Avant l’arrivée de vrais utilisateurs :

- Mets `ENV_NAME = PROD` dans `conf/system.ini`. En PROD, rien d’une erreur
  n’est montré à un client, quoi que dise `ERR_SHOW`.
- Garde `.env` lisible par son seul propriétaire (`chmod 600 .env`). Il
  contient tous les secrets ; les fichiers de configuration ne font que les
  nommer (`env:NAME`).
- Sers le dossier `www`, et rien d’autre. Le projet porte un `.htaccess` à
  sa racine et dans chaque dossier hors de `www`, qui refuse tout, et un
  dans `www` qui envoie chaque chemin vers `index.php`. Apache ne les lit
  que si `AllowOverride` le lui permet (`All`, ou
  `FileInfo Options AuthConfig Limit`). nginx ne les lit jamais : c’est sa
  configuration ci-dessous qui protège le projet.
- Retire de `www` ce que ton site n’utilise pas.
- Fais tourner PHP 8.2 au minimum, dans une version qui corrige php2shell
  (CVE-2026-109618, CVE-2026-109619) : 8.2.35, 8.3.36, 8.4.27, 8.5.12 ou
  plus récente. PHP 8.0 et 8.1 n’ont aucune version qui le corrige. Une
  distribution qui reporte le correctif garde le numéro de sa version : le
  diagnostic donne donc un `warn` (`php patch`), pas un `fail`. Demande à
  l’hébergeur si le correctif y est.
- La progression des envois doit être désactivée
  (`session.upload_progress.enabled = 0`) : ça ferme CVE-2026-115560 en
  attendant que PHP la corrige, et le framework ne s’en sert pas. Avec
  `mod_php`, `www/.htaccess` la désactive. Avec PHP-FPM, règle-la dans
  `php.ini` ou dans un `.user.ini` dans `www`. Elle ne peut pas se régler
  pendant l’exécution. Le diagnostic avertit tant qu’elle est activée
  (`upload progress`).

### Serveur web

La route est lue à partir du chemin entier de l’adresse (`REQUEST_URI`) :
le serveur web envoie chaque chemin vers `www/index.php`, son seul point
d’entrée.

**Quel profil répond.** `conf/doorways.lst` associe chaque requête à un
profil, par son hôte et le début de son chemin :

- une ligne qui nomme l’hôte l’emporte sur une ligne pour n’importe quel
  hôte, puis vient le `basePath` le plus long ;
- `/api` prend `/api` et `/api/...`, jamais `/apix` ;
- le `basePath` est retiré avant le routage, et remis devant les liens et
  les redirections que construit le framework ;
- sans la carte, ou quand aucune ligne ne correspond, c’est `default` qui
  répond.

`php nyuRun setting entryPts` liste et change les lignes ; l’installation
écrit la première. Une carte qui ne peut pas être lue répond 500 plutôt
qu’un autre profil.

**Plusieurs hôtes.** Pour servir plusieurs hôtes depuis un seul projet, le
serveur web les envoie tous vers le même `www` : un hôte virtuel avec
chaque nom en `ServerAlias`, ou une ligne `server_name` qui les liste.
L’hôte est lu dans l’en-tête `Host` : un proxy inverse doit donc le
transmettre (`ProxyPreserveHost On`, ou `proxy_set_header Host $host`). Un
client choisit son `Host` : ne donne donc une ligne d’hôte qu’à un profil
qui vérifie l’accès, ou à un profil ouvert exprès (`isPublic`) ;
`install checkAll` signale les autres (`doorways`).

Apache, avec `www` comme `DocumentRoot` :

```apache
<Directory /var/www/project/www>
    AllowOverride None
    Require all granted
    FallbackResource /index.php
</Directory>
```

Avec `AllowOverride None`, comme ci-dessus, les fichiers `.htaccess` ne
sont pas lus : le bloc `<Directory>` et `FallbackResource` font leur
travail.

nginx, avec PHP-FPM :

```nginx
root /var/www/project/www;

location / {
    try_files $uri /index.php$is_args$args;
}

location = /index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

Ne garde aucun autre fichier `.php` dans `www` : Apache l’exécuterait, et la
configuration nginx ci-dessus enverrait sa source comme un fichier.

## 3. Proxy inverse

Sans proxy, laisse `PRX_LIST` vide : l’adresse d’un client est celle de sa
connexion, et aucun en-tête n’est lu.

Derrière un proxy, un répartiteur de charge ou un CDN, trois clés de
`conf/system.ini` disent au framework qui croire :

```ini
; Addresses or CIDR ranges of your proxies
PRX_LIST = "10.0.0.5, 192.168.0.0/24, fd00::/8"
; Header in which they give the address of the client
PRX_HEAD = X-Forwarded-For
; Header in which they give the scheme the client used
PRX_SCHM = X-Forwarded-Proto
```

Seule une requête dont la connexion vient d’une adresse de `PRX_LIST` voit
ces en-têtes lus. L’en-tête de l’adresse est lu de droite à gauche, et la
première adresse qui n’est pas l’un de tes proxys est le client.

Sur le proxy lui-même, avec nginx :

```nginx
location / {
    proxy_pass http://10.0.0.20:8080;
    proxy_set_header X-Forwarded-For   $remote_addr;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

Avec Apache (`mod_proxy` ajoute `X-Forwarded-For` tout seul) :

```apache
ProxyPass        / http://10.0.0.20:8080/
ProxyPassReverse / http://10.0.0.20:8080/
RequestHeader set X-Forwarded-Proto "https"
```

Les règles :

- Ne liste que des proxys que tu gères. Jamais toute une plage d’un
  fournisseur que tu ne contrôles pas, et jamais `0.0.0.0/0` : n’importe
  quel client pourrait alors se donner l’adresse qu’il veut. Le diagnostic
  échoue dessus.
- Le proxy doit écrire l’en-tête lui-même, ou y ajouter. Un proxy qui
  transmet tel quel l’en-tête du client laisse le client choisir son
  adresse.
- Le serveur PHP ne doit pas pouvoir être atteint en contournant le proxy :
  attache-le à l’interface privée, ou ferme-le au pare-feu. Sinon, lister le
  proxy ne protège rien.
- Un CDN publie ses plages, et elles changent. La liste demande de
  l’entretien.

L’adresse du client compte : la limite sur les mauvais identifiants
(section 5) et le journal des accès s’en servent. Un mauvais `PRX_LIST`
fait de tous les clients une seule adresse, ou laisse un client choisir la
sienne.

Ce que le framework ne comprend pas :

- Une adresse écrite avec son port (`203.0.113.7:51234`), ou l’en-tête
  `Forwarded` de la RFC 7239. L’adresse revient alors à celle de la
  connexion. Configure le proxy pour qu’il envoie une adresse simple dans
  `X-Forwarded-For`.
- `X-Forwarded-Host`. Le nom du site vient de la configuration du serveur
  web lui-même : règle donc le nom du serveur PHP sur le nom public.

## 4. Certificats clients

Le porteur `clntCert` ne fait aucun TLS lui-même. Le serveur web vérifie le
certificat pendant la poignée de main et en donne le résultat dans des
variables du serveur ; le porteur les lit. Son bloc dans
`conf/access.json` nomme ces variables :

```json
"clntCert": { "checkVar": "SSL_CLIENT_VERIFY", "ownerVar": "SSL_CLIENT_S_DN_CN", "issueVar": "SSL_CLIENT_I_DN_CN", "issuerId": "" }
```

- `checkVar` doit contenir `SUCCESS` ;
- `ownerVar` donne le nom de l’utilisateur ;
- `issueVar` donne le nom de l’émetteur, comparé à `issuerId` quand il est
  réglé.

Une variable dont le nom commence par `HTTP_` est un en-tête de requête,
que n’importe qui peut envoyer : le porteur la refuse.

Apache :

```apache
SSLCACertificateFile /etc/apache2/ca/clients-ca.pem
SSLVerifyClient      require
SSLVerifyDepth       2
SSLOptions           +StdEnvVars
```

nginx, avec PHP-FPM. nginx donne le sujet entier : le nom commun en est
donc extrait :

```nginx
map $ssl_client_s_dn $client_cn { default ""; "~(^|,)CN=(?<cn>[^,]+)" $cn; }
map $ssl_client_i_dn $issuer_cn { default ""; "~(^|,)CN=(?<cn>[^,]+)" $cn; }

server {
    ssl_client_certificate /etc/nginx/ca/clients-ca.pem;
    ssl_verify_client      on;

    location ~ \.php$ {
        fastcgi_param SSL_CLIENT_VERIFY  $ssl_client_verify;
        fastcgi_param SSL_CLIENT_S_DN_CN $client_cn;
        fastcgi_param SSL_CLIENT_I_DN_CN $issuer_cn;
        # ... the usual fastcgi settings
    }
}
```

Une vérification à la main, avec et sans certificat :

```bash
curl --cert client.pem --key client.key https://your-site.example/your/route
```

```bash
curl https://your-site.example/your/route
```

La seconde doit être refusée : par le serveur web quand il exige un
certificat, ou par le framework avec un 401 `No verified client
certificate`.

Les limites :

- Un TLS terminé par un proxy séparé n’est pas pris en charge en 1.0. Le
  certificat n’arriverait à PHP que sous forme d’en-têtes de requête, que
  le porteur refuse.
- L’émetteur n’est comparé que par son nom commun. La confiance est décidée
  par le fichier d’autorités donné au serveur web, pas par ce nom.
- La révocation (CRL, OCSP) est au serveur web de la vérifier.
- Un certificat est un facteur à lui seul : un utilisateur avec un secret à
  deux facteurs est admis par son seul certificat.

## 5. Limite par adresse et comptes en attente

Les mauvais identifiants sont comptés par adresse de client. Le bloc
`tryLimit` la règle :

```json
"tryLimit": { "maxTries": 10, "holdSecs": 600, "dataPath": "temp/limits" }
```

Après `maxTries` mauvais identifiants en `holdSecs` secondes, l’adresse
reçoit un 429 avec `Retry-After` pendant `holdSecs` secondes, quoi qu’elle
envoie. Une adresse IPv6 est comptée avec tout son réseau /64. `maxTries` à
0 désactive la limite, ce que signale le diagnostic.

```bash
php nyuRun cleanup fullList
```

```bash
php nyuRun cleanup freeAddr 203.0.113.7
```

La première liste les adresses comptées et en attente, la seconde en
libère une.

À savoir :

- Si les compteurs ne peuvent pas être écrits, rien n’est limité : l’erreur
  est journalisée sur `access`, et les requêtes reçoivent leur réponse comme
  sans limite. Garde le dossier accessible en écriture.
- Comptés : un mauvais mot de passe, une mauvaise clé d’API, un mauvais
  code à deux facteurs ou d’activation, un token falsifié, et un bon mot de
  passe sur un compte qui n’est pas approuvé.
- Pas comptés : un token qui a expiré, un id de session inconnu ou expiré,
  une requête sans identifiants.
- Les bases qui vérifient un mot de passe mettent aussi un compte en
  attente après ses propres `maxTries` échecs : le bloc `nativeDb` (5 par
  défaut), et les blocs `userFile` et `htpasswd`. Quelqu’un qui connaît un
  nom d’utilisateur pourrait alors garder ce compte en attente en échouant
  exprès. Les marques de connexion empêchent que ça bloque le titulaire :
  - une fois qu’un client s’est connecté, il reçoit une marque (l’en-tête
    `X-Lgmark`, ou le cookie `lgmark`), signée avec le `markSecr` du bloc
    `tryLimit` et l’empreinte du mot de passe de l’utilisateur ;
  - un client qui envoie une marque valide n’est pas arrêté par la mise en
    attente : ses propres échecs sont comptés sur sa marque, donc les
    essais envoyés d’ailleurs ne peuvent pas le tenir à l’écart ;
  - une marque dure `markDays` jours (90), et prend fin à tout changement du
    mot de passe, ou de la clé.

  Sans `markSecr`, il n’y a pas de marques, et la mise en attente arrête
  aussi le titulaire : le diagnostic avertit (`login marks`). Un client qui
  ne s’est jamais connecté, ou qui a perdu sa marque, attend toujours la fin
  de la mise en attente.
- Plusieurs tentatives envoyées exactement au même moment peuvent dépasser
  de quelques-unes le décompte d’un compte. La limite par adresse s’applique
  toujours.

## 6. Fichiers de mots de passe

La vérification `htpasswd` lit un fichier htpasswd.

- bcrypt, et les crypt SHA-256 ou SHA-512, sont acceptés.
- apr1 (le MD5 d’Apache) est accepté aussi, parce que c’est ce qu’écrit
  l’outil d’Apache par défaut. Il est faible selon les critères
  d’aujourd’hui : écris les nouvelles lignes en bcrypt
  (`php nyuRun setting mkSecret htpasswd <user>` le fait).
- `{SHA}`, le crypt DES et le crypt MD5 sont refusés, sauf si le bloc règle
  `"weakHash": true`, ce que signale le diagnostic.
- Le temps d’un refus ne dit si un nom existe ou non que quand les
  empreintes du fichier sont en bcrypt. Avec d’autres formats, une mesure
  attentive pourrait le dire.

## 7. Codes à deux facteurs

Un code est accepté une fois : son pas de 30 secondes et ceux d’avant sont
fermés pour cet utilisateur.

Les secrets sont gardés chiffrés quand le bloc de la base donne une clé :

```json
"nativeDb": { ..., "tfaCrypt": "env:NYU_TFA_KEY" }
```

```bash
php nyuRun setting mkSecret tfaCrypt --useBlock=nativeDb
```

- Sans une clé de 32 caractères, les secrets sont gardés lisibles, avec un
  avertissement sur `access` chaque fois qu’un secret est créé.
- Garde une copie de la clé. Si elle est perdue ou changée, chaque secret
  chiffré devient illisible et ces utilisateurs sont refusés. Chacun a
  alors besoin de `php nyuRun account setTwoFa <user> --removeIt` et d’un
  nouveau secret.
- Un secret gardé lisible avant qu’une clé ne soit réglée continue de
  marcher, et n’est chiffré qu’à la création d’un nouveau.
- La clé ne se renouvelle pas sur place.
- Avec le fichier des utilisateurs (`userFile`), le dernier pas n’est pas
  retenu, parce que les requêtes ne font que lire ce fichier : un code peut
  resservir pendant environ 90 secondes.
- Un token d’API personnel est un facteur à lui seul, comme un certificat :
  on ne lui demande pas de code.

## 8. Tokens

- Un token (JWT) ne peut pas être repris. Il est accepté jusqu’à ce qu’il
  expire, sauf si le profil nomme une base d’utilisateurs
  (`"authWith": "nativeDb"` ou `"userFile"`) : l’utilisateur est alors
  confirmé à chaque requête, et un blocage ou un changement de rôle
  s’applique tout de suite.
- Garde `lifeSecs` court là où il n’y a pas de base d’utilisateurs.
- Remplacer le secret (`php nyuRun setting mkSecret jwtToken`) met fin à
  tous les tokens d’un coup.
- Un serveur de ressources (`dataServ`) vérifie les tokens avec une copie
  de la clé publique du serveur d’authentification, dans `conf/keys`. Rien
  ne suit l’original : quand le serveur d’authentification crée de
  nouvelles clés, copie de nouveau la nouvelle clé publique, avec
  `install complete --projKind=dataServ` ou à la main. D’ici là, les tokens
  qu’il signe avec sa nouvelle clé y sont refusés. En attendant, la clé
  précédente du serveur d’authentification,
  `conf/keys/<block>.old.pub`, continue de vérifier les tokens qu’elle a
  signés avant : copie-la aussi et nomme-la `prevFile` dans le bloc des
  tokens du serveur de ressources, avec son `retireAt` (l’heure Unix où elle
  a été retirée), pour que les tokens déjà émis passent jusqu’à ce qu’ils
  expirent.
- Une fois `issuerId` et `audience` réglés, un token doit les nommer
  (`iss`, `aud`), sinon il est refusé. Règle les deux sur un serveur
  d’authentification et sur chaque projet qui accepte ses tokens, pour
  qu’un token émis pour un projet ne puisse pas servir sur un autre.
- Un client ne reçoit que `Invalid token` ou `Expired token`. La raison du
  refus d’un token est dans le journal `access`, en mode `FULL`.

## 9. Sessions PHP

```json
"phpSessn": { "cookName": "nyuSessn", "lifeSecs": 3600, "fullSecs": 43200, "sameSite": "strict", "secureOn": true, "dataPath": "" }
```

- `secureOn` à true n’envoie le cookie que par HTTPS. Garde-le à true hors
  d’une machine locale ; derrière un proxy qui termine TLS, règle
  `PRX_SCHM` (section 3).
- `sameSite` est la seule défense contre une requête falsifiée depuis un
  autre site. `strict` est la valeur sûre. Avec `lax`, ne change jamais
  rien sur un GET.
- `lifeSecs` met fin à une session laissée inutilisée ; `fullSecs` y met
  fin quel que soit son usage.
- `dataPath` vide garde les sessions dans le dossier propre à PHP, que
  d’autres applications PHP du même serveur peuvent partager. Sur un
  serveur partagé, donne-lui un dossier du projet, comme `temp/sessns`.
- Un id de session inconnu ou expiré n’est pas compté contre une adresse.

Des identifiants écrits dans une adresse (`https://name:password@host/...`)
sont changés en en-tête par le client et n’arrivent jamais dans les
journaux du serveur, mais ils restent dans l’historique et les scripts du
client lui-même. Le framework ne peut ni le voir ni l’empêcher.

## 10. Les autres sites qui appellent

Un profil décide quelles pages d’autres sites peuvent lire ses réponses,
dans son bloc `response` :

```json
"corsList": ["https://app.example.org"]
```

Avec une liste, seule une origine listée est nommée dans
`Access-Control-Allow-Origin`. Sans liste, les en-têtes du profil sont
envoyés tels qu’ils sont écrits, et plusieurs profils livrés écrivent `*`.

`*` est acceptable pour une API dont les clients envoient une clé ou un
token dans un en-tête : la page d’un autre site ne le détient pas. Ça ne
l’est pas avec un cookie de session, que le navigateur envoie tout seul. Le
diagnostic avertit dans ce cas.

## 11. Taille des requêtes

```ini
REQ_SIZE = 8192
REQ_PART = 200
```

- `REQ_SIZE` est le plus gros corps que lit le framework, en Ko. Un corps
  plus gros reçoit un 413, et n’est pas gardé en mémoire.
- `REQ_PART` borne les parties d’un corps multipart envoyé avec `PUT` ou
  `PATCH`.
- PHP analyse lui-même les formulaires `POST` et les envois de fichiers,
  avant le framework : ceux-là sont bornés par `php.ini`
  (`post_max_size`, `upload_max_filesize`, `max_file_uploads`,
  `max_input_vars`).
- Règle aussi la limite propre au serveur web (`client_max_body_size` dans
  nginx, `LimitRequestBody` dans Apache) : elle refuse un gros corps avant
  que PHP ne démarre.

## 12. Erreurs et journaux

- `ERR_SHOW` montre au client le détail d’une erreur. Il est ignoré en
  PROD ; laisse-le désactivé en PREV aussi.
- `LOG_MODE` : `MINI` écrit les erreurs, les avertissements et les notices ;
  `BASE` ajoute les infos ; `FULL` ajoute le debug et le contexte d’une
  erreur (sa pile, sans les arguments des appels). `BASE` suffit en
  production.
- Le dossier des journaux (`LOG_PATH`, `temp/logs` par défaut) doit être
  accessible en écriture par PHP, et pas servi.
- Le framework ne journalise jamais un mot de passe, un code ou un token.
  Garde quand même `zend.exception_ignore_args = On` dans `php.ini` en
  production : c’est la propre protection de PHP contre ce que journalise
  d’autre code.

## 13. Notes sur le routage

- Une route déclarée qui limite ses méthodes (`mthdList`, ou une cible par
  méthode) ne limite que cette route. L’action vers laquelle elle pointe
  peut toujours être atteinte avec n’importe quelle méthode par ses autres
  chemins : `/app/action`, une route de son run, ou un autre alias. Les
  limites de méthodes ne sont pas un contrôle d’accès : une action à qui la
  méthode importe la vérifie elle-même.
- Un rôle donné à un chemin déclaré dans `minRoles`
  (`"/panel": "managers"`) suit l’action vers laquelle pointe ce chemin,
  pour la cible de chaque méthode et l’action par défaut comprise, quel que
  soit le chemin qui l’atteint. Un `/path` qu’aucune route ne déclare,
  écrit exactement comme la route, fait échouer chaque requête du profil
  avec un 500, et `install checkAll` échoue dessus (`access route`).
- Le nom d’une application ne contient que des lettres et des chiffres.
- Une clé qu’aucun code ne lit dans un fichier de routes est signalée dans
  le journal `runner` à chaque requête.
- Un site peut répondre à une erreur par sa propre page :
  `errors/<status>.html` dans le dossier de ses pages (`fileRoot`), avec
  `%code%` et `%error%` remplis. Elle sert pour une page ou un fichier
  statique, et pour un chemin auquel aucune route ne répond dans un profil
  qui trouve ses routes `manually`. Une requête pour une action garde le
  format du profil ; un chemin inconnu dans un profil `autopath` aussi.

## 14. Les applications qui appellent pour leurs utilisateurs

Une autre application (une application mobile, le portail d’un partenaire) peut appeler ce
projet pour l’un de ses propres utilisateurs. Elle envoie deux choses : sa
clé, dans l’en-tête du bloc `appsKeys`, et le token de l’utilisateur, signé
avec sa propre clé privée. Un profil lit les clés quand son bloc `security`
nomme ce bloc : `"appsConf": "appsKeys"`.

```json
"appsKeys": { "headName": "X-App-Key", "appsList": { "portal": { "keyDigst": "…", "routeSet": ["contacts/*"], "publFile": "conf/keys/portal.pub", "roleTops": "standard", "madeDate": "…" } } }
```

- Les clés sont créées, reçoivent leurs routes et sont révoquées en ligne
  de commande seulement
  (`php nyuRun appkeys makeKeys|fullList|setRoute|revokeIt`). Une clé est
  affichée une fois ; le fichier garde son empreinte SHA-256. Une clé
  révoquée est refusée dès la requête suivante.
- Copie la clé publique de l’application dans `conf/keys` et donne son
  chemin. Ne copie jamais sa clé privée : la détenir permettrait à ce
  projet de falsifier les tokens de cette application.
- Un token de l’application n’est valide qu’avec sa clé. Envoyé sans elle,
  il va au porteur propre au profil, où il n’est pas valide.
- L’utilisateur est pris dans le token, tel que l’application l’a émis : ce
  projet ne le cherche pas dans sa propre base, donc un blocage dans
  l’application ne s’applique qu’à l’expiration de son token. Garde court le
  `lifeSecs` de l’application.
- Le rôle de cet utilisateur n’est jamais au-dessus de `roleTops`
  (`standard` par défaut), et le rôle que demande une route (`minRoles`,
  `needsActionRle()`) s’applique toujours.
- Une application n’atteint que son `routeSet`, écrit comme `openList` ;
  toute autre route est un 404, comme une route qui n’existe pas. Une route
  ouverte à tous ignore la clé.
- Une mauvaise clé et un token falsifié comptent contre l’adresse. Chaque
  écriture est une notice dans le journal `access`, qui nomme
  l’application, l’utilisateur et la route ; une lecture est une info.
  L’enregistrement lui-même revient à l’action de le journaliser.
- Chaque application signe les tokens de ses utilisateurs avec sa propre
  clé privée, et chaque projet qu’elle appelle détient la clé publique qui
  va avec. Une fois que le projet règle `audience` dans le bloc `appsKeys`,
  le token d’une application doit la nommer, et nommer l’application comme
  son émetteur.

## 15. E-mails d’accès

La base d’utilisateurs native envoie ses codes par e-mail quand son bloc
nomme un compte de `conf/mailer.json`. Sans `mailAcnt`, rien n’est envoyé :
les codes sont donnés dans les réponses, pour être remis en main propre,
comme avant.

```json
"nativeDb": { ..., "mailAcnt": "nyuRuns", "signupOn": false, "codeMins": 15, "linkTmpl": "https://app.example.ch/access?do=%mailKind%&user=%userName%&code=%sentCode%" }
```

`php nyuRun install complete` demande tout cela une fois la base
d’utilisateurs native choisie, et écrit les réponses :

| Question | Écrit |
|---|---|
| Envoyer les e-mails d’accès ? (non par défaut) | non : `mailAcnt` reste tel quel, rien d’autre n’est demandé |
| Type du compte d’e-mail | `sendWith` : `selfSmtp` (proposé), `phpMailr` (proposé seulement quand PHPMailer est installé) ou `emlFiles` |
| Hôte du serveur d’e-mail, port (465 ou 587), utilisateur, mot de passe | `selfSmtp` et `phpMailr` seulement. Le mot de passe va dans `.env` en tant que `NYU_MAIL_PASS` ; le compte contient `"passWord": "env:NYU_MAIL_PASS"`, jamais le mot de passe lui-même |
| Adresse de l’expéditeur, nom de l’expéditeur | `fromAddr`, `fromName` ; un compte `emlFiles` garde ses e-mails dans `temp/mails` |
| Nom du compte d’e-mail (`nyuRuns` proposé) | le compte dans `conf/mailer.json`, nommé dans `nativeDb.mailAcnt` |
| Lien du front-end dans les e-mails | `nativeDb.linkTmpl` |
| Ouvrir les inscriptions ? (non par défaut) | `nativeDb.signupOn` |
| Envoyer un e-mail de test à | l’envoie tout de suite, comme le fait `install testMail` |

- Un `conf/mailer.json` créé par l’installation ne contient que ce compte ;
  dans un fichier qui existe, le compte est écrit et les autres restent tels
  quels. `install makeConf` crée le fichier à partir de
  `conf/mailer.json.dist`, avec ses trois comptes d’exemple, à remplir à la
  main.
- `--sendWith=<selfSmtp|phpMailr|emlFiles|none>` et `--signupOn` répondent
  d’avance. Avec `--noPrompt`, un compte qui envoie doit déjà être dans
  `conf/mailer.json` : son serveur ne peut pas être demandé.
- **Avant la mise en ligne**, lance `php nyuRun install testMail <address>` :
  elle envoie un court e-mail de test par le compte que nomme
  `nativeDb.mailAcnt`, et dit s’il est parti ou pourquoi il ne l’est pas
  (code de sortie 1). Elle ne change rien, et est journalisée sur `runner`
  comme tout autre e-mail. Un compte `emlFiles` écrit l’e-mail dans un
  fichier, et le dit.
- `codeMins` est le nombre de minutes que dure un code envoyé par e-mail (15
  par défaut). Un code remis par un gestionnaire dure 300 minutes ;
  `account makeUser` et `account resetPwd` prennent `--noExpiry` pour un
  code sans fin, en ligne de commande seulement.
- `linkTmpl` est la page du front-end vers laquelle pointe l’e-mail.
  `%mailKind%` lui dit quel formulaire montrer : `activate` et `register`
  envoient vers `auth/active`, `password` vers `auth/recover`. Sans lui,
  l’e-mail ne porte que le code. Le code est lié à l’utilisateur, pas à
  l’appareil : il peut être tapé sur un autre.
- `signupOn: true` ouvre `auth/signup`. Une inscription confirme son
  adresse avec le code, puis attend en `inReview` un gestionnaire
  (`POST users/<user>/approve`, `php nyuRun account approves`) ; la refuser,
  c’est la supprimer. Les inscriptions jamais confirmées sont supprimées
  une fois leur code expiré.
- Le bloc `tryLimit` borne les inscriptions : `maxQueue` en attente à la
  fois (256), au-delà duquel `auth/signup` répond 503 jusqu’à ce que
  certaines soient validées ou supprimées ; `maxPerIp` inscriptions d’un
  même client en `holdSecs` (16), comptées à part de ses mauvais
  identifiants (une adresse en attente pour ceux-là est refusée aussi) ; et
  `alertsAt`, les décomptes auxquels chaque gestionnaire approuvé qui a une
  adresse est prévenu par e-mail (`waitlist`) et le journal `access` le
  note (128, 200, 256).
- `auth/signup` et `auth/forgot` répondent 202, quels que soient le nom ou
  l’adresse. Chaque réinitialisation demandée compte contre l’adresse du
  client, comme un mauvais mot de passe (`maxTries`). Sous PHP-FPM, la
  réponse se termine avant que l’e-mail ne soit envoyé : son temps ne dit
  donc rien non plus ; sous une autre API serveur, le client attend
  l’e-mail, et le temps peut dire s’il est parti.
- Une réinitialisation demandée par e-mail garde le mot de passe jusqu’à ce
  que le code serve : personne ne peut donc bloquer un utilisateur en la
  demandant. Une réinitialisation par un gestionnaire l’annule tout de
  suite.
- Une adresse appartient à un seul compte, comparée en minuscules. Le
  fichier des utilisateurs (`userFile`) contient aussi des adresses, mais
  ne peut pas envoyer de codes : il est seulement lu.
- Les e-mails sont `conf/mails/<language>/<kind>.txt` (sa première ligne
  est le sujet) et `.html`, en anglais (`en`) et en français (`fr`). Un
  e-mail est écrit dans la langue de l’utilisateur à qui il va
  (`userLocale`), sinon dans le `DEF_LANG` de `system.ini` (`en` quand il
  n’est pas réglé) ; `fr-ch` se replie sur `fr`, puis sur la langue par
  défaut. Une autre langue est un dossier à côté d’eux. Une application les
  remplace par les siens dans `runs/Auth/mails/<language>/`.
- Une table créée par la 0.9 a d’abord besoin de
  `conf/schema/migrate-users-1.0.<type>.sql`, puis de
  `migrate-devices-1.0.<type>.sql` quand elle a des appareils (`<type>` est
  `mysql_db`, `postgres` ou `sqlitedb`). Ils renomment les colonnes et
  donnent aux rôles et aux statuts leurs codes de la 1.0. Sur MySQL 8 et
  MariaDB, les parties 2 et 4 du fichier des utilisateurs (les
  vérifications des rôles et des statuts) se lancent à la main.

## 16. Plusieurs serveurs

Un projet peut tourner sur plusieurs serveurs derrière un répartiteur de
charge. Il ne garde presque rien entre deux requêtes, mais ce qu’il garde
doit être prévu :

- **Les clés des tokens.** Chaque serveur doit signer et vérifier les
  tokens avec la même paire. Crée-la une fois
  (`php nyuRun setting mkSecret jwtToken`), puis copie `conf/keys` et les
  secrets de `.env` sur chaque serveur **avant** d’y lancer
  `install complete` : l’installation garde ce qui existe, mais sur un
  serveur sans clés elle crée sa propre paire, et les tokens de ce serveur
  sont alors refusés par les autres. Après un changement de clé, copie la
  nouvelle paire, et la clé publique précédente
  (`conf/keys/<block>.old.pub`), sur chaque serveur.
- **Les autres secrets.** C’est pareil pour tout `.env` : la clé
  `tfaCrypt` (sinon les secrets à deux facteurs créés sur un serveur ne se
  lisent pas sur un autre), `markSecr` (sinon une marque de connexion d’un
  serveur n’est pas valide sur le suivant), et les clés d’API.
- **Les sessions** (`phpSessn`) vivent dans des fichiers sur chaque
  serveur. Donne à `dataPath` un dossier que tous les serveurs partagent,
  ou fais en sorte que le répartiteur renvoie chaque utilisateur vers le
  même serveur.
- **Les décomptes de mauvaises tentatives** (`tryLimit.dataPath`) sont des
  fichiers sur chaque serveur : chacun compte donc de son côté, et une
  adresse est mise en attente par le serveur qui a vu les tentatives. Un
  dossier partagé rend la limite globale.
- **La base de données** (utilisateurs, appareils, comptes en attente) est
  partagée par tous.
- **L’horloge.** Chaque date de `users` et `devices` est écrite par PHP,
  dans son fuseau horaire (`date.timezone` de php.ini), quel que soit le
  fuseau de la base de données. Règle le même fuseau sur chaque serveur, de
  préférence UTC : deux serveurs à une heure d’écart ne seraient pas
  d’accord sur qui est en ligne, sur un compte en attente, ni sur la fin
  d’un code.
- **Le cache de configuration** (`temp/cache`) est créé par chaque
  serveur. Après un changement de `conf/`, lance
  `php nyuRun setting clrCache` sur chacun d’eux.
- **Les journaux** sont écrits par chaque serveur, dans son propre
  `temp/logs` : les rassembler revient à l’hébergeur.
