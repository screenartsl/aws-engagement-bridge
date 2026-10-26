# Retención permanente: propuesta de propiedades, NO desplegable

Leer [qué es el runtime y cómo se mantiene permanente](../../docs/history/RUNTIME-PERMANENCE-AND-EXPOSURE.md).

- `lifecycle.before.json`: propiedad LifecycleConfiguration del bucket en el SAM vigente, con expiración global a 30 días.
- `lifecycle.proposed.json`: propuesta de propiedad CloudFormation; expira solo `tmp/` y limpia multipart incompletos sin eliminar releases completos.
- `lifecycle.diff`: comparación de esa propiedad.

**No cargar estos archivos como plantilla CloudFormation ni usarlos con put-bucket-lifecycle-configuration.** Son una propuesta para revisar antes de integrar en la pila completa elegida. Los nombres de propiedades son de CloudFormation, no necesariamente los de la API S3.

No se cambian los hashes de las plantillas actuales ni se reintenta el despliegue bloqueado por SAM. No hay autorizaciones para modificar retención, IAM o recursos. El ajuste futuro debe conservar los doce recursos de ingreso y cualquier runtime ya instalado: únicamente actualizar LifecycleConfiguration del bucket, sin recrearlo, relajar privacidad ni resubir el ZIP. La plantilla definitiva requerirá otro hash y revisión del cambio esperado del bucket.
