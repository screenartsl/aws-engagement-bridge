# Fase 4 — runbook revisable, NO ejecutado

> Informe histórico. Para SNS/KMS, artefactos, parámetros, recursos y retirada AWS prevalece [CLOUDFORMATION-DEPLOYMENT.md](../CLOUDFORMATION-DEPLOYMENT.md), revisado el 25-10-2026. Los comandos de package/deploy de este informe no deben aplicarse a la plantilla actual. El estado instalado/desactivado del Core y el repositorio privado se actualizan en README y POST-INSTALL-VERIFICATION.

Este documento describe acciones futuras. La autorización actual permite preparación y lectura; **no ejecutar la sección de instalación, package/deploy, alta/vinculación, activación, envíos, retirada de recursos ni push sin autorización expresa**. No usar credenciales root ni producción. Alcance/costes: [PHASE-4.md](PHASE-4.md).

## 1. Preparación local y lectura disponibles ahora

```sh
cd /var/www/vhosts/muserelay.com/modules-develop/aws-engagement-bridge
python3 scripts/phase4/plan.py
python3 scripts/phase4/preflight.py --inputs deploy/phase4/inputs.example.json
python3 scripts/phase4/prepare-source.py --core-source /var/www/vhosts/muserelay.com/.codex/worktrees/aws-engagement-bridge-core
python3 -m unittest discover -s test/phase4 -v
npm test
npm run bundle
python3 scripts/review-history.py --self-test
python3 scripts/review-history.py
```

El ejemplo deja el preflight en PENDING y no contacta AWS mientras falte el perfil. No exportar AWS_ACCESS_KEY_ID/secret/tokens a logs ni buscar credenciales en `.env` o shells de producción. Al proporcionar un perfil **no-root** autorizado, el preflight puede usar boto3 de una instalación privada de herramientas, sin modificar el Python del sistema. AWS CLI y SAM CLI son requisitos de la futura shell operadora, no están instalados por esta fase.

Guardar inputs completos en `/etc/muserelay/aws-bridge/phase4-inputs.json` o en un directorio privado `/tmp` fuera de vhosts, modo 0600, directorio 0700. No comprometerlos en Git. Respetar `approved:false`: el archivo no representa aprobación del usuario. Se debe resolver y revisar:

| Campo | Valor necesario |
| --- | --- |
| Cuenta/región/hostname | `365470560685` / `eu-west-1` / `aws-bridge-staging.muserelay.com` |
| profile / deployerPrincipalArn | Perfil explícito y ARN IAM rol/usuario no-root autorizado para lectura; despliegue aún no concedido |
| artifactBucket | Bucket privado en Irlanda, ownership y prefijo de esta demo verificados |
| secretArn | ARN de secreto dedicado con sufijo real devuelto por AWS, nunca uno productivo |
| linkedWabaId | ID interno vinculado en Social, distinto del ID Meta |
| metaWabaId / metaPhoneNumberId | IDs Meta del activo de demostración, no el teléfono visible |
| socialPhoneArn | `arn:aws:social-messaging:eu-west-1:365470560685:phone-number-id/<id-real>` |
| recipient | Único número E.164 controlado por el probador, sin clientes reales |
| runId | ID único de este ensayo; no reutilizar IDs de simulaciones |

Una shell autenticada podrá ejecutar `aws configure list-profiles` y `aws sts get-caller-identity --profile PERFIL --region eu-west-1`, solo lectura. Revisar cuenta y ARN antes de otras llamadas. No inferir que el perfil correcto tiene permisos de despliegue. El preflight llama a GetInferenceProfile, no al runtime; guardar sus ARN de destino EU y completar el borrador InvokeModel. Comprobar también por STS la identidad que emitirá las credenciales temporales Bedrock, no solo la identidad de despliegue.

## 2. Instalación staging, solamente después de aprobación

Confirmar nodo de alojamiento y capacidad antes de crear servicios. La propuesta no agrega una VM de pago. Si el nodo/proxy cambia, revisar binarios, permisos y coste de nuevo.

