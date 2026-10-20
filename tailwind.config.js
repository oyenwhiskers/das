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
                // Hairaa Capital maroon — token name kept so existing navy-* classes pick it up
                navy: {
                    50: '#faf4f4',
                    100: '#f0e3e3',
                    500: '#8b3d3d',
                    700: '#5c1a1a',
                    800: '#4a1010',
                    900: '#2f0a0a',
                },
            },
        },
    },

    plugins: [forms],
};
