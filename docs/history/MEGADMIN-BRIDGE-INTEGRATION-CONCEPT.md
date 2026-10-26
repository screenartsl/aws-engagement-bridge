# AWS Engagement Bridge en Megadmin → Módulos

Revisión de concepto, 25/10/2026 (Europe/Paris). Solo lectura del Core actual y preparación documental; no instalación, cambios de permisos, activación ni modificaciones de producción.

## Encaje en el sistema existente

La página `/megadmin/modules` ya dispone de un catálogo central y permisos por plan, organización y usuario. Su cabecera tiene además el enlace a `/megadmin/extensions`, donde el cargador instalado permite habilitar/deshabilitar paquetes confiables y seleccionar organizaciones. El Bridge figura en config/extensions.php, pero **no está registrado como un modo AWS en el catálogo de canales ni en su formulario**.

Fuentes revisadas del Core `594a042c916120e353820ecbe12631473006abb2`: config/modules.php, config/extensions.php, ModuleAccessController, ModuleCatalog, ModuleAccess, EnforceModuleAccess, ExtensionsController/ExtensionManager, vistas superadmin/modules y superadmin/extensions, y ChannelController. La URL autenticada no pudo visualizarse con la herramienta web; la evidencia es el código local, no una inspección de una sesión de Megadmin.

La integración debe usar **esa misma sección de Módulos como entrada y administración**, conservando el cargador técnico y sus comprobaciones, en lugar de crear otro panel administrativo independiente. La existencia del enlace actual no equivale a tener ya implementada esta unificación.

## Diseño propuesto, todavía no implementado

| Lugar | Responsabilidad |
| --- | --- |
| Megadmin → Módulos | Entrada AWS Engagement Bridge / WhatsApp vía AWS, disponibilidad, organización autorizada, estado técnico y acceso a su configuración |
| Megadmin, detalle del módulo | Paquete/versiones, comprobaciones, permiso efectivo, habilitación/deshabilitación y controles independientes de recepción/agente/envío |
| Canales del usuario autorizado | Crear/configurar el canal AWS desde la pantalla habitual, con comprobaciones de backend equivalentes a la visibilidad del formulario |
| Bandeja actual de MuseRelay | Contactos, conversaciones, mensajes, asignación/intervención humana y enlace con agentes/herramientas nativas |
| AWS | Roles temporales para Lambda, SNS/SQS y ejecución del transporte; sin acceso administrativo desde el formulario cliente |

Propuesta de clave del catálogo: `whatsapp.aws_social`, hija de `whatsapp`, con estado de fábrica **internal**, no stable ni beta para todos los testers. Solo se concedería un override a la organización demo. No tocar los modos BYOK/Meta/Telnyx de las demás organizaciones. No dar un permiso global a todas las cuentas por instalar el paquete.

La demo identificada en modo read-only pertenece a la organización **227**, nombre real **Screen Art Demo Workspace**, con workspace **312**; no es reseller y tiene cero canales. Es el ámbito previsto, aún **no autorizado efectivamente**. Se verificó la propiedad del usuario indicado por el dueño, sin copiar correos/contraseñas al repositorio ni al staging. No convertirla en reseller ni crear otra organización duplicada.

## Seguridad: catálogo y ejecución deben coordinarse

Hay dos mecanismos existentes con funciones diferentes:

- ModuleCatalog/ModuleAccess decide visibilidad y acceso del usuario, con jerarquía de estados y overrides.
- ExtensionManager y el Store del Bridge deciden si el paquete puede ejecutar y qué organizaciones están autorizadas.

No convertirlos en dos interruptores contradictorios. La futura pantalla de Módulos debe presentar un estado efectivo y usar los servicios existentes, manteniendo sus controles técnicos. Un permiso de usuario/plan **no debe** saltarse el allowlist obligatorio del runtime para la organización 227. Dejar el módulo en internal y conceder un override solo no habilita recepción automáticamente.

Permiso para configurar un canal AWS: módulo disponible para esa organización + paquete habilitado para ella + rol nativo autorizado para gestionar canales + pertenencia al workspace. Para recibir/procesar o enviar, exigir además binding y controles propios. No exigir un binding ya conectado para mostrar un formulario que precisamente lo configura. Validar todos esos límites también en POST/API/worker, no solo en el menú.

