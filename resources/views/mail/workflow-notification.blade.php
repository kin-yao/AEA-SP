@component('mail::message')
@if (\App\Support\Brand::has())
<img src="{{ $message->embed(\App\Support\Brand::path()) }}" alt="AEA Limited" height="48" style="height: 48px; width: auto">
@endif

# {{ $subjectLine }}

Hi {{ $greetingName }},

@foreach ($lines as $line)
{{ $line }}

@endforeach
@if ($ctaUrl && $ctaLabel)
@component('mail::button', ['url' => $ctaUrl])
{{ $ctaLabel }}
@endcomponent
@endif

Thanks,<br>
{{ config('app.name') }}
@endcomponent
