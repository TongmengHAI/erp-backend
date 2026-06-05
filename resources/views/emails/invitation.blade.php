@component('mail::message')
{{ $greeting }}

{{ $intro }}

{{ $ctaIntro }}

@component('mail::button', ['url' => $url])
{{ __('invitation.email.cta_button') }}
@endcomponent

{{ $expiry }}

{{ __('invitation.email.safe_to_ignore') }}

{{ __('invitation.email.signature') }}
@endcomponent
