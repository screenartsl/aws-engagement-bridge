# Verificación posterior del ingreso y preparación del destino WhatsApp

Fecha: 25-10-2026, Europe/Paris. Cuenta Screen Art `365470560685`, región `eu-west-1`. **Solo lectura y preparación: no autoriza cambios AWS, conexión de WABA, consumidores, agentes, envíos ni runtime.**

## Estado y procedencia de la evidencia

El usuario comunica la pila `muserelay-aws-bridge-stage` en CREATE_COMPLETE y aporta el ARN SNS real. Posteriormente comunica comprobaciones realizadas desde su CloudShell sobre CloudFormation, SNS, KMS y suscripción. El agente no dispone de AWS CLI, perfil, variables de credenciales ni conector AWS: **no ha realizado consultas autenticadas AWS**. Se registran los resultados recibidos sin presentarlos como inspección independiente. Las respuestas se han facilitado como resumen, no como JSON bruto de las políticas.

| Comprobación | Estado actual |
| --- | --- |
| Pila, rol ejecutor y parámetros | CREATE_COMPLETE, ejecutor y recuperación correctos, según verificación CloudShell facilitada. |
| Tema SNS | ARN real comunicado: `arn:aws:sns:eu-west-1:365470560685:muserelay-aws-bridge-stage-inbound`. |
| Publicación SNS | Usuario confirma principal Social con SourceAccount/SourceArn restringidos. |
| Cifrado SNS | Usuario confirma clave dedicada `d712bd69-6b20-48b2-918a-fea097f7241a`. |
| KMS | Usuario confirma Enabled/true, SYMMETRIC_DEFAULT, ENCRYPT_DECRYPT, CUSTOMER, AWS_KMS y MultiRegion=false. |
| Política KMS real | Usuario confirma las cuatro declaraciones, principales, acciones y condiciones coincidentes con el YAML revisado. |
| Suscripción SNS | Una confirmada, cero pendientes; protocolo sqs, destino de entrada correcto y ARN coincidente con CloudFormation. |
| RawMessageDelivery y redrive de suscripción | **Pendientes de lectura posterior al despliegue.** |
| SQS: cifrado, políticas, retención y redrive | **Pendientes de lectura posterior al despliegue.** |
| S3: bloqueo público, cifrado, propietario y política | **Pendientes de lectura posterior al despliegue.** |
| Ausencia de mappings Lambda sobre las tres colas | **Pendiente.** El YAML de ingreso no declara runtime; revisar también mappings externos a la pila. |
| Destino Social/WABA | Usuario confirma que no se ha configurado; IDs AWS de WABA/teléfono pendientes de identificar. |
| Pruebas de eventos, envíos y agentes | No realizadas. No autorizadas por este procedimiento. |

[Registro de evidencia recibida](evidence/ingress-deployment-user-evidence.json). El YAML no cambia: commit de referencia `5f563ea4387c9694782943566614e38b9463168a`, SHA-256 `4bc773772e50645680a5ffacdb25fdd8100a7f4a07ea8c6c185d461295f023cb`.

## Recogida única de metadatos desde CloudShell

Se proporciona [collect-ingress-readonly.py](../scripts/cloudformation/collect-ingress-readonly.py), autocontenido, Python estándar y AWS CLI. Descargarlo desde el repositorio privado y subirlo al directorio privado de CloudShell; también puede utilizarse desde una copia privada ya disponible del repositorio. No requiere instalar dependencias, configurar claves en el servidor ni cargar el Core.

Desde la raíz de la copia del repositorio:

```sh
python3 scripts/cloudformation/collect-ingress-readonly.py --dry-run
python3 scripts/cloudformation/collect-ingress-readonly.py > ingress-readonly.json
```

Si se ha subido solo ese archivo a CloudShell, usar `python3 collect-ingress-readonly.py` con los mismos argumentos. El primer comando no llama a AWS. El segundo usa exclusivamente las lecturas enumeradas por el primero, empezando por STS y bloqueando otra cuenta, root o una sesión que no sea el permission set SSO AdministratorAccess esperado. Después consulta la pila, plantilla original, tema, suscripción, clave, tres colas, mappings Lambda filtrados por cola y configuración del bucket. No consulta cuerpos de mensajes, objetos S3, secretos, modelos ni credenciales. No usa debug ni imprime stderr de AWS sin filtrar.

