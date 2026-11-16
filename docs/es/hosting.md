# Alojar nyuRuns

Lo que tiene que configurar quien pone un proyecto nyuRuns en un servidor, y
los límites que conviene conocer antes de que lleguen usuarios reales. Este
documento acompaña la revisión de seguridad de la versión 1.0.

## 1. Propósito y reparto de tareas

El framework se encarga de una petición desde el momento en que PHP la
recibe: el enrutamiento, el acceso, las entradas, la respuesta. Todo lo que
viene antes es cosa del alojamiento, y el framework nunca lo toca:

- la configuración del servidor web (host virtual, TLS, certificados de cliente);
- el cortafuegos y lo que puede llegar al servidor PHP;
- `php.ini`;
- el proxy inverso o la CDN, cuando los hay.

Los ajustes propios del framework están en `conf/` y `.env`, y se leen y se
escriben con `php nyuRun setting`.

## 2. Puesta en marcha

```bash
php nyuRun install complete
```

Este comando comprueba el servidor, crea los archivos locales a partir de
sus ejemplos (`.env`, `conf/access.json`, `conf/database.json`), genera los
secretos que siguen vacíos, crea los usuarios y termina con el diagnóstico.
Pregunta qué cuentas básicas crear, la versión simple (`userDemo`,
`bossDemo`) o la completa, una por rol (`userDemo`, `powrDemo`,
`deptDemo`, `bossDemo`), y luego el nombre de cada una, con esos como
propuesta; `--noPrompt` toma la versión simple con esos nombres. Si lo
vuelves a lanzar, no sustituye nada de lo que ya existe.

Con la base de usuarios nativa (`nativeDb`), hace las preguntas de los
correos electrónicos de acceso justo después de la de la base: si se envían
(no, por defecto), el tipo de la cuenta de correo, la propia cuenta, el
enlace del front-end, si las altas están abiertas, y una dirección a la que
enviar un correo de prueba (§15). `--sendWith=<type>` (`none` para ningún
correo) y `--signupOn` las responden de antemano; `--noPrompt` solo toma
`none` y sin altas. A ninguna otra base se le hacen estas preguntas.

Su primera pregunta es qué es el proyecto; `--projKind=<kind>` la responde
de antemano, y `--noPrompt` toma `allInOne`. Ningún ajuste guarda la
respuesta: la instalación escribe los bloques de `conf/access.json` que
necesita el perfil, e `install checkAll` vuelve a leer el perfil a partir
de ellos (su fila `profile`).

| Perfil | Qué es | Lo que la instalación pregunta además |
|---|---|---|
| `allInOne` | los usuarios, el inicio de sesión y los datos en un solo proyecto | nada: la base de usuarios, la base de datos, las cuentas, las claves de los tokens |
| `authServ` | un servidor de autenticación: inicia la sesión de los usuarios y emite tokens para otros proyectos | el `issuerId` de sus tokens (con el nombre del host como propuesta) y su `audience`, los proyectos que los aceptarán (separados por comas); los tokens se firman con RS256, el par de claves se crea como lo hace `setting mkSecret jwtToken`, y se publica en `/.well-known/jwks.json` |
| `dataServ` | un servidor de recursos: sin usuarios propios, acepta los tokens de un servidor de autenticación | no la base de usuarios, y no se crea ningún usuario; el archivo de la clave pública del servidor de autenticación (copiado en `conf/keys`), su `issuerId`, y la `audience` que nombra este proyecto. El bloque de los tokens solo guarda esa clave pública (`"external": true`), y el inicio de sesión no nombra ninguna verificación de credenciales: responde 501 |
| `openSite` | un sitio en el que no hay nada en lo que iniciar sesión: solo archivos estáticos y páginas | ni la base de usuarios, ni las cuentas, ni las claves de los tokens, ni ningún otro secreto; el inicio de sesión no nombra ninguna verificación de credenciales. El mapa de los perfiles servidos propone `/=statics`, y cada uno de sus perfiles se abre (`"required": false`, `"isPublic": true`), salvo si puede llegar a una acción: entonces la instalación se detiene, y no se abre ni se escribe nada |

Un servidor de autenticación rechaza la base `none`; un servidor de
recursos y un sitio abierto rechazan `--useStore`.

