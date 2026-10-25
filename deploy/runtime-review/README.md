# Runtime de recepción: conjunto UPDATE preparado, sin ejecutar

**Revisión por cuota completada localmente:** el propietario ha comunicado ConcurrentExecutions=10, UnreservedConcurrentExecutions=10 y cero funciones. No desplegar la plantilla anterior de hash `3e82e39dd7aa...` ni el draft receive-review-62dab78f: reservaban una concurrencia. La nueva plantilla elimina esa reserva para inbound y limita su mapping a dos ejecuciones, manteniéndolo deshabilitado. Los identificadores actuales se detallan debajo. No se ha cambiado AWS ni solicitado un aumento de cuota.

Preparación del 25/10/2026 (Europe/Paris). El propietario comunica IAM Foundation en UPDATE_COMPLETE y las tres políticas inline de runtime verificadas, sin permisos Social/Bedrock/AdministratorAccess. También comunicó que las dos plantillas Original de IAM coincidían con las referencias revisadas antes de ejecutar. Es evidencia facilitada desde su CloudShell, no una consulta AWS realizada por este servidor. La revisión de políticas administradas adjuntas, boundaries/SCP y cuota Lambda queda pendiente.

**Estado de esta entrega:** solicitud completa preparada localmente, cero llamadas AWS, sin upload S3, sin conjunto creado en AWS, sin despliegue ni cambios de MuseRelay. La solicitud propone recursos para revisión; no es autorización para crearlos. El ZIP y la plantilla conservan los hashes revisados. No se cambia el YAML raíz ni la plantilla IAM ya desplegada.

## Archivos y versión

- YAML: [`template.yaml` de la raíz](../../template.yaml), fijado al commit `82707086af6e0597d741c3e44e7ce843c074a326`.
- SHA256 YAML: `88161733676dae8d788eff092cf75c6430c5ca28860e7f7048804e5ce3f694ac`.
- [Parámetros completos, todos explícitos](parameters.disabled.json).
- [Solicitud CreateChangeSet preparada](../runtime-iam/change-set.receive-runtime.prepared.json): incluye TemplateBody, parámetros y RoleARN; no ejecutarla automáticamente.
- [Manifiesto de alcance y requisitos pendientes](manifest.prepared.json).
- [Validación SAM offline con estos parámetros](validation.offline.json).

La solicitud anteriormente tenía un marcador de artefacto pendiente. Ahora referencia un paquete **real, local y fijado**. Eso no significa que el objeto exista ya en S3. No subirlo ni reconstruir una alternativa como InlineCode sin autorización.

| Identificador | Valor |
| --- | --- |
| Cuenta | `365470560685` |
| Región | `eu-west-1` |
| Pila EXISTENTE que se actualizaría | `muserelay-aws-bridge-stage` |
| Nombre propuesto del conjunto | `receive-runtime-review-88161733676d-82707086` |
| Rol de servicio CloudFormation | `arn:aws:iam::365470560685:role/muserelay-aws-bridge-stage-cfn-execution` |
| ZIP local privado | `/var/tmp/aws-bridge-receive-quota10-reviewed/bridge.zip` |
| Commit del paquete | `82707086af6e0597d741c3e44e7ce843c074a326` |
| SHA256 ZIP | `9e377f798018aae44aa7aa93a6e9eb3887d991a1347b87b2a2d0a725826747db` |
| Bucket previsto, ya existente según la etapa anterior | `muserelay-aws-bridge-stage-artifacts-365470560685-eu-west-1` |
| Clave S3 prevista | `releases/82707086af6e0597d741c3e44e7ce843c074a326/bridge.zip` |

El ZIP contiene únicamente `handlers.mjs`, no el Core, credenciales ni configuración privada. No confundirlo con el ZIP de descarga de código fuente de GitHub. Su checksum se validó y una prueba negativa confirmó que se rechaza un ZIP alterado.

