# Verificación de permisos antes y después de la pila IAM

Revisión: 25-10-2026, Europe/Paris. **No ejecutada en AWS desde esta sesión.** No hay AWS CLI, perfiles ni credenciales AWS de entorno en el usuario operativo del servidor. El usuario ha facilitado una salida STS de CloudShell que confirma cuenta y sesión SSO; se registra su procedencia, sin atribuir esa llamada a las herramientas del arquitecto. Las políticas adjuntas, boundaries/SCP y la asunción del rol nuevo siguen pendientes de comprobación efectiva.

## Qué se puede verificar antes de crear los roles

La revisión local comprueba los dos recursos, sus políticas y las condiciones del patrón SSO. No comprueba permisos efectivos de AWS. La política AWS administrada `AdministratorAccess` incluye todas las acciones y recursos y, por tanto, `sts:AssumeRole`; no hace falta adjuntar otra concesión igual si esa política está efectivamente asignada y no hay restricciones que la limiten. [Política oficial](https://docs.aws.amazon.com/aws-managed-policy/latest/reference/AdministratorAccess.html).

Todavía no puede hacerse un `AssumeRole` real contra un rol que no existe. **Sí puede revisarse el permiso de la identidad de origen y simular su acción sobre el ARN previsto**. Después de crear solo los dos roles, se prueba STS realmente antes de crear cualquier clave KMS o recurso de mensajería.

### A. Lectura desde CloudShell en la sesión SSO autorizada

Estos comandos no crean/modifican roles, políticas, claves o pilas. No usar `--debug`. La salida se limita a metadatos; no solicitar ni pegar claves, tokens o códigos MFA.

```sh
aws sts get-caller-identity --region eu-west-1 --query '{Account:Account,Arn:Arn}' --output json
```

Debe mostrar cuenta `365470560685` y una sesión `assumed-role/AWSReservedSSO_AdministratorAccess_...`. Si aparece root, otra cuenta o una identidad no autorizada, detenerse. No continuar por el nombre visible de la consola solamente.

Para el rol SSO comunicado, revisar:

```sh
BRIDGE_SSO_ROLE_NAME='AWSReservedSSO_AdministratorAccess_6a3ad0308126a256'
BRIDGE_SSO_ROLE_ARN='arn:aws:iam::365470560685:role/aws-reserved/sso.amazonaws.com/eu-west-1/AWSReservedSSO_AdministratorAccess_6a3ad0308126a256'
BRIDGE_RECOVERY_ROLE_ARN='arn:aws:iam::365470560685:role/muserelay-aws-bridge-kms-recovery'

aws iam get-role --role-name "$BRIDGE_SSO_ROLE_NAME" --query 'Role.{Arn:Arn,PermissionsBoundary:PermissionsBoundary.PermissionsBoundaryArn}' --output json
aws iam list-attached-role-policies --role-name "$BRIDGE_SSO_ROLE_NAME" --query 'AttachedPolicies[].PolicyArn' --output json
aws iam list-role-policies --role-name "$BRIDGE_SSO_ROLE_NAME" --query 'PolicyNames' --output json

aws iam simulate-principal-policy --policy-source-arn "$BRIDGE_SSO_ROLE_ARN" --action-names sts:AssumeRole --resource-arns "$BRIDGE_RECOVERY_ROLE_ARN" --query 'EvaluationResults[].{Action:EvalActionName,Resource:EvalResourceName,Decision:EvalDecision,MissingContext:MissingContextValues}' --output json
```

Comprobar la política adjunta `arn:aws:iam::aws:policy/AdministratorAccess`, el boundary si existe y cualquier política inline/denegación relevante. Si el sufijo actual ya cambió, no usar los identificadores anteriores: leer primero el ARN IAM real de la sesión autorizada y revisar que siga cumpliendo el patrón de confianza.

`allowed` en la simulación solo es una evidencia de la evaluación simulada; **no prueba la confianza de un rol aún inexistente ni elimina las limitaciones del simulador**, las SCP, las políticas de sesión u otras restricciones. Si faltan contextos o hay denegaciones, revisarlos sin modificar permisos automáticamente. El administrador de AWS Organizations debe confirmar las SCP aplicables cuando no sean visibles desde la cuenta miembro. [Limitaciones de la simulación](https://docs.aws.amazon.com/IAM/latest/UserGuide/access_policies_testing-policies.html).

### B. Revisión de la plantilla, antes de ejecutarla

La plantilla actual solo crea `StageCloudFormationRole` y `KmsRecoveryRole`, con políticas inline y Retain. No ejecuta operaciones KMS por el hecho de conceder permisos.

La revisión posterior endurece permisos del ejecutor:

- Se eliminan `DisableKey`, `ScheduleKeyDeletion` y `CancelKeyDeletion` de su concesión en la key policy preparada.
- Su política IAM contiene **Deny explícito para `kms:DisableKey` y `kms:ScheduleKeyDeletion`** sobre las claves de esta cuenta/Irlanda. El stack no necesita esas operaciones porque la clave lleva Retain. La retirada destructiva sería una operación administrativa distinta autorizada, no una capacidad de su despliegue habitual.
- Se retira del IAM del ejecutor la concesión de administración de políticas/alias de claves basada solamente en etiquetas. `PutKeyPolicy` para administrar **la clave creada** permanece concedido directamente por esa key policy al ARN estable de ejecución. Las concesiones de recurso y las denegaciones IAM deben evaluarse conjuntamente. [Políticas de clave](https://docs.aws.amazon.com/kms/latest/developerguide/key-policies.html).
- Se conserva `PutKeyPolicy` como parte de administración/recuperación: eliminarlo de todas las vías impediría corregir la política y contradiría la protección anti-bloqueo. No es un permiso inocuo; permite cambiar autorizaciones de esa clave.
- `CreateKey`, el etiquetado inicial y las lecturas regionales siguen necesitando alcance previo a conocer el UUID. El etiquetado inicial tiene restricciones de etiquetas solicitadas, pero **no debe tratarse como una garantía de aislamiento contra cualquier plantilla administrada por un actor malicioso**. Reservar PassRole/operaciones sobre la pila a administradores autorizados y revisar el change set. Tras crear la clave, se puede acotar más al ARN real bajo revisión; no se modifica ninguna clave existente en esta preparación. [Permisos del etiquetado inicial](https://docs.aws.amazon.com/kms/latest/developerguide/add-tags.html).

La política del rol de recuperación sigue limitada a DescribeKey/GetKeyPolicy/PutKeyPolicy, y la key policy incorpora su rol estable más la recuperación administrativa de cuenta. La prueba de asumir el rol no demuestra todavía que pueda administrar una clave futura; su política y las condiciones deben comprobarse también cuando exista esa clave.

**No ejecutar el conjunto de cambios preparado con el YAML anterior.** Descargar el actualizado, revisar otro conjunto de cambios y confirmar que solo añade los dos roles esperados. La creación IAM requiere autorización concreta; no se ejecutó aquí.

### C. Después de crear solo los roles, antes de crear KMS

Leer los ARN reales, las dos confianzas y las políticas inline, comparándolos con el archivo revisado:

```sh
aws iam get-role --role-name muserelay-aws-bridge-kms-recovery --query 'Role.{Arn:Arn,Trust:AssumeRolePolicyDocument,PermissionsBoundary:PermissionsBoundary.PermissionsBoundaryArn}' --output json
aws iam get-role --role-name muserelay-aws-bridge-stage-cfn-execution --query 'Role.{Arn:Arn,Trust:AssumeRolePolicyDocument,PermissionsBoundary:PermissionsBoundary.PermissionsBoundaryArn}' --output json
aws iam get-role-policy --role-name muserelay-aws-bridge-kms-recovery --policy-name bridge-stage-scoped --query 'PolicyDocument' --output json
aws iam get-role-policy --role-name muserelay-aws-bridge-stage-cfn-execution --policy-name bridge-stage-scoped --query 'PolicyDocument' --output json
```

La prueba real de asunción usa la sesión SSO original y muestra **solo el ARN resultante**, nunca las credenciales temporales:

```sh
aws sts assume-role --role-arn arn:aws:iam::365470560685:role/muserelay-aws-bridge-kms-recovery --role-session-name bridge-kms-recovery-readcheck --duration-seconds 900 --query 'AssumedRoleUser.Arn' --output json
```

Éxito: ARN `arn:aws:sts::365470560685:assumed-role/muserelay-aws-bridge-kms-recovery/...`. No añadir `--debug`, no quitar la selección `--query` para compartir la salida y no exportar credenciales al chat. La prueba emite una sesión temporal; no modifica recursos IAM/KMS. [API AssumeRole](https://docs.aws.amazon.com/cli/latest/reference/sts/assume-role.html).

No intentar asumir como persona el rol de ejecución: su confianza es solo CloudFormation y el rechazo sería esperable. No se añade al usuario esa confianza para facilitar una prueba.

## Estados de aceptación separados

| Comprobación | Estado de esta revisión |
| --- | --- |
| Configuración y consistencia local | Preparadas y verificables offline. |
| Identidad SSO en AWS | Confirmada por la salida CloudShell facilitada: cuenta `365470560685`, sesión `AWSReservedSSO_AdministratorAccess_6a3ad0308126a256/antoni.caimari`; no root. |
| Política adjunta, boundary/SCP y simulación de permiso fuente | Pendientes de los resultados reales correspondientes. |
| Asunción efectiva del rol de recuperación | Pendiente de que exista el rol y se ejecute STS autorizado. |
| Capacidad de leer/corregir la política de la clave real | Pendiente de su creación posterior autorizada y revisión efectiva; no probada mediante una escritura. |
| Creación/eliminación real de recursos por CloudFormation | No ejecutada; no se presenta el lint como prueba funcional. |

Si falla la fase C, detenerse **antes de crear la clave** y conservar la evidencia del error sin secretos. Si falla una lectura/simulación de fase A, no adjuntar AdministratorAccess adicional ni cambiar trust/SCP a ciegas. Las escrituras de política, los ensayos de recuperación destructivos, la clave y el ingreso son autorizaciones separadas. MuseRelay y sus canales actuales permanecen intactos.

## Salida STS facilitada y versión a utilizar

El usuario comunica `Account=365470560685` y `Arn=arn:aws:sts::365470560685:assumed-role/AWSReservedSSO_AdministratorAccess_6a3ad0308126a256/antoni.caimari`. Coincide con la identidad de origen prevista. El conjunto de cambios anterior conserva el YAML antiguo: no ejecutarlo. Preparar otro CREATE con el archivo reducido y revisar exactamente dos adiciones de tipo AWS::IAM::Role antes de confirmar. No se ha creado ni ejecutado un change set desde esta sesión. La [evidencia actualizada](evidence/kms-effective-permissions-review.json) distingue identidad recibida, verificaciones offline y pruebas AWS pendientes.
