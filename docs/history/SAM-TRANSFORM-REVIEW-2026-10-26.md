# Revisión del fallo SAM — 26/10/2026, Europe/Paris

**Propuestas preparadas para revisión. No se ha modificado IAM, reintentado ningún conjunto, desplegado recursos, leído SQS ni cambiado MuseRelay.** El propietario no autoriza cambios IAM ni nuevos intentos hasta revisar esta propuesta. El conjunto fallido debe conservarse como evidencia; no se elimina automáticamente.

## Diagnóstico y responsabilidad de la preparación

El propietario comunica FAILED / UNAVAILABLE para `receive-runtime-review-88161733676d-82707086`, pila `muserelay-aws-bridge-stage`, cuenta `365470560685`, región `eu-west-1`. El error atribuye al rol `muserelay-aws-bridge-stage-cfn-execution` una autorización CloudFormation ausente sobre la transformación Serverless.

La política preparada anteriormente omitía el permiso específico que AWS documenta para SAM. La revisión local confirma que no hay ninguna acción CloudFormation Allow en ese baseline del ejecutor. Es una omisión de la preparación IAM; no se soluciona dando AdministratorAccess al ejecutor.

La copia facilitada del error abrevia el nombre de acción y el ARN de la transformación; no la tratamos como una transcripción completa de CloudTrail. La acción y el ARN de la corrección se toman del requisito oficial de AWS, no de reconstruir esa copia. El diagnóstico encaja con el error comunicado y el permiso ausente; no certifica las restantes políticas efectivas de la cuenta.

`CAPABILITY_AUTO_EXPAND` no concede permisos IAM. La referencia CreateChangeSet indica además que esa capability no tiene efecto al crear conjuntos de cambios. Las solicitudes nuevas la omiten; no se usa como sustituto del permiso del rol.

## Opción recomendada: conservar SAM y corregir un permiso

Para mantener el runtime ya revisado, la corrección mínima consiste en **una declaración añadida** a `bridge-runtime-receive-provisioning` del ejecutor existente:

```json
{
  "Sid": "AllowAwsSamTransformInIreland",
  "Effect": "Allow",
  "Action": ["cloudformation:CreateChangeSet"],
  "Resource": "arn:aws:cloudformation:eu-west-1:aws:transform/Serverless-2016-10-31"
}
```

El segmento de cuenta **aws** es intencionado: identifica la transformación gestionada por AWS. No sustituirlo por 365470560685. No hay wildcard de acción, recurso o región en la declaración nueva. No se añaden ExecuteChangeSet, UpdateStack, DeleteStack, permisos IAM, Bedrock, WhatsApp ni acceso a valores de secretos.

Archivos completos:

- [Plantilla IAM corregida propuesta](../../deploy/runtime-sam-iam/template.yaml).
- [Declaración nueva como política JSON](../../deploy/runtime-sam-iam/policy.addition.json).
- [Policies antes](../../deploy/runtime-sam-iam/executor-policies.before.json), [después](../../deploy/runtime-sam-iam/executor-policies.after.json) y [diff íntegro](../../deploy/runtime-sam-iam/executor-policies.diff).
- [Solicitud IAM preparada, no creada en AWS](../../deploy/runtime-sam-iam/change-set.update.prepared.json).
- [Solicitud de reintento SAM preparada, todavía no autorizada](../../deploy/runtime-sam-iam/change-set.runtime-retry.prepared.json).
- [Comparación estructurada de ambas opciones](../../deploy/runtime-sam-iam/comparison.prepared.json).

| Elemento | Antes | Propuesta |
| --- | --- | --- |
| `bridge-stage-scoped` | Política existente, con denegaciones KMS | Idéntica |
| `bridge-runtime-receive-provisioning` | Nueve declaraciones | Las mismas nueve + una sobre el ARN SAM exacto |
| Confianza, nombre, duración y Retain del ejecutor | Existentes | Idénticos |
| KmsRecoveryRole completo | Existente | Idéntico |
| Tres roles de runtime completos | Existentes | Idénticos |
| GrantSocialSend | false según comprobación previa | false |
| Recursos IAM | Cinco roles | Los mismos cinco, sin Add/Remove |
| Cambio esperado en IAM Foundation | — | Un Modify de StageCloudFormationRole, Replacement=False |

SHA256 de la plantilla IAM propuesta:

```text
d60476a283b76d3dcc2babdd689bee81b1888e834364c90067621cc7ef4531cc
```