Checksum SHA256 completo esperado en base64 para S3: `njd/eYAYquRKp6qTpunrOIfZkaE0e4eyotCnJYJnR9s=`.

## Cinco Add esperados; los doce recursos actuales se conservan

La expansión SAM se hizo sin red, con los parámetros exactos de esta solicitud. Selecciona **17 recursos**:

| Recurso lógico nuevo | Tipo después de SAM | Estado previsto |
| --- | --- | --- |
| `State` | AWS::DynamoDB::Table | Tabla de estado, PAY_PER_REQUEST, Retain |
| `InboundFunction` | AWS::Lambda::Function | Node.js 22 / arm64, 256 MB, timeout 30 s, sin reserva de concurrencia, rol inbound existente |
| `InboundLogs` | AWS::Logs::LogGroup | Grupo propio, retención 7 días |
| `SigningSecret` | AWS::SecretsManager::Secret | Sin valor ni generación automática; Retain |
| `InboundFunctionInput` | AWS::Lambda::EventSourceMapping | **Enabled=false**, MaximumConcurrency=2, batch 1, respuestas parciales |

No se seleccionan API Gateway, funciones enqueuer/sender, colas outbound, secreto outbound, roles IAM ni recursos Bedrock/agente. Los doce recursos originales —SNS/KMS/SQS/S3 y sus políticas/suscripción— son estructuralmente idénticos al bootstrap publicado. La diferencia frente a la pila real debe comprobarse en AWS: se esperan **cinco Add, cero Modify, cero Remove y cero sustituciones**. Si aparecen otros cambios, detenerse y revisar antes de ejecutar. No aprobar por mero recuento sin revisar identidades y propiedades.

## Parámetros y controles

| Parámetro | Valor |
| --- | --- |
| DeployProcessing | `true`: incluir recursos en la propuesta; no significa activar procesamiento |
| DeployOutbound | `false` |
| ProcessingEnabled | `false` |
| ReceiveEnabled | `false` |
| SendEnabled | `false` |
| LiveSend | `false` |
| SecretArn / OutboundSecretArn | Vacíos: se propone crear solo el secreto inbound vacío |
| MetaApiVersion | Vacío: no se despliega emisor ni se envía a Meta |
| RunId | `phase4_receive_review`, no evidencia de conversación real |
| DailyInboundLimit / DailySendLimit | `6` cada uno, límites de aplicación para una futura prueba; no son límites monetarios |
| CoreBaseUrl | `https://aws-bridge-staging.muserelay.com` |

Los tres ARN de roles runtime y los dos ARN KMS administrativos están explicitados en `parameters.disabled.json`. `GrantSocialSend` **no es parámetro de esta pila**: pertenece a IAM Foundation y debe conservarse allí en false; esta actualización no modifica esa pila.

BindingsJson contiene una sola referencia técnica `screenart-demo`, con el teléfono AWS confirmado, el tema existente y `enabled=false`, `allowedRecipients=[]`. No contiene secretos ni IDs de organización de producción. La organización autorizada sigue siendo **ninguna** en esta preparación. El nombre del binding no acredita la existencia o propiedad de una organización.

Actualización durante la preparación: el propietario comunicó la creación de su usuario demo. Una consulta directa con PostgreSQL en modo read-only confirmó organización **227**, nombre real **Screen Art Demo Workspace**, no reseller, workspace **312**, propiedad coincidente con ese usuario y cero canales. No se le otorgó acceso al Bridge ni se probó una sesión autenticada. La membresía del workspace consta activa; el pivot de organización conserva estado pending. La revisión estática de EnsureTenantMember, EnsureWorkspaceRole y User confirma que reconocen al propietario directo por owner_id, por lo que ese pivot no demuestra por sí solo un bloqueo; no se alteró su estado. No se copió ningún correo/contraseña ni fila de usuario a los datos de staging o al repositorio. Los ID son metadatos de ámbito previsto, no una autorización efectiva.

