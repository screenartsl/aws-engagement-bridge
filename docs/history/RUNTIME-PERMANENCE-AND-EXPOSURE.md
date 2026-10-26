# Qué ejecuta el ZIP y cómo dejar el Bridge permanente

Revisión del 26/10/2026, Europe/Paris. Solo lectura y propuesta local: sin cambios AWS, IAM, lifecycle, consumidores ni Core. Sigue vigente la prohibición de nuevos intentos hasta revisar la corrección SAM/nativa.

## Tres piezas diferentes

1. El ZIP contiene el programa Node.js del transporte y sus dependencias. CloudFormation lo instala en AWS Lambda. El ZIP vigente contiene handlers.mjs y no incluye valores de credenciales de producción.
2. Lambda ejecuta handlers en respuesta a eventos. Para recepción, el origen es SQS. No es necesario mantener un servidor Node escuchando en un puerto o una máquina contratada adicional encendida. El servicio queda configurado y disponible para futuras invocaciones, aunque no ejecute un proceso continuo durante los periodos sin mensajes.
3. El módulo Laravel de MuseRelay conecta esos eventos con los objetos y la bandeja nativa del Core. MuseRelay sigue en su infraestructura actual. La recepción real exige instalar/revisar esa conexión y su autenticación; no se consigue solo subiendo el ZIP.

Recorrido previsto: WhatsApp → AWS Social → SNS → SQS → Lambda Node.js → HTTPS firmado → módulo en MuseRelay → bandeja nativa. La respuesta posterior utiliza su transporte AWS y permisos propios. Agente, herramientas y salida se activan en fases separadas.

## Exposición y controles previstos

La Lambda de entrada propuesta no tiene Function URL ni un evento HTTP/API Gateway. Su origen preparado es SQS y el acceso a Lambda se controla mediante IAM. Una identidad AWS que tenga permiso InvokeFunction, como un administrador autorizado, podría invocarla; no afirmar que únicamente SQS puede hacerlo. Tampoco presentar la falta de URL como inaccesibilidad de la API pública de AWS: esa API existe y exige autorización.

El endpoint HTTPS del Core sí es una dirección accesible desde Internet. El código del conector exige firma HMAC SHA-256 con clave dedicada, cuerpo/ruta/método firmados, ventana temporal, nonce contra repeticiones y binding válido de organización/workspace/canal. El ZIP no lleva esa clave: su inicialización privada está pendiente. La futura API de salida, si se despliega, también sería un endpoint HTTPS público con su autenticación; no se selecciona en la primera recepción.

Se han revisado los controles en código y pruebas aisladas, no mediante una conversación real. No se ha desplegado el runtime por el fallo de preparación SAM. Antes de activarlo se debe comprobar autenticación extremo a extremo, permisos efectivos, rechazo de solicitudes no firmadas/repetidas y aislamiento. No prometer invulnerabilidad por estar en AWS. La seguridad es compartida: AWS administra infraestructura y nosotros código, identidades, configuración y datos.

Los roles de ejecución de Lambda proporcionan la identidad AWS del runtime; no se necesita mantener una sesión de consola del administrador abierta ni reutilizar sus credenciales o las de Bedrock.

## Permanencia sin resubidas periódicas

El modo predeterminado de código Lambda es COPY: al desplegar conserva una copia del paquete en almacenamiento administrado por Lambda. La referencia S3 es el artefacto de despliegue, no un instalador que deba renovarse mensualmente para cada mensaje. La expiración de ese objeto no equivale a borrar la función ya desplegada, pero impide futuras operaciones que necesiten volver a leerlo.

La plantilla actual del bucket tiene una regla global de expiración de objetos a 30 días. El propietario informa caducidad prevista del ZIP el 25/11/2026. Para operación permanente debemos reemplazar ese alcance global por una política que conserve versiones de código activas y de rollback. No solucionar este problema reponiendo el mismo ZIP cada mes.

La propuesta local de propiedades está en [deploy/artifact-retention](../../deploy/artifact-retention/README.md):

- Los archivos completos de `releases/`, incluido el ZIP ya subido, no coincidirían con ninguna regla de expiración de la propuesta.
- Solo `tmp/` caducaría a los 30 días.
- Las partes de subidas multipart incompletas se limpiarían al día; eso no elimina objetos ya completados.
- Las versiones de código se identifican por commit y hash, se conservan privadas y no se sobrescriben. Versiones anteriores activas o necesarias para rollback se retiran solo con una limpieza revisada.

Retain del recurso bucket no sustituye a esta corrección: no desactiva su lifecycle. Tampoco habilitar versionado, por sí solo, elimina la regla de expiración del objeto actual. No se modifican bucket, versionado, IAM ni código en esta entrega.

El fragmento **no es una plantilla desplegable** ni un JSON de la API S3. Se integrará, tras revisión/autorización, en la variante completa SAM/nativa elegida y en la versión real de la pila, conservando todos sus recursos. No utilizar una plantilla antigua de solo ingreso si ya hay runtime desplegado: podría retirar recursos nuevos. Se esperan entonces cambios intencionados del bucket, separados del permiso SAM. Si se combina con la instalación del runtime, el alcance esperado pasaría a cinco Add y un Modify del bucket, y habría que revisar nuevos hashes y conjunto.

Una vez desplegado y autorizado el consumidor, funcionará con mensajes nuevos sin intervención mensual del operador. Solo se subirán paquetes cuando se publique una versión nueva o se necesite recuperar una anterior. Permanencia no significa prescindir de mantenimiento de código/dependencias. La configuración actual sigue siendo de pruebas, con límites y allowlists de demo; eliminar la caducidad no la convierte por sí sola en una configuración de producción ya validada.

Fuentes: [AWS Lambda](https://docs.aws.amazon.com/lambda/latest/dg/welcome.html), [permisos de Lambda](https://docs.aws.amazon.com/lambda/latest/dg/lambda-permissions.html), [almacenamiento COPY](https://docs.aws.amazon.com/AWSCloudFormation/latest/TemplateReference/aws-properties-lambda-function-code.html), [expiración S3](https://docs.aws.amazon.com/AmazonS3/latest/userguide/lifecycle-expire-general-considerations.html), [limpieza multipart](https://docs.aws.amazon.com/AmazonS3/latest/userguide/mpu-abort-incomplete-mpu-lifecycle-config.html).