Baseline comparado: `deploy/runtime-iam/template.yaml`, SHA256 `b2a87ec5d7d205b69250596103427fb55b8896b7f5997507b28b489b18feac3a`, cuya integridad y despliegue fueron confirmados previamente por el propietario. La comparación reconstruye el baseline eliminando únicamente la declaración añadida y exige igualdad del documento completo. No se usa una firma truncada de la consola como evidencia de igualdad.

El YAML del runtime SAM conserva SHA256 `88161733676dae8d788eff092cf75c6430c5ca28860e7f7048804e5ce3f694ac`. La corrección IAM no cambia funciones, colas, bucket, clave KMS, código ni parámetros del runtime.

## Alternativa: CloudFormation nativo, sin ampliar IAM por SAM

Se ha preparado una expansión **local y reproducible** con `aws-sam-translator==1.115.0`, sin red ni acceso AWS:

- [Plantilla nativa](../../deploy/runtime-native/template.yaml).
- [Parámetros cerrados](../../deploy/runtime-native/parameters.disabled.json).
- [Solicitud UPDATE nativa preparada, no creada en AWS](../../deploy/runtime-native/change-set.update.prepared.json).

SHA256 del YAML nativo:

```text
975cf0b1cef670c8eae00f284234ce56f9a904e6756bf3094b13fcb2688a42ad
```

No contiene Transform, Globals, AWS::Serverless::* ni recursos IAM. Utiliza el mismo rol CloudFormation y el mismo ZIP de S3, ya subido, sin otra escritura ni cambio de código. Con los parámetros actuales selecciona 17 recursos: los doce originales idénticos y cinco Add de recepción. Conserva los IDs lógicos del resultado SAM y todas sus propiedades. Los recursos outbound permanecen definidos condicionalmente, pero no se seleccionan; no es un despliegue de salida.

Los 17 recursos de recepción no reservan concurrencia de función, mantienen MaximumConcurrency=2 en su mapping SQS y resuelven Enabled=false. El secreto queda sin valor y los controles de procesamiento, recepción, agente y salida no se activan. No hay consumidores nuevos por preparar el archivo.

Ventaja: evita por completo este requisito de autorización de transformación, por lo que no exige la modificación IAM anterior. Requiere revisar y fijar otro YAML de despliegue, y mantener sincronizado el resultado generado cuando cambie el SAM fuente. La expansión local no sustituye la aceptación del servicio CloudFormation ni prueba los demás permisos de aprovisionamiento, boundaries/SCP o políticas de recursos.

La plantilla nativa conserva los defaults y las ramas condicionales del fuente. cfn-lint no comunica errores, pero sí avisos W1030 (default vacío de RuntimeArtifactKey en las funciones condicionales) y W1028 (ramas redundantes del OpenAPI outbound generado). La propuesta concreta proporciona la clave S3 no vacía, una Rule prohíbe vacío al seleccionar runtime y las pruebas verifican esa prohibición; el OpenAPI outbound no se selecciona. Se conservan los avisos en el informe de validación: no se presenta el lint nativo como libre de warnings ni se los oculta modificando la semántica.

## Comparación y recomendación

| Opción | IAM adicional | YAML del runtime | Secuencia después de autorización |
| --- | --- | --- | --- |
| SAM + corrección mínima | Una acción sobre un ARN regional de AWS | Mismo 881617… ya revisado | Revisar/aplicar UPDATE IAM, verificarlo y solo después autorizar un nuevo intento de conjunto runtime |
| Nativo | Ninguno por transformación | Nuevo YAML 975cf0… | Revisar el YAML y autorizar por separado un nuevo intento nativo, sin tocar IAM |

**Recomendación técnica para la corrección mínima: conservar SAM con la declaración exacta documentada por AWS.** Cambia menos el runtime ya revisado y evita mantener ahora otro artefacto de despliegue como fuente operativa. Nativo es una alternativa viable si se prefiere no conceder ese permiso adicional y se acepta revisar el nuevo YAML. No aplicar ambas rutas automáticamente ni confundir elegir una opción con autorizar su ejecución.

## Procedimiento de revisión, todavía sin nuevos intentos

