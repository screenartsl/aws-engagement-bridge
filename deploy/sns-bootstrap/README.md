# Preparación del destino SNS de demostración — NO desplegado

Cuenta prevista `365470560685`, región `eu-west-1`, nombre de stack previsto **`muserelay-aws-bridge-stage`**. WABA Meta facilitada por el usuario: `29207063275646749`; este ID no es el ID/ARN del recurso WABA vinculado de AWS. El teléfono de demostración es el que ya registró el usuario; esta plantilla no crea ni registra teléfonos.

ARN **previsto**, sujeto a creación/lectura y verificación real:

```text
arn:aws:sns:eu-west-1:365470560685:muserelay-aws-bridge-stage-inbound
```

No pegarlo como ARN existente hasta que `GetTopicAttributes` o la consola confirme el recurso real. Si existe un recurso apropiado con otro nombre, revisar su propietario, uso, política y suscripciones antes de reutilizarlo. No sobrescribir recursos ajenos. Desde esta sesión no hay AWS CLI, perfiles en el usuario operativo ni credenciales AWS de entorno; el inventario remoto permanece **PENDIENTE**, no se afirma que no existan temas.

## Configuración preparada

`template.yaml` contiene seis recursos con los mismos logical IDs y propiedades que el ingreso de la plantilla principal: tema SNS Standard, política SNS, cola SQS Standard, DLQ de procesamiento, política SQS y suscripción SNS→SQS. `RawMessageDelivery:false` conserva `Type`, `TopicArn` y `Message`, que exige `src/normalize.ts`; no suscribir directamente el endpoint HMAC del Core a SNS. Sin filtros, para conservar mensajes y estados. La cola acepta `sqs:SendMessage` de `sns.amazonaws.com` solo desde este tema/cuenta; SSE administrado de SQS, retención de cuatro días y cinco intentos antes de la DLQ (14 días). Esta DLQ corresponde al procesamiento SQS, no es una DLQ de fallo de entrega SNS.

`topic-policy.prepared.json` resuelve la declaración de publicación para el ARN previsto: servicio `social-messaging.amazonaws.com`, acción `sns:Publish`, cuenta de origen `365470560685` y origen Social en Irlanda. Es una política del **recurso SNS**, no una política de confianza IAM; no necesita crear un rol de ejecución para la publicación directa. El comodín de recurso Social sigue el patrón oficial para la configuración inicial; no sustituirlo por el ID numérico Meta ni por un ARN WABA inventado. Una vez conocido el ARN AWS real, revisar si procede restringirlo con evidencia del origen usado por Social.

El tema no configura una clave KMS propia, igual que la plantilla principal. No afirmar que tiene SSE-KMS activado. Si se exige cifrado SNS con KMS, revisar antes la clave simétrica y los permisos `kms:GenerateDataKey*`/`kms:Decrypt` del servicio Social. No usar una clave existente ni cambiar su política sin autorización.

No hay Lambda, IAM, Secrets Manager, Bedrock, API Gateway, envíos, workers ni conexión al Core en este bootstrap. Tras su futura creación autorizada la cola puede recibir eventos de demo y conservarlos sin procesarlos; no enviar mensajes hasta preparar el consumidor. Crear solo un tema sin una suscripción no conserva un historial para un consumidor futuro.

## Evolución sin duplicar recursos

Tras inventario y autorización, crear este ingreso como stack **`muserelay-aws-bridge-stage`**, solo si ese stack/recurso no existe. Obtener `InboundTopicArn` del output y contrastarlo con SNS antes de introducirlo en el formulario AWS. Vincular el destino cambia la configuración de la WABA y necesita la autorización correspondiente. Una WABA tiene un destino y todos sus números publican allí; comprobar que es exclusivamente de demostración y no reemplazar un destino anterior sin revisión.

