# Acceso temporal BridgeTools — propuesta, no instalación

26/10/2026. Preparación autorizada únicamente. No se ha creado/configurado ninguna
identidad, rol, boundary, asignación, trail, pila, usuario Linux o credencial.
No se ha ejecutado IAM Foundation ni el conjunto SAM pendiente.

## Decisión y alcance prioritario

IAM Identity Center, identidad humana autorizada con MFA, permission set dedicado
`BridgeToolsPrepare`; posterior `BridgeToolsDeployClosed` solo tras aprobar el plan.
No utilizar AdministratorAccess, Bedrock ni claves IAM permanentes. MFA se exige
en Identity Center/IdP; no usar aws:MultiFactorAuthPresent como supuesto de SSO.
Primero recepción, bandeja nativa y organización demo; envío y Bedrock después.
No se prepara onboarding comercial. No se toca la autenticación Bedrock del Core.

La política de preparación permite metadatos acotados, nuevos artefactos y preparar
conjuntos, pero no ejecutar. La de despliegue cerrado añade exclusivamente
ExecuteChangeSet sobre la pila runtime existente. **No asignar simultáneamente
BridgeAuditReadOnly:** su Deny NotAction bloquearía las escrituras. Elegir un
permission set por operación y mantener las cachés de sus perfiles separadas.

## Archivos exactos para revisión

- `tools.prepare.policy.json`: política inline SSO candidata, sin ExecuteChangeSet.
- `tools.deploy-closed.policy.json`: candidata para despliegue cerrado aprobado.
- `sso.permission-sets.proposed.yaml`: creación reproducible de los dos permission
  sets mediante el administrador de Identity Center, con asignaciones desactivadas
  por defecto y mutuamente excluyentes. Requiere InstanceArn y PrincipalId humanos
  reales, pendientes de verificar. No crea el usuario humano ni registra MFA.
  No usar el perfil de herramientas para desplegar esta plantilla.
- `executor.closed.policy.json` y `executor.trust.json`: nuevo rol exclusivo
  `arn:aws:iam::365470560685:role/muserelay-aws-bridge-stage-tools-cfn-execution`.
  La política es **inline**, no managed: su tamaño supera 6144 caracteres.
- `runtime.{inbound,enqueue,sender}.closed.boundary.json`: tres boundaries
  customer-managed distintas, a instalar solo por el administrador, cada una sobre
  su rol de runtime. Son techos; no conceden permisos por sí mismas.
- `ingress.stack-policy.proposed.json`: protege los doce recursos de ingreso
  existentes contra Modify/Replace/Delete durante UPDATE. Su aplicación requiere
  autorización independiente; no se ha aplicado. No protege contra DeleteStack,
  por lo que esa acción también queda fuera de las políticas de herramientas.
- `review-manifest.json`: controles y aprobaciones exigidos; no es autorización.
- `foundation.update.proposed.yaml`: plantilla Foundation completa candidata,
  basada en SAM d60476..., para otro conjunto independiente después de cerrar
  la corrección SAM. Propone cuatro Add (ejecutor y tres managed boundaries) y
  tres Modify (solo PermissionsBoundary de los roles runtime). Ejecutores previos,
  recuperación, confianzas, políticas y GrantSocialSend se conservan. Sin permisos
  SSO, asignaciones, consumidores ni recursos de mensajería nuevos. No cargarla
  encima del conjunto SAM pendiente ni ejecutarla por esta autorización.

Todos los JSON son candidatos completos, con fecha de expiración propuesta
**2026-10-27T00:00:00Z**. Renovar esa fecha y volver a revisar los hashes si ya no
sirve cuando se aprueben. Ninguna renovación será automática. Sesiones AWS de una
hora, sesión interactiva SSO de ocho horas y ventana de escritura máxima de 24h.
DateGreaterThanEquals bloquea nuevas peticiones incluso con sesiones aún válidas.
No equivale a borrar automáticamente la asignación ni detener operaciones ya
iniciadas; no comenzar una actualización cerca de la caducidad. Reservar al menos
dos horas para completar/recuperar CloudFormation antes del corte.

## Recursos y capacidades

Cuenta 365470560685, eu-west-1. Mismos cinco roles runtime/foundation solo lectura;
nuevo ejecutor solo lectura y PassRole a CloudFormation. **Esta única excepción
adicional de PassRole necesita aprobación expresa**: no es modificación IAM ni
asunción directa del ejecutor, pero permite delegarle operaciones. El ejecutor
solo pasa el rol inbound existente a Lambda. No puede crear/modificar/adjuntar
políticas IAM, cambiar confianzas, quitar boundaries, usar KMS admin/recovery ni
crear usuarios. No se concede PutRolePolicy ni siquiera limitado por nombre: su
contenido podría conceder privilegios arbitrarios.

