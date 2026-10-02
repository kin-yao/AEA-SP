@component('mail::message')
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
