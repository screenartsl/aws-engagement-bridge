# Reversión del conector instalado

Alcance: instalación autorizada de la release PHP 0.4.0 y soporte de extensiones del Core. No incluye infraestructura AWS, porque no se ha desplegado. Fecha operativa: 25 de octubre de 2026, Europe/Paris; los registros técnicos y commits conservan sus timestamps UTC reales.

## Puntos de recuperación

- Core anterior: `b8e9bf8c74789689a7cebddd1856a094e380c9d9`.
- Único commit de Core instalado por este trabajo: `7252eac087973d12d243299cf8d0f03569cfddc2`.
- Release PHP instalada: `01e14f26a5db24cdaf1d9b6b5d6e5e1d009380e2`, con hashes en `httpdocs/modules/aws-engagement-bridge/module-release.json`.
- Backup privado: `/var/tmp/muserelay-core-before-extensions-z1g47mot/database-before-extensions.dump`, PostgreSQL custom format, 50.602.811 bytes, modo 0600, directorio 0700.
- SHA-256: `18cd62032beb73788cbcddb9c075765eb4513b9b4c334a490913086095727551`.
- Archivo privado de los archivos de código anteriores: `code-before-extensions.tar.gz`, en el mismo directorio. SHA-256: `d3b38dc8ce962ac00e853cb8bd2e09badd1a4e9dbd2b9ee973b53d2976602d07`.

No subir estos backups, los logs originales, los datos restaurados ni `.env` a GitHub. Esta reversión conserva la configuración y claves actuales, que no se modificaron. No es una copia de recuperación completa del servidor, adjuntos o Redis; esos recursos no fueron alterados por la instalación.

## 1. Detener el trabajo nuevo

Ante una regresión atribuible a la instalación, detener cualquier activación o instalación adicional y registrar hora, HEAD, estado Git y síntomas sin copiar secretos. Mantener el Bridge desconectado, organizaciones vacías y sin bindings. En la instalación verificada ese es ya el estado efectivo; no hay workers ni entradas de cron del Bridge.

Si en el futuro hubiese trabajo dedicado, detener únicamente ese worker y revisar mensajes en vuelo. No matar los workers de canales actuales. Desconectar el Core no retira mensajes ya aceptados por AWS ni apaga sus colas; cuando exista AWS habrá que aplicar también su runbook específico.

## 2. Revertir el código conservando otras sesiones

1. Leer `git status --short` y `git rev-parse HEAD` del Core. Registrar los hashes de `.env`, `composer.json` y `composer.lock` y los cambios ajenos. En este despliegue ya existían cambios en `storage/geoip/GeoLite2-Country.mmdb` y `resources/views/contacts/index.blade.php.back`; deben conservarse.
2. Preparar otra worktree privada desde el HEAD actual y una rama de reversión. Revertir **solo** el commit de instalación mediante `git revert 7252eac087973d12d243299cf8d0f03569cfddc2`. Revisar el diff antes de integrarlo. Si hay commits posteriores, conservarlos y resolver los conflictos en la worktree. No usar `reset --hard`, checkout masivo del baseline ni restaurar encima todo el archivo tar.
3. Comprobar que el resultado retira el cargador, su registro, el control nuevo y la release, y restaura únicamente las siete modificaciones de archivos anteriores. Probar la reversión en PostgreSQL aislado. No ejecutar migraciones globales ni Composer en producción.
4. Justo antes de integrar, volver a comprobar el HEAD y los archivos afectados del Core vivo. Si cambiaron desde la preparación, rehacer la revisión sobre el nuevo HEAD. Integrar mediante avance lineal de la rama revisada, sin sobrescribir trabajo ajeno. Git debe rechazar cualquier conflicto con modificaciones locales; no forzarlo.
5. El servidor atiende los paneles por Caddy/PHP-FPM; validar el resultado por HTTPS local con Host/SNI correctos. Si el proceso dedicado MuseRelay Octane necesita cargar la reversión, usar únicamente `php artisan octane:reload --no-interaction` para ese proceso, bajo la autorización de reversión. No reiniciar ni recargar PHP-FPM, Caddy, PostgreSQL o Redis compartidos sin autorización específica.

