# Fase 4 — preparación de la primera conversación real

**GO para preparar y revisar. NO-GO para ejecutar todavía.** Fecha de revisión: 24 de octubre de 2026. La primera conversación real sigue pendiente; no se ha desplegado AWS, activado DNS/TLS, arrancado staging, enviado mensajes ni publicado GitHub en esta fase.

La cuenta autorizada es **Screen Art S.L., `365470560685`, `eu-west-1`**. El preparador rechaza cualquier otra cuenta y el preflight detiene las consultas si STS devuelve root. No se utilizará la cuenta de Muse Layer. El usuario ha confirmado `aws-bridge-staging.muserelay.com` como entrada técnica, sin panel de clientes/resellers. El rol/perfil IAM y la WABA/número exclusivos siguen pendientes.

## Decisión arquitectónica confirmada por el usuario

La solución definitiva es **una extensión del MuseRelay existente**. No requiere una segunda instalación comercial, contratar una máquina adicional ni imponer servidor/dominio/base/Redis propios a cada módulo. La disponibilidad y capacidad del anfitrión temporal todavía deben confirmarse; esto no autoriza usar el nodo de producción ni contratar infraestructura.

El staging aislado de fase 4 es **temporal y desechable**. `aws-bridge-staging.muserelay.com` es exclusivamente técnico y temporal: no será un panel ni una URL para clientes o resellers. Sus fixtures, bootstrap, factoría de demo y contabilidad sintética no se instalarán como sustitutos del bootstrap, agentes o facturación de producción.

La integración definitiva instalaría el paquete Laravel `screenartsl/muserelay-aws-engagement-bridge-core` dentro del despliegue actual, con los tres hooks preparados y cinco tablas `aws_bridge_*` en su conexión de base de datos. NativeCore reutiliza contactos, conversaciones, mensajes, agentes, tickets y eventos de la bandeja existentes. Los mensajes y tickets nativos deben compartir la misma conexión transaccional con el conector; no se trasladan a una base comercial paralela.

Redis puede ser el servicio actual, con claves y trabajo del módulo delimitados; el procesamiento Bridge tendrá su propio control de concurrencia y ejecución, sin exigir otro servidor Redis. Los clientes conservan sus organizaciones, permisos, paneles reseller/marca blanca y dominios habituales. Las rutas técnicas firmadas `/api/aws-engagement-bridge/events` y `/receipts` pueden publicarse en un origen HTTPS del Core ya existente: no se exige subdominio nuevo por módulo, organización o reseller. Los recursos AWS de transporte/estado siguen siendo necesarios; compartir el Core no elimina SNS/SQS/Lambda/DynamoDB/API Gateway ni sus controles.

La modularidad significa paquete, configuración, activación explícita por organización/canal y estado acotado. No significa que exista ya un cargador general de extensiones ni que cada módulo deba tener infraestructura propia. Los futuros módulos pueden compartir PostgreSQL/Redis y usar tablas, claves, colas y permisos propios; el aislamiento físico será una decisión específica cuando lo requieran sus cargas o requisitos.

Se puede retirar íntegramente el entorno temporal conservando código e historial del Bridge. Si posteriormente existe un Bridge activo definitivo, **primero** hay que verificar su instalación en el Core habitual, sus bindings/secretos propios y el destino HTTPS correcto en AWS. Borrar el staging mientras un despliegue siga apuntando a su URL interrumpiría ese despliegue. No se copiarán datos/credenciales de demo a clientes ni se borrarán recursos definitivos como parte de la retirada del staging.

La instalación definitiva sigue pendiente de revisión contra el esquema y recorridos completos del Core actual; las pruebas con fixtures no certifican todos los paneles. Requiere autorización expresa para instalación Composer/hooks, migraciones, configuración, cachés, workers y cualquier recarga compartida necesaria. No se realiza nada de ello en esta fase. La prueba del hackathon se mantiene aislada de los clientes beta.