1. Crear usuarios locales dedicados `muserelay-aws-stage` y `muserelay-aws-pg`, sin shell de login; no modificar usuarios/servicios actuales. Aplicación y PostgreSQL comparten el grupo **solo de staging** para el socket. Crear `/run/muserelay-aws-stage` con permiso de travesía para Caddy y subdirectorio `pgsock` 0750 propiedad del usuario PostgreSQL y grupo staging. El socket PostgreSQL 0770 no tiene TCP; Redis 0700 solo es accesible por la aplicación. Reponer directorios runtime con tmpfiles al reiniciar.
2. Copiar **la exportación revisada**, nunca `httpdocs`, a `/srv/muserelay-aws-stage/core`. Copiar una release comprometida del módulo a `/srv/muserelay-aws-stage/bridge`, sin `.git`, `.env`, node_modules, dist, credenciales ni datos. Instalar las dependencias exclusivamente en esa copia. Con el overlay Composer ya preparado: `composer update screenartsl/muserelay-aws-engagement-bridge-core --no-scripts --no-plugins --prefer-dist --no-interaction`. Conservar su lock final y hashes en el artefacto privado de release. Vaciar el manifiesto de package discovery en esa copia (`bootstrap/cache/packages.php` devuelve array vacío) y no ejecutar artisan productivo ni sus scripts.
3. Fuente de solo lectura para los procesos. Crear storage, cache, Redis y evidencia propios bajo `/var/lib/muserelay-aws-stage`; nunca usar rutas replicadas. Datos PostgreSQL propiedad 0700 del usuario PostgreSQL. Archivo de evidencia 0600, configuración 0600 del usuario de aplicación. No reutilizar APP_KEY, keys de cifrado, firmas o credenciales existentes.
4. Inicializar un clúster PostgreSQL 16 **nuevo**, como usuario `muserelay-aws-pg`, con autenticación local peer y host reject. Arrancarlo temporalmente en su socket privado/55434 usando el hba inicial; como su administrador local crear `"muserelay-aws-stage" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION` y base `bridge_phase4_test` de su propiedad. Detener ese arranque inicial. El servicio definitivo usa los archivos PostgreSQL/hba preparados, que solo admiten el rol de aplicación en la base demo. Nunca ejecutar comandos contra el clúster del sistema.
5. Instalar únicamente las unidades nuevas `bridge-stage-{postgresql,redis,fpm,worker,enqueue}.service` y `bridge-stage-enqueue.timer`. Revisar rutas de binarios y dependencias de arranque en el nodo; confirmar permisos de sockets y grupo Caddy. Arrancar primero PostgreSQL/Redis, después FPM y finalmente worker/timer tras la activación. No arrancar aún el agente.
6. Crear configuración privada a partir de `stage-runtime.example.json`. `expiresAt` será epoch UTC futuro a no más de 96 h. Generar tres claves de cifrado de 32 bytes y dos firmas independientes de al menos 32 bytes. La estructura de `signing-stage.json` y SecretString será `{ "phase4-demo": { "core": "<clave privada>", "outbound": "<otra clave privada>" } }`. Nunca guardar claves en argumentos, historial de shell, captura de pantalla o Git. El flujo usa lectura de archivos privados; no hay script que genere claves o cree secretos automáticamente durante preparación.
7. Preparar `/etc/muserelay/aws-bridge/bedrock-stage.json`, modo 0600, con `aws_region=eu-west-1`, `aws_access_key_id`, `aws_secret_access_key` y `aws_session_token` **temporales de la identidad Bedrock aprobada**. El SDK Core necesita esas credenciales explícitas: no usa automáticamente un instance profile. No crear usuario IAM de claves permanentes. Renovar sesión si caduca; no asumir que un token dura toda la ventana de 96 h.
8. El `outboundUrl` real llega del output del stack desactivado. Con configuración `enabled:false`, ejecutar **en la instalación nueva**, como usuario de aplicación y con las variables APP_*_CACHE de las unidades preparadas, `APP_ENV=bridge-staging php .../integration/live-staging/console.php seed`. Crea el esquema/fixtures propios y deja binding desactivado; rechaza una base ya instalada. No usar migrate:fresh ni Redis FLUSH en servicios del host.

La instalación/configuración anterior no ha sido ensayada con procesos de fase 4 en esta sesión: se han revisado archivos, clases y sintaxis, y se reutiliza el fixture integrado probado en fase 3. Debe superar la revisión de instalación en el nodo aprobado antes de declararla operativa.

## 3. AWS desactivado, solamente después de aprobación

