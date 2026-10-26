# Runtime creado para revisión: RoleARN null y comprobación efectiva

26/10/2026, Europe/Paris. Propietario comunica CREATE_COMPLETE / AVAILABLE para
receive-sam-review-88161733676d-82707086-fix1, exactamente los cinco Add previstos,
sin otros cambios. Fuente: propietario desde CloudShell, no lectura directa desde
este servidor. No se ha autorizado ejecución ni se ha modificado AWS/Core aquí.

## Corrección de la interpretación de RoleARN

**DescribeChangeSet no incluye RoleARN en su esquema oficial de respuesta.** Un
--query que lo solicite produce null al no existir el campo. No demuestra que la
pila carezca de ejecutor ni que se vaya a usar AdministratorAccess. Tampoco permite
certificar el rol elegido. La octava comprobación del antiguo verificador IAM,
rotulada «Sin nuevo rol de servicio», era insuficiente para esa conclusión y se
ha corregido su etiqueta/documentación; sus otros checks de plantillas/parámetros
no quedan invalidados por este campo. La confianza/permiso efectivo necesita otra
evidencia. No atribuir un rol de ejecución a null de DescribeChangeSet.

Según CreateChangeSet: RoleARN indicado expresamente se utiliza al ejecutar; si
se omite, se usa el asociado previamente a la pila; si tampoco existe, una sesión
basada en las credenciales del operador. ExecuteChangeSet no acepta un RoleARN
para arreglarlo en el último paso. No ejecutar para averiguar qué identidad usa.

## Una herramienta de solo lectura en CloudShell

Descargar del commit revisado `scripts/cloudformation/review-receive-change-set.py`
y guardarlo como `review-receive-change-set.py`. No obtenerlo de ramas cambiantes.

```sh
python3 review-receive-change-set.py --collect
```

Requiere Python 3 y AWS CLI ya presentes en CloudShell con la sesión autorizada.
Default sin --collect no llama a AWS: solo admite un archivo offline --evidence.
No se necesita copiar tokens ni configurar este servidor. No usar --debug.

Operaciones exactas:

- STS GetCallerIdentity: detenerse si Account no es 365470560685.
- CloudFormation DescribeChangeSet, DescribeStacks y GetTemplate Original/Processed,
  únicamente pila/conjunto indicados.
- IAM GetRole, ListAttachedRolePolicies, ListRolePolicies y GetRolePolicy de las
  dos políticas del ejecutor stage-cfn-execution. Nunca edita IAM ni lo asume.
- CloudTrail LookupEvents de CreateChangeSet, ventana +/-10 minutos de CreationTime
  (hasta ahora si el límite caería en futuro), hasta cuatro páginas de 50. Los
  eventos se filtran en memoria por conjunto; no imprime el resto ni cuerpos.

No CreateChangeSet/ExecuteChangeSet, GetSecretValue, S3, ReceiveMessage, DynamoDB
data API, Lambda Invoke, cambios o envíos. No escribe evidencias, caches, cuerpos
de eventos o respuestas en disco; AWS CLI utiliza la sesión existente. El código
captura stdout de CLI solo en memoria y muestra PASS/REVISAR, ARN de rol esperado
y el resultado. Las pruebas locales usan exclusivamente fixtures sintéticas.

La lectura de Processed admite JSON objeto o JSON codificado como string; Original
acepta el YAML idéntico al revisado o JSON estructuralmente equivalente. Si AWS
devuelve Processed como YAML, no instalar dependencias ni convertirlo sin revisar:
se detiene con REVISAR. Una respuesta inesperada o AccessDenied también detiene;
no cambia permisos como reacción automática.

## Quince comprobaciones

1. Cuenta/región/stack/conjunto y StackId coincidentes.
2. CREATE_COMPLETE / AVAILABLE y pila estable.
3. Exactamente los cinco Add, sin sustituciones ni cambios de ingreso.
4. Todos los parámetros efectivos coinciden con la entrega cerrada.
5. Original íntegro igual a template 881617... revisado.
6. DescribeStacks.RoleARN es el ejecutor limitado stage-cfn-execution.
7. CloudTrail acredita creación con ese rol o sin override, heredándolo de la pila.
8. Trust exclusiva cloudformation.amazonaws.com y ausencia de boundary inesperado.
9. Ninguna managed policy adjunta y exactamente las dos inline previstas.
10. Ambas inline íntegras iguales a la corrección SAM revisada, incluidos KMS Deny.
11. Mapping efectivamente Enabled=false, BatchSize=1 y MaximumConcurrency=2.
12. Inbound sin ReservedConcurrentExecutions y con su rol runtime propio.
13. Entorno Lambda con procesamiento, recepción, envío y LiveSend false.
14. Binding disabled, destinatarios vacíos y DeployOutbound=false.
15. Diecisiete recursos seleccionados: doce previos y cinco nuevos, una Lambda,
    sin nuevos recursos IAM/API/URL/nested/custom.

Las condiciones de SAM se resuelven localmente con los parámetros efectivos; no
basta con mostrar un Ref a ReceiveEnabled y suponer que es false. No se comparan
solo los valores por defecto ni se ignoran parámetros desconocidos.

Si CloudTrail aún no ha publicado el evento, no se afirma identidad verificada:
queda REVISAR. Esperar su disponibilidad y repetir únicamente lecturas. Si el rol
de la pila es null/diferente o el evento indica otro rol, no ejecutar ni modificar
la pila: preparar otra propuesta con --role-arn explícito y revisión separada.
No borrar el conjunto actual, añadir IAM o aplicar BridgeTools para solucionarlo.

Pegar las líneas PASS/REVISAR y el resultado. Incluso todos los PASS solo cierran
la revisión técnica: después hay que presentar costes y solicitar autorización
específica de ejecución. No autorizan consumidores, inicialización de firmas,
modificación de MuseRelay, mensajes, agentes ni herramientas.

Límites: no prueba conectividad Core, recursos aún no creados, SCP/Organizations,
permisos efectivos completos de los roles runtime, entrega de mensajes ni S3 real.
Las evidencias y el rol deben seguir vigentes justo antes de ejecutar; no cubre
cambios administrativos posteriores. Event history solo contiene eventos de
gestión y su lectura no tiene cargo CloudTrail; no es auditoría de mensajes.

Fuentes oficiales:
- https://docs.aws.amazon.com/AWSCloudFormation/latest/APIReference/API_DescribeChangeSet.html
- https://docs.aws.amazon.com/AWSCloudFormation/latest/APIReference/API_CreateChangeSet.html
- https://docs.aws.amazon.com/AWSCloudFormation/latest/APIReference/API_ExecuteChangeSet.html
- https://docs.aws.amazon.com/awscloudtrail/latest/userguide/how-cloudtrail-works.html