Un sitio abierto nunca hace público un run por accidente. `statics` no
sirve ningún run (`"runMount": { "*": false }`: `*` designa cada run que no
tiene una línea propia), e `install checkAll` hace fallar su fila `profile`
cuando un perfil abierto del mapa puede llegar a una acción. Un run que debe
ser público se abre a propósito, en un perfil que verifica el acceso, con
`openList`. Dar marcha atrás vuelve a cerrar las cosas: cuando la
instalación se lanza de nuevo y le da al proyecto una base de usuarios, cada
perfil del mapa abierto marcado `isPublic` se cierra (`"required": true`,
`isPublic` eliminado), y se nombra en la salida. Un perfil cerrado así no
tiene portador: responde 500 hasta que su propietario le dé uno, o lo vuelva
a abrir a propósito. Las reglas de los archivos no cambian con el perfil: un
archivo nunca sale de su carpeta, solo se sirven las extensiones de
`Response::TYPE_LIST`, y `nosniff` se envía siempre.

Sin `conf/access.json`, cada perfil responde 500 `Access misconfigured`: no
pasa nada. Solo un perfil abierto a propósito (`"required": false` con
`"isPublic": true`) se sigue sirviendo.

```bash
php nyuRun install checkAll
```

Cada fila es `fine`, `warn` (merece un vistazo) o `fail` (las peticiones no
se pueden servir, o quedan expuestas). El comando termina con un código de
error cuando una fila está en `fail`, así que un script de despliegue puede
detenerse ahí.

Antes de que lleguen usuarios reales:

- Pon `ENV_NAME = PROD` en `conf/system.ini`. En PROD no se le muestra nada
  de un error a un cliente, diga lo que diga `ERR_SHOW`.
- Deja `.env` legible solo por su propietario (`chmod 600 .env`). Contiene
  todos los secretos; los archivos de configuración solo los nombran
  (`env:NAME`).
- Sirve la carpeta `www`, y nada más. El proyecto lleva un `.htaccess` en su
  raíz y en cada carpeta fuera de `www` que lo deniega todo, y uno en `www`
  que envía cada ruta a `index.php`. Apache solo los lee si `AllowOverride`
  se lo permite (`All`, o `FileInfo Options AuthConfig Limit`). nginx no los
  lee nunca: lo que protege el proyecto ahí es su configuración de más abajo.
- Quita de `www` lo que tu sitio no use.
- Usa como mínimo PHP 8.2, en una versión que corrija php2shell
  (CVE-2026-109618, CVE-2026-109619): 8.2.35, 8.3.36, 8.4.27, 8.5.12 o
  posterior. PHP 8.0 y 8.1 no tienen ninguna versión que lo corrija. Una
  distribución que adapta el parche conserva el número de su versión, así
  que el diagnóstico da un `warn` (`php patch`), no un `fail`: pregunta al
  alojamiento si el parche está incluido.
- El progreso de las subidas debe estar desactivado
  (`session.upload_progress.enabled = 0`): cierra CVE-2026-115560 mientras
  PHP no lo corrija, y el framework no lo usa. Con `mod_php`,
  `www/.htaccess` lo desactiva. Con PHP-FPM, configúralo en `php.ini` o en
  un `.user.ini` dentro de `www`. No se puede configurar en tiempo de
  ejecución. El diagnóstico avisa mientras esté activado (`upload progress`).

### Servidor web

La ruta se lee a partir de la ruta completa de la dirección
(`REQUEST_URI`): el servidor web envía cada ruta a `www/index.php`, su único
punto de entrada.

**Qué perfil responde.** `conf/doorways.lst` asocia cada petición a un
perfil, según su host y el principio de su ruta:

- una línea que nombra el host gana a una línea para cualquier host, y
  luego el `basePath` más largo;
- `/api` toma `/api` y `/api/...`, nunca `/apix`;
- el `basePath` se quita antes del enrutamiento, y se vuelve a poner
  delante de los enlaces y las redirecciones que construye el framework;
- sin el mapa, o cuando ninguna línea coincide, responde `default`.

`php nyuRun setting entryPts` lista y cambia las líneas; la instalación
escribe la primera. Un mapa que no se puede leer responde 500 en lugar de
otro perfil.

**Varios hosts.** Para servir varios hosts desde un solo proyecto, el
servidor web los envía todos al mismo `www`: un host virtual con cada nombre
como `ServerAlias`, o una línea `server_name` que los liste. El host se lee
de la cabecera `Host`, así que un proxy inverso tiene que transmitirla
(`ProxyPreserveHost On`, o `proxy_set_header Host $host`). Un cliente elige
su `Host`, así que da una línea de host solo a un perfil que verifique el
acceso, o a uno abierto a propósito (`isPublic`); `install checkAll` señala
los demás (`doorways`).

Apache, con `www` como `DocumentRoot`:

```apache
<Directory /var/www/project/www>
    AllowOverride None
    Require all granted
    FallbackResource /index.php
</Directory>
```