Herramientas: escritura solo mediante conjuntos de la pila runtime y S3
`tooling-review/20261026/*`. PutObject exige AES256 e If-None-Match=*; no puede
sobrescribir la release existente, borrar objetos, cambiar ACL/bucket policy,
desactivar cifrado o lifecycle. Los bytes y hashes revisados deben comprobarse
antes de subir. El broker impondrá un máximo de 2 MiB por objeto para esta entrega;
IAM no establece por sí mismo ese límite. No subir datos ni secretos a este prefijo.

Ejecutor: aprovisionamiento de Lambda inbound, tabla state, secreto signing vacío
y logs propios. Metadatos de SNS/SQS/S3/KMS existentes; **no** permisos para editar
políticas de esos recursos. SNS y las tres SQS ya funcionan y no necesitan más
escritura para la recepción inicial. No se concede SetTopicAttributes,
SetQueueAttributes, Publish, Subscribe ni PurgeQueue. Las operaciones de logs
se limitan al grupo inbound; no logs de otros proyectos ni CloudWatch:PutMetricAlarm
innecesario para esta entrega. El runtime escribe sus propios logs mediante su rol.
La plantilla original puede requerir lecturas adicionales: validar sin ampliar
privilegios automáticamente si aparece AccessDenied.

Herramientas sin escrituras directas Lambda/DynamoDB/Secrets/SNS/SQS: CloudFormation
las delega al ejecutor reducido. El rol ejecutor conserva Create/Update/Delete de
los recursos inbound imprescindibles para rollback; no puede borrar el ingreso.
Secrets Manager permite crear el secreto vacío; su carga segura se aprobará en
otra fase. No GetSecretValue ni datos de DynamoDB para las herramientas. No Invoke
Lambda, ReceiveMessage, permisos Social ni Bedrock para las herramientas.
Los permisos para outbound/SecretValue/canales/agentes se diseñarán después; no
se presentan como operativos ahora. La propuesta no habilita la demo completa.

## Escalada: controles imprescindibles y límites de IAM

1. **CloudFormation:** el ejecutor actual tiene permisos de administración KMS y
   de políticas SNS/SQS/S3. Reutilizarlo permitiría delegación o exposición de datos,
   aunque la pila tenga un nombre restringido. La propuesta usa un nuevo ejecutor
   y conserva al anterior como KMS administrator sin cambiar la clave. La nueva
   selección del service role del stack debe revisarse: CloudFormation mantiene
   ese rol asociado para futuras operaciones. No ejecutar con el anterior.
2. **IAM:** PassRole únicamente al ejecutor candidato y únicamente a CloudFormation;
   el ejecutor pasa solo inbound a Lambda. Confianzas y boundaries solo por admin.
   No hay privilegios de autoedición. Revisar managed policies, boundaries, resource
   policies y SCP efectivos antes de asignar; el escaneo local no los conoce.
3. **Lambda:** cambiar código equivale a obtener las capacidades de su rol, aunque
   se deniegue Invoke al desarrollador. Ni flags de entorno ni Enabled=false son
   una frontera IAM. Los tres boundaries iniciales deniegan Receive/Delete/Send
   SQS, GetSecretValue, Social y Bedrock, incluso si alguien habilitase un mapping.
   Así no puede leer los mensajes existentes. Un mapping indebidamente habilitado
   aún podría generar errores/coste; se prohíbe también mediante el runner.
4. **Aprobaciones:** IAM no valida hashes de plantillas, código o parámetros, no
   garantiza que un mapping se cree Enabled=false, ni que una policy inline sea
   segura por su nombre. La política deploy permite ejecutar cualquier conjunto
   autorizado sobre ese stack; no vincula por sí misma el hash o service role de
   un conjunto anterior. Necesita un runner protegido que verifique esos datos.
5. **Datos una vez activados:** al autorizar recepción, código Lambda modificable
   con un rol consumidor permite leer/exfiltrar mensajes, y al autorizar Social
   permite enviar fuera de los flags del código. IAM limita el teléfono, no todos
   los destinatarios de WhatsApp. Antes de quitar los bloqueos, retirar escritura
   de código, artefactos y ejecución de conjuntos. Congelar el hash revisado; solo
   entonces habilitar una recepción controlada. Cualquier cambio exige pausar el
   consumidor, restaurar bloqueos y reabrir una ventana de despliegue autorizada.
   No prometer aislamiento absoluto frente al administrador/root o recursos que
   este vuelva a configurar. No se concede AWS administrado para terceros.

