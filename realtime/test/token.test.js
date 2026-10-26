import { test } from 'node:test';
import assert from 'node:assert/strict';
import { deriveKeys, resolveSecret, signToken, verifyToken } from '../src/token.js';

// Produced by App\Support\Collab\CollabToken::issue() with COLLAB_SECRET=test-collab-secret (exp 4000000000)
const PHP_TOKEN = 'eyJ2IjoxLCJzdWIiOjcsIm5hbWUiOiJBZGEgTG92ZWxhY2UiLCJkb2MiOiJwYWdlLjEyM2U0NTY3LWU4OWItNDJkMy1hNDU2LTQyNjYxNDE3NDAwMC4zIiwiZWRpdCI6dHJ1ZSwiZXhwIjo0MDAwMDAwMDAwfQ.8xu-mBW-BJoKJx8mg1B_2jI2nMkChXIspJmcS3doTRc';
const keys = deriveKeys('test-collab-secret');

test('accepts a token issued by Brainitive (PHP)', () => {
    const payload = verifyToken(PHP_TOKEN, keys.token, 3_999_999_000);
    assert.deepEqual(payload, { v: 1, sub: 7, name: 'Ada Lovelace', doc: 'page.123e4567-e89b-42d3-a456-426614174000.3', edit: true, exp: 4_000_000_000 });
});

test('derives the same keys as CollabConfig (PHP)', () => {
    assert.equal(keys.internal, '55054e9d54e79e502ae935d935c1c47812faf65d13d2359d7a495792e68cd46b');
    // no COLLAB_SECRET: derived from APP_KEY like config/collab.php
    assert.equal(resolveSecret({ APP_KEY: 'base64:dGVzdC1hcHAta2V5' }), '6c748a451fc1d825bcd71ed46486a1b9e9042920a9f3095d7941a56128549757');
    assert.equal(resolveSecret({ COLLAB_SECRET: 'explicit', APP_KEY: 'x' }), 'explicit');
    assert.equal(resolveSecret({}), null);
});

test('refuses expired, forged and malformed tokens', () => {
    assert.equal(verifyToken(PHP_TOKEN, keys.token, 4_000_000_000), null, 'expired');
    assert.equal(verifyToken(PHP_TOKEN, deriveKeys('another-instance').token, 0), null, 'other secret');
    const [payload, signature] = PHP_TOKEN.split('.');
    const forged = Buffer.from(JSON.stringify({ v: 1, sub: 1, name: 'x', doc: 'page.x.0', edit: true, exp: 4_000_000_000 })).toString('base64url');
    assert.equal(verifyToken(`${forged}.${signature}`, keys.token, 0), null, 'payload swapped');
    assert.equal(verifyToken(`${payload}.${signature}x`, keys.token, 0), null);
    assert.equal(verifyToken('abc', keys.token, 0), null);
    assert.equal(verifyToken(undefined, keys.token, 0), null);
    // the internal key is not a token key
    assert.equal(verifyToken(signToken({ v: 1, sub: 1, name: 'x', doc: 'd', edit: true, exp: 10 }, keys.internal), keys.token, 0), null);
});