La futura plantilla completa se aplicará mediante un **UPDATE revisado del mismo stack**, conservando logical IDs, nombre del tema y colas. No crear otro stack completo con el mismo TopicName ni borrar/recrear el ingreso. Revisar el change set y rechazar reemplazos inesperados. Los consumidores seguirán sin desplegarse/activarse hasta completar IDs, secretos, endpoint y autorización. El Core instalado no cambia y sigue desconectado, con cero organizaciones autorizadas.

## Lectura que falta

Un perfil/rol no root autorizado debe confirmar STS (`Account=365470560685`); después `sns:ListTopics`, `sns:GetTopicAttributes`, `sns:ListSubscriptionsByTopic`, `sns:GetSubscriptionAttributes`, y metadatos de las colas/stack que se encuentren (`sqs:GetQueueAttributes`, `cloudformation:DescribeStacks`/`ListStackResources`). Para comprobar la vinculación, listar/leer los recursos Social de demo. No leer valores de secretos ni cuerpos de mensajes, publicar pruebas, crear permisos o invocar modelos. El usuario puede obtener los mismos metadatos desde las páginas de consola, sin configurar credenciales en el chat.

## Acciones futuras que requieren autorización

1. Crear el stack de ingreso: SNS + SQS + DLQ, sus dos políticas de recurso y suscripción. Permisos del operador: creación/lectura/etiquetado y actualización de atributos de esos recursos y operaciones CloudFormation sobre el stack. La reversión/eliminación también exige aprobación; no se ejecutó nada.
2. Verificar ARN, política, región, propietario, cifrado y suscripción confirmada. Consultar `GetTopicAttributes`, no usar `CreateTopic` como supuesto chequeo de existencia.
3. Introducir el ARN verificado y terminar/actualizar el destino de la WABA de demostración bajo autorización. Conservar los IDs reales AWS/Meta del teléfono y WABA, que todavía faltan en el Bridge.
4. Preparar después un change set de actualización del stack completo y una prueba restringida; no habilitar clientes, organizaciones o envíos reales ahora.

## Coste orientativo del ingreso

Sin consumidores, sin clave KMS propia y sin tráfico no hay cuota fija por mantener estos recursos; las operaciones API son de pago por uso. Referencia sin contar free tier: SNS Standard alrededor de USD 0,50/millón de peticiones y SQS Standard alrededor de USD 0,40/millón, en bloques facturables de 64 KiB. Para 1.000 eventos pequeños, una publicación SNS y una entrega SQS por evento dan aproximadamente USD 0,0009; con futuras lecturas/borrados SQS, aproximadamente USD 0,0017, más llamadas de configuración, reintentos y transferencia aplicable. Reservar **menos de USD 0,01 para ese volumen bajo estas hipótesis**, no como límite de gasto. Las recepciones vacías y el número de estados también cuentan. No se presupone free tier disponible. No incluye WhatsApp/Social, Lambda, Bedrock, logs, impuestos ni el resto del Bridge.

Fuentes oficiales revisadas:

- [AWS Social: destino, Standard y políticas](https://docs.aws.amazon.com/social-messaging/latest/userguide/managing-event-destinations-add.html).
- [AWS registro: localización Meta de datos en reposo, separada de región AWS](https://docs.aws.amazon.com/social-messaging/latest/userguide/getting-started-whatsapp.html).
- [SNS Publish: región del destino](https://docs.aws.amazon.com/sns/latest/api/API_Publish.html).
- [SNS precios](https://aws.amazon.com/sns/pricing/) y [SQS precios](https://aws.amazon.com/sqs/pricing/).

La selección **EU (Germany)** es almacenamiento de Meta en reposo. No exige por sí misma SNS en Frankfurt; el ingreso del Bridge se prepara en Irlanda, igual que el recurso Social que se vincule allí. La compatibilidad de esta WABA concreta queda condicionada a verificar su asociación regional real en AWS; no prometer residencia de extremo a extremo exclusivamente en Alemania.