## Usuario Linux, caché y runner

Reutilizar la propuesta `../audit-readonly/prepare-linux-root.sh`: cuenta aislada
`muserelay-bridge-audit`, home /var/lib/muserelay-bridge-audit, 0700, sin sudo,
SSH ni grupos del Core. No se ha podido crear: sudo exige contraseña; root debe
ejecutar el script revisado. No se necesita otra máquina ni recargar servicios.

**No dar al agente una shell arbitraria bajo la cuenta que guarda la sesión de
escritura.** Root debe ser propietario del ejecutable del runner y del manifiesto
de aprobación (fuera de repositorio/directorios modificables por muserelaycom).
El titular inicia SSO dentro de esa cuenta con MFA. El agente solo invoca acciones
tipadas mediante un broker o sudo a ese único ejecutable, sin shell, argumentos
CLI libres, --debug, perfiles/endpoints/env alternativos ni lectura de archivos
arbitrarios. No conceder sudo -u cuenta bash/python/aws genéricos.

**Runner todavía no implementado.** Es un requisito de esta propuesta, no un
control ya disponible. Antes de habilitar escritura necesita pruebas contra
symlinks, path traversal, cambios de archivos tras revisión, sustitución de
manifiesto/ZIP, inyección de argumentos y exposición de tokens. Debe cargar bytes
y verificar hash sobre esos mismos bytes; no verificar una ruta y releer otra.
No configurar credenciales de escritura mientras falte este control.

Operaciones previstas del runner: inspect, upload_reviewed_artifact,
create_reviewed_change_set y execute_approved_change_set. Las dos últimas exigen
template hash, parámetros, account/region/stack, service role, estado y changes
exactos. Debe volver a leer el conjunto inmediatamente antes de ejecutarlo y
registrar ClientRequestToken. No nested stacks, imports, custom resources,
macros distintos del SAM oficial, transform Includes, URLs/artefactos libres,
recursos IAM ni cambios de los doce recursos de ingreso. Ningún acceso al Core.
El nombre de conjunto no sustituye comprobar StackId y service role efectivos.

## Instalación futura por etapas — ninguna autorizada por este documento

1. Cerrar SAM actual mediante sus lecturas exactas; su autorización es independiente.
2. Aprobar política/ventana/runner. Preparar y probar runner sin credenciales.
3. Administrador: preparar y revisar un nuevo UPDATE IAM Foundation separado para
   crear el ejecutor reducido y tres boundaries y asociarlas a los roles. Mantener
   GrantSocialSend=false, Retain y confianza existente. No sustituir la corrección
   SAM pendiente ni tocar IAM desde el perfil de herramientas. La plantilla
   candidata completa se entrega en `foundation.update.proposed.yaml`; revisar
   sus cuatro Add y tres Modify sin sustituciones. El cierre de esta revisión
   de acceso no autoriza dicho UPDATE.
4. Root: crear UID aislado, instalar CLI v2 oficial verificada y runner, limitar
   sudo al runner protegido y comprobar que muserelaycom/PHP no leen cachés.
   Comprobar `sudo -l`: los permisos de root generales requieren contraseña; no
   guardar esa contraseña ni ampliar reglas de servicios compartidos.
5. Administrador AWS: crear identidad humana dedicada o asignación dedicada al
   operador autorizado, MFA y permission set personalizado. Sin Admin ni perfiles
   de Bedrock. Configurar Start URL/región SSO reales, no inventarlos. Aprobar las
   asignaciones antes de aplicarlas. No crear usuarios IAM ni access keys.
6. Dentro del UID aislado, umask 077; configure sso para el permiso de preparación;
   login con --use-device-code --no-browser, completado por el titular sin pegar
   códigos o tokens en chat. Verificar STS cuenta/rol, expiración y políticas reales.
7. Autorizar auditoría y aplicar stack policy protectora mediante administrador.
   Verificar boundaries y que no haya mappings habilitados ni GrantSocialSend.
8. Autorizar ventana de preparación: subir solo bytes revisados y crear conjunto
   cerrado con nuevo ejecutor. Revisar Original, parámetros, cinco Add esperados y
   cero cambios de ingreso; cambiar de rol puede producir diferencias adicionales
   de metadatos que requieren revisión. No ejecutar automáticamente.
