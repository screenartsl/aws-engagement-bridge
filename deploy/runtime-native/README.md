# Alternativa CloudFormation nativa — revisión, NO desplegada

Resultado local fijado de aws-sam-translator 1.115.0 a partir del SAM 881617…, sin Transform ni recursos IAM. Reutiliza exactamente el ZIP de S3 ya subido y los parámetros revisados. Los 17 recursos seleccionados, incluidos los doce de ingreso, son iguales a los de la expansión SAM.

Leer el [informe completo](../../docs/history/SAM-TRANSFORM-REVIEW-2026-10-26.md), incluidos los avisos de lint y los límites de la validación offline. No hay autorización para crear un nuevo conjunto ni desplegar recursos. No aplicar la corrección IAM de la otra opción si se decide utilizar esta plantilla nativa.