1. Revisar este informe y escoger una ruta. No reintentar el conjunto fallido, crear otro ni editar políticas desde IAM todavía.
2. Si se escoge SAM, revisar la plantilla IAM d60476… y el diff. El conjunto IAM propuesto se llamaría `sam-transform-iam-review-d60476a283b7`, UPDATE de la pila existente `muserelay-aws-bridge-iam-foundation`, GrantSocialSend=false y CAPABILITY_NAMED_IAM. Se prepara desde SSO AdministratorAccess, sin seleccionar el ejecutor limitado como service role de esa pila IAM. Mantener el rol de servicio actual de IAM Foundation (previamente confirmado ausente); cualquier cambio observado exige revisión.
3. Solo tras autorizar preparar ese conjunto IAM, exigir un único Modify del ejecutor sin sustitución, sin cambios de confianza/Retain ni de los otros cuatro roles. Si Policies se trunca, obtener Original completo y comparar con la propuesta. La ejecución de ese UPDATE es otra autorización explícita.
4. Después de un UPDATE IAM autorizado y comprobado en UPDATE_COMPLETE, leer la política efectiva del ejecutor para confirmar el Sid, acción y ARN exactos, sin cambios colaterales. Revisar también las lecturas pendientes de políticas adicionales/boundaries. No asumir que AdministratorAccess del operador elimina las restricciones del rol de servicio.
5. Solo después se autoriza un nuevo intento SAM con nombre distinto: `receive-sam-review-88161733676d-82707086-fix1`. No borrar el conjunto FAILED para reutilizar su nombre. Esperar cinco Add, ningún cambio del ingreso y todos los controles off. No ejecutar el runtime en este paso.
6. Si se escoge nativo, no preparar/aplicar la modificación IAM. Revisar el YAML 975cf0…, sus parámetros y avisos. El nombre propuesto es `receive-native-review-975cf0b1cef6-82707086`, UPDATE de `muserelay-aws-bridge-stage`, rol `muserelay-aws-bridge-stage-cfn-execution`, sin Capabilities ni transform. Su creación requiere autorización posterior a esta propuesta; exigir igualmente cinco Add y ninguna modificación/sustitución/eliminación del ingreso.
7. La ejecución de cualquier conjunto runtime, inicialización de secretos, consumidores, mensajes e instalación/activación en MuseRelay permanecen sin autorizar. El endpoint técnico/Core y el canal de la organización demo siguen pendientes de sus respectivas revisiones.

## Pruebas, costes y reversión

Se han ejecutado **15 pruebas locales de seguridad**, todas correctas, que comprueban: diferencia IAM exacta de una declaración; igualdad del resto del documento; conservación de denegaciones KMS; roles y confianzas intactos; default GrantSocialSend=false; equivalencia de recursos del nativo con la expansión SAM; ausencia de IAM/macros en nativo; reuse del ZIP; rechazo de clave S3 vacía con runtime seleccionado; consumidores y envíos cerrados. El lint IAM no presenta errores ni avisos. El [informe de validación offline](../../deploy/runtime-sam-iam/validation.offline.json) conserva los avisos del nativo. También se guarda el [fallo comunicado por el propietario](../../deploy/runtime-sam-iam/failure.owner-reported.json) sin reconstruir sus identificadores incompletos.

No se ha simulado la nueva política en AWS ni creado un conjunto para validarla. Las comprobaciones locales no garantizan que no aparezca otro permiso de servicio insuficiente durante un futuro aprovisionamiento. Ante un nuevo error, parar y revisar la acción concreta; no ampliar a cloudformation:* ni Resource=*.

Preparar/revisar los archivos no crea consumo AWS. Añadir la declaración IAM no tiene coste recurrente por sí misma; el runtime propuesto y su estimación de costes no cambian. No se sube otro ZIP. Para revertir una corrección IAM aplicada más adelante, primero conservar/pausar el flujo y revisar si futuras operaciones SAM siguen necesitando ese permiso; retirarlo mediante UPDATE revisado, manteniendo los roles y las políticas previas. No borrar pilas, colas, claves, usuarios ni restaurar bases para deshacer esta declaración.

## Reproducción offline

```sh
AWS_DEFAULT_REGION=eu-west-1 AWS_EC2_METADATA_DISABLED=true PYTHONPATH=/tmp/muserelay-bridge-samtranslator-phase3 unshare --user --map-root-user --net python3 scripts/cloudformation/prepare-transform-fix-review.py --check
python3 -m unittest discover -s test/phase4 -p 'test_*security.py'
```

No ejecutan AWS ni el Core de producción. Los JSON con nombres de conjuntos son solicitudes preparadas, no conjuntos existentes.

Fuentes oficiales: [permiso específico para AWS::Serverless, ejemplo 2 de Policy resources](https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/control-access-with-iam.html), [CreateChangeSet y efecto de CAPABILITY_AUTO_EXPAND](https://docs.aws.amazon.com/AWSCloudFormation/latest/APIReference/API_CreateChangeSet.html), [transformación AWS::Serverless](https://docs.aws.amazon.com/AWSCloudFormation/latest/TemplateReference/transform-aws-serverless.html).
