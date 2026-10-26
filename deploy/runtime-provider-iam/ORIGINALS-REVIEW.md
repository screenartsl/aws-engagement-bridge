# Revisión de los Original reales — conjunto provider IAM

26/10/2026, Europe/Paris. El propietario comunica allMatch=true para cinco schemas
y CREATE_COMPLETE / AVAILABLE del conjunto runtime-provider-iam-review-cb4d602f2d90,
pila muserelay-aws-bridge-iam-foundation, único Modify StageCloudFormationRole,
Replacement=False. Es evidencia comunicada; no tenemos identidad AWS aquí ni hemos
leído directamente sus Original. No dar por cerrada esa revisión solo con los
schemas o el resumen de cambios. No se ha ejecutado el conjunto ni runtime/Core.

## Una ejecución de solo lectura en CloudShell

Descargar del commit revisado `scripts/cloudformation/verify-provider-iam-originals.py`
y subirlo al directorio privado de CloudShell. Usa la sesión SSO autorizada, no root.

```sh
python3 verify-provider-iam-originals.py --collect
```

Son cinco lecturas: STS para verificar cuenta/no root, DescribeStacks de Foundation,
GetTemplate Original actual, GetTemplate Original del conjunto y DescribeChangeSet
completo. Sin filtros/paginación parcial. La herramienta no modifica AWS ni guarda
respuestas ni imprime TemplateBody, políticas o credenciales. No llama IAM write,
SecretValue, SQS, Lambda, Bedrock ni ejecución CloudFormation. Default sin --collect
lee cuatro JSON locales; no hace llamadas AWS por defecto. No instala dependencias.

Exige igualdad íntegra con el baseline d60476... y la propuesta cb4d602... mediante
SHA256 del YAML exacto o JSON estructural canónico. Eso vincula los Original reales
a la comparación local exhaustiva: retirar las siete declaraciones añadidas
reconstruye exactamente el baseline completo. No basta con comparar Sid o recuento.
Rechaza otras políticas, cambios de confianza/roles, KMS Deny ausentes, condiciones
de secret/logs/tagging ampliadas, parámetros Send true/indeterminados, más cambios,
sustituciones, cuentas equivocadas y conjuntos no disponibles. YAML reformateado
desconocido exige revisión manual; nunca se acepta por aproximación.

Comprobaciones de metadatos: misma pila/StackId/cuenta/región/conjunto, estado
disponible y Foundation UPDATE_COMPLETE, un Modify del ejecutor y Scope=Properties,
GrantSocialSend=false tanto actual como propuesto. El rol actual de Foundation
se comprueba con DescribeStacks, no con el campo inexistente RoleARN de
DescribeChangeSet. La solicitud publicada omite RoleARN; no añadir overrides.
El último check solo acredita el rol actual, no prueba que no se añadiera un
override en una solicitud distinta de la publicada. Si se usó otro comando,
contrastar RoleARN de su CreateChangeSet en CloudTrail antes de aprobar ejecución.

## Excepciones de las 14 concesiones

- GetDataProtectionPolicy solo en el grupo inbound. DescribeIndexPolicies y
  DescribeResourcePolicies son metadatos regionales con Resource=* porque
  AWS no admite ARN de grupo para esas dos APIs. Pueden mostrar políticas de
  otros grupos de Irlanda; no se presentan como aislamiento por proyecto. No
  dan GetLogEvents, FilterLogEvents, Logs Insights ni edición de políticas.
- GetSecretValue solo al rol CloudFormation, en el ARN de signing de staging
  con tags Component=aws-engagement-bridge y Environment=staging e Irlanda.
  Es lectura de contenido, aunque inicialmente esté vacío. Si luego se carga
  material de firma, el rol tendrá esa capacidad. No se concede al usuario de
  herramientas/auditoría, ni este verificador lo llama. Sin otros secretos,
  firmas de Bedrock/Core, escritura, rotación o replicación.
- TagResource del mapping requiere RequestTag de sistema para stack y logical-id;
  ListTags requiere sus ResourceTag, no lambda:FunctionArn en APIs no soportadas.
  Contexto real y aceptación AWS no se certifican con la comparación local.

## Puerta de autorización después del resultado real

Esperado: todas las líneas PASS y `PASS_PROVIDER_IAM_ORIGINALS`. El resultado
no autoriza ejecución. Compartir resumen; entonces solicitar autorización expresa
para ejecutar exclusivamente este conjunto IAM existente. Mantener runtime,
consumidores, firma, envíos y Core fuera del alcance. Si hay REVISAR, detenerse
y revisar los campos antes de modificar o volver a crear conjuntos.

Tras autorizar, el propietario SSO AdministratorAccess selecciona la pila
Foundation → Conjuntos → runtime-provider-iam-review-cb4d602f2d90 → Ejecutar,
sin cambiar plantilla/parámetros/service role ni ejecutar otros conjuntos. Exigir
UPDATE_COMPLETE y comparar ambas inline instaladas completas con los archivos
after.json; revisar managed policies/boundaries/trust. Confirmar GrantSocialSend
false y denegaciones KMS. Registrar los eventos de gestión CloudTrail.

Solo después revalidar el runtime ya creado. No borrar/recrear el conjunto ni
subir ZIP mientras siga AVAILABLE e idéntico. Ejecución del runtime es otra
autorización. Esta corrección IAM no crea funciones, consume SQS, envía mensajes,
inicializa el secreto o modifica MuseRelay. No se estima coste AWS incremental por
los permisos IAM; el runtime futuro mantiene la estimación documentada por separado.

Fuentes:
- https://docs.aws.amazon.com/AWSCloudFormation/latest/APIReference/API_GetTemplate.html
- https://docs.aws.amazon.com/AWSCloudFormation/latest/APIReference/API_DescribeChangeSet.html
- https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/using-cfn-updating-stacks-changesets-execute.html
- https://docs.aws.amazon.com/service-authorization/latest/reference/list_logs.html
