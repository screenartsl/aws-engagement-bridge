# Revisión de ingreso SNS/KMS/SQS — 25 de octubre de 2026

> Estado posterior: el usuario comunica la pila de ingreso CREATE_COMPLETE y comprobaciones de configuración comunicadas como conformes desde CloudShell. Prevalece [INGRESS-POST-DEPLOYMENT.md](INGRESS-POST-DEPLOYMENT.md). Las instrucciones de creación siguientes son historial; no repetir el CREATE ni ejecutar otra vez el conjunto. La asociación de eventos Social sigue sin autorizar ni realizar.

**GO para preparar y revisar; ejecución pendiente de autorización separada.** No se ha creado un conjunto de cambios desde este servidor: no dispone de identidad AWS utilizable. Los archivos JSON son solicitudes preparadas localmente, no identificadores de conjuntos existentes en AWS. No se ha modificado MuseRelay ni se ha llamado a AWS.

## Estado posterior: conjunto de ingreso preparado y revisión de KMS

El usuario confirma `ingress-review-4bc773772e50` en CREATE_COMPLETE/AVAILABLE con doce Add, sin ejecución. La [revisión específica de Social/SNS/KMS y bloqueo administrativo](SNS-KMS-COMPATIBILITY.md) confirma que no hace falta añadir sns.amazonaws.com a la política de esta clave ni sustituir el conjunto por ese motivo. El YAML y sus hashes no cambian. La compatibilidad documental y el análisis local no son pruebas de creación KMS o entrega en AWS. Mantener el conjunto sin ejecutar hasta autorización separada.

## Estado posterior: IAM actualizado y siguiente paso desde consola

El usuario comunica UPDATE_COMPLETE de IAM Foundation, ARN sin cambios, acciones SNS/S3 previstas presentes y denegaciones DisableKey/ScheduleKeyDeletion conservadas, comprobado desde CloudShell. Se registra como [evidencia facilitada](../evidence/iam-foundation-update-user-evidence.json); no es una lectura AWS del agente. No repetir el UPDATE IAM. El apartado de ampliación IAM y sus comandos de preparación más abajo queda como historial del requisito ya completado según esa verificación. La fuente YAML de ingreso permanece idéntica al commit `5f563ea4387c9694782943566614e38b9463168a`, SHA-256 `4bc773772e50645680a5ffacdb25fdd8100a7f4a07ea8c6c185d461295f023cb`.

### Preparación de ingreso desde consola, sin CloudShell

1. En Screen Art `365470560685`, Irlanda, sesión SSO AdministratorAccess, comprobar si existe `muserelay-aws-bridge-stage`. Si ya existe, revisar su estado y change sets antes de continuar; no recrear, borrar ni escoger UPDATE automáticamente.
2. Si no existe, CloudFormation → Crear pila → Con nuevos recursos (estándar). Elegir plantilla existente y cargar **deploy/sns-bootstrap/template.yaml**, no la plantilla IAM ni el SAM completo. La carga de consola almacena el YAML en S3 y puede crear un bucket auxiliar de plantillas; no son los doce recursos de ingreso.
3. Nombre de pila exacto: `muserelay-aws-bridge-stage`. Introducir los dos parámetros del apartado siguiente. No se solicitan todavía WABA, número, secreto ni endpoint MuseRelay.
4. En Opciones → Permisos, seleccionar **muserelay-aws-bridge-stage-cfn-execution**, el rol actualizado. No seleccionar recuperación ni el rol SSO. En esta pila sí se usa el ejecutor, a diferencia del UPDATE IAM anterior.
5. Conservar rollback predeterminado y no añadir notificaciones SNS, nuevos roles ni opciones de despliegue rápido. Los cinco tags están definidos en los recursos de la plantilla; si se añaden en la pila, usar los mismos pares Company/Project/Component/Environment/Purpose. No hay recursos IAM ni transformación SAM que requieran capacidades IAM/SAM.
6. En la pantalla final de revisión, elegir **Crear conjunto de cambios**, nombre `ingress-review-4bc773772e50`. No pulsar Enviar/Crear pila como operación directa de despliegue ni Ejecutar conjunto de cambios.
7. Revisar el resultado: change set CREATE_COMPLETE/AVAILABLE, pila REVIEW_IN_PROGRESS, rol de servicio correcto, parámetros exactos y las doce adiciones del manifiesto. Sin Modify, Remove ni recursos de procesamiento. Revisar políticas SNS/KMS/SQS, Retain de clave/bucket y advertencias de validación. Detenerse para autorización independiente.