El agente no se despliega en esta pila ni se activa el agente del Core. El módulo de producción permanece intacto y desactivado; el conector preparado 0.5.1 no está instalado por esta entrega.

## Bandeja nativa y endpoint: requisito posterior explícito

La bandeja final será la de MuseRelay actual, utilizando una organización nueva Screen Art Demo y un usuario normal propietario/evaluador. No se crea otra bandeja comercial, ni otra instalación permanente.

El controlador de canales de producción todavía solo admite meta/telnyx en managed_provider y el formulario AWS no está implementado. Habilitar el catálogo del módulo no añade ese formulario ni limita automáticamente al propietario a crear solo canales AWS. Ese requisito necesita una revisión específica de UI/backend y alcance por organización. La recepción preparada materializa contactos/conversaciones/mensajes nativos; agentes, herramientas, intervención humana y envío siguen siendo pasos posteriores con sus propios controles. El canal AWS conserva transporte propio, sin fallback a Meta directo.

La gestión debe integrarse en la sección actual **Megadmin → Módulos**, que ya enlaza al cargador de extensiones. La [revisión de concepto y límites de autorización](../../docs/MEGADMIN-BRIDGE-INTEGRATION-CONCEPT.md) documenta el encaje; no se ha aplicado todavía en producción.

El hostname técnico de staging sigue siendo el único permitido por el YAML actual. Es un destino temporal de pruebas, todavía no activado ni verificado. Para llevar el runtime al endpoint del Core actual habrá que revisar el allowlist CoreBaseUrl, el hook, TLS/HMAC y la instalación del conector; no basta con cambiar una base de test por la de producción. Esta propuesta deshabilitada no envía callbacks y no exige activar DNS/HTTPS ahora. No presentar esta infraestructura como un flujo funcional de extremo a extremo.

## Lecturas pendientes antes de autorizar una ejecución

Desde CloudShell AdministratorAccess de Screen Art, los siguientes comandos consultan identidad, confianza, boundaries y políticas adjuntas, sin leer mensajes ni valores de secretos. No modifican IAM ni invocan Lambda. Si alguna lectura falla, compartir el error; no ampliar permisos.

```sh
aws sts get-caller-identity --region eu-west-1 --query '{Account:Account,Arn:Arn}' --output json --no-cli-pager

for BRIDGE_REVIEW_ROLE in muserelay-aws-bridge-stage-inbound-runtime muserelay-aws-bridge-stage-enqueue-runtime muserelay-aws-bridge-stage-sender-runtime muserelay-aws-bridge-stage-cfn-execution; do
  aws iam get-role --region eu-west-1 --role-name "$BRIDGE_REVIEW_ROLE" --query 'Role.{Name:RoleName,Arn:Arn,Boundary:PermissionsBoundary,Trust:AssumeRolePolicyDocument}' --output json --no-cli-pager
  aws iam list-attached-role-policies --region eu-west-1 --role-name "$BRIDGE_REVIEW_ROLE" --output json --no-cli-pager
  aws iam list-role-policies --region eu-west-1 --role-name "$BRIDGE_REVIEW_ROLE" --output json --no-cli-pager
done

aws iam get-role-policy --region eu-west-1 --role-name muserelay-aws-bridge-stage-cfn-execution --policy-name bridge-runtime-receive-provisioning --output json --no-cli-pager
aws lambda get-account-settings --region eu-west-1 --output json --no-cli-pager
aws cloudformation get-template --region eu-west-1 --stack-name muserelay-aws-bridge-stage --template-stage Original --output json --no-cli-pager > bridge-ingress-current.template-response.json
```

Se esperan políticas administradas adjuntas vacías en esos roles y ninguna boundary según lo declarado, pero **no se ha verificado todavía**. Las listas inline esperadas son solo bridge-runtime para los tres runtimes, y bridge-stage-scoped más bridge-runtime-receive-provisioning para el ejecutor. Si hay políticas adicionales, revisar sus versiones y alcance antes de concluir los permisos efectivos. Las SCP/RCP y políticas de recurso no quedan certificadas con ese listado.

