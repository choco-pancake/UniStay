import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const configPath = join(projectRoot, 'tailwind.config.cjs');
const blocks = [
  {
    file: 'tenant/dashboard.php',
    marker: 'tenant-tailwind:dashboard'
  },
  {
    file: 'tenant/dorms.php',
    marker: 'tenant-tailwind:dorms'
  },
  {
    file: 'tenant/map.php',
    marker: 'tenant-tailwind:map-base'
  },
  {
    file: 'tenant/map.php',
    marker: 'tenant-tailwind:map-tail'
  },
  {
    file: 'tenant/settings.php',
    marker: 'tenant-tailwind:settings'
  },
  {
    file: 'tenant/notifications.php',
    marker: 'tenant-tailwind:notifications'
  },
  {
    file: 'tenant/rooms.php',
    marker: 'tenant-tailwind:rooms'
  }
];

const workDir = mkdtempSync(join(tmpdir(), 'unistay-tenant-tailwind-'));

try {
  for (const [index, block] of blocks.entries()) {
    const pagePath = join(projectRoot, block.file);
    const php = readFileSync(pagePath, 'utf8');
    const startMarker = `/* ${block.marker}:start */`;
    const endMarker = `/* ${block.marker}:end */`;
    const start = php.indexOf(startMarker);
    const end = php.indexOf(endMarker);

    if (start === -1 || end === -1 || end < start) {
      throw new Error(`Tailwind markers were not found for ${block.marker}.`);
    }

    const inlineCss = php.slice(start + startMarker.length, end).trim();
    if (inlineCss === '') {
      throw new Error(`The inline Tailwind source is empty for ${block.marker}.`);
    }

    const inputPath = join(workDir, `tenant-${index}.css`);
    const outputPath = join(workDir, `tenant-${index}.min.css`);
    writeFileSync(inputPath, `${inlineCss}\n`, 'utf8');

    execFileSync(process.execPath, [
      resolve(projectRoot, 'node_modules', 'tailwindcss', 'lib', 'cli.js'),
      '--config', configPath,
      '--input', inputPath,
      '--output', outputPath,
      '--minify'
    ], { cwd: projectRoot, stdio: 'inherit' });

    const compiledCss = readFileSync(outputPath, 'utf8').trim();
    const before = php.slice(0, start + startMarker.length);
    const after = php.slice(end);
    writeFileSync(pagePath, `${before}\n${compiledCss}\n${after}`, 'utf8');
  }
} finally {
  rmSync(workDir, { recursive: true, force: true });
}
