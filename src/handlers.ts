import type { APIGatewayProxyEventV2, APIGatewayProxyStructuredResultV2, SQSEvent, SQSBatchResponse, Context } from 'aws-lambda';
import { evidence, evidenceHash } from './evidence.js';
import { SecretsManagerClient, GetSecretValueCommand } from '@aws-sdk/client-secrets-manager';
import { SQSClient, SendMessageCommand } from '@aws-sdk/client-sqs';
import { Binding, BridgeError, parseBindings, record, string } from './contracts.js';
import { enqueueRequest, jsonResponse } from './enqueue.js';
import { CoreClient } from './core-client.js';
import { DynamoState } from './state.js';
import { SocialAdapter } from './social.js';
import { ingest, deliver } from './workers.js';

interface Runtime {
  bindings: Binding[];
  secrets: Record<string, { core?: string; outbound?: string }>;
  store: DynamoState;
  core: CoreClient;
  region: string;
}
let cached: { until: number; direction: string; value: Runtime } | undefined;
function env(name: string): string { return string(process.env[name], 20_000); }
async function runtime(direction: 'core' | 'outbound' = 'core'): Promise<Runtime> {
  if (cached && cached.direction === direction && cached.until > Date.now()) return cached.value;
  const region = env('AWS_REGION');
  const bindings = parseBindings(env('BRIDGE_BINDINGS_JSON'));
  if (bindings.some(b => b.region !== region)) throw new BridgeError('binding_region_mismatch');
  const sm = new SecretsManagerClient({ region });
  const result = await sm.send(new GetSecretValueCommand({ SecretId: env('BRIDGE_SECRET_ARN') }));
  const data = record(JSON.parse(result.SecretString ?? '{}'));
  const secrets: Runtime['secrets'] = {};
  for (const b of bindings) {
    const keys = record(data[b.id]);
    const key = string(keys[direction]);
    if (key.length < 32) throw new BridgeError('weak_secret');
    secrets[b.id] = { [direction]: key };
  }
  const store = new DynamoState(env('BRIDGE_STATE_TABLE'), region);
  const core = new CoreClient(env('MUSERELAY_BASE_URL'), id => {
    const key = secrets[id]?.core; if (!key) throw new BridgeError('missing_secret'); return key;
  });
  const value = { bindings, secrets, store, core, region };
  cached = { until: Date.now() + 60_000, direction, value }; return value;
}

export async function inbound(event: SQSEvent, context?: Context): Promise<SQSBatchResponse> {
  if (process.env.BRIDGE_RUNTIME_ENABLED !== 'true' || process.env.BRIDGE_RECEIVE_ENABLED !== 'true') return disabledBatch(event);
  const rt = await runtime();
  return batch(event, async body => ingest(JSON.parse(body), rt.bindings, rt.store, rt.core, Number(env('BRIDGE_DAILY_INBOUND_LIMIT'))), context);
}

export async function sender(event: SQSEvent, context?: Context): Promise<SQSBatchResponse> {
  if (process.env.BRIDGE_RUNTIME_ENABLED !== 'true' || process.env.BRIDGE_SEND_ENABLED !== 'true' || process.env.BRIDGE_LIVE_SEND !== 'true') return disabledBatch(event);
  const rt = await runtime();
  const adapter = SocialAdapter.aws(rt.region, env('META_API_VERSION'), process.env.BRIDGE_LIVE_SEND === 'true');
  return batch(event, async body => deliver(JSON.parse(body), rt.bindings, rt.store, rt.core, adapter, Number(env('BRIDGE_DAILY_SEND_LIMIT'))), context);
}

/** Only POST /outbound and the read-only POST /templates; no registration, permission changes, or management operations. */
export async function enqueue(event: APIGatewayProxyEventV2): Promise<APIGatewayProxyStructuredResultV2> {
  try {
    if (event.requestContext.http.method !== 'POST' || (event.rawPath !== '/outbound' && event.rawPath !== '/templates')) return jsonResponse(404, { error: 'not_found' });
    if (!event.headers['x-bridge-binding'] || !event.headers['x-bridge-signature'] || !event.headers['x-bridge-timestamp'] || !event.headers['x-bridge-nonce']) {
      return jsonResponse(401, { error: 'unauthorized' });
    }
    if (process.env.BRIDGE_RUNTIME_ENABLED !== 'true' || process.env.BRIDGE_SEND_ENABLED !== 'true' || process.env.BRIDGE_LIVE_SEND !== 'true') return jsonResponse(503, { error: 'bridge_runtime_disabled' });
    const rt = await runtime('outbound');
    return enqueueRequest(event, { bindings: rt.bindings, secret: id => rt.secrets[id]?.outbound,
      nonce: key => rt.store.nonce(key), queue: async command => {
        await new SQSClient({ region: rt.region }).send(new SendMessageCommand({ QueueUrl: env('BRIDGE_OUTBOUND_QUEUE_URL'), MessageBody: JSON.stringify(command) }));
      },
      templates: binding => SocialAdapter.aws(rt.region, 'v23.0', false).templates(binding) });
  } catch {
    return jsonResponse(503, { error: 'temporary_failure' });
  }
}

/** Do not acknowledge/delete queued records while disabled, including manual invocation. */
function disabledBatch(event: SQSEvent): SQSBatchResponse {
  return { batchItemFailures: event.Records.map(item => ({ itemIdentifier: item.messageId })) };
}

async function batch(event: SQSEvent, work: (body: string) => Promise<void>, context?: Context): Promise<SQSBatchResponse> {
  const batchItemFailures: { itemIdentifier: string }[] = [];
  for (const item of event.Records) {
    try {
      if (Buffer.byteLength(item.body) > 250_000) throw new BridgeError('payload_too_large');
      await work(item.body);
      evidence('lambda_record_ok', { id: evidenceHash(item.messageId), awsRequestId: context?.awsRequestId });
    } catch (error) {
      console.warn(JSON.stringify({ event: 'bridge_record_failed', code: error instanceof BridgeError ? error.code : 'temporary_failure' }));
      batchItemFailures.push({ itemIdentifier: item.messageId });
    }
  }
  return { batchItemFailures };
}
