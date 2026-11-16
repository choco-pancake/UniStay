import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const configPath = join(projectRoot, 'tailwind.config.cjs');
const tailwindCli = resolve(projectRoot, 'node_modules', 'tailwindcss', 'lib', 'cli.js');

const TARGETS = {
  landing: [
    { file: 'index.php', marker: 'landing-tailwind' }
  ],
  auth: [
    { file: 'auth/login.php', marker: 'auth-tailwind:login' },
    { file: 'auth/forgot-password.php', marker: 'auth-tailwind:forgot-password' },
    { file: 'auth/reset-password.php', marker: 'auth-tailwind:reset-password' },
    { file: 'auth/register.php', marker: 'auth-tailwind:register' },
    { file: 'auth/set-google-password.php', marker: 'auth-tailwind:set-google-password' },
    { file: 'auth/landlord-register.php', marker: 'auth-tailwind:landlord-register' },
    { file: 'auth/google_oauth.php', marker: 'auth-tailwind:google-oauth' }
  ],
  tenant: [
    { file: 'tenant/dashboard.php', marker: 'tenant-tailwind:dashboard' },
    { file: 'tenant/dorms.php', marker: 'tenant-tailwind:dorms' },
    { file: 'tenant/map.php', marker: 'tenant-tailwind:map-base' },
    { file: 'tenant/map.php', marker: 'tenant-tailwind:map-tail' },
    { file: 'tenant/settings.php', marker: 'tenant-tailwind:settings' },
    { file: 'tenant/notifications.php', marker: 'tenant-tailwind:notifications' },
    { file: 'tenant/rooms.php', marker: 'tenant-tailwind:rooms' }
  ],
  admin: [
    { file: 'admin/dashboard.php', marker: 'admin-tailwind:dashboard' },
    { file: 'admin/verification.php', marker: 'admin-tailwind:verification' },
    { file: 'admin/users.php', marker: 'admin-tailwind:users' },
    { file: 'admin/settings.php', marker: 'admin-tailwind:settings' },
    { file: 'admin/notifications.php', marker: 'admin-tailwind:notifications' },
    { file: 'admin/subscribers.php', marker: 'admin-tailwind:subscribers' },
    { file: 'admin/application-review.php', marker: 'admin-tailwind:application-review' }
  ],
  landlord: [
    { file: 'landlord/dashboard.php', marker: 'landlord-tailwind:dashboard' },
    { file: 'landlord/dorms.php', marker: 'landlord-tailwind:dorms' },
    { file: 'landlord/requests.php', marker: 'landlord-tailwind:requests' },
    { file: 'landlord/settings.php', marker: 'landlord-tailwind:settings' },
    { file: 'landlord/notifications.php', marker: 'landlord-tailwind:notifications' }
  ],
  pages: [
    { file: 'pages/inc.php', marker: 'pages-tailwind:base' }
  ]
};

const requested = (process.argv[2] || 'all').toLowerCase();
const selectedKeys = requested === 'all'
  ? Object.keys(TARGETS)
  : (TARGETS[requested] ? [requested] : null);

if (!selectedKeys) {
  console.error(`Unknown target: "${requested}". Available targets: all, ${Object.keys(TARGETS).join(', ')}`);
  process.exit(1);
}

const workDir = mkdtempSync(join(tmpdir(), 'unistay-tailwind-'));

try {
  let fileIndex = 0;
  for (const key of selectedKeys) {
    const blocks = TARGETS[key];
    console.log(`\nCompiling Tailwind for [${key}] (${blocks.length} block${blocks.length > 1 ? 's' : ''})...`);

    for (const block of blocks) {
      const pagePath = join(projectRoot, block.file);
      const php = readFileSync(pagePath, 'utf8');
      const startMarker = `/* ${block.marker}:start */`;
      const endMarker = `/* ${block.marker}:end */`;
      const start = php.indexOf(startMarker);
      const end = php.indexOf(endMarker);

      if (start === -1 || end === -1 || end < start) {
        throw new Error(`Tailwind markers were not found for ${block.marker} in ${block.file}.`);
      }

      const inlineCss = php.slice(start + startMarker.length, end).trim();
      if (inlineCss === '') {
        throw new Error(`The inline Tailwind source is empty for ${block.marker} in ${block.file}.`);
      }

      const inputPath = join(workDir, `input-${fileIndex}.css`);
      const outputPath = join(workDir, `output-${fileIndex}.min.css`);
      fileIndex++;

      writeFileSync(inputPath, `${inlineCss}\n`, 'utf8');

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
      writeFileSync(pagePath, `${before}\n${compiledCss}\n${after}`, 'utf8');
    }
  }
  console.log(`\nSuccessfully compiled all requested Tailwind blocks.`);
} finally {
  rmSync(workDir, { recursive: true, force: true });
}
