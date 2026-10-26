# Corrección conjunta del ejecutor para recepción cerrada

26/10/2026, Europe/Paris. **Preparación local, no aplicada ni creada en AWS.**
Sustituye la candidata PITR de solo dos acciones. El propietario comunica que
DescribeType de los cinco tipos funcionó, pero no ha pegado el JSON íntegro con
acciones/versiones; no se atribuye a esa comunicación una comparación en vivo.
Se han contrastado el schema local oficial usado por cfn-lint y la documentación
actual de autorización AWS. La versión publicada del registro debe comprobarse
con el resumen compacto descrito al final, antes de autorizar ejecución IAM.

## Conclusión de alcance

Los dos permisos PITR arreglan el bloqueo confirmado, **pero no cubren el contrato
de lectura/estabilización y etiquetas de los cinco proveedores**. El listado
Create de un proveedor mezcla características opcionales. Esta propuesta añade
las operaciones de PITR seleccionadas, las lecturas declaradas por Read sobre
los recursos propios y el soporte de tags del mapping. No concede todas las APIs
Create/Update de los schemas ni afirma que todas las lecturas se invoquen siempre
en cada rama interna del proveedor. Es el mínimo conservador para soportar ese
contrato de Read, no una traza observada de llamadas ni una garantía de ejecución.

| Recurso y propiedades seleccionadas | Concesiones nuevas y motivo |
| --- | --- |
| State: PAY_PER_REQUEST, HASH pk, TTL, SSE con clave AWS-managed por defecto, PITR=true, sin importación/replicas/Kinesis | DescribeContinuousBackups + UpdateContinuousBackups para PITR; DescribeContributorInsights, DescribeKinesisStreamingDestination y GetResourcePolicy para Read del proveedor. No habilitan esas características ni leen items. |
| InboundFunction: ZIP S3 AES256, ARM64/nodejs22.x, rol existente, sin VPC/layers/URL/custom KMS/provisioned concurrency | GetFunctionRecursionConfig, GetRuntimeManagementConfig y GetFunctionScalingConfig, solo sobre la función inbound. Son lecturas de configuración declaradas por Read, no permisos de activar scaling/runtime/capacity providers. |
| InboundFunctionInput: SQS, Enabled=false, BatchSize=1, MaximumConcurrency=2, sin KMS de payload ni pollers provisionados | TagResource para las etiquetas de sistema que CloudFormation aplica al crear; ListTags para Read. Scoped por stack/logical-id, según detalle inferior. No se añade Invoke, ReceiveMessage, envío ni activación. |
| InboundLogs: grupo propio, retention=7, tags; sin DataProtection/FieldIndex/ResourcePolicy/KMS seleccionados | GetDataProtectionPolicy sobre ese grupo; DescribeIndexPolicies y DescribeResourcePolicies regionales para Read, aun sin configurar políticas. No Put/Delete policies ni lectura del contenido de logs. |
| SigningSecret: namespace de staging, vacío, sin GenerateSecretString/SecretString/KMS propio/replicación | GetSecretValue, declarado por Read, solo sobre el secreto propio y con tags Bridge/staging. Sin GetRandomPassword, PutSecretValue, UpdateSecret o ReplicateSecret. |

Total: **14 concesiones acción/recurso en siete declaraciones añadidas** al inline
`bridge-runtime-receive-provisioning`. TagResource/ListTags ya existían para la
función; esta adición solo añade su alcance acotado de mapping. Las otras doce
acciones no estaban permitidas por los inline anteriores. Los roles runtime,
recuperación, confianza, permisos antiguos, Retain y denegaciones KMS quedan
idénticos. GrantSocialSend=false. Ninguna modificación de permisos Bedrock/Core.

## Dos excepciones de alcance para aprobar expresamente