Con `AllowOverride None`, como arriba, los archivos `.htaccess` no se leen:
el bloque `<Directory>` y `FallbackResource` hacen su trabajo.

nginx, con PHP-FPM:

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

No dejes ningún otro archivo `.php` en `www`: Apache lo ejecutaría, y la
configuración de nginx de arriba enviaría su código fuente como un archivo.

## 3. Proxy inverso

Sin proxy, deja `PRX_LIST` vacío: la dirección de un cliente es la de su
conexión, y no se lee ninguna cabecera.

Detrás de un proxy, un balanceador de carga o una CDN, tres claves de
`conf/system.ini` le dicen al framework a quién creer:

```ini
; Addresses or CIDR ranges of your proxies
PRX_LIST = "10.0.0.5, 192.168.0.0/24, fd00::/8"
; Header in which they give the address of the client
PRX_HEAD = X-Forwarded-For
; Header in which they give the scheme the client used
PRX_SCHM = X-Forwarded-Proto
```

Solo se leen esas cabeceras en una petición cuya conexión viene de una
dirección de `PRX_LIST`. La cabecera de la dirección se lee de derecha a
izquierda, y la primera dirección que no es uno de tus proxies es el
cliente.

En el propio proxy, con nginx:

```nginx
location / {
    proxy_pass http://10.0.0.20:8080;
    proxy_set_header X-Forwarded-For   $remote_addr;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

Con Apache (`mod_proxy` añade `X-Forwarded-For` por sí solo):

```apache
ProxyPass        / http://10.0.0.20:8080/
ProxyPassReverse / http://10.0.0.20:8080/
RequestHeader set X-Forwarded-Proto "https"
```

Las reglas:

- Lista solo proxies que gestiones tú. Nunca todo un rango de un proveedor
  que no controlas, y nunca `0.0.0.0/0`: cualquier cliente podría darse la
  dirección que quisiera. El diagnóstico falla con eso.
- El proxy tiene que escribir la cabecera él mismo, o añadir a ella. Uno que
  deja pasar tal cual la cabecera del propio cliente le permite elegir su
  dirección.
- El servidor PHP no debe ser accesible esquivando el proxy: vincúlalo a la
  interfaz privada o ciérralo en el cortafuegos. Si no, listar el proxy no
  protege nada.
- Una CDN publica sus rangos, y cambian. La lista necesita mantenimiento.

La dirección del cliente importa: el límite de credenciales incorrectas
(sección 5) y el registro de accesos la usan. Un `PRX_LIST` incorrecto
convierte a todos los clientes en una sola dirección, o deja que un cliente
elija la suya.

Lo que el framework no entiende:

- Una dirección escrita con su puerto (`203.0.113.7:51234`), o la cabecera
  `Forwarded` del RFC 7239. La dirección vuelve entonces a ser la de la
  conexión. Configura el proxy para que envíe una dirección simple en
  `X-Forwarded-For`.
- `X-Forwarded-Host`. El nombre del sitio viene de la propia configuración
  del servidor web, así que pon como nombre del servidor PHP el público.

## 4. Certificados de cliente

El portador `clntCert` no hace TLS por sí mismo. El servidor web comprueba
el certificado durante el handshake y deja el resultado en variables del
servidor; el portador las lee. Su bloque en `conf/access.json` nombra esas
variables:

```json
"clntCert": { "checkVar": "SSL_CLIENT_VERIFY", "ownerVar": "SSL_CLIENT_S_DN_CN", "issueVar": "SSL_CLIENT_I_DN_CN", "issuerId": "" }
```

- `checkVar` debe contener `SUCCESS`;
- `ownerVar` da el nombre del usuario;
- `issueVar` da el nombre del emisor, comparado con `issuerId` cuando está
  configurado.

Una variable cuyo nombre empieza por `HTTP_` es una cabecera de petición,
que cualquiera puede enviar: el portador la rechaza.

Apache:

```apache
SSLCACertificateFile /etc/apache2/ca/clients-ca.pem
SSLVerifyClient      require
SSLVerifyDepth       2
SSLOptions           +StdEnvVars
```

nginx, con PHP-FPM. nginx da el sujeto completo, así que se extrae de él el
nombre común:

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

Una comprobación a mano, con y sin certificado:

```bash
curl --cert client.pem --key client.key https://your-site.example/your/route
```

```bash
curl https://your-site.example/your/route
```

La segunda debe ser rechazada: por el servidor web cuando exige un
certificado, o por el framework con un 401 `No verified client
certificate`.

Los límites:

- Un TLS terminado en un proxy aparte no está soportado en la 1.0. El
  certificado solo llegaría a PHP como cabeceras de petición, que el
  portador rechaza.
- El emisor solo se compara por su nombre común. La confianza la decide el
  archivo de autoridades que se le da al servidor web, no este nombre.
- La revocación (CRL, OCSP) le toca comprobarla al servidor web.
- Un certificado es un factor por sí solo: un usuario con un secreto de
  doble factor entra solo con su certificado.

## 5. Límite por dirección y cuentas en espera

Las credenciales incorrectas se cuentan por dirección de cliente. El bloque
`tryLimit` lo configura:

```json
"tryLimit": { "maxTries": 10, "holdSecs": 600, "dataPath": "temp/limits" }
```

Tras `maxTries` credenciales incorrectas en `holdSecs` segundos, la
dirección recibe un 429 con `Retry-After` durante `holdSecs` segundos, envíe
lo que envíe. Una dirección IPv6 se cuenta con toda su red /64. `maxTries` a
0 desactiva el límite, cosa que señala el diagnóstico.

```bash
php nyuRun cleanup fullList
```

```bash
php nyuRun cleanup freeAddr 203.0.113.7
```

El primero lista las direcciones contadas y en espera, el segundo libera
una.

A tener en cuenta:

- Si los contadores no se pueden escribir, no se limita nada: el error se
  registra en `access`, y las peticiones se responden como si no hubiera
  límite. Mantén la carpeta con permiso de escritura.
- Se cuentan: una contraseña, una clave de API, un código de doble factor o
  de activación incorrectos, un token falsificado, y una contraseña correcta
  en una cuenta que no está aprobada.
- No se cuentan: un token caducado, un id de sesión desconocido o caducado,
  una petición sin credenciales.
- Las bases que verifican una contraseña también ponen una cuenta en espera
  tras sus propios `maxTries` fallos: el bloque `nativeDb` (5 por defecto),
  y los bloques `userFile` y `htpasswd`. Alguien que conozca un nombre de
  usuario podría entonces mantener esa cuenta en espera fallando a
  propósito. Las marcas de inicio de sesión evitan que eso bloquee al
  titular:
  - una vez que un cliente ha iniciado sesión, recibe una marca (la cabecera
    `X-Lgmark`, o la cookie `lgmark`), firmada con el `markSecr` del bloque
    `tryLimit` y el hash de la contraseña del usuario;
  - a un cliente que envía una marca válida no lo detiene la espera: sus
    propios fallos se cuentan sobre su marca, así que los intentos enviados
    desde otro sitio no pueden dejarlo fuera;
  - una marca dura `markDays` días (90), y termina con cualquier cambio de la
    contraseña, o de la clave.

  Sin `markSecr` no hay marcas, y la espera también detiene al titular: el
  diagnóstico avisa (`login marks`). Un cliente que nunca ha iniciado
  sesión, o que ha perdido su marca, sigue esperando a que termine la
  espera.
- Varios intentos enviados exactamente en el mismo momento pueden superar en
  unos pocos el recuento de una cuenta. El límite por dirección se sigue
  aplicando.

## 6. Archivos de contraseñas

La verificación `htpasswd` lee un archivo htpasswd.

- Se aceptan bcrypt, y los crypt SHA-256 o SHA-512.
- apr1 (el MD5 de Apache) también se acepta, porque es lo que escribe la
  herramienta de Apache por defecto. Es débil según los criterios de hoy:
  escribe las líneas nuevas con bcrypt
  (`php nyuRun setting mkSecret htpasswd <user>` lo hace).
- `{SHA}`, el crypt DES y el crypt MD5 se rechazan salvo que el bloque
  configure `"weakHash": true`, cosa que señala el diagnóstico.
- El tiempo de un rechazo no revela si un nombre existe solo cuando los
  hashes del archivo son bcrypt. Con otros formatos, una medida cuidadosa
  podría revelarlo.

## 7. Códigos de doble factor

Un código se acepta una sola vez: su intervalo de 30 segundos y los
anteriores quedan cerrados para ese usuario.

Los secretos se guardan cifrados cuando el bloque de la base da una clave:

```json
"nativeDb": { ..., "tfaCrypt": "env:NYU_TFA_KEY" }
```

```bash
php nyuRun setting mkSecret tfaCrypt --useBlock=nativeDb
```

- Sin una clave de 32 caracteres, los secretos se guardan legibles, con un
  aviso en `access` cada vez que se crea uno.
- Guarda una copia de la clave. Si se pierde o se cambia, cada secreto
  cifrado se vuelve ilegible y esos usuarios son rechazados. Cada uno
  necesita entonces `php nyuRun account setTwoFa <user> --removeIt` y un
  secreto nuevo.
- Un secreto guardado legible antes de configurar una clave sigue
  funcionando, y no se cifra hasta que se crea uno nuevo.
- La clave no se rota sobre la marcha.
- Con el archivo de usuarios (`userFile`), el último intervalo no se
  recuerda, porque las peticiones solo leen ese archivo: un código se puede
  volver a usar durante unos 90 segundos.
- Un token de API personal es un factor por sí solo, como un certificado: no
  se le pide código.

## 8. Tokens

- Un token (JWT) no se puede retirar. Se acepta hasta que caduca, salvo que
  el perfil nombre una base de usuarios (`"authWith": "nativeDb"` o
  `"userFile"`): el usuario se confirma entonces en cada petición, y un
  bloqueo o un cambio de rol se aplica de inmediato.
- Mantén `lifeSecs` corto donde no haya base de usuarios.
- Sustituir el secreto (`php nyuRun setting mkSecret jwtToken`) pone fin a
  todos los tokens de golpe.
- Un servidor de recursos (`dataServ`) verifica los tokens con una copia de
  la clave pública del servidor de autenticación, en `conf/keys`. Nada sigue
  al original: cuando el servidor de autenticación crea claves nuevas,
  vuelve a copiar la nueva clave pública, con
  `install complete --projKind=dataServ` o a mano. Hasta entonces, los
  tokens que firma con su clave nueva se rechazan ahí. Mientras tanto, la
  clave anterior del servidor de autenticación,
  `conf/keys/<block>.old.pub`, sigue verificando los tokens que firmó antes:
  cópiala también y nómbrala `prevFile` en el bloque de los tokens del
  servidor de recursos, con su `retireAt` (la hora Unix en que se retiró),
  para que los tokens ya emitidos pasen hasta que caduquen.
- Una vez configurados `issuerId` y `audience`, un token tiene que
  nombrarlos (`iss`, `aud`) o se rechaza. Configura los dos en un servidor
  de autenticación y en cada proyecto que acepte sus tokens, para que un
  token emitido para un proyecto no se pueda usar en otro.
- A un cliente solo se le dice `Invalid token` o `Expired token`. El motivo
  por el que se rechazó un token está en el registro `access`, en modo
  `FULL`.

## 9. Sesiones PHP

```json
"phpSessn": { "cookName": "nyuSessn", "lifeSecs": 3600, "fullSecs": 43200, "sameSite": "strict", "secureOn": true, "dataPath": "" }
```

- `secureOn` a true envía la cookie solo por HTTPS. Mantenlo a true fuera de
  una máquina local; detrás de un proxy que termina TLS, configura
  `PRX_SCHM` (sección 3).
- `sameSite` es la única defensa contra una petición falsificada desde otro
  sitio. `strict` es el valor seguro. Con `lax`, no cambies nunca nada en un
  GET.
- `lifeSecs` pone fin a una sesión que no se usa; `fullSecs` le pone fin sea
  cual sea su uso.
- `dataPath` vacío guarda las sesiones en la carpeta propia de PHP, que otras
  aplicaciones PHP del mismo servidor pueden compartir. En un servidor
  compartido, dale una carpeta del proyecto, como `temp/sessns`.
- Un id de sesión desconocido o caducado no cuenta contra una dirección.

Las credenciales escritas en una dirección (`https://name:password@host/...`)
el cliente las convierte en una cabecera y nunca llegan a los registros del
servidor, pero se quedan en el historial y los scripts del propio cliente.
El framework no puede verlo ni impedirlo.

