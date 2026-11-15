import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const configPath = join(projectRoot, 'tailwind.config.cjs');
const pages = [
  {
    file: 'auth/login.php',
    marker: 'auth-tailwind:login'
  },
  {
    file: 'auth/forgot-password.php',
    marker: 'auth-tailwind:forgot-password'
  },
  {
    file: 'auth/reset-password.php',
    marker: 'auth-tailwind:reset-password'
  },
  {
    file: 'auth/register.php',
    marker: 'auth-tailwind:register'
  },
  {
    file: 'auth/set-google-password.php',
    marker: 'auth-tailwind:set-google-password'
  },
  {
    file: 'auth/landlord-register.php',
    marker: 'auth-tailwind:landlord-register'
  },
  {
    file: 'auth/google_oauth.php',
    marker: 'auth-tailwind:google-oauth'
  }
];

const workDir = mkdtempSync(join(tmpdir(), 'unistay-auth-tailwind-'));

try {
  for (const [index, page] of pages.entries()) {
    const pagePath = join(projectRoot, page.file);
    const php = readFileSync(pagePath, 'utf8');
    const startMarker = `/* ${page.marker}:start */`;
    const endMarker = `/* ${page.marker}:end */`;
    const start = php.indexOf(startMarker);
    const end = php.indexOf(endMarker);

    if (start === -1 || end === -1 || end < start) {
      throw new Error(`Tailwind markers were not found in ${page.file}.`);
    }

    const inlineCss = php.slice(start + startMarker.length, end).trim();
    if (inlineCss === '') {
      throw new Error(`The inline Tailwind source is empty in ${page.file}.`);
    }

    const inputPath = join(workDir, `auth-${index}.css`);
    const outputPath = join(workDir, `auth-${index}.min.css`);
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
