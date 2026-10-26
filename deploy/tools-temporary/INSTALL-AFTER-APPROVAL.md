# Secuencia operativa posterior a aprobación

No ejecutar estos pasos por recibir este archivo. Toda la entrega es una propuesta.
Leer primero README.md, sus límites, dependencias y revisiones independientes.

1. Confirmar SAM con evidencia real; autorizar por separado su ejecución.
2. Revisar los JSON, fecha de caducidad, delta Foundation, stack policy y el diseño
   del runner. Validar policies con Access Analyzer y simulación solo bajo permiso.
3. Implementar/probar el runner sin credenciales. No asignar escritura antes.
4. Administrador AWS: crear conjunto separado con
   `foundation.update.proposed.yaml`, GrantSocialSend=false, sin service role para
   Foundation. Comparar Originals: baseline esperado d60476..., cuatro Add y tres
   Modify sin reemplazo, solo tres nuevas asociaciones PermissionsBoundary.
   Otro resultado detiene el procedimiento. Autorizar ejecución explícitamente;
   después verificar confianza, boundaries, políticas adjuntas y límites efectivos.
5. Administrador de Identity Center: confirmar InstanceArn, región administrativa,
   permisos de gestión y PrincipalId de la identidad humana dedicada con MFA.
   Revisar/desplegar por separado `sso.permission-sets.proposed.yaml` mediante su
   identidad administrativa autorizada. Ambos Assign*=false al crear permission
   sets. Después aprobar la asignación de Prepare y cambiar solo AssignPrepare a
   true, con revisión independiente. No activar DeployClosed automáticamente.
   No crear identidades humanas o asignaciones improvisadas desde esta guía.
6. Operador root, únicamente tras revisar el script existente:

```sh
bash /var/www/vhosts/muserelay.com/modules-develop/aws-engagement-bridge/deploy/audit-readonly/prepare-linux-root.sh
```

   No reejecutar si detecta una cuenta previa; revisar el UID/home existentes.
   Instalar AWS CLI v2 oficial y verificar firma/integridad según AWS. Instalar
   runner/manifiesto en ruta root-owned no escribible por muserelaycom ni el UID
   del runner. La regla sudo permitirá solo ese ejecutable y peticiones tipadas,
   no `aws`, `python`, shells, perfiles o comandos arbitrarios. No se entrega una
   regla sudo sin el runner implementado y probado.
7. Operador autenticado, en una sesión privada de root, iniciar la cuenta aislada:

```sh
sudo -iu muserelay-bridge-audit
umask 077
aws configure sso --profile bridge-tools-prepare
aws sso login --profile bridge-tools-prepare --use-device-code --no-browser
aws sts get-caller-identity --profile bridge-tools-prepare --region eu-west-1 \
  --query '{Account:Account,Arn:Arn}' --output json --no-cli-pager
```

   Usar Start URL/región SSO reales y seleccionar solo BridgeToolsPrepare.
   El titular completa MFA en su navegador. No guardar tokens/códigos en chat.
   Verificar cuenta 365470560685 y rol AWSReservedSSO_BridgeToolsPrepare_...
   No seleccionar Admin. No compartir la shell con el agente/Core. El agente
   conserva únicamente la interfaz del runner aprobada; no puede leer el home.
8. Verificar CloudTrail de gestión y aprobar data selectors acotados si se requiere
   registrar subidas/consumo. Aplicar stack policy protectora mediante el operador
   autorizado, comprobar lectura posterior. Registrar cuenta, identidad, ventana,
   aprobaciones, hashes y ClientRequestToken. No imprimir mensajes ni secretos.
9. Autorizar primer PutObject condicional AES256 de archivo revisado y primer
   CreateChangeSet con el nuevo ejecutor. No utilizar scripts de despliegue libres.
   Revisar Original y parámetros. Conjunto cerrado: cero recursos IAM, cero cambios
   de ingreso, outbound false, flags false, destinatarios vacíos, bindings disabled.
10. Para ejecutar: aprobar el plan/conjunto, retirar asignación Prepare y asignar
    DeployClosed mediante revisión de la plantilla SSO; no asignar ambos perfiles.
    Login privado en perfil distinto bajo el UID aislado. Runner usa exclusivamente
    ese perfil y comprueba el manifiesto aprobado. No usar un conjunto anterior
    con ejecutor amplio ni delegar su revisión al nombre del conjunto.
11. Tras UPDATE_COMPLETE, comprobar recursos y retirar DeployClosed. Cerrar sesión
    no basta: revocar asignación y permisos de sesiones activas según README.
    Congelar código/artefactos. Solo entonces solicitar aprobación de recepción,
    límites de muestreo/prueba y posterior envío. No tocar Core por este permiso.

Revocación de emergencia: detener runner, bloquear permisos efectivos del permission
set/reprovisionar o aplicar Deny SCP administrado, retirar asignaciones, terminar
sesiones y limpiar solo la caché aislada. Verificar una sesión anterior denegada.
No borrar runtime, datos o recursos de mensajería como forma de retirar acceso.
La fecha absoluta también deniega peticiones nuevas al llegar su vencimiento.
Si hay una actualización en curso, coordinar recuperación con administrador antes
de retirar permisos al ejecutor. No renovar la fecha sin nueva autorización.
