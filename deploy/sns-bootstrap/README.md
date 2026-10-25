# Etapa 1: ingreso SNS/SQS cifrado — NO desplegado

Cuenta Screen Art `365470560685`, región `eu-west-1`, pila exacta **`muserelay-aws-bridge-stage`**. El usuario informa de una lista SNS vacía y de avanzar en el alta Meta; no hay inventario AWS independiente desde esta sesión. No crear recursos ni copiar un ARN supuesto al formulario sin autorización y verificación.

La guía vigente de las dos etapas, permisos, costes y retirada es [CLOUDFORMATION-DEPLOYMENT.md](../../docs/CLOUDFORMATION-DEPLOYMENT.md). Esta plantilla sustituye el primer bootstrap sin CMK: ahora incorpora cifrado SSE-KMS dedicado y DLQ de entrega SNS.

`template.yaml` contiene **12 recursos**: clave KMS simétrica + alias; tema SNS Standard + política; cola de entrada + DLQ de procesamiento + política; suscripción con `RawMessageDelivery=false`; DLQ de entrega SNS + política; bucket privado de artefactos + política TLS. El bucket tiene SSE-S3 y bloqueo público; colas SSE-SQS. El secreto y el runtime no se crean en esta etapa.

Único parámetro: `KmsAdministratorArn`, ARN IAM existente no-root de Screen Art correspondiente al ejecutor efectivo CloudFormation. Requiere identificarlo y comprobar permisos; no se inventa ni crea un rol. Las Rules rechazan otra cuenta, región o nombre de pila.

Salidas: `InboundTopicArn`, `SnsKeyArn`, `InboundQueueArn`, `InboundQueueUrl`, `InboundDeadLetterQueue`, `SnsDeliveryDeadLetterQueue`, `SubscriptionArn`, `ArtifactBucketName`. No hay valores secretos.

ARN previsto, todavía no creado/verificado:

```text
arn:aws:sns:eu-west-1:365470560685:muserelay-aws-bridge-stage-inbound
```

`topic-policy.prepared.json` muestra la concesión Social del recurso SNS: `sns:Publish` del servicio `social-messaging.amazonaws.com`, cuenta/origen Social de Irlanda. La plantilla añade también `kms:GenerateDataKey*`/`kms:Decrypt` sobre la clave SNS con las mismas condiciones de origen. No se usa un ARN WABA inventado ni una clave ajena. [Referencia oficial](https://docs.aws.amazon.com/social-messaging/latest/userguide/managing-event-destinations-add.html).

El coste fijo principal es **una clave USD 1/mes prorrateado por horas**, más uso; aproximadamente USD 0,13 por 96 h de almacenamiento de clave. SNS/SQS/S3/KMS se facturan por operaciones/almacenamiento; no es un precio por teléfono. [KMS](https://aws.amazon.com/kms/pricing/). La clave y el bucket quedan retenidos al eliminar la pila y exigen limpieza posterior autorizada; no prometer coste cero tras DeleteStack.

La segunda etapa es UPDATE de esta misma pila con [template.yaml completo](../../template.yaml), IDs finales y ZIP en el bucket devuelto. Conserva los doce recursos y añade consumidores inicialmente apagados. No crear otra pila con el mismo TopicName, no borrar/recrear el ingreso y rechazar reemplazos inesperados en el change set. No conectar directamente SNS a la API HMAC del Core.

Para regenerar y comprobar sin AWS, desde la raíz del repositorio:

```sh
python3 scripts/cloudformation/generate-bootstrap.py
python3 scripts/cloudformation/validate.py
```

La comparación automática evita divergencias entre bootstrap y plantilla completa. [Resultado offline](../cloudformation/validation.offline.json). Nada de esto prueba los permisos de la cuenta ni realiza un despliegue.

Después de crear/verificar con autorización, obtener el ARN real de Salidas y SNS; asociarlo a la WABA demo mediante otra operación autorizada. EU (Germany) es localización Meta, separada de Irlanda AWS. Los IDs Meta/WABA vinculada AWS/teléfono ARN son distintos. [Onboarding y estado conocido](../../docs/AWS-WHATSAPP-ONBOARDING.md).