## 10. Otros sitios que llaman

Un perfil decide qué páginas de otros sitios pueden leer sus respuestas, en
su bloque `response`:

```json
"corsList": ["https://app.example.org"]
```

Con una lista, solo se nombra en `Access-Control-Allow-Origin` un origen de
la lista. Sin ella, las cabeceras del perfil se envían tal como están
escritas, y varios perfiles incluidos escriben `*`.

`*` es aceptable para una API cuyos clientes envían una clave o un token en
una cabecera: la página de otro sitio no lo tiene. No lo es con una cookie
de sesión, que el navegador envía por sí solo. El diagnóstico avisa en ese
caso.

## 11. Tamaño de las peticiones

```ini
REQ_SIZE = 8192
REQ_PART = 200
```

- `REQ_SIZE` es el cuerpo más grande que lee el framework, en KB. Uno más
  grande se responde con un 413, y no se guarda en memoria.
- `REQ_PART` limita las partes de un cuerpo multipart enviado con `PUT` o
  `PATCH`.
- PHP analiza por sí mismo los formularios `POST` y las subidas de archivos,
  antes que el framework: a esos los limita `php.ini` (`post_max_size`,
  `upload_max_filesize`, `max_file_uploads`, `max_input_vars`).
- Configura también el límite propio del servidor web
  (`client_max_body_size` en nginx, `LimitRequestBody` en Apache): rechaza
  un cuerpo grande antes de que arranque PHP.

