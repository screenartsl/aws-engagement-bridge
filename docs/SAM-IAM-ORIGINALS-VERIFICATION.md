# Verificación íntegra del conjunto IAM SAM

Estado 26/10/2026: el propietario comunica CREATE_COMPLETE / AVAILABLE para
`sam-transform-iam-review-d60476a283b7`. La verificación de las respuestas reales
Original y DescribeChangeSet sigue pendiente. No se autoriza ni ejecuta nada.

La comparación local completa entre `deploy/runtime-iam/template.yaml` y
`deploy/runtime-sam-iam/template.yaml` conserva todos los recursos y propiedades:
al retirar únicamente `AllowAwsSamTransformInIreland` de la segunda se obtiene
exactamente la primera. Esa declaración añade solo
`cloudformation:CreateChangeSet` sobre
`arn:aws:cloudformation:eu-west-1:aws:transform/Serverless-2016-10-31`.
`bridge-stage-scoped`, las denegaciones `kms:DisableKey` y
`kms:ScheduleKeyDeletion`, las otras declaraciones, las confianzas y Retain
permanecen idénticos. El valor real del parámetro y Replacement deben verificarse
en AWS; el default de la plantilla no basta.

## Lecturas desde CloudShell AdministratorAccess

En la cuenta 365470560685, sin cambiar permisos ni ejecutar conjuntos:

```sh
aws cloudformation get-template --region eu-west-1 \
  --stack-name muserelay-aws-bridge-iam-foundation \
  --template-stage Original --output json --no-cli-pager \
  > sam-iam-current.original.json

aws cloudformation get-template --region eu-west-1 \
  --stack-name muserelay-aws-bridge-iam-foundation \
  --change-set-name sam-transform-iam-review-d60476a283b7 \
  --template-stage Original --output json --no-cli-pager \
  > sam-iam-proposed.original.json

aws cloudformation describe-change-set --region eu-west-1 \
  --stack-name muserelay-aws-bridge-iam-foundation \
  --change-set-name sam-transform-iam-review-d60476a283b7 \
  --output json --no-cli-pager > sam-iam-review.metadata.json
```

No añadir filtros, `--max-items` ni `--no-paginate`: se necesita la respuesta
completa. `--no-cli-pager` solo evita el visor interactivo.

Descargar `scripts/cloudformation/verify-sam-iam-originals.py` del repositorio
privado, guardarlo junto a los tres JSON y ejecutar:

```sh
python3 verify-sam-iam-originals.py
```

Solo utiliza la biblioteca estándar, lee los archivos locales y escribe un
resumen en pantalla. No llama a AWS, consulta credenciales, ejecuta cambios ni
imprime TemplateBody. Comprueba los hashes completos de ambas plantillas,
cuenta/región/pila/conjunto, estado, un único Modify del ejecutor, Replacement=False,
Scope=Properties, GrantSocialSend=false y ausencia de nuevo service role IAM.
Acepta YAML idéntico byte por byte o JSON estructuralmente idéntico; un YAML
reformateado requiere revisión y nunca se acepta por aproximación.

Enviar todas las líneas PASS/REVISAR y ChangeSetId. Si hay REVISAR, conservar los
archivos y detenerse. No pegar el contenido completo de políticas si no hace falta.
Incluso un resultado PASS deja pendiente la autorización expresa para ejecutar
exclusivamente este UPDATE IAM. No autoriza runtime, consumidores ni mensajes.

## Pruebas y límites

Diez pruebas locales con respuestas sintéticas verifican igualdad y rechazo de
mutaciones KMS, confianza de otros roles, parámetro real true/indeterminado,
sustituciones, cambios adicionales, cuenta/conjunto incorrectos, cambio de service
role, estados no disponibles y respuestas incompletas. No son evidencia de AWS.
El verificador vincula las respuestas reales al delta íntegro revisado; no es una
simulación de permisos efectivos ni garantiza ausencia de SCP/boundaries externos.

Referencias: [GetTemplate](https://docs.aws.amazon.com/cli/latest/reference/cloudformation/get-template.html),
[DescribeChangeSet](https://docs.aws.amazon.com/cli/latest/reference/cloudformation/describe-change-set.html).
