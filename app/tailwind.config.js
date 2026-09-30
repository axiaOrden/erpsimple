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
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                primary: '#0b57d0',
                'on-primary': '#ffffff',
                'primary-container': '#d3e3fd',
                'on-primary-container': '#041e49',
                secondary: '#575e71',
                'secondary-container': '#dbe2f9',
                'on-secondary-container': '#141b2c',
                'tertiary-container': '#cdebff',
                'on-tertiary-container': '#001f2e',
                error: '#b3261e',
                'error-container': '#f9dedc',
                'on-error-container': '#410e0b',
                surface: '#f9f9ff',
                'surface-variant': '#e0e2ec',
                'surface-container': '#f0f3fa',
                'surface-container-high': '#eaeef6',
                'on-surface': '#191c20',
                'on-surface-variant': '#44474e',
                outline: '#74777f',
                'outline-variant': '#c4c6d0',
            },
            boxShadow: {
                // Material elevation levels (resting states).
                'm1': '0 1px 2px rgba(0,0,0,.3), 0 1px 3px 1px rgba(0,0,0,.15)',
                'm2': '0 1px 2px rgba(0,0,0,.3), 0 2px 6px 2px rgba(0,0,0,.15)',
                'm3': '0 4px 8px 3px rgba(0,0,0,.15), 0 1px 2px rgba(0,0,0,.3)',
            },
            borderRadius: {
                'm': '1rem',
                'ml': '1.75rem',
            },
        },
    },

    plugins: [forms],
};
