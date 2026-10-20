@props(['alt' => 'Hairaa Capital'])

<span {{ $attributes->class(['inline-flex items-center gap-3.5 font-semibold tracking-tight text-navy-900']) }}>
    <img
        src="{{ asset('images/hairaa-capital-logo.png') }}"
        alt="{{ $alt }}"
        class="h-[1.65em] w-auto select-none"
    />
    <span class="leading-none">Hairaa DAS</span>
</span>
