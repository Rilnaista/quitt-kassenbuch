import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createHash } from 'node:crypto';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('.', import.meta.url));
const publicDir = join(root, 'public');

function walk(dir) {
  return readdirSync(dir).flatMap((n) => {
    const p = join(dir, n);
    return statSync(p).isDirectory() ? walk(p) : [p];
  });
}

/**
 * Erzeugt sw.js aus sw/sw-template.js. Cache-Name = Pruefsumme ueber ALLE Build-Dateien
 * (Leitlinie: beim Bauen erzeugt, nie von Hand). Precache-Liste = alle Build-Dateien.
 */
function serviceWorker() {
  return {
    name: 'quitt-sw',
    apply: 'build',
    enforce: 'post',
    generateBundle(_, bundle) {
      const hash = createHash('sha256');
      const files = [];
      for (const name of Object.keys(bundle).sort()) {
        const item = bundle[name];
        if (name.endsWith('.map')) continue;
        files.push(name);
        hash.update(name);
        hash.update(item.type === 'chunk' ? item.code : item.source);
      }
      for (const p of walk(publicDir).sort()) {
        const name = relative(publicDir, p).split('\\').join('/');
        if (name.startsWith('.') || name.includes('/.')) continue;
        files.push(name);
        hash.update(name);
        hash.update(readFileSync(p));
      }
      const build = hash.digest('hex').slice(0, 16);
      // .ttf/.woff sind Fallbacks fuer Alt-Browser (alle Zielgeraete koennen woff2) -> nicht vorab laden
      // ocr/ (~14 MB) wird nicht vorab, sondern beim ersten Gebrauch geladen und dann im Cache gehalten (sw-template)
      const precache = files.filter((f) => f !== 'sw.js' && !/\.(ttf|woff)$/.test(f) && !f.startsWith('ocr/'));
      const src = readFileSync(join(root, 'sw/sw-template.js'), 'utf8')
        .replace('__CACHE_NAME__', 'quitt-' + build)
        .replace('__BUILD_ID__', build)
        .replace('__PRECACHE__', JSON.stringify(precache.sort()));
      this.emitFile({ type: 'asset', fileName: 'sw.js', source: src });
    },
  };
}

export default defineConfig({
  base: './',
  plugins: [vue(), serviceWorker()],
  server: {
    proxy: { '/api': { target: 'http://localhost:8000', changeOrigin: true } },
  },
  build: { chunkSizeWarningLimit: 2500 },
  test: {
    environment: 'node',
    setupFiles: ['./tests/setup.js'],
  },
});
