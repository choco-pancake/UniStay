/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './index.php',
    './auth/*.php',
    './tenant/*.php',
    './assets/js/landing-page.js',
    './assets/js/landlord-apply-fields.js',
    './assets/js/landlord-apply.js',
    './assets/js/login.js',
    './assets/js/register.js',
    './assets/js/tenant/**/*.js'
  ],
  corePlugins: {
    preflight: false
  },
  theme: {
    extend: {}
  },
  plugins: []
};
