import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './app/Livewire/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"Source Sans 3"', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                navy: {
                    50: '#f3f6f9',
                    100: '#e4ebf2',
                    500: '#3d5a73',
                    700: '#1f3a52',
                    800: '#163044',
                    900: '#0f2435',
                },
            },
        },
    },

    plugins: [forms],
};
