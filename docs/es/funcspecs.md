# nyuRuns — Especificaciones funcionales

Versión 1.0

## Índice

1. [Introducción](#1-introducción)
2. [Aspectos clave de los requisitos](#2-aspectos-clave-de-los-requisitos)
3. [Visión general del diseño](#3-visión-general-del-diseño)
4. [Arquitectura del sistema](#4-arquitectura-del-sistema)
5. [Implementación de los procesos](#5-implementación-de-los-procesos)
6. [Configuración y escalabilidad](#6-configuración-y-escalabilidad)
7. [Resultados de rendimiento](#7-resultados-de-rendimiento)
8. [Pautas de desarrollo](#8-pautas-de-desarrollo)

A. [Respuestas y estados](#a-respuestas-y-estados)

---

## 1. Introducción

### 1.1 Lo que cubre este documento

Este documento describe lo que hace nyuRuns y cómo se desarrollan sus
procesos: quién usa un servicio construido con él, qué puede hacer cada
uno y los pasos de cada proceso, desde el inicio de sesión hasta la
entrega de un proyecto. Habla de comportamiento, no de código. Los nombres
del código solo aparecen donde hacen falta para encontrar un ajuste o un
comando.

Está escrito para los desarrolladores, y para los analistas y responsables
de producto que deciden lo que debe hacer un servicio construido con
nyuRuns. Cómo está construido el propio framework se describe en las
[Especificaciones técnicas](techspecs.md).

### 1.2 nyuRuns en pocas palabras

nyuRuns es el back-end de una aplicación. Un front-end (una página web, una
app móvil, otro programa) le envía peticiones por HTTP, y nyuRuns responde
con datos, un archivo o una página. Entre medias, se encarga de toda la
petición:

- averigua qué se pide, a partir de la URL de la petición;
- comprueba quién lo pide, y si tiene permiso;
- conserva solo los valores que la petición puede llevar, cada uno en el
  formato correcto;
- ejecuta la aplicación pedida, que lee y escribe sus datos;
- escribe la respuesta en el formato que espera el front-end, errores
  incluidos.

Alrededor de todo eso, aporta las herramientas que necesita un servicio:
los usuarios y sus accesos, las bases de datos, los documentos, las
imágenes, los sonidos, el correo, los registros y una línea de comandos
para instalar, configurar, probar y entregar un proyecto.

### 1.3 Quién usa un servicio construido con nyuRuns

| Actor | Quién | Qué hace |
|---|---|---|
| **Visitante** | cualquiera que no haya iniciado sesión | llega a lo que está abierto a todos: una página pública, el inicio de sesión, el alta, el restablecimiento de una contraseña olvidada |
| **Usuario estándar** | un usuario con sesión iniciada y el rol `standard` | usa el servicio con los derechos del rol más bajo |
| **Usuario avanzado** | el rol `advanced` | obtiene lo que el servicio reserva a su equipo |
| **Delegado** | el rol `delegate` | consulta los usuarios y aprueba las altas (`users/index`, `view`, `approve`); nunca modifica una cuenta |
| **Gestor** | el rol `managers` | gestiona los usuarios: los crea, los bloquea, los restablece y los elimina, cambia su rol, su dirección y su idioma, y también los aprueba; todas las demás acciones de `Users` son solo suyas |
| **Dispositivo** | una máquina con su propio token, que actúa por un usuario | habla con el servicio sin que nadie inicie sesión: un quiosco, un sensor, un servidor doméstico |
| **Aplicación** | otro programa que tiene una clave | llama al servicio en nombre de uno de sus propios usuarios |
| **Administrador** | la persona que gestiona el servidor | instala, configura, comprueba y actualiza el proyecto desde la línea de comandos |

Los roles están ordenados: cada uno tiene todos los derechos de los roles
anteriores, de `standard` a `advanced`, `delegate` y `managers`. Lo que
puede alcanzar cada rol lo decide el proyecto, ruta por ruta.

### 1.4 Lo que cubre la versión 1.0

La versión 1.0 se encarga de toda la petición y ofrece varias formas de
iniciar sesión y de llevar una identidad. Gestiona los usuarios y sus
correos de acceso, y admite cinco tipos de base de datos, documentos en los
formatos habituales de texto, datos y ofimática, imágenes, sonidos, correo
y la línea de comandos. Algunas partes de la visión original se dejan para
la versión 1.1, y unas pocas se descartaron. La lista completa está en un
solo sitio, las
[Especificaciones técnicas, 1.4 a 1.6](techspecs.md#14-lo-que-incluye-la-versión-10).
El capítulo 2 la recorre desde el punto de vista de las necesidades.

### 1.5 Los términos de este documento

| Término | Significa |
|---|---|
| **servicio** | un proyecto construido con nyuRuns, tal como lo ven sus usuarios |
| **front-end** | lo que envía las peticiones: una página web, una app móvil, un programa |
| **run** | una aplicación del proyecto, como `Users` o `Shows` |
| **acción** | lo que hace un run para una petición: listar, ver, crear, modificar, eliminar |
| **ruta** | la URL que lleva a una acción, una página o un archivo |
| **perfil** («preset» en el código) | el perfil del servicio: cómo se llega a él, cómo se comprueba el acceso y cómo responde |
| **base de usuarios** | donde se guardan los usuarios: un archivo de usuarios, o una tabla de base de datos |
| **rol** | lo que un usuario puede alcanzar: `standard`, `advanced`, `delegate`, `managers` |
| **estado** | en qué punto está una cuenta: `awaiting`, `inReview`, `approved`, `disabled` |

---

## 2. Aspectos clave de los requisitos

### 2.1 La visión original

Los Requisitos de nyuRuns lo describen como un micro-framework para
construir y desplegar el back-end de aplicaciones enteras como servicios
RESTful. El objetivo es simplificar la construcción de API repartiendo el
trabajo: los desarrolladores escriben lo que necesita su negocio, y el
framework se ocupa del resto. Se apoya en cuatro principios: el desarrollo
dirigido por modelos (MDD), la sencillez (KISS), REST y las operaciones
que recibe cada fuente de datos (crear, leer, modificar, eliminar y
listar, o CRUDL).

Este capítulo repasa las necesidades que nombra esa visión, y dice en qué
punto está la versión 1.0 en cada una. Los Requisitos tienen su propio
documento.

### 2.2 Las necesidades, y en qué punto está la 1.0

| Estado | Significa |
|---|---|
| **Cumplido** | la 1.0 hace lo que pide la necesidad |
| **En parte** | la 1.0 hace una parte, y la tabla dice cuál |
| **Versión 1.1** | se deja para la versión siguiente |
| **nyuTron** | trasladado a nyuTron, el proyecto aparte de herramientas alrededor de nyuRuns |

| Necesidad | Estado | En la 1.0 |
|---|---|---|
| Construir y desplegar el back-end de una aplicación entera, como servicios RESTful | Cumplido | recursos a los que se llega por su ruta, métodos que dicen qué se hace, estados que dicen cómo ha ido; una línea de comandos instala, comprueba y entrega el proyecto |
| Encargarse de toda la petición HTTP, para que los desarrolladores escriban menos | Cumplido | el enrutamiento, el acceso, las entradas, la acción, la respuesta y sus errores, en una sola secuencia (5.1) |
| Componentes dirigidos por los modelos de datos (MDD) | Cumplido | una aplicación trabaja a través de modelos de sus datos, registros y listas, nunca a través de consultas |
| Las operaciones CRUD listas para usar | En parte | cada fuente de datos recibe sus operaciones CRUDL a través de los modelos, y `makerun` escribe las acciones del run de una tabla SQL, así que ninguna se escribe a mano. Servir una tabla solo con configuración no está en la 1.0 |
| Otras fuentes de datos además de las bases: archivos XML, JSON, INI y de texto | En parte | los archivos en esos formatos, y en muchos más, los lee y escribe una acción como documentos. Usarlos como fuente de entradas configurada se deja para la 1.1 |
| El enrutamiento de cada petición, con alternativas | Cumplido | las rutas se encuentran a partir de la ruta de la URL, o las declara el sitio o cada run; una ruta declarada también puede redirigir, o servir un archivo, una carpeta o una página; un sitio puede tener sus propias páginas de error |
| Control de acceso (ACL) y tokens | Cumplido | roles ordenados, pedidos por ruta o por acción; tokens firmados, sesiones, claves de API, certificados de cliente; varias bases de usuarios |
| Gestores de datos: bases de datos, archivos, imágenes, sockets | En parte | cinco tipos de base de datos, documentos, imágenes, y además sonidos, que la visión no mencionaba. Los sockets se dejan para la 1.1 |
| Salida con funciones de caché | En parte | los archivos estáticos se envían con su versión y su fecha, y un cliente que ya los tiene recibe un 304. Las respuestas de datos no se guardan en caché |
| Gestión de errores | Cumplido | los errores se escriben en el formato de la respuesta, se registran y nunca se detallan en producción |
| Una arquitectura por capas (multinivel) | Cumplido | la petición, el acceso, la aplicación, sus modelos y sus almacenamientos son capas separadas (capítulo 4) |
| Mantenerlo sencillo | Cumplido | una primera instalación no necesita ninguna base de datos, y lo que un proyecto no usa no se carga |

### 2.3 Lo que la 1.0 añade a la visión original

Construir el framework sacó a la luz necesidades que la visión original no
mencionaba. La versión 1.0 las cubre:

- **Los usuarios y su ciclo de vida**: la activación con un código, los
  estados, el bloqueo, los roles, las contraseñas elegidas y
  restablecidas, las altas revisadas por un gestor, todo a través de una
  aplicación o de la línea de comandos (5.3 a 5.5).
- **Los correos de acceso**, en el idioma de cada usuario.
- **Los códigos de doble factor**, desde una app de autenticación, o
  enviados por correo (solo con la base de usuarios nativa).
- **Los dispositivos**, y **las aplicaciones que llaman por sus
  usuarios**, cada uno con su propia credencial (5.6 y 5.7).
- **Un límite de intentos fallidos**, por dirección y por cuenta.
- **Los documentos** en los formatos habituales de texto, datos y
  ofimática, leídos en una misma forma y vueltos a escribir a partir de
  ella.
- **Las imágenes y los sonidos**, leídos, editados y convertidos.
- **El correo**, a través de un servidor SMTP, de PHPMailer, o en
  archivos.
- **La línea de comandos**, para instalar, configurar, probar, gestionar y
  entregar un proyecto, con una copia construida para cada entorno.

### 2.4 Qué ha cambiado, y por qué

Las partes de la visión que la 1.0 no cumple del todo se apartaron para
terminar antes la versión, no se abandonaron. Los archivos como fuentes
configuradas y los sockets llegan con la versión 1.1, y la generación de
acciones CRUDL ya está en la 1.0 (`makerun`), a la espera del asistente
visual de nyuTron a su alrededor. La caché de las respuestas de datos es la
única necesidad sin versión prevista por ahora. La lista completa de lo
que queda para más adelante está en las
[Especificaciones técnicas, 1.5 y 1.6](techspecs.md#15-lo-que-queda-para-más-adelante).

---

## 3. Visión general del diseño

### 3.1 Los principios, tal como los muestra un servicio

**El desarrollo dirigido por modelos (MDD).** Un servicio se describe por
sus datos. Cada tipo de cosa que guarda (un usuario, un espectáculo, una
factura) es un modelo: un registro de una tabla, o una lista de registros.
Las aplicaciones leen y escriben a través de los modelos y nunca escriben
una consulta. Pasar a otra base de datos, o a otro tipo de base de datos,
no toca las aplicaciones, dentro de lo que ese tipo sabe hacer (MongoDB,
por ejemplo, no tiene joins).

**La sencillez (KISS).** Un servicio arranca con lo que necesita y nada
más. Una primera instalación guarda sus usuarios en un archivo y no
necesita ninguna base de datos, y lo que un proyecto no usa no se carga.
Cada parte hace una sola cosa, y los ajustes son simples archivos.

**REST.** Cada recurso tiene su propia URL, y el método de la petición dice
qué se hace con él. El estado de la respuesta dice cómo ha ido, sea cual
sea el formato de su cuerpo:

| Método | Qué se hace | Estados habituales |
|---|---|---|
| `GET` | leer un recurso, o listarlos | 200 con los datos, 204 cuando la lista está vacía, 404 |
| `POST` | crear un recurso, o lanzar una operación | 201 creado, 202 aceptado, 400 |
| `PUT`, `PATCH` | modificar un recurso | 200, 404, 409 cuando la modificación entra en conflicto |
| `DELETE` | eliminar un recurso | 200 o 204, 404 |

Una ruta puede dar a cada método su propia acción, así que una sola URL
cubre toda la vida de un recurso.

**CRUDL.** Cada fuente de datos recibe las mismas cinco operaciones:
crear, leer, modificar, eliminar y listar. Una lista se puede filtrar,
ordenar, limitar y unir a otra tabla, y el filtro se escribe igual para
todos los tipos de base de datos.

### 3.2 Las decisiones detrás del diseño

- **Una entrada, un perfil.** Cada petición llega por una sola puerta, y la
  entrada dice qué perfil («preset») se aplica: cómo se llega al servicio,
  quién entra y cómo responde. El perfil se elige una vez para el
  servicio, y se cambia con un comando.
- **Declarado, no programado.** Lo que acepta un servicio se declara en
  archivos: sus rutas, las entradas de cada acción, los roles de cada
  ruta, sus ajustes. Puedes saber lo que hace un servicio sin leer su
  código.
- **Cerrado por defecto.** En un perfil que pide credenciales, cada ruta
  exige una, salvo que el perfil la abra. Un ajuste que no se puede aplicar
  rechaza todas las peticiones en lugar de dejar pasar alguna. Incluso el
  perfil de una primera instalación pide un token: solo el de un sitio de
  páginas (`statics`) está abierto a todos.
- **Discreto con los clientes.** Un cliente se entera de lo que necesita, y
  de nada más. Lo que no puede alcanzar es «no encontrado», un nombre
  erróneo y una contraseña errónea reciben el mismo rechazo, y un error en
  producción no da ningún detalle. El registro tiene el resto.
- **Una sola forma de respuesta.** Los datos llegan en el formato del
  perfil (JSON, salvo que se indique otra cosa), y un error llega como
  `{"error": ..., "code": ...}` en ese mismo formato.
- **Empezar ligero, crecer después.** Un servicio pasa de un archivo de
  usuarios a una base de datos, de una base de datos a varias, y de una
  aplicación a muchas, sin reescribirse.
- **El alojamiento gestiona el servidor.** El framework se hace cargo de
  la petición en cuanto PHP la recibe. El servidor web, TLS y el
  cortafuegos son cosa de quien gestiona el servidor.

### 3.3 Los conceptos

```
  front-end ──petición──▶ entrada ──▶ perfil ──▶ ruta ──▶ run / acción ──▶ modelos ──▶ almacenamiento
                                        │         │            │
                                     acceso   entradas     respuesta ──▶ front-end
                                (roles, usuarios)
```

| Concepto | Qué es | Ejemplo |
|---|---|---|
| **Proyecto** | un servicio: sus aplicaciones, sus ajustes, sus datos | un servicio de reservas |
| **Run** | una aplicación del proyecto, que agrupa las acciones sobre un mismo tema | `Shows`, `Users` |
| **Acción** | una cosa que hace un run para una petición | listar los espectáculos, ver uno, crear un usuario |
| **Ruta** | la URL que lleva a una acción, una página, un archivo o una redirección | `shows/view/12`, `/notes/12` |
| **Perfil** | el perfil de una entrada: el acceso, las entradas, el enrutamiento, las respuestas | `restapi` para programas, `website` para un navegador |
| **Entradas** | los valores que lleva una petición, conservados solo cuando la acción los declara, cada uno en el formato correcto | un nombre, una fecha, una dirección |
| **Modelo** | un registro o una lista de una tabla | un espectáculo, la lista de espectáculos |
| **Base de usuarios** | donde se guardan los usuarios | el archivo de usuarios, la tabla de usuarios |
| **Rol** | lo que un usuario puede alcanzar, por orden: `standard`, `advanced`, `delegate`, `managers` | un gestor tiene todos los derechos de los roles anteriores |
| **Estado** | en qué punto está una cuenta | ver más abajo |
| **Respuesta** | el estado, más datos, un archivo, una página o un error | `200` con la lista de espectáculos |

Los estados de una cuenta:

| Estado | Significado | Puede iniciar sesión |
|---|---|---|
| `awaiting` | creada, pendiente de activación: su código aún no se ha usado | no |
| `inReview` | dada de alta por su cuenta y confirmada, pendiente de aprobación por un gestor | no |
| `approved` | en uso | sí |
| `disabled` | detenida por un gestor | no |

El capítulo 5.3 muestra cómo pasa una cuenta de un estado a otro.

---

## 4. Arquitectura del sistema

### 4.1 El servicio y lo que lo rodea

```
                    ┌───────────────────────────────────────────────────────────┐
  front-ends        │                     servicio nyuRuns                      │
  (web, móvil,      │                                                           │
   programas)       │  entrada ─▶ rutas ─▶ acceso ─▶ aplicaciones ─▶ respuesta  │
      │             │               │        │            │                     │
      ▼             │               │ usuarios, roles,    modelos, documentos,  │
  servidor web ────▶│               │ límites, 2FA        multimedia, correo    │
  (TLS, proxy)      │               ▼        │            │                     │
                    │      páginas, archivos ▼            ▼                     │
                    │                   registros ◀─────────                    │
                    │                                                           │
                    │  línea de comandos: instalar, configurar, gestionar,      │
                    │  probar, entregar                                         │
                    └──────┬──────────────┬──────────────┬──────────────┬───────┘
                           ▼              ▼              ▼              ▼
                    bases de datos    servidor        ffmpeg   otras aplicaciones
                  (MySQL, MariaDB,    de correo      (sonidos)  (claves, tokens)
                   PostgreSQL,         (SMTP)
                   SQLite, MongoDB)
```

### 4.2 Los bloques funcionales

| Bloque | Qué hace | Capítulo |
|---|---|---|
| **Entrada** | recibe cada petición del servidor web y aplica el perfil del servicio | 5.1 |
| **Enrutamiento** | averigua qué pide la URL: una acción, una página, un archivo, una redirección | 5.1 |
| **Acceso** | decide quién pide y si puede entrar: credenciales, roles, límites de intentos, códigos de doble factor | 5.2 |
| **Usuarios** | guarda las cuentas y su ciclo de vida: creación, activación, estados, roles, contraseñas, altas, correos de acceso | 5.3 a 5.5 |
| **Aplicaciones** | los runs del proyecto: sus acciones, las entradas que aceptan, los modelos de sus datos | 5.8 |
| **Datos** | las bases de datos a través de los modelos; los documentos, imágenes y sonidos leídos, editados y escritos | 5.8, 5.9 |
| **Correo** | envía los correos a través de las cuentas del proyecto | 5.10 |
| **Respuesta** | escribe la respuesta en su formato: datos, un archivo, una página o un error | 5.1, anexo A |
| **Registros** | deja constancia de lo que pasa, un archivo por tema: los errores, los accesos, el framework, la línea de comandos, cada aplicación | 6 |
| **Línea de comandos** | instala, configura, gestiona los usuarios y los dispositivos, prueba y entrega el proyecto | 5.11 |

Solo se carga lo que necesita una petición: una petición que no toca
ninguna base de datos no abre ninguna, y los bloques de datos y de correo
solo se cargan para las aplicaciones que los usan.

### 4.3 Los sistemas externos

| Sistema | Necesario | Para |
|---|---|---|
| **Servidor web** | siempre | recibe las peticiones y se las pasa a PHP; se encarga de TLS, o lo deja a un proxy situado delante |
| **Proxy inverso, CDN** | opcional | se sitúa delante del servidor web; el servicio solo se fía de la dirección que transmite cuando se le indica |
| **Servidores de bases de datos** | opcional | los datos de las aplicaciones, y los usuarios una vez que salen del archivo de usuarios |
| **Servidor de correo** | opcional | los correos de acceso, los códigos de doble factor enviados por correo y los correos propios de las aplicaciones |
| **ffmpeg** | opcional | convertir y cortar sonidos |
| **App de autenticación** | opcional, en el móvil del usuario | los códigos de doble factor, salvo que se envíen por correo |
| **Otras aplicaciones** | opcional | llamar al servicio por sus usuarios, o verificar los tokens que emite |

### 4.4 Varios servicios juntos

Un proyecto puede ser un servicio por sí solo, o uno de varios servicios
que trabajan juntos:

- **Un inicio de sesión para varios servicios.** Un servicio emite los
  tokens, firmados con su clave privada. Los demás solo tienen su clave
  pública, y la usan para verificarlos. Ellos no emiten ningún token: su
  inicio de sesión responde que los tokens vienen de otro servidor.
- **Una aplicación que llama por sus usuarios.** Un servicio da a otra
  aplicación una clave y las rutas que puede llamar. Esa aplicación llama
  entonces con su clave y un token que firma para uno de sus propios
  usuarios, que nunca inicia sesión directamente en el servicio. El rol con
  el que puede actuar tiene un tope.
- **Un solo propietario por dato**, la regla recomendada cuando varios
  servicios comparten datos. Cada tipo de dato pertenece a un servicio, y
  los demás llegan a él por las rutas de ese servicio, nunca por su base
  de datos. El framework no lo impone, pero las claves de aplicación lo
  ponen fácil.

### 4.5 Los entornos

Un proyecto vive en varios sitios: el ordenador del desarrollador, un
servidor de preproducción, la producción y cualquier servidor propio entre
medias (integración, una vista previa para un cliente). Se entrega a cada
uno como una copia construida para ese sitio. La copia contiene el código
y los ajustes de ejemplo, nunca los secretos ni los ajustes de otro sitio.
En cada servidor, el administrador instala la copia, crea sus secretos y
sus claves, y la comprueba antes de ponerla en marcha (5.11).

---

## 5. Implementación de los procesos

Cada proceso de abajo se describe como los pasos que dan un cliente y el
servicio, con la respuesta de cada salida. El anexo A lista los estados.

### 5.1 Una petición, de la URL a la respuesta

```
petición
  │
  ├─ cuerpo demasiado grande ─────────────────────────────────────▶ 413
  ├─ petición previa de otro sitio (OPTIONS) ─────────────────────▶ 204, con las cabeceras permitidas
  │
  ▼ ruta
  ├─ nada responde a la URL ──────────────────────────────────────▶ 404
  ├─ un método que la ruta no permite ────────────────────────────▶ 405, con los métodos permitidos
  │
  ▼ acceso
  ├─ ajustes imposibles de aplicar ───────────────────────────────▶ 500
  ├─ cliente en espera tras demasiados intentos fallidos ─────────▶ 429, con el tiempo de espera
  ├─ ninguna credencial, o una errónea ───────────────────────────▶ 401 (una página: envío al inicio de sesión)
  ├─ no se tiene el rol de la ruta ───────────────────────────────▶ 404
  │
  ▼ la ruta responde por sí misma, o lanza una acción
  ├─ redirección ─────────────────────────────────────────────────▶ 301 a 308
  ├─ archivo o página ────────────────────────────────────────────▶ 200, 206 (una parte), 304 (ya lo tiene), 404
  ├─ la aplicación o la acción no está accesible ─────────────────▶ 404
  ├─ falta una entrada obligatoria o no es válida ────────────────▶ 400
  │
  ▼ acción
  └─ el estado y los datos que da la acción ──────────────────────▶ 2xx con los datos, o un error
```

- Una ruta abierta a todos se salta el paso del acceso: pasa todo el mundo.
- Lo que el cliente no puede alcanzar es siempre el mismo 404, tanto si la
  URL no existe como si no se tiene el rol. Así, un cliente no puede trazar
  el mapa de lo que contiene el servicio.
- La respuesta se escribe en el formato del perfil. Un error es
  `{"error": "<message>", "code": <status>}`, y un sitio responde con su
  propia página de error cuando tiene una.

### 5.2 Iniciar y cerrar sesión

**El inicio de sesión** es una acción abierta a todos, `auth/login`:

1. El front-end envía el nombre y la contraseña (`userName`, `passWord`)
   con `POST`, en el cuerpo de la petición. Una contraseña en la URL se
   rechaza (400), porque las URL acaban en los registros y en el historial
   de los navegadores.
2. Una dirección de cliente en espera tras demasiados intentos fallidos se
   rechaza en el acto (429).
3. El nombre y la contraseña se verifican en la base de usuarios. Un nombre
   erróneo, una contraseña errónea y una cuenta inactiva reciben todos la
   misma respuesta, `Invalid credentials` (401), y cuentan contra la
   dirección del cliente. El tiempo de respuesta no delata si el nombre
   existe.
4. Un usuario con códigos de doble factor también tiene que enviar un
   código (`sentCode`):
   - **desde una app de autenticación**: sin código, la respuesta es
     `Verification code required` (401);
   - **por correo**: un primer intento sin código envía uno a la dirección
     del usuario (un correo por minuto como mucho), y recibe el mismo
     `Verification code required` (401). El front-end envía después el
     nombre, la contraseña y ese código.

   Un código erróneo o caducado recibe `Invalid verification code` (401) y
   cuenta contra la dirección. Cada código solo se acepta una vez.
5. El servicio entrega entonces la identidad que llevará el front-end: un
   **token** en el cuerpo de la respuesta, o una **sesión**, cuya cookie
   sale con la respuesta. Un servicio que solo verifica tokens emitidos en
   otro sitio no emite ninguno (501).

En la base de usuarios nativa, una cuenta cuya contraseña falla demasiadas
veces seguidas queda en espera un tiempo, venga de la dirección que vengan
los intentos.

**Cada petición posterior** lleva el token (`Authorization: Bearer …`) o
la cookie. Un token vale hasta que caduca, una hora en los ajustes de
ejemplo. Una sesión termina tras un rato de inactividad, o tras un tiempo
total. Un usuario bloqueado por un gestor se rechaza en el acto cuando el
perfil consulta la base de usuarios en cada petición.

**El cierre de sesión**, `auth/logout`, termina la sesión y hace caducar
su cookie. Un token no se puede retirar: el front-end simplemente lo
olvida.

**Los usuarios con sesión iniciada** también pueden cambiar su propia
contraseña (`auth/setpass`, con la actual) y el idioma de sus correos
(`auth/usrlang`).

Queda constancia de cada inicio de sesión con un rol por encima de
`standard`, y de cada uno de sus accesos. Queda constancia de cada rechazo
con su motivo.

### 5.3 La vida de una cuenta

```
     POST users, account makeUser           auth/signup (5.4)
        (por un gestor)                     (por el visitante)
               │                                     │
               ▼  código enviado, o dado             ▼  código enviado
          ┌──────────┐                          ┌──────────┐
    ┌────▶│ awaiting │                          │ awaiting │── código caducado: se elimina
    │     └──────────┘                          └──────────┘
    │          │ auth/active:                        │ auth/active:
    │          │ código, contraseña elegida          │ código, contraseña elegida
    │          │                                     ▼
    │          │                                ┌──────────┐
    │          │                                │ inReview │── rechazada: se elimina
    │          │                                └──────────┘
    │          │                                     │ POST users/<user>/approve, account approves
    │          ▼                                     │ (por un gestor)
    │     ┌──────────┐◀──────────────────────────────┘
    └─────│ approved │ bloquear  ┌──────────┐
 restabl. │          │──────────▶│ disabled │
(gestor)  └──────────┘◀──────────└──────────┘
                      desbloquear
       eliminar: desde cualquier estado, con los dispositivos de la cuenta
```

- **Creación**: un gestor crea la cuenta (`POST users`, o
  `account makeUser` en la línea de comandos) con su nombre y su rol, y si
  quiere una dirección de correo electrónico y un idioma. Empieza en
  `awaiting`, con un código de activación. Cuando el servicio envía correos
  y el usuario tiene una dirección, el código se le envía por correo. Si
  no, la respuesta se lo da una vez al gestor, que lo transmite.
- **Alta**: un visitante abre por su cuenta una cuenta `awaiting`, cuando
  el servicio lo permite (5.4).
- **Activación**: el usuario envía su nombre, el código y la contraseña
  que elige (`auth/active`). Una cuenta creada por un gestor pasa a
  `approved`, y la de un visitante dado de alta pasa a `inReview`. Un
  código erróneo se rechaza igual que un nombre erróneo, y cuenta.
- **Revisión**: un gestor aprueba la cuenta (`POST users/<user>/approve`,
  o `account approves`) y pasa a `approved`, o la rechaza eliminándola.
  Una cuenta en `inReview` no puede iniciar sesión, ni ser bloqueada.
- **Bloqueo**: un usuario bloqueado no puede iniciar sesión, y su sesión
  termina en su siguiente petición cuando el perfil consulta la base de
  usuarios (5.2). Solo se puede bloquear una cuenta `approved`.
- **Restablecimiento**: para una contraseña perdida, un gestor devuelve la
  cuenta a `awaiting` con un código nuevo. La contraseña antigua y el token
  personal dejan de funcionar en el acto. Una cuenta bloqueada tiene que
  desbloquearse primero (409 si no).
- **Rol, dirección, idioma**: un gestor puede cambiarlos en cualquier
  momento. Un nombre y una dirección solo pertenecen cada uno a una cuenta
  (409).
- **Eliminación**: la cuenta desaparece, junto con sus dispositivos.
- **Aprobación**: un delegado o un gestor aprueba un alta. Conserva su rol,
  `standard`; solo un gestor lo cambia.
- **Salvaguardas**: un gestor no puede bloquear, restablecer ni eliminar
  su propia cuenta, ni cambiar su rol, y el último gestor activo no puede
  perder esa condición.

**Los códigos** tienen diez caracteres, `XXXX-XXXX-XX`, sin caracteres que
se parezcan, y se leen sin importar las mayúsculas ni los guiones. Cada uno
se usa una vez, y solo se guarda su hash. Un código entregado por un gestor
dura 300 minutos. Uno enviado por correo dura los minutos que digan los
ajustes (15 por defecto).

### 5.4 Darse de alta

Cuando el servicio lo permite y envía correos, los visitantes pueden abrir
su propia cuenta, y un gestor decide si se queda.

```
visitante                          servicio                                       gestor
   │  auth/signup: nombre, dirección  │                                              │
   │─────────────────────────────────▶│ 202, siempre                                 │
   │                                  │ libre: cuenta awaiting, código enviado       │
   │                                  │ nombre ocupado: se avisa a la dirección dada │
   │                                  │ dirección ocupada: se avisa a su titular     │
   │  auth/active: código, contraseña │                                              │
   │─────────────────────────────────▶│ la cuenta, pendiente de aprobación           │
   │                                  │ aviso a los gestores si se acumulan ────────▶│
   │                                  │     POST users/<user>/approve                │
   │                                  │◀─────────────────────────────────────────────│
   │                                  │ la cuenta aprobada                           │
```

1. El visitante da un nombre y una dirección de correo electrónico. La
   respuesta es 202 pase lo que pase, así que nadie puede averiguar qué
   nombres o direcciones están ocupados. Un nombre y una dirección libres
   crean una cuenta `awaiting`, y un código sale hacia la dirección. Si el
   nombre ya está ocupado, se avisa a la dirección dada; si lo está la
   dirección, su titular recibe un correo que dice que alguien ha intentado
   usarla.
2. El visitante confirma la dirección con el código y elige una contraseña
   (`auth/active`), y la cuenta pasa a `inReview`.
3. Un gestor la aprueba (`POST users/<user>/approve`, o
   `account approves`), y pasa a `approved`. Rechazarla es eliminarla.

**Límites**: un cliente solo puede darse de alta un cierto número de veces
en un periodo dado (429 por encima), y solo un cierto número de altas puede
estar pendiente a la vez (503 por encima). Los gestores reciben un correo
cuando el recuento de altas pendientes llega a los umbrales fijados. Un
alta cuyo código caducó sin usarse se elimina. Sin altas permitidas, o sin
correo, `auth/signup` no se encuentra (404).

### 5.5 Una contraseña olvidada

```
usuario ── auth/forgot: nombre o dirección ──▶ 202, siempre
                                               una cuenta aprobada con una dirección: un código enviado,
                                               la contraseña se queda como está
usuario ── auth/recover: nombre, código, nueva contraseña ──▶ la nueva contraseña guardada
```

1. El usuario da su nombre o su dirección (`auth/forgot`). La respuesta es
   202 sea quien sea el usuario, o aunque no exista, y cada petición cuenta
   contra la dirección del cliente.
2. Una cuenta aprobada con una dirección recibe un código. Su contraseña
   sigue funcionando, así que una petición hecha por otra persona no cambia
   nada mientras el código no se use.
3. El usuario envía su nombre, el código y su nueva contraseña
   (`auth/recover`). Un código erróneo o caducado se rechaza (401), y
   cuenta.

Sin correo, `auth/forgot` no se encuentra (404). Un gestor restablece
entonces la cuenta en su lugar (5.3), lo que anula en el acto la contraseña
antigua.

### 5.6 Los dispositivos

Un dispositivo es una máquina que llama al servicio sin que nadie inicie
sesión: un quiosco, un sensor, un servidor doméstico. Actúa en nombre de
un usuario de la base de usuarios nativa, con su propio token.

1. El administrador da un token al dispositivo, con un nombre, la etiqueta
   que le da la aplicación y un tope: el rol más alto con el que actúa,
   `standard` salvo que se indique otro, nunca por encima del de su usuario
   (`php nyuRun devices newToken <name> <user> <label> --roleTops=<role>`).
   El token se muestra una vez, y solo se guarda su hash.
2. El dispositivo envía su token en una cabecera con cada petición. Se
   acepta mientras el dispositivo no esté revocado y su usuario esté
   activo, y se anota la hora en que se le vio por última vez.
3. En cada petición, el dispositivo actúa con el más bajo entre su tope y
   el rol actual de su usuario, así que bajar de rol al usuario baja de
   golpe todos sus dispositivos. Un dispositivo creado antes de los topes no
   tiene ninguno y actúa con el rol de su usuario; `install checkAll`
   nombra aquellos cuyo usuario está por encima de `standard`
   (`device caps`). Lo que ese rol puede alcanzar lo deciden las rutas,
   igual que para un usuario.
4. Un dispositivo nunca modifica la cuenta de su usuario: cambiar la
   contraseña, o la dirección de su propio usuario, exige el inicio de
   sesión con contraseña, y ahí un token de dispositivo se rechaza con un
   403.
5. El administrador lista los dispositivos (`devices fullList`) y puede
   revocar uno (`devices revokeIt`). Un dispositivo revocado se rechaza
   desde su siguiente petición, y se queda en la lista con la hora en que se
   revocó. Un dispositivo cuyo usuario está bloqueado se rechaza, y uno cuyo
   usuario se elimina desaparece con él.

Los dispositivos necesitan la base de usuarios nativa: un servicio con
dispositivos en uso no puede pasar sus usuarios al archivo de usuarios
mientras no se revoquen.

### 5.7 Una aplicación que llama por uno de sus usuarios

Otra aplicación (una aplicación móvil, el portal de un socio) llama al
servicio en nombre de sus propios usuarios, que nunca inician sesión en el
propio servicio.

```
administrador ── php nyuRun appkeys makeKeys <app> --routeSet=… --publFile=… --roleTops=… ──▶ clave, mostrada una vez
aplicación    ── petición: su clave en X-App-Key, y un token que firma para su usuario ──▶ servicio
servicio      ── ¿clave conocida? ¿token firmado por esa aplicación? ¿ruta listada? ¿rol que se tiene? ──▶ acción
```

1. **El administrador registra la aplicación**: su nombre, las rutas que
   puede llamar, la clave pública que verifica sus tokens y el rol más alto
   con el que pueden actuar sus usuarios. El servicio entrega una clave,
   mostrada una vez, y solo guarda su hash.
2. **La aplicación llama** con su clave en una cabecera propia
   (`X-App-Key` por defecto). Como token portador, envía un token que firmó
   con su clave privada para uno de sus usuarios, con el nombre y el rol de
   ese usuario, y una caducidad.
3. **El servicio verifica**, en este orden:
   - la clave: una errónea da un 401, que cuenta contra la dirección;
   - el token: tiene que estar firmado por esa aplicación, sin caducar, y
     nombrar la audiencia de este proyecto cuando hay una fijada;
   - la ruta: una que la clave no lista da un 404;
   - el rol de la ruta: un 404 cuando no se tiene.

   El rol nunca es más alto que el tope fijado para la aplicación.
4. **La acción se ejecuta** para ese usuario, y sabe qué aplicación ha
   llamado. El usuario pertenece a la aplicación: el servicio no lo busca
   en su propia base de usuarios.

Una escritura (cualquier método que no sea de lectura) queda registrada
como aviso (`notice`), una lectura como información, y una ruta rechazada
como aviso. El administrador puede cambiar las rutas de una
aplicación, y revocar su clave: la siguiente petición que la use se
rechaza. Una ruta abierta a todos ignora la clave.

### 5.8 Trabajar con los datos

Un run da a sus datos las cinco operaciones a través de sus acciones. Con
una ruta para cada método, una sola URL cubre un recurso:

| El front-end envía | La acción | La respuesta |
|---|---|---|
| `GET /shows?filters[search]=jazz&sorting=^date&indexes=20,40` | lee una lista, filtrada, ordenada y recortada | 200 con las filas, 204 cuando ninguna coincide |
| `GET /shows/12` | carga un registro y sus filas relacionadas | 200 con el registro, 404 cuando no existe |
| `POST /shows` con los campos | rellena un registro nuevo y lo guarda | 201 con su identificador, 400 para un campo que no es válido |
| `PATCH /shows/12` con los campos | lo carga, lo modifica y lo guarda | 200, 404, 409 cuando la modificación entra en conflicto |
| `DELETE /shows/12` | lo elimina, con las filas hijas nombradas | 200 o 204, 404 |

Lo que pasa por el camino:

- **Las entradas**: solo llegan a la acción los campos que declara, cada
  uno en el formato correcto, y un campo obligatorio que falta da un 400
  antes de que la acción se ejecute. Un valor que no es válido para su
  formato se vacía.
- **Los filtros**: un valor de la petición se compara tal cual, nunca como
  un patrón. Una búsqueda con comodín se pide por el nombre del campo, y un
  valor enviado para ella se protege primero, así que un cliente no puede
  ampliar lo que se le muestra.
- **Las listas**: las filas se leen de una cantidad fija cada vez (una por
  defecto cuando no se pide ninguna), a partir de un desplazamiento,
  ordenadas por cualquier campo.
- **Los registros**: sus filas relacionadas vienen con ellos (un
  espectáculo y sus funciones). Una eliminación con sus hijos se hace en
  una sola transacción: todo, o nada. Queda constancia de cada
  eliminación, con el usuario que la pidió.
- **Los archivos en un registro**: una imagen o un archivo se guarda tal
  como se envió, y se devuelve igual, leído trozo a trozo.

Los nombres de esta tabla vienen de un ejemplo: cada run nombra sus propias
acciones, y las rutas que declara deciden qué método llega a cuál.

### 5.9 Documentos y medios

**Los documentos.** Una acción lee un documento (un archivo enviado con la
petición, o uno del servidor) y obtiene su contenido en una de tres
formas, sea cual sea su formato:

| Forma | Para | A partir de |
|---|---|---|
| un árbol de claves y valores | ajustes, intercambios de datos | JSON, YAML, XML, INI |
| tablas de filas y celdas | hojas de cálculo | CSV, TSV, Excel, hojas de cálculo OpenDocument |
| bloques de texto en orden | documentos para leer | texto, Markdown, HTML, Word, textos OpenDocument, PDF, ePub |

Un calendario (iCalendar) da sus eventos, sus tareas y sus notas. La acción
trabaja sobre la forma, y luego la escribe en cualquier formato que la
admita: una hoja leída de Excel se puede escribir en CSV, un texto Markdown
en Word, PDF o ePub. Un documento cuyo contenido no corresponde a su nombre
se rechaza.

**Las imágenes**: se pueden leer su tipo, su tamaño y lo que registra una
foto (fecha, cámara, ubicación). Se pueden redimensionar, recortar, girar
y limpiar de esos datos incrustados, y un dibujo (SVG) se puede convertir
en imagen.

**Los sonidos**: se pueden leer su tipo, su duración, sus etiquetas y su
imagen de portada, y quitar sus etiquetas. Un sonido WAV o MP3 se puede
cortar. Con ffmpeg en el servidor, cualquier sonido se puede convertir a
otro tipo, o cortar.

**Los códigos QR** se pueden crear a partir de un texto, como la dirección
que escanea una app de autenticación.

Queda constancia de cada archivo que trae una petición, tanto si se recibió
como si falló, y tanto si la petición se permitió como si no. Un cuerpo
más grande de lo que acepta el servicio se rechaza (413): uno anunciado más
grande no se lee en absoluto, y uno que resulta más grande no se lee más
allá del límite.

### 5.10 Enviar correo

1. **Las cuentas**: el proyecto lista sus cuentas de correo, y la primera
   es la predeterminada. Cada una envía a través de un servidor SMTP, de
   PHPMailer (para los adjuntos), o en archivos, lo que guarda cada correo
   en la máquina durante el desarrollo.
2. **Un correo** tiene un remitente, unos destinatarios, un asunto, una
   versión de texto y una versión HTML. Se escribe a partir de una
   plantilla: un archivo de texto cuya primera línea es el asunto, más un
   archivo HTML a su lado, con sus marcadores rellenados y codificados.
3. **El envío**: la conexión va cifrada y el certificado del servidor se
   verifica. Solo con un servidor de correo en la misma máquina se habla
   sin cifrar. Una cuenta puede enviar una copia de cada correo a otras
   direcciones, y una copia que falla se registra, no se notifica.
4. **De qué queda constancia**: de cada correo enviado, o rechazado, en el
   registro del framework.

**Los correos de acceso** son los de 5.2 a 5.5:

| Correo | Se envía cuando | A |
|---|---|---|
| `activate` | se crea o se restablece una cuenta con dirección | el usuario |
| `register` | un visitante se da de alta | la dirección dada |
| `password` | un usuario pide una contraseña nueva | el usuario |
| `passcode` | un usuario con códigos de doble factor por correo inicia sesión | el usuario |
| `usrtaken` | un alta pide un nombre que ya está en uso | la dirección dada |
| `emltaken` | un alta usa una dirección que ya está en uso | el titular de la dirección |
| `waitlist` | el recuento de altas pendientes llega a un umbral fijado | los gestores |

Cada uno sale en el idioma del usuario. Si el usuario no tiene ninguno, se
usa el idioma de la petición cuando quien pide es ese usuario, y si no, el
idioma por defecto del servicio. El framework los incluye en inglés y en
francés, y un run puede sustituirlos por su propia redacción y su propio
aspecto.

### 5.11 Instalar, configurar y entregar un proyecto

```
máquina del desarrollador                   servidor
─────────────────────────                   ──────────────────────────────────────
php nyuRun bundles final ──▶ copia ───────▶ php nyuRun install complete
  (código, ejemplos,                          comprueba el servidor, crea los ajustes
   bibliotecas; ningún secreto,               locales a partir de los ejemplos, crea los
   ningún ajuste local)                       secretos y las claves, crea los usuarios,
                                              y lo comprueba todo
                                            php nyuRun setting …  (cambia un ajuste)
                                            php nyuRun install checkAll (antes de servir)
```

1. **Construir** una copia para el sitio al que va: el ordenador del
   desarrollador, un servidor de preproducción, la producción, o un sitio
   que declara el proyecto. Sus ajustes (el entorno, los errores que se
   muestran, lo que se registra) son los de ese sitio.
2. **Instalarla**: `install complete` pregunta primero qué es el proyecto:
   usuarios, inicio de sesión y datos en uno (`allInOne`), un servidor de
   autenticación que emite tokens para otros proyectos (`authServ`), un
   servidor de recursos sin usuarios propios, que acepta esos tokens
   (`dataServ`), o un sitio sin nada en lo que iniciar sesión, que solo
   sirve páginas y archivos (`openSite`). Después comprueba PHP y sus
   extensiones, crea los ajustes locales a partir de sus ejemplos, crea los
   secretos y las claves de tokens que falten, crea los usuarios (un
   archivo de usuarios, o tablas de base de datos) y lanza el diagnóstico.
   Con los usuarios en una base de datos, también pregunta si se envían
   los correos de acceso (activación, restablecimiento, altas), a través de
   qué cuenta de correo, y si las altas están abiertas; se puede enviar un
   correo de prueba en el momento, o más tarde con `install testMail`.
   Pregunta lo que necesita, proponiendo una respuesta por defecto para
   cada pregunta, y un script de despliegue puede saltárselas todas. No se
   sustituye nada de lo que ya existe.
3. **Configurar**: `setting` muestra y cambia los ajustes. Cada valor se
   comprueba, el archivo anterior se conserva, y un cambio que dejaría el
   servicio inutilizable se deshace.
4. **Comprobar**: `install checkAll` dice, fila por fila, qué está bien,
   qué merece un vistazo y qué falla. Termina con un código de error cuando
   falla una fila, así que un script de despliegue se detiene ahí.
5. **Funcionar**: los usuarios se gestionan desde la línea de comandos o a
   través del run `Users`. `showlog` muestra los registros; `cleanup` libera
   una dirección en espera y hace limpieza de los archivos temporales
   antiguos.
6. **Actualizar**: una copia nueva sustituye el código, mientras que los
   ajustes locales del servidor, sus secretos, sus claves y sus datos se
   quedan como están.

---

## 6. Configuración y escalabilidad

### 6.1 Lo que elige un proyecto

Cada elección de abajo es un ajuste en un archivo, que se cambia con
`php nyuRun setting` y se comprueba con `php nyuRun install checkAll`. Los
archivos y sus claves están listados en las
[Especificaciones técnicas, capítulo 6](techspecs.md#6-archivos-de-configuración).

| Elección | Opciones | Por defecto |
|---|---|---|
| **Perfil** del servicio | una API abierta, una API para programas con claves, una API web con tokens, un sitio con sesiones, un sitio de páginas, o un perfil propio del proyecto | tokens, JSON |
| **Cómo inicia sesión un usuario** | una contraseña verificada en un archivo de contraseñas, el archivo de usuarios o la tabla de usuarios; una clave de API; un token de dispositivo; un certificado de cliente; una clave de aplicación, para un usuario de otra aplicación | el archivo de usuarios |
| **En qué viaja la identidad** | un token firmado por el servicio, una cookie de sesión, una cabecera, la autenticación Basic | un token |
| **Quién llega a qué** | las rutas abiertas a todos, el rol mínimo de cada ruta, el rol que una acción pide por sí misma | lo fija el perfil: los perfiles por defecto, de API web y de sitio cierran todo salvo el inicio de sesión y lo que va con él, y reservan `Users` a los gestores |
| **Usuarios** | el archivo de usuarios (solo lectura) o una tabla de base de datos; altas abiertas o no; códigos enviados por correo o entregados en mano; longitud de las contraseñas; códigos de doble factor, por app o por correo | el archivo de usuarios, sin altas |
| **Límites** | los intentos fallidos antes de que una dirección o una cuenta quede en espera, y durante cuánto tiempo; las altas por cliente, y pendientes a la vez; el tamaño de las peticiones | una dirección en espera tras 10 intentos fallidos y una cuenta tras 5, durante 10 minutos |
| **Respuestas** | JSON, YAML, XML, CSV, HTML, en bruto; los sitios autorizados a llamar desde un navegador | JSON, ningún otro sitio |
| **Idiomas** | el idioma de los correos de los usuarios que no tienen ninguno; las plantillas de cada idioma | inglés |
| **Registros** | cuánto se escribe, dónde, y cuándo se rota un registro | todo, en `temp/logs` |
| **Entorno** | desarrollo, pruebas, preproducción, producción: lo que muestra un error | desarrollo |

Una elección que no se puede aplicar detiene el servicio con un error, en
lugar de dejarlo funcionar en un estado más débil: un rol desconocido, un
perfil con una clave que no conoce, un inicio de sesión que nombra una base
de usuarios que no existe.

### 6.2 Cómo crece un servicio

Un servicio empieza pequeño y crece sin reescribirse:

| De | A | Cómo |
|---|---|---|
| usuarios en un archivo | usuarios en una base de datos, con contraseñas gestionadas por ellos mismos, altas, correos, dispositivos | un comando los traslada, con sus contraseñas, sus roles y sus secretos de doble factor (`install moveUser nativeDb`). El archivo se queda como estaba, así que el inicio de sesión puede volver a apuntar a él, sin lo que haya cambiado desde entonces |
| una base de datos | varias, de varios tipos | cada una se nombra en los ajustes, y cada modelo nombra la que usa |
| una aplicación | muchas | una carpeta para cada una en `runs/`, y modelos compartidos por todas en `runs/Shared` |
| un servicio | varios que trabajan juntos | uno emite los tokens y los demás los verifican; las aplicaciones se llaman entre sí con claves (4.4) |
| un servidor | desarrollo, preproducción, producción, integración | una copia construida para cada uno, con sus propios ajustes (5.11) |
| las herramientas del framework | las del proyecto | su propia verificación de credenciales o su propio portador, sus propios comandos, sus propias plantillas de correo y sus propias páginas |

### 6.3 Funcionar en varios servidores

El servicio no guarda ningún estado propio entre dos peticiones, salvo lo
que se lista abajo. Un proyecto que funciona en varios servidores detrás de
un balanceador de carga tiene que prever cada punto:

| Estado | Se guarda en | En varios servidores |
|---|---|---|
| **Tokens** | el propio token, firmado | cualquier servidor puede verificarlo, siempre que todos tengan la misma clave |
| **Sesiones** | los archivos de PHP en el servidor | una carpeta o un almacenamiento compartido para las sesiones, o un balanceador que envíe a cada usuario al mismo servidor |
| **Recuentos de intentos fallidos** | archivos en `temp/limits` en cada servidor | cada servidor lleva su propio recuento, así que una dirección queda en espera en el servidor que vio los intentos |
| **Cuentas en espera, usuarios, dispositivos** | la base de datos | compartidos por todos |
| **Registros** | los archivos de cada servidor | los reúne el alojamiento, si se quiere |
| **Ajustes, secretos y claves de tokens** | los archivos de cada servidor | los mismos en todos: se crean una vez y los copia el alojamiento. Lanzar `install complete` en cada servidor daría a cada uno sus propias claves, y sus tokens no funcionarían en los demás |
| **Caché de configuración** | `temp/cache` en cada servidor | cada servidor crea la suya; tras un cambio de ajustes, lanza `php nyuRun setting clrCache` en cada uno |

### 6.4 Lo que aún no está

- **Sin caché para las respuestas de datos**: cada petición lee sus datos.
  Solo los archivos estáticos los guardan en caché los clientes (304).
- **Sin límites por ruta**: el límite de intentos se aplica por dirección y
  por cuenta, para todo el servicio.
- **Sin sockets**: cada intercambio es una petición y su respuesta.
- **Sin host reenviado**: detrás de un proxy, el host que pidió el cliente
  no se lee. El servicio conoce el host por el que lo llama su proxy, no el
  que usó el cliente.

Están listados con todo lo que queda para más adelante en las
[Especificaciones técnicas, 1.5](techspecs.md#15-lo-que-queda-para-más-adelante).

---

## 7. Resultados de rendimiento

### 7.1 Qué se midió

Cinco frameworks recibieron las mismas dos aplicaciones, cada una escrita
como se usa normalmente ese framework: su propio enrutador, su validación,
su capa de base de datos y sus ajustes de producción. nyuRuns sirve de
referencia: cada uno de los demás da los mismos estados y los mismos datos
para las mismas peticiones.

| Framework | Versión | Capa de base de datos |
|---|---|---|
| nyuRuns | 1.0, bundle `final` | sus modelos, `DBLst` y `DBRec` |
| Slim | 4.15.3 | PDO a secas, PHP-DI |
| Flight | 3.19.5 | su propio envoltorio de PDO |
| Laravel | 13.40.0 | modelos Eloquent |
| Symfony | 8.1.11 | entidades y repositorios de Doctrine ORM |

**Las aplicaciones.** Una ligera, una lista de tareas sin inicio de
sesión, y una compleja: proyectos compartidos por varios usuarios, con cada
petición firmada por un token (HS256). Diez escenarios:

| | Escenario | Hace |
|---|---|---|
| L1 | lista | 50 tareas, página 10 |
| L2 | lectura | una tarea |
| L3 | creación | una tarea, validada, releída |
| L4 | modificación | una tarea, releída |
| C1 | mis proyectos | los proyectos del usuario, con su rol en cada uno |
| C2 | tareas, filtradas y ordenadas | 50 tareas de un proyecto, por estado y orden |
| C3 | estadísticas de un proyecto | las tareas de un proyecto contadas por estado y por usuario |
| C4 | tarea con comentarios | una tarea, su responsable, sus subtareas, sus 20 últimos comentarios |
| C5 | creación de una tarea | en un proyecto, con sus campos comprobados y el responsable miembro del proyecto |
| C6 | añadir un comentario | a una tarea, releído |

**Las máquinas.** El servidor: un Intel NUC, Core i3-7100U (2 núcleos,
4 hilos, 2,4 GHz), 4 GB de memoria, Ubuntu 26.04. PHP 8.5.4 con PHP-FPM, un
pool estático de 8 workers, OPcache activado sin comprobar los archivos;
MySQL 8.4 en la misma máquina (buffer pool de 512 MB, sin registro
binario). Cada framework funcionó detrás de nginx y luego detrás de Apache
(MPM event), en su modo de producción: mapas de clases optimizados, cachés
precalentadas, sin depuración. nyuRuns funcionaba como lo construye su
bundle `final`: `PROD`, registros `MINI`, cachés de configuración y de
tablas activadas. La carga venía de una segunda máquina, por gigabit con
cable.

**La medición.** k6 mantiene ocupado un número fijo de clientes, y cada
uno envía su siguiente petición en cuanto recibe la respuesta a la
anterior, con HTTP/1.1 keep-alive. Una respuesta que no sea 2xx cuenta como
fallida. Cada pasada se mide durante 30 s, tras 10 s de calentamiento al
empezar cada escenario y 3 s entre niveles de carga, con 1, 8 y 32
clientes. La serie completa se ejecutó tres veces, con los frameworks en
un nuevo orden aleatorio cada vez y las bases de datos restablecidas antes
de cada framework; cada cifra es la mediana de las tres. La CPU se lee en
el servidor: el tiempo que usan PHP-FPM, MySQL y el servidor web durante
una pasada, dividido por sus peticiones. La máquina de carga nunca pasó
del 62 % de su propia CPU.

### 7.2 Resultados

Peticiones por segundo, y el tiempo en el que se respondió al 99 % de
ellas (p99), detrás de nginx, de la serie del 24 y 25 de noviembre de 2026.

**Un cliente cada vez**: peticiones por segundo (p99 en ms)

| Escenario | Flight | nyuRuns | Slim | Symfony | Laravel |
|---|---|---|---|---|---|
| L1 lista | 430 (3,8) | 318 (4,5) | 357 (4,3) | 203 (7,7) | 120 (11,6) |
| L2 lectura | 500 (3,4) | 393 (3,9) | 429 (3,8) | 260 (6,7) | 162 (9,6) |
| L3 creación | 309 (5,6) | 265 (6,6) | 241 (6,7) | 143 (9,6) | 117 (12,2) |
| L4 modificación | 290 (5,7) | 235 (7,5) | 236 (7,5) | 180 (7,4) | 134 (10,5) |
| C1 mis proyectos | 436 (3,8) | 346 (4,6) | 381 (4,1) | 204 (7,1) | 152 (9,2) |
| C2 tareas, filtradas | 330 (4,4) | 264 (6,8) | 262 (6,2) | 117 (11,8) | 98 (11,7) |
| C3 estadísticas de un proyecto | 284 (5,8) | 235 (6,0) | 267 (6,8) | 146 (9,6) | 120 (12,0) |
| C4 tarea con comentarios | 331 (4,9) | 257 (7,0) | 309 (4,8) | 137 (10,1) | 91 (13,8) |
| C5 creación de una tarea | 254 (7,1) | 219 (6,9) | 204 (7,9) | 105 (12,6) | 91 (14,3) |
| C6 añadir un comentario | 262 (6,8) | 222 (7,4) | 209 (6,6) | 109 (12,0) | 90 (14,1) |

**32 clientes a la vez**: peticiones por segundo (p99 en ms)

| Escenario | Flight | nyuRuns | Slim | Symfony | Laravel |
|---|---|---|---|---|---|
| L1 lista | 1 407 (27) | 1 041 (36) | 1 200 (32) | 551 (67) | 313 (118) |
| L2 lectura | 1 785 (21) | 1 318 (29) | 1 475 (26) | 712 (52) | 428 (86) |
| L3 creación | 1 014 (45) | 992 (44) | 865 (49) | 429 (87) | 334 (110) |
| L4 modificación | 914 (48) | 835 (50) | 840 (49) | 479 (77) | 352 (104) |
| C1 mis proyectos | 1 512 (25) | 1 147 (33) | 1 270 (30) | 549 (67) | 395 (94) |
| C2 tareas, filtradas | 1 066 (36) | 810 (46) | 797 (47) | 300 (122) | 251 (145) |
| C3 estadísticas de un proyecto | 869 (44) | 715 (53) | 796 (48) | 395 (92) | 307 (117) |
| C4 tarea con comentarios | 1 055 (35) | 799 (46) | 953 (39) | 376 (97) | 240 (152) |
| C5 creación de una tarea | 813 (55) | 784 (52) | 712 (56) | 303 (121) | 259 (140) |
| C6 añadir un comentario | 824 (52) | 796 (52) | 761 (53) | 317 (115) | 261 (139) |

El servidor está totalmente ocupado desde 8 clientes: pasar a 32 añade
espera, no peticiones (nyuRuns gana menos del 1 % en cada escenario).

**La CPU por petición**, en ms, la media de los diez escenarios con 32
clientes:

| | Flight | nyuRuns | Slim | Symfony | Laravel |
|---|---|---|---|---|---|
| PHP | 1,61 | 2,63 | 2,56 | 7,76 | 10,66 |
| MySQL | 1,87 | 1,52 | 1,49 | 1,68 | 2,21 |
| Servidor web | 0,22 | 0,23 | 0,23 | 0,26 | 0,26 |
| **Total, nginx** | **3,70** | **4,39** | **4,28** | **9,70** | **13,13** |
| Total, Apache | 3,81 | 4,62 | 4,50 | 9,96 | 13,42 |

**Detrás de Apache**, el servidor web cuesta unos 0,43 ms por petición,
frente a 0,24 ms con nginx, y el tiempo de PHP sigue igual. Esos 0,2 ms
pesan más en los frameworks ligeros: nyuRuns atiende entre un 3,8 y un
7,1 % menos de peticiones que detrás de nginx, y sus proporciones se
mueven una o dos centésimas (1,03 veces Slim, 1,21 veces Flight, 0,46 veces
Symfony, 0,34 veces Laravel). La clasificación no cambia.

Lo que muestra:

- **nyuRuns cuesta lo que cuesta un micro-framework**: 1,02 veces Slim y
  1,19 veces Flight, que es el que menos hace alrededor de una petición.
- **Escenario por escenario frente a Slim**, nyuRuns atiende más en C2 y
  en tres escrituras (L3, C5, C6), más o menos lo mismo en L4 (un 0,6 %
  menos), y entre un 9,6 y un 16,2 % menos en las demás lecturas (L1, L2,
  C1, C3, C4).
- **Flight sigue por delante**: nyuRuns atiende entre un 17,7 y un 26,2 %
  menos de peticiones en las lecturas, entre un 2,1 y un 3,5 % menos en
  tres escrituras (L3, C5, C6), y un 8,7 % menos en la modificación (L4).
- **Symfony y Laravel cuestan 2,21 y 2,99 veces más CPU**: nyuRuns atiende
  de 1,74 a 3,33 veces sus peticiones, haciendo lo mismo que ellos para
  estas aplicaciones: el enrutamiento, la validación de las entradas, los
  tokens firmados, modelos en lugar de consultas.
- **MySQL cuesta más o menos lo mismo para todos**, con Laravel algo por
  encima. La cifra de Flight sube por su C5 (7.4).

### 7.3 Cómo llegó nyuRuns hasta ahí

La primera serie completa, el 5 de noviembre, situaba a nyuRuns en 6,03 ms
por petición de media. Entre esa serie y la de arriba, el framework se
optimizó sin cambiar lo que hace:

- menos sentencias por petición: cada tabla descrita una sola vez, y un
  modelo creado solo cuando una acción lo pide;
- un mapa de clases completo en los bundles `stage` y `final`;
- ninguna línea de registro para el acceso de un usuario estándar en
  producción (los inicios y cierres de sesión, los rechazos y los accesos
  privilegiados se siguen escribiendo);
- la configuración, las rutas y las descripciones de las tablas leídas una
  vez, y después desde una caché (`CNF_CACH`, `TBL_CACH`).

La media pasó de 6,03 ms a 2,67 ms: menos de la mitad.

Esas cifras vienen de la máquina Windows del 5 y el 7 de noviembre, una
petición cada vez. La serie del 24 y 25 de noviembre volvió a medir el
resultado, en Linux y bajo carga, con todo lo añadido desde entonces: las
rutas propias de cada run, un contexto para cada acción, la revisión de
seguridad, el cambio de nombres.

### 7.4 Lo que estas cifras no dicen

- **Por debajo del servidor de referencia.** Dos núcleos y 4 GB, frente a
  seis núcleos y 16 GB (Requisitos, 7.3). Las proporciones entre frameworks
  valen en cualquier máquina; las peticiones por segundo son una cota
  inferior, no los objetivos P-07 y P-08.
- **32 clientes como mucho.** Los objetivos piden 50; a partir de 8
  clientes, esta máquina solo añade espera.
- **PHP 8.5, no 8.2.** No se midió la versión más antigua que admite
  nyuRuns.
- **La memoria no se midió**, así que P-09 sigue abierto.
- **Dos aplicaciones.** No se midieron otros tipos de trabajo (documentos
  grandes, imágenes, muchos joins).
- **16 peticiones fallidas**, de 15,4 millones, todas detrás de Apache, con
  32 clientes, solo en POST y PATCH: conexiones que el servidor cerró justo
  cuando salía la petición siguiente, muy probablemente el MPM event de
  Apache cerrando conexiones keep-alive. Todos los frameworks tuvieron
  alguna (nyuRuns 5, Symfony 4, Flight 3, Slim 2, Laravel 2), nginx
  ninguna. Vienen del servidor web, y ninguna cifra cambia.
- **Una anomalía sin explicar.** C5 se volvió a ejecutar el 25 de noviembre
  para nyuRuns y Flight: las cifras se mantuvieron con un margen del 2 %
  (nyuRuns 780 y 779 por segundo detrás de nginx, frente a 784). El C5 de
  Flight sigue costando a MySQL alrededor de 1 ms más detrás de nginx que
  detrás de Apache, con las mismas sentencias y sin esperas de bloqueo;
  desactivar el spinning del redo log de MySQL no cambió nada. Mueve la
  media de Flight con nginx alrededor de un 1 % y no afecta a nyuRuns,
  cuyo tiempo de MySQL es el mismo con los dos servidores.

---

## 8. Pautas de desarrollo

Cómo diseñar un servicio con nyuRuns, desde lo que necesita el negocio
hasta lo que llama el front-end. Las reglas técnicas (los secretos, los
bundles, las entradas, los nombres) están en las
[Especificaciones técnicas, capítulos 2 y 12](techspecs.md#2-pautas-y-recomendaciones).

### 8.1 Del negocio a los runs

1. **Haz la lista de lo que guarda el negocio**: socios, pedidos,
   espectáculos, facturas. Cada cosa se convierte en una tabla, y en un
   modelo de esa tabla.
2. **Agrúpalas por tema**: lo que se gestiona junto, por las mismas
   personas, va en un mismo run (`Members`, `Orders`, `Shows`). Un run
   que acaba conteniendo cosas sin relación debería dividirse.
3. **Dale un propietario a cada cosa**: un solo run la escribe (y, en un
   grupo de servicios, un solo servicio), y los demás la leen por las rutas
   de ese propietario.
4. **Pon lo que necesita cada run en `runs/Shared`**: un modelo usado por
   varios runs, o una verificación de credenciales propia del proyecto.

### 8.2 De los runs a las rutas

- **Una URL por recurso**, con el método que dice qué se hace: `GET` lee o
  lista, `POST` crea, `PUT` o `PATCH` modifica, `DELETE` elimina (3.1).
  Declara una ruta por recurso en el propio `routes.rte` del run, con un
  destino por método, en lugar de una URL por verbo. Entonces solo se
  llega al run por esas rutas.
- **Nombra lo que ve el front-end**: las rutas son el contrato con el
  front-end. Un run se puede reorganizar detrás de ellas, o el perfil puede
  montarlo en otro sitio, sin que el front-end se dé cuenta.
- **Responde con el estado correcto**: 201 para lo que se crea, 204 para
  una lista vacía, 404 para lo que no existe, 409 para una modificación que
  entra en conflicto, 400 para una entrada no válida. El front-end decide
  según el estado, no según el mensaje.
- **Devuelve lo que muestra el front-end**, y nada más: una lista da los
  campos de una lista, un registro los de su página. Un hash de contraseña,
  un código o un token nunca sale del servicio.

### 8.3 Las entradas

- **Declara las entradas de cada acción**, cada una con su formato. Lo que
  no se declara no llega a la acción.
- **Marca como obligatorio** aquello sin lo que la acción no puede
  funcionar, para que la petición se rechace antes de que se ejecute la
  acción.
- **Que una búsqueda siga siendo una búsqueda**: un valor del front-end se
  compara tal cual. Ofrece coincidencias con comodín solo en los campos
  donde tenga sentido.
- **Envía las contraseñas en el cuerpo**, nunca en la URL.

### 8.4 El acceso

1. **Elige el perfil** según quién llama: programas con claves, una API web
   con tokens, o un sitio con sesiones.
2. **Abre rutas a propósito**: lista las rutas a las que puede llegar
   cualquiera (el inicio de sesión, el alta, una página pública). Todas las
   demás exigen una credencial.
3. **Planifica los roles**: `standard` para los usuarios, `advanced` para
   el equipo, `managers` para quienes gestionan los usuarios. Da a cada
   ruta el rol mínimo que necesita, en el perfil. Una acción que necesita
   más lo pide por sí misma, y un run entero puede comprobarlo una vez, en
   `openEachAction()`.
4. **Deja que el front-end oculte lo que un usuario no puede alcanzar**: el
   servicio responde 404 para un rol que no se tiene, igual que para una
   URL que no existe, así que el front-end no debería ofrecerlo.
5. **Da a las máquinas y a los socios sus propias credenciales**: un token
   de dispositivo para una máquina, una clave de aplicación para la
   aplicación de un socio. Cada una se puede revocar por separado, y
   ninguna es nunca la contraseña de una persona.

### 8.5 Los usuarios

- **Empieza con el archivo de usuarios** mientras los usuarios sean pocos
  y fijos. Pasa a la base de datos cuando necesiten cambiar su contraseña,
  darse de alta, recibir correos o tener dispositivos (6.2).
- **Envía los códigos por correo** cuando el servicio tenga usuarios fuera
  del equipo: la activación, las altas y los restablecimientos ya no
  necesitan un gestor de por medio.
- **Abre las altas solo con revisión**: cada alta espera a un gestor, así
  que ajusta los límites a lo que los gestores pueden revisar.
- **Escribe los correos en los idiomas de los usuarios**: da a cada run sus
  propias plantillas, con su redacción y su aspecto, para cada idioma que
  tengan sus usuarios.
- **Ten al menos dos gestores**: el último no se puede bloquear ni
  eliminar, pero un gestor tampoco puede restablecer su propia cuenta. Una
  contraseña perdida necesita entonces el correo de contraseña olvidada,
  otro gestor, o la línea de comandos.

### 8.6 Los datos

- **Nombra las tablas como esperan los modelos** (columnas de 10
  caracteres, `id_<singular>` para el identificador, `<singular>_id` para
  un enlace, como `user_id`): así las relaciones se encuentran sin
  declararlas.
- **Declara los hijos** con los que se muestra un registro, y los que se
  eliminan con él, para que una eliminación sea todo o nada.
- **Acota las listas**: lee siempre una página de filas, con un orden que
  el usuario pueda seguir, en lugar de una tabla entera.
- **Usa un formato de documento para los intercambios**: lee una
  importación (CSV, Excel) en su forma, compruébala y después guárdala a
  través de los modelos. Escribe una exportación de la misma manera.

### 8.7 Del desarrollo a la producción

- **Desarrolla con los correos escritos en archivos**, y los errores a la
  vista.
- **Prueba cada run**: sus acciones, sus estados y los rechazos de sus
  roles. La batería de pruebas del propio framework muestra cómo
  (Especificaciones técnicas, capítulo 13).
- **Entrega una copia construida para cada sitio**, compruébala en su
  servidor antes de ponerla en marcha, y guarda los secretos y las claves
  solo en ese servidor (5.11).
- **Lee los registros**: los rechazos, las eliminaciones y los correos no
  enviados muestran con qué se han encontrado los usuarios.

---

## A. Respuestas y estados

Lo que recibe un cliente del framework y de los runs que incluye. Un error
se escribe `{"error": "<message>", "code": <status>}` en el formato del
servicio, y un sitio puede responder en su lugar con su propia página de
error. Los mensajes están en inglés y van dirigidos a los desarrolladores:
un front-end decide según el estado, y escribe su propio mensaje para el
usuario. Un run añade sus propios mensajes a sus estados.

### A.1 Éxito

| Estado | Cuándo | Cuerpo |
|---|---|---|
| **200** | la petición está hecha | los datos de la acción, o un archivo o una página |
| **201** | se crea un recurso | lo que devuelve la acción: para un usuario, su nombre, más su código cuando ningún correo lo lleva |
| **202** | se acepta una petición, sea cual sea su resultado: un alta, una contraseña olvidada | `{"asked": true}` |
| **204** | hecho, sin nada que enviar: una lista vacía, una petición previa de otro sitio | ninguno |
| **206** | una parte de un archivo, como pidió `Range` | esa parte |

Las respuestas del run `Auth`:

| Acción | Cuerpo en caso de éxito |
|---|---|
| `auth/login` | `{"token": "<the token>"}`, o `{"logged": true}` con la cookie de sesión |
| `auth/logout` | `{"logged": false}` |
| `auth/active` | `{"active": true, "stat": "approved"}`, o `{"active": false, "stat": "inReview"}` para un alta |
| `auth/setpass`, `auth/recover` | `{"changed": true}` |
| `auth/usrlang` | `{"lang": "<the language>"}` |
| `auth/keyset`, en `/.well-known/jwks.json` | las claves públicas que verifican los tokens (RS256); un 404 cuando los tokens se firman con un secreto (HS256) |

### A.2 Redirecciones

| Estado | Cuándo |
|---|---|
| **301, 302, 303, 307, 308** | una ruta o una acción envía al cliente a otro sitio (`Location`) |
| **302** | un visitante sin credencial pide una página de un sitio que tiene una página de inicio de sesión, y se le envía allí |
| **304** | el cliente ya tiene el archivo estático que pide |

### A.3 Errores del cliente

| Estado | Mensaje | Cuándo |
|---|---|---|
| **400** | `Missing or invalid input: <fields>` | falta una entrada obligatoria o no es válida |
| | `Password sent in the query string` | una contraseña ha llegado en la URL |
| | `Invalid userName or passWord`, `Invalid userName, sentCode or passWord`, `Invalid userName or userMail`, `Invalid oldValue or passWord`, `Invalid userName` | una entrada de una acción de `Auth` o `Users` no es texto |
| | `Password too short: …`, `Password too long: …`, `Password holding a control character`, `Password identical to the user name`, `Password identical to the current one` | una contraseña nueva incumple una regla |
| | `Invalid user name`, `Invalid mail address`, `Invalid role`, `Invalid status`, `Invalid language`, `Invalid filter` | un valor dado al run `Users` no es válido |
| **401** | `Invalid credentials` | un nombre o una contraseña erróneos al iniciar sesión, o una cuenta inactiva |
| | `Verification code required`, `Invalid verification code` | falta el código de doble factor o es erróneo |
| | `Invalid token`, `Expired token` | el token está falsificado o ha caducado |
| | `Account not active` | la cuenta detrás de una sesión o de un token se ha bloqueado entretanto |
| | `Invalid activation`, `Invalid code` | un nombre o un código erróneos en la activación o la recuperación, o un código caducado |
| | `Invalid API key`, `No API key provided`, `Invalid app key`, `Not logged in`, `No active session`, `Missing Bearer Authorization header`, … | falta la credencial que pide el perfil o es errónea |
| **403** | `Sign in with the password: a device cannot change its account` | un token de dispositivo en una acción que modifica la cuenta de su usuario (`needsPassLogin()`); una acción también puede responder uno por sí misma (`stopThisAction(403, …)`). Lo que un usuario, una clave de aplicación o una llamada desde la línea de comandos no puede alcanzar es un 404 |
| **404** | `Not Found` | nada responde a la URL; la aplicación, la acción, el archivo o la página no existe; se llama a un run con sus propias rutas por su ruta simple; no se tiene el rol (un usuario, una clave de aplicación o una llamada desde la línea de comandos); una clave de aplicación llega a una ruta que no lista; una función está desactivada (las altas, las contraseñas olvidadas) |
| | `User not found` | se pide al run `Users` un usuario que no existe |
| **405** | `Method Not Allowed` | la ruta o la acción no acepta ese método; la cabecera `Allow` lista los que acepta |
| **409** | `User name already taken`, `Mail address already used` | el nombre o la dirección pertenece a otra cuenta |
| | `A manager cannot do this to its own account` | un gestor bloquea, restablece o elimina su propia cuenta, o cambia su propio rol |
| | `The last active manager cannot be locked` (… `removed`, `reset`, `given another role`) | el servicio se quedaría sin gestor |
| | `A locked user is unlocked before it is reset`, `A pending user is neither locked nor unlocked`, `A user in review is approved or removed`, `User not awaiting review`, `User already …`, `No reset for this user` | el cambio no encaja con el estado de la cuenta |
| **413** | `Payload Too Large` | el cuerpo es más grande de lo que acepta el servicio |
| **429** | `Too many attempts` | la dirección está en espera tras demasiados intentos fallidos, o ha pedido demasiadas veces (altas, restablecimientos); `Retry-After` dice cuánto hay que esperar |

### A.4 Errores del servicio

| Estado | Mensaje | Cuándo |
|---|---|---|
| **500** | `Internal Server Error` | un error en el código, sin detalle en producción |
| | `Access misconfigured` | los ajustes de acceso no se pueden aplicar |
| | `Login unavailable`, `User management unavailable`, `Credential store unavailable`, `User cannot be written` | no se puede llegar a la base de usuarios o no se puede escribir en ella |
| **501** | `Tokens are issued by another server` | el servicio solo verifica tokens, y no emite ninguno |
| **503** | `Sign-ups closed for now` | hay demasiadas altas pendientes de aprobación |

---
