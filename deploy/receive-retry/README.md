# Ruta rápida: corrección SAM y runtime de recepción cerrado

26/10/2026, Europe/Paris. Preparación local, cero llamadas AWS. Esta ruta manual
continúa sin esperar la automatización BridgeTools. No aplicar las plantillas
Foundation/SSO nuevas de tools-temporary para resolver el bloqueo SAM actual.

## Ahora: GO técnico de la corrección IAM mínima

GO técnico limitado a la plantilla d60476... revisada y a las ocho comprobaciones
Original/DescribeChangeSet que comunica el propietario. No es evidencia de una
consulta AWS directa desde este servidor ni autorización del propietario para
ejecutar. La transcripción del ARN no se usa para construir comandos: seleccionar
cuenta, región, pila y nombre del conjunto exactos en CloudFormation.

- Cuenta 365470560685, eu-west-1.
- Pila `muserelay-aws-bridge-iam-foundation`.
- Conjunto `sam-transform-iam-review-d60476a283b7`.
- Un Modify StageCloudFormationRole, sin sustitución; GrantSocialSend=false.
- Única incorporación: cloudformation:CreateChangeSet sobre
  arn:aws:cloudformation:eu-west-1:aws:transform/Serverless-2016-10-31.
- Recuperación, confianzas, Retain y denegaciones KMS conservados.

El propietario debe autorizar expresamente ejecutar solo ese conjunto antes de
pulsar Ejecutar. No añadir otro service role a la pila IAM. Esperar UPDATE_COMPLETE.
Comprobar después mediante estas lecturas, sin mostrar valores de secretos:

```sh
aws cloudformation describe-stacks --region eu-west-1 \
  --stack-name muserelay-aws-bridge-iam-foundation \
  --query 'Stacks[0].{Status:StackStatus,RoleARN:RoleARN,Outputs:Outputs}' \
  --output json --no-cli-pager

aws iam get-role-policy --region eu-west-1 \
  --role-name muserelay-aws-bridge-stage-cfn-execution \
  --policy-name bridge-runtime-receive-provisioning \
  --query 'PolicyDocument.Statement[?Sid==`AllowAwsSamTransformInIreland`]' \
  --output json --no-cli-pager
```

El estado debe ser UPDATE_COMPLETE y la lectura mostrar la declaración exacta.
No se crean SNS, Lambda, consumidores, permisos Social ni recursos facturables
nuevos mediante esta corrección IAM. No ejecutar si el conjunto cambió o ya no
está disponible. Si falla, registrar el error y revisar antes de otros intentos.

## Después: preparar el conjunto de recepción, no ejecutarlo

Los tres archivos copiables de esta carpeta fijan la misma plantilla 881617... y
los mismos parámetros ya revisados. No reconstruir/subir el ZIP otra vez. El nuevo
nombre evita reutilizar el conjunto runtime FAILED anterior:

`receive-sam-review-88161733676d-82707086-fix1`

La preparación del conjunto está solicitada por el propietario, pero depende de
cerrar la corrección IAM. Desde CloudShell, después de ese cierre y de comprobar
cuenta/rol, descargar `change-set.prepared.json` del commit revisado y ejecutar:

```sh
aws cloudformation create-change-set --region eu-west-1 \
  --cli-input-json file://change-set.prepared.json --no-cli-pager
```

Esto crea el conjunto para revisión; no ejecuta el runtime. Usa TemplateBody para
evitar otra subida manual de plantilla. No añadir CAPABILITY_AUTO_EXPAND como
sustituto del permiso IAM ni ejecutar scripts con cambios adicionales.

Si se prefiere consola: seleccionar la pila existente muserelay-aws-bridge-stage,
Acciones de pila → Crear conjunto de cambios → Reemplazar plantilla → cargar
runtime.template.yaml; usar todos los valores de parameters.disabled.json y el
rol muserelay-aws-bridge-stage-cfn-execution. Desactivar conjuntos nested. La consola
puede almacenar el YAML en su bucket administrado; no cambia la release ZIP.
No usar Actualizar directamente ni Ejecutar como parte de esta preparación.

Esperar CREATE_COMPLETE / AVAILABLE y compartir Changes, Parameters, RoleARN y
Original. Solo cinco Add: State, InboundFunction, InboundLogs, SigningSecret y
InboundFunctionInput. Cero Modify/Remove/Replace del ingreso y ningún recurso IAM.
Mapping Enabled=false, MaximumConcurrency=2, sin reserva inbound; secreto vacío,
DeployOutbound=false, ProcessingEnabled/ReceiveEnabled/SendEnabled/LiveSend=false,
bindings disabled y destinatarios vacíos. Cada discrepancia detiene el proceso.
La ejecución del conjunto requerirá nueva autorización y su coste estimado.

## Recepción en la bandeja nativa: dependencias reales restantes

El paquete 0.5.1 preparado reutiliza NativeCore::incoming para Contact, Conversation,
Message e InboxBroadcaster; no se crea otra bandeja. Las pruebas previas con Core
aislado/PG/Redis son sintéticas, no recepción AWS. Producción conserva el paquete
0.4.0 deshabilitado según última revisión; no se ha actualizado en esta entrega.

Para la primera recepción no hacen falta agente, Bedrock, envío ni calendario.
Faltan, con sus revisiones/autorizaciones:

1. Preparar instalación acotada del conector y reconocimiento AWS en Channel,
   comprobando Core HEAD, cambios concurrentes, backup/restauración y rollback.
2. Configurar la organización demo existente (227/workspace312, por reverificar),
   un canal nativo WhatsApp de transporte AWS y su binding, sin conceder otras
   organizaciones. La UI AWS en Megadmin/Canales sigue pendiente de implementación;
   habilitar el loader actual no crea ese formulario automáticamente.
3. Elegir/revisar el endpoint HTTPS de la instalación actual para la demo. El YAML
   de esta entrega solo admite el hostname técnico temporal, aún no verificado.
   El endpoint final del Core requiere un allowlist de URL revisado y un UPDATE
   posterior antes de activar, no crear otro SaaS ni activar DNS por inferencia.
4. Inicializar material HMAC dedicado, probar firma/rechazo/scope sin datos reales
   y verificar acceso del evaluador a su inbox. No usar claves Bedrock ni Admin.
5. Solo después autorizar consumidor y drenaje limitado, incluyendo el backlog SQS
   existente. No ReceiveMessage, purgas o selección de eventos por esta preparación.
   Mostrar mensaje real en la bandeja nativa con trazabilidad y sin activar agente
   ni salida. Desactivación pausa consumidor y controles Core; no borra mensajes.

Respuesta humana → AWS, agente Bedrock y ticket con confirmación son fases siguientes.
No presentarlas como completadas por desplegar la infraestructura de recepción.

Fuente oficial del procedimiento de conjunto:
https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/using-cfn-updating-stacks-changesets-create.html
