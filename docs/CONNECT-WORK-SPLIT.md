# Conectar la demo: trabajo en paralelo y autorizaciones

25/10/2026, Europe/Paris. Se mantiene el alcance de recepción primero. No se interpretan «vamos a conectarlo todo» ni la creación del usuario demo como autorización para desplegar recursos, modificar producción o consumir/envíar mensajes reales.

## Qué prepara el arquitecto

- Runtime de recepción compatible con la cuota comunicada (10 total/10 no reservadas): sin reserva de función inbound y máximo dos invocaciones de su mapping SQS, que permanece deshabilitado. No aumentar cuotas ni cambiar la fundación IAM.
- ZIP verificable, plantilla y parámetros para descargar desde un **draft del repositorio privado**. Los archivos de revisión no instalan nada en AWS. No usar el draft anterior receive-review-62dab78f, marcado SUPERSEDED.
- Integración siguiente de UI/backend en una copia aislada: entrada en Megadmin → Módulos, permiso exclusivo a organización 227 y formulario AWS en Canales; pruebas de límites de organización, regresión y reversión. **Esa implementación sigue pendiente**, no se ha aplicado por preparar el runtime.
- Revisión concreta del conjunto AWS que presente el propietario, sin ejecutar desde este servidor ni pedir credenciales administrativas.
- Preparación de instalación Core con backup fresco, restauración comprobada, revisión de cambios concurrentes y actividad del reseller antes de solicitar la autorización de producción.

## Qué hace el propietario con GPT en la consola AWS

1. Hecho: GetAccountSettings de solo lectura. Se comunicaron ConcurrentExecutions=10, UnreservedConcurrentExecutions=10, FunctionCount=0 y TotalCodeSize=0. La plantilla anterior con reserva 1 no debe desplegarse.
2. Completar las lecturas de políticas adjuntas, listas inline y boundaries indicadas en `deploy/runtime-review/README.md`. No cambiar permisos como reacción a una diferencia.
3. Tras autorización **específica de subida**, descargar bridge.zip del nuevo draft privado y subirlo al bucket/clave exactos del manifiesto vigente. Mantener privacidad, AES256 y política existentes. No subir el ZIP de código fuente ni la plantilla antigua. El paquete mide 274324 bytes; con S3 Standard, una sola subida y su almacenamiento durante un mes se estiman por debajo de USD 0.01, excluyendo otras operaciones/configuraciones/costes existentes. Es una estimación, no un límite de facturación. La subida no crea funciones, secretos, tablas ni consumidores.
4. Verificar checksum S3 y preparar el UPDATE de la pila existente muserelay-aws-bridge-stage, con los parámetros vigentes y el ejecutor correcto. Esperar CREATE_COMPLETE / AVAILABLE y compartir cinco Add, cero modificaciones del ingreso, cero sustituciones/eliminaciones y mapping Enabled=false.
5. Revisar y autorizar por separado una ejecución de ese conjunto concreto. Después comprobar recursos y mapping Disabled. No inicializar claves, activar consumidores o probar una invocación como parte automática del despliegue.

## Puertas posteriores

- Instalación y habilitación dormante en MuseRelay: autorización propia, solo organización 227. No activa el circuito AWS.
- Recepción real: HTTPS/HMAC verificados, canal/binding propietario, controles específicos y consentimiento que cubra también el backlog existente en SQS. No purgar ni seleccionar a ciegas solo un evento nuevo. Probar primero una entrada en la bandeja nativa sin agente ni salida.
- Respuesta humana, agente Bedrock y ticket confirmado: siguientes fases. SocialSend sigue sin concederse. No probar envíos ni herramientas reales ahora. Calendario y comercialización para terceros quedan fuera de esta primera recepción.

El usuario demo es propietario normal, no reseller. Organización real 227, Screen Art Demo Workspace, workspace 312; aún sin canal AWS ni permiso efectivo del Bridge. Los mensajes deben reutilizar la bandeja nativa y conservar transporte AWS; no crear otra bandeja ni caer al transporte Meta directo.

## Prompt inicial para GPT

```text
Vamos a preparar la recepción AWS Engagement Bridge en Screen Art, cuenta 365470560685, región eu-west-1. Ayúdame paso a paso en la consola. No desplegar ni activar nada todavía.

IAM Foundation está UPDATE_COMPLETE. Lambda GetAccountSettings devuelve cuota total/no reservada 10, cero funciones. El arquitecto ha sustituido la plantilla anterior: inbound sin ReservedConcurrentExecutions, mapping SQS MaximumConcurrency=2 y Enabled=false. No solicitar aumento de cuota ni usar el draft antiguo receive-review-62dab78f.

Descargaremos del nuevo draft PRIVADO de screenartsl/aws-engagement-bridge los archivos bridge.zip, bridge.zip.sha256, runtime.template.yaml y parameters.disabled.json. Si no puedes acceder a GitHub privado, pídeme esos archivos; no pidas tokens ni inventes parámetros.

Primero revisaremos las lecturas IAM pendientes. La subida del ZIP a S3 requiere mi autorización específica. Una vez autorizado, usaremos únicamente el bucket y clave del manifiesto vigente, con privacidad y cifrado existentes, y comprobaremos el checksum. No crear buckets ni cambiar políticas/IAM.

Después prepararemos, sin ejecutar, un conjunto UPDATE de la pila existente muserelay-aws-bridge-stage. Usaremos el rol muserelay-aws-bridge-stage-cfn-execution. DeployProcessing=true solo para proponer los recursos; DeployOutbound, ProcessingEnabled, ReceiveEnabled, SendEnabled y LiveSend permanecen false, binding disabled y destinatarios vacíos.

Esperamos cinco Add: State, InboundFunction, InboundLogs, SigningSecret e InboundFunctionInput. Ningún cambio del ingreso existente, ninguna eliminación/sustitución y ningún rol IAM nuevo. Detenerse si difiere. Compartiremos el conjunto completo con el arquitecto antes de autorizar su ejecución. No modificar MuseRelay, inicializar secretos, consumir SQS, invocar Lambda/Bedrock ni enviar WhatsApp.
```

Fuentes: [concurrencia máxima de SQS](https://docs.aws.amazon.com/lambda/latest/dg/services-sqs-scaling.html), [precios S3](https://aws.amazon.com/s3/pricing/). El precio exacto depende de la región, clase y operaciones de la cuenta; no es autorización de gasto.
