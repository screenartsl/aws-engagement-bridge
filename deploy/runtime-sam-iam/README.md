# Propuesta IAM mínima para el transform SAM — NO aplicada

Leer el [informe de diagnóstico, comparación y alternativas](../../docs/history/SAM-TRANSFORM-REVIEW-2026-10-26.md). Una sola declaración cloudformation:CreateChangeSet sobre arn:aws:cloudformation:eu-west-1:aws:transform/Serverless-2016-10-31, añadida al ejecutor existente; no se crea ningún rol ni se amplían privilegios administrativos.

El propietario no autoriza cambios IAM ni nuevos intentos hasta revisar la propuesta. La plantilla, diff y JSON preparados no se ejecutan automáticamente. El baseline existente permanece intacto en deploy/runtime-iam/template.yaml. No aplicar esta opción si se elige la alternativa nativa.