## 12. Errores y registros

- `ERR_SHOW` muestra al cliente el detalle de un error. Se ignora en PROD;
  déjalo desactivado también en PREV.
- `LOG_MODE`: `MINI` escribe los errores, los avisos y las notices; `BASE`
  añade los info; `FULL` añade el debug y el contexto de un error (su pila,
  sin los argumentos de las llamadas). `BASE` basta en producción.
- La carpeta de los registros (`LOG_PATH`, `temp/logs` por defecto) tiene
  que tener permiso de escritura para PHP y no servirse.
- El framework nunca registra una contraseña, un código ni un token. Aun
  así, mantén `zend.exception_ignore_args = On` en `php.ini` en producción:
  es la protección propia de PHP para lo que registre otro código.

## 13. Notas sobre el enrutamiento

- Una ruta declarada que limita sus métodos (`mthdList`, o un destino por
  método) solo limita esa ruta. A la acción a la que apunta se puede seguir
  llegando con cualquier método por sus otras rutas: `/app/action`, una ruta
  de su run, u otro alias. Los límites de métodos no son un control de
  acceso, así que una acción a la que le importa el método lo comprueba ella
  misma.
- Un rol dado a una ruta declarada en `minRoles` (`"/panel": "managers"`) va
  con la acción a la que apunta esa ruta, para el destino de cada método y
  la acción por defecto incluida, sea cual sea la ruta que llega a ella. Un
  `/path` ahí que ninguna ruta declara, escrito exactamente como la ruta,
  hace fallar cada petición del perfil con un 500, e `install checkAll`
  falla con él (`access route`).