El middleware EnforceModuleAccess excluye `/api/*` y las rutas sin prefijo de módulo. Por tanto, añadir una fila al catálogo **no protege** por sí solo el endpoint de eventos. La autenticación HMAC, mapeo de organización y controles técnicos del Bridge deben mantenerse independientes de la sesión del evaluador. La recepción no requiere que el usuario tenga el navegador abierto.

Los overrides del catálogo pueden permitir acciones de UI a usuarios concretos; no usarlos como única garantía de exclusividad del Bridge. Estado off debe bloquear las operaciones correspondientes también en backend. Los workers no tienen usuario conectado: su autorización debe resolverse a partir de organización/binding, no con un usuario superadmin artificial.

El usuario demo solo podría crear un canal AWS si se implementa y revisa expresamente esa restricción. El sistema actual no la aplica automáticamente, y no se deben bloquear por accidente otros modos de las demás organizaciones. El proveedor AWS se conserva en cada canal; no hay fallback al transporte Meta directo.

## Qué significa desactivar

Deshabilitar el módulo detendrá sus operaciones en Core y los accesos correspondientes, sin tocar conversaciones ni canales existentes de otros transportes. La pantalla debe distinguir **instalado**, **habilitado para la organización**, **recepción activa**, **agente activo**, **envío activo** y **conexión verificada**. No mostrar «AWS conectado» solo porque el paquete esté habilitado de forma dormante.

El interruptor de MuseRelay no elimina ni desconecta automáticamente el destino SNS de AWS. Si hubiera un mapping Lambda activo, bloquear el Core podría provocar reintentos, polling y paso a DLQ. La desactivación operativa completa requiere pausar también ese consumidor mediante el procedimiento AWS autorizado. No prometer que un checkbox local detiene toda facturación AWS ni conceder permisos administrativos generales para conseguirlo. La propuesta de runtime actual tiene el mapping apagado, por lo que no hay consumidor nuevo que pausar.

## Credenciales y experiencia normal de los mensajes

El formulario no debe pedir las claves actuales de Bedrock ni las credenciales administrativas AWS. Para esta demo, Lambda obtiene credenciales temporales de su propio rol. MuseRelay referencia el binding y los identificadores autorizados; las firmas de transporte usan material dedicado fuera de PostgreSQL y del repositorio. WABA/teléfono/cuenta/región/referencia de rol son identificadores, no contraseñas.

La recepción preparada crea objetos nativos de contacto, conversación y mensaje dentro del workspace correcto. Desde allí se integra con la bandeja y los mecanismos normales, conservando el origen AWS y su trazabilidad. Eso permite reutilizar supervisión/intervención humana y conectar posteriormente agente Bedrock y ticket confirmado. El envío vuelve por AWS. No implica que todas las herramientas estén ya habilitadas o certificadas: agente/envío siguen apagados y calendario conserva su revisión pendiente.

## Próximos pasos sin cambiar el alcance AWS actual

1. Terminar la revisión del runtime deshabilitado, sin ejecutar ni consumir mensajes.
2. Preparar en una copia privada el alta del modo AWS en el catálogo actual y su formulario/backend acotados, reutilizando el loader y Megadmin. Mantener defaults cerrados y evitar migraciones generales de módulos.
3. Probar controles de organización/workspace, usuario autorizado, acceso directo por URL/API, apagado, canales Meta/Telnyx y paneles existentes con datos sintéticos.
4. Antes de instalar en Core: backup fresco y restauración comprobada, revisión de cambios concurrentes, reversión y nueva comprobación de actividad del reseller. Instalación y activación requieren autorización específica.
5. Conceder exclusivamente la organización 227 y probar primero la instalación dormante; después autorizar por separado la recepción real. No conceder agentes, salida o herramientas en el mismo clic de habilitar el catálogo.

Los mecanismos futuros de reseller/BYO AWS y la comercialización gestionada siguen pendientes de diseño contractual. No se habilitan terceros, paneles nuevos ni un sistema general de plugins en esta fase.