La salida JSON contiene metadatos, plantilla y políticas para revisión. `REQUIRES_POLICY_REVIEW` significa recogida, **no certificación automática**. `PARTIAL_REQUIRES_POLICY_REVIEW` incluye lecturas fallidas: no interpretarlas como ausencia de recursos. El script no cambia permisos ni intenta reparar errores. Compartir únicamente esa evidencia de configuración por el canal privado autorizado; nunca `.aws`, tokens, OTP, PIN ni credenciales de sesión.

Siete pruebas con respuestas sintéticas, sin red, comprueban bloqueo de identidad/cuenta, parada ante salidas o recursos runtime inesperados antes de leer la plantilla, rechazo de operaciones de escritura/lectura de mensajes, tratamiento de AccessDenied y ausencia de un falso PASS de políticas. No prueban los servicios AWS reales.

## Criterios para revisar la salida

| Recurso | Resultado esperado |
| --- | --- |
| CloudFormation | Doce recursos de ingreso completos, sin IAM, Lambda, API, DynamoDB, secreto o runtime; service role ejecutor y parámetros exactos. Plantilla original igual a la fuente revisada; Retain y UpdateReplacePolicy Retain de SnsKey y ArtifactBucket. |
| SNS | Standard, 256 KiB, cuenta correcta, KmsMasterKeyId resuelto a la clave dedicada; política de publicación Social restringida a cuenta/origen Social en Irlanda. Una única suscripción al destino de entrada previsto. |
| KMS | Propiedades ya comunicadas; alias `alias/muserelay-aws-bridge-stage-sns` apunta a la misma clave; rotación automática false, etiquetas de staging y cuatro declaraciones revisadas. No añadir un principal SNS por esta asociación. |
| Suscripción | TopicArn exacto, protocolo sqs, Endpoint `arn:aws:sqs:eu-west-1:365470560685:muserelay-aws-bridge-stage-inbound-queue`, `RawMessageDelivery="false"`, sin filtros, RedrivePolicy a `...:muserelay-aws-bridge-stage-sns-delivery-dlq`. |
| Entrada SQS | SSE-SQS true; retención 345600 s; visibilidad 180 s; RedrivePolicy a `...:muserelay-aws-bridge-stage-inbound-dlq`, maxReceiveCount 5; permiso SNS SendMessage solo desde el tema/cuenta previstos. |
| DLQ de procesamiento | SSE-SQS true; retención 1209600 s; RedriveAllowPolicy byQueue con una sola cola de origen: la entrada. |
| DLQ de entrega SNS | SSE-SQS true; retención 1209600 s; permiso SNS SendMessage restringido al tema/cuenta previstos. |
| Mappings Lambda | Lista vacía para las tres colas. Esto no demuestra ausencia de un poller externo distinto de Lambda; no arrancar ni detener workers compartidos. |
| Bucket | `muserelay-aws-bridge-stage-artifacts-365470560685-eu-west-1`, Irlanda, SSE-S3 AES256, cuatro bloqueos públicos true, BucketOwnerEnforced, política Deny de transporte inseguro para identidades no servicio y ciclo de artefactos a 30 días/multipart a un día. No es el bucket auxiliar de plantillas de consola. |

Los atributos de SNS/SQS pueden contener JSON serializado dentro de cadenas; revisar los objetos de Policy/RedrivePolicy después de decodificar, no por coincidencia textual de fragmentos. No aprobar una política por conservar solamente el Sid. Normalizar ID o ARN de KmsMasterKeyId contra el ARN/UUID de la clave, y el orden de declaraciones sin ignorar concesiones adicionales. Contadores SQS aproximados no equivalen a una prueba de entrega ni a que un mensaje se haya procesado. No abrir la función de sondeo de mensajes de la consola para esta auditoría.

