# Política de privacidad — FacturasNube

Última actualización: 30 de agosto de 2026

FacturasNube es un plugin de código abierto para FacturaScripts que sube el PDF de las
facturas de cliente a Google Drive. Se instala y se ejecuta **en el servidor de cada
usuario**. No existe ningún servicio alojado por el autor: no hay servidores, ni bases
de datos, ni cuentas gestionadas por terceros.

## Quién trata los datos

El responsable del tratamiento es **la persona u organización que instala el plugin en
su propio servidor de FacturaScripts**. El autor del plugin no recibe, almacena ni
puede acceder a ningún dato de las instalaciones de terceros.

## Qué datos se tratan

Todo se guarda en la base de datos de la propia instalación de FacturaScripts:

- **Credenciales de Google**: el token de acceso, el token de refresco, su fecha de
  caducidad, los permisos concedidos y la dirección de correo de la cuenta conectada.
  Se guardan en la tabla `facturas_nube_cuentas`.
- **Estado de sincronización**: por cada factura, su código, el identificador y el
  enlace del archivo en Google Drive, el nombre y la carpeta de destino, una huella del
  documento, el número de intentos y el último mensaje de error. Tabla
  `facturas_nube_archivos`.
- **Contenido de las facturas**: el PDF se genera en memoria en el momento de subirlo.
  No se guarda ninguna copia local adicional.

## Qué se envía a Google

Únicamente lo necesario para guardar las facturas en el Drive del propio usuario:

- El PDF de cada factura y su nombre de archivo.
- El nombre de las carpetas que el plugin crea para organizarlas (por ejemplo
  `2026/2026-08`).

Los destinos son exclusivamente `oauth2.googleapis.com` y `www.googleapis.com`. El
plugin no envía datos a ningún otro servicio, no incluye analítica ni telemetría, y no
comunica nada al autor.

## Permisos que se solicitan y por qué

El plugin pide el permiso mínimo posible:

- **`https://www.googleapis.com/auth/drive.file`** — permite crear, consultar,
  modificar y eliminar **únicamente los archivos que el propio plugin crea**. No da
  acceso al resto del contenido de Google Drive.

Se usa para tres cosas y ninguna más: crear la carpeta donde se guardan las facturas,
subir y actualizar el PDF de cada factura, y retirarlo cuando la factura se elimina en
FacturaScripts.

Opcionalmente, el administrador puede configurar el permiso completo
`https://www.googleapis.com/auth/drive`. Solo es necesario para escribir dentro de una
carpeta preexistente que no haya creado el plugin, y sigue usándose exclusivamente para
las mismas tres operaciones.

## Cuánto tiempo se conservan

Los tokens se conservan mientras la cuenta esté conectada. El estado de sincronización
se conserva mientras exista la factura correspondiente.

## Cómo revocar el acceso

De cualquiera de estas dos formas:

- En FacturaScripts: **Admin → Facturas en la nube → Desconectar**. El plugin revoca el
  token en Google y borra las credenciales guardadas.
- En Google: https://myaccount.google.com/permissions, retirando el acceso a la
  aplicación.

Los archivos ya subidos permanecen en Google Drive y son propiedad del usuario. Puede
eliminarlos desde Drive cuando quiera.

## Datos de menores

El plugin es una herramienta de gestión empresarial y no está dirigido a menores de
edad.

## Cambios en esta política

Cualquier cambio se publicará en este mismo documento dentro del repositorio del
plugin, junto con su fecha de actualización.

## Contacto

Para cualquier cuestión sobre esta política, abre una incidencia en el repositorio del
plugin en GitHub.