9. Solo con aprobación del plan cerrado habilitar el permission set deploy por la
   ventana aprobada; runner ejecuta exactamente el conjunto revisado. Obtener
   UPDATE_COMPLETE y comprobar políticas, controles deshabilitados y contadores
   sin recibir mensajes. No rollback destructivo/force sin revisión.
10. Retirar deploy/code writes. Autorizar separadamente inicializar firma/canal y
    Core/staging, luego boundary de recepción y una prueba real limitada. No crear
    esa autorización por inferencia. Envío humano, Social y Bedrock después.

## Auditoría y pruebas de permisos

CloudTrail management event history ya registra eventos de gestión durante 90 días;
no certificamos su lectura real ni un trail duradero. Un administrador independiente
debe comprobar trail/selectores/destino y exportar evidencia antes de retirar acceso.
PassRole no es un evento API independiente: revisar el request de CreateChangeSet/
CreateFunction que contiene el rol delegado. La identidad de herramientas no puede
parar CloudTrail ni borrar auditoría. Sin LookupEvents de cuenta completa para el
agente: puede revelar datos ajenos. Auditor realiza consultas por identidad/ventana.

Event history no incluye data events como PutObject, Invoke o lecturas de mensajes.
Para auditoría completa aprobar selectores acotados de data events para prefijo de
artefactos, funciones y colas/tablas del Bridge compatibles con CloudTrail; revisar
costes antes de activarlos. Log del runner con identidad, aprobación, hash, operación,
request ID y resultado, sin cuerpos, números, firmas ni tokens; enviarlo a destino
protegido por administrador, no solo un archivo editable por el agente.

Antes de configurar: validar políticas con Access Analyzer (solo tras autorización)
y simulador IAM administrado, contra ARN de roles reales. Probar Allowed para
metadatos/prepare, Denied para IAM admin, PassRole de otro rol/servicio, otra región,
otros recursos, Execute IAM Foundation, ReceiveMessage, PurgeQueue, Invoke, Social,
Bedrock, GetSecretValue, escritura/borrado/overwrite de release y después de expiry.
No realizar llamadas reales prohibidas para comprobar que fallan. Las simulaciones
no sustituyen pruebas de trust, boundaries y service policies. Primeras escrituras
reales solo sobre artefacto y change set autorizados, nunca clientes ni mensajes.

## Revocación, persistencia y costes

Retirar asignaciones prepare/deploy, reprovisionar permisos y aplicar bloqueo
efectivo de sesiones activas según procedimiento Identity Center. Terminar portal/
logout no basta para invalidar todas las sesiones IAM ya emitidas: mantener Deny
o control SCP administrado hasta caducidad. No editar AWSReservedSSO directamente.
Cerrar runner y limpiar cachés solo del UID aislado. Mantener auditoría. Verificar
la revocación desde una sesión anterior. Revisar políticas/grants pendientes;
no borrar claves, colas, mensajes o runtime para retirar acceso al arquitecto.

La expiración de herramientas/ejecutor no apaga un runtime Lambda ya desplegado.
Mantener límites del runtime y permisos autorizados por fase; no dejar un boundary
cerrado si después se pretende recepción permanente. Tras concurso, conservar
servicio y releases de rollback, retirar solo escritura y asignaciones temporales.
No revertir al viejo ejecutor amplio sin autorización. Cada mantenimiento posterior
requiere otra ventana revisada y política con nueva caducidad.

Estimación: 4–8 horas de preparación/validación del runner y fundamentos si no hay
bloqueos, más intervención root/SSO y aceptación IAM de AWS. No prometer entrega en
una hora. Preparar read-only puede adelantarse. Esta mejora no debe bloquear la
ruta manual existente si consume el tiempo restante del concurso.
Identity Center no tiene coste adicional; roles/boundaries no requieren servidores.
S3, Lambda, DynamoDB, Secrets, logs y data events sí pueden facturar. Antes del
runtime presentar estimación por recursos reales y presupuesto; esta política no
es un límite monetario. No hay nuevo despliegue ni gasto de pruebas realizado.

Fuentes oficiales:
- https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/using-iam-servicerole.html
- https://docs.aws.amazon.com/IAM/latest/UserGuide/id_roles_use_passrole.html
- https://docs.aws.amazon.com/IAM/latest/UserGuide/access_policies_boundaries.html
- https://docs.aws.amazon.com/AmazonS3/latest/userguide/conditional-writes-enforce.html
- https://docs.aws.amazon.com/awscloudtrail/latest/userguide/how-cloudtrail-works.html
- https://docs.aws.amazon.com/singlesignon/latest/userguide/revoke-user-permissions.html
