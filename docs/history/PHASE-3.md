# Fase 3 — pruebas integradas y reproducibilidad

**GO para el staging aislado y la demostración reproducible con AWS simulado. NO-GO, por ahora, para presentar una conversación real de WhatsApp/Bedrock como verificada.** No se ha instalado el módulo en producción, desplegado AWS, enviado mensajes reales ni publicado código durante esta fase.

El módulo permanece en `/var/www/vhosts/muserelay.com/modules-develop/aws-engagement-bridge`, rama `codex/aws-engagement-bridge-phase3`, paquete Laravel 0.3.0. El entorno temporal se crea fuera del árbol replicado, bajo `/tmp/muserelay-bridge-phase3-…`. No es un sistema general de descubrimiento de extensiones: su instalación y activación siguen siendo explícitas.

## Evidencias del recorrido

El runner obtiene una copia de fuente comprometida en Git, aplica los tres hooks a esa copia e instala el paquete mediante Composer. La comparación antes/después del parche confirma exactamente tres archivos modificados y **3347 archivos de fuente copiados sin cambios**. No usa el bootstrap, `.env`, vendor, bases de datos, Redis, colas, claves ni almacenamiento de producción.

Dentro de un espacio de red Linux separado, sin interfaces activadas, el recorrido ejecutado es:

```mermaid
sequenceDiagram
    participant W as WhatsApp/SNS simulado
    participant N as Worker AWS TypeScript
    participant L as Laravel + conector instalado
    participant P as PostgreSQL privado
    participant R as Redis + worker real
    participant A as Agente nativo
    participant B as SDK Bedrock simulado
    W->>N: Evento sintético
    N->>L: POST firmado; router Laravel real
    L->>P: Evento duradero e idempotente
    R->>L: aws-bridge:drain
    L->>A: NativeCore → AutomationService → AIAgentService → AgentExecutor
    A->>B: Converse y tool_use
    B-->>A: Llamada controlada a support_create_ticket
    A->>P: Tool nativa, auditoría y checkpoint
    A->>L: Respuesta al cliente
    L->>P: Mensaje y outbox duraderos
    L->>N: Comando firmado a API Gateway simulado
    N->>N: Enqueue, deduplicación y SDK Social simulado
    N->>L: Recibo firmado + estado de lectura
    L->>P: Actualización del mensaje
```

El primer turno **no crea ticket**: la herramienta nativa exige confirmación y guarda la marca en Redis real. El segundo mensaje del cliente permite crear **un ticket nativo y una auditoría**, con referencia devuelta por la herramienta. Antes de las pruebas adicionales hay una conversación, un contacto, dos entradas del cliente, dos respuestas del agente y un aviso de privacidad nativo: cinco mensajes. Ambos comandos de salida quedan aceptados por el SDK simulado y sus estados de lectura actualizan los mensajes correspondientes. Los estados no vuelven a ejecutar el agente.

Se usan las clases reales NativeCore, AutomationService, AIAgentService, AgentExecutor, CreateTicketTool y sus modelos/callbacks. El SDK Bedrock serializa Converse contra un handler controlado; el SDK Social serializa SendWhatsAppMessage contra otro handler controlado. SNS/SQS/DynamoDB y el transporte HTTP de red se simulan. El router y las firmas de Laravel sí se ejecutan. La selección de modelo utiliza una factoría exclusiva del harness; la facturación tiene un tracker sintético. Esto no verifica calidad del modelo, credenciales, cuotas ni consumo facturable.

## Resultados

| Comprobación | Evidencia |
| --- | --- |
| Contratos Node | 31 pruebas aprobadas |
| Conector PHP con SQLite | 28 pruebas, 102 aserciones aprobadas |
| Mismos contratos con PostgreSQL real | 28 pruebas, 158 aserciones aprobadas |
| Aserciones nativas adicionales | 18 comprobaciones: ownership, cifrado, ticket, auditoría, recibos, pausa humana, ventana de 24 horas y rollback |
| Duplicados simultáneos | 8 procesos/entregas: 1 aceptación y 7 duplicados |
| Competencia por el trabajo | 6 procesos: una sola lease PostgreSQL |
| Rollback transaccional | Error inyectado después de ejecutar la herramienta nativa: ticket, auditoría y checkpoint se revierten juntos |
| Regresión | 15 comprobaciones: nueve rutas de herramientas heredadas, envío Meta con HTTP simulado, AutomationService heredado y su job original, limitación Telnyx existente y bloqueo del fallback AWS |
| Desactivación | Sin rutas Bridge registradas ni trabajo reclamado; el envío AWS falla cerrado |
| Rollback del esquema | Se retiran únicamente las cinco tablas Bridge; se conservan mensajes, tickets y canales nativos |
| Plantilla AWS | Expansión SAM offline y cfn-lint sin incidencias; 23 recursos, tres roles explícitos sin políticas SQS gestionadas amplias |
| Historial Git | Escáner de todos los objetos locales, incluidos metadatos y objetos no alcanzables, además del árbol publicable; sin hallazgos en la revisión realizada |

