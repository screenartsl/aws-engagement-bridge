# Inspección puntual de eventos recibidos — preparada, no ejecutada

> Actualización posterior: el usuario da GO condicionado, limitado a una recepción, y pide revisión del commit fijado y simulación antes de cualquier ReceiveMessage. El [procedimiento exacto de descarga e integridad](CLOUDSHELL-INSPECTION-PINNED.md) cubre esta fase. El código del commit `55e5437c2b39ff42a391c2e46e03267e8ebd335c` se ha revisado y permanece sin cambios; no se ha realizado ninguna recepción.

Fecha: 25-10-2026, Europe/Paris. Cuenta Screen Art `365470560685`, región `eu-west-1`. No cambia la infraestructura ni activa el Core, workers, agentes, Bedrock o envíos. **La ejecución del muestreo requiere autorización específica: ReceiveMessage afecta al estado de recepción SQS.**

## Evidencia nueva recibida

El usuario comunica WABA Meta `29207063275646749` vinculada/activa, número de demo conectado y destino SNS de staging habilitado. Contadores comunicados: entrada 2 visibles, ambas DLQ 0, en procesamiento 0 y retrasados 0; no se han leído/eliminado/procesado mensajes. Se registra en [social-linked-ingress-user-evidence.json](evidence/social-linked-ingress-user-evidence.json), como resumen aportado, no como consulta AWS del agente.

Estos contadores muestran actividad de ingreso, pero no identifican el contenido, su origen individual ni una conversación. Los eventos podrían ser mensajes, estados o cambios administrativos. La identificación necesita revisar el sobre recibido. No se presenta todavía como prueba de conversación, Bedrock o integración con MuseRelay.

## Alcance exacto propuesto

- Ejecutar desde la sesión SSO AdministratorAccess autorizada de Screen Art en CloudShell.
- Comprobar identidad, URL/ARN de cola, cifrado SSE-SQS, configuración de redrive, ausencia de mensajes en procesamiento y mappings Lambda vacíos antes de recibir.
- **Una sola llamada ReceiveMessage, hasta dos mensajes, WaitTimeSeconds=0 y VisibilityTimeout=30 segundos.** Sin bucles, paginación ni reintentos automáticos del cliente.
- No DeleteMessage, PurgeQueue, ChangeMessageVisibility, redrive, publicación SNS, envío WhatsApp, IAM, despliegue o llamada al Core. No se ejecuta el normalizador ni el worker de producción.
- El cuerpo completo se recibe temporalmente en memoria del proceso para interpretarlo. No se imprime ni guarda deliberadamente. La salida se construye mediante una lista cerrada de campos.

SQS no ofrece una vista del cuerpo sin recepción: ReceiveMessage incrementa el contador del mensaje y lo oculta durante el plazo indicado. Si no se elimina, vuelve a estar disponible al expirar la visibilidad. La retención original continúa y el contador no se revierte. Las recepciones repetidas pueden provocar su traslado a DLQ con maxReceiveCount=5. No repetir si faltan mensajes, hay un error o el resultado es incierto. Una llamada corta puede devolver menos mensajes de los esperados o ninguno. [ReceiveMessage oficial](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/APIReference/API_ReceiveMessage.html), [configuración de redrive](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/APIReference/API_GetQueueAttributes.html).

La ausencia de mappings Lambda no excluye un poller externo distinto de Lambda. La comprobación de mensajes en procesamiento es aproximada y no un bloqueo exclusivo contra otros lectores. No habilitar otro lector durante el muestreo ni garantizar que SQS Standard entregará exactamente dos eventos distintos.

