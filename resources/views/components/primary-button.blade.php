<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-navy-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-navy-900 focus:bg-navy-900 active:bg-navy-900 focus:outline-none focus:ring-2 focus:ring-navy-500 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
