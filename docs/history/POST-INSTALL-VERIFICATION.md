# Verificación posterior de instalación — Bridge 0.4.0

Resultado: **PASS para conservar el conector instalado y desactivado**. No autoriza ni demuestra operación real AWS/WhatsApp. Fecha operativa: 25/10/2026 Europe/Paris; evidencias en UTC.

## Código instalado y trabajo de otras sesiones

- Core: `7252eac087973d12d243299cf8d0f03569cfddc2`.
- Release PHP de este repositorio: `01e14f26a5db24cdaf1d9b6b5d6e5e1d009380e2` (0.4.0).
- Baseline previo: `b8e9bf8c74789689a7cebddd1856a094e380c9d9`.

Se verificaron 41 archivos de la instalación contra la copia usada en las pruebas, todos idénticos; el archivo adicional `module-release.json` se verifica contra el commit de origen y los 26 hashes del paquete. Los siete archivos anteriores modificados coinciden con el baseline en el backup de código. `.env`, `composer.json` y `composer.lock` conservan sus hashes. Los dos cambios locales ajenos previos se mantienen intactos. No se sobrescribió trabajo de otra sesión, no se hizo reset/pull/rebase del Core ni se subió el Core completo a GitHub.

## Resultados

| Comprobación | Resultado y alcance |
| --- | --- |
| Backup PostgreSQL | Restauración real correcta: 255 tablas, 827.787 filas, PostgreSQL 16, socket privado y sin red. SHA-256 inalterado. Cluster temporal detenido. |
| Esquema anterior | Coinciden 3.984 columnas, 764 restricciones, 817 índices y tres triggers de los esquemas `public`/`audit`, excluyendo solo los objetos propios Bridge. También coinciden relaciones, vistas y políticas. No se afirma que los datos vivos se congelen: la actividad legítima posterior puede cambiar filas. |
| Estado Bridge | Desactivado en ExtensionManager y Store; cero organizaciones autorizadas, cero canales `managed_provider=aws_social`, cero bindings y las cinco tablas vacías. |
| Entrada real HTTPS | 32 comprobaciones por Caddy local con TLS/Host/SNI correctos: central y tres dominios de panel existentes responden; login 200 y secciones protegidas redirigen al login; callback Bridge 503 `bridge_disabled`. Sin login ni suplantación de usuarios productivos. |
| Paneles autenticados | 29 casos en base separada vacía con esquema real y fixtures sintéticos: 15 con Core instalado y 14 con Core anterior. Login, dashboard, Megadmin, catálogo, extensiones, reseller/clientes, agentes, tickets y canales renderizan 200 sin error flash. Se prueban los hosts central y reseller. |
| Agentes/tickets/canales | Suite aislada final: 32 pruebas Node; 30 PHP SQLite; 30 PHP PostgreSQL/170 aserciones; conversación de dos turnos, ticket nativo con confirmación, aislamiento de organización y transacciones; regresiones Meta directo/automatización y dispatch de otros proveedores. HTTP/LLM/AWS simulados, PostgreSQL/Redis y workers reales. No certifica entrega externa. |
| Sin ejecución Bridge | Sin procesos dedicados ni cron de usuario del Bridge; no se añadió trabajo al scheduler Core. El provider registra rutas/servicios, no ejecuta un daemon ni procesa mensajes. Los hooks seleccionan AWS solo para canales explícitos AWS y rechazan el módulo apagado; las rutas de los demás transportes conservan su comportamiento en las regresiones. |
| Logs | Lectura de 57 archivos locales: sin errores nuevos ni firmas nuevas de WARNING desde la recarga dedicada. Persisten avisos anteriores; ninguna señal de fallo del cargador/Bridge. Ventana exacta en la evidencia JSON. |
| Reversión | Parche inverso ensayado, 42 archivos verificados contra baseline; Core anterior renderiza las 14 páginas de regresión con las tablas aditivas presentes. Backup restaurado realmente. Procedimiento completo en [CORE-ROLLBACK.md](../CORE-ROLLBACK.md). |

## Incidencias y límites

La instalación desactivada ya se había aplicado cuando llegó la petición adicional de ensayar la restauración: el backup y su decodificación completa se habían verificado antes; la restauración real se completó **después**. Para futuras instalaciones se incorpora el ensayo antes de aplicar código.

Las primeras fixtures de panel no incluían un país fiscal y luego activaban la redirección fiscal esperada del reseller. Se corrigieron exclusivamente los datos sintéticos, sin cambiar producto. Las pruebas finales comparativas pasan en las dos versiones.

Una sonda inicial dirigida al puerto Octane no representaba las rutas reseller atendidas por PHP-FPM y devolvía 404. Se corrigió la sonda para atravesar el HTTPS local real de Caddy: las rutas existentes pasan. No fue necesario cambiar routing ni recargar servicios compartidos. Se registran estos ajustes del ensayo para no confundirlos con regresiones productivas.

No se ejecutó un navegador/JavaScript ni se inició sesión como un cliente real. Los envíos y modelos externos se simularon para no producir mensajes o costes. No se ha validado todavía la conversación real AWS CDS/Bedrock ni la capacidad para miles de usuarios simultáneos.

No se detectó una regresión que requiriera revertir la instalación productiva. Los calendarios y tickets históricos conservan su implementación: no se han convertido en paquetes independientes. No se han creado recursos AWS, activado Social, autorizado organizaciones, enlazado WABA ni enviado mensajes reales.

## Evidencia

[Resumen JSON sin datos personales ni secretos](../evidence/core-postinstall-verification.json). Los backups y logs completos permanecen privados fuera de web/Git, en `/var/tmp/muserelay-core-before-extensions-z1g47mot`. El código fuente continúa en este repositorio privado, conservando los commits originales; los commits de documentación posteriores no cambian la release instalada.
