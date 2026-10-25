# CloudFormation cifrado en dos etapas — preparado, NO desplegado

Revisión: 25-10-2026. **GO para revisión offline; NO-GO para ejecutar sin autorización y preflight real.** Cuenta prevista Screen Art S.L. `365470560685`, región `eu-west-1`. No se ha contactado la cuenta mediante API, creado recursos, cambiado IAM, finalizado el alta Social ni enviado mensajes. La lista SNS vacía y el progreso Meta son datos comunicados por el usuario, no inventario independiente.

Esta guía sustituye las instrucciones AWS de los informes históricos de fase 4. La instalación/retirada de staging MuseRelay continúa descrita en [PHASE-4-RUNBOOK.md](PHASE-4-RUNBOOK.md); ninguna de esas acciones queda autorizada por preparar esta plantilla. El Core instalado permanece desactivado, sin autorizaciones ni canales AWS según la última revisión; no se ha vuelto a instalar ni modificado durante esta tarea.

## 1. Dos etapas, una sola pila

| Etapa | Archivo | Acción futura y resultado |
| --- | --- | --- |
| 1. Ingreso cifrado | [deploy/sns-bootstrap/template.yaml](../deploy/sns-bootstrap/template.yaml) | Crear **`muserelay-aws-bridge-stage`**. Necesita dos roles IAM estables existentes: ejecución y recuperación, según la revisión SSO/KMS. Devuelve `InboundTopicArn`; no exige todavía IDs del teléfono, secreto ni Core staging. |
| 2. Runtime inicialmente apagado | [template.yaml](../template.yaml) | **Actualizar esa misma pila**, con identificadores AWS/Meta definitivos y ZIP Lambda privado. Añade el procesamiento sin activarlo. |

El bootstrap se deriva del completo con `python3 scripts/cloudformation/generate-bootstrap.py`. Conserva logical IDs, propiedades y nombres de los doce recursos iniciales. La revisión automática detecta divergencias. No crear otra pila con el mismo nombre de tema, ni borrar/recrear el ingreso. El change set deberá mostrar adiciones del runtime y **cero reemplazos** del tema, clave, bucket, suscripción o colas iniciales. Si aparecen reemplazos o eliminaciones inesperados, detenerse y revisar.

La plantilla completa también admite comenzar con `DeployProcessing=false`; se entrega el archivo inicial sin transformación SAM para facilitar la primera carga desde consola. Este es el mecanismo concreto que resuelve la dependencia circular del alta: primero obtener SNS, después obtener/verificar los IDs de WhatsApp, por último incorporar los consumidores.

## 2. Recursos exactos de la primera etapa

| Logical ID | Tipo y nombre previsto |
| --- | --- |
| `SnsKey` | Una clave KMS simétrica dedicada SNS; UUID/ARN asignados por AWS. Retenida al borrar la pila. |
| `SnsKeyAlias` | `alias/muserelay-aws-bridge-stage-sns`. |
| `InboundTopic` | SNS **Standard**, `muserelay-aws-bridge-stage-inbound`, máximo 256 KiB, SSE-KMS con `SnsKey`. |
| `TopicPolicy` | Permite exclusivamente al servicio Social publicar en ese tema desde la cuenta y región previstas. |
| `InboundQueue` | SQS Standard `muserelay-aws-bridge-stage-inbound-queue`, cifrado SSE-SQS, retención 4 días, visibilidad 180 s. |
| `InboundDlq` | SQS `muserelay-aws-bridge-stage-inbound-dlq`, retención 14 días; después de 5 recepciones fallidas de la cola principal. |
| `InboundQueuePolicy` | Solo SNS desde el tema/cuenta indicados puede entregar a la cola mediante esta concesión de recurso. |
| `DeliveryDlq` | SQS `muserelay-aws-bridge-stage-sns-delivery-dlq`, 14 días: fallos de entrega de la suscripción SNS. |
| `DeliveryDlqPolicy` | Solo SNS desde ese tema/cuenta puede entregar a esta DLQ. |
| `Subscription` | SNS → SQS, `RawMessageDelivery=false`, sin filtros y con DLQ de entrega. |
| `ArtifactBucket` | S3 privado `muserelay-aws-bridge-stage-artifacts-365470560685-eu-west-1`, SSE-S3/AES256, acceso público bloqueado, propietario único, caducidad de objetos 30 días. Retenido. |
| `ArtifactBucketPolicy` | Deniega acceso sin TLS a identidades no exentas de servicio AWS; no concede acceso público. |