1. Logs DescribeIndexPolicies/DescribeResourcePolicies no admiten resource-level
   ARN en la referencia de autorización AWS. Se usan con Resource=* y
   aws:RequestedRegion=eu-west-1. Permiten ver metadatos de políticas regionales,
   potencialmente de otros grupos; **no** están limitados ficticiamente al nombre
   del grupo y **no** permiten leer mensajes de logs o cambiarlos. Ya existía
   DescribeLogGroups regional. Si no se acepta esta visibilidad, no afirmar que
   el contrato Read está cubierto: necesitaría otra estrategia/revisión.
2. GetSecretValue no es solo metadatos. Lo obtiene únicamente el rol de servicio
   CloudFormation, cuya confianza no cambia, sobre
   muserelay-aws-bridge-stage/signing-?????? con Component=aws-engagement-bridge y
   Environment=staging. No se concede a la herramienta/usuario de auditoría ni
   se llama desde los scripts de revisión. El despliegue crea un secreto sin
   valor, pero si se poblase después, ese rol podría leerlo. Esta capacidad debe
   aceptarse al revisar la propuesta; no ocultarla como una lectura inocua.
   La cuenta/namespace son de demostración; nada de secretos de Bedrock/Core.

El schema marca SecretString y GenerateSecretString como write-only, pero declara
GetSecretValue en Read. No se deduce que leer el valor sea siempre necesario para
un secreto vacío, ni se deduce lo contrario de que la plantilla no tenga valor.
Se incluye por el contrato declarado del proveedor para evitar un nuevo fallo de
estabilización. No autoriza inicializar material de firma ni leerlo manualmente.

## Etiquetas del mapping: no reutilizar lambda:FunctionArn donde no se soporta

Create/Get/Update/Delete mapping siguen con el condicionante de función existente.
TagResource/ListTags tienen permisos y condition keys distintos. La adición usa:

- TagResource sobre arn:aws:lambda:eu-west-1:365470560685:event-source-mapping:*,
  solo si RequestTag aws:cloudformation:stack-name=muserelay-aws-bridge-stage y
  logical-id=InboundFunctionInput, además de región Irlanda.
- ListTags sobre el mismo patrón UUID, solo si ResourceTag de sistema acredita
  ese stack y logical-id. UUID desconocido hasta la creación; no se inventa.

CloudFormation aplica esos system tags a recursos taggables. No se concede
TagResource sin condiciones a todos los mappings, ni se usa FunctionArn en APIs
que no lo documentan. No se añade UntagResource del mapping: no hay Tags del
usuario que actualizar en esta entrega y Delete no lo declara. El contexto real
de request/resource tags deberá aceptarlo AWS al desplegar; una simulación con
contexto sintético no prueba que el servicio lo aporte. Si se rechaza, detener y
revisar el evento exacto, no retirar las condiciones ni ampliar a todos los UUID.

## Permisos excluidos deliberadamente

No APIs de items DynamoDB, Query/Scan, ImportTable, réplicas, Kinesis streaming,
UpdateContributorInsights, Put/DeleteResourcePolicy, nuevas APIs IAM/PassRole,
VPC/EC2/EFS, capas/capacity providers, Lambda Invoke/FunctionURL, KMS grants/decrypt
generales, logs GetLogEvents/FilterLogEvents/Insights/LiveTail, Secret Put/replica,
SQS Receive/Send/Purge, SNS Publish, Social ni Bedrock. Claves por defecto de los
servicios no justifican conceder KMS:*; no hay customer KMS en Code/env/secret/mapping.
No se cambian las denegaciones kms:DisableKey y kms:ScheduleKeyDeletion.

## Archivos y conjunto preparado

- `template.yaml`: Foundation completa candidata.
- `policy.additions.json`: siete declaraciones exactas añadidas.
- `executor-policies.before.json`, `after.json`, `.diff`: comparación completa.
- `review.json`: hashes, identificadores, alcance, excepciones y límites.
- `provider-iam.change-set.prepared.json`: solicitud UPDATE, sin RoleARN para la pila IAM,
  GrantSocialSend=false y CAPABILITY_NAMED_IAM. No contiene ExecuteChangeSet.

SHA256 del YAML:

