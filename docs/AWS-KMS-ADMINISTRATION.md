# Administración KMS estable: Identity Center, CloudFormation y recuperación

Revisión: **25-10-2026, Europe/Paris**. Preparación local, sin llamadas autenticadas AWS ni cambios IAM. El usuario comunica Identity Center operativo, usuario `antoni.caimari`, permission set `AdministratorAccess`, MFA funcionando, cuenta `365470560685` y región `eu-west-1`. No equivale a un inventario API ni confirma el MFA del usuario root.

## Decisión y corrección del diseño anterior

**No utilizar el ARN `AWSReservedSSO_AdministratorAccess_6a3ad0308126a256` como administrador único y permanente de la clave.** La plantilla anterior lo admitía, pero no resolvía su recreación ni una recuperación independiente. Se sustituye por roles estables y una política de recuperación explícita; no se debe continuar con el YAML anterior.

AWS explica que, al quitar todas las asignaciones del permission set en una cuenta, su rol puede desaparecer; al reasignarlo cambia el sufijo. Recomienda conservar una asignación y un rol IAM de respaldo. Su ejemplo de confianza usa `aws:PrincipalArn` con comodín solo para ese sufijo. [Recomendación oficial Identity Center/KMS](https://docs.aws.amazon.com/singlesignon/latest/userguide/referencingpermissionsets.html).

El ARN SSO comunicado es un identificador IAM válido y podría funcionar inicialmente. El problema es su ciclo de vida como único principal de una clave retenida, no el hecho de usar MFA o de entrar mediante SSO.

## ARN exactos previstos

| Campo / uso | Valor que debe coincidir con los roles realmente creados |
| --- | --- |
| `KmsAdministratorArn` **y** rol de servicio en Permisos CloudFormation | `arn:aws:iam::365470560685:role/muserelay-aws-bridge-stage-cfn-execution` |
| Nuevo parámetro `KmsRecoveryAdministratorArn` | `arn:aws:iam::365470560685:role/muserelay-aws-bridge-kms-recovery` |

**Son ARN previstos; esos roles todavía no se han creado ni comprobado desde esta sesión.** No pegar un ARN inexistente para continuar. La revisión actual restringe los parámetros a esos nombres estables. No introducir el ARN STS `assumed-role`, el ARN SSO o el usuario de Identity Center en estos campos.

Asignar un ARN al parámetro de KMS **no configura el rol de ejecución de la pila**. En el asistente, seleccionar también el primer rol en **Configurar opciones de pila → Permisos → Rol IAM**. Así, el llamador efectivo de KMS será el mismo principal incluido como administrador. Sin rol de servicio CloudFormation utiliza una sesión basada en la identidad que inicia la operación. [Funcionamiento del rol de servicio](https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/using-iam-servicerole.html).

## Dos roles y ciclos de vida separados

Se prepara [deploy/iam-foundation/template.yaml](../deploy/iam-foundation/template.yaml), pila prevista **`muserelay-aws-bridge-iam-foundation`**, con exactamente **dos roles IAM nombrados**, sus políticas inline, etiquetas y salidas. No crea KMS, SNS, mensajes ni el Core. Ambos roles llevan `DeletionPolicy: Retain` y `UpdateReplacePolicy: Retain`.

Esta preparación IAM es un prerrequisito independiente; las dos etapas del transporte siguen siendo ingreso cifrado → actualización del mismo stack con runtime. La pila del transporte no crea ni elimina a sus propios administradores KMS.

1. **`muserelay-aws-bridge-stage-cfn-execution`**: confianza exclusivamente en `cloudformation.amazonaws.com`. Sus permisos iniciales permiten administrar el ingreso de doce recursos, con ARN/nombres acotados y restricciones KMS. No contiene AdministratorAccess, creación de usuarios/roles, envíos Social, lectura de mensajes ni Bedrock.
2. **`muserelay-aws-bridge-kms-recovery`**: confianza en el permission set AdministratorAccess de esta cuenta/instancia regional mediante patrón de sufijo. Permite describir la clave de staging y leer/modificar su política, no incorpora permisos criptográficos ni despliegue ordinario. Quien puede modificar una política de clave tiene poder administrativo para cambiar autorizaciones: tratarlo como acceso privilegiado, no como lectura.

La confianza del segundo rol es [recovery-role-trust.json](../deploy/iam-foundation/recovery-role-trust.json):

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Principal": {"AWS": "arn:aws:iam::365470560685:root"},
    "Action": "sts:AssumeRole",
    "Condition": {"ArnLike": {
      "aws:PrincipalArn": "arn:aws:iam::365470560685:role/aws-reserved/sso.amazonaws.com/eu-west-1/AWSReservedSSO_AdministratorAccess_*"
    }}
  }]
}
```

Aquí el principal de cuenta se restringe mediante `ArnLike`: no es una autorización para cualquier cuenta, cualquier rol ni cualquier usuario de Identity Center. Incluye las sesiones del permission set AdministratorAccess autorizado, **no solo a una persona concreta**. El patrón soporta otro sufijo si ese permission set se recrea. Conservar MFA en Identity Center. Cambiar el nombre del permission set, su cuenta o la región de su ARN requiere revisar esta confianza.

## Política de la clave revisada

La política preparada tiene cuatro concesiones:

| Concesión | Principal / operaciones |
| --- | --- |
| Administración habitual | Rol estable CloudFormation; lista explícita de operaciones administrativas de la clave/alias. |
| Recuperación directa | Rol IAM estable de recuperación; `kms:DescribeKey`, `kms:GetKeyPolicy`, `kms:PutKeyPolicy`. |
| Recuperación por la cuenta | Principal de cuenta `arn:aws:iam::365470560685:root`; **solo las mismas tres operaciones**. Permite delegarlas mediante IAM para esta clave y conservar una vía administrativa si se borran roles. |
| Publicación cifrada | `social-messaging.amazonaws.com`, `kms:GenerateDataKey*` y `kms:Decrypt`, restringidos a cuenta y origen Social de Irlanda. |

No hay `kms:*` ni descifrado concedido a los operadores/roles de recuperación por estas declaraciones. `Resource:"*"` dentro de la política de clave designa esa clave concreta. La concesión al principal de cuenta habilita delegación IAM; no da acceso automáticamente a todos sus usuarios. Un administrador IAM con permisos suficientes podrá usarla. [Política KMS y recuperación administrativa](https://docs.aws.amazon.com/kms/latest/developerguide/key-policy-default.html).

Se declara **`BypassPolicyLockoutSafetyCheck=false`**. KMS debe comprobar que quien crea/aplica la política conserva `PutKeyPolicy`; el rol de ejecución estable satisface el diseño de esa comprobación. Los principales deben existir y ser visibles en KMS; respetar propagación IAM y detenerse ante `InvalidPrincipal` o fallos de permisos, sin desactivar la comprobación. [Requisitos CloudFormation/KMS](https://docs.aws.amazon.com/AWSCloudFormation/latest/TemplateReference/aws-resource-kms-key.html).

Estas vías reducen el riesgo; no garantizan acceso frente a una clave eliminada, denegaciones explícitas, SCP o pérdida total de administración de la cuenta. Las comprobaciones efectivas y la recuperación real siguen pendientes. No se borra/recrea el rol SSO para probarlo: la continuidad del patrón se comprueba offline, sin alterar asignaciones.

## Permisos preparados y límites

Las políticas completas para revisión/copia están en [deploy/iam-foundation](../deploy/iam-foundation/README.md):

| Archivo | Alcance |
| --- | --- |
| `execution-role-trust.json` | Solo CloudFormation puede asumir el rol de ejecución. Se usa el patrón oficial de rol de servicio; no se inventan condiciones de contexto de extensiones del registro. |
| `execution-ingress-policy.json` | Crear/leer/actualizar/borrar tema y suscripción de demo, tres colas/políticas y bucket propio; crear clave simétrica etiquetada con comprobación anti-bloqueo y administrar clave/alias de staging. |
| `recovery-role-trust.json` | Solo AdministratorAccess SSO de Screen Art con cualquier sufijo válido del patrón. |
| `recovery-policy.json` | Las tres operaciones de política sobre claves de Irlanda etiquetadas para este Bridge/staging. La key policy limita su concesión directa a la clave creada. |
| `operator-additions.example.json` | `iam:PassRole` exclusivamente sobre el rol de ejecución y solo hacia CloudFormation; `sts:AssumeRole` exclusivamente sobre el de recuperación. **Complemento**, no una política completa de operador. |

El permission set AdministratorAccess comunicado ya incluye normalmente estas operaciones; no adjuntar políticas duplicadas automáticamente. SCP, boundaries o session policies aún pueden impedirlas. El futuro operador restringido necesitará también operaciones CloudFormation sobre la pila y las lecturas/cargas S3 autorizadas. [Guía de mínimos privilegios CloudFormation](https://docs.aws.amazon.com/prescriptive-guidance/latest/least-privilege-cloudformation/service-roles-for-cloudformation.html).

Excepciones justificadas de alcance: `kms:CreateKey` necesita `Resource:"*"` y se limita por región/especificación/uso/etiquetas; el ID de clave no existe antes de crearla. El etiquetado inicial debe cubrir las claves de la cuenta/región y se limita por etiquetas solicitadas. `kms:ListAliases` es listado regional. No conceder acceso global de escritura como solución. [Creación de claves](https://docs.aws.amazon.com/kms/latest/developerguide/create-keys.html), [etiquetado inicial KMS](https://docs.aws.amazon.com/kms/latest/developerguide/add-tags.html).

**Esta política de ejecución es para la etapa 1.** Permite cambios y retirada de sus recursos normales; KMS y S3 retenidos no se destruyen al eliminar la pila. La etapa 2 incorpora Lambda, roles de runtime, API, DynamoDB, secretos y colas de salida: requiere ampliar de forma revisada la política del ejecutor antes de ese UPDATE. No intentar desplegarla con la política de ingreso ni conceder AdministratorAccess al ejecutor para evitar esa revisión. El alcance del ensayo no exige hacerlo ahora para obtener el ARN SNS.

## Procedimiento propuesto, todavía no autorizado

1. Pausar la creación de `muserelay-aws-bridge-stage` con el archivo anterior. No se ha desplegado nada por revisar esta nota.
2. Bajo autorización específica, inventariar si existen los dos roles/nombres y la pila IAM. No sobrescribir roles ajenos. Usar la sesión SSO del titular, sin root ni credenciales compartidas.
3. Crear la pila IAM de dos roles con el YAML preparado y reconocer `CAPABILITY_NAMED_IAM`. Es una modificación IAM independiente, no un paso de solo lectura. Alternativamente, crear manualmente esos nombres con sus dos JSON de confianza/permisos; preferimos la plantilla para reproducibilidad. No adjuntar AdministratorAccess a los roles nuevos.
4. Después de `CREATE_COMPLETE`, leer ambos `GetRole` y políticas inline, comprobar las salidas y propagación IAM. Verificar que el acceso de recuperación puede asumirse mediante el SSO autorizado y contrastar STS; no cambiar una key policy ni crear una clave de prueba para hacerlo. No mostrar credenciales temporales.
5. Retomar el ingreso con el **nuevo YAML** y los dos parámetros de la tabla de ARN; seleccionar además el rol de ejecución en **Permisos de pila**. Mantener cuenta/Irlanda y el nombre exacto del stack de transporte. Revisar el change set antes de autorizar su ejecución.
6. Tras crear el ingreso de forma autorizada, leer la política/cifrado de la clave y comprobar los cuatro principales/concesiones. Obtener el ARN real SNS de Salidas; terminar el alta de la WABA demo sigue siendo una operación aparte.

Una vez asociado, el rol de servicio se utiliza en operaciones posteriores; CloudFormation no permite simplemente quitarlo, aunque sí cambiarlo por otro autorizado. Restringir quién puede operar la pila, pues podrá utilizar su rol asociado. No eliminarlo ni sustituirlo sin revisar las políticas de las claves y la recuperación. [Ciclo del rol asociado](https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/using-iam-servicerole.html).

## Recuperación y retirada

- **Cambia el sufijo SSO:** restaurar una asignación autorizada del mismo permission set; el nuevo rol puede asumir el rol estable de recuperación por el patrón. Las referencias KMS a los dos roles estables no cambian.
- **Se borra un rol estable:** recrear el mismo nombre no garantiza recuperar su identidad anterior en políticas de recursos. Utilizar la otra vía autorizada para corregir las referencias de la política. No borrar ambos roles durante la vida de la clave.
- **SSO no está disponible:** recurrir al procedimiento administrativo de emergencia de Screen Art. La concesión limitada al principal de cuenta deja una vía de política; confirmar previamente acceso de emergencia y MFA root con el titular. No usar root en el ensayo del Bridge.
- **Se retira la demo:** desactivar tráfico y retirar recursos según runbook. Las claves y los roles IAM están retenidos; conservar administración hasta resolver retención/borrado aprobado. La eliminación de la pila IAM por sí sola no elimina sus roles. No limpiar roles antes que las claves que dependen de ellos.

## Evidencia y estado

Esta revisión entrega configuración reproducible y validación local; no confirma creación, actualización, rollback ni recuperación real en AWS. La prueba offline cubre cambios de sufijo permitidos, otras cuentas/permission sets rechazados, políticas sin permisos de mensajes/IA y comprobación anti-bloqueo activa. Los informes anteriores a esta revisión describen la plantilla sin estas vías adicionales y no deben usarse para ese aspecto.

El presupuesto de clave de la demo no cambia y no se crea otra clave por añadir administradores. La identidad humana con AdministratorAccess sigue siendo una identidad privilegiada: no se distribuyen sus sesiones a Lambda ni a resellers. No se ha usado el usuario indicado para autenticar herramientas en este servidor.
