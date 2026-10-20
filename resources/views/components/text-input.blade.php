@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 focus:border-navy-500 focus:ring-navy-500 rounded-md shadow-sm']) }}>
