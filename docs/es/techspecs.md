# nyuRuns — Especificaciones técnicas

Versión 1.0

## Índice

1. [Introducción](#1-introducción)
2. [Pautas y recomendaciones](#2-pautas-y-recomendaciones)
3. [Requisitos del sistema](#3-requisitos-del-sistema)
4. [El ciclo de vida de una petición](#4-el-ciclo-de-vida-de-una-petición)
5. [Componentes](#5-componentes)
6. [Archivos de configuración](#6-archivos-de-configuración)
7. [Línea de comandos](#7-línea-de-comandos)
8. [Construcción de los runs (aplicaciones)](#8-construcción-de-los-runs-aplicaciones)
9. [Seguridad](#9-seguridad)
10. [Capa de datos](#10-capa-de-datos)
11. [Estructura de carpetas](#11-estructura-de-carpetas)
12. [Convenciones de código](#12-convenciones-de-código)
13. [Pruebas](#13-pruebas)

---

## 1. Introducción

### 1.1 Qué es nyuRuns

nyuRuns es un microframework PHP para construir servicios de API RESTful:
el back-end de una aplicación, desde la petición HTTP que recibe hasta la
respuesta que envía.

La idea es que los desarrolladores escriban lo que hace su aplicación y
dejen al framework el manejo de la petición. Para cada petición, nyuRuns
encuentra la ruta, comprueba quién pide y qué tiene permitido hacer, se
queda solo con las entradas que declara la acción (cada una en su formato),
llama a la acción y escribe la respuesta en el formato pedido, errores
incluidos. Alrededor de eso, aporta lo que necesitan la mayoría de los
servicios: bases de datos a través de modelos, documentos leídos y escritos
en muchos formatos, imágenes, sonidos, correo, registros y una línea de
comandos para instalar, configurar, probar y entregar un proyecto.

Unos pocos principios le dan forma:

- **MDD** (desarrollo dirigido por modelos): una aplicación trabaja a
  través de modelos de sus datos, registros y listas, en lugar de a través
  de consultas.
- **KISS** (mantenlo simple): no se carga nada hasta que hace falta, y una
  primera instalación no necesita ninguna base de datos.
- **REST**: a los recursos se llega por su ruta, el método dice lo que se
  hace y el estado dice cómo ha ido.
- **CRUDL**: crear, leer, modificar, borrar y listar, las operaciones que
  recibe cada fuente de datos.

### 1.2 A quién va dirigido este documento

Está escrito para desarrolladores: quienes construyen aplicaciones sobre
nyuRuns y quienes trabajan en el propio nyuRuns. Describe cómo está
construido el framework: sus componentes, los archivos que lo configuran y
sus convenciones.

Los otros documentos cubren el resto:

| Documento | Cubre | Para |
|---|---|---|
| Requisitos | lo que nyuRuns debe lograr, y por qué | desarrolladores, analistas, responsables de producto |
| Especificaciones funcionales | lo que hace nyuRuns, y cómo transcurren sus procesos | desarrolladores, analistas |
| Guía del usuario | instalarlo y construir una primera aplicación | desarrolladores |
| Referencia del código (en inglés) | cada clase y cada método, generados a partir del código | desarrolladores |
| [Alojamiento](hosting.md) | lo que debe aportar el servidor, y cómo configurarlo | quien aloje nyuRuns |

Cuando un tema se trata por completo en otro sitio, este documento enlaza a
él en lugar de repetirlo.

### 1.3 Las palabras de este documento

- **Run**: una aplicación construida sobre nyuRuns, en una carpeta de
  `runs/`.
- **Acción**: lo que hace un run para una petición, un método público de su
  `Actions.php`, al que se llega por una ruta como `shows/view/12` o por una
  ruta que declara el run.
- **Perfil** («preset» en el código): un perfil del framework (cómo se
  comprueban, se enrutan y se responden las peticiones), elegido para cada
  petición por el mapa de los perfiles servidos (`conf/doorways.lst`).
- **Almacenamiento**: donde se guardan los datos: una base de datos o un
  archivo de usuarios.
- **Bundle**: una copia de un proyecto hecha para un entorno.
- Los nombres escritos `así` son nombres del código, de la configuración o
  de la línea de comandos, exactamente como se escriben allí.

### 1.4 Lo que incluye la versión 1.0

**Peticiones y respuestas.** Todo el ciclo de vida, del punto de entrada a
la respuesta. Las rutas se encuentran a partir de la ruta de la URL, o las
declaran el sitio y cada run, y pueden llevar a una acción, una
redirección, un archivo o una carpeta estáticos, o una página HTML. La
definición de cada acción conserva y da formato a sus entradas, y un campo
obligatorio que falta recibe un 400. Las respuestas salen en JSON, YAML,
XML, CSV, HTML o en salida en bruto. Los errores se escriben en el formato
de la respuesta, se registran y nunca se detallan en producción.

**Configuración.** Perfiles, cada uno un perfil del framework; un archivo
de sistema para los registros, los errores, las cachés, los proxies y los
límites; secretos guardados en un archivo de entorno, aparte de la
configuración.

**Acceso.**
- Formas de llevar una identidad: la autenticación Basic, una clave de API,
  un token firmado, una sesión PHP, un certificado de cliente.
- Formas de verificarla: un archivo de contraseñas, claves de API, una
  tabla de usuarios, un archivo de usuarios, tokens de dispositivos, claves
  de aplicación, o una verificación que aporta el proyecto.
- Roles, y un límite de intentos fallidos por dirección y por cuenta.
- Códigos de doble factor, desde una app de autenticación o por correo.
- Tokens firmados con un par de claves, ligados a un emisor y a una
  audiencia una vez configurados, con sus claves públicas publicadas
  (`/.well-known/jwks.json`).
- Usuarios gestionados desde una aplicación o desde la línea de comandos,
  altas aprobadas por un gestor y contraseñas restablecidas por los propios
  usuarios.

**Datos.** Bases de datos con nombre en MySQL, MariaDB, PostgreSQL, SQLite
y MongoDB; registros y listas, con filtros escritos de la misma forma para
todas; transacciones; valores binarios leídos y enviados trozo a trozo;
identificadores que se ordenan por fecha (UUID v7).

**Documentos y medios.** Lectura y escritura de documentos de texto,
Markdown, HTML, XML, JSON, YAML, CSV, iCalendar, PDF, Word, Excel,
OpenDocument y ePub; imágenes, dibujos y las imágenes que contienen los
documentos; sonidos, cortados y convertidos con ffmpeg; códigos QR.

**Correo.** Cuentas de correo que envían por SMTP, por PHPMailer o a
archivos; los correos de acceso (activación, alta, restablecimiento,
códigos de inicio de sesión), escritos a partir de plantillas en el idioma
de cada usuario.

**Línea de comandos.** `php nyuRun`, para instalar un proyecto en su
servidor, cambiar su configuración, lanzar las pruebas, limpiar lo que va
dejando, gestionar sus usuarios, dispositivos y claves de aplicación, y
construir una copia para cada entorno.

**Calidad.** Una batería de pruebas de unas 4 500 comprobaciones, lanzada
sobre SQLite y sobre servidores reales de MySQL, MariaDB, PostgreSQL y
MongoDB, y una auditoría de las convenciones de código.

### 1.5 Lo que queda para más adelante

**Versión 1.1:**
- los conectores SSO y LDAP, y un servidor de autenticación;
- códigos por SMS como segundo factor, y un inicio de sesión aprobado desde
  el teléfono;
- certificados de cliente presentados a través de un proxy, un perfil para
  ellos, y el anclaje de certificados;
- límites por ruta;
- fuentes de entradas distintas de la petición: archivos, registros, otras
  API, la sesión, un flujo;
- sockets;
- varias uniones en una misma lista, y la codificación de las entradas;
- lectura de texto enriquecido y de código fuente, de escritura a mano y de
  texto escaneado;
- mezcla de sonidos: forma de onda, sonoridad, fundidos, tempo.

**Un proyecto aparte, con nombre en clave nyuTron:** un asistente visual
alrededor de `makerun`, un asistente de instalación, un gestor de ajustes
avanzados y kits de datos listos para usar (socios, clientes, pedidos,
tareas, proyectos). Son herramientas alrededor de nyuRuns, no partes de él.

### 1.6 En qué difiere la 1.0 de la primera visión

La primera descripción de nyuRuns, en sus Requisitos, prometía en algunos
puntos más de lo que entrega la 1.0. Lo que ha cambiado:

- **CRUDL listo para usar.** Los modelos dan a cada fuente de datos sus
  operaciones CRUDL, pero un run sigue declarando sus acciones: ningún
  endpoint sirve una tabla solo con la configuración. En su lugar,
  `makerun` escribe esas acciones a partir de una tabla SQL (7.10).
- **Archivos como fuentes de datos.** Una acción lee y escribe archivos
  como documentos. Usarlos como fuente de entradas configurada, junto a la
  petición, queda para la versión 1.1.
- **Sockets.** Al principio figuraban entre los gestores de datos, y quedan
  para la versión 1.1. Los datos grandes se siguen leyendo y enviando trozo
  a trozo.
- **Caché.** Los archivos estáticos salen con su versión y su fecha, y un
  cliente que ya tiene uno recibe un 304. Las respuestas de datos no se
  guardan en caché: los perfiles las envían con cabeceras no-cache. Lo que
  se guarda en caché, en cambio, es lo que el framework lee en cada
  petición: la configuración, las rutas y las descripciones de las tablas
  (6.2, `CNF_CACH`, `TBL_CACH`).

---

## 2. Pautas y recomendaciones

Lo que un desarrollador debe hacer, y evitar, al construir y entregar un
proyecto sobre nyuRuns. Lo que debe aportar el propio servidor (servidor
web, proxy inverso, TLS, `php.ini`) se trata en [Alojamiento](hosting.md).

### 2.1 Empezar ligero, crecer cuando haga falta

Un proyecto nuevo no necesita ninguna base de datos. Su configuración de
acceso de ejemplo inicia la sesión de los usuarios con un archivo de
usuarios (`conf/users.lst`), que `php nyuRun install userBase` crea con sus
usuarios básicos: dos (el rol más bajo y los gestores) o uno por rol. La
aplicación solo lee ese archivo. Cuando los usuarios necesitan cambiar su
propia contraseña, recibir sus códigos por correo, darse de alta por su
cuenta o tener dispositivos, un comando los traslada a una tabla de
usuarios en una base de datos:

```
php nyuRun install moveUser nativeDb
```

Cada usuario conserva su identificador, su contraseña, su rol, su estado,
su dirección, su idioma y su secreto de doble factor. El archivo de
usuarios se queda como estaba, así que el traslado se puede deshacer. Los
dispositivos, y su tabla, solo llegan con un bloque `dvcToken` en
`conf/access.json`.

### 2.2 Un bundle por entorno

Nunca copies un proyecto a un servidor a mano: la carpeta del proyecto
contiene sus secretos, sus pruebas y sus documentos. Entrega en su lugar un
bundle, construido para el entorno al que va:

```
php nyuRun bundles final
```

Un bundle contiene el código, los ejemplos de configuración y las
bibliotecas, y nada más, y su `system.ini` está ajustado para su entorno.
En el servidor, `php nyuRun install complete` crea sus archivos locales,
sus secretos y sus usuarios, y `php nyuRun install checkAll` dice lo que no
está listo. El diagnóstico termina con un código de fallo cuando falla una
fila, así que un script de despliegue puede detenerse ahí.

El framework conoce cuatro entornos, fijados por `ENV_NAME`. Sus propios
modos de bundle los fijan así:

| Modo | `ENV_NAME` | `ERR_LEVL` | `ERR_SHOW` | `LOG_MODE` | `CNF_CACH` | Mapa de clases |
|---|---|---|---|---|---|---|
| `local` | `DEVS` | `E_ALL` | `TRUE` | `FULL` | `FALSE` | el de Composer, tal como se instaló |
| `stage` | `PREV` | `E_ALL` | `FALSE` | `BASE` | `TRUE` | completo, con autoridad |
| `final` | `PROD` | `E_ALL` | `FALSE` | `MINI` | `TRUE` | completo, con autoridad |

En producción, los registros guardan los errores, los avisos y las
notificaciones: los inicios y cierres de sesión y los rechazos, pero
ninguna línea por cada acceso de un usuario estándar.

Un proyecto con servidores propios entre medias (un servidor de pruebas o
de integración, una vista previa para un cliente) declara sus modos con
`php nyuRun bundles makeMode`, y los construye como los demás. Un modo se
nombra libremente, como una acción: letras minúsculas y dígitos, de
cualquier longitud (`qa`, `preview`, `integration`). Para qué sirve, lo
lleva como su entorno, `ENV_NAME` (`DEVS`, `TEST`, `PREV`, `PROD`), que
pregunta `makeMode`.

### 2.3 Guardar los secretos en un solo sitio

Todos los secretos viven en `.env`: el secreto o las claves de los tokens,
las claves de API, la clave de los secretos de doble factor, las
contraseñas de la base de datos y del correo. Los archivos de configuración
solo los nombran (`env:NYU_DB_PASS`, `env:NYU_MAIL_PASS`).

- Nunca hagas commit de `.env`, ni de la configuración local creada a
  partir de sus ejemplos: `conf/access.json`, `conf/database.json`,
  `conf/mailer.json`, `conf/users.lst`, `conf/keys/`. Git los ignora, y los
  bundles los dejan fuera.
- Crea los secretos con la línea de comandos en lugar de a mano. Los hace
  lo bastante largos y los escribe donde la configuración los lee
  (`php nyuRun setting mkSecret jwtToken`, `keysList`, `tfaCrypt`,
  `markSecr`).
- Dale un `tfaCrypt` a la base de usuarios. Sin él, los secretos de doble
  factor se guardan legibles, y el diagnóstico lo dice.

### 2.4 Enviar los códigos por correo en lugar de entregarlos en mano

Un usuario activado o restablecido por un gestor necesita un código para
elegir una contraseña. Sin cuenta de correo, el código vuelve en la
respuesta al gestor (a través de la aplicación o de la línea de comandos),
que lo transmite. Las altas y los restablecimientos que piden los propios
usuarios quedan entonces cerrados (404). Con una cuenta de correo, los
usuarios de la base de usuarios nativa reciben sus códigos directamente.
`install complete` pregunta todo esto una vez elegida la base de usuarios
nativa (la cuenta, el enlace, las altas), e `install testMail <address>`
comprueba que la cuenta envía:

- Nombra una cuenta de `conf/mailer.json` en la clave `mailAcnt` del bloque
  `nativeDb`. Un código enviado por correo dura `codeMins` minutos (15). Uno
  entregado por un gestor dura más, ya que pasa por una persona.
- Ajusta `linkTmpl` a la página del front-end que recibe el código
  (`%sentCode%`, más `%mailKind%` y `%userName%` si hacen falta), para que
  el correo lleve un enlace además del código. Haz que sea una dirección
  `https`.
- En desarrollo, envía a través de una cuenta `emlFiles`: los correos se
  escriben en `temp/mails`, y ninguno sale de la máquina.
- Abre las altas (`signupOn`) solo con una cuenta de correo: la dirección
  confirma el alta, y después un gestor la aprueba. El bloque `tryLimit`
  las limita por cliente (`maxPerIp`) y en total (`maxQueue`), y avisa por
  correo a los gestores cuando se acumulan (`alertsAt`). Abre
  `auth/signup`, `auth/active`, `auth/forgot` y `auth/recover` en
  `openList`, y nada más del run `Auth` de lo que necesite el front-end.
- Las respuestas a un alta y a una contraseña olvidada son las mismas sea
  cual sea el usuario. Mantenlo así en el front-end, que no debe revelar si
  un nombre o una dirección son conocidos.
- Cada correo sale en el idioma de su usuario, o si no en `DEF_LANG`. El
  framework trae sus plantillas en inglés y en francés
  (`conf/mails/<language>/`). Un run escribe las suyas, con su redacción y
  su aspecto, en `runs/<App>/mails/<language>/`, para cada idioma que hablen
  sus usuarios.

### 2.5 Firmar los tokens con un par de claves

La configuración de ejemplo firma los tokens con RS256: una clave privada
los firma, y su clave pública los verifica. Guarda la clave privada en el
servidor que emite los tokens, y en ningún otro sitio. Una aplicación que
solo verifica tokens recibe únicamente la clave pública: no emite ninguno,
y su inicio de sesión responde 501. HS256, un único secreto compartido por
todos los que verifican los tokens, sigue ahí para un proyecto hecho de una
sola aplicación.

Ese reparto es el perfil que `install complete` pregunta primero, y que
`install checkAll` vuelve a leer a partir de `conf/access.json` y del mapa
de los perfiles servidos (fila `profile`): ningún ajuste lo guarda.

| Perfil | Escrito por la instalación | Señalado por el diagnóstico cuando |
|---|---|---|
| `allInOne` | como antes: la base de usuarios, la base de datos, las cuentas, las claves de los tokens | — |
| `authServ` | como `allInOne`, más RS256 con su par de claves, el `issuerId` (se propone el nombre del host) y la `audience` (los proyectos que aceptan sus tokens) | no tiene `issuerId` o no tiene `audience` |
| `dataServ` | sin base de usuarios y sin usuarios; la clave pública del servidor de autenticación copiada en `conf/keys/<block>.pub`, nombrada solo por `publFile` con `"external": true`, su `issuerId`, la `audience` que nombra este proyecto, y un `loginSet` sin `authWith` | no tiene `issuerId` o no tiene `audience`, o falta el archivo de su clave pública |
| `openSite` | sin base de usuarios, sin cuentas, sin secretos, un `loginSet` sin `authWith`; el mapa propone `/=statics`, y cada uno de sus perfiles se abre (`"required": false`, `"isPublic": true`), nunca uno que pueda llegar a una acción | falla cuando un perfil abierto del mapa puede llegar a una acción (una ruta declarada hacia una acción, o las rutas automáticas con un run que sus rutas no cierran); avisa cuando un perfil del mapa comprueba el acceso |

Una clave pública sola junto a una base de usuarios no forma ninguno de los
cuatro, y también se señala. `statics` no sirve ningún run:
`"runMount": { "*": false }`, donde `*` designa cada run que no tiene una
línea propia. Cuando la instalación da una base de usuarios a un sitio
abierto, los perfiles de su mapa marcados `isPublic` se vuelven a cerrar
(`"required": true`, `isPublic` quitado) y se nombran. Sin
`conf/access.json`, un perfil que comprueba el acceso responde 500
`Access misconfigured`. La clave copiada no sigue al servidor de
autenticación: cuando ese servidor crea claves nuevas, vuelve a copiar la
nueva clave pública, y la anterior (`<block>.old.pub`) como `prevFile`
mientras tanto ([Alojamiento §8](hosting.md#8-tokens)).

Un token sigue siendo válido hasta que caduca, y no se puede retirar antes.
Mantén su vida corta (`lifeSecs`, una hora en la configuración de ejemplo),
o nombra una base de usuarios en el perfil, para que un usuario bloqueado
en la base se rechace de inmediato.

### 2.6 Mostrar los errores donde ayudan, nunca en producción

En desarrollo, los errores se muestran en la respuesta y se registran
completos. En producción, un cliente nunca ve nada de un error, diga lo que
diga `ERR_SHOW`, y el registro guarda los detalles. Dos ajustes deciden qué
se notifica siquiera:

- `ERR_LEVL` elige los errores que notifica PHP. Lo que deja fuera ni se
  muestra ni se registra. Mantén `E_ALL` en desarrollo, y en producción
  deja fuera solo lo que no es un fallo (`"E_ALL & ~E_DEPRECATED"`).
- `LOG_MODE` elige cuánto de lo notificado se escribe: `FULL` añade las
  líneas de depuración y su contexto, `BASE` se queda en la información, y
  `MINI` guarda los errores, los avisos y las notificaciones.

### 2.7 Declarar las entradas de cada acción

Una acción solo recibe los campos que lista su definición, en
`runs/<App>/input/<action>.json`, cada uno con su formato; los demás se
dejan fuera. Da una definición a cada acción, incluso a una sin entradas.

- Marca un campo como obligatorio con un `!` al final de su nombre
  (`"userName!"`) cuando la acción no puede funcionar sin él: una petición
  sin él recibe un 400.
- Lista una contraseña con un formato vacío, para que se conserve
  exactamente como se envió.
- `OutText` está pensado para lo que se muestra, no para lo que va a una
  consulta: codifica las comillas y quita las etiquetas.
- Un filtro compara un valor tal cual. Una coincidencia con comodín se pide
  por el nombre del campo (`title~`), y solo `*` es un comodín. Una
  búsqueda tomada de una petición entra protegida:
  `'*'.Filter::quoteMatchText($search).'*'`, para que nunca sea un patrón.

### 2.8 Dar a cada ruta el menor acceso que necesite

- Abre una ruta a todo el mundo solo a propósito, en el `openList` del
  perfil. Todas las demás rutas piden una credencial.
- Pide un rol por ruta en el perfil (`minRoles`), o en la propia acción
  (`needsActionRle()`). Las dos cosas deben cumplirse. Un usuario sin el
  rol recibe un 404, como para una ruta que no existe, así que un usuario
  estándar no puede trazar el mapa de las rutas de los gestores. Evita que
  el front-end las ofrezca.
- Mantén activo el límite por dirección (el bloque `tryLimit`): las
  contraseñas, claves y códigos erróneos de una dirección la dejan entonces
  en espera un rato.
- Nunca bases una decisión de acceso en lo que el cliente dice de sí mismo
  (su agente, su sistema, su idioma). Solo su dirección es algo que el
  servidor lee por sí mismo.

### 2.9 Elegir el formato de las respuestas

Un perfil escribe sus respuestas en un solo formato (`jsonData` salvo que
se indique otro). Una acción que necesita otro, una exportación CSV por
ejemplo, elige el formato de su propia respuesta
(`setReplyFormat('csvTable')`). Nombra los sitios autorizados a llamar
desde un navegador (`corsList`) en lugar de autorizarlos todos.

---

## 3. Requisitos del sistema

Lo que necesita un servidor para ejecutar un proyecto nyuRuns, y lo que
añade cada parte opcional. En el propio servidor,
`php nyuRun install checkAll` dice lo que falta.

### 3.1 PHP

PHP 8.2 o posterior, en el servidor web y en la línea de comandos, la misma
versión en los dos. La batería de pruebas se ejecuta en la versión más
antigua admitida y en la más reciente (13.5).

Se espera una versión que corrija php2shell (CVE-2026-109618,
CVE-2026-109619): 8.2.35, 8.3.36, 8.4.27, 8.5.12 o posterior, o el paquete
de una distribución con la corrección incorporada. Conviene desactivar
`session.upload_progress.enabled` en `php.ini`: el framework no lo usa, y
arrastra CVE-2026-115560. `install checkAll` avisa de las dos cosas.

La línea de comandos ejecuta otros programas (ffmpeg, las pruebas) a través
de `proc_open()`. Un servidor que la desactiva en `php.ini`
(`disable_functions`) pierde los sonidos y el lanzador de pruebas, y nada
más.

### 3.2 Extensiones PHP

| Extensión | Necesaria para | Sin ella |
|---|---|---|
| `json` | todo: la configuración, las entradas, las respuestas | no se lee ni se responde nada |
| `dom` | los documentos HTML y XML, la escritura de PDF | esos documentos no se pueden leer ni escribir |
| `iconv` | las codificaciones de texto de los documentos y los sonidos | las bibliotecas no se pueden instalar |
| `zlib` | las partes comprimidas de los documentos PDF | las bibliotecas no se pueden instalar |
| `mbstring` | la lectura y escritura de documentos PDF | los documentos PDF no se pueden leer ni escribir |
| `openssl` | los tokens firmados con RS256, los secretos de doble factor cifrados, el correo cifrado | la configuración de ejemplo no puede firmar tokens; los secretos de doble factor se guardan legibles |
| `pdo_mysql` | las bases MySQL y MariaDB | esas bases no se pueden usar |
| `pdo_pgsql` | las bases PostgreSQL | lo mismo |
| `pdo_sqlite` | las bases SQLite, y los grupos de pruebas `data` y `http` | lo mismo, y esas pruebas se saltan |
| `mongodb` | las bases MongoDB | lo mismo |
| `zip` | los documentos Office, OpenDocument y ePub | esos documentos no se pueden leer ni escribir |
| `gd` | modificar imágenes: redimensionar, recortar, girar, limpiar | las imágenes se leen, no se modifican |
| `imagick` | dibujar imágenes SVG como PNG, JPEG o WebP | las imágenes SVG no se dibujan |
| `exif` | lo que registra una foto: la fecha, la cámara, el lugar | esa información no se lee |

`json`, `iconv`, `zlib` y `dom` vienen con la mayoría de las compilaciones
de PHP. Las bibliotecas del framework exigen `dom`, `mbstring`, `iconv` y
`zlib`, así que Composer solo las instala en un PHP que tenga las cuatro.
Un bundle lleva sus bibliotecas, así que el servidor al que va nunca
ejecuta Composer.

### 3.3 Bibliotecas

Instaladas por Composer en `vendor/`, y llevadas por cada bundle:

| Biblioteca | Versión | Sirve para |
|---|---|---|
| `level-2/dice` | 4.0 | el contenedor de inyección de dependencias |
| `firebase/php-jwt` | 7.2 | los tokens firmados |
| `dompdf/dompdf` | 3.1 | escribir documentos PDF |
| `smalot/pdfparser` | 2.12 | leer documentos PDF |

`phpmailer/phpmailer` se sugiere, no se exige: una cuenta de correo
`phpMailr` lo necesita para enviar adjuntos. Las cuentas `selfSmtp` y
`emlFiles` no necesitan nada más. Las bibliotecas y sus licencias se
listan en `NOTICE`.

### 3.4 Otros programas

- **ffmpeg**, para los sonidos: descomprimirlos, cortarlos y convertirlos.
  Su ruta completa la da `SND_TOOL` (en `.env`, mediante
  `env:NYU_SND_TOOL`), y `SND_SECS` limita cuánto tiempo puede ejecutarse
  (60 segundos). Sin él, un sonido se sigue leyendo (su tipo, sus etiquetas
  y su imagen de portada), sus etiquetas se pueden quitar, y un sonido WAV
  o MP3 se puede cortar; los demás tipos no se pueden convertir ni cortar.
- **Un servidor de correo** que hable SMTP, para las cuentas `selfSmtp` y
  `phpMailr`: en el puerto 465 con TLS, o en otro puerto con STARTTLS. Solo
  con un servidor en la misma máquina se habla sin cifrar.

### 3.5 Bases de datos

No hace falta ninguna: un proyecto inicia la sesión de sus usuarios desde
el archivo de usuarios hasta que los traslada a una base de datos (2.1).
La pasada de pruebas de la 1.0 se ejecutó en estos servidores:

| Servidor | Versiones |
|---|---|
| MySQL | 5.7, 8.4 |
| MariaDB | 13.0 |
| PostgreSQL | 18 |
| SQLite | la del `pdo_sqlite` de PHP |
| MongoDB | 9.0 |

Las tablas entregadas (`conf/schema`) están escritas para MySQL (MariaDB
incluido), PostgreSQL y SQLite. Las migraciones de las tablas de usuarios y
de dispositivos renombran columnas con `RENAME COLUMN`, que MySQL 5.7 no
tiene: cada archivo de migración de MySQL lleva una variante para él.

### 3.6 Servidor web

Vale cualquier servidor web que pase la petición a PHP: Apache, nginx con
PHP-FPM, u otro. Debe:

- servir solo la carpeta `www`, y nada por encima de ella (`conf`, `runs`,
  `src`, `temp`, `test`, `vendor`);
- enviar todas las rutas a `www/index.php`, que lee la ruta a partir de la
  ruta completa de la URL. Un proyecto se sirve en la raíz de su host, no
  en una subcarpeta de otro sitio (los ajustes de Apache y nginx están en
  [Alojamiento](hosting.md#servidor-web));
- encargarse él mismo de TLS, o dejarlo a un proxy situado delante (ver
  [Alojamiento](hosting.md#3-proxy-inverso)).

### 3.7 Carpetas escritas

El framework escribe en `temp/`: los registros (`temp/logs`, o
`LOG_PATH`), los recuentos del límite de intentos (`temp/limits`), la
caché de configuración (`temp/cache`), las versiones anteriores de los
archivos de configuración que cambia la línea de comandos
(`temp/archives`), y los correos de una cuenta `emlFiles` (`temp/mails`).
Las sesiones PHP se guardan donde las ponga `php.ini`, salvo que el bloque
`phpSessn` nombre una carpeta (`dataPath`). La línea de comandos escribe en
`conf/` y `.env` cuando cambia la configuración. El usuario con el que se
ejecuta el servidor web solo necesita escribir en `temp/`.

---

## 4. El ciclo de vida de una petición

Cada petición pasa por un único objeto, el Runner (`src/Runner.php`),
siempre en el mismo orden. Cualquier paso puede terminar la petición con su
propia respuesta, y los pasos siguientes ya no se ejecutan.

```
www/index.php
  1. Arranque        perfil, configuración, objetos compartidos, registros, errores
  2. Petición        límites de tamaño, entradas
  3. Ruta            petición previa, rutas declaradas, ruta de la URL
  4. Acceso          configuración, dirección en espera, credencial, rol
  5. Acción          respuesta de la ruta, o aplicación, entradas, modelos, acción
  6. Respuesta       estado, cuerpo, formato, cabeceras
```

### 4.1 El arranque

El punto de entrada, `www/index.php`, define las carpetas del proyecto y
crea el Runner. Sin perfil nombrado, el Runner lee primero
`conf/doorways.lst` y elige la línea que coincide con el host y con el
principio de la ruta (`Runner::findPresetName()`); el `basePath` de esa
línea se quita de la ruta antes del enrutamiento, y se guarda como
`routBase`. Sin mapa o sin coincidencia, es `default`.

1. **El perfil** (`conf/presets/<name>.rps`) se lee y se comprueba: una
   clave que no conoce impide que se cargue (`Preset::KEYS_LIST`).
2. **La configuración** la carga el Loader: `conf/system.ini`, cada archivo
   `.json` de `conf/`, y los valores de `.env` que nombran. Con
   `CNF_CACH`, el perfil, esos archivos y las rutas se leen de
   `temp/cache` después de la primera petición; `.env` se sigue leyendo
   cada vez. Los valores que el llamante da al Runner se ponen encima.
3. **Los objetos compartidos** los construye el contenedor
   (`Setup\Builder`, sobre Dice): el registro del servidor (`$_SERVER`,
   vaciado una vez leído), el registro del cliente (su dirección, vista a
   través de los proxies de confianza), la respuesta y la petición.
4. **Los registros** se conectan con la configuración (`LOG_PATH`,
   `LOG_MODE`), junto con el programa que descomprime los sonidos, cuando
   `SND_TOOL` nombra uno.
5. **El gestor de errores** se instala, al nivel de `ERR_LEVL`.

Un fallo antes de que el gestor de errores esté en su sitio no lo puede
formatear ni registrar el framework: va al registro de errores propio de
PHP, y el cliente recibe un simple 500 en JSON.

### 4.2 La lectura de la petición

La petición se lee una vez, cuando se construye: su método, su URL, sus
cabeceras, su cadena de consulta y su cuerpo (JSON, un formulario, o un
cuerpo multipart con archivos, para todos los métodos).

- Un cuerpo mayor que `REQ_SIZE`, o un cuerpo multipart con más partes que
  `REQ_PART`, recibe un 413, y se registra un aviso. Un cuerpo anunciado
  como mayor no se lee en absoluto; uno que resulta mayor no se lee más
  allá del límite.
- El bloque `inputSet` del perfil dice de qué fuentes vienen las entradas.
  Los valores del servidor y del cliente nunca son entradas: una acción los
  lee a través de la petición.

### 4.3 Encontrar la ruta

El Router (`src/Tools/Router.php`) convierte la ruta de la URL en una ruta,
en este orden:

1. **Una petición previa CORS**, una petición `OPTIONS` que pregunta por un
   método, recibe un 204 con las cabeceras del perfil, en cualquier ruta.
2. **Las rutas del punto de entrada**, en un perfil `mapping`.
3. **Las rutas declaradas**: primero las del sitio, en `conf/routes/*.rte`
   (o la carpeta propia del perfil), y luego las que declara cada run en
   `runs/<App>/routes.rte`, montadas bajo el nombre del run o donde diga el
   `runMount` del perfil. Gana una ruta igual a la ruta de la URL, y luego
   el primer patrón que coincida con ella. Una ruta vacía se busca como
   `index`.
4. **La propia ruta de la URL**, en un perfil `autopath`: sus partes reciben
   los nombres del `pathForm` del perfil, por posición
   (`$appToRun/$doAction/$argument`, o sea `shows/view/12`). A un run que
   declara sus propias rutas no se llega así: su ruta recibe un 404,
   registrado en `runner`.

Una ruta declarada nombra lo que responde: una acción, una redirección, un
archivo o una carpeta estáticos, o una página HTML. Un método que no
permite recibe un 405 con su cabecera `Allow`, y una petición `OPTIONS`
recibe los métodos que permite.

### 4.4 La comprobación del acceso

El Checker (`src/Access/Checker.php`) decide si la petición sigue. Falla en
cerrado: una configuración que no puede aplicar rechaza todas las
peticiones, y nunca recurre a una verificación más débil.

1. **Los roles de las rutas** (`minRoles`) se leen primero. Uno que no se
   puede aplicar da un 500 (`Access misconfigured`).
2. **Una ruta abierta a todos** (`openList`), o un perfil que no pide
   ningún acceso, deja pasar la petición, sin nadie con la sesión iniciada.
   Una ruta abierta que además pide un rol es un 500.
3. **El portador y la verificación** (`carrWith`, `authWith`) deben existir
   y funcionar juntos, y la ruta de inicio de sesión debe ser una ruta del
   sitio; si no, un 500.
4. **Una dirección en espera** por demasiadas credenciales erróneas recibe
   un 429, con `Retry-After`, antes de leer ninguna credencial.
5. **La llamada de una aplicación** para uno de sus usuarios se verifica
   con su propia clave y su propio token, cuando el perfil nombra las
   claves de aplicación.
6. **La credencial** la lee el portador y se verifica. Si falta o se
   rechaza, es un 401, con `WWW-Authenticate` para Basic y Bearer; a un
   visitante que pide una página se le envía en cambio a la ruta de inicio
   de sesión (302). Solo una credencial presentada y errónea cuenta contra
   la dirección.
7. **El rol** de la ruta se compara con el del usuario. Un usuario que no
   lo tiene recibe un 404, como si la ruta no existiera, y el rechazo se
   registra en `access` con el rol necesario.

A partir de ahí, los eventos trazados en los registros llevan el usuario y
la dirección de la petición, y cada archivo recibido se traza, se haya
concedido el acceso o no. Una petición rechazada en este paso nunca carga
la aplicación.

### 4.5 Ejecutar la acción

Una ruta que responde por sí misma se responde aquí: una redirección sale
con su estado, un archivo estático con su versión y su fecha (un 304 cuando
el cliente ya lo tiene, una parte cuando lo pide `Range`), una página con
sus marcadores rellenados. Un archivo o una página que no se encuentra es
un 404, con el mismo `Not Found` que todos los demás.

Si no, la ruta nombra una aplicación, y su Controller
(`src/App/Controller.php`) toma el relevo:

1. **La aplicación** debe ser una carpeta de `runs/` nombrada solo con
   letras y dígitos, que contenga un `Actions.php` que declare su
   actionset.
2. **La acción** es la que nombra la ruta, o la de por defecto de la
   aplicación cuando no nombra ninguna: el `$dfltAction` de su actionset, y
   nada más (sin él, un 500, registrado). Debe ser una de las acciones de la
   aplicación: un método público de su actionset, no un método auxiliar ni
   un método del framework. A una acción que limita una ruta declarada no
   se llega por su ruta.
3. **Las entradas** reciben la definición de la acción
   (`input/<action>.json`): los campos listados se conservan, cada uno en
   su formato, y los demás se dejan fuera. Un campo obligatorio que falta o
   está vacío es un 400, y la acción no se ejecuta.
4. **Los modelos** de la aplicación se cargan (`ModelStack`).
5. **El actionset** se crea con un `RunContext` de solo lectura (lo que
   necesita para esta petición), ejecuta su gancho `openEachAction()`, y
   luego ejecuta la acción, con el parámetro de la ruta cuando lo hay.

Todo lo que falla en los pasos 1 y 2 recibe la misma respuesta,
`404 Not Found`, que una ruta a la que no responde ninguna ruta declarada:
un cliente no puede distinguir una aplicación que no existe de una acción a
la que no puede llegar. El motivo se registra en `runner`. Una acción por
defecto que no nombra ninguna acción es un error de la aplicación, no del
cliente: es un 500, registrado como error.

Una acción fija su estado y su salida. Puede detenerse en cualquier
momento, desde sí misma, desde un método auxiliar o desde
`openEachAction()`, con `stopThisAction()`: detenerse no es un error, y su
estado pasa a ser el de la respuesta. `needsActionRle()` la detiene con un
404, igual que el rol de una ruta, y `redirectClient()` con una
redirección.

### 4.6 La escritura de la respuesta

El estado decide el cuerpo:

| Estado | Cuerpo |
|---|---|
| 204 sin salida | ninguno |
| 204 con una salida | la salida, enviada como 200 |
| 2xx | la salida de la acción |
| 3xx | ninguno: la respuesta es su cabecera `Location` |
| 4xx, 5xx | `{"error": <message>, "code": <status>}` |

Los avisos que PHP lanzó durante la petición se añaden al cuerpo (`data` y
`warnings`) cuando se pueden mostrar los errores, salvo en una respuesta
CSV o en bruto, donde se quedan en el registro. Una salida que no se puede
escribir en el formato de la respuesta convierte la respuesta en un 500.

Un error en una página, en un archivo estático, o en una ruta a la que no
responde ninguna ruta declarada en un perfil que sirve páginas, se responde
con la página de error del sitio (`<fileRoot>/errors/<status>.html`) cuando
tiene una. Una acción conserva el formato de su perfil.

La respuesta escribe entonces sus cabeceras: las del perfil, el origen
permitido cuando el perfil lo lista, y `X-Content-Type-Options: nosniff` en
cada respuesta. Una petición `HEAD` recibe solo las cabeceras. Se envía el
cuerpo, y la petición termina.

### 4.7 Los errores por el camino

Una excepción que ningún código captura, o un error fatal, la captura el
gestor de errores (`src/Error/handling.php`): se registra en el canal
`error`, y se responde con un 500 en el formato de la respuesta. Sus
detalles solo se muestran cuando `ERR_SHOW` lo permite, y nunca en
producción. Lo que `ERR_LEVL` deja fuera ni se muestra ni se registra.

---

## 5. Componentes

El framework vive en `src/`, bajo el espacio de nombres `nyu\`, cargado por
Composer (PSR-4). Sus partes se agrupan por espacio de nombres:

| Espacio de nombres | Contiene | Usado por |
|---|---|---|
| `nyu\` | el Runner | el punto de entrada |
| `Setup` | el perfil, la configuración, el contenedor | el Runner, la línea de comandos |
| `Tools` | la petición, la ruta, las entradas, los formatos, los registros | todas las partes |
| `Access` | la comprobación del acceso, sus portadores y sus verificaciones, la base de usuarios | el Runner, los runs `Auth` y `Users` |
| `App` | el controlador y el actionset de los runs | el Runner, los runs |
| `Output` | la respuesta | el Runner, las acciones |
| `Error` | el gestor de errores | el Runner |
| `Data` | las bases de datos, los documentos, los medios, el correo | los runs, la capa de acceso |
| `Shell` | la línea de comandos | `php nyuRun` |

Una petición va de arriba abajo de esta tabla. `Data` solo se carga cuando
un run o la capa de acceso lo pide.

### 5.1 El Runner y la puesta en marcha

- **`Runner`**: la secuencia del capítulo 4, del perfil a la respuesta.
- **`Setup\Preset`**: lee un perfil (`conf/presets/<name>.rps`), comprueba
  sus claves (`Preset::KEYS_LIST`) y pasa sus bloques al Loader.
- **`Setup\Loader`**: la configuración de la petición: los archivos de
  `conf/`, los valores de `.env` que nombran, y los ajustes de cada parte
  (registros, errores, límites de las peticiones, proxies, sonidos).
- **`Setup\ConfCache`**: la caché de configuración: el perfil comprobado,
  los archivos de configuración, las rutas y las descripciones de las
  tablas, guardados en `temp/cache/config-<preset>.cache` y leídos en su
  lugar.
- **`Setup\Builder`**: el contenedor de inyección de dependencias, sobre
  Dice. Construye los objetos que comparte toda la petición: los registros
  del servidor y del cliente, la petición y la respuesta.

### 5.2 Las herramientas

| Clase | Papel |
|---|---|
| `Registry` | un diccionario de valores, la base de cada registro |
| `ServerReg` | `$_SERVER`, leído una vez y luego vaciado, para que ningún código lo lea si no es a través de la petición |
| `ClientReg` | lo que la petición dice de su cliente: su dirección (a través de los proxies de confianza), su agente, su sistema, su tipo de dispositivo y su idioma; solo la dirección la lee el propio servidor |
| `Request` | la petición tal como se envió: método, ruta, cabeceras, cadena de consulta, cuerpo, archivos, dentro de los límites de tamaño |
| `Router` | la ruta de la petición (4.3), los enlaces a las rutas declaradas, las páginas de error |
| `Input` | las entradas de una acción, conservadas y formateadas según su definición |
| `Variable` | los formatos que puede recibir un valor: textos, números, precios, fechas, direcciones, números de teléfono, enlaces, HTML |
| `Logger` | un archivo de registro por canal (`error`, `access`, `runner`, `shell`, uno por run), los niveles PSR-3, la rotación |
| `Language` | el idioma en que se escribe un texto para una persona, y el archivo encontrado para ella |
| `StrKit` | métodos auxiliares para cadenas |
| `UniqueId` | identificadores que un registro conserva dondequiera que se copie: UUID de la versión 7 |

### 5.3 El acceso

**`Access\Checker`** decide si una petición sigue (4.4). Empareja un
**portador**, que lee la credencial de la petición (`carrWith`), con una
**verificación**, que la comprueba (`authWith`):

| Portador | Clase | Lleva |
|---|---|---|
| `httpBasc` | `Carr\Basic` | un nombre y una contraseña, por HTTP Basic |
| `apiHeadr` | `Carr\Header` | una clave en una cabecera |
| `jwtToken` | `Carr\JWT` | un token firmado (HS256, RS256) |
| `phpSessn` | `Carr\PHPSess` | una sesión PHP, por su cookie |
| `clntCert` | `Carr\ClCert` | un certificado de cliente, verificado por el servidor web |

| Verificación | Clase | Compara con |
|---|---|---|
| `htpasswd` | `Auth\PassList` | un archivo de contraseñas en el formato de Apache |
| `regdKeys` | `Auth\RegKey` | una lista de claves de API |
| `userFile` | `Auth\UserList` | el archivo de usuarios, de solo lectura |
| `nativeDb` | `Auth\Native` | la tabla de usuarios de una base de datos |
| `dvcToken` | `Auth\Device` | los tokens de los dispositivos |

Un portador y una verificación que no pueden funcionar juntos rechazan
todas las peticiones (`Checker::PAIR_LIST`). Junto a ellos:

- **`Limiter`**: cuenta las credenciales erróneas de cada dirección, y la
  deja en espera un rato una vez superado el límite.
- **`Totp`**: los códigos de doble factor (RFC 6238), y el cifrado de sus
  secretos.
- **`AppKey`**: las llamadas que hace una aplicación para uno de sus
  usuarios (`appsKeys`).
- **`Auth\UserBase`**: la gestión de la base de usuarios nativa: creación,
  activación, roles, estados, contraseñas, tokens, altas,
  restablecimientos.
- **`AccessMail`**: los correos de los procesos de acceso (activación,
  alta, restablecimiento, código de inicio de sesión), a partir de sus
  plantillas, en el idioma de su usuario.

### 5.4 Las aplicaciones

- **`App\Controller`**: encuentra la aplicación y su acción, aplica la
  definición de sus entradas, carga sus modelos, construye su `RunContext`
  y la ejecuta (4.5).
- **`App\RunContext`**: lo que recibe un actionset para una petición, de
  solo lectura: la acción y su parámetro, las entradas, el resultado del
  acceso, la petición, la respuesta, el router, los modelos, el nombre y la
  carpeta de la aplicación.
- **`App\Actionset`**: el padre de la clase `Actions` de cada run. Cada uno
  de los métodos públicos propios del run es una acción. El actionset les
  pasa sus entradas, sus modelos, el acceso, la petición y la respuesta,
  ejecuta primero el gancho `openEachAction()`, y ofrece los métodos
  auxiliares `serveFileReply()`, `servePageReply()`, `redirectClient()`,
  `makeUrlOfRoute()`, `setReplyFormat()`, `getLoggerOfApp()`,
  `needsActionRle()`, `hasRoleOrAbove()` y `stopThisAction()`.
- **`App\ModelStack`**: los modelos de un run, cada `<Name>.php` de su
  carpeta `mods/` (`<Name>Model.php` como alternativa, ganando
  `<Name>.php` cuando están los dos), y luego los compartidos en
  `runs/Shared`, encontrados del mismo modo.
- **`App\RunLoader`**: carga las clases de los runs cuando se piden por
  primera vez: las acciones y el comando de un run, sus modelos, una clase
  en un archivo con su nombre, y las clases de `runs/Shared`.
  `src/App/loadruns.php` (el `autoload.files` de Composer) lo registra
  después del cargador propio de Composer, así que un proyecto actualizado desde una
  copia anterior ejecuta `composer dump-autoload` una vez. Ningún código
  incluye por sí mismo un archivo de un run.
- **`App\StopRun`**: lo que lanza `stopThisAction()` para detener una
  acción a propósito, con su estado y su salida.

### 5.5 La salida y los errores

- **`Output\Response`**: la respuesta: su estado, sus cabeceras (las del
  perfil, el origen permitido, `nosniff`), y su cuerpo en su formato
  (`jsonData`, `yamlData`, `xmlNodes`, `csvTable`, `htmlView`,
  `rawBytes`), un archivo enviado por partes, o una página con sus
  marcadores rellenados.
- **`Error/handling.php`**: los gestores de errores, de excepciones y de
  cierre: registran en `error`, recogen los avisos y responden un 500 a
  través del Runner.

### 5.6 Los datos

**Bases de datos.** Un run trabaja a través de modelos, no de consultas:

- **`DBRec`**: un registro de una tabla, cargado por su clave o encontrado
  por sus campos, guardado, o borrado con sus hijos. **`DBLst`**: una lista
  de registros leída a través de un filtro, con sus campos elegidos,
  agrupada, ordenada, limitada, unida a otra tabla, o los hijos de un
  registro.
- **`DBStore\Store`**: lo que un registro y una lista necesitan de una base
  de datos, aportado por **`SqlStore`** para los tipos SQL y
  **`MongoStore`** para MongoDB. **`DBStore\Filter`** lee el filtro, escrito
  de la misma forma para todos (`field`, `field!`, `field<=`, `field~`, …).
- **`DBCon`**: una conexión por base de datos configurada, abierta en el
  primer uso, a través de su gestor: **`DBHndlr\MySQL`** (MariaDB
  incluido), **`PgSQL`** y **`SQLite`**, todos sobre PDO.

**Documentos.** Un documento se lee en una de tres formas, y se escribe a
partir de una de ellas (`Data\Shape`): `nodes` (un árbol de claves y
valores), `sheet` (tablas con nombre, de filas y celdas) y `block` (bloques
de texto en el orden de lectura).

- **`Dcmnt`**: un archivo en el servidor, con su tipo deducido de sus
  primeros bytes, leído por el analizador que pide su extensión.
- **`Parsers\*`**, elegidos por `Parsers\Index`: texto, Markdown, HTML,
  XML, JSON, YAML, INI, CSV y TSV, iCalendar, PDF, Word, Excel, texto y
  hoja de cálculo de OpenDocument, ePub.
- **`Writers\*`**, elegidos por `Writers\Index`: los mismos formatos salvo
  INI, con el PDF escrito mediante dompdf.
- **`Fluxd`**: una fuente leída trozo a trozo (un archivo, el cuerpo de la
  petición), o bytes ya en memoria (un valor binario leído de una tabla).
- **`Archv`**: un archivo zip leído entrada a entrada, en memoria.

**Medios.**

- **`Image`**: una imagen, su tipo, su tamaño y lo que registra una foto;
  redimensionada, recortada, girada, limpiada (`gd`); un dibujo convertido
  en imagen (`imagick`).
- **`Sound`**: un sonido, su tipo, su duración, sus etiquetas y su imagen
  de portada; sus etiquetas quitadas, un WAV o un MP3 cortado. **`Codec`**
  ejecuta ffmpeg para el resto.
- **`QrCode`**: el código QR de un texto, dibujado en SVG o como texto para
  un terminal.

**Correo.** **`Mail\Mailer`** envía un **`Message`** a través de una cuenta
de `conf/mailer.json`, con su driver: **`Drivers\Native`** (el cliente SMTP
propio del framework, `selfSmtp`), **`PHMail`** (PHPMailer, para los
adjuntos, `phpMailr`) o **`ToFile`** (archivos `.eml`, `emlFiles`).
**`Template`** construye un mensaje a partir de sus archivos de texto y
HTML, con cada `%name%` rellenado.

### 5.7 La línea de comandos

- **`Shell\Console`**: lee los argumentos de `php nyuRun`, encuentra el
  comando y lo ejecuta.
- **`Shell\Command`**: el padre de cada comando, con sus subcomandos
  (`listSubCmndDct()`), junto con **`Options`**, **`Printer`** (texto, o
  JSON para un script) y **`ShellStop`** (el código de salida).
- **`Shell\Cmds\*`**: `install`, `setting`, `testing`, `cleanup`,
  `showlog`, `cronjob`, `account`, `devices`, `bundles`, `appkeys`,
  `makerun` (capítulo 7).
- **`HealthChk`** ejecuta las comprobaciones de `install checkAll`.
  **`ConfFile`** escribe los archivos de configuración, cada uno a su
  manera, y **`UserFile`** el archivo de usuarios. **`ActCaller`** llama a
  una acción de un run desde la línea de comandos, con los roles del
  perfil.

### 5.8 Dónde se amplía el framework

| Para añadir | Escribe | Declarado en |
|---|---|---|
| una verificación de credenciales | `runs/Shared/<Name>Auth.php`, que implementa `Access\Auth\Handler` | el bloque `plugLibs` de `access.json`, bajo `authWith` |
| un portador | `runs/Shared/<Name>Carr.php`, que implementa `Access\Carr\Handler` | el bloque `plugLibs`, bajo `carrWith` |
| un modelo compartido por varios runs | `runs/Shared/<Name>.php` (o `<Name>Model.php`) | nada: se encuentra por su nombre |
| un perfil | `conf/presets/<name>.rps` | el punto de entrada que lo nombra |
| una plantilla de correo | `runs/<App>/mails/<language>/<kind>.txt` y `.html` | nada: sustituye a la del framework |
| un comando de la línea de comandos | `runs/<App>/Shell.php`, la clase `Shell` que extiende `Shell\Command` | nada: el comando es el nombre del run en minúsculas |

El nombre de un plug-in tiene seis caracteres, letras y dígitos, y empieza
por una letra. Uno que está listado pero no se puede usar se registra, y la
petición se rechaza como mal configurada.

---

## 6. Archivos de configuración

### 6.1 Cómo se lee la configuración

En cada petición, el Loader lee cada archivo `.json` e `.ini` de `conf/`
(o de la carpeta que nombra el perfil), y guarda cada uno bajo el nombre de
su archivo en mayúsculas: `system.ini` es `SYSTEM`, `access.json` es
`ACCESS`. Con `CNF_CACH`, la primera petición de un perfil escribe lo que
ha leído en `temp/cache`, y las siguientes leen ese archivo en su lugar.
Cada comando que escribe en `conf/` borra la caché, igual que
`install complete` y `setting clrCache`. Después de una edición a mano,
`php nyuRun setting clrCache` la borra, e `install checkAll` avisa de una
caché más antigua que sus archivos. Ningún secreto entra en la caché:
`.env` se lee en cada petición. Así que un archivo de `conf/` que no sea
una configuración no debe terminar en `.json` ni en `.ini`: los ejemplos
terminan en `.dist`, las listas en `.lst`.

Un valor escrito `env:NAME` se sustituye por el valor de `NAME` en `.env`
(o en el archivo que nombra el perfil), o si no en el entorno del proceso.
Los secretos solo se escriben así (2.3).

Los archivos creados a partir de un ejemplo (`access.json`,
`database.json`, `mailer.json`, `users.lst`) y `.env` son locales de cada
instalación: git los ignora, y los bundles los dejan fuera.
`php nyuRun install complete` los crea a partir de sus ejemplos, y
`php nyuRun setting` los lee y los cambia, comprobando cada valor.

Todos los nombres siguen la misma regla: las claves de un mismo bloque
comparten una longitud, 8 caracteres para los archivos JSON, y 8 con la
forma `XXX_YYYY` para `system.ini`.

### 6.2 `system.ini`

| Clave | Valor | Por defecto |
|---|---|---|
| `ENV_NAME` | el entorno: `DEVS`, `TEST`, `PREV`, `PROD` | `DEVS` |
| `LOG_MODE` | los niveles escritos: `MINI` (errores, avisos, notificaciones), `BASE` (más la información), `FULL` (más la depuración, con el contexto) | `FULL` |
| `LOG_PATH` | la carpeta de los registros, desde la raíz del proyecto | `temp/logs` |
| `LOG_SIZE` | el tamaño de un registro, en KB, antes de rotarlo; 0 para ningún límite | 1024 |
| `LOG_ROTA` | la rotación: `INDX` (copias numeradas por encima de `LOG_SIZE`), `WEEK`, `MNTH` (nombres de archivo con fecha), `NONE` | `INDX` |
| `LOG_PRFX` | el formato `date()` al principio de cada línea | `Y-m-d H:i` |
| `ERR_LEVL` | los errores que notifica PHP, nombres `E_*` unidos por `\|`, `&` y `~` | `E_ALL` |
| `ERR_SHOW` | si los errores se muestran en la respuesta, `TRUE` o `FALSE`; nunca en `PROD` | |
| `DEF_ROWS` | las filas que lee una lista cuando no se le dice cuántas | 100 |
| `DEF_LANG` | el idioma de los correos de un usuario que no tiene ninguno | `en` |
| `REQ_SIZE` | el mayor cuerpo leído, en KB; 0 para ningún límite | |
| `REQ_PART` | el máximo de partes de un cuerpo multipart; 0 para ningún límite | |
| `CNF_CACH` | si el perfil, los archivos de configuración y las rutas se guardan en caché (`TRUE`, `FALSE`) | `FALSE`; `TRUE` en los bundles `stage` y `final` |
| `TBL_CACH` | si las descripciones de las tablas también se guardan en caché, con `CNF_CACH`; una consulta que falla en una tabla en caché borra la caché | `FALSE` |
| `SND_TOOL` | la ruta completa de ffmpeg, vacía sin él | |
| `SND_SECS` | los segundos que puede ejecutarse ffmpeg | 60 |
| `PRX_LIST` | las direcciones o los rangos de los proxies inversos de confianza | vacío |
| `PRX_HEAD` | la cabecera en la que dan la dirección del cliente | |
| `PRX_SCHM` | la cabecera en la que dan su esquema | |

### 6.3 `.env`

`.env.example` lista los nombres que usan los ejemplos de configuración:

| Nombre | Contiene |
|---|---|
| `NYU_JWT_SECRET` | el secreto de los tokens HS256 (32 caracteres como mínimo); vacío con RS256 |
| `NYU_API_KEY` | una clave de API del bloque `regdKeys` |
| `NYU_TFA_KEY` | la clave con la que se cifran los secretos de doble factor (32 caracteres como mínimo) |
| `NYU_MARK_KEY` | la clave con la que se firman las marcas de inicio de sesión (`tryLimit.markSecr`) |
| `NYU_DB_PASS` | la contraseña de la base de datos |
| `NYU_MAIL_PASS` | la contraseña de la cuenta de correo |
| `NYU_SND_TOOL` | la ruta de ffmpeg |
| `NYU_TEST_*` | los servidores de pruebas para MySQL, PostgreSQL y MongoDB (capítulo 13) |

Se puede añadir cualquier nombre: basta con que la configuración lo
escriba `env:NAME`.

### 6.4 Perfiles

Un perfil (`conf/presets/<name>.rps`, JSON con líneas de comentario) es un
perfil del framework, elegido por el punto de entrada. Sus bloques y sus
claves:

| Bloque | Claves | Fija |
|---|---|---|
| (arriba) | `presetId`, `synopsis` | su nombre, y para qué sirve |
| `settings` | `confType`, `confList`, `confPath`, `envrFile` | los archivos de configuración leídos: sus tipos (`all`, `ini`, `json`), sus nombres (`any` o una lista), su carpeta, el archivo de los secretos |
| `security` | `required`, `carrWith`, `carrConf`, `authWith`, `authConf`, `openList`, `signPath`, `minRoles`, `appsConf`, `isPublic` | la comprobación del acceso: si hace falta, el portador y la verificación con los bloques de `access.json` que leen, las rutas abiertas a todos, la ruta de inicio de sesión de un sitio, los roles de las rutas, el bloque de las claves de aplicación, y un sitio pensado para estar abierto |
| `inputSet` | `sourceId`, `readFrom`, `fieldSet` | las fuentes de las entradas (`QUERY`), y los campos conservados |
| `dispatch` | `detectBy`, `pathForm`, `routeDir`, `fileRoot`, `runMount` | el enrutamiento: `autopath` o `manually`, los nombres de las partes de una ruta, la carpeta de los archivos de rutas, la carpeta de las páginas y archivos estáticos, dónde se montan las rutas de cada run (o `false` para dejar de servirlo) |
| `response` | `outputAs`, `corsList`, `headList` | la respuesta: su formato, los sitios autorizados a llamar, las cabeceras enviadas |

Una clave que un perfil no conoce impide que se cargue. El framework trae
siete perfiles:

| Perfil | Para |
|---|---|
| `default` | el perfil cuando el mapa no nombra ninguno, o no hay mapa: cerrado, tokens Bearer, JSON |
| `mapping` | un sitio que declara sus propias rutas: cerrado, tokens Bearer |
| `service` | un servicio al que llaman otros programas: una clave de API en una cabecera |
| `restapi` | una API web: tokens Bearer, el inicio de sesión abierto |
| `website` | un sitio usado desde un navegador: una cookie de sesión, la tabla de usuarios, sus propias rutas |
| `statics` | un sitio de páginas, archivos y redirecciones: abierto, sin entradas, sus propias rutas |
| `example` | nunca se carga: cada ajuste de un perfil, con lo que hace |

### 6.5 `access.json`

Los bloques que un perfil nombra con `carrConf`, `authConf` y `appsConf`,
y los ajustes de la capa de acceso. Un bloque se llama como su verificación
o su portador, así que un valor que nombra un bloque se entiende solo
(`"carrConf": "jwtToken"`):

| Bloque | Claves | Fija |
|---|---|---|
| `loginSet` | `authWith`, `authConf`, `carrWith`, `carrConf` | cómo inicia el run `Auth` la sesión de un usuario, y lo que emite |
| `plugLibs` | `authWith`, `carrWith` | las verificaciones y los portadores en plug-in permitidos (5.8) |
| `htpasswd` | `passFile`, `weakHash`, `maxTries`, `holdSecs` | el archivo de contraseñas de la verificación `htpasswd`, y su límite por cuenta |
| `nativeDb` | `linkName`, `useTable`, `idleSecs`, `maxTries`, `holdSecs`, `minChars`, `tfaCrypt`, `mailAcnt`, `signupOn`, `codeMins`, `linkTmpl` | la base de usuarios nativa: su base de datos y su tabla, el tiempo tras el cual un usuario deja de estar en línea, las contraseñas erróneas antes de que la cuenta quede en espera y durante cuánto tiempo, la contraseña más corta, la clave de los secretos de doble factor, los correos de acceso |
| `userFile` | `listFile`, `tfaCrypt`, `maxTries`, `holdSecs` | el archivo de usuarios, la clave de sus secretos de doble factor, y su límite por cuenta |
| `dvcToken` | `useTable`, `usersTbl`, `seenSecs` | la tabla de los dispositivos, la de sus usuarios, y cada cuánto se registra el último uso de un dispositivo; el tope de cada dispositivo, el rol más alto con el que actúa, es una columna de su tabla (`deviceTops`) |
| `tryLimit` | `maxTries`, `holdSecs`, `dataPath`, `maxQueue`, `maxPerIp`, `alertsAt`, `markSecr`, `markDays` | el límite por dirección, los límites de las altas, y las marcas de inicio de sesión |
| `regdKeys` | `headName`, `keysList` | la cabecera de las claves de API, y las claves |
| `jwtToken` | `hmacSecr`, `lifeSecs`, `signAlgo`, `privFile`, `publFile`, `prevFile`, `retireAt`, `issuerId`, `audience`, `external` | los tokens: su algoritmo, su secreto o sus claves, la clave pública anterior y la hora en que se retiró, su vida, su emisor y su audiencia; `external` cuando la clave pública es la de otro servidor |
| `phpSessn` | `cookName`, `lifeSecs`, `fullSecs`, `sameSite`, `secureOn`, `dataPath` | las sesiones PHP: la cookie, el tiempo sin uso y en total |
| `clntCert` | `checkVar`, `ownerVar`, `issueVar`, `issuerId` | los valores del servidor de los que se lee un certificado de cliente, y el emisor aceptado |
| `appsKeys` | `headName`, `appsList`, `audience` | las claves de las aplicaciones que llaman para sus usuarios (`keyDigst`, `routeSet`, `publFile`, `roleTops`, `madeDate` para cada una) |

Un proyecto puede tener varios bloques de un mismo tipo con nombres
propios, y cada perfil nombra el que usa.

### 6.6 `database.json`

Una base de datos, o varias por su nombre, siendo la primera la de por
defecto:

| Clave | Valor |
|---|---|
| `dbEngine` | `mysql_db` (MariaDB incluido), `postgres`, `sqlitedb`, `mongo_db` |
| `hostName`, `portNumb` | el servidor; vacíos para SQLite |
| `baseName` | la base de datos, o el archivo de una base SQLite |
| `userName`, `passWord` | la cuenta, con la contraseña como `env:NAME` |
| `encoding` | el juego de caracteres (`utf8`) |
| `authBase` | la base de datos que contiene la cuenta, cuando es otra |
| `waitSecs` | los segundos que se dan al servidor para responder |

### 6.7 `mailer.json`

Las cuentas de correo por su nombre, siendo la primera la de por defecto,
cada una con las claves `sendWith` (`selfSmtp`, `phpMailr`, `emlFiles`),
`hostName`, `portNumb`, `userName`, `passWord`, `fromAddr`, `fromName`,
`waitSecs` (los segundos que puede tardar un servidor), `mailsDir` (la
carpeta de una cuenta `emlFiles`) y `copiesTo` (direcciones a las que se
vuelve a enviar cada correo).

### 6.8 Archivos de rutas

Las rutas del sitio se declaran en `conf/routes/*.rte` (o en la carpeta del
perfil), JSON con líneas de comentario, por su ruta:

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

- Una parte escrita `$name` captura un valor: el primero es el parámetro de
  la acción, y todos rellenan los marcadores de una página. Un `*` final
  toma el resto de la ruta.
- `mthdList` lista los métodos de la ruta (si no, solo `GET`); un bloque
  `pointsTo` también puede contener un bloque por método.
- Un destino nombra un solo tipo: una acción (`appToRun`, `doAction`), una
  redirección (`redirect`, con su estado en `httpCode`), un archivo
  (`filePath`) o una carpeta (`fldrPath`) estáticos, o una página HTML
  (`pageFile`).

Un run declara sus propias rutas de la misma forma, en
`runs/<App>/routes.rte`, con un destino que nombra una de sus acciones solo
con `doAction`. Sus rutas se montan bajo el nombre del run en minúsculas
(`""` es `/notes`, `$id` es `/notes/$id`), una ruta que empieza por una
barra parte de la raíz del sitio, y el `runMount` del perfil puede montar
el run en otro sitio, o no montarlo. Una clave que nada lee se registra
como aviso. Un archivo de rutas no contiene ningún ajuste de acceso: los
roles de las rutas están en el perfil.

### 6.9 Definiciones de las entradas

Las entradas de una acción se declaran en `runs/<App>/input/<action>.json`:

```json
{ "fieldSet": { "user!": "OutText", "pass": "", "mail": "EmlAddr" } }
```

Cada campo se nombra con su formato (uno de los de `Tools\Variable`). Un
`!` después del nombre lo hace obligatorio, y un formato vacío lo conserva
tal como se envió. Un formato desconocido detiene la petición con un 500
(un 400 para un campo obligatorio). Una acción sin este archivo no recibe
ninguna entrada; `{ "fieldSet": "all" }` conserva cada entrada tal como la
construyó el perfil.

### 6.10 Otros archivos

| Archivo | Contiene |
|---|---|
| `conf/users.lst` | el archivo de usuarios: `{ "users": [ ... ] }`, con los nombres de las columnas de la tabla de usuarios |
| `conf/bundle.lst` | los modos de bundle del proyecto (capítulo 7) |
| `conf/.htpasswd` | el archivo de contraseñas de la verificación `htpasswd` |
| `conf/keys/` | las claves de los tokens (`.key`, `.pub`), creadas por `setting mkSecret jwtToken` |
| `conf/schema/` | las tablas entregadas, un archivo por tabla y por tipo de base (`users.sqlitedb.sql`), y sus migraciones |
| `conf/mails/<language>/` | las plantillas de los correos de acceso |

---

## 7. Línea de comandos

### 7.1 Uso

```
php nyuRun <command> <sub-command> [arguments] [--options]
```

Cada comando es una palabra de siete letras, y cada subcomando una de ocho,
reconocida sea cual sea su caja. Un comando sin subcomando, o con
`--helpText`, lista lo que hace; `showlog` y `cronjob` no tienen ninguno, y
toman sus argumentos directamente.

| Opción | Efecto |
|---|---|
| `--noPrompt` | no se hace ninguna pregunta: se toman las respuestas por defecto |
| `--yesToAll` | lo que borra o sustituye se confirma de antemano; con `--noPrompt` solo, se rechaza |
| `--outputAs=json` | el resultado como un único objeto JSON, para que lo lea un script |
| `--silently` | ningún comentario, solo el resultado |
| `--helpText` | lo que hace el comando |
| `--versions` | la versión del framework |

| Código de salida | Significa |
|---|---|
| 0 | hecho |
| 1 | fallido |
| 2 | uso incorrecto: una palabra desconocida, un argumento que falta |
| 3 | rechazado o declinado |
| 4 | hecho, con problemas encontrados (`install checkAll`) |

Lo que cambia un comando se traza en el canal de registro `shell`, firmado
`cli:<system user>`. Lo que cambia en los usuarios se traza en `access`,
como el mismo cambio hecho por HTTP.

### 7.2 `install`: instalar y comprobar

| Subcomando | Hace |
|---|---|
| `complete` | toda la instalación: primero se pregunta el perfil del proyecto (`allInOne`, `authServ`, `dataServ`, `openSite`; `--projKind` lo responde), se comprueba el servidor, se crean los archivos locales, se crean los secretos, se crean los usuarios (se preguntan sus nombres), el diagnóstico; pregunta lo que necesita, proponiendo un valor por defecto (`--noPrompt` los toma todos, `allInOne` entre ellos, `--useStore` elige la base de usuarios). Con la base de usuarios nativa, los correos de acceso se preguntan después de la base: enviados o no, la cuenta de correo escrita en `mailer.json`, `linkTmpl`, `signupOn`, un correo de prueba (`--sendWith=<selfSmtp\|phpMailr\|emlFiles\|none>` y `--signupOn` responden de antemano; `--noPrompt` toma `none`) |
| `testHost` | comprueba el servidor: PHP, sus extensiones, las carpetas escritas |
| `makeConf` | crea `.env`, `access.json`, `database.json` y `mailer.json` a partir de sus ejemplos |
| `userBase` | crea la tabla de usuarios y los usuarios básicos, o el archivo de usuarios; la tabla de dispositivos con un bloque `dvcToken` |
| `checkAll` | el diagnóstico: cada fila `fine`, `warn` o `fail`; código de salida 4 con un `fail` |
| `moveUser <nativeDb\|userFile>` | traslada los usuarios a la otra base de usuarios, y el inicio de sesión con ellos (`--dropsAll`, `--yesToAll`) |
| `testMail [address]` | envía un correo de prueba a través de la cuenta que nombra `nativeDb.mailAcnt`, y dice si ha salido o por qué no; no se cambia nada |

No se sustituye nada que exista: un archivo, un secreto, una tabla, una
base de usuarios que contiene usuarios.

### 7.3 `setting`: leer y cambiar la configuración

| Subcomando | Hace |
|---|---|
| `showConf <coreConf\|accsConf\|dataConf\|psetConf> [path]` | muestra un archivo, uno de sus bloques o claves, con los secretos ocultos |
| `setValue <coreConf\|accsConf\|dataConf\|psetConf> <path> <value>` | cambia un valor (`KEY`, `block.key`, `preset.block.key`), comprobado según su regla (`--overrule`) |
| `entryPts [basePath] [preset]` (`--hostName`, `--removeIt`) | los perfiles servidos y dónde (`conf/doorways.lst`): listados, fijados o quitados |
| `mkSecret <jwtToken\|keysList\|tfaCrypt\|markSecr\|htpasswd>` | crea un secreto, el par de claves RS256, o una contraseña del archivo de contraseñas, y lo escribe donde la configuración lo lee (`--useBlock`, `--showSecr`, `--typeItIn`) |
| `fullList [preset]` | las rutas de un perfil: ruta, métodos, tipo, destino, propietario, y a quién se sirven |
| `clrCache` | borra la caché de configuración: la próxima petición de cada perfil vuelve a leer sus archivos |

Un archivo se escribe conservando sus comentarios y su formato, y su
versión anterior se copia en `temp/archives`. Un cambio que lo deja
inutilizable se deshace.

### 7.4 `account`, `cronjob`: usuarios y acciones

Los usuarios de la base de usuarios que usa el inicio de sesión
(`--useStore`, o `--useBlock` para otro bloque):

| Subcomando | Hace |
|---|---|
| `fullList`, `viewUser` | lista los usuarios (`--statusIs`, `--roleName`, `--rowCount`, `--startRow`), muestra uno |
| `makeUser <user> [role]` | crea un usuario pendiente de activación, y muestra una vez su código de activación (`--noExpiry`, `--language`) |
| `activate <user> <code>` | activa un usuario con su código, y le da una contraseña |
| `lockUser`, `freeUser` | bloquea un usuario, lo desbloquea |
| `giveRole <user> <role>` | da otro rol |
| `dropUser <user>` | borra un usuario |
| `resetPwd <user>` | un nuevo código de activación, para una contraseña perdida |
| `password <user>` | una nueva contraseña, creada y mostrada una vez (`--typeItIn` para teclearla) |
| `apiToken <user>` | un token de API personal, mostrado una vez (`--removeIt` lo quita) |
| `setTwoFa <user>` | un secreto de doble factor, con el código QR de la app de autenticación (`--noQrCode` sin él, `--emailOtp` para códigos por correo, `--removeIt` lo quita) |
| `setEmail <user> [address]` | fija o quita la dirección |
| `language <user> [language]` | muestra, fija o quita el idioma |
| `approves [user]` | lista las altas por revisar, o aprueba una |

`php nyuRun cronjob <app/action[/param]> [field=value]`, un comando propio
sin subcomando, llama a una acción de un run para tareas automáticas, como
el usuario `cli:<system user>`, con los roles de un perfil (`--presetId`,
`--roleName`, `--bodyFrom`, `--httpVerb`, `--saveInto`).

### 7.5 `devices`, `appkeys`: dispositivos y aplicaciones

| Comando | Subcomandos |
|---|---|
| `devices` | `newToken <name> <user> <role>` da un token a un dispositivo, mostrado una vez; `fullList [user]`; `revokeIt <name> <user>` |
| `appkeys` | `makeKeys <app>` con `--routeSet`, `--publFile`, `--roleTops`, da una clave mostrada una vez; `fullList`; `setRoute <app>` sustituye sus rutas; `revokeIt <app>` |

Solo se guarda el hash de un token o de una clave. Un dispositivo revocado
se queda en la lista con la hora en que se revocó; una clave revocada se
rechaza desde la petición siguiente.

### 7.6 `bundles`: una copia para cada entorno

| Subcomando | Hace |
|---|---|
| `local`, `stage`, `final` | construye la copia para desarrollo, para un servidor previo a producción, para producción |
| `<mode>` | construye un modo del proyecto |
| `fullList` | lista los modos, los del framework y los del proyecto |
| `makeMode <mode>` | declara un modo del proyecto en `conf/bundle.lst`, o lo cambia |
| `dropMode <mode>` | quita uno |

Un bundle se escribe en `dist/<mode>`, o en la carpeta vacía que nombra
`--destPath`. `--archived` añade su `.tar.gz`, y `--planOnly` lista lo que
se copiaría. Solo se copia lo que nombra `Bundle::KEEP_LIST`, menos lo que
nombra `DROP_LIST`: ningún secreto, ningún ajuste local, ninguna clave. Los
ajustes del modo se escriben en el `system.ini` de la copia. Una copia para
`stage` o `final` recibe un mapa de clases completo y con autoridad, creado
en ella por Composer (`--composer` lo nombra, o si no `composer.phar` en la
raíz, o el del PATH). Sin Composer, la copia conserva el cargador
automático del proyecto, y lo dice.

Un modo del proyecto, en `conf/bundle.lst`, se nombra libremente (letras
minúsculas y dígitos, de cualquier longitud, que no sea ni un modo del
framework ni un subcomando). Contiene `settings` (claves de `system.ini`,
entre ellas su entorno `ENV_NAME`: `DEVS`, `TEST`, `PREV` o `PROD`, que
pregunta `makeMode`) y `addPaths` (rutas copiadas además de la lista
permitida).

### 7.7 `cleanup`, `showlog`: archivos temporales y registros

| Subcomando | Hace |
|---|---|
| `cleanDir <cacheDir\|logFiles\|tryLimit\|archives\|all>` | borra los archivos de esas carpetas de `temp` (`--keepDays=<days>`) |
| `fullList` | las direcciones que el límite de intentos cuenta o deja en espera |
| `freeAddr <address\|all>` | libera una dirección |

No se borra nada fuera de la carpeta `temp` del proyecto.

`php nyuRun showlog <channel>`, un comando propio sin subcomando, muestra
las últimas líneas de un registro (`--numLines`, `--watching` para
seguirlo).

### 7.8 `testing`: la batería de pruebas

`php nyuRun testing` ejecuta los grupos `unit`, `audit` y `data`. Un grupo
o una palabra ejecuta esos scripts, y `all` ejecuta todos los grupos. Las
extensiones que necesitan las pruebas y que PHP no carga se dan para la
ejecución (`--addExtns`). El capítulo 13 describe la batería.

### 7.9 Los comandos de un run

Un run trae su propio comando con `runs/<App>/Shell.php`, que declara la
clase `Shell` de su espacio de nombres, que extiende `Shell\Command`. El
comando es el nombre del run en minúsculas, y cada subcomando ejecuta un
método llamado `invoke` más su nombre (`invokeSayHello` para `sayHello`),
listado en su `listSubCmndDct()`.

### 7.10 `makerun`: un run a partir de una tabla

| Subcomando | Hace |
|---|---|
| `crudlRun <App>` | pregunta la base de datos (solo SQL), la tabla, su clave, los campos y sus formatos, las acciones (`index`, `view`, `create`, `update`, `remove`), las tablas hijas, el rol mínimo y el montaje; escribe `runs/<App>/runspec.json`, y luego el run; un run que existe se rechaza |
| `fromSpec <App>` | vuelve a escribir el run a partir de su `runspec.json`, sin preguntas; un archivo que existe solo se sustituye si se acepta (`--yesToAll`), y los archivos cambiados a mano no se fusionan |
| `fullList` | lista los runs que tienen una spec: base de datos, tabla, acciones, hijos |

El run recibe su `routes.rte`, su `Actions.php` y un archivo de entradas
por acción. Las preguntas rellenan la spec, y el generador solo lee la
spec, así que un run se puede volver a escribir después de editar la spec.
Cada archivo escrito se traza en `shell`, y se muestra la línea `minRoles`
que hay que añadir al perfil. Un bundle deja fuera `runspec.json`.

---

## 8. Construcción de los runs (aplicaciones)

### 8.1 De qué está hecho un run

Un run es una carpeta de `runs/`, nombrada con letras y dígitos, con su
primera letra en mayúscula. Solo `Actions.php` es obligatorio:

```
runs/Notes/
  Actions.php                 las acciones                 namespace nyu\Runs\Notes
  mods/<Name>.php             los modelos                  namespace nyu\Runs\Notes\mods
  input/<action>.json         las entradas de cada acción
  routes.rte                  sus propias rutas
  pages/<name>.html           las páginas con las que responde
  mails/<language>/<kind>.*   sus propias plantillas de correo
  Shell.php                   su propio comando de php nyuRun
```

Sin `routes.rte`, al run se llega por su nombre en minúsculas
(`notes/view/12`), y por las rutas que el sitio declara para él. Con uno,
solo se llega a él por sus propias rutas (6.8).

### 8.2 Las acciones

`Actions.php` declara la clase `Actions` del espacio de nombres del run,
que extiende `nyu\App\Actionset`. Cada uno de sus métodos públicos es una
acción, a la que se llega por su nombre; los métodos del framework, los
estáticos y los protegidos no lo son.

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

- **`$dfltAction`** nombra la acción que se ejecuta cuando la ruta no
  nombra ninguna; sin él, una petición así es un 500, registrado en
  `runner`, e `install checkAll` lo señala (`default <Run>`). Un run que
  declara sus rutas no lo necesita: su ruta `""` dice lo que responde su
  ruta sola.
- **`openEachAction()`**, cuando el run lo declara, se ejecuta antes de
  cada acción: ahí va una comprobación que comparte todo el run (un rol, un
  ajuste), y puede detener la petición con `stopThisAction()`.
- **El parámetro** de una acción es la parte de la ruta que el `pathForm`
  llama `$argument` (la tercera en los perfiles del framework:
  `notes/view/12`), o el primer valor que captura una ruta declarada. Una
  acción que lo necesita lo declara; una que no toma ninguno lo ignora.
- Una acción sigue las convenciones del proyecto (capítulo 12), salvo en su
  nombre, que es la palabra de la ruta, de cualquier longitud.

### 8.3 Lo que recibe una acción

| Propiedad | Contiene |
|---|---|
| `$this->inputsRegr` | las entradas, definidas por `input/<action>.json`: `getValueOrDflt('name', default)`, `readLinesArray()` |
| `$this->modelStack` | los modelos del run: `getModelObject('name')` |
| `$this->accessRegr` | el resultado del acceso: `loggedIn`, `userName`, `userRole`, `identity` (lo que la base de usuarios dice del usuario), `appsName` para la llamada de una aplicación |
| `$this->requestObj` | la petición: `isSentByMethod()`, `getHeaderValue()`, `getCookieValue()`, `getUploadFiles()`, `getUploadEntry()`, `getServerValue()`, `getClientOfRqt()` |
| `$this->respondObj` | la respuesta, para sus cabeceras |
| `$this->routingObj` | el router, para los enlaces a las rutas declaradas |
| `$this->runContext` | el `RunContext` del que vienen todos ellos, de solo lectura |

Las entradas son los únicos valores enviados por un cliente que una acción
debe usar como datos. Los valores del servidor, y lo que el cliente dice de
sí mismo, se leen a través de la petición, y nunca deciden un acceso (2.8).

### 8.4 Responder

Una acción fija tres propiedades:

- **`statusCode`**: el estado de la respuesta (200 cuando no fija ninguno);
- **`statusText`**: el mensaje de un estado de error;
- **`replyValue`**: los datos de la respuesta, escritos en el formato del
  perfil: un array, un registro, una lista, un texto, un número.

Los métodos auxiliares del actionset:

| Método auxiliar | Hace |
|---|---|
| `stopThisAction($code, $message, $output)` | detiene la acción de inmediato, desde sí misma o desde un método auxiliar, con ese estado |
| `serveFileReply($path, $type)` | responde con un archivo del servidor, por partes cuando lo pide `Range` |
| `servePageReply($name, $values)` | responde con `pages/<name>.html`, con sus marcadores `%name%` rellenados y codificados |
| `redirectClient($address, $code)` | redirige a una ruta del sitio o a una dirección `http(s)` |
| `makeUrlOfRoute($route, $values, $query)` | el enlace a una ruta declarada |
| `setReplyFormat($format)` | otro formato para esta respuesta: `jsonData`, `yamlData`, `xmlNodes`, `csvTable`, `htmlView`, `rawBytes` |
| `getLoggerOfApp()` | el registro del run, en su propio canal |
| `needsActionRle($role)` | detiene la acción con un 404 salvo que el usuario tenga el rol |
| `hasRoleOrAbove($role)` | dice si el usuario tiene el rol |

Una excepción que la acción no captura es un 500, registrado con su traza
(4.7); en producción, al cliente no se le dice nada más.

### 8.5 Los modelos

Un modelo es un registro o una lista de una tabla, declarado una vez y
obtenido por su nombre. `mods/Single.php` declara la clase `Single` de
`nyu\Runs\Notes\mods`, obtenida con `getModelObject('single')`. La forma
antigua, `SingleModel.php`, se sigue encontrando cuando no hay
`Single.php`:

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

- **Un registro** (`DBRec`) nombra su tabla, sus tablas hijas y sus tablas
  padre. Se carga por su clave (`loadRecordList()`) o se encuentra por sus
  campos (`findRecordList()`), con sus relaciones cargadas a la vez; se
  rellena (`setFieldsArray()`), se guarda (`insertOrUpdate()`) y se borra
  (`dropThisRecord()`).
- **Una lista** (`DBLst`) nombra su tabla. Se filtra (`setListFilters()`),
  se eligen sus campos (`setReadColumns()`), se agrupa
  (`setGroupFields()`), se ordena (`setOrderFields()`), se limita
  (`setLimitOffset()`), se une (`joinOtherTable()`), y luego se lee
  (`readRowsOfList()`).
- Un modelo puede nombrar otra base de datos distinta de la de por defecto.
- Las tablas del framework siguen la nomenclatura del capítulo 10: columnas
  de 10 caracteres, `id_<table>` para el identificador, `<table>_id` para
  un enlace.

Los modelos de `runs/Shared` se dan a cada run que no tiene ninguno con ese
nombre. Un modelo se crea la primera vez que una acción lo pide, y luego se
conserva durante la petición: un run solo paga por los modelos que usa su
acción.

### 8.6 Las entradas

Cada acción declara sus entradas en `input/<action>.json` (6.9). Una acción
sin definición no recibe ninguna entrada: las entradas están cerradas por
defecto. `{ "fieldSet": "all" }` es la opción explícita que conserva cada
entrada que construyó el perfil.

### 8.7 Los runs entregados

| Run | Contiene |
|---|---|
| `Auth` | el inicio y el cierre de sesión, la activación, las altas, las contraseñas olvidadas, el cambio de una contraseña o de un idioma, las claves publicadas: `login`, `logout`, `active`, `signup`, `forgot`, `recover`, `setpass`, `usrlang`, `keyset` |
| `Users` | la gestión de los usuarios, para un gestor: `index`, `view`, `create`, `approve`, `setmail`, `langs`, `lock`, `unlock`, `role`, `remove`, `reset` |
| `Shows` | un ejemplo: una lista de espectáculos filtrada, ordenada y limitada (`index`), y un espectáculo con sus eventos y sus artículos (`view`) |
| `Shared` | vacío: los modelos y plug-ins que comparten los runs |

`Auth` declara sus rutas en su propio `routes.rte` (`/auth/login`, …, y
`/.well-known/jwks.json`). `Auth` y `Users` trabajan sobre la base de
usuarios que nombra el bloque `loginSet`, y cada acción de `Users` pide ella
misma el rol `managers`. `Users` es REST: su `routes.rte` nombra al usuario
en la ruta, que se da a la acción como su opción, y el método elige la
acción: `GET`/`POST /users` (index, create), `GET`/`DELETE /users/<user>`,
`PUT /users/<user>/role`, `/email`, `/langs`,
`PUT`/`DELETE /users/<user>/lock`, `POST /users/<user>/reset` y `/approve`;
otro método recibe un 405.

---

## 9. Seguridad

Lo que hace el framework para proteger un proyecto, en resumen. Los ajustes
se tratan en [Alojamiento](hosting.md), los consejos en el capítulo 2, y el
orden de las comprobaciones en el capítulo 4.

### 9.1 Principios

- **Fallar en cerrado.** Una configuración que no se puede aplicar rechaza
  todas las peticiones con un 500 (`Access misconfigured`); nunca recurre a
  una verificación más débil.
- **No decirle al cliente nada que no necesite saber.** Todo lo que no
  puede alcanzar es el mismo 404; un nombre erróneo y una contraseña
  errónea reciben el mismo rechazo; la respuesta a un alta o a una
  contraseña olvidada es la misma pida quien pida; producción no muestra
  ningún detalle de un error.
- **Fiarse solo de lo que lee el servidor.** Las únicas entradas son las
  que declara una acción. Lo que el cliente dice de sí mismo es una pista,
  nunca un motivo para conceder acceso. La dirección de un cliente se lee a
  través de los proxies en los que confía la configuración, y de ningún
  otro.
- **Mantener los secretos fuera de los archivos.** Los secretos viven en
  `.env`; las claves, los tokens y los códigos solo se guardan como hashes;
  un bundle no lleva ninguno.
- **Repartir las tareas.** El framework se encarga de la petición desde el
  momento en que PHP la recibe. El servidor web, TLS, el cortafuegos y
  `php.ini` son cosa del alojamiento
  ([Alojamiento §1](hosting.md#1-propósito-y-reparto-de-tareas)).

### 9.2 Peticiones y entradas

| Riesgo | Lo que hace el framework | Tratado en |
|---|---|---|
| un cuerpo demasiado grande para guardarlo | rechazado con un 413 por encima de `REQ_SIZE` o `REQ_PART`, antes de leerlo | [Alojamiento §11](hosting.md#11-tamaño-de-las-peticiones) |
| valores que la acción no esperaba | solo se conservan los campos de `input/<action>.json`, cada uno en su formato | 6.9 |
| una dirección de cliente falsificada | las cabeceras de reenvío solo se leen de los proxies de `PRX_LIST` | [Alojamiento §3](hosting.md#3-proxy-inverso) |
| una contraseña en un enlace, guardada en registros e historiales | las acciones de `Auth` rechazan una contraseña enviada en la cadena de consulta (400) | |
| un valor de filtro usado como patrón | se compara tal cual; una coincidencia se pide por el nombre del campo (`field~`) | 2.7 |
| la inyección SQL | los almacenamientos envían los valores como parámetros, y solo aceptan nombres hechos de letras, dígitos y guiones bajos | 10 |

### 9.3 Rutas y respuestas

| Riesgo | Lo que hace el framework | Tratado en |
|---|---|---|
| trazar el mapa de lo que existe | un único 404 `Not Found` para una aplicación o una acción desconocida, una ruta cerrada, un run alcanzado por su ruta simple, un archivo que falta, un rol que no se tiene | 4.5 |
| salir de una carpeta | un archivo pedido en una carpeta nunca sale de ella; solo se sirven las extensiones de `Response::TYPE_LIST` | [Alojamiento §13](hosting.md#13-notas-sobre-el-enrutamiento) |
| scripts en una página | los marcadores de las páginas y los correos se codifican en HTML | 8.4 |
| una redirección a otro sitio | una ruta de inicio de sesión debe ser una ruta del sitio; `redirectClient()` solo acepta una ruta o una dirección `http(s)` | |
| la detección del tipo de contenido | `X-Content-Type-Options: nosniff` en cada respuesta | 4.6 |
| llamadas desde otros sitios | `Access-Control-Allow-Origin` solo nombra un origen que liste `corsList` | [Alojamiento §10](hosting.md#10-otros-sitios-que-llaman) |

### 9.4 Credenciales

- **Las contraseñas** se cifran con hash bcrypt. Un nombre desconocido
  cuesta igualmente un hash, así que el tiempo que tarda un rechazo no
  revela qué nombres existen. Un archivo de contraseñas solo acepta
  formatos débiles cuando se le indica (`weakHash`,
  [Alojamiento §6](hosting.md#6-archivos-de-contraseñas)).
- **Las cuentas que no están activas** se rechazan igual que una contraseña
  errónea; el motivo solo está en el registro.
- **Los tokens** llevan una caducidad (`exp`) y un usuario, o se rechazan;
  una vez configurados, su emisor (`iss`) y su audiencia (`aud`) también se
  verifican. Las claves RS256 de menos de 2048 bits se rechazan, y un token
  nombra su clave (`kid`) ([Alojamiento §8](hosting.md#8-tokens)).
- **Las sesiones** terminan tras `lifeSecs` sin uso o `fullSecs` en total.
  Un inicio de sesión recibe un nuevo id de sesión y cierra aquel con el
  que llegó, y la cookie es `HttpOnly`, `Secure` y `SameSite` según el
  ajuste ([Alojamiento §9](hosting.md#9-sesiones-php)).
- **Los códigos de doble factor** se aceptan una sola vez cada uno, y sus
  secretos se guardan cifrados con `tfaCrypt`
  ([Alojamiento §7](hosting.md#7-códigos-de-doble-factor)). Un código
  enviado por correo para un inicio de sesión sale como mucho una vez por
  minuto.
- **Las claves, los tokens personales, los tokens de dispositivos, las
  claves de aplicación, los códigos de activación y de restablecimiento**
  se muestran una vez, se guardan como hashes y se comparan por su hash.
- **Los códigos enviados por correo** duran `codeMins` minutos, y un
  restablecimiento conserva la contraseña hasta que se usa su código
  ([Alojamiento §15](hosting.md#15-correos-de-acceso)).
- **El correo** se envía cifrado, con el certificado del servidor
  verificado.

### 9.5 Los intentos

El límite por dirección (`tryLimit`) cuenta las contraseñas, claves y
códigos erróneos, los tokens falsificados, y cada alta y cada
restablecimiento pedidos: por encima de `maxTries`, la dirección queda en
espera durante `holdSecs` segundos y recibe un 429. Un token caducado, una
credencial que falta o un rol que no se tiene no se cuentan. Las bases de
usuarios que verifican contraseñas (`nativeDb`, `userFile`, `htpasswd`)
también dejan en espera una cuenta tras sus propios `maxTries` intentos
fallidos. Un cliente que ya inició sesión con éxito lleva una marca de
inicio de sesión, firmada con `markSecr`, y sigue entrando mientras su
cuenta está en espera, así que un atacante no puede dejar fuera al
titular. Las altas se limitan por cliente y en
total ([Alojamiento §5](hosting.md#5-límite-por-dirección-y-cuentas-en-espera)).

### 9.6 Errores y registros

- En producción no se muestra nada de un error, diga lo que diga
  `ERR_SHOW`.
- Una traza se registra sin los argumentos de sus llamadas, así que ninguna
  contraseña acaba en un registro.
- Los nombres que da un cliente se escriben en los registros como cadenas
  JSON, así que no se puede falsificar ninguna línea.
- Los usuarios con cualquier rol por encima de `standard` se trazan en cada
  acceso, y cada rechazo se traza con su motivo
  ([Alojamiento §12](hosting.md#12-errores-y-registros)).

### 9.7 La entrega

Un bundle solo copia lo que nombra `Bundle::KEEP_LIST`: ni `.env`, ni
configuración local, ni claves, ni pruebas (7.6).
`php nyuRun install checkAll` falla en lo que expondría un servidor o lo
detendría (un secreto o una clave de doble factor demasiado cortos, una
lista de proxies abierta a todos, ajustes de acceso o un perfil que no se
pueden aplicar, claves de tokens que no se pueden usar o que no forman
pareja), y avisa de lo que merece un vistazo (errores mostrados en un
servidor de preproducción, una versión de PHP sin sus últimas correcciones
de seguridad, un archivo de contraseñas que acepta hashes débiles,
orígenes abiertos a todos, tokens ligados a ningún emisor ni audiencia).

### 9.8 Lo que queda para el alojamiento, o para más adelante

- El servidor web, TLS, el cortafuegos y `php.ini`
  ([Alojamiento](hosting.md)).
- El host reenviado (`X-Forwarded-Host`) no se lee.
- Los certificados de cliente cuyo TLS gestiona un proxy, el anclaje de
  certificados y los límites por ruta quedan para la versión 1.1 (1.5).

---

## 10. Capa de datos

Un run llega a sus datos a través de modelos, registros y listas (8.5),
nunca a través de consultas. Este capítulo describe lo que hay debajo.

```
DBRec, DBLst            los modelos de los runs
   |
DBStore\Store           lo que necesitan un registro y una lista: buscar, insertar, modificar, borrar, transacciones
   |-- SqlStore         los tipos SQL, a través de su gestor
   |      |-- DBHndlr\MySQL, PgSQL, SQLite (PDO)
   |-- MongoStore       MongoDB, a través de su driver
   |
DBCon                   una conexión por base de datos configurada
```

### 10.1 Conexiones

Las bases de datos son las de `conf/database.json` (6.6): una, o varias por
su nombre, siendo la primera la de por defecto. Una conexión se abre la
primera vez que se usa, y se conserva durante la petición:
`DBCon::linkNamedDbCon('name')` la da, y `''` da la base de datos por
defecto. Un modelo nombra otra base de datos distinta de la de por defecto
a través de su conexión.

Cada tipo SQL tiene su gestor, sobre PDO, que escribe las condiciones en su
propio dialecto: MySQL (MariaDB incluido), PostgreSQL y SQLite. El gestor de
SQLite activa las claves foráneas en cada conexión, como las tienen las
demás bases de datos. MongoDB es un almacenamiento aparte (10.8).

### 10.2 Nomenclatura

Las tablas del framework siguen un único esquema de nombres, en el que se
apoyan los registros y las listas para encontrar sus relaciones sin que se
las indiquen:

| Elemento | Regla | Ejemplo |
|---|---|---|
| tabla | un nombre en plural | `shows`, `events` |
| columna | 10 caracteres | `systemName`, `createDate` |
| identificador | `id_` y el nombre de la tabla en singular | `id_show` |
| enlace a otra tabla (uno a varios) | el nombre de esa tabla en singular, y `_id` | `show_id` |
| enlace a un padre en la misma tabla | `parent_id` | |

La longitud de las columnas es la convención del framework: las tablas
propias de un proyecto pueden usar otras longitudes, siempre que los
identificadores y los enlaces sigan la nomenclatura. Una relación siempre
puede nombrar sus campos cuando una tabla no la sigue.

### 10.3 Registros

Un registro (`DBRec`) se liga a su tabla cuando se crea: su clave y sus
campos se leen del almacenamiento, una vez por tabla y por petición, o de
la caché de configuración con `TBL_CACH`.

- **Carga**: `loadRecordList($key)` por su clave, `findRecordList($filter)`
  para el primero que coincide con un filtro (10.5). Los dos dan los campos
  del registro, o `false`, y cargan sus relaciones declaradas.
- **Relaciones**: las tablas hijas que declara un registro se cargan con
  él, cada una bajo el nombre de su tabla con la primera letra en
  mayúscula (`Events`); un padre, bajo su nombre en singular (`Show`). Un
  hijo se puede declarar con su orden.
- **Guardado**: `setFieldsArray($values)` toma los valores de los campos
  que tiene la tabla y deja los demás. `insertOrUpdate()` modifica la fila
  de la clave, o inserta una nueva, con la clave dada o creada por la base
  de datos. La propia clave nunca se modifica. Devuelve la clave, o
  `false`: tal como la dio la base de datos para una modificación, como un
  número cuando la base de datos la numeró, o tal como la fijó la
  aplicación en los demás casos.
- **Borrado**: `dropThisRecord($children)` borra el registro y las filas de
  las tablas hijas nombradas en la llamada, en una sola transacción, y nada
  más. El borrado se traza en `runner`, con el usuario y la dirección de la
  petición. Una base de datos sin transacciones borra un registro solo, y
  rechaza un borrado que nombra hijos.

### 10.4 Listas

Una lista (`DBLst`) se liga a su tabla, se configura y luego se lee:

| Método | Fija |
|---|---|
| `setListFilters($filter)` | el filtro (10.5), en lugar del anterior |
| `setReadColumns('a, b, count(*) as n')` | los campos leídos, y funciones sobre ellos, con alias |
| `setGroupFields($fields)` | los campos por los que se agrupan las filas |
| `setOrderFields($fields)` | el orden; un campo escrito `^name` se ordena de forma descendente |
| `setLimitOffset($count)` | cuántas filas, a partir de un número o de `"count,offset"`; `DEF_ROWS` cuando el número no es un número |
| `joinOtherTable([table, field, field])` | una tabla unida (`LEFT JOIN`), solo SQL |
| `readRowsOfList()` | lee las filas; `listColumnVals('field')` lee un campo de cada una |
| `loadChildRecrd($table)` | añade a cada fila leída sus filas de una tabla hija |

### 10.5 Filtros

Un filtro es un array de nombres de campos y valores. Se lee de la misma
forma para todas las bases de datos (`DBStore\Filter`), y cada
almacenamiento lo escribe en su propio lenguaje:

| Escrito | Selecciona las filas en las que el campo |
|---|---|
| `'field'=>value` o `'field='=>value` | es igual al valor |
| `'field!'=>value` | es distinto de él |
| `'field<'`, `'field<='`, `'field>'`, `'field>='` | está por debajo o por encima |
| `'field'=>[a, b]`, `'field!'=>[a, b]` | es uno de los valores, o ninguno de ellos |
| `'field~'=>'*text*'`, `'field!~'=>'*text*'` | coincide con el texto, o no; `*` es cualquier texto, `\*` un asterisco y `\\` una barra invertida |
| `'field?'=>false`, `'field?'=>true` | no tiene ningún valor, o tiene uno |

Una fila debe cumplir todas las condiciones del filtro. Un valor se compara
tal cual: ninguno de sus caracteres es un comodín, así que un valor tomado
de una petición no puede ampliar una búsqueda. Solo un campo nombrado con
`~` busca coincidencias, y solo `*` es ahí un comodín;
`Filter::quoteMatchText()` protege un texto tomado de una petición, para
que solo coincida consigo mismo. El patrón se lee una vez, en `Filter`:
cada almacenamiento escribe lo que se ha leído (`LIKE … ESCAPE`, una
expresión regular). Un valor `null` no coincide con nada, así que una
entrada vacía no puede seleccionar las filas que quedaron sin valor. Un
nombre de campo se compone de letras, dígitos y guiones bajos, con una
tabla opcional (`shows.title`); cualquier otra cosa se rechaza.

### 10.6 Transacciones

El almacenamiento de una base de datos (su `Store`) dice si tiene
transacciones (`canTransaction()`), y abre una (`addTransaction()`), la
confirma (`setTransaction()`) o la cancela (`dropWholeTrnsc()`). Una acción
llega a ellas a través de la conexión:

```php
$dbStore = DBCon::linkNamedDbCon()->getStoreObject();
$dbStore->addTransaction();
// records saved and removed
$dbStore->setTransaction();
```

Los tipos SQL tienen transacciones; MongoDB, tal como lo usa nyuRuns, no
tiene.

### 10.7 Valores binarios

Una imagen, un sonido o cualquier archivo se guarda en un campo como valor
binario:

- se escribe a partir de un flujo (`Data\Fluxd`) dado como valor del campo:
  un archivo en el servidor, el cuerpo de la petición, bytes en memoria.
  Entre el flujo y la base de datos se mantiene entero, se escribe de una
  sola vez, y se vuelve a leer hasta su límite (uno mayor se rechaza);
- se vuelve a leer como un flujo, solo cuando el campo se nombra entre los
  campos pedidos: una lectura que no lo nombra lo deja fuera;
- una acción lo envía tal cual con el formato de respuesta `rawBytes`.

### 10.8 MongoDB

A MongoDB se llega a través de la extensión `mongodb` de PHP, como el
almacenamiento `MongoStore`. Una tabla es una colección, una fila un
documento, y la clave de cada tabla es el `_id` que da MongoDB. Lo que
cambia respecto a los tipos SQL:

- una colección no tiene una lista fija de campos;
- los valores se comparan con su tipo: el texto `'2'` no es el número `2`;
- las funciones, las uniones y las agrupaciones se rechazan;
- no hay transacciones (necesitarían un replica set), así que un registro
  se borra solo, y nombrar sus hijos en el borrado se rechaza.

### 10.9 Tablas entregadas

`conf/schema/` contiene las tablas que usa el framework, un archivo por
tabla y por tipo de base de datos: `users` y `devices`, en `.mysql_db.sql`
(MariaDB incluido), `.postgres.sql` y `.sqlitedb.sql`.
`php nyuRun install userBase` las crea; una tabla que existe se deja como
está. Los archivos `migrate-<table>-<version>.<type>.sql` adaptan una tabla
creada por una versión anterior, renombrando sus columnas si hace falta: se
aplican a mano, después de una copia de seguridad.

### 10.10 Identificadores que viajan

Un registro copiado a otra base de datos o a otra aplicación conserva su
identidad con un UUID de la versión 7 (`Tools\UniqueId`, RFC 9562). Empieza
por la hora en que se creó, así que los identificadores se ordenan en ese
orden, y termina con bits aleatorios. En la 1.0 el framework los crea, pero
no se los da por sí mismo a un registro: una tabla que necesita uno lo
guarda en un campo propio, junto a su clave, rellenado por la acción que
crea el registro.

---

## 11. Estructura de carpetas

### 11.1 El proyecto

```
nyuRuns/
  www/                    la única carpeta que sirve el servidor web
    index.php             el único punto de entrada: arranca el Runner, que elige su perfil en conf/doorways.lst
  src/                    el framework, espacio de nombres nyu\ (capítulo 5)
  runs/                   las aplicaciones (capítulo 8)
    Auth/  Users/  Shows/
    Shared/               los modelos y plug-ins que comparten los runs
  conf/                   la configuración (capítulo 6)
    presets/              los perfiles (.rps)
    routes/               las rutas declaradas del sitio (.rte), una carpeta por perfil que tenga las suyas
    schema/               las tablas entregadas y sus migraciones (.sql)
    mails/<language>/     las plantillas de los correos de acceso
    keys/                 las claves de los tokens (locales)
  pages/                  las páginas y los archivos estáticos de los sitios (dispatch.fileRoot)
  media/                  los archivos multimedia del proyecto, fuera de git
  temp/                   lo que escribe el framework (local)
  test/                   la batería de pruebas (capítulo 13)
  vendor/                 las bibliotecas, instaladas por Composer (locales)
  docs/                   la documentación, en docs/en (fr, es); docs/online, su HTML y la referencia del código (generados, sin seguimiento)
  nyuRun                  la línea de comandos: php nyuRun <command>
  composer.json, composer.lock
  .env.example            los nombres de los secretos
  README.md, HOSTING.md, CONTRIBUTING.md, LICENSE, NOTICE
```

El servidor web sirve `www` y nada por encima: la configuración, el código,
los registros y las pruebas nunca son accesibles por HTTP. Las páginas y
los archivos de un sitio los sirve el framework desde `pages/` (o la
carpeta que nombra su perfil), solo a través de las rutas declaradas para
ellos.

### 11.2 Con seguimiento, locales y generados

| Tipo | Dónde | En git | En un bundle |
|---|---|---|---|
| el framework y sus runs | `src/`, `runs/`, `www/`, `nyuRun` | sí | sí |
| los ejemplos de configuración y los perfiles, rutas, esquemas, plantillas | `conf/` | sí | sí |
| las páginas de los sitios | `pages/` | sí | sí |
| la configuración local | `.env`, `conf/access.json`, `database.json`, `mailer.json`, `users.lst`, `.htpasswd` | no | no |
| las claves de los tokens | `conf/keys/` | no | no, salvo que los `addPaths` de un modo nombren un archivo |
| los modos de bundle del proyecto | `conf/bundle.lst` | sí | no |
| las bibliotecas | `vendor/` | no | sí |
| lo que escribe el framework | `temp/` | no | no |
| las pruebas, los documentos | `test/`, `docs/` | sí | no |
| el HTML de la documentación y la referencia del código | `docs/online/`, generado por `.phpdoc/build.ps1` | no | no |
| los bundles | `dist/` | no | |

Un bundle solo copia las rutas de `Bundle::KEEP_LIST` (`src`, `runs`,
`www`, `pages`, `conf`, `vendor`, los archivos de Composer, `nyuRun`,
`LICENSE`, `NOTICE`, `.htaccess`), menos las de `DROP_LIST` (la
configuración local, cada archivo de `conf/keys`, `bundle.lst`), más las
rutas que añade su modo. Cada servidor crea sus propias claves con
`install complete`.

### 11.3 `temp/`

| Carpeta | Contiene | La vacía |
|---|---|---|
| `logs/` | los registros, un archivo por canal (`LOG_PATH`) | `cleanup cleanDir logFiles` |
| `limits/` | los recuentos del límite por dirección (`tryLimit.dataPath`) | `cleanup cleanDir tryLimit`, `cleanup freeAddr` |
| `archives/` | los archivos de configuración tal como estaban antes de que un comando los cambiara | `cleanup cleanDir archives` |
| `cache/` | la caché de configuración, un archivo por perfil (`CNF_CACH`) | `cleanup cleanDir cacheDir`, `setting clrCache`, cualquier comando que escriba en `conf/` |
| `mails/` | los correos de una cuenta `emlFiles` | a mano |

Solo el usuario con el que se ejecuta el servidor web necesita escribir ahí
(3.7).

### 11.4 Un run

```
runs/<App>/
  Actions.php             las acciones, clase Actions de nyu\Runs\<App>
  mods/<Name>.php         los modelos, clase <Name> de nyu\Runs\<App>\mods
  input/<action>.json     las entradas de cada acción
  routes.rte              sus propias rutas
  pages/<name>.html       las páginas con las que responde
  mails/<language>/       sus propias plantillas de correo
  Shell.php               su propio comando
```

### 11.5 Las pruebas

```
test/
  run.php                 ejecuta la batería, o los grupos nombrados
  unit/  audit/  data/  http/   los grupos (capítulo 13)
  lib/                    los métodos auxiliares de las pruebas
  fixtures/               las configuraciones, rutas, páginas, runs y archivos que usan las pruebas
```

---

## 12. Convenciones de código

nyuRuns sigue un único conjunto de convenciones, en el framework y en los
runs que trae. Una contribución también las sigue, y el grupo de pruebas
`audit` comprueba la mayoría (12.5).

### 12.1 Nombres de longitud fija

Cada nombre está en `camelCase` y tiene la longitud de su tipo, así que un
nombre dice de un vistazo lo que es. Las longitudes dejan sitio para
palabras cortas enteras en lugar de una sola palabra comprimida. Las clases
son la excepción: `PascalCase`, de cualquier longitud.

| Elemento | Longitud | Ejemplo |
|---|---|---|
| método | 14 | `makeAccessCode()`, `getModelObject()` |
| parámetro | 12 | `$requiredRole`, `$appNameToRun` |
| propiedad | 10 | `$requestObj`, `$modelStack` |
| variable local | 8 | `$fileName`, `$rowsList` |
| variable de bucle | 6, `one` o `nth` y el elemento | `$oneRow`, `$nthRow` |
| constante | 9, con la forma `XXXX_YYYY` | `KEYS_LIST`, `TYPE_LIST` |

Quedan exentos de las longitudes:

- las acciones de cada run, entregado o propio del proyecto (`login`,
  `index`, `view`, `card`): su nombre es la palabra de la ruta, y la
  auditoría no toca ningún método de `runs/*/Actions.php`;
- los modos de bundle, nombrados libremente como una acción (`local`,
  `stage`, `final`, y los de `conf/bundle.lst`): para qué sirve un modo, lo
  lleva como `ENV_NAME`;
- los métodos PSR-3 de `Tools\Logger` (`error`, `warning`, `notice`,
  `info`, `debug`) y sus constantes de nivel (`LEVL_ERROR`, …);
- la variable de un `catch`, `$e`;
- los métodos mágicos de PHP, y los métodos que impone una interfaz de PHP
  (`jsonSerialize`, `getIterator`, `count`, …);
- los nombres estándar, sea cual sea su longitud: los métodos HTTP, las
  extensiones de archivo, los tipos MIME, los nombres `E_*`, los nombres de
  cabeceras;
- por ahora, las variables de bucle de 3 letras listadas en
  `test/audit/looplabels.txt`.

Un nombre se construye a partir de huecos, siempre en el mismo orden, y
`NAMERULES.md` contiene toda la gramática. En resumen:

- un método es un verbo de una lista cerrada, luego aquello sobre lo que
  actúa, y luego el tipo de lo que devuelve cuando no es evidente
  (`readRouteList()`, `makeAccessCode()`);
- un parámetro es su papel, su sujeto y su tipo (`$requiredRole`,
  `$givenUserName`), una propiedad su sujeto y su tipo (`$accessConf`), una
  variable local una o dos palabras sencillas (`$fileName`, `$response`);
- un sí o no es siempre una pregunta: `is`, `has`, `can`, `needs` o `was`,
  y luego el sujeto (`hasWrongSecret()`, `$isLoggedIn`);
- cada palabra se escribe entera cuando cabe, o en su única forma corta de
  3, 4 o 5 letras del glosario (`Rqst`, `Mssg`, `Modl`); la longitud se
  alcanza con una escritura más larga de un hueco, nunca con una palabra de
  más.

Un concepto tiene una sola palabra en todas partes (`$requestObj` es la
petición en todas las clases), y una palabra tiene un solo significado.

La misma regla vale más allá del código:

| Elemento | Regla | Ejemplo |
|---|---|---|
| claves de un bloque de configuración | 8, en los archivos JSON y en el destino de una ruta | `carrWith`, `appToRun` |
| claves de `system.ini` | 8, con la forma `XXX_YYYY`; los códigos entre sus valores tienen 4, en mayúsculas | `LOG_MODE`, `DEVS`, `INDX` |
| códigos generados en ejecución | 8; en minúsculas cuando nombran un archivo (un perfil, un tipo de correo, un tipo de base de datos) | `standard`, `advanced`, `delegate`, `managers` |
| columnas de una tabla | 10 | `systemName` |
| comandos de `php nyuRun`, y sus subcomandos | 7 para un comando, 8 para un subcomando | `install complete`, `cleanup cleanDir` |
| opciones de `php nyuRun` | 8 | `--noPrompt`, `--yesToAll` |
| formatos de `Tools\Variable` | 7, para que cada método `formats…()` tenga 14 | `OutText`, `formatsOutText()` |

### 12.2 Los tipos

- Cada parámetro y cada valor de retorno lleva tipo, con los tipos escritos
  en mayúscula: `String`, `Array`, `Bool`, `Int`, `Float`, `Mixed`, `Void`,
  `Never`.
- Un valor de varios tipos recibe su unión precisa (`Array|Bool`,
  `?String`); `Mixed` se reserva para lo que de verdad puede ser cualquier
  cosa.
- Un parámetro cuyo valor por defecto es `null` se declara anulable
  (`?String $userNameText=null`).
- No se usa `declare(strict_types=1)`.

### 12.3 La documentación

- Cada método público tiene un docblock: lo que hace, luego `@param` para
  cada parámetro, `@return`, y `@throws` para lo que deja pasar.
- Una clase empieza con un docblock que dice qué es y cómo se usa.
- Un comentario dice por qué, no qué, y se escribe en inglés, como los
  nombres.
- La referencia del código se genera a partir de los docblocks con
  phpDocumentor (`phpdoc.dist.xml`), en `docs/online/reference`.
  `pwsh .phpdoc/build.ps1` la genera junto con las páginas de estos
  documentos en cada uno de sus idiomas (`docs/en`, `fr`, `es`), escritas
  por el analizador de Markdown y el generador de HTML propios del
  framework (`.phpdoc/site`): una sola carpeta en `docs/online`, que git no
  sigue, para publicar tal cual.

### 12.4 Los errores

- Un error del framework es una `RuntimeException`, o una clase que la
  extiende (`StopRun`, `ShellStop`, `ParseError`, `WriteError`,
  `MailFailure`, `QrCodeError`).
- Un mensaje dice lo que ha ido mal con palabras que un desarrollador lee
  en un registro. Nunca contiene un secreto, y los nombres que da un
  cliente se escriben como cadenas JSON.
- Lo que se le puede decir a un cliente lo decide el estado, no el mensaje
  de una excepción (4.7).

### 12.5 La auditoría

`php test/run.php audit` lee `src/` y `runs/` con el tokenizer de PHP, y
falla con:

- un archivo que no se puede analizar;
- una clase que no está en `PascalCase`, o una constante que no está en
  `XXXX_YYYY`;
- un método, parámetro, propiedad, variable local o de bucle que no tiene
  su longitud;
- un método público sin docblock;
- un parámetro o un valor de retorno sin tipo.

`php test/audit/ConventionTest.php --list` muestra cada hallazgo, incluidos
los aceptados. Las longitudes de las claves de configuración, las columnas
y los códigos no se auditan: se comprueban cuando se revisa un cambio.

### 12.6 Las contribuciones

Una contribución se firma según el Developer Certificate of Origin
(`git commit -s`), trae sus pruebas, y pasa la batería y la auditoría, como
explica [`CONTRIBUTING.md`](../../CONTRIBUTING.md) (en inglés). Un nombre
nuevo o renombrado se propone antes de escribirlo, ya que cambia cómo se
lee el código.

---

## 13. Pruebas

La batería se compone de scripts PHP que se comprueban a sí mismos, sin
más dependencia que PHP y sus extensiones: no hay ningún framework de
pruebas que instalar. Contiene unas 4 500 comprobaciones, y
[`test/README.md`](../../test/README.md) (en inglés) tiene los detalles.

### 13.1 Ejecutarla

```
php test/run.php                 todos los grupos
php test/run.php unit audit      los grupos nombrados
php test/run.php Variable        los scripts cuyo nombre contiene la palabra
php nyuRun testing              unit, audit y data, desde la línea de comandos
```

Cada `*Test.php` se ejecuta en su propio proceso PHP, y se puede ejecutar
solo (`php test/unit/VariableTest.php`). El lanzador muestra cada script,
luego el total de comprobaciones superadas, fallidas y saltadas, y termina
con un código de fallo cuando falla una comprobación.

### 13.2 Los grupos

| Grupo | Scripts | Comprueba |
|---|---|---|
| `unit` | 95 | cada parte por separado: formatos, petición, router, acceso, portadores y verificaciones, tokens, límites, registros, respuesta, gestor de errores, documentos, imágenes, sonidos, correo, la línea de comandos |
| `data` | 24 | las bases de datos: gestores, almacenamientos, filtros, registros, listas, la base de usuarios, los dispositivos, los traslados entre bases de usuarios, los runs que las usan; sobre bases SQLite temporales, y sobre servidores reales cuando están configurados |
| `http` | 1 | peticiones completas a través del servidor web integrado de PHP: estados, cabeceras, cuerpos, rutas, acceso, páginas, archivos |
| `audit` | 1 | las convenciones de código en `src/` y `runs/` (12.5) |

### 13.3 Lo que necesitan las pruebas

- **PHP y sus extensiones.** Los grupos `data` y `http` necesitan
  `pdo_sqlite`, y se saltan sin ella; las comprobaciones de doble factor
  necesitan `openssl`; las imágenes, los sonidos y los documentos se saltan
  lo que su extensión (`gd`, `imagick`, `zip`) o ffmpeg no permite. Un PHP
  de línea de comandos sin `php.ini` recibe sus extensiones a través de
  `PHPRC`, que apunta a una carpeta cuyo `php.ini` las lista: el lanzador y
  la prueba `http` arrancan otros procesos PHP, que no reciben ninguna
  opción `-d`.
- **Servidores de bases de datos reales, si quieres.** `MySQLTest`,
  `PgSQLTest` y `MongoDBTest` ejecutan las mismas comprobaciones en
  servidores reales, a partir de los nombres `NYU_TEST_*` de `.env`. Cada
  una trabaja en una base de datos propia cuyo nombre contiene `test`
  (`nyuruns_test`), rechaza cualquier otra, y se salta cuando su servidor
  no está configurado o no responde.
- **Nada fuera del proyecto.** Las pruebas escriben en la carpeta temporal
  del sistema o en sus propias carpetas; el grupo `http` escribe en el
  `temp/logs` del proyecto. Una prueba que cuenta credenciales erróneas
  fija sus propios límites, así que el `temp/limits` del proyecto queda
  intacto.

### 13.4 Escribir una prueba

```php
<?php
require __DIR__.'/../lib/harness.php';

checkIdentical('label', $actual, $expected);          // strict comparison
checkTrueValue('label', $condition);
checkPregMatch('label', $text, '/pattern/');
checkForThrows('label', function(){ ... }, 'RuntimeException');

finishChecking();
```

- Un script vive en `test/<group>/<Name>Test.php`, y sigue las
  convenciones del capítulo 12.
- Una notificación o un aviso lanzado por el código probado hace fallar el
  script, salvo que la prueba lo recoja (`takePhpNotices()`).
- `recordSkipping()` dice por qué una comprobación no se puede ejecutar
  aquí; `inTemporaryDir()` da una ruta en la carpeta temporal;
  `mockRequestObj()` y `makeFakeServer()` construyen una petición sin
  servidor web.
- Los fixtures (configuraciones, rutas, páginas, un run de prueba llamado
  `Probe`, un archivo de usuarios, documentos) viven en `test/fixtures`.
- Una prueba que lee los registros conecta su propia carpeta
  (`Logger::loadLogsConfig()`), y la desconecta al final.

### 13.5 Antes de una versión

Una versión se cierra con una pasada completa de la batería: todos los
grupos, `http` incluido, en la versión más antigua admitida de PHP (8.2) y
en la más reciente, con los servidores SQL que admite la versión (capítulo
3) y MongoDB.

---
