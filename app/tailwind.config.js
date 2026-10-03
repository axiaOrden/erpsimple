import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],
    safelist: [
        { pattern: /^(bg|text)-(primary|on-primary|primary-container|on-primary-container|secondary-container|on-secondary-container|tertiary-container|on-tertiary-container|error|error-container|on-error-container|surface|surface-variant|surface-container|surface-container-high|on-surface|on-surface-variant|outline|outline-variant)$/ },
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Manrope', 'Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                primary: '#006a64',
                'on-primary': '#ffffff',
                'primary-container': '#9cf2e8',
                'on-primary-container': '#00201d',
                secondary: '#4a635f',
                'secondary-container': '#cce8e2',
                'on-secondary-container': '#06201c',
                'tertiary-container': '#cde5ff',
                'on-tertiary-container': '#001d34',
                error: '#ba1a1a',
                'error-container': '#ffdad6',
                'on-error-container': '#410002',
                surface: '#eaf5f1',
                'surface-variant': '#d8e7e2',
                'surface-container': '#dfede8',
                'surface-container-high': '#d4e5df',
                'surface-container-highest': '#c8dbd5',
                'surface-container-low': '#f1faf7',
                'on-surface': '#15201e',
                'on-surface-variant': '#3f4946',
                outline: '#6f7976',
                'outline-variant': '#aebfba',
                'inverse-surface': '#203a36',
                'inverse-on-surface': '#e7f5f1',
            },
            boxShadow: {
                'm1': '0 1px 2px rgba(0, 55, 50, .12), 0 4px 12px rgba(0, 55, 50, .06)',
                'm2': '0 4px 10px rgba(0, 55, 50, .12), 0 10px 28px rgba(0, 55, 50, .09)',
                'm3': '0 10px 24px rgba(0, 42, 38, .16), 0 24px 52px rgba(0, 42, 38, .12)',
            },
            borderRadius: {
                'm': '1.25rem',
                'ml': '2rem',
            },
        },
    },

    plugins: [forms],
};
