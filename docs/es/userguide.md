# nyuRuns — Guía del usuario

Versión 1.0

## Índice

1. [Presentación del producto](#1-presentación-del-producto)
2. [Requisitos mínimos](#2-requisitos-mínimos)
3. [Pasos de instalación](#3-pasos-de-instalación)
4. [Pasos de configuración](#4-pasos-de-configuración)
5. [Tu primera aplicación (run)](#5-tu-primera-aplicación-run)
6. [Estructura de tu proyecto](#6-estructura-de-tu-proyecto)
7. [Referencia del código](#7-referencia-del-código)
8. [Recomendaciones](#8-recomendaciones)

A. [Resolución de problemas](#a-resolución-de-problemas)

---

## 1. Presentación del producto

nyuRuns es un framework PHP para el back-end de tu aplicación: la parte
que recibe las peticiones de una página web, de una app móvil o de otro
programa, y devuelve datos. Tú escribes lo que hace de verdad tu servicio;
nyuRuns se encarga del resto. Este:

- averigua qué acción pide una URL;
- comprueba quién llama, y si tiene permiso;
- se queda solo con las entradas que espera tu acción, cada una en el
  formato correcto;
- escribe la respuesta, en JSON o en otro formato, errores incluidos.

Además trae lo que casi todos los servicios acaban necesitando de todos
modos: usuarios y roles, tokens firmados y sesiones, modelos de base de
datos, documentos, imágenes, sonidos, correo electrónico, registros, y una
línea de comandos (`php nyuRun`) para instalar, configurar, probar y
entregar tu proyecto.

### 1.1 Lo que vas a construir

Paso a paso, vas a instalar nyuRuns en tu máquina y construir un pequeño
servicio llamado `Notes`:

- notas que cualquiera que haya iniciado sesión puede leer y buscar;
- cada nota en su propia URL, donde puedes leerla, crearla, modificarla y
  borrarla;
- la escritura reservada al personal, mediante un rol;
- una página de navegador que muestra una nota.

Al final, tendrás un servicio que responde a peticiones reales, y sabrás
dónde vive cada parte de un proyecto.

### 1.2 Antes de empezar

Necesitas saber PHP y lo básico de HTTP: métodos, códigos de estado,
cabeceras. No necesitas un servidor de base de datos, porque la guía usa
SQLite, que guarda toda la base de datos en un solo archivo. Los comandos
están escritos para una terminal y funcionan igual en PowerShell en
Windows.

### 1.3 Los demás documentos

| Documento | Ve allí para |
|---|---|
| [Especificaciones funcionales](funcspecs.md) | lo que hace nyuRuns, y cómo se desarrollan sus procesos |
| [Especificaciones técnicas](techspecs.md) | cómo está construido: componentes, archivos, comandos, convenciones |
| [Alojamiento](hosting.md) | poner un proyecto en un servidor real |
| Referencia del código (en inglés) | cualquier clase o método (capítulo 7) |

---

## 2. Requisitos mínimos

### 2.1 Para esta guía

| Necesitas | Por qué | Compruébalo con |
|---|---|---|
| **PHP 8.2** o posterior, en la línea de comandos | ejecuta nyuRuns y su línea de comandos | `php -v` |
| las extensiones **json**, **dom**, **iconv**, **zlib**, **mbstring** | Composer no instala las bibliotecas de nyuRuns sin ellas | `php -m` |
| la extensión **openssl** | firma los tokens con los que inician sesión tus usuarios | `php -m` |
| la extensión **pdo_sqlite** | la base de datos del tutorial | `php -m` |
| **Composer** | instala las bibliotecas | `composer --version` |
| **Git** | descarga el código | `git --version` |

El servidor web integrado en PHP es todo lo que necesitas para el
desarrollo, así que nada de Apache ni de nginx en esta guía.

La mayoría de las versiones de PHP ya incluyen estas extensiones. Si falta
una en `php -m`, actívala en tu `php.ini` (`extension=pdo_sqlite`). Si tu
PHP no carga ningún `php.ini`, apunta la variable de entorno `PHPRC` a la
carpeta que contiene uno. El apéndice A muestra el aspecto de cada
extensión que falta.

### 2.2 Para un servidor real

Un servidor que ejecuta tu proyecto para usuarios reales necesita además
HTTPS, y un servidor web que envíe cada URL a nyuRuns. Todo lo demás
depende de lo que haga tu proyecto: otra base de datos, un servidor de
correo, ffmpeg para los sonidos, o extensiones para las imágenes y los
documentos ofimáticos.

- Las [Especificaciones técnicas, capítulo 3](techspecs.md#3-requisitos-del-sistema)
  enumeran lo que necesita cada parte.
- [Alojamiento](hosting.md) te guía en la puesta a punto del servidor.

En cualquier máquina, `php nyuRun install checkAll` te dice lo que falta.

---

## 3. Pasos de instalación

### 3.1 Obtener el código

```
git clone <repository address> notes
cd notes
composer install
```

Composer pone las bibliotecas en `vendor/`. A partir de aquí, ejecuta cada
comando desde la carpeta `notes`, la raíz de tu proyecto.

### 3.2 Instalar el proyecto

```
php nyuRun install complete
```

El comando hace unas cuantas preguntas: qué es tu proyecto, el entorno,
dónde viven tus usuarios, la base de datos, y los nombres de las cuentas
básicas. Cada una propone una respuesta, e Intro la acepta. Para esta
guía, responde:

| Pregunta | Respuesta | Por qué |
|---|---|---|
| `What this project is (allInOne, authServ, dataServ, openSite) [allInOne]` | Intro | los usuarios, el inicio de sesión y las notas viven todos en este único proyecto |
| `Environment (DEVS, TEST, PREV, PROD) [DEVS]` | Intro | estás desarrollando: los errores se muestran y todo queda registrado |
| `Store of the users (nativeDb, userFile, none) [userFile]` | `nativeDb` | los usuarios van a una base de datos, que el tutorial necesita de todos modos |
| `Database type (mysql_db, postgres, sqlitedb) [mysql_db]` | `sqlitedb` | SQLite: la base de datos es solo un archivo, sin servidor |
| `Database file [nyuRuns]` | `notes.sqlite` | el archivo, en la raíz de tu proyecto |
| `Accounts to create: simple (userDemo and bossDemo) or full (userDemo, powrDemo, deptDemo, bossDemo) [simple]` | `full` | una cuenta por rol: el tutorial también inicia sesión como `powrDemo` |
| `Name of the standard account [userDemo]`, luego las de `advanced`, `delegate` y `managers` | Intro, cuatro veces | los nombres que usa esta guía |

Después se encarga de todo lo demás por sí solo:

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

Esto es lo que acaba de pasar:

- **Primero se comprueba PHP**, antes de escribir nada.
- **Tus ajustes locales** se han creado a partir de sus ejemplos. `.env`
  guarda los secretos, `conf/access.json` fija cómo inician sesión los
  usuarios, y `conf/database.json` dice dónde está la base de datos.
  Ninguno de ellos se sube nunca al repositorio.
- **Las claves de los tokens** se han generado en `conf/keys`.
- **Se han creado cuatro usuarios**, uno por rol: `userDemo` (`standard`),
  `powrDemo` (`advanced`), `deptDemo` (`delegate`) y `bossDemo`
  (`managers`). La versión simple, la que toma `--noPrompt`, crea solo
  `userDemo` y `bossDemo`. **Copia sus contraseñas ahora**: no volverás a
  verlas, y las necesitarás en los pasos siguientes. Las tuyas serán
  distintas de las de arriba.
- **Un diagnóstico** lo remata todo (3.5).

Vuelve a ejecutarlo y nada se rompe: `install complete` nunca sustituye lo
que ya existe, sea un archivo, un secreto, una tabla o un usuario.

> ¿No quieres base de datos? Responde `userFile`. Tus usuarios se guardan
> entonces en un archivo, `conf/users.lst`, no se crea ningún ajuste de
> base de datos, y el servicio funciona sin ninguna base de datos. Puedes
> moverlos a una más adelante con un solo comando (4.8).

> ¿Quieres separar el inicio de sesión de tus datos? Haz de un proyecto un
> **servidor de autenticación** (`authServ`): guarda los usuarios, les
> inicia la sesión y emite los tokens. La instalación pregunta además
> quién emite los tokens (se propone el nombre de tu host) y qué proyectos
> los aceptarán. Tus otros proyectos son entonces **servidores de
> recursos** (`dataServ`): no tienen usuarios, y aceptan los tokens del
> servidor de autenticación. Su instalación se salta los usuarios y pide
> el archivo de la clave pública del servidor de autenticación
> (`conf/keys/jwtToken.pub` allí), su emisor, y el nombre de este proyecto
> en la audiencia. Su propio inicio de sesión responde 501: los usuarios
> inician sesión en el servidor de autenticación. `install checkAll` dice
> qué perfil es un proyecto, en su fila `profile`.

> ¿Solo un sitio, sin nada en lo que iniciar sesión? Responde `openSite`.
> Sin usuarios, sin cuentas, sin secretos: la instalación sirve el perfil
> `statics`, tus páginas y tus archivos, abiertos a todo el mundo. Nunca
> abre un perfil que pueda ejecutar una acción, así que un run que añadas
> más tarde no se hará público a tus espaldas. Si más adelante vuelves a
> ejecutar la instalación con una base de usuarios, cierra los perfiles
> que había abierto, y te dice cuáles.

### 3.3 Servirlo

PHP trae su propio servidor web, que basta y sobra para el desarrollo.
Arráncalo desde la carpeta `www` en una segunda terminal, y déjalo
funcionando:

```
cd www
php -S localhost:8000 index.php
```

Cada petición va a `index.php`, que se la pasa a nyuRuns. Pulsa Ctrl+C
para parar el servidor.

### 3.4 Enviar una primera petición

Inicia sesión como `userDemo` con su contraseña (en Windows PowerShell 5,
escribe `curl.exe` en lugar de `curl`):

```
curl -X POST http://localhost:8000/auth/login -d "userName=userDemo&passWord=501dfc2edfd1d66323e4"
```

```json
{"token":"eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIsImtpZCI6…"}
```

El servicio responde con un token, firmado con las claves creadas durante
la instalación. De serie, cada URL exige ese token, salvo el inicio de
sesión y lo que hacen los visitantes antes de tener una cuenta. Tu
front-end lo devuelve con cada petición, en una cabecera
`Authorization: Bearer <token>`. Ya puestos, intenta romper cosas:

| Petición | Respuesta |
|---|---|
| una contraseña equivocada | `401 {"error":"Invalid credentials","code":401}` |
| `curl http://localhost:8000/nothing/here` | `401 {"error":"Missing Bearer Authorization header","code":401}` |
| lo mismo, con `-H "Authorization: Bearer <token>"` | `404 {"error":"Not Found","code":404}` |
| `curl http://localhost:8000/auth/login` con el token (un `GET`) | `405 {"error":"Method Not Allowed","code":405}` |

El capítulo 4 muestra cómo decidir quién entra y dónde.

### 3.5 Comprobarlo

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

Cada fila es `fine`, `warn` (merece un vistazo) o `fail` (el servicio no
puede servir, o está expuesto). En una instalación recién hecha, unos
cuantos avisos son normales: `extensions` (extensiones opcionales que
quizá no necesites), `origins` para cada perfil que lee tokens (cualquier
sitio puede llamar a la API desde un navegador), `token claims` (tus
tokens aún no están ligados a tu proyecto) y, en Windows, `file rights`.
El perfil `example` solo explica los ajustes: nunca se carga, y un punto
de entrada que lo nombre hace fallar el diagnóstico. Un `warn` en
`upload progress` viene de los propios ajustes de PHP: pon
`session.upload_progress.enabled = 0` en tu `php.ini`. El apéndice A
explica las demás filas.

### 3.6 En un servidor real

Un proyecto no se copia tal cual a un servidor. Construyes una copia hecha
para ese servidor (`php nyuRun bundles final`), la instalas allí con
`install complete`, y la pones detrás de un servidor web que envíe cada
URL a `www/index.php`. El capítulo 8 tiene la lista de comprobación para
la puesta en marcha, y [Alojamiento](hosting.md) trata la configuración
del servidor.

---

## 4. Pasos de configuración

Cada ajuste vive en un archivo de `conf/`, salvo los secretos, que van en
`.env`. `php nyuRun setting` los muestra y cambia un valor cada vez:
primero comprueba el valor nuevo, y guarda el archivo anterior en
`temp/archives`. Cada paso de abajo termina con una forma de comprobar que
ha funcionado.

### 4.1 Elegir el perfil

De serie, cada petición arranca el perfil `default`: tokens, con el inicio
de sesión abierto. El perfil `restapi` funciona igual, y es el pensado
para una API web, así que sírvelo en la raíz:

```
php nyuRun setting entryPts / restapi
```

Un proyecto puede servir varios perfiles: `setting entryPts /api restapi`
da las rutas que cuelgan de `/api` a `restapi`, y
`--hostName=api.example.org` le da un host propio. El mapa vive en
`conf/doorways.lst`.

Después mira quién puede llegar a qué:

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

La columna `owner` dice quién declara cada ruta: el sitio (`conf/routes`)
o un run (su propio `routes.rte`). Todo necesita un token
(`others: logged`). Las excepciones son el inicio de sesión y lo que hacen
los visitantes antes de tener una cuenta: activarla, darse de alta,
recuperar una contraseña olvidada. El run `Users` es para los delegados y
los gestores: un delegado (`deptDemo`) lee los usuarios y aprueba las
altas, y todas las demás acciones son de los gestores (`bossDemo`).
Pruébalo con `curl http://localhost:8000/users`: sin token recibes un 401,
con el token de `userDemo` un 404, y con el de `deptDemo` o el de
`bossDemo` la lista de usuarios.

Los demás perfiles son `service` (programas que llaman con una clave de
API), `website` (un sitio con una cookie de sesión), `statics` (solo
páginas y archivos), `mapping` (solo las rutas que declares), y `example`,
que nunca se carga pero muestra todos los ajustes que puede tener un
perfil.

### 4.2 Decidir quién entra y dónde

El rol mínimo de cada ruta se fija en el perfil, en `minRoles`. Abre
`conf/presets/restapi.rps` y busca:

```json
"minRoles": { "users/*": "delegate", "users/create": "managers", "users/remove": "managers", "users/role": "managers", "users/setmail": "managers", "users/langs": "managers", "users/lock": "managers", "users/unlock": "managers", "users/reset": "managers" }
```

Cuando varias entradas nombran una ruta, se aplica el rol más alto:
`users/*` abre `Users` a un delegado, y cada acción que modifica una
cuenta está nombrada para quedarse en manos de los gestores.

Nombra una ruta como `run/action`, como `run/*` para todas las acciones de
un run, o como `/path` para una ruta declarada, montaje incluido. Los
roles están ordenados (`standard` < `advanced` < `delegate` < `managers`),
así que una ruta que pide `advanced` deja pasar también a los dos roles
que están por encima. El capítulo 5 añade aquí las rutas de `Notes`. Tras
una modificación, `setting fullList restapi` muestra el resultado, e
`install checkAll` te dice si el perfil aún se carga.

Los bloques como `minRoles` se editan a mano en el archivo, ya que
`setting setValue` solo cambia valores sueltos.

### 4.3 Gestionar los usuarios

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

| Para | Ejecuta |
|---|---|
| crear un usuario pendiente de activación | `php nyuRun account makeUser ann advanced` (el código de activación se muestra una vez) |
| fijar su dirección de correo | `php nyuRun account setEmail ann ann@example.org` |
| restablecer su contraseña | `php nyuRun account password ann` (se muestra una vez) |
| cambiar su rol | `php nyuRun account giveRole ann managers` |
| bloquearlo o desbloquearlo | `php nyuRun account lockUser ann`, `php nyuRun account freeUser ann` |
| ver lo que admite un comando | `php nyuRun account --helpText` |

Un gestor puede hacer todo esto también por HTTP, mediante el run `Users`
(`POST /users`, `PUT /users/<user>/lock`, …: sus rutas nombran al usuario
en la ruta). Cada cambio queda registrado en `temp/logs/access.log`.

### 4.4 Enviar los correos de acceso

Sin cuenta de correo, el código de activación de un usuario nuevo va al
gestor, que se lo transmite. Con una, el usuario lo recibe directamente.
Para probarlo sin enviar nada de verdad, usa una cuenta que escriba los
correos en archivos:

```
cp conf/mailer.json.dist conf/mailer.json
php nyuRun setting setValue accsConf nativeDb.mailAcnt devbox
```

`devbox` es la tercera cuenta del ejemplo, y guarda cada correo como un
archivo en `temp/mails`. Ahora, con la sesión iniciada como `bossDemo`,
crea un usuario con una dirección de correo:

```
curl -X POST http://localhost:8000/users -H "Authorization: Bearer <token of bossDemo>" -d "userName=bob&saveRole=standard&userMail=bob@example.org"
```

```json
{"user":"bob","mailed":true}
```

El correo te espera en `temp/mails`, código incluido, y
`temp/logs/runner.log` confirma que se ha enviado. Para correos de verdad,
rellena la primera cuenta de `mailer.json` con tu servidor SMTP, pon su
contraseña en `.env` (`NYU_MAIL_PASS`), y nombra esa cuenta en
`nativeDb.mailAcnt`.

No tienes por qué hacerlo a mano: con la base de usuarios nativa,
`php nyuRun install complete` pregunta si enviar los correos de acceso,
luego tu servidor SMTP, el remitente, el enlace del front-end y si las
altas están abiertas, y lo escribe todo (la contraseña acaba en `.env`,
nunca en `mailer.json`). Sea como sea, comprueba que la cuenta envía de
verdad:

```
php nyuRun install testMail you@example.org
```

Te dice si el correo ha salido, o por qué no, y no cambia nada.

Una vez que los correos funcionan, se abren dos puertas más:

- **Las contraseñas olvidadas** funcionan enseguida: `auth/forgot`, y
  luego `auth/recover`.
- **Las altas** se abren con
  `php nyuRun setting setValue accsConf nativeDb.signupOn true`. Cada una
  espera entonces la aprobación de un gestor (`account approves`).

Pon en `nativeDb.linkTmpl` la página de tu front-end que recibe un código
(`https://…/activate?code=%sentCode%`), y los correos incluirán un enlace
en el que hacer clic.

### 4.5 Elegir el idioma

Los correos salen en el idioma de cada usuario, o si no en el idioma por
defecto:

```
php nyuRun setting setValue coreConf DEF_LANG fr
```

nyuRuns trae sus correos en inglés y en francés. Fija el idioma de un
usuario con `account language ann fr`, o deja que los usuarios fijen el
suyo (`auth/usrlang`).

### 4.6 Guardar los secretos

`install complete` ha puesto los secretos en `.env` y las claves de los
tokens en `conf/keys`. No los subas nunca al repositorio. Para crear
claves nuevas:

```
php nyuRun setting mkSecret jwtToken
```

Pregunta antes de sustituir unas claves en uso, porque unas claves nuevas
anulan todos los tokens firmados con las antiguas, y todo el mundo tiene
que volver a iniciar sesión.

### 4.7 Registros y entorno

| Ajuste | Comando | Efecto |
|---|---|---|
| cuánto se registra | `php nyuRun setting setValue coreConf LOG_MODE BASE` | `FULL` todo, `BASE` todo salvo la depuración, `MINI` errores, avisos y notificaciones |
| si se muestran los errores | `php nyuRun setting setValue coreConf ERR_SHOW FALSE` | nunca se muestran en producción, diga lo que diga este ajuste |
| el entorno | `php nyuRun setting setValue coreConf ENV_NAME PROD` | `DEVS`, `TEST`, `PREV`, `PROD` |

Para leer un registro desde la línea de comandos:

```
php nyuRun showlog access --numLines=3
```

```
[2026-11-10 01:16] [NOTICE] Privileged login granted for user "bossDemo" (managers) from 127.0.0.1
[2026-11-10 01:16] [NOTICE] Privileged access granted for user "bossDemo" (managers) from 127.0.0.1 to users/create
[2026-11-10 01:16] [NOTICE] User "bob" created (standard) by user "bossDemo" from 127.0.0.1
```

### 4.8 Del archivo de usuarios a una base de datos

Si durante la instalación respondiste `userFile`, tus usuarios viven en
`conf/users.lst`. Para moverlos a una base de datos:

```
php nyuRun install makeConf
php nyuRun install moveUser nativeDb --yesToAll
```

El primer comando crea `conf/database.json` a partir de su ejemplo. El
segundo pregunta dónde está la base de datos, crea la tabla de usuarios, y
mueve a todos los usuarios, con su contraseña, su rol y su secreto de
doble factor. Después cambia el inicio de sesión a la base de datos. El
archivo de usuarios queda intacto, así que puedes volver a apuntar el
inicio de sesión hacia él: pon `authWith` y `authConf` a `userFile` en el
bloque `loginSet` de `access.json`. Solo ten en cuenta que lo que haya
cambiado en la base de datos desde entonces no estará en el archivo.

---

## 5. Tu primera aplicación (run)

Es hora de construir `Notes`: una lista de notas que cualquier usuario con
la sesión iniciada puede leer y que solo el personal (el rol `advanced`)
puede escribir, con cada nota en su propia URL y una página para los
navegadores. Basta con un puñado de archivos cortos:

```
runs/Notes/
  notes.sql                 la tabla
  mods/Listing.php          la lista de notas
  mods/Single.php           una nota
  Actions.php               lo que hace el run
  input/<action>.json       las entradas de cada acción
  pages/note.html           la página de una nota
  routes.rte                sus URL
test/data/NotesTest.php     su prueba
```

El perfil es `restapi` (4.1) y la base de datos es `notes.sqlite` (3.2).

### 5.1 La tabla

`runs/Notes/notes.sql`:

```sql
CREATE TABLE notes (
	id_note  INTEGER PRIMARY KEY AUTOINCREMENT,
	noteName VARCHAR(80) NOT NULL,
	noteText TEXT,
	noteUser VARCHAR(40),
	madeDate DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

El identificador es `id_` más el nombre de la tabla en singular. nyuRuns
da a sus propias columnas nombres de exactamente 10 caracteres; los tuyos
son cosa tuya, y nada los comprueba, así que aquí tienen 8. Crea la tabla
en la base de datos de tu proyecto:

```
php -r "(new PDO('sqlite:notes.sqlite'))->exec(file_get_contents('runs/Notes/notes.sql'));"
```

### 5.2 Los modelos

Primero, la lista de notas, `runs/Notes/mods/Listing.php`:

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

Después una sola nota, `runs/Notes/mods/Single.php`: lo mismo, salvo que
`Single` extiende `nyu\Data\DBRec`. Cada archivo lleva el nombre de su
clase. Las acciones los obtienen por su nombre,
`getModelObject('listing')` y `getModelObject('single')`, y los modelos
averiguan solos la clave y los campos de la tabla. Un archivo llamado
`ListingModel.php` también se encontraría: es la forma antigua, que se
mantiene como alternativa.

### 5.3 Las acciones

`runs/Notes/Actions.php`: cada método público es una acción.

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

Algunas cosas en las que fijarse:

- Una acción fija su estado (`statusCode`) y sus datos (`replyValue`), y el
  framework escribe la respuesta.
- `stopThisAction()` detiene una acción en el acto, tanto si se llama
  desde la propia acción como desde un método auxiliar. Así es como
  `loadNote()` responde 404 para cada acción que necesita una nota.
- `loadNote()` está protegido, así que no es una acción y ningún cliente
  puede llamarlo.
- `noteName~` con `*` a cada lado pide una coincidencia. Un valor simple se
  compararía de forma exacta.
- Los nombres siguen las convenciones del proyecto: 8 caracteres para una
  variable local, 12 para un parámetro, 6 para una variable de bucle
  (`one` o `nth` y el elemento: `oneFld`). `recordIdText` y `rowsList` son
  los nombres que usa el ejemplo `Shows` para lo mismo, y `listModl`,
  `rcrdModl` dicen que contienen modelos. La auditoría comprueba también
  tus runs (5.9).

### 5.4 Las entradas

Cada acción declara lo que acepta, en `runs/Notes/input/`:

| Archivo | Contenido |
|---|---|
| `index.json` | `{ "fieldSet": { "name": "", "limit": "Integer" } }` |
| `create.json` | `{ "fieldSet": { "name!": "", "text": "" } }` |
| `update.json` | `{ "fieldSet": { "name": "", "text": "" } }` |
| `view.json`, `remove.json`, `card.json` | `{ "fieldSet": {} }` |

Solo los campos de la lista llegan a la acción. `name!` es obligatorio,
así que una nota enviada sin nombre se rechaza (400) antes siquiera de
que se ejecute la acción. Un formato vacío guarda el valor exactamente
como se envió, mientras que `Integer` convierte `limit` en un número. Los
textos se guardan tal como se envían y se codifican allí donde se
muestran: en la página (5.8), o en tu front-end.

### 5.5 Las URL

`runs/Notes/routes.rte` asigna cada método de cada URL a su acción:

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

Las rutas de un run se montan bajo su nombre en minúsculas, así que `""`
se convierte en `/notes` y `$id` en `/notes/$id`. Una ruta que empieza por
una barra parte en cambio de la raíz del sitio: `/note/$id`. Cada destino
nombra una de las acciones del run solo con `doAction`, y `$id` captura
esa parte de la ruta y se la pasa a la acción como su parámetro.

En cuanto un run tiene su propio archivo de rutas, ese archivo es la única
entrada: `notes/view/1` recibe un 404. El perfil puede montar un run en
otro sitio (`"runMount": { "notes": "api/notes" }` en su bloque
`dispatch`), o dejar de servirlo (`false`).

### 5.6 Los roles

En `conf/presets/restapi.rps`, abre a todo el mundo la ruta de la página,
y deja la escritura al personal:

```json
"openList": ["auth/login", "auth/active", "auth/signup", "auth/forgot", "auth/recover", "/.well-known/jwks.json", "notes/card"],
"minRoles": { "users/*": "delegate", "users/create": "managers", "users/remove": "managers", "users/role": "managers", "users/setmail": "managers", "users/langs": "managers", "users/lock": "managers", "users/unlock": "managers", "users/reset": "managers", "notes/create": "advanced", "notes/update": "advanced", "notes/remove": "advanced" }
```

Nombrar la página por su acción la mantiene abierta dondequiera que se
monte el run. Compruébalo:

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

### 5.7 Pruébalo

Inicia sesión como `userDemo` y como `powrDemo` (3.4), y guarda los dos
tokens. Después añade `-H "Authorization: Bearer <token>"` a cada llamada:

| Petición | Como | Respuesta |
|---|---|---|
| `GET /notes` | userDemo | `204`: aún no hay ninguna nota |
| `POST /notes` con `name=Compras` | userDemo | `404 {"error":"Not Found","code":404}`: escribir es cosa del personal |
| `POST /notes` con `name=Compras&text=Leche, pan` | powrDemo | `201 {"id_note":1}` |
| `POST /notes` con `text=Sin nombre` | powrDemo | `400 {"error":"Missing or invalid input: name","code":400}` |
| `GET /notes` | userDemo | `200 [{"id_note":1,"noteName":"Compras","noteText":"Leche, pan","noteUser":"powrDemo","madeDate":"2026-11-10 01:14:55"}]` |
| `GET /notes?name=comp` | userDemo | las notas cuyo nombre contiene «comp» |
| `GET /notes/1` | userDemo | `200` la nota |
| `PATCH /notes/1` con `text=Leche, pan, huevos` | powrDemo | `200` la nota, modificada |
| `DELETE /notes/1` | powrDemo | `204` |
| `GET /notes/1` | userDemo | `404` |
| `GET /notes` | nadie | `401 {"error":"Missing Bearer Authorization header","code":401}` |

Por ejemplo:

```
curl -X POST http://localhost:8000/notes -H "Authorization: Bearer <token of powrDemo>" -d "name=Compras&text=Leche, pan"
curl -X PATCH http://localhost:8000/notes/1 -H "Authorization: Bearer <token of powrDemo>" -d "text=Leche, pan, huevos"
```

Cuando se rechaza a un usuario sin el rol, se le dice que la URL no
existe, como si de verdad no existiera. Así que tu front-end no debería
ofrecer lo que el rol de un usuario no puede alcanzar.

### 5.8 Una página

`runs/Notes/pages/note.html`:

```html
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>%name%</title></head>
<body>
	<h1>%name%</h1>
	<p>%text%</p>
	<p><small>Escrito por %user%, el %date%</small></p>
</body>
</html>
```

La acción `card` rellena cada marcador `%name%` mediante
`servePageReply()`, y cada valor se codifica en HTML. Borraste la nota 1 al
final de 5.7, así que primero crea una nueva, y luego abre
`http://localhost:8000/note/<su número>` en un navegador. No hace falta
iniciar sesión, ya que esa ruta está abierta. Una nota llamada
`Sala "B" <a mediodía>` aparece exactamente así, con sus caracteres
codificados una vez y solo una.

### 5.9 Una prueba propia

`test/data/NotesTest.php` ejecuta las acciones sobre una base de datos
propia. Un run recibe un `RunContext`, lo que necesita para una petición,
y `treatAskedActn()` ejecuta la acción:

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

Ejecútala junto con la auditoría de las convenciones:

```
php test/run.php Notes audit
```

```
17 checks passed, 0 failed
```

### 5.10 Lo que has construido

Ahora tienes un servicio con un recurso en su propia URL, que leen todos
los usuarios y escribe el personal. Cada método tiene su acción, cada
acción declara sus entradas, y además hay una página para el navegador y
una prueba. Por dónde seguir:

- añadir un campo: una columna en la tabla, y la entrada que la rellena;
- enlazar las notas con otra tabla mediante una columna con el nombre de
  esa tabla en singular más `_id` (como `user_id`), y declararla como hija
  del registro (Especificaciones técnicas, 10.3);
- enviar un correo cada vez que se escribe una nota, mediante una cuenta
  de `mailer.json`;
- entregar el proyecto a un servidor (capítulo 8).

---

## 6. Estructura de tu proyecto

Este es tu proyecto una vez terminado el capítulo 5:

```
notes/
  www/index.php               el único archivo al que llega el servidor web
  nyuRun                      la línea de comandos
  runs/
    Notes/                    tu run, con sus propias rutas (capítulo 5)
    Auth/  Users/  Shows/     los runs que trae nyuRuns: sesión, usuarios, un ejemplo
    Shared/                   lo que comparten tus runs: modelos, verificaciones
  conf/
    system.ini                registros, errores, cachés, límites
    access.json               cómo inician sesión          (tuyo, no se sube)
    database.json             dónde está la base de datos  (tuyo, no se sube)
    mailer.json               las cuentas de correo        (tuyo, no se sube)
    presets/restapi.rps       el perfil del servicio: sus rutas abiertas y sus roles
    routes/                   las rutas de todo el sitio
    keys/                     las claves de los tokens     (tuyas, no se suben)
  .env                        los secretos                 (tuyo, no se sube)
  notes.sqlite                la base de datos del tutorial
  temp/                       registros, cachés, correos escritos en archivos
  test/data/NotesTest.php     tu prueba
  src/                        nyuRuns en sí
  vendor/                     las bibliotecas
```

Lo que escribes va en `runs/`, el perfil y `test/`, más `conf/routes` para
las rutas de todo el sitio. Lo que trae nyuRuns vive en `src/` y en los
runs que lo acompañan. Lo que pertenece a una sola máquina (los archivos
marcados como «tuyo», más `temp/`) se queda en ella: git lo ignora, y la
copia construida para un servidor lo deja fuera. Nada de `conf/keys` viaja
con ella, ni siquiera una clave pública, porque el servidor crea las
suyas. Si tu servidor verifica tokens emitidos por otro, nombra la clave
pública de ese servidor en un modo del proyecto (sus rutas `addPaths`,
consulta `bundles makeMode`) para llevarla consigo.

`notes.sqlite` también queda fuera de esa copia, pero git no lo sabe.
Añádelo a `.gitignore`, o saca la base de datos del proyecto y da su ruta
completa en `conf/database.json`.

La organización completa, y lo que conserva de ella un bundle, está en las
[Especificaciones técnicas, capítulo 11](techspecs.md#11-estructura-de-carpetas).

---

## 7. Referencia del código

Cada clase y cada método de nyuRuns tiene un docblock que dice lo que
hace, lo que recibe y lo que devuelve. La referencia del código es un
sitio construido a partir de esos docblocks: una página por clase, sus
métodos con sus parámetros, y un cuadro de búsqueda.

### 7.1 Construirla

La referencia la construye phpDocumentor 3. No es una de las bibliotecas
de un proyecto, así que la consigues tú: descarga su `.phar` del sitio de
phpDocumentor y ponlo en `temp/tools`. Después, desde la raíz del
proyecto:

```
php -d memory_limit=1G temp/tools/phpDocumentor.phar run
```

Lee `phpdoc.dist.xml`, que cubre las clases de `src` con sus métodos
públicos y protegidos, y escribe la referencia en `docs/online/reference`,
que git no sigue. phpDocumentor necesita la extensión `mbstring`.

Con PowerShell, `pwsh .phpdoc/build.ps1` construye toda la documentación
de una vez. Primero la referencia, luego las páginas de los documentos que
estás leyendo ahora mismo, en cada idioma en que están escritos, con el
aspecto del proyecto gracias al analizador Markdown y al generador HTML
propios de nyuRuns. Los archivos `.md` siguen siendo la fuente, y
`docs/online` se construye a partir de ellos: una carpeta por idioma, la
referencia al lado, y un `index.html` que lleva a cada visitante al idioma
de su navegador.

### 7.2 Leerla

Abre `docs/online/index.html` en un navegador, o sirve la carpeta
(`php -S localhost:8790 -t docs/online`). La referencia del código es la
última entrada del menú, `docs/online/reference/index.html`, en inglés en
todos los idiomas. Las clases aparecen por espacio de nombres, y el
capítulo 5 de las Especificaciones técnicas dice lo que contiene cada una.
Las que más usarás en un run son:

| Clase | Para |
|---|---|
| `nyu\App\Actionset` | lo que recibe una acción, y sus métodos auxiliares (`servePageReply()`, `stopThisAction()`, `needsActionRle()`, …) |
| `nyu\Data\DBRec`, `nyu\Data\DBLst` | un registro y una lista de una tabla |
| `nyu\Data\DBStore\Filter` | cómo se escribe un filtro |
| `nyu\Tools\Variable` | los formatos de las entradas (`OutText`, `Integer`, `EmlAddr`, …) |
| `nyu\Tools\Request` | la petición: cabeceras, cookies, archivos, cliente |
| `nyu\Data\Mail\Mailer` | enviar un correo desde una acción |

### 7.3 Escribir para ella

Los docblocks de tus propios runs siguen la misma regla que los del
framework: cada método público tiene uno, con `@param` y `@return`. No
aparecen en la referencia, que solo cubre `src`. Para incluirlos, añade
`<path>runs</path>` junto a `<path>src</path>` en `phpdoc.dist.xml`.

---

## 8. Recomendaciones

Una lista de comprobación para el día en que tu servicio se pone en marcha
en un servidor real. Cada punto indica dónde se trata a fondo: los
consejos en las
[Especificaciones técnicas, capítulo 2](techspecs.md#2-pautas-y-recomendaciones),
el diseño en las
[Especificaciones funcionales, capítulo 8](funcspecs.md#8-pautas-de-desarrollo),
y el servidor en [Alojamiento](hosting.md).

### 8.1 Antes de entregar

- [ ] La batería de pruebas pasa, incluidas las pruebas de tus runs y la
      auditoría: `php test/run.php`.
- [ ] Cada acción tiene su `input/<action>.json` y cada ruta su rol
      mínimo: `php nyuRun setting fullList <profile>` no muestra nada
      abierto que no deba estarlo.
- [ ] Tu front-end oculta lo que el rol de un usuario no puede alcanzar,
      ya que el servicio lo responde como «no encontrado».

### 8.2 Entregar una copia, no la carpeta

- [ ] Construye la copia de producción: `php nyuRun bundles final --archived`.
      No contiene secretos, ni ajustes locales, ni clave privada, ni
      pruebas. Su `system.ini` está ajustado para producción (`PROD`,
      errores ocultos, registros `MINI`, caché de configuración activada),
      y sus clases quedan mapeadas cuando Composer está disponible.
- [ ] Instálala en el servidor con `php nyuRun install complete`: los
      secretos y las claves se crean allí y nunca salen de allí.
- [ ] Deja `.env` legible solo por su propietario (`chmod 600 .env`).

### 8.3 El servidor

- [ ] El servidor web sirve `www` y nada más, y envía cada URL a
      `www/index.php` ([Alojamiento, «Servidor web»](hosting.md#servidor-web)).
- [ ] HTTPS lo gestiona el servidor web o un proxy situado delante. Detrás
      de un proxy, `PRX_LIST` nombra ese proxy, y solo ese.
- [ ] PHP es una versión con sus últimas correcciones de seguridad, y
      `php.ini` tiene `session.upload_progress.enabled = 0`.
- [ ] El usuario con el que funciona el servidor web puede escribir en
      `temp/`, y en ningún otro sitio.

### 8.4 Los ajustes

- [ ] Hay una cuenta de correo real en `mailer.json`, con su contraseña
      en `.env`, y `nativeDb.linkTmpl` es una URL `https` de tu front-end.
- [ ] `php nyuRun install testMail <your address>` te ha hecho llegar un
      correo.
- [ ] El límite de intentos fallidos sigue activado (`tryLimit` en
      `access.json`).
- [ ] Tienes al menos dos gestores, ya que un gestor no puede restablecer,
      bloquear ni eliminar su propia cuenta.
- [ ] Después de editar a mano un archivo de `conf/`, has ejecutado
      `php nyuRun setting clrCache`. Con la caché activada, el servicio
      sigue leyendo los ajustes antiguos hasta que lo hagas.
      `install checkAll` avisa de una caché más antigua que sus archivos.

### 8.5 Antes de la puesta en marcha, y después

- [ ] `php nyuRun install checkAll` termina con `fail: 0`. Ante un fallo,
      sale con un código de error, así que un script de despliegue se
      detiene ahí mismo.
- [ ] Haz copia de seguridad de lo que no se puede reconstruir: la base de
      datos, `.env`, `conf/keys` y tus archivos locales de `conf/`.
- [ ] Lee los registros de vez en cuando (`php nyuRun showlog access`), y
      limpia los antiguos
      (`php nyuRun cleanup cleanDir logFiles --keepDays=30`).
- [ ] Para actualizar, construye una copia nueva y ponla encima del
      código. `.env`, las claves, tus archivos locales de `conf/` y los
      datos se quedan como están; después ejecuta
      `php nyuRun setting clrCache`. La copia trae su propio `system.ini`,
      sus perfiles y sus rutas, así que un ajuste que hayas cambiado en el
      servidor tendrás que volver a cambiarlo, o guardarlo en un modo del
      proyecto (`bundles makeMode`).

---

## A. Resolución de problemas

### A.1 Dónde mirar

1. **La respuesta.** En desarrollo (`ERR_SHOW = TRUE`, y nunca en `PROD`),
   un error de código aparece directamente en la respuesta, con su archivo
   y su línea.
2. **Los registros** de `temp/logs`, un archivo por tema: `error.log`
   (errores de código), `access.log` (inicios de sesión, rechazos y su
   motivo), `runner.log` (lo que no se encontró y por qué, correos,
   eliminaciones, subidas), `shell.log` (lo que cambiaron los comandos).
   `php nyuRun showlog <channel>` muestra las últimas líneas.
3. **`php nyuRun install checkAll`** para los ajustes (A.3).
4. **`php nyuRun setting fullList <profile>`** para saber quién llega a
   qué.

### A.2 Respuestas que te sorprenden

| Recibes | Por qué | Qué hacer |
|---|---|---|
| `404` en una acción que acabas de escribir | al cliente nunca se le dice por qué. Quizá la acción no es un método público de `Actions`, la carpeta o la clase de su run tienen un nombre incorrecto, su run tiene su propio archivo de rutas, así que solo esas rutas llegan a ella, o al usuario le falta el rol | mira `runner.log` (`Not found: "notes/view": …`) o `access.log` (`role … needed`) |
| `500 Internal Server Error` en la URL de un run sin acción | la acción por defecto (`$dfltAction`) no nombra ninguna acción, o no está definida | `runner.log` la nombra |
| `500 Access misconfigured` en cada petición | un ajuste de acceso no se puede aplicar: un rol desconocido en `minRoles`, una ruta abierta y con rol a la vez, un portador y una verificación que no funcionan juntos, o una ruta de inicio de sesión que no es una ruta del sitio | `access.log` dice cuál (`Access misconfigured: …`) |
| `500` que nombra `Input misconfigured` | un `input/<action>.json` que no es `{ "fieldSet": … }`, o que nombra un formato que no existe | corrige el archivo; los formatos vienen de `nyu\Tools\Variable` |
| `400 Missing or invalid input: …` | faltaba un campo obligatorio (`name!`) o estaba vacío | envíalo, y comprueba su nombre |
| `401 Missing Bearer Authorization header` | el perfil pide un token | inicia sesión y envía `Authorization: Bearer <token>` |
| `401 Invalid credentials` con la contraseña correcta | la cuenta no está activa (`awaiting`, `inReview` o `disabled`) | `php nyuRun account fullList` muestra su estado |
| `400 Password sent in the query string` | la contraseña ha llegado en la URL | envíala en el cuerpo, con `POST` |
| `405 Method Not Allowed` | la ruta no acepta ese método | su cabecera `Allow` enumera los que acepta |
| `413 Payload Too Large` | el cuerpo es más grande que `REQ_SIZE`, o tiene más partes que `REQ_PART` | súbelos en `system.ini` si es lo que quieres |
| `429 Too many attempts` | esa dirección ha enviado demasiadas credenciales erróneas | espera `Retry-After` segundos, o ejecuta `php nyuRun cleanup freeAddr <address>` |
| `501 Tokens are issued by another server` | el perfil solo verifica tokens firmados en otro sitio | inicia sesión en el servicio que los emite |
| un cambio en `conf/` no se nota | la caché de configuración está activada (`CNF_CACH`) | `php nyuRun setting clrCache` |
| `"mailed": true`, pero no llega nada | el correo falló después de enviarse la respuesta | `runner.log` dice por qué; con una cuenta `emlFiles`, mira en `temp/mails` |
| en cada petición: `Class "nyu\Runner" not found` | el servidor integrado de PHP se arrancó fuera de `www` | arráncalo desde `www` (3.3) |

### A.3 Las filas de `install checkAll`

Un `fail` significa que el servicio no puede servir, o está expuesto. Un
`warn` merece un vistazo.

| Fila | Dice | Haz |
|---|---|---|
| `php` | anterior a 8.2 | actualiza PHP |
| `php patch`, `upload progress` | a PHP le faltan sus últimas correcciones, o el progreso de subida está activado | actualiza PHP; pon `session.upload_progress.enabled = 0` en `php.ini` |
| `extension json`, `extensions` | falta una extensión obligatoria u opcional | consulta A.4 |
| `folder temp/…` | no existe o no se puede escribir en ella | deja que el usuario del servidor web escriba en `temp/` |
| `access` | falta `conf/access.json` | `php nyuRun install makeConf` |
| `access login` | no hay inicio de sesión configurado, o el que hay nombra una verificación o un bloque que no existen | corrige el bloque `loginSet` de `access.json` |
| `preset <name>` | el perfil no se carga (una clave desconocida, un rol que no es un rol, una ruta abierta con un rol) | la fila dice cuál; corrige el archivo `.rps` |
| `entry index` | la entrada arranca un perfil que no pide credenciales | nombra un perfil que verifique el acceso (4.1), o pon `"isPublic": true` en su bloque `security` si el sitio debe estar abierto |
| `origins <profile>` | cualquier sitio puede llamar a la API desde un navegador | enumera los orígenes permitidos en `corsList` |
| `route clash`, `route shadow` | dos runs declaran la misma ruta, o una ruta del sitio oculta la ruta de un run | cambia el nombre de una de las rutas, o cambia el montaje de un run |
| `access route` | `openList` o `minRoles` nombran una ruta que no existe | corrige la entrada en el perfil |
| `token claims …` | los tokens no llevan emisor ni audiencia, así que no están ligados a este proyecto | `php nyuRun setting setValue accsConf jwtToken.issuerId …` y `.audience` |
| `login marks` | no hay clave para las marcas de inicio de sesión, así que poner una cuenta en espera también deja fuera a su titular | `php nyuRun setting mkSecret markSecr` |
| `file rights` | no se pueden comprobar los permisos de los archivos secretos (Windows) | limita `conf` y `temp` a la cuenta del servidor web (`icacls`) |
| `secret …`, `token keys …`, `2FA key …` | falta un secreto o una clave, es demasiado corto, no es una clave válida, o es una clave pública que no forma pareja con su clave privada | `php nyuRun setting mkSecret jwtToken` (o `keysList`, `tfaCrypt`) |
| `database …` | la base de datos no responde, o su extensión no está cargada | comprueba `conf/database.json`, el servidor, y A.4 |
| `users` | falta la tabla o el archivo de usuarios | `php nyuRun install userBase` |
| `access mails …`, `access link …`, `access codes …` | la cuenta de correo, el enlace o la longitud de los códigos no son válidos | consulta 4.4 |
| `sign-ups …` | las altas están abiertas, pero no hay correo para confirmarlas | nombra una cuenta de correo en `nativeDb.mailAcnt`, o cierra las altas |
| `config cache` | la caché es más antigua que un archivo cambiado desde entonces | `php nyuRun setting clrCache` |
| `table cache` | `TBL_CACH` está activado sin `CNF_CACH` | activa los dos, o desactiva los dos |
| `error level` | `ERR_LEVL` contiene algo que no es un nivel de error | usa nombres `E_*` (`E_ALL & ~E_DEPRECATED`, entre comillas) |
| `mail language` | `DEF_LANG` no es una etiqueta de idioma | `en`, `fr`, `fr-ch`, … |
| `proxies` | `PRX_LIST` confía en todas las direcciones | enumera solo tus proxies |
| `.env`, `key rights …` | otros usuarios del sistema pueden leerlos | `chmod 600` |
| `cookie …`, `origins …` | una cookie de sesión por HTTP sin cifrar en producción, o todos los sitios autorizados a llamar | pon `secureOn` a true, y enumera los orígenes (`corsList`) |
| `limits` | el límite de intentos fallidos está desactivado | da un número a `tryLimit.maxTries` |
| `requests` | `REQ_SIZE` es 0, así que se lee un cuerpo de cualquier tamaño | dale un tamaño |

### A.4 Una extensión que falta

| Falta | Lo que ves |
|---|---|
| `json` | no se responde nada, e `install checkAll` falla enseguida |
| `dom`, `iconv`, `zlib`, `mbstring` | `composer install` se niega a instalar las bibliotecas, y los documentos PDF y HTML no se pueden leer |
| `openssl` | no se pueden crear tokens RS256 (`token keys …: RS256 needs the openssl extension`), y los secretos de doble factor no se pueden cifrar |
| `pdo_sqlite`, `pdo_mysql`, `pdo_pgsql` | `database …: the extension … is not loaded`; sin `pdo_sqlite`, las pruebas `data` y `http` se saltan |
| `gd`, `imagick`, `zip`, `exif` | las imágenes no se pueden modificar, los SVG no se dibujan, y los documentos ofimáticos y los datos de las fotos no se pueden leer; `install checkAll` los enumera en `extensions` |

Activa una extensión en `php.ini` (`extension=openssl`) y compruébala con
`php -m`. A un PHP de línea de comandos que no carga ningún `php.ini` se
le puede dar uno mediante la variable de entorno `PHPRC`.

---
