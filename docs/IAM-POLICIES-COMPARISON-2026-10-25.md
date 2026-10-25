# Comparación completa de políticas IAM — 25/10/2026 (Europe/Paris)

Resultado: **PASS documental para las plantillas publicadas; sin autorización de ejecución**. No se modifica ningún YAML de infraestructura, rol, recurso AWS, consumidor o archivo de producción. No se ha obtenido desde este servidor la plantilla real de la pila ni del conjunto de cambios. Sus estados y propiedades visibles proceden de la comprobación comunicada por el propietario.

## Procedencia verificable

Se descargaron mediante la API autenticada de GitHub, sin mostrar ni copiar credenciales, los dos archivos del commit publicado `401728d1a66f577481936e07f2630a559144efb9`. Sus bytes coinciden con los objetos Git locales y se verificó que `screenartsl/aws-engagement-bridge` sigue siendo privado.

| Archivo publicado | SHA256 del archivo completo |
| --- | --- |
| `deploy/iam-foundation/template.yaml` (baseline del repositorio) | `3c280bc43c60d68d6b5e5c186574e34bdbc7cfcd6e879dcaa6c3361faa8e8260` |
| `deploy/runtime-iam/template.yaml` (propuesta) | `b2a87ec5d7d205b69250596103427fb55b8896b7f5997507b28b489b18feac3a` |

El baseline es la versión anterior **del repositorio**, no una exportación nueva de AWS. El YAML propuesto tampoco se ha recuperado aún del conjunto AWS. La firma truncada de la interfaz no sustituye esa comprobación.

Artefactos íntegros, sin truncamiento, bajo `deploy/runtime-iam/review/`:

- [Policies anterior](../deploy/runtime-iam/review/executor-policies.before.json).
- [Policies propuesta](../deploy/runtime-iam/review/executor-policies.after.json).
- [Diff unificado de Policies](../deploy/runtime-iam/review/executor-policies.diff).
- [Tres roles con GrantSocialSend=false resuelto](../deploy/runtime-iam/review/runtime-roles.send-disabled.json).
- [Resultado estructurado, procedencia y hashes de los artefactos](../deploy/runtime-iam/review/comparison.json).

## StageCloudFormationRole: igualdad exacta de la política anterior

Antes: una política inline, `bridge-stage-scoped`.

Después: exactamente dos políticas inline, en este orden:

1. `bridge-stage-scoped`: objeto completo idéntico al anterior, incluidas acciones, recursos, condiciones y denegaciones.
2. `bridge-runtime-receive-provisioning`: única política añadida, nueve declaraciones.

La comparación estructural del recurso completo solo admite ese cambio de `Properties.Policies`. Tipo, nombre, confianza, duración, etiquetas y ambas políticas Retain se conservan. `KmsRecoveryRole` se conserva íntegramente, no solo su nombre.

SHA256 de `bridge-stage-scoped`, como JSON canónico antes **y** después:

```text
5173b81dfd8e2deda4136014a3b6d9243751eb4ebb6ea45490d4c2287a08bedb
```

El bloque de denegación conservado es:

```json
{
  "Sid": "PreventKeyDestructionFromDeployment",
  "Effect": "Deny",
  "Action": ["kms:DisableKey", "kms:ScheduleKeyDeletion"],
  "Resource": "arn:aws:kms:eu-west-1:365470560685:key/*"
}
```

Para reproducir la prueba a partir de los JSON del informe (Python estándar, sin AWS, aplicación ni dependencias adicionales):

```sh
python3 - <<'PY'
import hashlib, json
from pathlib import Path
p = Path('deploy/runtime-iam/review')
before = json.loads((p/'executor-policies.before.json').read_text())
after = json.loads((p/'executor-policies.after.json').read_text())
assert [x['PolicyName'] for x in before] == ['bridge-stage-scoped']
assert [x['PolicyName'] for x in after] == ['bridge-stage-scoped', 'bridge-runtime-receive-provisioning']
assert before[0] == after[0]
canonical = json.dumps(before[0], sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode()
assert hashlib.sha256(canonical).hexdigest() == '5173b81dfd8e2deda4136014a3b6d9243751eb4ebb6ea45490d4c2287a08bedb'
assert len(after[1]['PolicyDocument']['Statement']) == 9
print('PASS: política anterior idéntica; una política nueva con nueve declaraciones')
PY
```