Coste: una recepción SQS más las lecturas de preflight, sin inferencia ni envíos. Reserva orientativa **USD 0,01** para este muestreo acotado; no es un techo de facturación de la cuenta. Las solicitudes SQS dependen también del tamaño recibido; los costes de infraestructura y eventos entrantes existentes continúan por separado. [Tarifas SQS](https://aws.amazon.com/sqs/pricing/).

## Herramienta preparada y privacidad

[inspect-ingress-sample.py](../scripts/cloudformation/inspect-ingress-sample.py) usa Python estándar y AWS CLI. Su invocación predeterminada es solo un plan local sin llamadas AWS:

```sh
python3 scripts/cloudformation/inspect-ingress-sample.py
```

La variante siguiente queda **documentada para ejecutar únicamente después de autorización expresa**, desde una copia privada en CloudShell. Si se descarga solo ese archivo, ajustar únicamente su ruta local:

```sh
python3 scripts/cloudformation/inspect-ingress-sample.py --receive-once
```

No ejecutar directamente `aws sqs receive-message` ni el sondeo de la consola: mostrarían el cuerpo y el receipt handle sin esta redacción. No guardar una respuesta íntegra en archivo para redactarla después ni pegarla en el chat. No usar debug. El script lee únicamente el ajuste no secreto `cli_history` y se detiene si está habilitado o no puede comprobarlo; no modifica la configuración. AWS CLI puede almacenar respuestas completas cuando ese historial está activo. [Protección de datos de AWS CLI](https://docs.aws.amazon.com/cli/latest/userguide/data-protection.html).

Salida permitida:

| Campo | Información |
| --- | --- |
| Origen | Booleanos de coincidencia con el tema, cuenta, WABA y número de demo, cuando están presentes. No identificadores literales. |
| Estructura | Sobre SNS, representación string/objeto de whatsAppWebhookEntry, presencia de contexto AWS y metadatos necesarios. |
| Eventos | Campos/tipos/estados de una lista cerrada y cantidades; lo desconocido se convierte en `other`. |
| Datos textuales | Solo presencia de texto, nunca contenido, longitud, remitente ni nombre. |
| Control SQS | Número de recepciones comunicado y aviso de presupuesto bajo desde 3; nunca MessageId ni ReceiptHandle. |

Se excluyen teléfonos, nombres, correos, IDs de mensajes, contactos literales, texto, respuestas de formularios, URLs/IDs multimedia, timestamps del usuario, errores libres y claves/valores desconocidos. No se descargan medios ni certificados SNS. La muestra no verifica criptográficamente una firma SNS ni acredita autorización de organización/canal/allowlist de MuseRelay.

El esquema esperado es cuerpo SQS → sobre SNS → Message JSON Social → whatsAppWebhookEntry string/objeto → changes. Coincide con el formato oficial y la estructura que contempla nuestro normalizador, que sigue desactivado. [Formato oficial Social](https://docs.aws.amazon.com/social-messaging/latest/userguide/managing-event-destination-dlrs.html).

Doce pruebas locales con datos sintéticos, sin red, verifican redacción de texto/teléfonos/nombres/URLs/IDs/receipt handles, tipos desconocidos, JSON inválido, bloqueo por root/mappings, una sola recepción limitada, ausencia de reintentos ante error/vacío, cliente sin retries, bloqueo de borrado/purga/cambio de visibilidad y detección del historial CLI. No son una prueba de lectura real de estos eventos.

## Criterios después de una ejecución futura autorizada

1. Si el resultado es STOP_DO_NOT_REPEAT, no repetir el muestreo: revisar metadatos y el momento de ejecución; no asumir que el servidor no recibió la solicitud.
2. Si el esquema es desconocido o no coincide el origen/WABA, detenerse. No reenviar ni pedir el cuerpo completo por chat. Preparar otra revisión mínima autorizada si hace falta.
3. Si solo hay cambios administrativos o estados, documentar recepción de notificaciones; no afirmar un mensaje de usuario. Si hay texto entrante, documentar la compatibilidad de estructura, sin activar el agente ni responder.
4. Dejar expirar la visibilidad de la muestra. No borrarla ni restablecer el contador. Comprobar después únicamente atributos de cola, teniendo en cuenta su consistencia eventual.
5. La prueba futura de recepción desde un teléfono de demo necesita autorización y una frase sintética nueva. Tendrá que distinguirse de eventos anteriores mediante evidencia redactada; no se ejecuta en esta fase.

El siguiente despliegue del Bridge sigue pendiente de revisión independiente: identificadores AWS definitivos, binding demo deshabilitado, secreto HMAC, entorno Core de prueba autorizado, ampliación mínima del ejecutor para runtime y recursos/costes. Inicialmente se revisaría DeployProcessing=true con ProcessingEnabled=false y LiveSend=false, sin organizaciones ni destinatarios autorizados. Activar procesamiento y posteriormente envíos son decisiones separadas. No cambiar ahora los canales Meta actuales ni usar el ingreso registrado para activar una organización de producción.
