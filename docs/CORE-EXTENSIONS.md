# Integración en el Core y extensiones instaladas

Autorización del usuario: 25 de octubre de 2026, Europe/Paris. Incluye backup previo e instalación del conector en MuseRelay con control de conexión/desconexión en megadmin. No autoriza crear AWS, vincular WABA, enviar mensajes reales ni convertir ahora los calendarios/tickets históricos en paquetes separados.

## Arquitectura

El código fuente del Bridge permanece en su repositorio independiente. Se instala una **release de su paquete PHP** en `httpdocs/modules/aws-engagement-bridge`, con `module.php`, código, configuración, migración y `module-release.json` con commit/hashes de origen. No se carga código desde `modules-develop`, `.codex`, un enlace simbólico, un checkout temporal ni uploads. No se instala Composer en producción ni se modifica su conjunto de dependencias; se reutiliza Laravel y las bibliotecas ya presentes.

`config/extensions.php` enumera los paquetes de confianza. `ExtensionServiceProvider` valida ubicación y API, registra su autoload y ejecuta register/boot con captura de errores. No busca plugins en cualquier carpeta ni permite subir PHP desde el panel. Los proveedores deben ser pequeños y no hacer llamadas de red ni ejecutar trabajo en register/boot. Esto reduce el alcance de fallos de registro; no es un sandbox para código arbitrario, consumo ilimitado de memoria o efectos externos.

El conector instalado utiliza los contactos, mensajes, conversaciones, agente, tickets, bandeja y conexión PostgreSQL actuales. Sus cinco tablas `aws_bridge_*` guardan vínculos, eventos, acciones, nonces y outbox; no duplican las tablas comerciales. Redis puede ser el existente, con el procesamiento del conector delimitado. No se requiere otro dominio comercial, servidor, PostgreSQL ni Redis por módulo.

El staging aislado sigue siendo exclusivamente temporal. Su esquema sintético, credenciales, claves, factoría de modelos y bypass de créditos **no se copian a producción**. Instalar el paquete no ejecuta el harness de pruebas.

## Control del administrador

Entrada: `/megadmin/extensions`, enlazada desde megadmin y desde Módulos del panel. Rutas bajo `web`, `auth` y `superadmin`; cambios con CSRF y confirmación de la contraseña actual. Los clientes/resellers no pueden administrar estas extensiones.

Se muestran versión instalada, conexión efectiva, organizaciones autorizadas y requisitos pendientes, sin claves ni contenido de firmas. Conectar requiere configuración local válida y al menos una organización existente; el Bridge además exige cada binding de canal y sus controles de ownership. Esta inspección no certifica permisos/SCP/cuotas ni una conversación AWS real.

La disponibilidad de secciones del panel sigue en `/megadmin/modules`. El control nuevo gestiona el funcionamiento del paquete; no cambia la configuración existente de tickets, calendarios, planes o reseller.

## Desconectar

El estado se guarda en `platform_settings`, grupo `extensions`, clave del módulo, con booleano y lista de organizaciones. La lectura para operar se realiza de nuevo, sin la caché de una hora de PlatformSetting. `RuntimeSettings` y Store consultan ese estado incluso si la instancia de worker ya existía. Desconectar conserva los datos y corta nuevas aceptaciones, claims, publicación de outbox y herramientas del conector que revalidan el binding.

Las rutas firmadas permanecen instaladas: un callback de un binding conocido recibe `503 bridge_disabled` cuando está desconectado. Esto permite alternar el estado con procesos de larga duración sin reconstruir rutas en cada cambio. No se elimina el historial ni se restaura toda la base para apagar un módulo.

Una operación ya iniciada puede terminar. El control del Core no retira mensajes aceptados por AWS ni apaga automáticamente su infraestructura/colas. Para una parada completa de un despliegue AWS activo hay que seguir su runbook y desactivar también la entrada/envío del lado AWS. En esta instalación el Bridge permanece desconectado, sin vínculos ni recursos AWS creados por este trabajo.