Lecturas oficiales: [SNS atributos](https://docs.aws.amazon.com/cli/latest/reference/sns/get-topic-attributes.html), [SQS atributos](https://docs.aws.amazon.com/cli/latest/reference/sqs/get-queue-attributes.html), [KMS política](https://docs.aws.amazon.com/cli/latest/reference/kms/get-key-policy.html), [mappings Lambda](https://docs.aws.amazon.com/cli/latest/reference/lambda/list-event-source-mappings.html). La compatibilidad de principal y condiciones se revisó en [SNS-KMS-COMPATIBILITY.md](SNS-KMS-COMPATIBILITY.md).

**Punto de control:** completar SQS/S3/atributos de suscripción antes de aprobar la conexión operativa. Una discrepancia requiere revisar evidencia y, si procede, un nuevo cambio autorizado por infraestructura como código; no editar políticas directamente ni reenviar mensajes para diagnosticar.

## Guía preparada para asociar el destino Social — detenerse antes de guardar

1. Entrar con la identidad SSO autorizada en AWS End User Messaging Social, misma cuenta y región. Abrir **Business accounts / Cuentas empresariales** y consultar la WABA de demostración.
2. Comprobar el ID Meta **29207063275646749** y el número de demostración **+34 871 99 91 69**. Copiar los identificadores AWS reales de WABA y teléfono, además del metaPhoneNumberId. No deducirlos del número visible ni sustituir el ID AWS `waba-…` por el numérico Meta. Revisar **todos** los teléfonos de la WABA y su destino actual; no modificar un destino que atienda otros canales/clientes.
3. Si el registro AWS ya está COMPLETE y contiene el número correcto, utilizar esa cuenta vinculada. Si aparece INCOMPLETE o no aparece, revisar/reanudar el alta de demostración pendiente antes de cambiar nada. Puede ser necesaria otra autenticación interactiva si caducó la sesión; no prometer que Meta nunca pedirá repetir pasos. No crear otro número ni migrar un número de los canales actuales por defecto.
4. Para una WABA vinculada correcta: **Event destinations / Destino de eventos → Edit destination / Editar destino → Enable → Amazon SNS**. Preparar este ARN existente:

```text
arn:aws:sns:eu-west-1:365470560685:muserelay-aws-bridge-stage-inbound
```

5. Revisar el alcance y **detenerse antes de Save / Guardar o de la confirmación final del registro**. La plantilla ya prepara los permisos Social/SNS/KMS; no crear un tema alternativo ni conceder IAM adicional automáticamente. La autorización para desplegar ingreso no autoriza por sí sola esta asociación.

AWS permite un destino de eventos por WABA y publica en él los eventos de todos sus números. Guardar la asociación inicia potencialmente la entrada de eventos en SNS/SQS, incluso con los agentes apagados; puede generar uso facturable y almacenamiento de contenido. No es una operación de solo lectura. [Procedimiento y alcance oficial de AWS Social](https://docs.aws.amazon.com/social-messaging/latest/userguide/managing-event-destinations-add.html).

Para el caso de alta aún incompleta, el flujo integrado puede vincular/migrar activos; el ID/número debe revisarse antes de confirmar. [Alta oficial de WhatsApp](https://docs.aws.amazon.com/social-messaging/latest/userguide/getting-started-whatsapp.html). Conservar PIN/OTP fuera de capturas, Git, logs y chat. La localización Meta EU (Germany) comunicada no cambia automáticamente la región AWS del tema.

## Después de una autorización futura de asociación

El titular guardaría únicamente el destino de demo y aportaría confirmación de WABA/número/destino. Releer el recurso Social permite comprobar registrationStatus COMPLETE y eventDestinations con el ARN SNS correcto; no demuestra entrega de mensajes. [Campos oficiales de la cuenta vinculada](https://docs.aws.amazon.com/cli/latest/reference/socialmessaging/get-linked-whatsapp-business-account.html).

Seguirían sin instalarse consumidores, runtime, agentes ni envíos. No publicar mensajes SNS de prueba, no enviar WhatsApp, no invocar Bedrock, no recibir/borrar/purgar/redirigir mensajes SQS. Los eventos que lleguen permanecerían en la entrada hasta consumo autorizado o expiración (cuatro días); no prometer una bandeja histórica ilimitada ni activar un consumidor para vaciarla. La aplicación MuseRelay y los canales Meta actuales no se cambian en este procedimiento.

Para deshacer una asociación futura, se prepararía una operación autorizada sobre **solo el destino de demo**, revisando todos los números afectados. Desconectar el destino no requiere borrar la WABA o el teléfono; no retirar SNS mientras Social siga apuntando a él. La eliminación de colas, bucket o clave y la retención de datos son decisiones posteriores independientes.