La orden Git anterior es el paso de preparación en la worktree; no una instrucción para ejecutarla directamente sobre producción sin estas comprobaciones.

## 3. Base de datos

La reversión ordinaria del código **conserva las cinco tablas aditivas**. El Core anterior funciona con ellas presentes, como se comprobó en las pruebas de paneles. No es necesario restaurar toda la base para desconectar o retirar el conector.

Tablas propias: `aws_bridge_bindings`, `aws_bridge_events`, `aws_bridge_actions`, `aws_bridge_nonces`, `aws_bridge_outbox`. La única fila de control añadida es la migración `2026_10_24_180000_create_aws_bridge_tables`. No se cambiaron definiciones de tablas históricas.

Si se requiere retirar también el esquema, hacerlo como operación revisada aparte: sin código/worker consumidor, comprobar de nuevo que esas cinco tablas están vacías, respaldar su estado y aplicar exclusivamente el `down()` de esa migración en una transacción, retirando solo su fila de migraciones. Ante cualquier tabla con datos o dependencia inesperada, conservarla y detener la retirada. Nunca ejecutar `migrate:rollback`, `migrate:fresh`, `db:wipe`, borrar organizaciones/canales ni vaciar Redis. El ensayo sintético de fase 3 verifica que el rollback de este esquema conserva mensajes, tickets y canales nativos.

## 4. Restauración del backup

Restauración real verificada en PostgreSQL 16 con cluster temporal del usuario no root, socket privado y namespace de red vacío, sin listeners TCP. Se ejecutó `pg_restore --exit-on-error --no-owner --no-privileges` contra **`bridge_restore_test`**, jamás contra la base productiva. Se comprobaron las 255 tablas (254 de `public`, una de `audit`), 827.787 filas y las definiciones anteriores frente al servidor actual en solo lectura. El cluster se detuvo al terminar.

Para repetir el ensayo, crear un cluster nuevo fuera de web/Git con `initdb`, autenticación local solo dentro de un directorio 0700 y conexiones TCP rechazadas; iniciar en un namespace sin red, crear una base cuyo nombre contenga `test` y restaurar allí. Verificar SHA-256 antes/después, salida de `pg_restore`, recuentos, restricciones/índices y esquema. No cargar `.env`, proveedores, workers o aplicaciones contra los datos reales restaurados. Las pruebas de panel se ejecutaron contra **otra base vacía con el mismo esquema y fixtures sintéticos**, sin claves productivas. Detener únicamente el cluster creado por el ensayo y guardar un informe sin datos personales.

La restauración del backup completo sobre producción queda reservada a recuperación de desastre con ventana de escritura controlada y reconciliación de datos posteriores. No automatizarla como reversión de este módulo: perdería cambios legítimos posteriores al backup. Antes de una recuperación de ese alcance se necesita su autorización y plan específico.

## 5. Criterio de aceptación de la reversión

Acceso principal/reseller y Megadmin responden; protección de sesión se conserva; agente/tickets/transportes pasan las regresiones sintéticas; `.env` y dependencias no cambian; no aparecen errores nuevos atribuibles a la operación. Bridge sin ejecución, organizaciones ni canales AWS. Conservar evidencia de HEAD anterior, commit de reversión, estado desactivado y comprobaciones.

Ensayo realizado: parche inverso aplicado en una copia privada, los **42 archivos afectados** coinciden con el baseline o desaparecen si fueron nuevos. Las 14 pruebas de paneles sobre el Core anterior pasan con las tablas aditivas presentes. No se aplicó la reversión en producción porque no se detectó una regresión. Véase [informe](POST-INSTALL-VERIFICATION.md) y [evidencia](evidence/core-postinstall-verification.json).
