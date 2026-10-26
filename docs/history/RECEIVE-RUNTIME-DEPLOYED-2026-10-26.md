# Runtime de recepción desplegado (26/10/2026)

Despliegue real en `muserelay-aws-bridge-stage` (cuenta 365470560685, eu-west-1) con el usuario
IAM `muserelay-bridge-deployer`, por el rol `muserelay-aws-bridge-stage-cfn-execution`.

## Secuencia

1. IAM `runtime-provider-iam-review-cb4d602f2d90` verificado con `verify-provider-iam-originals.py`
   (PASS_PROVIDER_IAM_ORIGINALS) y ejecutado por el propietario. Foundation `UPDATE_COMPLETE`; la
   Original desplegada es idéntica a la propuesta verificada.
2. `receive-sam-review-88161733676d-82707086-fix1` falló en `State`:
   `KMS validation error ... Key alias/aws/dynamodb (e8feb59a-...) does not exist`. La clave existe y
   está habilitada; `SSEEnabled: true` exige la clave KMS gestionada por AWS y permisos KMS
   (CreateGrant) que el rol de CloudFormation no tiene. Rollback limpio (`UPDATE_ROLLBACK_COMPLETE`).
   `SigningSecret` (Retain) quedó huérfano y vacío; se borró con `--force-delete-without-recovery`.
3. Corrección: `State` sin `SSESpecification` → cifrado por defecto de DynamoDB (clave propiedad de
   AWS), sin llamadas a KMS ni permisos nuevos. Única diferencia con fix1 (comparada sobre la
   plantilla Processed). Plantilla SHA256 `643736eeb40a63a9b25978af61cc89fe8d3ae9249a267d05a4bb4103455c8881`.
4. `receive-sam-review-82707086-fix2-sse-default`: cinco Add idénticos; `UPDATE_COMPLETE` 15:18 UTC.

## Estado comprobado tras el despliegue

- Función `muserelay-aws-bridge-stage-inbound`, nodejs22.x arm64, rol `...-inbound-runtime`,
  CodeSha256 = ZIP verificado `9e377f79...747db`. Sin concurrencia reservada.
- Mapping `64cf45f4-3def-45c2-a78a-6a37979bed18` **Disabled**, BatchSize 1, MaximumConcurrency 2.
- `BRIDGE_RUNTIME_ENABLED`, `BRIDGE_RECEIVE_ENABLED`, `BRIDGE_SEND_ENABLED`, `BRIDGE_LIVE_SEND` = false.
- 0 invocaciones. Cola inbound con 2 mensajes visibles, sin consumir.
- `muserelay-aws-bridge-stage-state` ACTIVE, PAY_PER_REQUEST, cifrado por defecto.
- `SigningSecret` creado sin valor.

## Pendiente para la recepción real

Secreto de firma (AWS + Core), HTTPS `aws-bridge-staging.muserelay.com` (DNS + Caddy), conector 0.5.1
y reconocimiento del transporte en el Core, extensión solo para la organización 227, canal/binding demo,
y finalmente habilitar procesamiento/recepción y el mapping.
