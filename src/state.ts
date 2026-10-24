import { randomUUID } from 'node:crypto';
import { DynamoDBClient } from '@aws-sdk/client-dynamodb';
import { DynamoDBDocumentClient, GetCommand, PutCommand, DeleteCommand, UpdateCommand } from '@aws-sdk/lib-dynamodb';
import { BridgeError, SendResult } from './contracts.js';

export type Claim = { state: 'acquired'; token: string } | { state: 'busy' } | { state: 'done'; result?: SendResult };
export interface StateStore {
  claim(key: string, digest: string, outbound: boolean): Promise<Claim>;
  complete(key: string, token: string, result?: SendResult): Promise<void>;
  release(key: string, token: string): Promise<void>;
  nonce(key: string): Promise<boolean>;
  spend(bindingId: string, dailyLimit: number): Promise<boolean>;
}

/** Only hashes, identifiers and receipts are stored here; no customer message bodies. */
export class DynamoState implements StateStore {
  private readonly db: DynamoDBDocumentClient;
  constructor(private readonly table: string, region: string, private readonly clock = () => Date.now(), documentClient?: DynamoDBDocumentClient) {
    this.db = documentClient ?? DynamoDBDocumentClient.from(new DynamoDBClient({ region }));
  }
  private now(): number { return Math.floor(this.clock() / 1000); }

  async claim(key: string, digest: string, isOutbound: boolean): Promise<Claim> {
    const token = randomUUID();
    const now = this.now();
    try {
      await this.db.send(new PutCommand({ TableName: this.table, Item: {
        pk: key, digest, token, state: 'working', leaseUntil: now + 180, expiresAt: now + 30 * 86400,
      }, ConditionExpression: 'attribute_not_exists(pk)' }));
      return { state: 'acquired', token };
    } catch (error) { if (!conditional(error)) throw error; }
    const result = await this.db.send(new GetCommand({ TableName: this.table, Key: { pk: key }, ConsistentRead: true }));
    const item = result.Item;
    if (!item) return { state: 'busy' };
    if (item.digest !== digest) throw new BridgeError('idempotency_conflict');
    if (item.state === 'done') return { state: 'done', result: item.result as SendResult | undefined };
    if (Number(item.leaseUntil) > now) return { state: 'busy' };
    if (isOutbound) {
      // The previous worker may have died after AWS accepted the send. Never resend.
      const receipt: SendResult = { state: 'uncertain', errorCode: 'worker_interrupted' };
      try {
        await this.db.send(new UpdateCommand({ TableName: this.table, Key: { pk: key },
          UpdateExpression: 'SET #s = :done, #r = :result',
          ConditionExpression: '#s = :working AND #t = :old',
          ExpressionAttributeNames: { '#s': 'state', '#r': 'result', '#t': 'token' },
          ExpressionAttributeValues: { ':done': 'done', ':result': receipt, ':working': 'working', ':old': item.token },
        }));
        return { state: 'done', result: receipt };
      } catch (error) { if (!conditional(error)) throw error; return { state: 'busy' }; }
    }
    try {
      await this.db.send(new UpdateCommand({ TableName: this.table, Key: { pk: key },
        UpdateExpression: 'SET #t = :new, leaseUntil = :until',
        ConditionExpression: '#s = :working AND #t = :old',
        ExpressionAttributeNames: { '#s': 'state', '#t': 'token' },
        ExpressionAttributeValues: { ':new': token, ':until': now + 180, ':working': 'working', ':old': item.token },
      }));
      return { state: 'acquired', token };
    } catch (error) { if (!conditional(error)) throw error; return { state: 'busy' }; }
  }

  async complete(key: string, token: string, result?: SendResult): Promise<void> {
    await this.db.send(new UpdateCommand({ TableName: this.table, Key: { pk: key },
      UpdateExpression: 'SET #s = :done, #r = :result', ConditionExpression: '#t = :token AND #s = :working',
      ExpressionAttributeNames: { '#s': 'state', '#r': 'result', '#t': 'token' },
      ExpressionAttributeValues: { ':done': 'done', ':result': result ?? null, ':token': token, ':working': 'working' },
    }));
  }
  async release(key: string, token: string): Promise<void> {
    await this.db.send(new DeleteCommand({ TableName: this.table, Key: { pk: key },
      ConditionExpression: '#t = :token AND #s = :working', ExpressionAttributeNames: { '#t': 'token', '#s': 'state' },
      ExpressionAttributeValues: { ':token': token, ':working': 'working' } }));
  }
  async nonce(key: string): Promise<boolean> {
    try {
      await this.db.send(new PutCommand({ TableName: this.table, Item: { pk: `nonce:${key}`, expiresAt: this.now() + 600 },
        ConditionExpression: 'attribute_not_exists(pk)' }));
      return true;
    } catch (error) { if (!conditional(error)) throw error; return false; }
  }
  async spend(bindingId: string, dailyLimit: number): Promise<boolean> {
    const day = new Date(this.clock()).toISOString().slice(0, 10);
    try {
      await this.db.send(new UpdateCommand({ TableName: this.table, Key: { pk: `budget:${bindingId}:${day}` },
        UpdateExpression: 'SET expiresAt = :ttl ADD used :one', ConditionExpression: 'attribute_not_exists(used) OR used < :limit',
        ExpressionAttributeValues: { ':ttl': this.now() + 2 * 86400, ':one': 1, ':limit': dailyLimit } }));
      return true;
    } catch (error) { if (!conditional(error)) throw error; return false; }
  }
}
function conditional(error: unknown): boolean { return error instanceof Error && error.name === 'ConditionalCheckFailedException'; }
