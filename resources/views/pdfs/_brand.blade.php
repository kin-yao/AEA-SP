@php($logo = \App\Support\Brand::dataUri())
@if ($logo)
    <img src="{{ $logo }}" alt="AEA Limited" style="height: {{ $h ?? 34 }}px; margin-bottom: {{ $mb ?? 8 }}px">
@endif
