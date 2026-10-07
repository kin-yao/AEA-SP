@props(['height' => '2.5rem', 'max' => '12rem'])
@if (\App\Support\Brand::has())
    <img src="{{ \App\Support\Brand::url() }}" alt="AEA Limited" {{ $attributes }} style="height: {{ $height }}; width: auto; max-width: {{ $max }}">
@else
    <span {{ $attributes }} style="font-weight: 800; letter-spacing: .05em">AEA</span>
@endif
