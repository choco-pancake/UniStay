import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const landingPage = join(projectRoot, 'index.php');
const configPath = join(projectRoot, 'tailwind.config.cjs');

const workDir = mkdtempSync(join(tmpdir(), 'unistay-landing-tailwind-'));
const inputPath = join(workDir, 'landing.css');
const outputPath = join(workDir, 'landing.min.css');

try {
  const php = readFileSync(landingPage, 'utf8');
  const startMarker = '/* landing-tailwind:start */';
  const endMarker = '/* landing-tailwind:end */';
  const start = php.indexOf(startMarker);
  const end = php.indexOf(endMarker);

  if (start === -1 || end === -1 || end < start) {
    throw new Error('Landing Tailwind markers were not found in index.php.');
  }

  const sourceCss = php.slice(start + startMarker.length, end).trim();
  writeFileSync(inputPath, `${sourceCss}\n`, 'utf8');

  const tailwindCli = requireResolve('tailwindcss/lib/cli.js');
  execFileSync(process.execPath, [
    tailwindCli,
    '--config', configPath,
    '--input', inputPath,
    '--output', outputPath,
    '--minify'
  ], { cwd: projectRoot, stdio: 'inherit' });

  const compiledCss = readFileSync(outputPath, 'utf8').trim();
  const before = php.slice(0, start + startMarker.length);
  const after = php.slice(end);
  writeFileSync(landingPage, `${before}\n${compiledCss}\n${after}`, 'utf8');
} finally {
  rmSync(workDir, { recursive: true, force: true });
}

function requireResolve(specifier) {
  return resolve(projectRoot, 'node_modules', ...specifier.split('/'));
}