- Una aplicación se nombra solo con letras y cifras.
- Una clave de un archivo de rutas que nada lee se señala en el registro
  `runner` en cada petición.
- Un sitio puede responder a un error con su propia página:
  `errors/<status>.html` en la carpeta de sus páginas (`fileRoot`), con
  `%code%` y `%error%` rellenados. Se usa para una página o un archivo
  estático, y para una ruta a la que no responde ninguna ruta declarada en un
  perfil que encuentra sus rutas `manually`. Una petición a una acción
  conserva el formato del perfil; también una ruta desconocida en un perfil
  `autopath`.

## 14. Las aplicaciones que llaman por sus usuarios

Otra aplicación (una aplicación móvil, el portal de un socio) puede llamar a este
proyecto en nombre de uno de sus propios usuarios. Envía dos cosas: su
clave, en la cabecera del bloque `appsKeys`, y el token del usuario, firmado
con su propia clave privada. Un perfil lee las claves cuando su bloque
`security` nombra ese bloque: `"appsConf": "appsKeys"`.

```json
"appsKeys": { "headName": "X-App-Key", "appsList": { "portal": { "keyDigst": "…", "routeSet": ["contacts/*"], "publFile": "conf/keys/portal.pub", "roleTops": "standard", "madeDate": "…" } } }
```

- Las claves se crean, reciben sus rutas y se revocan solo desde la línea de
  comandos (`php nyuRun appkeys makeKeys|fullList|setRoute|revokeIt`). Una
  clave se muestra una vez; el archivo guarda su hash SHA-256. Una clave
  revocada se rechaza desde la petición siguiente.
- Copia la clave pública de la aplicación en `conf/keys` y da su ruta. No
  copies nunca su clave privada: tenerla permitiría a este proyecto
  falsificar los tokens de esa aplicación.
- Un token de la aplicación solo es válido con su clave. Enviado sin ella,
  va al portador propio del perfil, donde no es válido.
- El usuario se toma del token, tal como lo emitió la aplicación: este
  proyecto no lo busca en su propia base, así que un bloqueo en la
  aplicación solo se aplica cuando caduca su token. Mantén corto el
  `lifeSecs` de la aplicación.
- El rol de ese usuario nunca está por encima de `roleTops` (`standard` por
  defecto), y el rol que pide una ruta (`minRoles`, `needsActionRle()`) se
  sigue aplicando.
- Una aplicación solo llega a su `routeSet`, escrito como `openList`;
  cualquier otra ruta es un 404, como una ruta que no existe. Una ruta
  abierta a todos ignora la clave.
