# Revisión de Social → SNS SSE-KMS y protección administrativa

> Actualización posterior: el usuario comunica CREATE_COMPLETE y clave KMS habilitada con las cuatro declaraciones previstas, comprobadas desde CloudShell. Véase [verificación posterior](INGRESS-POST-DEPLOYMENT.md). El análisis previo de diseño siguiente es histórico; no se ha probado entrega de eventos ni realizado una escritura de recuperación KMS.

Fecha: 25-10-2026, Europe/Paris. **No requiere corregir el YAML ni generar otro change set por esta cuestión. No autoriza ejecutar el conjunto existente.**

Fuente revisada: `deploy/sns-bootstrap/template.yaml` del commit `5f563ea4387c9694782943566614e38b9463168a`, SHA-256 `4bc773772e50645680a5ffacdb25fdd8100a7f4a07ea8c6c185d461295f023cb`. La documentación posterior conserva ese contenido. El usuario confirma el conjunto `ingress-review-4bc773772e50` en CREATE_COMPLETE/AVAILABLE con doce Add y las propiedades previstas; es evidencia facilitada, no una consulta AWS del agente.

## Principal y condiciones de publicación

La sección específica de AWS End User Messaging Social para temas SNS cifrados prescribe estos valores, que coinciden con nuestra plantilla:

| Campo | Valor resuelto para staging |
| --- | --- |
| Principal.Service | `social-messaging.amazonaws.com` |
| Action | `kms:GenerateDataKey*`, `kms:Decrypt` |
| StringEquals aws:SourceAccount | `365470560685` |
| ArnLike aws:SourceArn | `arn:aws:social-messaging:eu-west-1:365470560685:*` |

El patrón es el origen **Social**, no el ARN del tema SNS ni el ID numérico Meta. La política SNS permite al mismo servicio publicar en el tema concreto, con esas condiciones de origen. No hace falta añadir una concesión al principal SNS en la clave para este flujo. [Instrucción específica oficial de Social](https://docs.aws.amazon.com/social-messaging/latest/userguide/managing-event-destinations-add.html).

La guía general SNS concede las operaciones criptográficas al principal publicador del servicio de origen. La autorización específica anterior es coherente con ese modelo. [Guía SNS de claves y fuentes de eventos](https://docs.aws.amazon.com/sns/latest/dg/sns-key-management.html).

SNS sí aparece en nuestras políticas SQS con `sqs:SendMessage`, restringido al tema y cuenta. Las tres colas usan **SSE-SQS**, no la CMK del tema. No se trasladan permisos de una política SQS a la política de clave SNS. Esta conclusión no cubre un futuro cambio a colas con CMK propia, otro publicador, otra cuenta o una suscripción distinta. `Resource:"*"` dentro de esta política de clave se refiere a esa clave concreta. [Semántica de Resource en una key policy](https://docs.aws.amazon.com/kms/latest/developerguide/key-policy-overview.html).

## Creación sin bloqueo administrativo de diseño

El requisito de AWS para la política inicial es que quien llama a CreateKey pueda realizar posteriormente PutKeyPolicy; mantener la comprobación habilitada permite a KMS rechazar una política que bloquee al creador. Los principales referenciados deben existir y ser visibles para KMS. [CreateKey, Policy](https://docs.aws.amazon.com/kms/latest/APIReference/API_CreateKey.html).

La comprobación local confirma:

- El `RoleARN` de la solicitud preparada y `KmsAdministratorArn` son el mismo: `arn:aws:iam::365470560685:role/muserelay-aws-bridge-stage-cfn-execution`. **Confirmar también ese rol en el change set real de la consola.**
- La política IAM del ejecutor permite `kms:CreateKey` con las propiedades simétricas, región y etiquetas solicitadas en el YAML; permite también el etiquetado inicial. La actualización de metadatos no altera esas concesiones.
- La declaración `DedicatedKeyAdministration` concede directamente al ARN estable del ejecutor `kms:PutKeyPolicy` sobre la clave. No necesita una concesión IAM amplia adicional a todas las claves etiquetadas.
- `BypassPolicyLockoutSafetyCheck=false`, tanto en la plantilla como en la condición de creación IAM.
- El rol de recuperación conserva DescribeKey/GetKeyPolicy/PutKeyPolicy. Se mantiene además la delegación administrativa de cuenta limitada a esas tres operaciones.
- Los Deny del ejecutor siguen limitados a DisableKey y ScheduleKeyDeletion; no deniegan CreateKey ni PutKeyPolicy. Retain conserva la clave al retirar la pila.

CloudFormation hace las llamadas usando el rol de servicio seleccionado; el parámetro KmsAdministratorArn no lo selecciona por sí solo. [Rol de servicio CloudFormation](https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/using-iam-servicerole.html). El acceso directo de un rol mediante la key policy está documentado por KMS. [Evaluación del principal de la key policy](https://docs.aws.amazon.com/kms/latest/developerguide/determining-access-key-policy.html).

**Resultado:** configuración compatible y sin bloqueo administrativo identificado en el diseño. No equivale a haber creado o probado una clave en AWS. El agente no dispone de credenciales AWS para auditar independientemente el RoleARN remoto, políticas efectivas, boundaries/SCP o propagación IAM. Un change set disponible no prueba CreateKey ni entrega Social. No se debe desactivar la comprobación de bloqueo ni ampliar permisos automáticamente si AWS devuelve un error.

Validación: comprobaciones offline de plantilla/rol/condiciones, validadores existentes de foundation y transporte, y coherencia de solicitudes preparadas, todas PASS. [Evidencia de revisión](evidence/sns-kms-social-principal-review.json). No se modificaron plantillas, recursos AWS, permisos desplegados ni producción. El conjunto sigue sin ejecutar, pendiente de autorización separada.