La función inbound ya no configura ReservedConcurrentExecutions. Usa el pool no reservado y su mapping SQS tiene MaximumConcurrency=2 (mínimo admitido para este control), sin pollers provisionados. Es una configuración compatible a nivel de plantilla con el dato comunicado de 10 unidades no reservadas; no certifica permisos ni despliegue. MaximumConcurrency limita este origen SQS, no las invocaciones manuales ni toda la cuenta, y no impone orden entre mensajes. Cuando se active, las entregas pueden duplicarse y concurrir: mantener idempotencia y serialización por conversación en Core. Este ajuste desactiva la optimización de polling de baja actividad y puede aumentar las peticiones SQS al activar el mapping. Las funciones outbound futuras conservan su reserva 1, pero no se seleccionan ahora y requieren otra revisión de cuota. No solicitar aumento ni modificar otras funciones para esta propuesta de recepción.

El objeto S3 aún no ha sido subido por esta preparación. Solo si se comunica una subida autorizada, comprobar su existencia/checksum sin descargar ni ejecutar código:

```sh
aws s3api head-object --region eu-west-1 --bucket muserelay-aws-bridge-stage-artifacts-365470560685-eu-west-1 --key releases/82707086af6e0597d741c3e44e7ce843c074a326/bridge.zip --expected-bucket-owner 365470560685 --checksum-mode ENABLED --query '{Bytes:ContentLength,ChecksumSHA256:ChecksumSHA256,ChecksumType:ChecksumType,Metadata:Metadata}' --output json --no-cli-pager
```

No interpretar un 403 como prueba de inexistencia. El checksum SHA256 S3 se expresa en base64; debe convertirse el hash hexadecimal esperado o calcularse sobre el ZIP original para compararlo. Un ETag o un campo de metadata no sustituyen esa comprobación criptográfica. La subida futura debería almacenar checksum SHA256 completo; si no está disponible, revisar un método de verificación antes de ejecutar. No usar un ZIP de otro commit bajo esta misma clave.

## Preparación desde consola, sin ejecutar

1. Cuenta `365470560685`, región Irlanda, sesión SSO administrativa. Guardar plantilla/parámetros actuales de `muserelay-aws-bridge-stage`; no tocar IAM Foundation.
2. Seleccionar esa pila → Acciones de pila → Crear conjunto de cambios → Sustituir plantilla → cargar el `template.yaml` raíz fijado al commit indicado.
3. Nombre `receive-runtime-review-88161733676d-82707086`. Copiar todos los valores de `parameters.disabled.json`; no reutilizar valores vacíos de DeployProcessing del bootstrap. Confirmar los cinco controles false y DeployOutbound=false. DeployProcessing=true únicamente describe los recursos propuestos.
4. Permisos: conservar/seleccionar el rol `muserelay-aws-bridge-stage-cfn-execution`. A diferencia de la actualización IAM anterior, **esta pila sí usa ese rol**. No usar los roles de ejecución Lambda como rol de CloudFormation.
5. Revisar la transformación SAM. La solicitud JSON reconoce CAPABILITY_AUTO_EXPAND; no incorpora CAPABILITY_NAMED_IAM ni crea roles. Una solicitud que use Capabilities no debe añadir simultáneamente ResourceTypes. Conservar etiquetas/opciones de pila. Si la consola plantea capacidades/recursos distintos, revisar antes de avanzar.
6. Crear únicamente el conjunto para revisión. Se referencia la clave S3 prevista, pero el objeto no está verificado ni subido: si AWS rechaza la preparación por esa ausencia, detenerse, compartir el error y solicitar autorización específica para subir el artefacto. No improvisar otra fuente de código. En todo caso el ZIP debe existir y estar verificado antes de cualquier ejecución.
7. Esperar CREATE_COMPLETE / AVAILABLE. Revisar cinco Add y los nombres/tipos previstos; **Enabled=false** en el mapping; rol inbound correcto; entorno BRIDGE_RUNTIME_ENABLED/BRIDGE_RECEIVE_ENABLED/BRIDGE_SEND_ENABLED/BRIDGE_LIVE_SEND todos false; secreto sin valor; ninguna sustitución, modificación ni eliminación del ingreso.
8. Si una propiedad aparece truncada, obtener GetTemplate Original y Processed del conjunto por su nombre y pila para comparar. No basarse solo en el contador de cambios.
9. Compartir estado, nombre/ARN completo, cambios y parámetros. **No pulsar Ejecutar**. La ejecución del runtime requiere autorización separada después de cerrar artefacto, cuota y permisos.