Esto comprueba los artefactos publicados, no los permisos instalados en AWS.

## Política nueva del ejecutor: alcance exacto

Cuenta `365470560685`, región `eu-west-1`, prefijo `muserelay-aws-bridge-stage`:

| Sid | Permisos y ámbito |
| --- | --- |
| `PassOnlyInboundRuntime` | `iam:PassRole` exclusivamente al rol `...-inbound-runtime`, con `iam:PassedToService=lambda.amazonaws.com` |
| `InboundFunctionProvisioning` | Create/Get/Update/Delete de la función inbound prevista, concurrencia y etiquetas; ARN exacto y condición de región |
| `CreateOnlyInboundMapping` | `lambda:CreateEventSourceMapping`, Resource `*`, con región y `lambda:FunctionArn` exactos |
| `ManageOnlyInboundMapping` | Get/Update/Delete de mappings en la cuenta/región, con `lambda:FunctionArn` exacto |
| `OwnStateTableProvisioning` | Administración y TTL/etiquetas de la tabla `...-state`, no lectura/escritura de ítems |
| `EmptySigningSecretMetadata` | Crear/consultar metadatos y etiquetas del secreto `.../signing-??????`; no GetSecretValue ni PutSecretValue |
| `OwnLogGroupProvisioning` | Administración/retención/etiquetas del grupo `/aws/lambda/...-inbound` |
| `RegionalLogGroupMetadata` | `logs:DescribeLogGroups`, Resource `*`, limitado a Irlanda; permite metadatos de otros grupos de esa región |
| `ReadReviewedArtifact` | `s3:GetObject` solo en `releases/*/bridge.zip` del bucket privado previsto; no subida ni lectura de otros objetos |

No se añaden permisos para crear/editar IAM, invocar Bedrock o enviar WhatsApp. Las acciones individuales, ARN y condiciones completos constan en `executor-policies.after.json`. Las dos declaraciones con Resource `*` no son permisos administrativos globales: una limita la función/región y la otra es lectura regional de metadatos. Se comprobó en la referencia oficial Lambda el soporte de `lambda:FunctionArn` para las cuatro operaciones de mapping utilizadas.

## Tres roles nuevos: confianza, permisos declarados y conservación

Cada rol tiene `MaxSessionDuration=3600`, `DeletionPolicy=Retain` y `UpdateReplacePolicy=Retain`. Los nombres son los indicados por la plantilla. No se adjuntan ManagedPolicyArns, AdministratorAccess ni una permissions boundary propia.

