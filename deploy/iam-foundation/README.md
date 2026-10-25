# Roles estables de administración — preparación, NO desplegada

Procedimiento y decisiones: [AWS-KMS-ADMINISTRATION.md](../../docs/AWS-KMS-ADMINISTRATION.md).

La plantilla crea únicamente dos roles IAM con nombres estables y políticas inline, sin AdministratorAccess y con Retain:

- `arn:aws:iam::365470560685:role/muserelay-aws-bridge-stage-cfn-execution`: servicio CloudFormation; permisos para el ingreso SNS/KMS/SQS/S3 de etapa 1.
- `arn:aws:iam::365470560685:role/muserelay-aws-bridge-kms-recovery`: recuperación de política de la clave mediante el permission set AdministratorAccess SSO autorizado.

Cuenta `365470560685`, región del stack `eu-west-1`, nombre requerido `muserelay-aws-bridge-iam-foundation`. **No crear sin autorización.** Antes, comprobar si existen estos roles; no sobreescribir identidades ajenas. Su creación necesita reconocer IAM con nombres: `CAPABILITY_NAMED_IAM`.

`template.yaml` es la fuente reproducible. Los JSON separados contienen exactamente las mismas políticas para revisión o procedimiento manual autorizado; la validación local comprueba que no diverjan. El archivo `operator-additions.example.json` solo contiene PassRole delimitado y AssumeRole de recuperación, no todo el acceso del operador a CloudFormation/consola/S3.

En el stack de transporte, `KmsAdministratorArn` debe coincidir con el rol de servicio seleccionado en **Permisos**, y `KmsRecoveryAdministratorArn` con el segundo rol. No basta con pegar el primer ARN en un parámetro. Ambos deben existir antes de crear la clave; no se han creado desde esta sesión.

El rol de ejecución **no tiene aún permisos de etapa 2** (Lambda/API/DynamoDB/Secrets Manager/roles de runtime). Revisarlos y ampliar con autorización antes de incorporar procesamiento; no adjuntar AdministratorAccess como atajo. Los roles no reciben permisos para enviar WhatsApp ni invocar Bedrock.

No borrar roles retenidos mientras existan claves o pilas que los utilicen. Conservar al menos una asignación SSO autorizada. El patrón de sufijo permite recreación del rol SSO; no sustituye la conservación de los roles estables ni una recuperación administrativa de la cuenta.

## Revisión posterior antes de ejecutar

Véase [verificación efectiva y endurecimiento](../../docs/IAM-FOUNDATION-VERIFICATION.md). El ejecutor tiene Deny explícito para desactivar o programar el borrado de claves, y ya no recibe PutKeyPolicy por una concesión IAM basada en etiquetas. La administración de la clave propia se declara en su key policy. La ausencia de AWS CLI/perfil en este servidor impide afirmar verificación de permisos reales. El AssumeRole real se verifica tras crear los dos roles y antes de crear KMS; antes se comprueba identidad y se puede simular la política del origen. No ejecutar el change set con el YAML anterior.
