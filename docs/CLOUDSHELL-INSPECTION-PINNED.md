# Descarga fijada y simulación de la inspección SQS

Fecha: 25-10-2026, Europe/Paris. El usuario da GO condicionado a un solo muestreo, pero solicita **revisar el código y ejecutar primero su simulación; no ejecutar ReceiveMessage todavía**. Este documento cubre únicamente descarga, integridad y plan local. No activa AWS, Core, workers ni envíos.

## Archivo revisado

- Commit: `55e5437c2b39ff42a391c2e46e03267e8ebd335c`.
- Ruta: `scripts/cloudformation/inspect-ingress-sample.py`.
- SHA-256: `3c4727d791082f6e242c5a710d1c74fbc415b0f1dda313971b11ab533c1d2f34`.
- [Archivo exacto en GitHub privado](https://github.com/screenartsl/aws-engagement-bridge/blob/55e5437c2b39ff42a391c2e46e03267e8ebd335c/scripts/cloudformation/inspect-ingress-sample.py).

La revisión del código identifica una sola llamada ReceiveMessage en inspect(), precedida de identidad/cuenta, cola/cifrado/redrive/visibilidad, ausencia de mensajes en procesamiento y mappings Lambda vacíos. Sus parámetros fijan máximo dos mensajes, espera cero y visibilidad de 30 segundos. El cliente recibe AWS_MAX_ATTEMPTS=1 y no hay bucle de recepción. No existen llamadas de borrado, purga, cambio de visibilidad, publicación, envío o activación. La salida usa campos fijos, enumeraciones cerradas, cantidades y booleanos. El historial CLI debe estar deshabilitado o sin configurar; no se cambia automáticamente.

El script no ha cambiado. Los doce tests del mismo commit se ejecutaron en una carpeta desechable sin red. Además se ejecutó main() en su modo predeterminado con subprocess.run bloqueado: cero invocaciones. Esto complementa la revisión del código, no demuestra una lectura AWS. [Registro de revisión](evidence/inspection-pinned-code-review.json).

## Opción A: CloudShell ya tiene gh autenticado para el repositorio

La sesión AWS AdministratorAccess no autentica GitHub automáticamente. Esta opción requiere GitHub CLI ya instalado y una sesión GitHub existente con acceso de lectura al repositorio privado. No copiar el token del servidor, pegar tokens en el chat ni ponerlos en el comando. Si no se dispone de ese acceso, usar la opción B.

El siguiente bloque descarga por la API autenticada de GitHub, exige el hash exacto y ejecuta **solo el modo local de plan**. Los && impiden ejecutar si falla la descarga o el hash:

```sh
umask 077
BRIDGE_SAMPLE_DIR=$(mktemp -d "$HOME/aws-bridge-inspection.XXXXXX") &&
cd "$BRIDGE_SAMPLE_DIR" &&
gh api --hostname github.com --method GET \
  -H 'Accept: application/vnd.github.raw+json' \
  'repos/screenartsl/aws-engagement-bridge/contents/scripts/cloudformation/inspect-ingress-sample.py?ref=55e5437c2b39ff42a391c2e46e03267e8ebd335c' \
  > inspect-ingress-sample.py &&
printf '%s\n' '3c4727d791082f6e242c5a710d1c74fbc415b0f1dda313971b11ab533c1d2f34  inspect-ingress-sample.py' | sha256sum --check --strict - &&
python3 -B inspect-ingress-sample.py
```

No se usa main, un enlace temporal con credenciales ni una descarga pública. GitHub acepta un commit en ref y el tipo raw devuelve el contenido del archivo. [API Contents](https://docs.github.com/en/rest/repos/contents#get-repository-content), [gh api](https://cli.github.com/manual/gh_api).

## Opción B: navegador autenticado, sin gh ni credenciales GitHub en CloudShell

Abrir el archivo exacto enlazado arriba y descargar el archivo raw conservando el nombre `inspect-ingress-sample.py`; no guardar la página HTML ni editar el contenido. Cargar ese único archivo mediante Acciones → Cargar archivo de CloudShell. Desde el directorio donde se haya cargado, ejecutar:

```sh
printf '%s\n' '3c4727d791082f6e242c5a710d1c74fbc415b0f1dda313971b11ab533c1d2f34  inspect-ingress-sample.py' | sha256sum --check --strict - &&
python3 -B inspect-ingress-sample.py
```

Si el hash falla, detenerse. No corregir o sustituir código a mano ni ejecutar otro archivo por similitud de nombre.

## Resultado esperado y punto de parada

Primero: `inspect-ingress-sample.py: OK`. Después, JSON con `mode=PREPARED_NOT_EXECUTED`, `awsCalls=0`, `receiveCalls=1`, `maximumMessages=2`, `visibilitySeconds=30`, `waitTimeSeconds=0`, `automaticSdkRetries=false`, `deletes=false`, `visibilityResetCalls=false` y `coreOrRuntimeActivation=false`.

`receiveCalls=1` describe el plan, no una recepción realizada; `awsCalls=0` indica que esta simulación no realiza consultas AWS. La simulación tampoco ejecuta los preflight reales: estos se comprobarían antes de la futura lectura dentro de la ejecución autorizada. Compartir únicamente el OK y el JSON del plan para cerrar la condición pendiente. **No añadir --receive-once en esta fase.**

La autorización condicionada mantiene el alcance de una recepción total de hasta dos mensajes. No autoriza repetir manualmente si hay error o una respuesta vacía. El script no impide que alguien lo lance otra vez por separado: la restricción es de procedimiento además de código. El muestreo futuro incrementará el contador y aplicará visibilidad temporal; no se revierte el contador ni se garantiza cero efectos de redrive si hubiera recepciones previas desconocidas. El [plan de inspección](INBOUND-SAMPLE-INSPECTION.md) conserva sus efectos, límites y coste orientativo.