Crear un conjunto no instala los recursos del runtime. La carga de una plantilla por la consola puede almacenar ese archivo en un bucket de CloudFormation; no equivale a subir el ZIP de Lambda. La infraestructura existente sigue recibiendo eventos SNS/SQS independientemente del Bridge; no publicar ni consumir pruebas durante esta revisión.

## Coste y reversión

Esta preparación local no incurre en uso AWS. Después de una futura ejecución autorizada, el recurso adicional de coste fijo más visible será el secreto, a una tarifa orientativa de USD 0.40/mes prorrateada (aprox. USD 0.053 por cuatro días), más solicitudes. Añadir almacenamiento S3 y los consumos reales de DynamoDB/logs/etc.; deshabilitar el mapping evita su polling automático, pero no impide invocaciones manuales autorizadas por otros actores ni tráfico al ingreso ya existente. No prometer coste cero ni una factura máxima. No se añade otra clave KMS; el coste de la existente continúa separado.

Para una futura prueba de recepción de pocos mensajes, el presupuesto orientativo previo de USD 1 incremental / USD 5 de reserva sigue siendo una estimación para revisar, no una autorización ni un límite AWS. No incluye mensajes imprevistos, impuestos, infraestructura existente, Bedrock o envíos. Antes de autorizar gasto revisar precios vigentes de Irlanda y el alcance real.

Reversión de la preparación: descartar el conjunto no ejecutado no cambia los recursos. Tras una futura ejecución: mantener consumidores/gates off, conservar ingreso y evidencia, y preparar un UPDATE revisado al bootstrap original. State y SigningSecret son Retain: no asumir que desaparecen ni quedan revocados al retirarlos. Planificar sus referencias/importación o eliminación por separado antes de reintentar nombres. Mantener roles y políticas de aprovisionamiento mientras CloudFormation necesite gestionar recursos; nunca borrar toda la pila de ingreso, la clave KMS, las colas ni el rol de recuperación para deshacer esta fase. No restaurar una base de producción para desconectar un módulo.

## Reproducción local

```sh
python3 scripts/cloudformation/prepare-receive-review.py --release-dir /var/tmp/aws-bridge-receive-quota10-reviewed --check
```

Validaciones completadas: checksum de ZIP/YAML, inventario del ZIP, rechazo de ZIP alterado; ocho pruebas de seguridad de runtime/IAM; validación estructural de CloudFormation; cfn-lint; expansión SAM sin red con estos parámetros exactos y comprobación de igualdad de los doce recursos originales. No prueban permisos/SCP/cuotas en vivo, entrega AWS real, DNS/TLS ni instalación del Core.

Referencias: [conjuntos de cambios](https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/using-cfn-updating-stacks-changesets-create.html), [SAM Function y Role](https://docs.aws.amazon.com/serverless-application-model/latest/developerguide/sam-resource-function.html), [concurrencia reservada](https://docs.aws.amazon.com/lambda/latest/dg/configuration-concurrency.html), [concurrencia máxima SQS](https://docs.aws.amazon.com/lambda/latest/dg/services-sqs-scaling.html), [Secrets Manager](https://aws.amazon.com/secrets-manager/pricing/).
