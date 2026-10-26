# BridgeAuditReadOnly — preparación, sin acceso AWS activado

26/10/2026. Cuenta 365470560685, región eu-west-1. El propietario autoriza preparar
aislamiento Linux, política mínima y procedimiento SSO. No autoriza crear usuarios
AWS, permission sets, asignaciones ni credenciales del Core. No se ha configurado
SSO ni instalado AWS CLI ni creado la cuenta Linux: el usuario de ejecución actual
es muserelaycom y `sudo -n /usr/bin/true` exige contraseña. No pedirla por chat.

## Cuenta Linux y frontera de ejecución

`prepare-linux-root.sh`, revisado y ejecutado posteriormente por un operador root,
crea exclusivamente `muserelay-bridge-audit`, grupo propio, contraseña bloqueada,
sin sudo/grupos adicionales ni SSH keys; home `/var/lib/muserelay-bridge-audit`
0700 y `.aws` 0700 fuera de vhosts, repositorios y sincronización del sitio.
Se detiene si encuentra cuenta/grupo/directorio previo. No es una instalación SSO.
La preparación del usuario no habilita a esta sesión para suplantarlo.

El operador root podrá abrir la sesión con
`sudo -iu muserelay-bridge-audit`, y debe verificar `id`, grupos, permisos y `umask
077` antes de autenticarse. No conceder a muserelaycom un sudo genérico sobre este
usuario, acceso a su home ni ejecución de comandos arbitrarios en su nombre.
Los comandos/revisiones podrán ejecutarse desde una sesión del agente iniciada
por el operador bajo el usuario aislado, o mediante un broker root-owned con
operaciones predefinidas y salidas depuradas. El broker no está implementado.
No afirmar que esta sesión dispone ya de acceso directo. El aislamiento de UID
no protege frente a root ni frente a una escalada al administrador del servidor.

## Política preparada y límites

`BridgeAuditReadOnly.policy.json` es una propuesta de política inline para un
permission set nuevo y dedicado. No usa ReadOnlyAccess ni AdministratorAccess,
no incluye AssumeRole/PassRole, lecturas de secretos/objetos/logs, mensajes,
inferencia o invocaciones. Permite solo metadatos de recursos identificados.
Un Deny NotAction impide acciones fuera de la lista incluso ante otra política
Allow. No adjuntar políticas adicionales: podrían ampliar los recursos sobre
los que se permiten estas mismas lecturas. IAM es global; el resto exige Irlanda.

IAM consulta únicamente cinco roles; no permite ListRoles, SimulatePrincipalPolicy
ni lectura de versiones de managed policies desconocidas. Si aparecen adjuntas
o boundaries, sus ARN se revisarán y se añadirá GetPolicy/GetPolicyVersion solo
tras autorización. No confundir lectura inline con auditoría efectiva completa.
No permite Lambda GetFunction/GetFunctionConfiguration ni ListFunctions:
pueden exponer código, variables o recursos ajenos. Mappings, suscripciones y
nuevos change sets requerirán añadir ARN exactos revisados; no se adivinan UUID.
ListSubscriptionsByTopic devuelve ARN para una comprobación posterior autorizada.
No concede ListChangeSets: solo DescribeChangeSet de dos nombres revisados.
GetTemplate usa recursos stack; validar la autorización real de solicitudes con
ChangeSetName antes de darla por operativa y revisar cualquier AccessDenied.
Templates, outputs y eventos pueden contener datos sensibles: no imprimirlos
íntegramente ni registrarlos automáticamente. No usar --debug ni export-credentials.

La política no está validada por IAM Access Analyzer ni probada contra AWS.
Se ha comprobado estructura JSON, lista exacta de acciones y restricciones locales.
No se debe aplicar sin revisión y autorización de la creación/asignación SSO.

## Activación futura SSO — requiere autorización aparte

1. Administrador: revisar política y crear permission set BridgeAuditReadOnly,
   sesión de una hora, sin políticas adicionales. Asignarlo a una identidad humana
   autorizada con MFA y únicamente en 365470560685. No usar el perfil Admin.
2. Operador del servidor: instalar AWS CLI v2 oficial, verificar procedencia e
   integridad y habilitar la ejecución bajo el UID aislado. No recargar servicios.
3. Dentro de ese UID, `umask 077`, ejecutar
   `aws configure sso --profile bridge-audit-readonly`. Introducir Start URL y región
   SSO desde la consola real; no están confirmadas aquí y no deben inventarse.
   Elegir solo BridgeAuditReadOnly, cuenta 365470560685, trabajo eu-west-1.
4. `aws sso login --profile bridge-audit-readonly --use-device-code --no-browser`.
   El titular completa el flujo en el navegador y MFA; no pegar códigos/tokens en
   chats/logs. Si aparecen roles administrativos, detenerse y revisar asignaciones.
5. Comprobar STS Account/Arn sin exportar claves. Hacer una lectura autorizada de
   la pila. Comprobar permisos de directorios/cache sin imprimir su contenido.
   Verificar denegaciones con simulación autorizada, no intentando envíos,
   ReceiveMessage, acceso a secretos ni otras operaciones reales prohibidas.

SSO cachea tokens en disco bajo el UID aislado; son secretos aunque no sean claves
IAM permanentes. Las credenciales AWS se renuevan durante la sesión SSO, pero
una sesión SSO expirada requiere nuevo login. No reutilizar Bedrock ni Admin.

## Revocación y rollback

Cerrar el ejecutor, `aws sso logout` bajo el UID aislado y retirar caches solo de
ese home. Logout local no revoca por sí solo credenciales ya emitidas. El
administrador retira la asignación e invalida sesiones SSO; terminar una sesión
del portal tampoco termina automáticamente sesiones IAM role ya emitidas.
Para bloqueo inmediato seguir el procedimiento oficial de Deny/SCP para sesiones
Identity Center, con el administrador de Organizations, o retirar los permisos
efectivos del permission set y reprovisionar/verificar su propagación; no editar
directamente AWSReservedSSO roles. Mantener el bloqueo hasta caducidad de sesiones.
Planificar este control antes de activar. No desactivar tu acceso de emergencia.

Tras cerrar procesos/sesiones, el operador puede retirar exclusivamente la cuenta
y su home auditados; revisar propietario/ruta antes de borrar. No tocar Core,
roles runtime, IAM Foundation, colas o canales. No hay reversión de producción.

## Coste y siguiente fase

Identity Center no tiene coste adicional de servicio. Infraestructura de logs,
almacenamiento y futuras escrituras pueden facturarse. No se ha realizado ninguna
operación AWS. La fase lectura no habilita CreateChangeSet ni artefactos; requerirá
otro diseño y autorización. Este trabajo no bloquea la revisión SAM independiente.

Referencias oficiales:
- https://docs.aws.amazon.com/cli/latest/userguide/cli-configure-sso.html
- https://docs.aws.amazon.com/cli/latest/reference/sso/login.html
- https://docs.aws.amazon.com/singlesignon/latest/userguide/end-active-sessions.html
- https://docs.aws.amazon.com/singlesignon/latest/userguide/revoke-user-permissions.html
