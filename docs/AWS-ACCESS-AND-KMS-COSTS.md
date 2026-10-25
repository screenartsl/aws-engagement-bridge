# Costes KMS y acceso AWS para el Bridge

Revisión documental: **25-10-2026, Europe/Paris**. Recoge la explicación aportada por el usuario y sus matices para futuros presupuestos. No crea claves, identidades, MFA, permisos ni recursos. Cuenta prevista: Screen Art S.L. `365470560685`, Irlanda `eu-west-1`.

**Actualización posterior del usuario:** Identity Center operativo, usuario `antoni.caimari`, AdministratorAccess y MFA SSO funcionando. No comprobado por API desde aquí. La decisión actual sobre roles estables, clave y recuperación prevalece en [AWS-KMS-ADMINISTRATION.md](AWS-KMS-ADMINISTRATION.md); MFA root continúa sin confirmar.

## 1. Dos conceptos de coste diferentes

Una clave KMS propia tiene un coste base de **USD 1/mes, prorrateado por horas**, más solicitudes facturables. **USD 0,13 durante cuatro días es una estimación de permanencia, no de cuatro usos.** Los ejemplos oficiales muestran USD 0,03/10.000 solicitudes para determinadas operaciones simétricas; no es una tarifa universal. Revisar región, operación, tipo de clave, franquicia aplicable y recargos de rotación. [Precio oficial KMS](https://aws.amazon.com/kms/pricing/).

| Ejemplo orientativo | Coste base de mantener una clave, sin solicitudes |
| --- | ---: |
| Cuatro días / 96 horas | Aproximadamente USD 0,13. |
| Un mes completo | USD 1,00 para la clave simple del ejemplo. |

No se factura un periodo fijo de treinta días por encender una clave cuatro días; el redondeo anterior sirve para presupuestar, no sustituye la factura horaria. Para un servicio permanente hay que revisar especialmente las rotaciones: no prometer que el total de almacenamiento siempre seguirá en USD 1 tras rotarla.

Ejemplo aritmético **sin descontar free tier**: si 100.000 solicitudes fueran facturables a USD 0,03/10.000, el uso sumaría USD 0,30. Con un mes base de esa clave, serían USD 1,30 **solo de KMS bajo esas hipótesis**, antes de impuestos. No es una medición del Bridge ni una tarifa por conversación. No se presupone franquicia disponible para esta cuenta.

## 2. Compartir una clave no significa pagar por teléfono

En el diseño propuesto, una misma clave puede proteger el tema que recibe eventos de varios números autorizados. Cien números no implican automáticamente cien claves. La partida se calcula por las claves realmente elegidas y el uso facturable; no multiplicar por WABA/número salvo que se decida crear una clave dedicada para cada uno.

Esta posibilidad no configura por sí misma aislamiento entre clientes ni decide el diseño comercial. Propuesta pendiente: clave propia de staging, separación para producción y claves adicionales por partner cuando lo justifiquen sus requisitos. BYO AWS, región y políticas pueden requerir recursos distintos. No se cambia la clave dedicada que ya describe la plantilla de demo.

SNS puede reutilizar claves de datos; **un WhatsApp no equivale necesariamente a una petición KMS**. Para estimar, AWS considera temas, publicadores y reutilización de claves de datos, y advierte que el valor real puede ser mayor. No deducir solicitudes a partir del número de teléfonos. [Estimación KMS para SNS](https://docs.aws.amazon.com/sns/latest/dg/sns-key-management.html).

El presupuesto futuro debe separar estas líneas: claves y solicitudes KMS; transporte Social y tarifas Meta; SNS/SQS; Lambda/API; almacenamiento/historial; secretos; logs/copias; IA. KMS no incluye transporte WhatsApp ni almacenamiento ilimitado de conversaciones. Las [estimaciones del ensayo](CLOUDFORMATION-DEPLOYMENT.md) no constituyen el precio comercial de MuseRelay.

La plantilla usa `Retain` para la clave: eliminar la pila no elimina la clave ni detiene automáticamente sus cargos. El [procedimiento de retirada](CLOUDFORMATION-DEPLOYMENT.md) exige inventariar recursos retenidos y autorizar su limpieza. No borrar una clave mientras otro recurso dependa de ella.

## 3. Root AWS y las identidades de trabajo

AWS recomienda reservar root para tareas excepcionales que lo requieran, protegerlo con MFA y evitar claves de acceso root. Para trabajo habitual, utilizar identidades autorizadas con credenciales temporales y permisos delimitados. IAM Identity Center/federación o roles se evaluarán según la estructura de la cuenta. [Buenas prácticas root](https://docs.aws.amazon.com/IAM/latest/UserGuide/root-user-best-practices.html), [identidades temporales y mínimos permisos](https://docs.aws.amazon.com/IAM/latest/UserGuide/best-practices.html).

El usuario `root` de Linux, una cuenta GitHub y el root de AWS **son identidades distintas**. Tener una shell root o poder subir Git no demuestra identidad ni permisos AWS. Una etiqueta visible en la consola tampoco sustituye la verificación de identidad efectiva.

La propuesta no es crear una identidad con acceso total para todos los procesos. Conviene distinguir responsabilidades:

| Identidad | Alcance que se busca |
| --- | --- |
| Persona administradora | Acceso humano autenticado, MFA y capacidades administrativas autorizadas; mantenimiento de acceso/recovery. |
| Operador/ejecutor CloudFormation | Solo las operaciones necesarias para esta pila; revisar rol de servicio, políticas, límites y permisos de paso de roles. |
| Funciones Lambda | Tres roles de runtime limitados a sus recursos; únicamente el emisor puede enviar por el teléfono aprobado. |
| Agente Bedrock de demo | Identidad independiente, modelos/perfiles y destinos permitidos; no hereda administración de infraestructura. |
| Futuro partner/BYO | Acceso temporal delimitado, confianza/revocación explícitas; no credenciales root del partner. No implementado. |

Los detalles de recursos y permisos siguen en el [runbook CloudFormation](CLOUDFORMATION-DEPLOYMENT.md). El nivel de permiso de un administrador humano no debe trasladarse a Lambda ni a automatizaciones para resellers.

## 4. Secuencia futura de preparación del acceso

Estas acciones **no se han ejecutado y necesitan la autorización aplicable**:

1. Comprobar qué acceso autorizado ya existe y si la cuenta es independiente o pertenece a una organización. No habilitar Identity Center, crear una organización ni duplicar identidades automáticamente.
2. Confirmar protección MFA y recuperación de root sin pedir contraseña, código, QR, semilla ni captura de secretos. Su estado actual permanece **pendiente de confirmar**; el texto recibido propone la pregunta, no aporta la respuesta.
3. Elegir/reutilizar una identidad humana autorizada con sesiones temporales y MFA. Si necesita crearse o asignarse acceso, presentar primero los cambios concretos. Root solo se usaría si la tarea realmente lo requiere y bajo control del titular; disponer de root no convierte su uso en un requisito para todo este proceso.
4. Confirmar cuenta Screen Art e identidad efectiva mediante lectura autorizada. Revisar permisos, SCP/boundaries y recursos existentes antes de proponer despliegue. No compartir credenciales en chat ni copiarlas al repositorio.
5. Identificar el ARN IAM no-root efectivo que administrará la clave como `KmsAdministratorArn`. Si CloudFormation utiliza un rol de servicio, será su ARN IAM, no el ARN STS de la sesión ni el nombre de la persona. Mantener esa identidad mientras exista la clave retenida y evitar que una reasignación/borrado deje sin administración la clave.
6. Revisar alcance/coste/change set y solicitar autorización de creación de recursos. Tener MFA o una identidad correcta no autoriza por sí solo desplegar, activar el Bridge ni enviar mensajes.

Puede hacerse desde una consola autenticada; no es necesario entregar claves al arquitecto. Para preparar el Bridge bastan los identificadores no secretos y las evidencias de permisos acordadas. La creación/asignación de acceso AWS es independiente del código de MuseRelay y no exige cambiar sus paneles o canales.

## 5. Estado que queda registrado

| Dato | Estado conocido |
| --- | --- |
| Cuenta/región objetivo | `365470560685` / `eu-west-1`, facilitadas por el usuario. |
| Identidad efectiva de despliegue | Pendiente de identificar y verificar; no se ha utilizado root AWS desde esta sesión. |
| MFA root | Pendiente de confirmar por el titular; no se solicitan sus valores. |
| Identity Center / roles administrativos existentes | El usuario comunica SSO AdministratorAccess con MFA; los dos roles estables nuevos siguen sin crear/verificar. Sin inventario API. |
| Clave KMS de staging | Definida en plantilla local, sin creación ni ARN real verificado aquí. |
| Permisos / despliegue / envíos | Sin cambios ni autorización nueva por este documento. |

Esta nota complementa las [ideas de modalidades futuras](FUTURE-HYBRID-ARCHITECTURE.md) y el [onboarding WhatsApp](AWS-WHATSAPP-ONBOARDING.md). Mantiene separadas la explicación de costes, la gestión de acceso y la autorización para ejecutar.
