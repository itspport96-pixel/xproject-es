/**
 * Config de Tailwind (build estatico).
 *
 * Replica exactamente el `tailwind.config` que antes iba inline en
 * public/index.php sobre el CDN (v3.4.17). El CDN ya NO se usa en runtime:
 * se compila a public/css/tailwind.css (commiteado; la VM de produccion no
 * tiene Node).
 *
 * darkMode 'class': el tema oscuro se activa con <html class="dark">, que
 * fija el bootstrap de tema (inline, con nonce) antes del primer pintado.
 */
module.exports = {
  content: ['./public/index.php', './public/js/**/*.js'],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        // Coherente con los tokens de themes.css: el acento claro es
        // azul-600 (AA como texto y como fondo con texto blanco).
        primary: {
          DEFAULT: 'rgb(var(--color-primary) / <alpha-value>)',
          dark: 'rgb(var(--color-primary) / <alpha-value>)'
        }
      },
      // Reconciliacion con los tokens de themes.css (D8): si no, .rounded /
      // .shadow los definiria la escala estatica de Tailwind y el resultado
      // dependeria del orden de carga.
      borderRadius: {
        sm: '0.375rem',
        DEFAULT: '0.625rem'
      },
      boxShadow: {
        sm: 'var(--shadow-sm)',
        DEFAULT: 'var(--shadow-md)',
        lg: 'var(--shadow-lg)'
      }
    }
  },
  plugins: []
};