[Procedimiento oficial de previsualización de una pila nueva](https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/cfn-console-create-stack.html#cfn-console-create-stack-changesets). Crear correctamente el change set no prueba entrega de eventos ni garantiza el despliegue posterior. Si la consola no ofrece la opción de crear solo el conjunto, detenerse antes del botón de despliegue directo y revisar la pantalla.

## Evidencia recibida y versiones

El usuario confirma desde CloudShell que `muserelay-aws-bridge-iam-foundation` está en `CREATE_COMPLETE`, cuenta `365470560685`, región `eu-west-1`, y proporciona estas salidas reales:

| Salida | ARN |
| --- | --- |
| CloudFormationExecutionRoleArn | `arn:aws:iam::365470560685:role/muserelay-aws-bridge-stage-cfn-execution` |
| KmsRecoveryRoleArn | `arn:aws:iam::365470560685:role/muserelay-aws-bridge-kms-recovery` |

También comunica éxito de una asunción real STS del rol de recuperación, mostrando solo el ARN. Se registra como evidencia facilitada por el usuario, no como llamada del agente. Esto prueba la asunción en ese momento; aún no prueba administración de una clave que no existe. No se ha leído aquí la política inline realmente desplegada.

La fuente de ingreso es [deploy/sns-bootstrap/template.yaml](../../deploy/sns-bootstrap/template.yaml), SHA-256 `4bc773772e50645680a5ffacdb25fdd8100a7f4a07ea8c6c185d461295f023cb`. Su contenido no cambia en esta revisión. El [manifiesto](../../deploy/sns-bootstrap/review-manifest.json) fija los hashes de ambas plantillas, parámetros, rol de servicio y doce adiciones esperadas. Los JSON incorporan exactamente el YAML en `TemplateBody`; no necesitan publicar la plantilla en S3.

## Hallazgo: completar permisos de metadatos antes de ejecutar ingreso

La base IAM ya creada no se vuelve a crear ni se reemplazan sus roles. La revisión de los esquemas de proveedores incluidos en cfn-lint detecta permisos de lectura declarados por los handlers que no estaban cubiertos. Además, AWS documenta que CloudFormation utiliza actualmente `s3:TagResource` y `s3:UntagResource` para etiquetas de buckets.

Se prepara una actualización **local** de la política inline del ejecutor con 18 acciones adicionales: `sns:GetDataProtectionPolicy`; quince lecturas S3 y dos acciones de etiquetado, todas sobre el tema/bucket exactos. Lista completa y hashes del esquema consultado en [ingress-provider-permission-review.json](../evidence/ingress-provider-permission-review.json). El ejecutor pasa de 48 a 66 acciones Allow. Los esquemas son una copia local, no una consulta a la versión activa de AWS; declarar permisos de un handler no demuestra que invoque cada API para todas las propiedades. La ampliación cubre conservadoramente sus lecturas, sin conceder las escrituras opcionales del proveedor.

No se añaden `GetObject`, `PutObject`, `DeleteObject`, `sns:Publish`, operaciones de mensajes SQS, `iam:PassRole`, permisos de Bedrock o cambios IAM al ejecutor. No se añaden configuración de replicación, website, ABAC ni otras escrituras S3 opcionales. Permanece el Deny explícito de `kms:DisableKey` y `kms:ScheduleKeyDeletion`. La administración `PutKeyPolicy` se concede directamente sobre la futura clave, no mediante una concesión IAM amplia basada en etiquetas.

Referencias: [etiquetado S3 utilizado por CloudFormation](https://docs.aws.amazon.com/AmazonS3/latest/userguide/buckets-tagging-enable-abac.html), [permisos S3](https://docs.aws.amazon.com/AmazonS3/latest/userguide/using-with-s3-policy-actions.html), [lectura de protección SNS](https://docs.aws.amazon.com/sns/latest/dg/sns-access-policy-language-api-permissions-reference.html), [esquema real del proveedor mediante DescribeType](https://docs.aws.amazon.com/AWSCloudFormation/latest/APIReference/API_DescribeType.html).

Antes de ejecutar, contrastar desde CloudShell la política desplegada con el JSON revisado. Si coincide con la versión anterior, hay que revisar y autorizar por separado el UPDATE IAM preparado. No adjuntar AdministratorAccess a estos roles. No dar por probados SCP, boundaries, condiciones de creación o handlers mediante lint o un change set correcto.

## Parámetros y políticas definitivos del ingreso

Los dos valores de [parameters.stage.json](../../deploy/sns-bootstrap/parameters.stage.json) son los ARN anteriores: `KmsAdministratorArn` toma el ejecutor y `KmsRecoveryAdministratorArn` el rol de recuperación. **Seleccionar también el ejecutor como rol de servicio de CloudFormation**; el parámetro por sí solo no configura ese rol. La petición preparada lleva `RoleARN` explícito. El rol de recuperación no se utiliza para ejecutar la pila.

| Recurso/política | Configuración revisada |
| --- | --- |
| SNS | Standard, nombre `muserelay-aws-bridge-stage-inbound`, máximo 256 KiB, SSE-KMS dedicada. FIFO no es compatible con Social. |
| Publicación SNS | Solo concesión al servicio `social-messaging.amazonaws.com` para `sns:Publish` al tema exacto; `aws:SourceAccount=365470560685` y `aws:SourceArn=arn:aws:social-messaging:eu-west-1:365470560685:*`. No publicación pública. Los permisos IAM legítimos de la misma cuenta se evalúan también. |
| Uso KMS por Social | Clave simétrica, `kms:GenerateDataKey*` y `kms:Decrypt` para Social con las mismas condiciones de origen. No se entrega material de clave a MuseRelay. |
| Administración KMS | Rol estable de ejecución; rol estable de recuperación con Describe/Get/PutKeyPolicy; vía de recuperación de cuenta limitada a esas tres acciones. Sin rol SSO efímero incrustado; protección anti-bloqueo activa. Retain en la clave. |
| SQS | Una cola de entrada, una DLQ de procesamiento y otra de fallos de entrega SNS. SSE-SQS; entrada 4 días, DLQ 14 días; redrive de procesamiento tras 5 recepciones, visibilidad 180 s. |
| Políticas SQS | `sns.amazonaws.com` puede SendMessage solo desde este tema y cuenta hacia entrada y DLQ de entrega. La DLQ de procesamiento acepta redrive solo desde la entrada. |
| Suscripción | SNS → SQS, `RawMessageDelivery=false`, sin filtros, DLQ de entrega asociada. Conserva el sobre SNS esperado por el Bridge. |
| S3 | Bucket privado de artefactos, SSE-S3, bloqueo público, propietario enforced y política TLS; sin objetos en esta etapa. Retain; caducidad de artefactos a 30 días. |

Los doce recursos exactos, con LogicalResourceId y tipo, constan en el manifiesto. **No hay Lambda, API, worker, secreto, DynamoDB ni Bedrock en esta plantilla.** No se autoriza ninguna organización, no se crea un canal y no se cambia el Core. SNS/SQS sí distribuirían y almacenarían eventos si alguien asociase el destino posteriormente; esa asociación no ocurre aquí y requiere autorización independiente. Las colas no tendrán consumidores en esta etapa.

Las condiciones Social/KMS coinciden con el [ejemplo oficial de AWS](https://docs.aws.amazon.com/social-messaging/latest/userguide/managing-event-destinations-add.html). Se prepara todo en Irlanda; no se deduce otra región SNS de la localización de datos seleccionada en Meta. La entrega efectiva debe verificarse después de la asociación autorizada. No se confunde el ID numérico Meta con un ARN de recurso AWS.

ARN esperado del tema, **aún no creado ni verificado**:

```text
arn:aws:sns:eu-west-1:365470560685:muserelay-aws-bridge-stage-inbound
```

Usar únicamente la salida real `InboundTopicArn` tras `CREATE_COMPLETE` y una lectura SNS satisfactoria. Crear el destino afectará a todos los números de esa WABA: revisar previamente que sea exclusivamente la de demostración.

## Preparar en CloudShell sin ejecutar

Subir los archivos revisados a CloudShell desde una copia privada del repositorio. Nunca cargar `.env`, claves o tokens. La preparación local se reproduce con `python3 scripts/cloudformation/prepare-ingress-review.py`; `--check` detecta JSON desactualizados sin escribir ni llamar a AWS.

Comprobar cuenta/sesión y foundation (solo lectura):

```sh
aws sts get-caller-identity --region eu-west-1 --query '{Account:Account,Arn:Arn}' --output json
aws cloudformation describe-stacks --region eu-west-1 --stack-name muserelay-aws-bridge-iam-foundation --query 'Stacks[0].{Status:StackStatus,RoleARN:RoleARN,Outputs:Outputs}' --output json
aws iam get-role --role-name muserelay-aws-bridge-stage-cfn-execution --query 'Role.{Arn:Arn,Trust:AssumeRolePolicyDocument,Boundary:PermissionsBoundary}' --output json
aws iam get-role --role-name muserelay-aws-bridge-kms-recovery --query 'Role.{Arn:Arn,Trust:AssumeRolePolicyDocument,Boundary:PermissionsBoundary}' --output json
aws iam get-role-policy --role-name muserelay-aws-bridge-stage-cfn-execution --policy-name bridge-stage-scoped --query PolicyDocument --output json > execution-policy.aws.json
python3 - <<'PY'
import json
from pathlib import Path
expected = json.loads(Path('deploy/iam-foundation/execution-ingress-policy.json').read_text())
actual = json.loads(Path('execution-policy.aws.json').read_text())
assert actual == expected, 'La política desplegada difiere; revisar el UPDATE IAM antes del ingreso'
print('Política inline coincide con la revisión local')
PY
```

Antes de la actualización, es esperable que esa comparación detecte la versión anterior; no es motivo para aplicar permisos automáticamente. Revisar también la confianza y política del rol de recuperación. Para obtener esquemas actuales, `cloudformation describe-type --type RESOURCE --type-name AWS::S3::Bucket` (y `AWS::SNS::Topic`) permite consultar `Schema` en Irlanda, sin crear recursos. Comparar `handlers.read.permissions` y las propiedades utilizadas; no copiar todas las escrituras opcionales del proveedor a IAM.

Leer el inventario actual antes de crear el change set de ingreso; la lista vacía anterior no sustituye este preflight:

```sh
aws cloudformation describe-stacks --region eu-west-1 --stack-name muserelay-aws-bridge-stage --query 'Stacks[0].{Status:StackStatus,RoleARN:RoleARN}' --output json
aws sns list-topics --region eu-west-1 --query 'Topics[].TopicArn' --output json
aws sqs list-queues --region eu-west-1 --queue-name-prefix muserelay-aws-bridge-stage --output json
aws kms list-aliases --region eu-west-1 --query "Aliases[?AliasName=='alias/muserelay-aws-bridge-stage-sns'].{Alias:AliasName,TargetKeyId:TargetKeyId}" --output json
aws s3api head-bucket --region eu-west-1 --bucket muserelay-aws-bridge-stage-artifacts-365470560685-eu-west-1
```

El error explícito de pila inexistente es esperable para un CREATE inicial; AccessDenied o errores de red no prueban ausencia. Igual criterio para el bucket: 403 no significa libre; el nombre S3 es global. Si existen pila, alias, bucket, tema o colas, detenerse e identificar propietario/estado, sin adoptar ni borrar recursos automáticamente. Si la pila está `REVIEW_IN_PROGRESS`, revisar los change sets anteriores y sustituir solo la preparación aprobada; no cambiar a UPDATE a ciegas ni crear otra pila con nombres distintos. El JSON CREATE presupone una pila nueva.

Primero, para **generar el change set de la corrección IAM**, desde la sesión SSO administradora y en la raíz de la copia del repositorio:

```sh
aws cloudformation create-change-set --region eu-west-1 --cli-input-json file://deploy/iam-foundation/change-set.metadata-update.prepared.json --query '{Id:Id,StackId:StackId}' --output json
```

Esto prepara un UPDATE de foundation; no lo ejecuta. Debe mostrar únicamente modificación de `StageCloudFormationRole`, sin reemplazo, sin cambio de recuperación y sin recursos nuevos. Leer el `RoleARN` de la pila foundation: si tiene un service role configurado, no presumir que puede actualizar IAM; revisarlo antes y no reutilizar el ejecutor de ingreso para que se amplíe a sí mismo. La petición no sustituye ese rol. La ejecución necesita autorización concreta adicional.

Para **generar el change set de ingreso**, una vez revisadas las precondiciones, sin ejecutarlo:

```sh
aws cloudformation create-change-set --region eu-west-1 --cli-input-json file://deploy/sns-bootstrap/change-set.create.prepared.json --query '{Id:Id,StackId:StackId}' --output json
```

Es posible preparar este change set antes de ejecutar la corrección IAM, pero no aprueba permisos efectivos ni permite saltarse esa dependencia. La solicitud solo acepta los tipos de recursos de ingreso mediante `ResourceTypes`; no solicita capacidades IAM ni transformaciones SAM. Crea metadatos de change set/pila en revisión, no los doce recursos. [Semántica oficial](https://docs.aws.amazon.com/AWSCloudFormation/latest/APIReference/API_CreateChangeSet.html).

Consultar el ARN/ID devuelto, sustituyendo únicamente el marcador siguiente:

```sh
aws cloudformation describe-change-set --region eu-west-1 --change-set-name '<ID-devuelto>' --query '{Status:Status,ExecutionStatus:ExecutionStatus,StatusReason:StatusReason,RoleARN:RoleARN,Parameters:Parameters,Changes:Changes[].ResourceChange.{Action:Action,LogicalId:LogicalResourceId,Type:ResourceType,Replacement:Replacement}}' --output json
```

Esperar `CREATE_COMPLETE` del **change set**, `ExecutionStatus=AVAILABLE` y contrastar las doce adiciones del manifiesto, sin modificaciones, eliminaciones ni reemplazos. Este estado no es el `CREATE_COMPLETE` de una pila desplegada. Nombres de change sets incluyen el hash del YAML; si ya existe uno con el mismo nombre, leerlo y no sobrescribirlo/repetir operaciones automáticamente. Ningún archivo ejecuta `ExecuteChangeSet`. **Detenerse aquí y presentar el resultado para autorización.**

Vía consola: actualizar foundation con el YAML revisado, crear change set sin ejecutarlo; para ingreso, pila `muserelay-aws-bridge-stage`, YAML bootstrap, dos parámetros y ejecutor estable en Permisos. Revisar change set. La carga de YAML en consola puede almacenar una plantilla en S3; el camino CLI con TemplateBody evita esa carga adicional.

## Coste y retirada

Esta etapa no incorpora recursos con coste fijo de cómputo. La clave KMS cuesta **USD 1/mes prorrateado por horas**, aproximadamente **USD 0,13 por 96 horas**, más operaciones facturables. SNS, SQS y S3 cobran por uso: con bucket vacío, sin consumidores ni destino WABA asociado, se espera un coste adicional pequeño de solicitudes de configuración. Reserva orientativa para 96 horas: **USD 0,50**, sin créditos/free tier/impuestos; no es un límite automático. La facturación exacta depende de llamadas y tráfico efectivo. [KMS](https://aws.amazon.com/kms/pricing/), [SNS](https://aws.amazon.com/sns/pricing/), [SQS](https://aws.amazon.com/sqs/pricing/), [S3](https://aws.amazon.com/s3/pricing/).

WhatsApp, Bedrock, runtime y almacenamiento/operación del staging no forman parte de esta estimación. La asunción STS verificada no autoriza pruebas de mensajería ni inferencia.

Si solo se preparan change sets, se pueden retirar esos metadatos sin haber creado el ingreso; revisar la pila `REVIEW_IN_PROGRESS` y no confundirla con una instalación. Tras una ejecución futura autorizada, CloudFormation intentará rollback ante fallo; conservar eventos e inventariar recursos retenidos antes de reintentar. No se ha probado ese rollback en AWS.

Borrar la pila de ingreso, con autorización y revisión de datos pendientes, eliminaría tema, alias, suscripción, colas y políticas. **Clave KMS y bucket quedan retenidos**; la clave continúa facturando mientras se conserve activa. La política del ejecutor impide desactivar/programar borrado KMS; la limpieza final es una actuación administrativa separada, con autorización y ventana KMS mínima de siete días. No retirar foundation ni sus roles mientras sobreviva una clave dependiente. Si ya se hubiera asociado la WABA, retirar antes su destino de demo con aprobación; no tocar otros números/canales.

La actualización IAM puede revertirse con el YAML anterior revisado si no hay operaciones activas que necesiten los nuevos permisos; no borrar/recrear los roles. Ningún procedimiento de esta etapa requiere restaurar la base de MuseRelay, recargar sus servicios o modificar sus canales Meta. El rollback local del Core permanece documentado por separado.