## Qué está preparado y dónde

Código independiente: `/var/www/vhosts/muserelay.com/modules-develop/aws-engagement-bridge`, rama `codex/aws-engagement-bridge-phase4`. Sigue sin instalarse en producción. Esta carpeta se replica mediante lsyncd: nunca debe contener secretos ni datos operativos de la demo.

| Artefacto | Uso y límite |
| --- | --- |
| `deploy/phase4/inputs.example.json` | Cuenta y región confirmadas; identificadores pendientes explícitos; autorización siempre `false` |
| `scripts/phase4/plan.py` | Render offline de parámetros desactivados, política de lectura y borrador Bedrock; no ejecuta AWS |
| `scripts/phase4/preflight.py` | STS, identidad Social concreta, cuota Lambda, perfiles EU Bedrock, metadatos de secreto y simulación IAM indicativa; nunca GetSecretValue/Converse/Send |
| `scripts/phase4/prepare-source.py` | Copia de revisión en `/tmp`, exclusivamente desde Git; tres hooks comprobados, sin `.env`, vendor ni datos del checkout |
| `integration/live-staging/` | Bootstrap separado, SDK Bedrock real observado, entrada HMAC, fixtures nativos, Redis worker y snapshots sin texto de clientes |
| `deploy/phase4/` | Propuestas de Caddy, FPM, PostgreSQL, Redis, servicios y timer; **no instaladas** |
| `scripts/phase4/collect-logs.py` | Lectura de tres grupos CloudWatch concretos; conserva solo campos permitidos y procedencia |
| `scripts/phase4/verify-evidence.py` | Comprobación offline de dos turnos, confirmación, un ticket/auditoría y request IDs AWS; rechaza evidencias incompletas/simuladas |

El bootstrap nuevo **no usa `Simulation.php`, Http::fake ni un handler SDK simulado**. Construye el cliente nativo Bedrock con credenciales temporales propias, observando su middleware. La factoría exclusiva de demo fija Nova Lite para el agente y Nova Micro para los clasificadores. La contabilidad de créditos internos se omite solo en la organización sintética; las llamadas AWS serán reales y facturables cuando se autoricen. No se prueba aquí el sistema comercial de cobros.

## Estado verificado frente a pendientes

