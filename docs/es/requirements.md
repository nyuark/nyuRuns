# nyuRuns — Requisitos del proyecto

Versión 1.0

## Índice

1. [Visión general](#1-visión-general)
2. [Propósitos y objetivos](#2-propósitos-y-objetivos)
3. [Principios aplicados](#3-principios-aplicados)
4. [Público objetivo](#4-público-objetivo)
5. [Funcionalidades básicas](#5-funcionalidades-básicas)
6. [Requisitos detallados](#6-requisitos-detallados)
7. [Objetivos de rendimiento](#7-objetivos-de-rendimiento)
8. [Notas adicionales](#8-notas-adicionales)

---

## 1. Visión general

### 1.1 El proyecto

nyuRuns es un microframework para construir y desplegar servicios de API
RESTful. Permite a programadores y equipos desarrollar y entregar el
back-end completo de una aplicación.

Su objetivo principal es ofrecer una base para componentes dirigidos por
los modelos de datos, para que los desarrolladores se centren en el negocio
que construyen. Las operaciones habituales sobre los datos (crear, leer,
modificar, eliminar y listar) se dan a cada fuente de datos, de forma
configurable: así cualquier programador puede construir servicios web sobre
un conjunto de datos o un conjunto de modelos. Una vez que hay unos pocos
ajustes en su sitio, los usuarios de un servicio pueden listar, leer,
escribir y eliminar los elementos de una fuente de datos, normalmente una
base de datos. Otras fuentes, como archivos (XML, JSON, INI y texto plano),
pueden sumarse por el camino.

El framework está hecho para encargarse de toda la petición HTTP, y reducir
el trabajo que supone tratarla. También trae herramientas integradas que
simplifican el desarrollo: el enrutamiento de cada petición entrante, con
soluciones de respaldo; el control de acceso y los tokens para la
seguridad; y gestores de datos para bases de datos, archivos, imágenes y
sockets. La salida viene con funciones de caché y la gestión de errores. Al
final, nyuRuns da a los desarrolladores una arquitectura por capas para los
servicios web, y una base para construir API back-end en un abrir y cerrar
de ojos.

### 1.2 La ambición

nyuRuns aspira a ser tan eficiente como los microframeworks, a la vez que
ofrece las funcionalidades clave, y parte de las capacidades, de los más
pesados. Está hecho para apoyarse en tecnologías modernas, y para ser una
herramienta completa, lista para usar.

### 1.3 Lo que cubre este documento

Este documento dice lo que nyuRuns debe lograr, y por qué: sus propósitos,
los principios que aplica, a quién se dirige, sus funcionalidades, sus
requisitos detallados y sus objetivos de rendimiento. Para cada requisito,
dice en qué punto está la versión 1.0: cumplido, cumplido en parte, dejado
para la versión 1.1, llevado a nyuTron (el proyecto aparte de herramientas
alrededor de nyuRuns), o abandonado.

Lo que hace nyuRuns se describe en las
[Especificaciones funcionales](funcspecs.md), cómo está construido en las
[Especificaciones técnicas](techspecs.md), y cómo usarlo en la
[Guía del usuario](userguide.md).

---

## 2. Propósitos y objetivos

### 2.1 Propósitos

El propósito de nyuRuns es simplificar la construcción de API repartiendo
el trabajo que requieren. Puedes construir encima aplicaciones complejas,
pero su primer propósito es mantener las cosas simples.

1. **Repartir el trabajo.** El framework se encarga de la petición, y el
   desarrollador solo escribe lo que el negocio necesita.
2. **Mantenerlo simple.** Un servicio empieza pequeño, solo carga lo que
   usa, y crece sin reescribirse.
3. **Trabajar a través de modelos.** Una aplicación llega a sus datos a
   través de modelos de esos datos, sea cual sea la fuente.
4. **Ser eficiente.** Una petición no cuesta más que con un microframework.
5. **Ser completo.** Las funcionalidades clave de los frameworks más
   pesados, y parte de sus capacidades, vienen listas para usar,
   construidas sobre tecnologías modernas.

### 2.2 Objetivos

Cada propósito lleva a objetivos que se pueden comprobar:

| # | Objetivo | Propósito | 1.0 |
|---|---|---|---|
| O1 | Una aplicación declara sus acciones, sus entradas y sus URL; no tiene ningún código que lea una petición, compruebe el acceso o escriba una respuesta | 1 | Cumplido |
| O2 | Cada fuente de datos recibe la creación, la lectura, la modificación, la eliminación y el listado, a través de los mismos modelos | 3 | Cumplido para las bases de datos |
| O3 | Una tabla se sirve solo con los ajustes, sin ninguna acción escrita para ella | 1, 3 | Cumplido de otra forma: `makerun` escribe el run de una tabla (rutas, acciones, entradas), así que ninguno se escribe a mano; nyuTron lo rodea de un asistente visual |
| O4 | Los archivos son una fuente de datos igual que las bases de datos, configurada en los ajustes | 3 | Versión 1.1; por ahora, una acción lee y escribe los archivos como documentos |
| O5 | Una primera instalación se hace con un solo comando y no necesita ninguna base de datos | 2 | Cumplido |
| O6 | Un servicio pasa de un archivo de usuarios a una base de datos, de una base de datos a varias, y de una aplicación a varias que trabajan juntas, sin reescribir su código | 2 | Cumplido |
| O7 | Una petición no cuesta más CPU que con los microframeworks habituales, y bastante menos que con los frameworks completos | 4 | Cumplido (capítulo 7), medido en Linux el 24 y 25 de noviembre de 2026, después del ciclo de vida y las rutas de los runs, la revisión de seguridad y el cambio de nombres |
| O8 | El inicio de sesión, los roles, los límites de intentos, los códigos de doble factor, los tokens firmados y el ciclo de vida de las cuentas vienen con el framework, sin ninguna biblioteca que añadir | 5 | Cumplido |
| O9 | El propio framework lee, escribe y envía documentos, imágenes, sonidos y correos electrónicos | 5 | Cumplido |
| O10 | Un proyecto se instala, configura, comprueba, prueba y entrega desde una sola línea de comandos | 5 | Cumplido |
| O11 | El framework se apoya en los estándares actuales: PHP 8.2 y posteriores, tokens firmados (RS256), códigos de un solo uso (RFC 6238), identificadores de la versión 7 (RFC 9562), los formatos ofimáticos de hoy | 5 | Cumplido |
| O12 | Los errores llegan al cliente en el formato del servicio, y nunca en detalle en producción | 1 | Cumplido |

Los objetivos que la 1.0 no cumple del todo vuelven a aparecer, requisito
por requisito, en el capítulo 6.

---

## 3. Principios aplicados

Cuatro principios guían cada elección de nyuRuns. Cada uno se enuncia aquí
con aquello a lo que compromete al framework. Cómo los muestra un servicio
está en las
[Especificaciones funcionales, 3.1](funcspecs.md#31-los-principios-tal-como-los-muestra-un-servicio).

### 3.1 MDD: el desarrollo dirigido por modelos

Una aplicación se describe por sus modelos de datos, y trabaja a través de
ellos.

- Una aplicación lee y escribe sus datos a través de modelos (registros y
  listas), nunca a través de consultas que escribe ella misma.
- Un modelo se declara una vez, por la tabla que representa: su clave, sus
  campos y sus relaciones se deducen de la tabla y de sus nombres.
- Cambiar de base de datos, o de tipo de base de datos, no toca las
  aplicaciones, dentro de lo que ese tipo de base de datos sabe hacer.
- `makerun` se apoya en los modelos: escribe las acciones del run de una
  tabla, para que una tabla se sirva sin código escrito a mano. nyuTron,
  las herramientas alrededor del framework, lo rodea de un asistente visual.

### 3.2 KISS: mantenerlo simple

Lo que un servicio no necesita ni se instala, ni se carga, ni se configura.

- Una primera instalación se hace con un solo comando, sin base de datos.
- Una petición solo carga lo que usa: una petición que no llega a ninguna
  base de datos no abre ninguna, y un modelo solo se crea cuando una acción
  lo pide.
- Los ajustes son simples archivos, cambiados de valor en valor, y cada
  valor se comprueba cuando se escribe.
- Cada parte hace una sola cosa, y un proyecto puede añadir las suyas (una
  verificación de credenciales, un portador, un comando) junto a las del
  framework, sin cambiarlas.
- Un servicio crece añadiendo, nunca reescribiendo lo que tiene.

### 3.3 REST

Cada recurso tiene su propia URL, el método de la petición dice qué se hace
con él, y el estado de la respuesta dice cómo ha ido.

- El método decide la acción: una ruta puede dar a cada método de una URL
  su propia acción.
- El estado es siempre el que HTTP da para el caso (201 para algo creado,
  404 para algo que no se encuentra, 409 para un conflicto, …), sea cual sea
  el formato del cuerpo.
- Una petición lleva todo lo que el servicio necesita para responderla: un
  token o una clave, nunca un estado guardado entre dos peticiones, salvo la
  sesión que un sitio decide usar.
- A un cliente se le dice lo que necesita y nada más: lo que no puede
  alcanzar «no se encuentra», exactamente igual que lo que no existe.

### 3.4 CRUDL: crear, leer, modificar, eliminar, listar

Cada fuente de datos recibe las mismas cinco operaciones, escritas de la
misma forma.

- Un registro se crea, se lee, se modifica y se elimina; una lista se lee,
  se filtra, se ordena, se limita y se une con otras tablas.
- El filtro de una lista se escribe de la misma forma para todos los tipos
  de base de datos, y un valor que viene de una petición nunca se lee como
  un patrón.
- La visión original llamaba a estas operaciones CRUDI, con la I del índice
  de una lista. CRUDL es el nombre que se quedó.

### 3.5 Las reglas que se derivan

Los principios llevan a unas pocas reglas que valen en todo el framework:

- **Declarado, no programado**: las rutas, las entradas, los roles y los
  ajustes se declaran en archivos, así que puedes saber lo que hace un
  servicio sin leer su código. Cada run declara sus propias rutas, junto a
  sus acciones.
- **Cerrado por defecto**: en un perfil que pide credenciales, cada ruta
  exige una salvo que se abra a propósito, y un ajuste que no se puede
  aplicar rechaza todas las peticiones.
- **Un solo sistema de nombres**: cada nombre del código, de los ajustes y
  de las tablas tiene la longitud de su tipo, para que un nombre te diga lo
  que es
  ([Especificaciones técnicas, capítulo 12](techspecs.md#12-convenciones-de-código)).

---

## 4. Público objetivo

### 4.1 Quién construye con nyuRuns

| Lector | Necesita que nyuRuns le permita |
|---|---|
| **Un desarrollador PHP** | escribir un back-end sin escribir su fontanería: el enrutamiento, el acceso, las entradas, las respuestas, los errores |
| **Un equipo** | compartir una sola forma de escribir servicios: la misma organización, los mismos nombres y las mismas convenciones en cada proyecto, comprobados por las pruebas |
| **Una pequeña empresa, o su proveedor** | tener su propio back-end en un alojamiento PHP corriente, sin una pila pesada que instalar, vigilar y actualizar |
| **Un analista o un responsable de producto** | saber lo que hará un servicio, y lo que cada usuario puede alcanzar, a partir de sus especificaciones y sus ajustes en lugar de su código |
| **La persona que lo aloja** | instalar, comprobar y actualizar un proyecto desde una sola línea de comandos, con lo que el servidor debe ofrecer listado en un solo sitio |

nyuRuns espera que sus desarrolladores conozcan PHP y HTTP. No necesita
ningún lenguaje de plantillas, sus modelos se reducen a un puñado de
métodos, y su contenedor no se interpone en el camino de las aplicaciones.

### 4.2 Lo que se construye con él

- **Back-ends de negocio**: los registros de una empresa (socios,
  contactos, pedidos, tareas, documentos) y las personas que trabajan con
  ellos, con sus roles.
- **API para front-ends**: una app web o móvil, escrita en cualquier
  lenguaje, que habla con el servicio en JSON.
- **Servicios que trabajan juntos**: varios servicios, cada uno dueño de
  sus datos, uno de los cuales inicia la sesión de los usuarios para los
  demás, y todos se llaman entre sí con claves.
- **Servicios para máquinas**: dispositivos y programas que llaman con sus
  propias credenciales, sin que nadie inicie sesión.
- **Sitios ligeros**: páginas y archivos servidos junto a una API, sin un
  sistema de gestión de contenidos.

### 4.3 Para qué no está hecho

- **Sitios centrados en las páginas**, con plantillas, formularios y un
  tema: nyuRuns sirve páginas, pero está construido para los datos y las
  API.
- **Intercambios en tiempo real** (chats, flujos en directo): cada
  intercambio es una petición y su respuesta, y los sockets se dejan para la
  versión 1.1.
- **Un servicio que deba aguantar una carga muy fuerte en un solo servidor,
  sin mediciones propias**: nyuRuns solo se ha medido bajo carga en una
  máquina pequeña, muy por debajo del servidor de referencia (capítulo 7).

---

## 5. Funcionalidades básicas

Las funcionalidades que nyuRuns debe tener, por área. **1.0**: en la
versión 1.0; **1.1**: dejada para la versión 1.1; **nyuTron**: en el
proyecto aparte de herramientas alrededor de nyuRuns; **abandonada**:
descartada del todo.

### 5.1 Peticiones y respuestas

| Funcionalidad | Estado |
|---|---|
| Toda la petición gestionada: ruta, acceso, entradas, acción, respuesta | 1.0 |
| Entradas conservadas según la definición de cada acción, cada una en su formato; campos obligatorios | 1.0 |
| Respuestas en JSON, YAML, XML, CSV, HTML, o en bruto | 1.0 |
| Errores en el formato de la respuesta, registrados, nunca detallados en producción | 1.0 |
| Límites al tamaño de las peticiones | 1.0 |
| Llamadas desde otros sitios (CORS), para los orígenes listados | 1.0 |
| La codificación de las entradas | 1.1 |
| Entradas que vienen de otras fuentes que la petición: archivos, registros, otras API, la sesión, un flujo | 1.1 |
| Los sockets | 1.1 |

### 5.2 Enrutamiento

| Funcionalidad | Estado |
|---|---|
| Rutas encontradas a partir de la ruta de la URL, o declaradas en archivos de rutas, por el sitio o por cada run | 1.0 |
| Runs montados donde dice el perfil, o no servidos | 1.0 |
| Una acción por método de una URL | 1.0 |
| Redirecciones, archivos y carpetas estáticos, páginas HTML | 1.0 |
| Las páginas de error propias de un sitio | 1.0 |
| Archivos estáticos guardados en caché por los clientes (versión y fecha, 304) | 1.0 |
| Respuestas de datos guardadas en caché | aún no prevista |

### 5.3 Acceso

| Funcionalidad | Estado |
|---|---|
| Portadores: la autenticación Basic, una clave de API en una cabecera, un token firmado, una sesión PHP, un certificado de cliente | 1.0 |
| Verificaciones: un archivo de contraseñas, claves de API, un archivo de usuarios, una tabla de usuarios, tokens de dispositivos, una verificación propia del proyecto | 1.0 |
| Roles ordenados, por ruta y por acción | 1.0 |
| Un límite de intentos fallidos, por dirección y por cuenta | 1.0 |
| Códigos de doble factor, desde una app de autenticación o por correo | 1.0 |
| Tokens firmados con un par de claves (RS256), verificados por otros servicios | 1.0 |
| El emisor y la audiencia de los tokens, y las claves públicas publicadas | 1.0 |
| Aplicaciones que llaman por sus usuarios, con una clave | 1.0 |
| Conectores SSO y LDAP | 1.1 |
| Códigos por SMS como segundo factor; un inicio de sesión aprobado desde el teléfono | 1.1 |
| Certificados de cliente presentados a través de un proxy, y un perfil para ellos; la fijación de certificados | 1.1 |
| Límites por ruta | 1.1 |
| La autenticación Digest y un portador por cookie | abandonada |

### 5.4 Usuarios

| Funcionalidad | Estado |
|---|---|
| Usuarios en un archivo o en una tabla de base de datos, trasladados de uno a otro | 1.0 |
| Cuentas creadas, activadas con un código, bloqueadas, restablecidas, eliminadas; roles | 1.0 |
| Contraseñas cambiadas y restablecidas por los propios usuarios | 1.0 |
| Altas, confirmadas por correo y aprobadas por un gestor | 1.0 |
| Correos de acceso, en el idioma de cada usuario | 1.0 |
| Dispositivos, con sus propios tokens | 1.0 |
| Usuarios gestionados a través de una aplicación y desde la línea de comandos | 1.0 |

### 5.5 Datos

| Funcionalidad | Estado |
|---|---|
| Bases de datos: MySQL, MariaDB, PostgreSQL, SQLite, MongoDB; varias a la vez | 1.0 |
| Registros y listas, con un solo filtro para todos los tipos de base de datos | 1.0 |
| Relaciones: los hijos y el padre de un registro | 1.0 |
| Las transacciones | 1.0 |
| Valores binarios, leídos y enviados trozo a trozo | 1.0 |
| Identificadores que viajan con un registro (UUID versión 7) | 1.0 |
| Varias uniones en una misma lista | 1.1 |
| Una tabla servida sin código escrito a mano (`makerun`) | 1.0 |

### 5.6 Documentos y medios

| Funcionalidad | Estado |
|---|---|
| Documentos leídos y escritos: texto, Markdown, HTML, XML, JSON, YAML, INI (solo lectura), CSV y TSV, iCalendar, PDF, Word, Excel, OpenDocument, ePub | 1.0 |
| Imágenes: leídas, redimensionadas, recortadas, giradas, limpiadas; dibujos convertidos en imágenes | 1.0 |
| Sonidos: leídos, sin sus etiquetas, cortados; convertidos con ffmpeg | 1.0 |
| Los códigos QR | 1.0 |
| El texto enriquecido y el código fuente leídos como documentos | 1.1 |
| La escritura a mano y el texto escaneado leídos | 1.1 |
| Sonidos mezclados: forma de onda, volumen, fundidos, tempo | 1.1 |

### 5.7 Correo

| Funcionalidad | Estado |
|---|---|
| Cuentas de correo que envían por SMTP, por PHPMailer, o a archivos | 1.0 |
| Correos escritos a partir de plantillas, en varios idiomas | 1.0 |

### 5.8 Línea de comandos y herramientas

| Funcionalidad | Estado |
|---|---|
| La instalación y el diagnóstico de un proyecto | 1.0 |
| Ajustes leídos y cambiados, secretos generados | 1.0 |
| Usuarios, dispositivos y claves de aplicación gestionados | 1.0 |
| Una copia del proyecto construida para cada entorno | 1.0 |
| La batería de pruebas y la auditoría de las convenciones | 1.0 |
| Un run escrito a partir de una tabla: rutas, acciones, entradas (`makerun`) | 1.0 |
| Un asistente visual alrededor de `makerun` | nyuTron |
| Un asistente de instalación | nyuTron |
| Un gestor de los ajustes avanzados | nyuTron |
| Kits de datos listos para usar: miembros, clientes, pedidos, tareas, proyectos | nyuTron |

---

## 6. Requisitos detallados

Cada requisito es una regla que el framework respeta. «Cumplido en» nombra
la sección que explica cómo: **ET** para las
[Especificaciones técnicas](techspecs.md), **EF** para las
[Especificaciones funcionales](funcspecs.md).

### 6.1 Peticiones y entradas

| # | Requisito | Estado | Cumplido en |
|---|---|---|---|
| R-01 | Cada petición pasa por una sola entrada, en una sola secuencia: ruta, acceso, entradas, acción, respuesta | 1.0 | ET 4 |
| R-02 | Un cuerpo más grande que el tamaño fijado, o con más partes de las fijadas, se rechaza (413): un cuerpo anunciado como más grande no se lee, y uno que resulta más grande no se lee más allá del límite | 1.0 | ET 4.2 |
| R-03 | Una acción solo recibe las entradas que lista su definición, cada una en su formato; si falta una obligatoria, la petición se rechaza (400) antes de que la acción se ejecute | 1.0 | ET 6.9, EF 5.8 |
| R-04 | Los valores del servidor y del cliente nunca son entradas; lo que un cliente dice de sí mismo nunca da acceso | 1.0 | ET 9.2 |
| R-05 | Un valor de una petición usado en un filtro se compara tal cual, nunca se lee como un patrón | 1.0 | ET 10.5 |
| R-06 | Las entradas vienen de otras fuentes que la petición: archivos, registros, otras API, la sesión, un flujo | 1.1 | |
| R-07 | La codificación de las entradas se fija, y se convierte | 1.1 | |

### 6.2 Enrutamiento y respuestas

| # | Requisito | Estado | Cumplido en |
|---|---|---|---|
| R-08 | Una ruta se encuentra a partir de la ruta de la URL, o de las rutas que declaran el sitio o cada run (un run se monta donde dice el perfil); una ruta declarada nombra una acción para cada método | 1.0 | ET 4.3 |
| R-09 | Una ruta declarada redirige, o sirve un archivo, una carpeta o una página; un archivo nunca sale de su carpeta | 1.0 | ET 6.8, ET 9.3 |
| R-10 | Todo lo que un cliente no puede alcanzar recibe la misma respuesta, 404, tanto si no existe como si no se puede alcanzar; el único 403 que queda es el de un token de dispositivo en una acción que modifica la cuenta de su usuario | 1.0 | ET 4.5 |
| R-11 | Una respuesta se escribe en el formato del perfil, o de la acción: JSON, YAML, XML, CSV, HTML, en bruto | 1.0 | ET 4.6 |
| R-12 | Un error se escribe en el formato de la respuesta, se registra, y nunca se detalla en producción | 1.0 | ET 4.7 |
| R-13 | Cada respuesta prohíbe la detección del tipo de contenido; otros sitios solo llaman desde un navegador desde los orígenes listados | 1.0 | ET 9.3 |
| R-14 | Un archivo estático se envía con su versión y su fecha, y un cliente que ya lo tiene recibe un 304 | 1.0 | ET 1.6 |
| R-15 | Un cliente y el servicio se comunican por sockets | 1.1 | |

### 6.3 Acceso

| # | Requisito | Estado | Cumplido en |
|---|---|---|---|
| R-16 | En un perfil que pide credenciales, cada ruta exige una salvo que el perfil la abra a propósito; un ajuste de acceso que no se puede aplicar rechaza todas las peticiones | 1.0 | ET 4.4 |
| R-17 | Una credencial viaja por la autenticación Basic, una clave en una cabecera, un token firmado, una sesión PHP o un certificado de cliente, y se verifica con un archivo de contraseñas, una lista de claves, un archivo de usuarios, una tabla de usuarios, tokens de dispositivos, o una verificación propia del proyecto | 1.0 | ET 5.3 |
| R-18 | Los roles están ordenados; una ruta, o una acción, pide el rol mínimo que necesita | 1.0 | ET 2.8, EF 5.2 |
| R-19 | Las credenciales erróneas se cuentan por dirección, y la dirección queda en espera pasado un límite (429); una cuenta queda en espera tras demasiadas contraseñas erróneas | 1.0 | ET 9.5 |
| R-20 | Un nombre erróneo y una contraseña errónea reciben el mismo rechazo y, donde las contraseñas se cifran con bcrypt, en el mismo tiempo | 1.0 | ET 9.4 |
| R-21 | Un usuario con códigos de doble factor inicia sesión con un código de un solo uso, desde una app de autenticación o enviado por correo, cada uno aceptado una sola vez | 1.0 | EF 5.2 |
| R-22 | Los tokens están firmados y caducan; los tokens firmados con un par de claves los verifican otros servicios solo con la clave pública | 1.0 | ET 2.5, EF 4.4 |
| R-23 | Una aplicación llama por sus propios usuarios con una clave, limitada a las rutas que se le dan y a un rol máximo | 1.0 | EF 5.7 |
| R-24 | Los usuarios inician sesión por SSO o LDAP | 1.1 | |
| R-25 | Un segundo factor se envía por SMS, o un inicio de sesión se aprueba desde el teléfono | 1.1 | |
| R-26 | Los tokens nombran a su emisor y su audiencia, y las claves que los verifican se publican | 1.0 | EF 5.7, EF A.1 |
| R-27 | Los certificados de cliente se leen a través de un proxy, con un perfil propio; los certificados se fijan | 1.1 | |
| R-28 | Los límites se fijan ruta por ruta | 1.1 | |

### 6.4 Usuarios

| # | Requisito | Estado | Cumplido en |
|---|---|---|---|
| R-29 | Los usuarios se guardan en un archivo o en una tabla, y se trasladan de uno a otro con sus contraseñas, sus roles y sus secretos | 1.0 | EF 6.2 |
| R-30 | Una cuenta pasa por los estados pendiente de activación, pendiente de aprobación, activa y bloqueada; un código la activa | 1.0 | EF 5.3 |
| R-31 | Los usuarios cambian su propia contraseña, y restablecen una olvidada por correo, conservando su contraseña hasta que se usa el código | 1.0 | EF 5.5 |
| R-32 | Los visitantes se dan de alta, confirman su dirección, y esperan la aprobación de un gestor; las altas están limitadas por cliente y en total | 1.0 | EF 5.4 |
| R-33 | Los correos de acceso salen en el idioma de cada usuario | 1.0 | EF 5.10 |
| R-34 | Un servicio nunca se queda sin un gestor activo | 1.0 | EF 5.3 |
| R-35 | Un dispositivo actúa por un usuario con un token propio, revocado por separado | 1.0 | EF 5.6 |
| R-36 | Las claves, los tokens y los códigos se muestran una vez y se guardan como hashes; los secretos de doble factor se guardan cifrados | 1.0 | ET 9.4 |

### 6.5 Datos

| # | Requisito | Estado | Cumplido en |
|---|---|---|---|
| R-37 | Una aplicación llega a sus datos a través de modelos, registros y listas, nunca a través de consultas que escribe ella | 1.0 | ET 10 |
| R-38 | Las bases de datos son MySQL, MariaDB, PostgreSQL, SQLite y MongoDB, varias a la vez | 1.0 | ET 10.1 |
| R-39 | Un filtro se escribe de la misma forma para todos los tipos de base de datos | 1.0 | ET 10.5 |
| R-40 | Un registro se elimina con los hijos nombrados, en una sola transacción, o no se elimina | 1.0 | ET 10.3 |
| R-41 | Un valor binario se envía al cliente, y se escribe en un archivo, trozo a trozo; entre el flujo y la base de datos, se guarda entero, hasta su límite | 1.0 | ET 10.7 |
| R-42 | La clave de un registro guardado tiene el mismo tipo que cuando se vuelve a leer: una clave que numeró la base de datos es un número | 1.0 | ET 10.3 |
| R-43 | Una lista une varias tablas | 1.1 | |
| R-44 | Una tabla se sirve sin código escrito a mano: `makerun` escribe su run a partir de la tabla | 1.0 | ET 7.10 |

### 6.6 Documentos, medios y correo

| # | Requisito | Estado | Cumplido en |
|---|---|---|---|
| R-45 | Un documento se lee en una de tres formas (un árbol, hojas, bloques de texto), y se escribe a partir de ella, en los formatos de 5.6 | 1.0 | ET 5.6, EF 5.9 |
| R-46 | Un documento cuyo contenido no corresponde a su nombre se rechaza | 1.0 | EF 5.9 |
| R-47 | Las imágenes se leen y se editan; los sonidos se leen, se cortan, y se convierten con ffmpeg | 1.0 | EF 5.9 |
| R-48 | Un correo se envía por SMTP, cifrado y con el certificado de su servidor verificado, por PHPMailer, o a archivos, escrito a partir de sus plantillas | 1.0 | EF 5.10 |
| R-49 | Se leen el texto enriquecido, el código fuente, la escritura a mano y el texto escaneado; se mezclan los sonidos | 1.1 | |

### 6.7 Línea de comandos y explotación

| # | Requisito | Estado | Cumplido en |
|---|---|---|---|
| R-50 | Un proyecto se instala con un solo comando, que no sustituye nada de lo que ya existe; su diagnóstico detiene un script de despliegue si algo falla | 1.0 | ET 7.2 |
| R-51 | Un ajuste se cambia de valor en valor, se comprueba, y el archivo anterior se conserva | 1.0 | ET 7.3 |
| R-52 | Una copia del proyecto se construye para cada entorno, sin secretos, sin ajustes locales y sin claves | 1.0 | ET 7.6 |
| R-53 | Los usuarios, los dispositivos y las claves de aplicación se gestionan desde la línea de comandos, y cada cambio queda trazado | 1.0 | ET 7.4, ET 7.5 |
| R-54 | Cada tema tiene su registro; los accesos privilegiados y los rechazos quedan trazados; una traza no contiene ningún argumento de sus llamadas | 1.0 | ET 9.6 |

### 6.8 Calidad

| # | Requisito | Estado | Cumplido en |
|---|---|---|---|
| R-55 | El framework funciona en PHP 8.2 y posteriores; sus pruebas pasan en la versión más antigua admitida y en la más reciente | 1.0 | ET 3.1, ET 13.5 |
| R-56 | Una batería de pruebas comprueba cada parte, en SQLite y en servidores de bases de datos reales | 1.0 | ET 13 |
| R-57 | Cada nombre tiene la longitud de su tipo, cada método público su docblock, cada parámetro y cada retorno su tipo; una auditoría los comprueba | 1.0 | ET 12 |
| R-58 | Una referencia del código se genera a partir de sus docblocks | 1.0 | ET 12.3 |

---

## 7. Objetivos de rendimiento

### 7.1 El objetivo

nyuRuns aspira a ser tan eficiente como los microframeworks, a la vez que
ofrece las funcionalidades clave, y parte de las capacidades, de los más
pesados. No debe pagar esa completitud con el coste de cada petición.

### 7.2 Los objetivos, y en qué punto está la 1.0

| # | Objetivo | 1.0 |
|---|---|---|
| P-01 | Una petición cuesta al servidor como mucho 1,2 veces la CPU que cuesta con Slim, en la misma máquina, para el mismo trabajo | Cumplido: 1,02 veces (4,39 ms frente a 4,28 ms de Slim; Flight 3,70 ms) |
| P-02 | Una petición cuesta como mucho la mitad de la CPU que cuesta con Symfony, en la misma máquina, para el mismo trabajo | Cumplido, por poco: 0,45 veces, para un objetivo de 0,5 (9,70 ms para Symfony; Laravel 13,13 ms) |
| P-03 | No envía a la base de datos más sentencias que ellos para el mismo trabajo | Cumplido: una para leer un registro, cinco para un registro con sus relaciones en el caso complejo del banco de pruebas |
| P-04 | Una petición solo carga lo que usa: ninguna conexión si no se leen datos, ningún modelo si una acción no lo pide | Cumplido |
| P-05 | Lo que el framework lee en cada petición (los ajustes, las rutas, las tablas) se lee una vez en producción, y luego desde una caché | Cumplido: `CNF_CACH`, `TBL_CACH`, activados por la copia de producción |
| P-06 | Los datos grandes se leen y se envían trozo a trozo, nunca se guardan enteros en memoria | En parte: los archivos y los valores binarios se envían trozo a trozo; un cuerpo de petición se guarda entero, hasta `REQ_SIZE`, y un valor binario hasta su límite, entre el flujo y la base de datos |
| P-07 | En el servidor de referencia (7.3), con 50 clientes a la vez, las lecturas ligeras del banco de pruebas (una lista, un registro, los proyectos de un usuario) atienden 800 peticiones por segundo o más, con el 1 % más lento respondido en menos de 150 ms y ninguna fallida | No medido en el servidor de referencia. En una máquina más pequeña (7.3), una cota inferior: con 32 clientes, las lecturas ligeras superan las 1 040 peticiones por segundo, con el 1 % más lento respondido en 36,4 ms como mucho y ninguna fallida |
| P-08 | En el servidor de referencia, con 50 clientes a la vez, las escrituras del banco de pruebas (un registro creado, una tarea, un comentario) atienden 500 peticiones por segundo o más, en las mismas condiciones | No medido en el servidor de referencia. En la misma máquina más pequeña, una cota inferior: con 32 clientes, las escrituras superan las 780 peticiones por segundo, con el 1 % más lento respondido en 52 ms como mucho y ninguna fallida detrás de nginx |
| P-09 | Una petición de API usa como mucho 4 MB de memoria en su pico | Aún no medido |

Los objetivos relativos (P-01, P-02) valen en cualquier máquina. Las cifras
de la 1.0 vienen de la serie del 24 y 25 de noviembre de 2026: un Intel NUC
con Linux, detrás de nginx y detrás de Apache, con 1, 8 y 32 clientes a la
vez, y cada cifra es la mediana de tres repeticiones. Lo que no cubre:
PHP 8.2 (la serie usó la 8.5), el servidor de referencia, 50 clientes y la
memoria por petición. El método, los resultados escenario por escenario y
sus límites están en las
[Especificaciones funcionales, capítulo 7](funcspecs.md#7-resultados-de-rendimiento).

### 7.3 El servidor de referencia

Una cifra en peticiones por segundo no significa nada sin su máquina. P-07
a P-09 se fijan para esta:

| | Servidor de referencia | La máquina medida |
|---|---|---|
| Procesador | 6 núcleos, de la clase de un AMD Ryzen 5 5500 | Intel Core i3-7100U, 2 núcleos / 4 hilos, 2,4 GHz: por debajo |
| Memoria | 16 GB | 4 GB, 3,2 utilizables: por debajo |
| Sistema | Linux | Ubuntu 26.04 |
| PHP | 8.2 o posterior, con PHP-FPM, OPcache activado sin comprobar los archivos, JIT desactivado | 8.5.4, con PHP-FPM, OPcache sin comprobar los archivos |
| Base de datos | MySQL, en la misma máquina | MySQL 8.4, en la misma máquina |
| nyuRuns | su copia `final`: ajustes de producción, cachés de configuración y de tablas activadas | `bundles final`, `CNF_CACH` y `TBL_CACH` activadas |

El software coincide; el hardware se queda muy corto, con alrededor de un
tercio del procesador. Así que las cifras de esa máquina son una cota
inferior para P-07 y P-08, nunca una medición de ellos.

**De dónde salen las cifras.** Los objetivos se fijaron a partir de la
serie del 7 de noviembre, en Windows: una petición costaba 2,67 ms de CPU en el
servidor web y 1,47 ms en MySQL, unos 4,1 ms en total. Seis núcleos
totalmente ocupados responderían a unas 1 450 peticiones por segundo. Los
objetivos de carga toman más o menos la mitad, para dejar sitio a lo que
añade la carga: la espera en los bloqueos, el paso de un proceso a otro, la
red. Las escrituras cuestan alrededor de una décima más de CPU, pero
también esperan a la base de datos, sus bloqueos y su disco, algo que una
medición de una petición cada vez no muestra, así que su objetivo se fija
más bajo.

### 7.4 Lo que aún no está fijado

- **Documentos y medios**: la lectura, la escritura y la conversión de
  archivos grandes, imágenes y sonidos no se han medido. Sus objetivos se
  fijarán cuando una primera medición dé una base.

Un objetivo se fija antes de medirse, para que una medición pueda cumplirlo,
o no. Los objetivos de carga tienen ya una primera cota inferior, en una
máquina por debajo del servidor de referencia; la medición en sí, con 50
clientes en esa clase de máquina, está por hacer, igual que la memoria por
petición (P-09).

---

## 8. Notas adicionales

### 8.1 Plataforma

- **PHP 8.2 o posterior.** 8.0 y 8.1 ya no reciben correcciones de
  seguridad. Se recomienda una versión que corrija php2shell
  (CVE-2026-109618, CVE-2026-109619), y el diagnóstico te avisa cuando
  falta.
- **Las extensiones** que necesitan las bibliotecas (`json`, `dom`,
  `iconv`, `zlib`, `mbstring`) son obligatorias. Las demás solo las
  necesitan las partes que las usan
  ([Especificaciones técnicas, capítulo 3](techspecs.md#3-requisitos-del-sistema)).
- **Cualquier servidor web** que pase la petición a PHP y envíe cada URL al
  punto de entrada. El servidor en sí corresponde a quien lo aloja
  ([Alojamiento](hosting.md)).

### 8.2 Licencia y contribuciones

- nyuRuns se publica bajo la **licencia MIT**, copyright 2021-2026 Jason
  JOHNSTON (`LICENSE`).
- Las **contribuciones** se firman según el Developer Certificate of Origin
  (`git commit -s`), como explica `CONTRIBUTING.md`.
- Las **bibliotecas** que usa conservan sus propias licencias, listadas en
  `NOTICE`. PHPMailer, sugerido y no obligatorio, está bajo LGPL 2.1.

### 8.3 Versiones

| Versión | Incluye |
|---|---|
| 0.88 | la última versión antes de que el código estuviera completo, etiquetada el 21 de octubre de 2026 |
| 0.9 | el código completo: la tabla de dispositivos renombrada, los tokens RS256, el formato de los perfiles, la copia construida para cada entorno, los ajustes de entrega |
| **1.0** | la primera versión pública: la documentación, la licencia, la optimización del rendimiento, los códigos por correo, los tokens ligados a un emisor y a una audiencia, las rutas propias de cada run, la revisión de seguridad, y la última pasada de pruebas |
| 1.1 | lo que los capítulos 5 y 6 le dejan: SSO y LDAP, un segundo factor por SMS o aprobado desde el teléfono, los certificados a través de un proxy, los límites por ruta, otras fuentes de entradas, los sockets, varias uniones, el texto enriquecido y el código, la escritura a mano, la mezcla de sonidos |
| nyuTron (nombre en clave) | un proyecto aparte de herramientas alrededor de nyuRuns: un asistente visual alrededor de `makerun`, un asistente de instalación, un gestor de los ajustes avanzados, kits de datos listos para usar |

Cada versión se cierra con una pasada completa de la batería de pruebas, en
las versiones de PHP y los servidores de bases de datos admitidos.

### 8.4 Lo que ha cambiado desde la visión original

- **CRUDI** es ahora **CRUDL**: las mismas operaciones, con la lista
  nombrada por lo que es.
- **Una tabla servida solo con los ajustes** se genera en su lugar:
  `makerun` escribe su run a partir de la tabla, en vez de que el framework
  la lea desde los ajustes.
- **Los archivos como fuentes de datos**, **los sockets** y **una caché
  para las respuestas de datos** no están en la 1.0. Los dos primeros
  llegan con la versión 1.1, y el último aún no está previsto.
- **La autenticación Digest y un portador por cookie** se abandonaron.

---