1. Confirmar STS no-root de Screen Art y región, ownership S3, modelos EU y cuotas; revisar ARN finales y políticas mínimas de [PHASE-4.md](PHASE-4.md). Inspeccionar cualquier stack/bucket/número preexistente: no sobrescribir recursos ajenos. La WABA debe ser exclusivamente de demo, creada y vinculada con autorización. Si el destino SNS apunta a otro servicio, detenerse antes de sustituirlo.
2. Crear el secreto propio y bucket/prefijo privado si no existen, usando transferencia de secretos por archivo/STDIN protegido, nunca texto en línea. Crear/reutilizar el rol Bedrock aprobado y revisar trust/InvokeModel. No otorgar AdministratorAccess. Con CMK adicional hay que revisar la política KMS antes de seguir.
3. Renderizar los inputs finales con `plan.py`. Revisar que no quede `TO_SUPPLY`, que `BindingsJson` tenga `enabled:false`, que `LiveSend=false` y que el destinatario sea el único de demo. El render **no equivale a validación de permisos ni a autorización**.
4. Empaquetar y crear un change set **sin ejecutarlo automáticamente**. Ejemplo para la futura shell, con variables ya revisadas y región/perfil explícitos:

```sh
npm ci --ignore-scripts --no-audit --no-fund
npm run check
npm test
npm run bundle
sam package --template-file template.yaml --s3-bucket "$BRIDGE_ARTIFACT_BUCKET" --s3-prefix "$BRIDGE_ARTIFACT_PREFIX" --output-template-file "$BRIDGE_PACKAGED_TEMPLATE" --profile "$BRIDGE_AWS_PROFILE" --region eu-west-1
aws cloudformation create-change-set --stack-name muserelay-aws-bridge-stage --change-set-name phase4-reviewed-create --change-set-type CREATE --template-body "file://$BRIDGE_PACKAGED_TEMPLATE" --parameters "file://$BRIDGE_DISABLED_PARAMETERS" --capabilities CAPABILITY_IAM --profile "$BRIDGE_AWS_PROFILE" --region eu-west-1
aws cloudformation describe-change-set --stack-name muserelay-aws-bridge-stage --change-set-name phase4-reviewed-create --profile "$BRIDGE_AWS_PROFILE" --region eu-west-1
```

Estos comandos escriben en AWS y **no se han ejecutado**. Si existe el stack, no cambiar CREATE por UPDATE sin revisar su identidad/historial. Comparar los recursos/roles finales con la expansión revisada. Ejecutar el change set solo dentro de la autorización concreta de despliegue. Guardar outputs y versión/hash del paquete. Verificar resource policies, roles efectivos, mappings, límites y retenciones con lectura autenticada.

5. Crear el registro DNS y configurar únicamente el vhost técnico tras autorización. `caddy adapt` solo parsea; antes de una recarga del proxy real revisar la configuración completa, permisos del socket y reversión del nuevo bloque. Obtener TLS y comprobar HTTPS válido. Las rutas ajenas devuelven 404; las dos rutas Bridge exigen HMAC cuando se activen. Health no está publicado. No cambiar los vhosts actuales.

## 4. Activación y prueba, solamente después de aprobación de envío

Con todo preparado, revisar juntos la misma identidad de binding, account/region, topic ARN real, Meta WABA/phone y ARN AWS en ambos lados; versión Meta `v23.0` es candidata y debe confirmarse como soportada antes del ensayo. Verificar que el SecretString y archivo Core contienen las mismas firmas por dirección sin mostrarlas.

1. Mantener LiveSend false hasta la ventana autorizada. Habilitar solo el binding de staging y organización 97101 en la base privada, y `enabled:true` en su configuración privada. Activar worker/timer nuevos. No editar config de producción.
2. Actualizar el stack revisado para habilitar **solo ese binding** y `LiveSend=true`, con límites 6/6 y runId único. Respetar caché de configuración/secreto Lambda de hasta 60 s. Activar destino Social→SNS solamente en WABA demo cuando el endpoint esté comprobado. No enviar mensajes durante comprobaciones previas; no llamar testConnection de Bedrock sin permiso de consumo.
3. Capturar `before.json` con `console.php snapshot before` desde la instalación nueva. Un operador/probador envía manualmente el texto sintético del [guion](PHASE-4.md), espera respuesta y captura `after_first.json`; confirmar cero tickets y error nativo `ticket_confirmation_required`.
4. Enviar «Sí, confirmo…», esperar referencia real y capturar `after_second.json`. Si el primer turno creó ticket o no se produjo confirmación comprobable, detener la prueba: no se considera éxito. Si hay duplicados/estado uncertain, no reenviar automáticamente; revisar acuses antes de cualquier acción para evitar doble envío.
5. Ejecutar el colector de solo lectura `collect-logs.py --inputs <archivo privado> --since-epoch-ms <inicio real>`. Combinar sus registros permitidos con `evidence.ndjson` del worker privado, manteniendo la procedencia del colector. Guardar snapshots, hashes de archivos, releaseCommit y resultados del preflight en un directorio 0700 fuera de vhosts. Revisar que no contienen texto/secretos ni teléfonos.
6. Copiar `manifest.pending.json` al directorio privado. Cambiarlo a `LIVE_AWS_RUN`, `simulation:false`, `accountVerified:true`, commit real y verificaciones de observación/procedencia **solo después de comprobar esos hechos**. Ejecutar `verify-evidence.py --directory <directorio privado>`. Exigir PASS, dos respuestas observadas en WhatsApp y una revisión AWS autenticada de las fuentes; un JSON de PASS fabricado no es evidencia real.

