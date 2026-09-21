import { build } from 'esbuild';
import { mkdir, writeFile } from 'node:fs/promises';
await mkdir('../runtime', { recursive: true });
const result = await build({
  entryPoints: ['dist/main.js'], outfile: '../runtime/gateway.cjs', bundle: true,
  platform: 'node', target: 'node24', format: 'cjs', sourcemap: false,
  legalComments: 'inline', metafile: true,
  // Optional Nest integrations and ws accelerators, never required at runtime.
  external: ['@nestjs/microservices', '@nestjs/microservices/*', '@nestjs/websockets', '@nestjs/websockets/*', 'class-transformer', 'class-validator', 'bufferutil', 'utf-8-validate'],
});
await writeFile('dist/build-meta.json', JSON.stringify(result.metafile, null, 2));
const probe = await build({
  entryPoints: ['probe.mjs'], outfile: '../runtime/probe.cjs', bundle: true,
  platform: 'node', target: 'node24', format: 'cjs', sourcemap: false,
  legalComments: 'inline', metafile: true, external: ['bufferutil', 'utf-8-validate'],
});
await writeFile('dist/probe-build-meta.json', JSON.stringify(probe.metafile, null, 2));
