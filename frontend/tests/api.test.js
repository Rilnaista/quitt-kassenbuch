import { describe, it, expect, beforeEach } from 'vitest';
import * as api from '../src/api/index.js';
import { mockFetch, apiError, json } from './helpers.js';

beforeEach(() => {
  localStorage.clear();
  api.setApiBase('http://quitt.test/unterordner/api/v1/');
});

describe('API-Layer', () => {
  it('Fehlerformat -> ApiError mit code, message, fields', async () => {
    mockFetch({ 'POST /auth/login': () => apiError(429, 'rate_limited', { retry_after: 60 }) });
    const err = await api.login('1234').catch((e) => e);
    expect(err).toBeInstanceOf(api.ApiError);
    expect(err).toMatchObject({ status: 429, code: 'rate_limited', retryAfter: 60 });
  });
  it('401 mit Token verwirft das Token und meldet das', async () => {
    api.setToken('alt');
    let seen = null;
    const off = api.onAuthProblem((e) => { seen = e.code; });
    mockFetch({ 'GET /settings': () => apiError(401, 'unauthorized') });
    await api.getSettings().catch(() => {});
    off();
    expect(api.getToken()).toBeNull();
    expect(seen).toBe('unauthorized');
  });
  it('falsche alte PIN (401) beim PIN-Aendern behaelt das Token', async () => {
    api.setToken('gut');
    mockFetch({ 'POST /auth/pin': () => apiError(401, 'unauthorized') });
    await api.changePin('0000', '1234').catch(() => {});
    expect(api.getToken()).toBe('gut');
  });
  it('Netzfehler -> code "network"', async () => {
    globalThis.fetch = async () => { throw new TypeError('Failed to fetch'); };
    const err = await api.health().catch((e) => e);
    expect(err.code).toBe('network');
  });
  it('Download-Ticket-URL relativ zum Installationspfad', async () => {
    api.setToken('t');
    const calls = mockFetch({ 'POST /downloads': () => json({ url: 'api/v1/statement.pdf?from=2026-01-01&to=2026-12-31&ticket=abc' }) });
    const url = await api.statementUrl('2026-01-01', '2026-12-31');
    expect(calls[0].body).toEqual({ target: 'statement', params: { from: '2026-01-01', to: '2026-12-31' } });
    expect(url).toBe('http://quitt.test/unterordner/api/v1/statement.pdf?from=2026-01-01&to=2026-12-31&ticket=abc');
  });
  it('PIN-Reset: ohne Code (reset-pin-Datei) bzw. mit Code', async () => {
    const calls = mockFetch({ 'POST /auth/reset': () => ({ recovery_code: 'NEU', token: 't', expires_at: 'x' }) });
    await api.resetPin({ newPin: '1234' });
    await api.resetPin({ recoveryCode: 'ABC', newPin: '1234' });
    expect(calls[0].body).toEqual({ new_pin: '1234' });
    expect(calls[1].body).toEqual({ recovery_code: 'ABC', new_pin: '1234' });
    expect(calls[0].headers.Authorization).toBeUndefined();
  });
});
