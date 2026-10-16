import { describe, it, expect } from 'vitest';
import { isDemoHost } from '../src/lib/demo.js';

describe('isDemoHost (REQ-080)', () => {
  it('ist fuer den Demo-Host wahr', () => {
    expect(isDemoHost('demo.quitt.cloud')).toBe(true);
  });
  it('ignoriert Gross/Klein und abschliessenden Punkt', () => {
    expect(isDemoHost('Demo.Quitt.Cloud')).toBe(true);
    expect(isDemoHost('demo.quitt.cloud.')).toBe(true);
  });
  it('ist fuer andere Hosts falsch', () => {
    expect(isDemoHost('quitt.cloud')).toBe(false);
    expect(isDemoHost('kita.example.de')).toBe(false);
    expect(isDemoHost('localhost')).toBe(false);
  });
});