Existe además el bloqueo técnico global `MUSERELAY_EXTENSIONS_ENABLED=false`. No se cambia `.env` durante la instalación. Cambiar ese bloqueo o instalar/actualizar código puede exigir recargar el proceso de aplicación; no debe confundirse con la desconexión ordinaria en megadmin. No reiniciar PostgreSQL, Redis o Caddy para conectar/desconectar el módulo.

## Instalación reproducible

1. Backup PostgreSQL previo, privado fuera de web/Git; comprobar retorno de pg_dump, listado, lectura completa del archivo y SHA-256. La lectura del archivo no equivale a un simulacro de restauración en otra base. La restauración real posterior de este backup y la comparación del esquema quedaron verificadas: [resultado y límites](history/POST-INSTALL-VERIFICATION.md). Para próximas instalaciones, ensayar también la restauración antes de aplicar el código.
2. Probar en una copia de código con PostgreSQL/Redis propios, datos ficticios y red aislada. El runner instala el paquete también por Composer únicamente en esa copia para mantener la cobertura anterior; una prueba adicional comprueba que la carpeta funciona sin instalar el paquete Composer en el Core.
3. Comprometer la release del módulo y revisar secretos. `scripts/install-core-package.py --core <Core> --source-ref <commit>` instala solo código de un commit inmutable. En una ruta con `.env` o en producción exige `--backup-manifest <manifest privado válido>`. No habilita, ejecuta SQL, modifica `.env` ni recarga servicios; rechaza sobrescribir una release existente.
4. Aplicar el soporte de Core y los tres hooks revisados. El catálogo permite futuras extensiones con manifiesto/provider, pero cada módulo debe respetar los controles de estado y organización en sus rutas, trabajos y herramientas; no se promete integrar cualquier paquete sin puntos de conexión adicionales.
5. Aplicar exclusivamente la migración de las cinco tablas Bridge, en una transacción y con revisión de identidad, ausencia previa y límites de lock. Registrar esa migración; no ejecutar todas las migraciones pendientes, migrate:fresh, rollback global, purge o flush de Redis.
6. Publicar el código revisado en el Core, manteniendo la extensión apagada y sin demo/clientes nuevos. Si hace falta, recargar de forma gradual únicamente el Octane de MuseRelay para cargar el código y verificar la respuesta web. Esta autorización de actualización del Core no es un reinicio general de servicios compartidos.

## Verificación y retirada

La suite comprueba conexión/desconexión sin reiniciar instancias, revocación de organización, rechazo de callback desconectado sin guardar eventos, ruta de paquete defectuoso, paquete fuera de modules, autenticación/rol/contraseña y render ES/EN. Mantiene el recorrido nativo de dos turnos/ticket con AWS simulado y las regresiones existentes de Meta/otros transportes. Los resultados concretos de esta ejecución y del estado instalado se registran por separado; no son evidencia de WhatsApp/Bedrock real.

Para retirar: desconectar, parar el trabajo dedicado cuando exista, revisar mensajes en vuelo y conservar estado/datos necesarios; después retirar únicamente el registro y la release del módulo con revisión de código. Las cinco tablas pueden conservarse para trazabilidad. No borrar mensajes, tickets, organizaciones, tablas históricas ni infraestructura compartida. Restaurar el backup completo no es el procedimiento normal de desactivación, porque podría perder cambios legítimos posteriores.

Tickets y calendario actuales conservan su implementación y controles anteriores. Las nuevas herramientas pueden adoptar esta base gradualmente; no se crea ahora un marketplace, instalador web ni certificación para miles de usuarios simultáneos.

Procedimiento operativo de reversión y pruebas realizadas: [CORE-ROLLBACK.md](CORE-ROLLBACK.md). La reversión ordinaria conserva las tablas aditivas y no restaura toda la base productiva.