- No hay AWS CLI, perfiles en los archivos AWS del usuario de esta sesión ni credenciales AWS en su entorno. No se ha ejecutado STS contra AWS. La cuenta está **declarada por el usuario**, todavía no autenticada.
- El subdominio propuesto no resolvía en la consulta DNS realizada. No se han creado registros ni certificados. Caddy existe y su proceso/grupo es `caddy`; su propuesta se ha parseado sin activar nada.
- La herramienta conectada de MuseDock rechazó la consulta de nodos por falta de autenticación. No se puede certificar desde esa conexión el nodo/proxy/certificado final.
- No hay WABA ni número de demo vinculados todavía. No se migrará ni se modificará ninguna WABA/canal Meta actual. El destino SNS se configura por WABA y abarca sus teléfonos: por eso la demo debe tener **WABA independiente**. [Documentación AWS](https://docs.aws.amazon.com/social-messaging/latest/userguide/managing-event-destinations-add.html).
- No están verificados permisos efectivos, SCP, permission boundaries, cuotas Lambda/Bedrock, acceso a modelos ni restricciones de facturación de esta cuenta. Hay un bloqueo posible adicional: la plantilla reserva tres ejecuciones Lambda y AWS exige dejar 100 sin reservar; una cuenta con cuota inicial pequeña puede impedir el despliegue. El preflight revisa GetAccountSettings y señala si no caben tres reservas adicionales. No solicita aumentos ni quita reservas de otras funciones. [Regla oficial de concurrencia](https://docs.aws.amazon.com/lambda/latest/dg/configuration-concurrency.html).
- El origen Git local apunta a `screenartsl/aws-engagement-bridge`; no se ha autenticado su existencia/visibilidad ni hecho push. [Publicación privada](REPOSITORY-UPLOAD.md).

## Alojamiento propuesto y aislamiento

Tras autorización, propuesta inicial: procesos dedicados en el servidor de staging que se confirme, con código en `/srv/muserelay-aws-stage/{core,bridge}`, datos en `/var/lib/muserelay-aws-stage`, sockets en `/run/muserelay-aws-stage`, configuración/secrets privados en `/etc/muserelay/aws-bridge/`. Ninguna de esas instalaciones se ha realizado. La fuente Core se exporta desde `b8e9bf8c74789689a7cebddd1856a094e380c9d9` y recibe únicamente los tres hooks ya revisados más el overlay Composer del conector.

Se proponen dos usuarios locales nuevos: aplicación `muserelay-aws-stage` y PostgreSQL `muserelay-aws-pg`. Cinco servicios propios (PostgreSQL, Redis, FPM, worker Redis, encolador) y un timer. Base `bridge_phase4_test`, rol SQL no superusuario `muserelay-aws-stage`, socket PostgreSQL privado en puerto lógico 55434 sin TCP. Redis propio sin TCP, base/prefijo/cola propios. Los servicios de aplicación tienen `InaccessiblePaths=/var/www/vhosts`, fuente de solo lectura y escritura limitada a los directorios de demo. FPM/worker tienen límites de memoria/CPU; no arrancan ni recargan servicios Core/reseller.

El hostname acepta solamente POST a `/api/aws-engagement-bridge/events` y `/receipts`. El conector verifica HMAC-SHA256 sobre bytes exactos, ruta, método, timestamp y nonce duradero; claves distintas por dirección, archivos 0600. Rechaza otras organizaciones y destinatarios. No hay interfaz pública de tickets en este endpoint: se usan las herramientas/modelos/tablas nativas dentro de la base sintética. La ruta nativa de referencia existe únicamente para generar el resultado y devuelve 404 si se intenta abrir. Los paneles habituales conservan sus dominios y datos.

Un host compartido conserva competencia potencial por CPU/disco/kernel: los límites de procesos reducen ese riesgo, no certifican aislamiento físico ni disponibilidad de producción. Antes de activarlo hay que confirmar nodo, capacidad libre y ruta de proxy. Si se exige una VM dedicada, habrá que presupuestarla y autorizarla aparte. La activación de un nuevo vhost en el proxy compartido exige revisar su configuración completa y autorizar la recarga; no se ha supuesto permiso para ello.

## AWS: inventario exacto propuesto

Stack `muserelay-aws-bridge-stage`, en Irlanda. La expansión SAM offline tiene **23 recursos**:

| Tipo | Cantidad |
| --- | ---: |
| SNS topic estándar, topic policy y suscripción SQS | 3 |
| SQS: entrada, salida, dos DLQ y una queue policy | 5 |
| DynamoDB on-demand, cifrado/PITR/TTL | 1 |
| Lambda Node.js 22 arm64 | 3 |
| Roles IAM explícitos de Lambda | 3 |
| Event source mappings SQS | 2 |
| API Gateway HTTP API, stage y Lambda permission | 3 |
| CloudWatch log groups, retención 7 días | 3 |

Fuera del stack: un bucket S3 privado de artefactos (o uno aprobado ya existente, prefijo propio), un secreto de firmas, WABA/número de demo y destino Social→SNS. Una identidad Bedrock de staging: preferir rol existente autorizado y acotado; si no existe, proponer **un rol adicional** asumible por el operador aprobado, sin usuario IAM nuevo ni claves permanentes. Su creación/permisos requieren autorización separada. No se crea EC2, RDS, ElastiCache, NAT, dominio comercial ni agente administrado de Bedrock: se reutiliza el agente nativo de MuseRelay con runtime Converse.

Lambda: 256 MB, timeout 30 s y concurrencia reservada 1 por función; SQS visibilidad 180 s, retención 4 días, DLQ 14 días; API 1 req/s, burst 2. El callback solo persiste/acuse; la inferencia ocurre en el worker PHP fuera del timeout Lambda. Firma de salida: Core→HTTP API; entrega: sender→Social→WhatsApp; eventos de entrega vuelven por SNS. No se registra ningún webhook nuevo en la app Meta actual.

## Permisos mínimos y comprobación pendiente

| Identidad | Acciones/alcance previsto |
| --- | --- |
| Lambda inbound | Get/Put/Update/DeleteItem en la tabla State; GetSecretValue en el secreto propio; Receive/DeleteMessage y GetQueueAttributes en entrada; CreateLogStream/PutLogEvents en su grupo |
| Lambda enqueue | PutItem en State; GetSecretValue propio; SendMessage en salida; escritura en su grupo |
| Lambda sender | Get/Put/Update/DeleteItem en State; GetSecretValue propio; polling de salida; **SendWhatsAppMessage solo en el ARN del teléfono demo**; escritura en su grupo |
| SNS y SQS resource policies | Social publica solo con SourceAccount de Screen Art; SNS entrega solo desde el topic esperado a la cola propia; sin acceso público |
| Bedrock PHP | `bedrock:InvokeModel` en los perfiles `eu.amazon.nova-lite-v1:0`, `eu.amazon.nova-micro-v1:0` y **cada ARN foundation-model de destino devuelto por GetInferenceProfile**; `Converse` es la API, no una acción IAM llamada bedrock:Converse |
| Operador de solo lectura | STS, GetAccountSettings de Lambda, GetLinkedWhatsAppBusinessAccount/PhoneNumber, GetInferenceProfile, DescribeSecret sin valor; tras crear stack, Describe/ListStackResources, GetFunctionConfiguration/GetPolicy, lectura de las colas/topic/roles/grupos propios |
| Operador de despliegue, no concedido | CloudFormation Create/Describe/Execute/DeleteChangeSet, Describe/DeleteStack; S3 Get/PutObject solo prefijo de artefactos y GetBucketLocation; crear/actualizar/eliminar únicamente recursos propios SNS/SQS/DynamoDB/Lambda/API Gateway/Logs y sus políticas/mappings; crear/poner/eliminar los tres roles runtime y PassRole condicionado a Lambda; crear el secreto propio; vinculación Social y PutWhatsAppBusinessAccountEventDestinations solo demo |

Los tres roles runtime se han comprobado en la expansión SAM sin managed policies amplias ni Resource `*`. STS/GetAccountSettings requieren Resource `*` en la identidad lectora; esto no se extiende a los roles de ejecución. La política de lectura y la de Bedrock renderizadas son **borradores**, no permisos aplicados. El despliegue tiene recursos con identificadores generados por CloudFormation —especialmente HTTP API—: la política efectiva del operador debe concretarse con la identidad aprobada, nombres, condiciones/tagging soportados y change set; no se entrega una política administradora universal como mínimo supuesto. No se han concedido estos permisos.

La simulación IAM usa un conjunto orientativo de acciones con Resource `*`: una denegación puede ser consecuencia de un scope correcto; un allowed tampoco prueba SCP, cuotas, resource policies o todos los parámetros reales. Para certificar mínimos hacen falta políticas de la identidad concreta y configuración resuelta del stack. Si se exige KMS CMK para secreto/SNS/colas, sus key policies y kms:Decrypt/GenerateDataKey requieren revisión adicional; no están cubiertos por el borrador actual. SNS no usa CMK en esta demo sintética; SQS/DynamoDB sí tienen el cifrado gestionado indicado.

Los perfiles `eu.` pueden enrutar inferencias a otras regiones de la UE: **Irlanda es la región de entrada, no una promesa de inferencia exclusivamente en Irlanda**. El preflight rechaza destinos fuera del patrón EU/Amazon Nova previsto. [AWS sobre perfiles y regiones](https://docs.aws.amazon.com/bedrock/latest/userguide/inference-profiles-support.html), [autorización Social](https://docs.aws.amazon.com/service-authorization/latest/reference/list_socialmessaging.html).

## Organización y prueba de aceptación

Fixtures reservados, únicamente en la base staging: organización 97101, workspace 10, canal 20 `aws_social`, agente 30 Nova Lite, proveedor 40 Bedrock, buzón 50 con `ticket_inbox_ids=[50]`. El esquema reutiliza el fixture nativo de fase 3: no es un dump ni certifica la cadena histórica completa de migraciones SaaS. El seeder exige base vacía, rol/socket específicos y módulo desactivado; deja el binding desactivado.

1. Snapshot `before`: cero eventos de mensaje y cero tickets.
2. Un probador con teléfono de demostración autorizado inicia WhatsApp: «Soy Visitante Demo. Quiero registrar una consulta de accesibilidad para el museo. Descripción: necesito información sobre la rampa. Es una demostración y no quiero reservar ni pagar nada.»
3. Esperar respuesta real. La herramienta debe devolver `ticket_confirmation_required`; snapshot `after_first`: un evento terminado y **cero tickets**. Si el modelo no llama la herramienta, se considera prueba no superada; no se fuerza un resultado ficticio.
4. El mismo probador responde: «Sí, confirmo que registres esa solicitud de demostración.»
5. Esperar respuesta real con referencia de la herramienta; snapshot `after_second`: dos eventos terminados, **un ticket y una auditoría nativos**, salida aceptada y sin duplicados. Un email/nombre adicional exigido por el flujo o un fallo de calidad del modelo puede necesitar ajustar los datos sintéticos/ensayar; no se promete que todo modelo complete exactamente dos mensajes sin validarlo.
6. Reunir logs permitidos CloudWatch y `evidence.ndjson` privado de staging; verificar las llamadas Converse y Social de ambos turnos, sus request IDs y el Lambda awsRequestId. Anotar que el probador recibió las dos respuestas. El manifiesto pendiente no puede pasar el verificador.

Solo se emplean nombres/asuntos inventados. Los dos teléfonos de operación (emisor y probador) serán números de prueba controlados por la empresa, nunca clientes; un número de teléfono sigue siendo dato personal/operativo y se guarda únicamente en la configuración/BD privada. No se promete ausencia física de esos identificadores en el proveedor. No se guardan cuerpos, prompts, respuestas, teléfonos, tokens ni firmas en los logs de evidencia. Logging ordinario de Laravel y access log del vhost desactivados; no activar model invocation logging con cuerpos. Metadatos con hashes/request IDs se conservan con acceso restringido, sin comprometerlos en Git.

El verificador comprueba consistencia, **no autentica archivos fabricados manualmente**. Para declarar la prueba real hay que conservar procedencia CloudWatch obtenida con la identidad AWS aprobada y contrastar request IDs, fuente/versiones y resultado observado. El test sintético del propio verificador nunca se presenta como prueba real.

## Presupuesto y ventana

Propuesta a aprobar: **hasta 96 horas**, un probador, una conversación de dos turnos, hasta 6 entradas y 6 salidas/día como margen de ensayo; Nova Lite/Micro solamente, máximo 40 intentos SDK Converse en este staging y salida limitada a 2048 tokens por llamada. Los reintentos internos del SDK pueden añadir intentos HTTP facturables. No activar plantillas, campañas, medios, provisioned throughput ni recursos de cómputo AWS.

| Concepto | Estimación de la demo |
| --- | --- |
| WhatsApp AWS, dos entradas y dos salidas | `2 × $0.001 + 2 × $0.005 = $0.012` |
| Un secreto durante 96 h | Aproximadamente $0.054 de almacenamiento, más consultas |
| Bedrock | Reserva técnica $1 para esta prueba y margen de ensayos; no es una cotización tarifaria de Nova en Irlanda |
| Lambda, SNS, SQS, DynamoDB/PITR, HTTP API, S3 y logs pequeños | Reserva conjunta $1; confirmar con uso real, sin depender de Free Tier |
| Propuesta total de autorización | **$5 USD**, gasto esperado de esta demo limitada inferior a $1–3, excluidos impuestos y teléfono/servicios externos |

Tarifas consultadas el 24/10/2026: [AWS WhatsApp](https://aws.amazon.com/end-user-messaging/pricing/), [Secrets Manager](https://aws.amazon.com/secrets-manager/pricing/), [Bedrock](https://aws.amazon.com/bedrock/pricing/). Los cargos Meta por plantillas son separados; la prueba usa texto libre iniciado por el usuario dentro de su ventana de atención, sin plantillas. [Facturación Social](https://docs.aws.amazon.com/social-messaging/latest/userguide/billing.html).

Los $5 **no son un techo técnico ni una garantía**: el contador diario no mide dinero; mantener API pública/recursos abiertos genera exposición residual y los servicios pueden facturar después del ensayo. Debe haber operador, revisión de consumo y apagado al finalizar. El bootstrap corta actividad al expirar la configuración, pero no elimina AWS ni desconecta por sí solo la WABA. Una nueva VM, un número de pago, cuotas especiales, impuestos o CMK adicional cambian el presupuesto y exigen revisar la autorización.

## Plazo y decisión

Con WABA/número vinculados, identidad no-root, acceso Nova y proxy/DNS disponibles: **4–8 horas técnicas para la primera ejecución y otras 4–8 horas para resolver diferencias y dejar evidencia reproducible**. El 28 de octubre es viable de manera condicionada, no garantizada. Alta/OTP/verificación de Meta, vinculación Social, límites de cuenta/SCP/Bedrock y DNS/proxy/certificado son bloqueos externos sin SLA controlable por nosotros.

Si estos requisitos no están resueltos el 26 de octubre, el riesgo de llegar al 28 es alto. No se sustituirá la prueba real por logs del simulador; no se amplía la fase hacia otros canales para ocultar el bloqueo.

Tickets nativos: incluidos en esta aceptación. **Calendarios: todavía bloqueados por ToolGuard en el Bridge**, reservados para una fase posterior. **Miles de usuarios simultáneos: no certificados**; actualmente se serializa por binding/canal y hay concurrencia Lambda 1. Ampliar Node no resuelve ese cuello por sí mismo: harían falta partición/orden por conversación, control de cuotas, equidad y pruebas de carga. Esas modificaciones no se realizan en fase 4.

## Autorizaciones que faltan

Preparación local aprobada y realizada. Para avanzar hay que autorizar expresamente, con los identificadores resueltos:

1. Crear/vincular **solo WABA y número de demo**, y su destino Social→SNS; no reutilizar/migrar los canales actuales.
2. Crear el stack de 23 recursos, secreto, artefactos S3 y, si falta, un rol temporal Bedrock acotado; presupuesto propuesto $5/96 h y posterior eliminación revisada.
3. Instalar los usuarios/procesos/directorios propios de staging en el nodo confirmado, publicar solo el hostname técnico y activar DNS/TLS/nuevo vhost con revisión de proxy.
4. Activar exclusivamente la organización demo y habilitar envíos para el probador autorizado; ejecutar los dos mensajes reales y recoger evidencia.
5. **Por separado**, crear/verificar y subir el repositorio GitHub privado bajo `screenartsl`, sin Core completo ni acceso a jueces. No está publicado.

El runbook concreto de instalación, activación y retirada está en [PHASE-4-RUNBOOK.md](PHASE-4-RUNBOOK.md). Ninguna de estas operaciones se ejecuta por el hecho de generar parámetros o pasar tests.
