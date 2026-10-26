# HOLD: coste cerrado y dependencia PITR omitida

**Candidata sustituida:** la [revisión consolidada de proveedores](../runtime-provider-iam/README.md)
incluye lectura/estabilización y etiquetas, además de PITR. No aplicar esta versión
de dos acciones por separado; se conserva como evidencia del hallazgo.

26/10/2026, Europe/Paris. El propietario comunica quince PASS para el conjunto
receive-sam-review-88161733676d-82707086-fix1. Es evidencia de integridad de
configuración/rol, no prueba de cobertura de APIs del proveedor CloudFormation.
No se ha ejecutado el conjunto ni cambiado AWS/Core desde este servidor.

## Resultado de revisión

NO-GO temporal de ejecución. El runtime State contiene
PointInTimeRecoveryEnabled=true. Al preparar el ejecutor se omitieron
dynamodb:UpdateContinuousBackups y dynamodb:DescribeContinuousBackups sobre
la tabla muserelay-aws-bridge-stage-state. Ninguna política revisada los concede
y el propietario verificó que no hay managed policies adjuntas. Es una omisión
de preparación; no se debe resolver ejecutando con AdministratorAccess.

El proveedor necesita UpdateContinuousBackups para habilitar PITR y
DescribeContinuousBackups para consultar su estado. Son operaciones distintas
de CreateTable/UpdateTable/DescribeTable. Los quince PASS anteriores solo
comparaban el ejecutor con la política que habíamos preparado; esa igualdad
no detectaba el permiso funcional ausente. Se añade un guard separado al
verificador para no volver a confundir ambas cosas.

## Corrección mínima candidata, sin aplicar

`template.proposed.yaml` es la Foundation completa basada en d60476..., añadiendo
una declaración de dos acciones al inline bridge-runtime-receive-provisioning del
StageCloudFormationRole. `policy.addition.json` y `executor-policies.diff`
permiten revisar el cambio completo. Retirar esa única declaración reconstruye
exactamente la plantilla base. Esperado: un Modify del ejecutor, sin sustitución,
sin cambios en recuperación, tres roles runtime, confianza, Retain o KMS Deny;
GrantSocialSend=false. La tabla/región son exactas, sin acceso a datos ni otras tablas.

**Esta es una corrección candidata del bloqueo confirmado, no un certificado de
que cubra todas las dependencias del proveedor.** El snapshot de handlers de
cfn-lint conserva las listas create/read/update/delete de cinco tipos. Incluyen
permisos para características opcionales no seleccionadas: no conceder su unión
completa ni añadir IAM, importaciones, Kinesis o lectura de datos por comodidad.
También declara lecturas de metadatos adicionales de tabla/Lambda/logs y tags de
mapping; hay que revisar su necesidad y alcance antes de cerrar una versión IAM
definitiva y solicitar su ejecución. No encadenar cambios IAM improvisados.

Antes de presentar la corrección definitiva, contrastar los handlers actuales
mediante DescribeType en eu-west-1 y revisar las APIs requeridas por las propiedades
seleccionadas. Es solo metadatos y no crea recursos. La fuente del snapshot es la
caché local de los schemas oficiales usados por cfn-lint, no una lectura de la
versión activa del registro de la cuenta. No se ha consultado AWS desde aquí.

Una vez revisada la corrección definitiva, requiere un nuevo conjunto y autorización
específica de IAM Foundation. No sustituir el YAML del runtime para esconder PITR,
borrar el conjunto ya preparado, repetir subidas ni ejecutar usando el operador.
La pila Foundation es distinta: su actualización por sí misma no modifica la pila
runtime; tras ella hay que volver a comprobar que el conjunto actual sigue AVAILABLE,
que su Original/Parameters siguen idénticos y revisar las nuevas políticas efectivas.
La aprobación de ejecución del runtime sigue pendiente.

## Coste incremental de los cinco recursos, apagados

Supuestos: mapping Disabled, ninguna invocación ni consumidor alternativo, sin datos
de aplicación en State, sin valor de firma ni logs de ejecución, sin provisioned
concurrency, sin API/outbound y sin nuevas claves KMS administradas por el cliente.
No se descuentan créditos/promociones para estimar el pequeño coste fijo.

| Recurso | Coste orientativo apagado |
| --- | --- |
| SigningSecret | Presupuestar USD 0.40/mes, prorrateado por horas; API USD 0.05/10000 solicitudes |
| InboundFunction | Sin coste de ejecución mientras no se invoque; creación/código no implica ejecución continua |
| InboundFunctionInput | Sin consumo/polling SQS mientras Disabled; no provisioned pollers seleccionados |
| InboundLogs | Sin datos ingeridos/almacenados de aplicación, coste de logs aproximadamente cero |
| State | On-demand: sin lecturas/escrituras de aplicación, sin coste de capacidad reservada. Storage/PITR por volumen; tabla inicialmente vacía |

Tarifas públicas regionales consultadas: DynamoDB Standard storage, más allá del
tramo gratuito si aplica, USD 0.283/GB-mes; PITR USD 0.22/GB-mes. State usa
SSEEnabled=true, que selecciona la clave AWS-managed alias/aws/dynamodb, con posibles
solicitudes KMS facturables; no es otra customer-managed key de USD 1/mes. No asumir
que mantener datos/cifrado es gratuito solo porque el consumidor esté apagado.

Previsión para este estado vacío: alrededor de USD 0.40/mes más solicitudes
pequeñas de aprovisionamiento/metadatos/cifrado. Presupuesto prudente orientativo:
USD 1/mes incremental, o aproximadamente USD 0.03–0.07 por dos días apagado.
No es un límite de facturación ni incluye impuestos, recursos existentes,
restauraciones, invocaciones, datos posteriores o nuevas funciones de auditoría.
Una activación futura exige otra estimación de uso (mensajes/Bedrock incluidos).

State y SigningSecret tienen Retain: apagar/eliminar recursos de procesamiento
no los borra ni garantiza detener sus costes. No ejecutar una eliminación para
ahorrar céntimos; documentar y autorizar cleanup selectivo cuando corresponda.

Fuentes oficiales:
- https://aws.amazon.com/secrets-manager/pricing/
- https://pricing.us-east-1.amazonaws.com/offers/v1.0/aws/AWSSecretsManager/current/eu-west-1/index.json
- https://aws.amazon.com/dynamodb/pricing/
- https://pricing.us-east-1.amazonaws.com/offers/v1.0/aws/AmazonDynamoDB/current/eu-west-1/index.json
- https://aws.amazon.com/lambda/pricing/
- https://aws.amazon.com/cloudwatch/pricing/
- https://docs.aws.amazon.com/AWSCloudFormation/latest/TemplateReference/aws-properties-dynamodb-table-ssespecification.html
- https://docs.aws.amazon.com/amazondynamodb/latest/APIReference/API_UpdateContinuousBackups.html
- https://docs.aws.amazon.com/amazondynamodb/latest/APIReference/API_DescribeContinuousBackups.html
- https://docs.aws.amazon.com/cloudcontrolapi/latest/userguide/resource-types.html
