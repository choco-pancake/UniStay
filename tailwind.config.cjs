/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './index.php',
    './auth/*.php',
    './tenant/*.php',
    './admin/*.php',
    './landlord/*.php',
    './pages/*.php',
    './assets/js/**/*.js'
  ],
  corePlugins: {
    preflight: false
  },
  theme: {
    extend: {}
  },
  plugins: []
};