- Una clave incorrecta y un token falsificado cuentan contra la dirección.
  Cada escritura es una notice en el registro `access`, que nombra la
  aplicación, el usuario y la ruta; una lectura es un info. Registrar el
  propio registro le toca a la acción.
- Cada aplicación firma los tokens de sus usuarios con su propia clave
  privada, y cada proyecto al que llama tiene la clave pública
  correspondiente. Una vez que el proyecto configura `audience` en el bloque
  `appsKeys`, el token de una aplicación tiene que nombrarla, y nombrar a la
  aplicación como su emisor.

## 15. Correos de acceso

La base de usuarios nativa envía sus códigos por correo electrónico cuando
su bloque nombra una cuenta de `conf/mailer.json`. Sin `mailAcnt`, no se
envía nada: los códigos se dan en las respuestas, para entregarlos en mano,
como antes.

```json
"nativeDb": { ..., "mailAcnt": "nyuRuns", "signupOn": false, "codeMins": 15, "linkTmpl": "https://app.example.ch/access?do=%mailKind%&user=%userName%&code=%sentCode%" }
```

`php nyuRun install complete` lo pregunta todo una vez elegida la base de
usuarios nativa, y escribe las respuestas:

| Pregunta | Se escribe |
|---|---|
| ¿Enviar los correos de acceso? (no, por defecto) | no: `mailAcnt` se queda como está, no se pregunta nada más |
| Tipo de la cuenta de correo | `sendWith`: `selfSmtp` (propuesto), `phpMailr` (ofrecido solo cuando PHPMailer está instalado) o `emlFiles` |
| Host del servidor de correo, puerto (465 o 587), usuario, contraseña | solo `selfSmtp` y `phpMailr`. La contraseña va a `.env` como `NYU_MAIL_PASS`; la cuenta contiene `"passWord": "env:NYU_MAIL_PASS"`, nunca la propia contraseña |
| Dirección del remitente, nombre del remitente | `fromAddr`, `fromName`; una cuenta `emlFiles` guarda sus correos en `temp/mails` |
| Nombre de la cuenta de correo (`nyuRuns` propuesto) | la cuenta en `conf/mailer.json`, nombrada en `nativeDb.mailAcnt` |
| Enlace del front-end en los correos | `nativeDb.linkTmpl` |
| ¿Abrir las altas? (no, por defecto) | `nativeDb.signupOn` |
| Enviar un correo de prueba a | lo envía en el acto, como hace `install testMail` |

- Un `conf/mailer.json` creado por la instalación solo contiene esa cuenta;
  en un archivo que ya existe, se escribe la cuenta y las demás se quedan
  como están. `install makeConf` crea el archivo a partir de
  `conf/mailer.json.dist`, con sus tres cuentas de ejemplo, para rellenarlo
  a mano.
- `--sendWith=<selfSmtp|phpMailr|emlFiles|none>` y `--signupOn` responden de
  antemano. Con `--noPrompt`, una cuenta que envía tiene que estar ya en
  `conf/mailer.json`: no se puede preguntar por su servidor.
- **Antes de la puesta en marcha**, lanza
  `php nyuRun install testMail <address>`: envía un correo de prueba corto
  por la cuenta que nombra `nativeDb.mailAcnt`, y dice si ha salido o por
  qué no (código de salida 1). No cambia nada, y se registra en `runner`
  como cualquier otro correo. Una cuenta `emlFiles` escribe el correo como
  un archivo, y lo dice.
- `codeMins` son los minutos que dura un código enviado por correo (15 por
  defecto). Un código entregado por un gestor dura 300 minutos;
  `account makeUser` y `account resetPwd` aceptan `--noExpiry` para uno sin
  fin, solo en la línea de comandos.
- `linkTmpl` es la página del front-end a la que enlaza el correo.
  `%mailKind%` le dice qué formulario mostrar: `activate` y `register` envían
  a `auth/active`, `password` a `auth/recover`. Sin él, el correo solo lleva
  el código. El código está ligado al usuario, no al dispositivo: se puede
  escribir en otro.
- `signupOn: true` abre `auth/signup`. Un alta confirma su dirección con el
  código, y luego espera en `inReview` a un gestor
  (`POST users/<user>/approve`, `php nyuRun account approves`); rechazarla
  es eliminarla. Las altas nunca confirmadas se eliminan cuando caduca su
  código.
- El bloque `tryLimit` limita las altas: `maxQueue` pendientes a la vez
  (256), por encima de las cuales `auth/signup` responde 503 hasta que se
  aprueben o se eliminen algunas; `maxPerIp` altas de un mismo cliente en
  `holdSecs` (16), contadas aparte de sus credenciales incorrectas (una
  dirección en espera por ellas también se rechaza); y `alertsAt`, los
  recuentos a los que se avisa por correo (`waitlist`) a cada gestor
  aprobado que tenga dirección y el registro `access` lo anota (128, 200,
  256).