## 5. Desactivación, rollback y eliminación autorizados

El plan de retirada debe formar parte de la aprobación de la ventana. No existe script de limpieza que recorra o borre recursos por prefijo sin revisión.

El staging es temporal, no una segunda instalación comercial. Si solo existe la demo, se apaga y retira conservando el código/historial. Si se ha autorizado y activado después el Bridge definitivo dentro del MuseRelay actual, comprobar primero que su CoreBaseUrl, bindings, firmas y recursos activos son definitivos y no dependen del hostname, BD, Redis ni credenciales de demo. No eliminar un endpoint aún referenciado ni incluir recursos definitivos en la limpieza. La instalación y cualquier cambio/recarga de producción requieren una autorización diferente a la de staging.

1. Parar la entrada de la **WABA demo** desactivando su destino de eventos; dejar intactos los destinos/Meta webhooks actuales. Deshabilitar binding Core demo/configuración, detener timer/worker de staging y actualizar el stack demo con `LiveSend=false` y binding desactivado. Parar workers antes de que caduque la sesión o termine la ventana. Revisar mensajes en vuelo y outbox uncertain antes de purgar colas; no reejecutar envíos inciertos.
2. Guardar solo evidencia redactada necesaria; no exportar base/Redis con teléfonos o credenciales a Git. Resolver retención y eliminar datos de prueba privados tras revisión.
3. Eliminar **solo el stack ARN confirmado** de esta cuenta/región. Esperar DELETE_COMPLETE y comprobar recursos residuales. La tabla DynamoDB State tiene **DeletionPolicy/UpdateReplacePolicy Retain**: identificar su ARN físico previamente guardado y eliminarla expresamente, solo después de exportar evidencia/deduplicación necesaria. Verificar también backups/PITR/exportaciones: la retención no se limpia por borrar el stack.
4. Desprogramar/eliminar el secreto propio con recuperación mínima de 7 días, después de inutilizar firmas y detener todo acceso; no usar ForceDeleteWithoutRecovery. Revisar/limpiar solo objetos/versiones del prefijo S3 de esta demo. El bucket se elimina únicamente si fue creado para ella y no contiene otros proyectos; atender versiones/delete markers.
5. Revisar y retirar la política/rol Bedrock solo si se creó para esta prueba y ya no tiene sesiones/uso necesario. Si se reutilizó un rol, retirar únicamente el cambio aprobado. No borrar usuarios/roles preexistentes ni cuentas, WABA o números actuales. Desvincular WABA/número demo es una acción externa distinta, que debe constar en la aprobación.
6. Retirar solo vhost/DNS/certificado de staging con revisión del proxy completo. Detener/deshabilitar las cinco unidades nuevas y timer; eliminar usuarios/rutas solo cuando no existan procesos ni dependencias. No reiniciar Core, colas, PostgreSQL, Redis o paneles de producción.
7. Comprobar ausencia de actividad/gastos residuales y registrar los ARN eliminados/conservados. El presupuesto no se detiene por cerrar el navegador ni por obtener PASS.

## 6. GitHub privado: aprobación independiente

Conservar los commits originales y todo el historial local. Revisar `review-history.py` antes y después del commit final; mantener un bundle privado fuera de vhosts. No se incluye el Core completo ni los artefactos/datos/secretos de runtime. En shell autenticada y **tras autorización de publicación**, verificar que el propietario es `screenartsl` y visibilidad PRIVATE, reconciliar cualquier remoto existente sin force-push, y subir la rama revisada. [Instrucciones de repositorio](REPOSITORY-UPLOAD.md). No crear repositorio, push ni invitaciones durante esta fase de preparación.
