import { createHash } from 'node:crypto';
export function evidenceHash(value: string): string { return createHash('sha256').update(value).digest('hex'); }
/** Operational metadata only. Never accepts arbitrary body, headers, phone, errors or credentials. */
export function evidence(event: 'core_ack' | 'social_api_result' | 'lambda_record_ok' | 'notify_result', fields: {
  id?: string; requestId?: string; status?: string; awsRequestId?: string; httpStatus?: number;
}): void {
  const runId = process.env.BRIDGE_RUN_ID;
  if (!runId || !/^phase4_[A-Za-z0-9_-]{4,56}$/.test(runId)) return;
  const safe: Record<string, string | number> = { event, runId, time: new Date().toISOString() };
  for (const key of ['id', 'requestId', 'status', 'awsRequestId'] as const) {
    const value = fields[key];
    if (value && /^[a-zA-Z0-9_-]{1,128}$/.test(value)) safe[key] = value;
  }
  if (Number.isInteger(fields.httpStatus) && fields.httpStatus! >= 100 && fields.httpStatus! <= 599) safe.httpStatus = fields.httpStatus!;
  console.info(JSON.stringify(safe));
}
