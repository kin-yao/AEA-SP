@component('mail::message')
# {{ $isNewAccount ? 'Welcome to the AEA Service Portal' : 'Your password was reset' }}

Hi {{ $account->name }},

@if ($isNewAccount)
An account has been created for you on the AEA Service Portal.
@else
Your password on the AEA Service Portal has just been reset.
@endif

**Email:** {{ $account->email }}
**Temporary password:** {{ $temporaryPassword }}

@component('mail::button', ['url' => url('/login')])
Sign in
@endcomponent

You'll be asked to set a password of your own the moment you sign in with this one.

If you weren't expecting this email, please contact ICT.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
