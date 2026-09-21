import { readdir, readFile, writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const chunks = ['Third-party licenses included in the bundled Socket Bridge gateway.\n'];
async function scan(directory) {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    if (!entry.isDirectory() || entry.name.startsWith('.')) continue;
    const folder = join(directory, entry.name);
    if (entry.name.startsWith('@')) { await scan(folder); continue; }
    try {
      const pkg = JSON.parse(await readFile(join(folder, 'package.json'), 'utf8'));
      const files = await readdir(folder);
      for (const file of files.filter(name => /^(licen[sc]e|copying|notice)(\.|$)/i.test(name))) {
        const content = await readFile(join(folder, file), 'utf8').catch(() => '');
        if (content) chunks.push(`\n${'='.repeat(72)}\n${pkg.name} ${pkg.version} — ${file}\n${content}`);
      }
      if (files.includes('node_modules')) await scan(join(folder, 'node_modules'));
    } catch { /* Not a package directory. */ }
  }
}
await scan(join(root, 'gateway/node_modules'));
await writeFile(join(root, 'runtime/THIRD_PARTY_LICENSES.txt'), chunks.join('\n'));