Son **87 ejecuciones de pruebas de contrato**, porque las 28 PHP se ejecutan en dos motores, además del recorrido y las comprobaciones integradas. No se suman como 87 pruebas de negocio distintas. El resumen verificable y hashes de logs están en [evidencia estructurada](../evidence/phase-3-summary.json). Los logs completos permanecen en el directorio temporal privado indicado allí; no se incluyen datos de producción.

La regresión conserva el comportamiento de Meta/Telnyx/Instagram/Messenger/SMS/Telegram/email/chatbot/voz en el punto de despacho de herramientas. Meta también tiene una prueba de envío HTTP simulado y una prueba del flujo de AutomationService que mantiene el job `SendAIChannelResponse`. Eso no sustituye una prueba completa de proveedor para cada canal ni una revisión de navegador de todos los paneles.

## Correcciones realizadas dentro del módulo

1. **Remitente entrante compatible con la confirmación nativa.** NativeCore guardaba `sender_type=contact`, mientras CustomerConfirmation y el webhook actual utilizan `user`. Con `user`, Redis puede identificar el mensaje nuevo y el ticket se registra después de la confirmación. La corrección está en el paquete, sin tocar el Core productivo.
2. **Identificador de teléfono AWS.** La validación PHP/TypeScript/SAM admite los identificadores con guiones documentados en los ARN de origen, manteniendo cuenta/región exactas y rechazando separadores de ruta, comodines y cambios de cuenta. Se añadió cobertura específica. Referencias: [formato IAM](https://docs.aws.amazon.com/service-authorization/latest/reference/list_socialmessaging.html), [ejemplos CloudTrail de Social](https://docs.aws.amazon.com/social-messaging/latest/userguide/logging-using-cloudtrail.html).
3. **Permisos de ejecución explícitos.** La expansión SAM inicial añadía una política SQS gestionada a los consumidores. La plantilla preparada usa ahora tres roles con recursos concretos para cola, tabla, secreto, teléfono y logs. No se ha creado ni cambiado ningún rol en AWS.
4. **Pruebas PostgreSQL y aislamiento reproducible.** Se corrigió el cierre de conexiones del fixture de tests para evitar agotar su clúster privado. Se añadieron instalación Composer independiente, guardas de entorno/red, procesos reales y apagado del staging incluso si falla una comprobación.

## Producción y hallazgos heredados

El checkout productivo conserva los cambios ajenos que ya estaban presentes al comenzar: `storage/geoip/GeoLite2-Country.mmdb` y `resources/views/contacts/index.blade.php.back`. No se modificaron ni se incluyeron en commits. El worktree independiente de Core tampoco recibió cambios nuevos en esta fase; el runner trabaja sobre una tercera copia temporal.

La versión base contiene una excepción de Telnyx en `sendTextMessage`: «Media WhatsApp aún no implementado para modo guiado». La prueba registra que sigue igual; **no se ha corregido ni activado ese canal**. Se trata de un hallazgo heredado, separado del adaptador AWS.

El esquema usado es un fixture sintético de integración, ampliado a partir de pruebas Core y de las necesidades de modelos/cifrado. No se obtuvo un dump productivo ni se certificó la cadena completa de migraciones históricas. No se instalaron reglas RLS: las restricciones de organización son comprobaciones de la aplicación, con unicidad y locks SQL donde corresponde. El rollback no convierte Redis, correo u otros efectos externos en transacciones SQL; una confirmación Redis consumida antes de un fallo puede requerir una nueva confirmación del cliente.

## Instalación, Git y siguiente prueba

La reproducción completa, activación por organización, desactivación y rollback están en [STAGING.md](../STAGING.md). El inventario de AWS, IAM, identificadores y configuraciones pendientes está en [AWS-LIVE-CHECKLIST.md](AWS-LIVE-CHECKLIST.md). Se han conservado los commits iniciales `2d02e31`, `f2b5d07` y `e43b552`; el código y harness nuevos de esta fase se añaden después, sin alterar su fecha ni atribuir al hackathon el agente o sistema de tickets histórico. [Procedencia](../PROVENANCE.md).

El destino local es `screenartsl/aws-engagement-bridge`; la existencia y visibilidad del remoto no han sido autenticadas en esta sesión. La rama y una copia Git bundle pueden prepararse localmente, pero **no se ha publicado**. [Preparación del repositorio privado](REPOSITORY-UPLOAD.md). El análisis de secretos es heurístico y cubre lo almacenado localmente; no certifica historial que solo exista en GitHub ni archivos externos LFS.

Con activos de prueba, permisos, modelos y un endpoint staging público ya disponibles, estimo **4–8 horas de trabajo** para la primera conversación real y **medio a un día adicional** para resolver diferencias y ensayar. La demostración simulada ya es reproducible antes del 28 de octubre. La real es viable de forma condicionada; no puede prometerse mientras falten alta/OTP o revisión Meta, permisos/SCP de AWS, acceso/cuotas Bedrock, ingreso HTTPS staging y autorización del gasto/envío. Para mantener intactos los canales actuales, la prueba deberá usar una WABA y número de demostración independientes.
