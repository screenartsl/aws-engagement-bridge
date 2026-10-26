# Guía de consola: actualización IAM del runtime (25/10/2026, Europe/Paris)

Estado: preparación local autorizada para publicar en el repositorio **privado**. No se ha creado ni ejecutado un conjunto de cambios AWS desde este servidor. La producción sigue con el conector 0.4.0 desactivado; la versión preparada es 0.5.1. No se han autorizado organizaciones, creado canales, leído SQS ni enviado mensajes durante esta entrega.

## Versión que debe usarse

- Repositorio: `screenartsl/aws-engagement-bridge` (privado).
- Commit del código revisado: `401728d1a66f577481936e07f2630a559144efb9`.
- **Archivo correcto para el siguiente paso:** `deploy/runtime-iam/template.yaml`.
- [YAML fijado al commit](https://github.com/screenartsl/aws-engagement-bridge/blob/401728d1a66f577481936e07f2630a559144efb9/deploy/runtime-iam/template.yaml).
- SHA256 del YAML: `b2a87ec5d7d205b69250596103427fb55b8896b7f5997507b28b489b18feac3a`.
- Nombre propuesto del conjunto: `receive-runtime-iam-review-b2a87ec5d7d2` (no existe todavía por esta preparación).

En GitHub, acceder con una cuenta autorizada, abrir ese enlace y usar **Download raw file** para guardar el YAML como archivo de texto. Comprobar que no se descargó una página HTML de inicio de sesión. No pegar tokens en el chat ni usar una URL autenticada con credenciales dentro de CloudFormation.

**No usar** `deploy/iam-foundation/template.yaml`: es la versión anterior, sin los roles de runtime. Tampoco usar aún `template.yaml` de la raíz ni volver a desplegar `deploy/sns-bootstrap/template.yaml`. No crear otra pila IAM ni duplicar SNS/WABA/teléfono.

## Alcance exacto esperado

Actualizar la pila existente `muserelay-aws-bridge-iam-foundation`, cuenta `365470560685`, región `eu-west-1`, desde la sesión IAM Identity Center AdministratorAccess.

| Recurso lógico | Acción esperada | Resultado |
| --- | --- | --- |
| `InboundExecutionRole` | Add | `muserelay-aws-bridge-stage-inbound-runtime` |
| `EnqueueExecutionRole` | Add | `muserelay-aws-bridge-stage-enqueue-runtime` |
| `SenderExecutionRole` | Add | `muserelay-aws-bridge-stage-sender-runtime` |
| `StageCloudFormationRole` | Modify, Replacement=False | Añadir política inline `bridge-runtime-receive-provisioning` |
| `KmsRecoveryRole` | Sin cambios | Conservar nombre, confianza y permisos |

La comparación local con la fundación anterior confirma que la confianza del ejecutor, sus políticas anteriores y las denegaciones explícitas de `kms:DisableKey` / `kms:ScheduleKeyDeletion` permanecen iguales. Se conservan ambos ARN existentes y Retain. Los tres roles nuevos también tienen Retain. La autorización efectiva y las diferencias frente a la pila **real** deben contrastarse en el conjunto de cambios, no se deducen de esta comparación local.

Si CloudFormation muestra `(Truncated-Signature)` en Policies, consultar la [comparación íntegra, hashes y extracción de plantillas sin ejecutar](IAM-POLICIES-COMPARISON-2026-10-25.md). No dar por verificada la plantilla real del conjunto solo por su resumen de recursos.

Parámetro único: **`GrantSocialSend=false`**. Reconocer `CAPABILITY_NAMED_IAM` porque se crean roles con nombres explícitos. No adjuntar AdministratorAccess a los roles nuevos.

Los roles de runtime confían en `lambda.amazonaws.com`; Lambda los asume automáticamente. No se crean usuarios IAM ni claves permanentes. Tampoco autorizan al servidor web a asumirlos ni proporcionan credenciales a Laravel. Bedrock conserva su autenticación actual, y la futura identidad de inferencia queda fuera de esta actualización.

Resumen de permisos; el inventario completo está en `deploy/runtime-iam/policies.review.json`:

- Recepción: ReceiveMessage/DeleteMessage/GetQueueAttributes sobre la cola de entrada; CRUD de ítems de la tabla de estado; lectura del secreto de firma hacia Core; escritura en sus propios logs.
- Encolador futuro: PutItem sobre estado, lectura de otro secreto de firma de salida, SendMessage a la cola de salida y sus propios logs.
- Emisor futuro: consumo de su cola de salida, estado, secreto hacia Core y logs. Con `GrantSocialSend=false` **no obtiene** `social-messaging:SendWhatsAppMessage`.
- Ejecutor CloudFormation: aprovisionamiento de recepción y PassRole **solo** del rol inbound hacia Lambda. No crea/modifica roles IAM, no lee valores de secretos, no invoca Bedrock ni envía WhatsApp. Los permisos de salida requerirán otra revisión.

Esta pila solo administra IAM: no crea Lambda, tabla, secreto, colas, suscripciones ni consumidores. Los permisos de consumo concedidos al rol no consumen mensajes por sí solos.

## Procedimiento de consola: preparar y revisar, sin ejecutar

1. Verificar la cuenta `365470560685`, región Irlanda y sesión SSO AdministratorAccess; no utilizar root.
2. CloudFormation → Pilas → seleccionar **`muserelay-aws-bridge-iam-foundation`**.
3. Acciones de pila → **Crear conjunto de cambios** → sustituir plantilla → cargar el YAML fijado arriba.
4. Introducir el nombre propuesto y mantener **GrantSocialSend=false**.
5. Revisar los permisos de CloudFormation. Para esta pila IAM se usa la sesión administrativa actual **si no tiene ya un rol de servicio asociado**. No seleccionar `muserelay-aws-bridge-stage-cfn-execution`: es el ejecutor limitado de la pila de mensajería y no puede gestionar esta actualización IAM. Si aparece un rol de servicio ya asociado a IAM Foundation, detenerse y revisar su ARN y permisos; no sustituirlo a ciegas. AWS no permite simplemente retirar un rol de servicio previamente asociado.
6. Conservar las opciones/etiquetas existentes y reconocer la creación de recursos IAM con nombres explícitos.
7. Elegir la acción que **crea el conjunto para revisión**. No pulsar una acción que ejecute directamente la actualización.
8. Esperar CREATE_COMPLETE / AVAILABLE. Comparar los cambios: exactamente tres Add y un Modify sin sustitución. Revisar el contenido de la plantilla y la diferencia de Policies. No debe haber Remove, cambios de confianza, modificación de recuperación ni eliminación de denegaciones KMS.
9. Compartir para revisión solo el nombre/ARN del conjunto, estado, cuatro recursos y diferencias de políticas; sin credenciales ni valores de secretos. **No ejecutar** hasta recibir autorización expresa tras revisar este conjunto concreto.

Si AWS muestra diferencias adicionales, nombres ya existentes fuera de la pila, AccessDenied o una capacidad no prevista, detener la ejecución y revisar el motivo concreto. No ampliar permisos manualmente.

## Recursos, costes y reversión

La actualización IAM no tiene cargo recurrente por mantener estos roles y no añade servicios de procesamiento. La carga de plantillas puede usar almacenamiento S3. El presupuesto previo de USD 1 incremental para una pequeña prueba de recepción de cuatro días y USD 5 de reserva operativa corresponde a la **fase posterior**, no a un precio fijo ni a un límite de gasto. No incluye tráfico imprevisto, impuestos, servicios existentes, inferencia ni envíos. Ver supuestos y fuentes en `deploy/runtime-iam/README.md`.

Antes de ejecutar IAM, descargar/guardar privadamente la plantilla y parámetros reales actuales de la pila. Un conjunto aún no ejecutado se puede descartar sin cambiar los roles. Si una actualización ejecutada falla, revisar primero eventos y estado de rollback. **Retain no es revocación**: pueden quedar roles retenidos aunque se retire su declaración o se elimine la pila. Antes de usar una plantilla anterior como reversión hay que inventariar los roles reales y sus referencias. Revocar/quitar políticas o eliminar roles requiere un procedimiento y autorización propios; no borrar la pila ni el rol de recuperación como atajo.

## Fase siguiente, todavía no ejecutable con este prompt

Tras una actualización IAM autorizada y verificada: revisar un UPDATE de `muserelay-aws-bridge-stage` usando el runtime de la raíz y el ejecutor limitado. Añadiría cinco recursos de recepción tras SAM: DynamoDB, Lambda inbound, logs, secreto inicialmente vacío y event source mapping deshabilitado. Se preservan los doce recursos actuales de ingreso.

Se necesita subir antes el ZIP fijado a un commit al bucket privado, y revisar el endpoint técnico, la firma HMAC y los IDs reales de organización/workspace/canal. Los JSON preparados conservan un marcador de artefacto pendiente: **no enviarlos tal cual**. La URL actual del runtime admite el hostname técnico temporal de staging; el paso al endpoint del MuseRelay actual requiere revisar esa restricción, no apuntar una base de test a producción. La bandeja final es la nativa de MuseRelay.

Mantener `DeployOutbound=false`, `ProcessingEnabled=false`, `ReceiveEnabled=false`, `SendEnabled=false`, `LiveSend=false`, binding deshabilitado y destinatarios vacíos. La instalación del módulo para la organización demo y el primer consumo real se autorizan y verifican por separado. La organización demo la crea el propietario; no asumir que existe. Tampoco dar por resuelta la ocupación de Abdu: se vuelve a comprobar inmediatamente antes de cualquier activación en MuseRelay.

Hay dos eventos previamente comunicados en SQS: activar un mapping podría consumirlos antes de uno nuevo. No leer, purgar, mover ni eliminar ese contenido para preparar una demo sin autorización específica sobre ese alcance.

## Fuentes oficiales comprobadas

- [Crear un conjunto de cambios desde la consola](https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/using-cfn-updating-stacks-changesets-create.html).
- [Rol de servicio de CloudFormation y límites de su sustitución](https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/using-iam-servicerole.html).
- [Roles de ejecución Lambda y asunción automática](https://docs.aws.amazon.com/lambda/latest/dg/lambda-intro-execution-role.html).

## Prompt para continuar con GPT

```text
Ayúdame paso a paso, mediante la consola AWS y sin CloudShell, a PREPARAR Y REVISAR un conjunto de cambios UPDATE. No lo ejecutaremos todavía.

Cuenta Screen Art: 365470560685. Región: eu-west-1, Irlanda. Acceso: IAM Identity Center AdministratorAccess con MFA, no root.
Pila existente: muserelay-aws-bridge-iam-foundation. Ya está desplegada; no crear otra pila.

Repositorio PRIVADO: screenartsl/aws-engagement-bridge.
Commit revisado: 401728d1a66f577481936e07f2630a559144efb9.
YAML exacto:
https://github.com/screenartsl/aws-engagement-bridge/blob/401728d1a66f577481936e07f2630a559144efb9/deploy/runtime-iam/template.yaml
SHA256: b2a87ec5d7d205b69250596103427fb55b8896b7f5997507b28b489b18feac3a.
Si no puedes acceder al repositorio privado, pídeme el YAML descargado desde mi GitHub autenticado; no inventes su contenido ni me pidas tokens.

Nombre sugerido: receive-runtime-iam-review-b2a87ec5d7d2.
Parámetro: GrantSocialSend=false. Capacidad: CAPABILITY_NAMED_IAM.

Cambios esperados:
- Add InboundExecutionRole, EnqueueExecutionRole y SenderExecutionRole.
- Modify StageCloudFormationRole, Replacement=False, añadiendo únicamente la política inline bridge-runtime-receive-provisioning.
- Ningún cambio en KmsRecoveryRole, en las confianzas existentes ni en las denegaciones kms:DisableKey y kms:ScheduleKeyDeletion. Conservar las políticas anteriores y Retain.

Roles existentes:
arn:aws:iam::365470560685:role/muserelay-aws-bridge-stage-cfn-execution
arn:aws:iam::365470560685:role/muserelay-aws-bridge-kms-recovery

No uses el ejecutor limitado de mensajería como rol de servicio de esta actualización IAM. Si IAM Foundation no tiene un rol de servicio asociado, utiliza los permisos de mi sesión administrativa. Si ya tiene uno asociado, detente y pide su ARN para revisarlo; no lo cambies sin revisión.

Guíame desde seleccionar la pila → Acciones de pila → Crear conjunto de cambios → Sustituir plantilla → Cargar YAML. Descargaremos el archivo real, no una página HTML. Guardaremos la plantilla/parámetros actuales antes de cualquier ejecución futura.

Cuando llegue a CREATE_COMPLETE/AVAILABLE, ayúdame a revisar los tres Add y el único Modify, incluyendo las diferencias de políticas. Si aparecen otras modificaciones, sustituciones o eliminaciones, detente. Termina con un resumen para que el arquitecto revise el conjunto concreto y yo autorice su ejecución por separado. No me indiques pulsar Ejecutar todavía.

Esta fase no despliega Lambda/SNS/SQS/KMS, no crea usuarios ni claves permanentes, no toca MuseRelay/Bedrock, no consume SQS y no envía WhatsApp. El runtime, la instalación en MuseRelay y la primera recepción tendrán revisiones y autorizaciones separadas. No utilices la plantilla antigua deploy/iam-foundation/template.yaml ni despliegues ahora el runtime de la raíz.
```