- `auth/signup` y `auth/forgot` responden 202 sea cual sea el nombre o la
  dirección. Cada restablecimiento pedido cuenta contra la dirección del
  cliente, como una contraseña incorrecta (`maxTries`). Con PHP-FPM, la
  respuesta termina antes de que se envíe el correo, así que su tiempo
  tampoco revela nada; con otra API de servidor, el cliente espera al
  correo, y el tiempo puede revelar si ha salido.
- Un restablecimiento pedido por correo conserva la contraseña hasta que se
  usa el código, así que nadie puede dejar fuera a un usuario pidiéndolo. Un
  restablecimiento hecho por un gestor la anula en el acto.
- Una dirección pertenece a una sola cuenta, comparada en minúsculas. El
  archivo de usuarios (`userFile`) también contiene direcciones, pero no
  puede enviar códigos: solo se lee.
- Los correos son `conf/mails/<language>/<kind>.txt` (su primera línea es el
  asunto) y `.html`, en inglés (`en`) y en francés (`fr`). Un correo se
  escribe en el idioma del usuario al que va (`userLocale`), si no en el
  `DEF_LANG` de `system.ini` (`en` cuando no está configurado); `fr-ch`
  recurre a `fr`, y luego al idioma por defecto. Otro idioma es una carpeta
  al lado de ellas. Una aplicación los sustituye por los suyos en
  `runs/Auth/mails/<language>/`.
- Una tabla creada por la 0.9 necesita primero
  `conf/schema/migrate-users-1.0.<type>.sql`, y luego
  `migrate-devices-1.0.<type>.sql` cuando tiene dispositivos (`<type>` es
  `mysql_db`, `postgres` o `sqlitedb`). Renombran las columnas y dan a los
  roles y a los estados sus códigos de la 1.0. En MySQL 8 y MariaDB, las
  partes 2 y 4 del archivo de los usuarios (las comprobaciones de los roles
  y los estados) se lanzan a mano.

## 16. Varios servidores

Un proyecto puede funcionar en varios servidores detrás de un balanceador de
carga. No guarda casi nada entre dos peticiones, pero lo que guarda hay que
preverlo:

- **Las claves de los tokens.** Cada servidor tiene que firmar y verificar
  los tokens con el mismo par. Créalo una vez
  (`php nyuRun setting mkSecret jwtToken`), y luego copia `conf/keys` y los
  secretos de `.env` en cada servidor **antes** de lanzar ahí
  `install complete`: la instalación conserva lo que existe, pero en un
  servidor sin claves crea su propio par, y los tokens de ese servidor los
  rechazan entonces los demás. Tras un cambio de clave, copia el par nuevo,
  y la clave pública anterior (`conf/keys/<block>.old.pub`), en cada
  servidor.
- **Los demás secretos.** Lo mismo vale para todo `.env`: la clave
  `tfaCrypt` (si no, los secretos de doble factor creados en un servidor no
  se pueden leer en otro), `markSecr` (si no, una marca de inicio de sesión
  de un servidor no es válida en el siguiente), y las claves de API.
- **Las sesiones** (`phpSessn`) viven en archivos en cada servidor. Dale a
  `dataPath` una carpeta que compartan todos los servidores, o haz que el
  balanceador de carga devuelva a cada usuario al mismo servidor.
- **Los recuentos de intentos fallidos** (`tryLimit.dataPath`) son archivos
  en cada servidor, así que cada uno cuenta por su lado, y una dirección la
  pone en espera el servidor que vio los intentos. Una carpeta compartida
  hace que el límite sea global.
- **La base de datos** (usuarios, dispositivos, cuentas en espera) la
  comparten todos.
- **El reloj.** Cada fecha de `users` y `devices` la escribe PHP, en su zona
  horaria (`date.timezone` de php.ini), sea cual sea la zona de la base de
  datos. Configura la misma zona en cada servidor, preferiblemente UTC: dos
  servidores con una hora de diferencia no estarían de acuerdo sobre quién
  está en línea, sobre una cuenta en espera ni sobre cuándo caduca un
  código.
- **La caché de configuración** (`temp/cache`) la crea cada servidor. Tras
  un cambio en `conf/`, lanza `php nyuRun setting clrCache` en cada uno de
  ellos.
- **Los registros** los escribe cada servidor, en su propio `temp/logs`:
  reunirlos es cosa del alojamiento.
