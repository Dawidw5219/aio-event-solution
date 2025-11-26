/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ["./src/**/*.php", "./src/**/*.twig"],
  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: "var(--aio-color-primary, #2271b1)",
          hover: "var(--aio-color-primary-hover, #135e96)",
          light: "var(--aio-color-primary-light, #e5f5fb)",
        },
        secondary: {
          DEFAULT: "var(--aio-color-secondary, #f0f0f1)",
          hover: "var(--aio-color-secondary-hover, #dcdcde)",
          dark: "var(--aio-color-secondary-dark, #50575e)",
        },
      },
    },
  },
  plugins: [],
  // Ważne: nie styluj WP admin poza naszym pluginem
  corePlugins: {
    preflight: false,
  },
};