Su confianza completa es idéntica en los tres:

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Principal": {"Service": "lambda.amazonaws.com"},
    "Action": "sts:AssumeRole"
  }]
}
```

No confían directamente en el servidor web, un usuario, root o la sesión SSO. La confianza no incluye una condición por ARN de función; no afirmar que la confianza por sí sola limita a una función concreta. La asociación se controla mediante PassRole y los permisos para crear/actualizar funciones; administradores con otros permisos podrían asociar estos roles a otras funciones. Lambda asume sus roles automáticamente; no se crean claves de acceso permanentes.

| Rol | Permisos concedidos por esta plantilla, con el envío deshabilitado |
| --- | --- |
| `...-inbound-runtime` | Get/Put/Update/DeleteItem en la tabla de estado; GetSecretValue del secreto de firma hacia Core; CreateLogStream/PutLogEvents en sus logs; ReceiveMessage/DeleteMessage/GetQueueAttributes de la cola inbound exacta |
| `...-enqueue-runtime` | PutItem en estado; GetSecretValue del secreto separado `signing-outbound`; logs propios; SendMessage solo a la cola outbound |
| `...-sender-runtime` | CRUD de ítems en estado; secreto hacia Core; logs propios; ReceiveMessage/DeleteMessage/GetQueueAttributes solo en la cola outbound. Sin SendWhatsAppMessage |

Los tres roles resueltos tienen cuatro declaraciones Allow cada uno, sin Resource `*` ni acciones `servicio:*`. Comparten la tabla de estado: el aislamiento por binding/organización corresponde al diseño y validación de la aplicación, no a una condición IAM por partición. Ninguno recibe permisos de Bedrock, KMS, SNS o administración IAM. El acceso al secreto sí permite leer su valor: está limitado al secreto técnico previsto, no a secretos de Bedrock/producción. Si se cambiara el cifrado del secreto/cola a una CMK propia, habría que revisar además los permisos KMS correspondientes.

Crear los roles no crea funciones ni mappings y no inicia el consumo SQS. `Retain` conserva también las políticas adjuntas; no es una medida de revocación automática.

## GrantSocialSend=false y permisos efectivos

La plantilla tiene Default `false` y una condición Fn::Equals contra la cadena `true`. Con el parámetro `false` comunicado por el propietario, la declaración `OnlyDemoPhone` se elimina mediante `AWS::NoValue`. Se verificó esa resolución en el artefacto de roles: **no contiene** `social-messaging:SendWhatsAppMessage`. La rama `true` sigue en el YAML para una futura autorización, limitada al ARN del teléfono demo; no se activa ahora.

Esto es ausencia de una concesión, **no un Deny explícito** que impida toda concesión futura desde otra política. No se ha observado IAM en vivo ni se han creado los roles. No se pueden certificar sus permisos efectivos a partir del YAML: también intervienen SCP/RCP, políticas de recursos, sesión, boundaries y denegaciones. La revisión actual acredita lo que concede la plantilla propuesta; no acredita llamadas reales ni disponibilidad de servicios.

## Cerrar el hueco de la interfaz truncada sin ejecutar

Conjunto comunicado: `receive-runtime-iam-review-b2a87ec5d7d2`, pila `muserelay-aws-bridge-iam-foundation`. El propietario confirma CREATE_COMPLETE / AVAILABLE, tres Add, un Modify sin sustitución, GrantSocialSend=false y ninguna service role asociada. No se reconstruye ni da por válido el ARN incompleto recibido anteriormente.

Obtener la **plantilla completa del conjunto**, además de la plantilla de la pila actual. Si la consola permite copiar/descargar ambas completas, usar esa vía. No copiar solo la propiedad Policies truncada. Si hace falta una extracción alternativa, estas dos operaciones de CloudShell son exclusivamente de lectura:

```sh
aws cloudformation get-template --region eu-west-1 --stack-name muserelay-aws-bridge-iam-foundation --template-stage Original --output json > bridge-iam-current.template-response.json
aws cloudformation get-template --region eu-west-1 --stack-name muserelay-aws-bridge-iam-foundation --change-set-name receive-runtime-iam-review-b2a87ec5d7d2 --template-stage Original --output json > bridge-iam-proposed.template-response.json
```

Estos archivos contienen plantillas IAM, no credenciales. GetTemplate permite especificar el conjunto por nombre más pila y obtener Original sin ejecutarlo. Se guarda JSON completo para evitar ambigüedades de formato de TemplateBody. El SHA256 de ese JSON envolvente **no** será el SHA256 del YAML: extraer TemplateBody y comparar contenido estructural, no declarar una discrepancia solo por formato.

Comparar: pila actual contra baseline publicado, propuesta AWS contra propuesta publicada, y Policies actual/propuesta entre sí. Si el contenido coincide, se resuelve la falta de evidencia visual. Si no coincide, revisar la diferencia antes de crear otro conjunto; no cambiar roles directamente. Revisar también el parámetro **real** del conjunto, ya comunicado como false; el default del YAML no sustituye ese dato.

No hay acceso AWS de lectura configurado en este servidor. Por tanto, los dos archivos reales quedan pendientes de aportar para esa última comparación. No autorizar ni ejecutar aún el conjunto basándose solo en este informe.

## Referencias oficiales contrastadas

- [GetTemplate para pila o conjunto y etapa Original](https://docs.aws.amazon.com/AWSCloudFormation/latest/APIReference/API_GetTemplate.html).
- [Evaluación de políticas y permisos efectivos](https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_policies_evaluation-logic.html).
- [Acciones, recursos y condiciones Lambda](https://docs.aws.amazon.com/service-authorization/latest/reference/list_lambda.html).
- [Roles de ejecución Lambda](https://docs.aws.amazon.com/lambda/latest/dg/lambda-intro-execution-role.html).
- [DeletionPolicy y Retain](https://docs.aws.amazon.com/AWSCloudFormation/latest/TemplateReference/aws-attribute-deletionpolicy.html).