```text
cb4d602f2d9067c4271c6789fd2c1ce8716d28aadae2caa6421aa81d55565e98
```

Conjunto propuesto: `runtime-provider-iam-review-cb4d602f2d90`, pila
`muserelay-aws-bridge-iam-foundation`, cuenta 365470560685, región eu-west-1.
El baseline es SAM d60476... ya comunicado como desplegado. No usar el YAML
anterior PITR de dos acciones, ni tools-temporary Foundation/SSO, ni b2a87....

La suma compacta de ambas políticas inline (incluidos wrappers/nombres como cota
conservadora) es 9789 caracteres, bajo el límite agregado IAM de 10240 por rol.
No adjuntar otra política ni repartir por inline para saltar el límite: es agregado.

## Procedimiento: preparar y revisar, no ejecutar

1. Revisar las excepciones, diff y baseline; contrastar el schema actual con el
   modo --provider-summary del verificador actualizado. Un mismatch exige revisar
   el JSON completo/versiones, no cambiar IAM automáticamente.
2. Desde CloudShell AdministratorAccess, descargar la solicitud de este commit y
   crear únicamente el conjunto de revisión:

```sh
aws cloudformation create-change-set --region eu-west-1 \
  --cli-input-json file://provider-iam.change-set.prepared.json --no-cli-pager
```

3. Exigir CREATE_COMPLETE / AVAILABLE, un único Modify StageCloudFormationRole,
   Replacement=False, sin otros cambios ni nuevo service role. Si Policies se
   trunca, obtener Original actual/propuesta y comparar íntegramente con ambos
   hashes del review.json; no aprobar por firma truncada. La identidad de ejecución
   Foundation se verifica con DescribeStacks y solicitud, no RoleARN null de
   DescribeChangeSet. El dueño opera como SSO administrador sin asignar el ejecutor
   limitado a la pila IAM. La ejecución necesita autorización específica posterior.
4. Tras autorizar/aplicar Foundation, exigir UPDATE_COMPLETE y nueva lectura de
   inline/policies/trust. Las nuevas políticas deben coincidir con la propuesta,
   sin managed policies/boundary inesperados. No inferir permisos por la plantilla.
5. Volver a revisar el conjunto runtime actual receive-sam-review-881617...-fix1:
   sigue sin autorizarse su ejecución; no borrarlo/recrearlo si continúa AVAILABLE
   y Original/params/rol/changes coinciden. Una actualización Foundation no modifica
   la pila runtime. El verificador actualizado comprobará la nueva política íntegra
   y sus dependencias en el siguiente gate. Se conserva runtime SHA881617..., ZIP
   S3 y los cinco Add. Nada de consumidores, firmas, agentes, envíos o Core.

El coste incremental apagado no cambia por añadir permisos: alrededor de USD0.40/mes
más operaciones pequeñas; presupuesto orientativo USD1/mes (no cap). Ver el informe
anterior de costes. La aceptación real de AWS, propagación, boundaries/SCP y contexto
de tagging no se certifican con pruebas locales. No se garantiza cero incidencias;
se han revisado anticipadamente las dependencias declaradas en lugar de conceder
una unión de todos los permisos opcionales.

Fuentes oficiales consultadas:
- https://docs.aws.amazon.com/cloudcontrolapi/latest/userguide/resource-types.html
- https://docs.aws.amazon.com/service-authorization/latest/reference/list_dynamodb.html
- https://docs.aws.amazon.com/service-authorization/latest/reference/list_lambda.html
- https://docs.aws.amazon.com/service-authorization/latest/reference/list_logs.html
- https://docs.aws.amazon.com/whitepapers/latest/tagging-best-practices/implementing-and-enforcing-tagging.html
- https://docs.aws.amazon.com/AWSCloudFormation/latest/TemplateReference/aws-resource-lambda-eventsourcemapping.html
- https://docs.aws.amazon.com/AWSCloudFormation/latest/TemplateReference/aws-resource-secretsmanager-secret.html