Total: **12 recursos**. No crea roles IAM, Lambda, API, secretos, Bedrock, organizaciones, números ni WABA. No registra el teléfono y no consume la cola. Si se autoriza después un destino Social, los eventos pueden acumularse y caducar: no habilitar tráfico de prueba hasta tener preparado su procesamiento. La DLQ de SNS y la de procesamiento resuelven errores distintos.

Etiquetas donde el tipo las admite: `Company=ScreenArt`, `Project=MuseRelay`, `Component=aws-engagement-bridge`, `Environment=staging`, `Purpose=hackathon`. Añadirlas también a la pila. No incluyen números telefónicos, nombres de personas ni secretos.

## 3. Publicación, cifrado y permisos

La declaración de publicación del tema es:

```json
{
  "Effect": "Allow",
  "Principal": {"Service": "social-messaging.amazonaws.com"},
  "Action": "sns:Publish",
  "Resource": "arn:aws:sns:eu-west-1:365470560685:muserelay-aws-bridge-stage-inbound",
  "Condition": {
    "StringEquals": {"aws:SourceAccount": "365470560685"},
    "ArnLike": {"aws:SourceArn": "arn:aws:social-messaging:eu-west-1:365470560685:*"}
  }
}
```

La política de la clave autoriza al mismo servicio, con esas condiciones de origen, a `kms:GenerateDataKey*` y `kms:Decrypt`. `Resource:"*"` en esa **política de clave** significa la clave concreta; no es una autorización global IAM. El patrón Social sigue la recomendación oficial inicial; no sustituirlo por el ID numérico Meta ni inventar un ARN AWS WABA. [Compatibilidad Standard y políticas Social/SNS/KMS](https://docs.aws.amazon.com/social-messaging/latest/userguide/managing-event-destinations-add.html).

**Revisión SSO/KMS posterior:** los dos parámetros ahora exigen los roles estables de [AWS-KMS-ADMINISTRATION.md](AWS-KMS-ADMINISTRATION.md). `KmsAdministratorArn` será `arn:aws:iam::365470560685:role/muserelay-aws-bridge-stage-cfn-execution` y hay que seleccionar ese mismo rol de servicio en Permisos de CloudFormation. `KmsRecoveryAdministratorArn` será `arn:aws:iam::365470560685:role/muserelay-aws-bridge-kms-recovery`. Ambos son previstos, no creados/verificados. La nueva [preparación IAM separada](../deploy/iam-foundation/README.md) define dos roles retenidos; crearla necesita autorización. No usar directamente el rol `AWSReservedSSO_…` como único administrador ni continuar con el YAML anterior.

La política KMS incluye administración estable, recuperación por el segundo rol y delegación de cuenta limitada a `DescribeKey`/`GetKeyPolicy`/`PutKeyPolicy`, además de Social. No hay `kms:*` ni se desactiva la comprobación anti-bloqueo. El rol de ejecución debe conservar acceso a `PutKeyPolicy`, y los administradores deben sobrevivir a la pila de transporte. La política de ejecución preparada cubre **solo el ingreso de etapa 1**; la etapa 2 necesita ampliación delimitada y autorizada.

Los roles Lambda no pueden administrar IAM/KMS, registrar WABA ni invocar Bedrock. Solo `SenderExecutionRole` recibe `social-messaging:SendWhatsAppMessage`, sobre **un único ARN de teléfono**. Las otras concesiones se restringen a su tabla, cola, secreto y grupo de logs. El secreto de firmas usa por defecto `aws/secretsmanager`, SQS usa SSE-SQS y S3 usa SSE-S3: son capas separadas de la clave SNS. No añadir permisos KMS de SNS a los consumidores por reflejo.

Una clave propia cuesta por **clave**, no por teléfono/WABA; puede proteger varios temas compatibles dentro del ámbito regional y de políticas elegido. Esto no aporta aislamiento por organización por sí mismo. La demo usa una clave dedicada de staging; producción tendría su propia separación y BYO AWS es un diseño futuro. No se configura rotación automática para la prueba corta; revisar esa decisión si se conserva comercialmente. [Precio KMS](https://aws.amazon.com/kms/pricing/).

## 4. Preflight pendiente: lectura y permisos de despliegue

Antes de aprobar la creación, una identidad autorizada **no-root** debe comprobar STS (`Account=365470560685`), región, permisos efectivos, SCP/boundaries y recursos existentes. No hay perfil AWS utilizable en esta sesión. Desde consola se pueden obtener los mismos metadatos, sin compartir credenciales.

Lectura mínima según recurso: `sns:ListTopics`, `GetTopicAttributes`, `ListSubscriptionsByTopic`, `GetSubscriptionAttributes`; `sqs:ListQueues`, `GetQueueAttributes`; `cloudformation:DescribeStacks`, `ListStackResources`; `kms:ListAliases`, `DescribeKey`, `GetKeyPolicy`; S3 `HeadBucket`/`GetBucketLocation`, cifrado, bloqueo público y metadatos. Después del alta, leer el WABA y teléfono **vinculados AWS** de demostración. La primera fase no requiere leer valores de secretos ni cuerpos de mensajes.

Familias de permisos que habrá que revisar para el **ejecutor de despliegue**, sin concederlas ahora:

| Fase | Operaciones requeridas y límites |
| --- | --- |
| CloudFormation | Crear/describir change set, ejecutar solo el aprobado, leer eventos/recursos y actualizar/borrar únicamente esta pila. La consola también puede necesitar listar recursos. |
| Ingreso | SNS crear/configurar tema/política/suscripción y etiquetas; SQS crear/configurar las tres colas/políticas/etiquetas. ARN/nombres del inventario anterior. |
| Clave | `kms:CreateKey` y administración explícita/alias de la clave dedicada; no usar ni cambiar claves ajenas. Las operaciones de creación/listado que no admiten ARN deben revisarse por separado, no convertirlas en un permiso global de administración. |
| Artefactos | Crear/configurar el bucket privado y política; `s3:PutObject` al prefijo `releases/` solo cuando se autorice la carga. CloudFormation/Lambda deben poder leer ese objeto. |
| Runtime | Crear/actualizar las tres funciones, dos event mappings, API/ruta/integración/permiso, tres grupos de logs, tabla DynamoDB, dos colas y metadatos de secreto. Crear tres roles con sus políticas inline y `iam:PassRole` **solo a Lambda** sobre esos roles. |
| Firmas | `secretsmanager:PutSecretValue` sobre el nuevo secreto de demo, por operación privada separada; el despliegue no contiene el valor. |

Este inventario no acredita los permisos actuales del usuario ni constituye una política IAM ya otorgada. Sus ARN finales y las restricciones de la cuenta faltan; revisaremos el conjunto efectivo y el change set antes de ejecutar. No pedir AdministratorAccess como atajo. La Lambda reserva tres ejecuciones en total; la cuota disponible debe permitirlo dejando al menos 100 sin reservar. Revisar `lambda:GetAccountSettings`, sin quitar reservas ajenas ni solicitar aumentos ahora. [Concurrencia Lambda](https://docs.aws.amazon.com/lambda/latest/dg/configuration-concurrency.html).

## 5. Primera etapa desde consola, después de autorización

1. Confirmar identidad no-root, cuenta/Irlanda y ausencia del stack/tema/bucket/alias previstos. Si alguno existe, revisar propietario y uso; no crear duplicados ni sobrescribirlo. No usar `CreateTopic` como prueba de existencia.
2. Seleccionar el archivo **`deploy/sns-bootstrap/template.yaml`**, nombre de pila exacto `muserelay-aws-bridge-stage`, `KmsAdministratorArn` y `KmsRecoveryAdministratorArn` confirmados y etiquetas. Seleccionar además el rol de servicio estable en Permisos. No requiere reconocer creación de roles IAM, porque esta fase no los crea.
3. Revisar recursos, permisos, costes y eliminación. Puede prepararse un change set de tipo **CREATE** para revisión; crearlo ya escribe en AWS y necesita autorización. Ejecutarlo requiere el alcance aprobado.
4. Tras `CREATE_COMPLETE`, comprobar los doce recursos, cifrado y suscripción confirmada. Leer `InboundTopicArn` en **Salidas** y contrastarlo con SNS (cuenta/región/política/clave). Solo entonces copiar el ARN real al formulario Social.
5. Asociar ese destino y completar/actualizar el registro de la WABA demo es **otra acción autorizable**, no la ejecuta la plantilla. Registrar IDs AWS y Meta efectivos sin OTP/PIN. Si la sesión de alta expiró, podría ser necesario reanudar el proceso interactivo; la plantilla no exige un número nuevo ni garantiza saltarse verificaciones Meta/AWS.

ARN **previsto, aún no creado/verificado**:

```text
arn:aws:sns:eu-west-1:365470560685:muserelay-aws-bridge-stage-inbound
```

La consola permite URL S3, cargar archivo y sincronización Git. Para este ensayo elegimos archivo, sin conectar Git ni crear automatización continua. **Elegir un YAML para cargar puede subirlo inmediatamente a S3 y crear el bucket regional de plantillas de CloudFormation si no existe**, antes de enviar la pila. Abrir/explorar el formulario no equivale a crear la pila, pero no afirmar que toda la preparación del asistente es de solo lectura. Ese bucket de plantillas es adicional al bucket de artefactos definido en nuestra pila y debe incluirse en la revisión de costes/limpieza. [Comportamiento oficial de carga y creación](https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/cfn-console-create-stack.html).

## 6. Segunda etapa: código, IDs y secretos, todavía apagada

La expansión SAM completa contiene **30 recursos CloudFormation** con el secreto nuevo (26 declaraciones SAM), incluyendo los doce anteriores. Si se aporta un secreto dedicado externo en lugar de crearlo, serían 29. Las adiciones son: dos colas SQS de salida (principal + DLQ), DynamoDB, tres roles IAM, tres Lambda, tres grupos de logs de 7 días, un secreto vacío, HTTP API + stage, dos mappings SQS y un permiso Lambda/API. No crea agentes Bedrock ni copia el Core.

1. Preparar el ZIP reproducible local desde el commit revisado: `npm test`, `npm run bundle` y `python3 scripts/cloudformation/package-runtime.py --output /var/tmp/aws-bridge-release-DIRECTORIO-NUEVO`. La carpeta privada 0700 queda fuera de vhosts; ZIP/plantillas/manifiesto 0600. El ZIP incluye solo `handlers.mjs`; el manifiesto fija commit y SHA-256. El script requiere checkout limpio y no contacta AWS.
2. Después de autorizarlo, subir `bridge.zip` al bucket devuelto por `ArtifactBucketName`, clave exacta `releases/<commit-de-40-caracteres>/bridge.zip` del manifiesto. Verificar SHA-256 local/cargado sin publicar el objeto. No hay bucket público ni descarga del código desde Git en el runtime.
3. Preparar parámetros definitivos fuera de Git usando [inputs pendientes](../deploy/cloudformation/inputs.pending.json) como inventario, **no como archivo desplegable**. `RuntimeArtifactKey` toma la clave anterior; `SocialPhoneArn` es el ARN AWS real; `BindingsJson` contiene una sola asociación demo con `id`, `region`, `topicArn`, **ID numérico WABA Meta**, `metaPhoneNumberId`, `awsPhoneNumberId` (ARN AWS), `channelRef`, `enabled:false`, `allowedRecipients:[]`. El WABA vinculado AWS se conserva para preflight, no sustituye el ID Meta del binding. Verificar la versión Graph soportada por Social en `MetaApiVersion`; no copiar una versión de una simulación. Solo usar el hostname técnico de staging y límites diarios 6/6.
4. En la **misma pila**, crear change set **UPDATE** usando `template.yaml`, `DeployProcessing=true`, **`ProcessingEnabled=false`, `LiveSend=false`** y demás inputs. `CAPABILITY_IAM` reconoce tres roles; al ejecutar directamente una transformación SAM, CloudFormation puede exigir también `CAPABILITY_AUTO_EXPAND`. Estas capacidades no conceden permisos al operador. Revisar la expansión/change set, sin ejecutar automáticamente. El CodeUri ya usa bucket y clave S3 parametrizados: no necesita `sam package` ni SAM CLI para la vía de consola. Sí necesita el ZIP existente y legible. No cargar YAML de un commit distinto al manifiesto.
5. `SecretArn` vacío crea **metadatos de secreto sin valor**, nombre `muserelay-aws-bridge-stage/signing`; `SigningSecretArn` devuelve su ARN, nunca sus claves. Generar dos claves criptográficas independientes por binding (al menos 32 bytes) fuera de web/Git y de los argumentos/historial/logs, en archivo privado 0600/directorio 0700. La estructura es `{ "binding-id": { "core": "<privada>", "outbound": "<otra privada>" } }`. Inicializarlo con una operación privada explícitamente autorizada (`PutSecretValue` leyendo el archivo, no valores inline); comprobar nombre, tags y ausencia de valor previo para evitar rotar uno existente por error. En staging Core, copiar/montar ese contenido únicamente en `/etc/muserelay/aws-bridge/` o `/run/secrets/`, con los permisos del lector correcto. No se ha creado ningún archivo de claves real durante esta preparación. [Semántica de secreto vacío y clave predeterminada](https://docs.aws.amazon.com/AWSCloudFormation/latest/TemplateReference/aws-resource-secretsmanager-secret.html).
6. Un `SecretArn` externo exige demostrar que es exclusivo de staging, misma cuenta/región y cifrado con la clave predeterminada `aws/secretsmanager`. Si usa CMK propia, detenerse: requerirá revisar un `kms:Decrypt` delimitado, no está incluido automáticamente.

Con `ProcessingEnabled=false`, ambos mappings SQS quedan deshabilitados; además los handlers no procesan/acknowledgean lotes si no está exactamente `true`, y `/outbound` rechaza la operación antes de leer secretos o encolar. `LiveSend=false`, binding deshabilitado y allowlist vacía son controles adicionales. El API HTTP existe tras esa actualización, pero no entrega comandos por estar apagado. Peticiones al API o invocaciones manuales pueden tener coste incluso rechazadas: los flags no son un límite de facturación AWS.

El endpoint de staging, PostgreSQL/Redis, organización, agente Bedrock y buzón de tickets son prerrequisitos externos preparados por fase 4; siguen sin activar. Bedrock se invoca por el agente existente del Core aislado y su identidad específica, no por los roles de estas Lambda. No hay un nuevo Core comercial ni panel de clientes, ni obligación de un dominio/base/Redis por módulo. En producción el módulo continúa apagado. Activar procesamiento real, allowlist, binding y envíos necesitará autorización posterior; `LiveSend=false` por sí solo no impediría costes Bedrock si se autorizase procesar entradas.

## 7. Coste orientativo y límites

La [nota de costes KMS y acceso AWS](AWS-ACCESS-AND-KMS-COSTS.md) distingue permanencia de la clave, solicitudes criptográficas y transporte WhatsApp; también registra el acceso no-root/MFA pendiente. Los USD 0,13 siguientes corresponden aproximadamente a mantener una clave cuatro días, no a cuatro operaciones.

Estimación sin free tier, créditos ni impuestos; tarifas consultadas el 25-10-2026. No es presupuesto automático ni techo de gasto.

| Concepto | Estimación / condición |
| --- | --- |
| KMS dedicada, desde etapa 1 | USD 1/mes prorrateado por horas; unas **USD 0,13 por 96 h**, más operaciones KMS. Una clave, no una por teléfono. |
| Secrets Manager, solo etapa 2 | USD 0,40/mes por secreto prorrateado; aproximadamente **USD 0,05 por 96 h**, más llamadas. |
| SNS/SQS/S3/Lambda/API/DynamoDB/logs | Pago por uso. Para una demo corta y bajo volumen, reservar **hasta USD 1 orientativo para esta infraestructura durante 96 h**, incluyendo las cuotas anteriores. No garantiza gasto inferior: reintentos, entradas ajenas, API público, logs/polling y retenciones se facturan. No hay EC2, NAT Gateway, RDS ni Redis gestionado en esta pila. |
| Dos entradas + dos respuestas WhatsApp | Tarifa AWS Social **USD 0,012** (2 × 0,001 entrada + 2 × 0,005 salida), **más tarifa Meta aplicable** y Bedrock. |
| 24 entradas y 24 salidas en cuatro días | AWS Social **USD 0,144**, más Meta/Bedrock. Los límites del Bridge son por binding/día UTC y no limitan cobros por recibir eventos no procesados ni otras llamadas AWS. |
| Bedrock | Falta verificar modelo/perfil, permisos y estimación por tokens antes de autorizar inferencia. El presupuesto de trabajo propuesto de **USD 5/96 h** para una demo acotada sigue siendo una reserva, no una autorización ni un hard cap. |

[KMS](https://aws.amazon.com/kms/pricing/), [Secrets Manager](https://aws.amazon.com/secrets-manager/pricing/), [SNS](https://aws.amazon.com/sns/pricing/), [SQS](https://aws.amazon.com/sqs/pricing/), [Social y tarifa Meta separada](https://aws.amazon.com/end-user-messaging/pricing/). La retención de recursos tras la demo prolonga cargos. La caducidad S3 a 30 días solo es apropiada para artefactos de esta prueba: conservar una copia privada antes de expirar o de intentar rollback tardío; revisar la política para servicio permanente.

## 8. Reversión y eliminación: requieren autorización

Ante fallo de creación inicial, revisar eventos y rollback CloudFormation; no reintentar alterando permisos a ciegas. Una clave/bucket con `Retain` pueden sobrevivir a rollback o eliminación y bloquear otro intento por nombre/alias. Inventariarlos antes de repetir. Ante fallo de actualización, conservar YAML/ZIP previos y dejar que CloudFormation revierta las adiciones; comprobar expresamente que el ingreso original permanece intacto. No marcar la reversión AWS como probada en vivo: solo se ha revisado el diseño offline.

Retirada ordenada propuesta:

1. Deshabilitar demo/Core staging y `ProcessingEnabled`/`LiveSend`, revisar operaciones en curso y comandos inciertos sin reenviarlos. No detener workers nativos compartidos. Cambiar `DeployProcessing=false` **elimina recursos condicionales**: no usarlo como simple interruptor de pausa.
2. Tras revisar todos los números de esa WABA, desactivar/quitar **solo el destino de eventos de demo** antes de eliminar SNS. No alterar otra WABA ni canales Meta directos. No se necesita desregistrar el teléfono por borrar este stack.
3. Revisar evidencia/retención y contenido pendiente de colas; autorizar su pérdida antes de borrar recursos. No purgar ni redrive automático. Borrar la pila retira recursos normales, incluidos sus roles/colas/API/logs; no toca el Core ni tablas de producción.
4. Quedan **retenidos**: clave KMS, bucket de artefactos, tabla DynamoDB y secreto de firmas creado por la pila. El alias KMS se elimina, la clave no. Un secreto externo nunca perteneció al stack. Esos recursos pueden seguir costando: inventariarlos en el informe final con ARN reales.
5. Después de confirmar que nadie los usa y con aprobación específica, vaciar/borrar el bucket propio; eliminar tabla cuando venza retención; programar borrado del secreto con ventana de recuperación (no force delete); programar borrado KMS con mínimo **7 días** y verificar que la clave sea la de demo. No automatizamos estas operaciones destructivas. Revisar también bucket/objetos de plantillas que creó la consola. TTL DynamoDB borra ítems, no la tabla/PITR.
6. Retirar endpoint/usuarios/servicios/archivos del staging temporal según runbook, solo tras verificar que ningún Bridge definitivo apunta allí. No recargar servicios compartidos ni DNS/TLS de producción sin autorización. Registrar ausencia de tráfico y costes residuales; cerrar la consola no los detiene.

La reversión del Core instalado y su restauración aislada se documentaron por separado en [CORE-ROLLBACK.md](CORE-ROLLBACK.md). No se ha ejecutado instalación Core en esta tarea y no es necesario restaurar la base de producción para preparar o retirar el transporte AWS.

## 9. Evidencia y condiciones para continuar

Para la revisión posterior de roles estables/SSO y los hashes vigentes prevalece [kms-stable-administrators-preparation.json](evidence/kms-stable-administrators-preparation.json). El informe de cifrado anterior describe la entrega histórica sin estas vías adicionales de administración.

[validation.offline.json](../deploy/cloudformation/validation.offline.json) registra hashes, reglas y recursos de ambos modos. El [informe de evidencias](evidence/encrypted-cloudformation-preparation.json) reúne los resultados y las limitaciones de la revisión. Validación local: cfn-lint sobre ambas plantillas; expansión SAM con el CodeUri S3 real y sin llamadas IAM; controles de identidad/flags/permisos/consistencia; pruebas de handlers apagados junto a la suite Node. Los revisores se ejecutan con red aislada. Esto no demuestra permisos efectivos, elegibilidad Meta, entrega SNS/KMS ni éxito CloudFormation en la cuenta.

Para reproducir: PyYAML para `generate-bootstrap.py`/`validate.py`; cfn-lint 1.58.0, aws-sam-translator 1.115.0; dependencias Node fijadas en package-lock. Ejecutar herramientas en entorno privado, sin instalar ni modificar servicios compartidos. `scripts/review-sam.py` usa datos sintéticos solo para la expansión; `validate.py` prueba las Rules, no sustituye la validación de bindings finales.

**Pendiente antes del primer recurso:** preflight real de la identidad SSO comunicada y autorización/creación/verificación de los dos roles estables; usar el YAML actualizado de esta revisión. **Pendiente antes de la conversación:** IDs AWS/Meta finales, ZIP cargado, secreto inicializado, staging HTTPS/Bedrock operativo y autorización restringida a demo. El objetivo previo al 28 de octubre es viable condicionado a resolver esos accesos: estimación de trabajo **30–60 min** para ingreso/verificación y **4–8 h adicionales** para runtime/staging/primer ensayo, sin contar esperas externas o incidencias. No prometer una demostración funcional solo por obtener un ARN SNS.

**Paquete histórico sustituido para despliegue:** `/var/tmp/aws-bridge-release-f34cd4105b16/` contiene las plantillas anteriores a la revisión SSO/KMS. No cargar esos YAML; usar las plantillas nuevas y su informe de esta revisión. El paquete histórico contiene: `01-ingress.yaml`, `02-runtime.yaml`, `bridge.zip` y `manifest.json`. Fuente fijada a `f34cd4105b1698c620a7e465d9cc19e964adb734`; no está cargado en AWS ni servido por web. Se verificaron hashes, permisos, contenido único del ZIP y carga de sus tres exports sin invocarlos. La presente revisión sí modifica las políticas y los parámetros YAML; el código Lambda permanece sin cambios.
