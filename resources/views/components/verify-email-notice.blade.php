{{--
  Confirming the email of an account made with an email and a password
  (App\Support\EmailVerification), on the demo page and in the studio: what a
  link or a new link did, and while the email still has to be confirmed, what
  to do, with a button for a link.

  $verifyEmail: null, or ['address' => the address to confirm, 'sent' => whether a link that still works went out].
  $verifyEmailTitle: the notice's title on this page.
--}}
@php
  $verifyFlash = session('verify_email_notice');
  $verifyFlash = is_array($verifyFlash) && trim((string) ($verifyFlash['message'] ?? '')) !== '' ? $verifyFlash : null;
  $verifyFlashIsError = ($verifyFlash['type'] ?? '') === 'error';
  $verifyAddress = trim((string) ($verifyEmail['address'] ?? ''));
  $verifySent = (bool) ($verifyEmail['sent'] ?? false);
  // The address is set apart from the words around it, so it reads the right way in Arabic too.
  [$verifyBodyBefore, $verifyBodyAfter] = array_pad(explode(':email', (string) __($verifySent ? 'ui.verify_email.notice_body' : 'ui.verify_email.notice_body_unsent'), 2), 2, '');
@endphp
@if($verifyFlash || $verifyAddress !== '')
  <div class="verify-email">
    @if($verifyFlash)
      <p class="verify-email-flash {{ $verifyFlashIsError ? 'is-error' : 'is-ok' }}" role="{{ $verifyFlashIsError ? 'alert' : 'status' }}">{{ $verifyFlash['message'] }}</p>
    @endif
    @if($verifyAddress !== '')
      <section class="verify-email-notice" id="verifyEmailNotice" aria-labelledby="verifyEmailTitle">
        <span class="verify-email-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
        </span>
        <div class="verify-email-text">
          <h2 id="verifyEmailTitle">{{ $verifyEmailTitle ?? __('ui.verify_email.notice_title') }}</h2>
          <p>{{ $verifyBodyBefore }}<bdi>{{ $verifyAddress }}</bdi>{{ $verifyBodyAfter }}</p>
          @if($verifySent)
            <p class="verify-email-hint">{{ __('ui.verify_email.notice_hint') }}</p>
          @endif
        </div>
        <form method="POST" action="{{ route('verification.send') }}" class="verify-email-form" onsubmit="this.querySelector('button').disabled = true;">
          @csrf
          <button type="submit" class="btn btn-primary">{{ __($verifySent ? 'ui.verify_email.resend_button' : 'ui.verify_email.send_button') }}</button>
        </form>
      </section>
    @endif
  </div>
  <style>
    .verify-email { display: grid; gap: .75rem; margin-bottom: 1.1rem; }
    .verify-email-flash { margin: 0; padding: .75rem 1rem; border-radius: .85rem; font-size: .92rem; line-height: 1.55; font-weight: 600; }
    .verify-email-flash.is-ok { border: 1px solid color-mix(in oklab, var(--ok) 45%, var(--line)); background: color-mix(in oklab, var(--ok) 12%, var(--surface)); color: color-mix(in oklab, var(--ok) 70%, var(--text)); }
    .verify-email-flash.is-error { border: 1px solid color-mix(in oklab, var(--danger) 45%, var(--line)); background: color-mix(in oklab, var(--danger) 10%, var(--surface)); color: color-mix(in oklab, var(--danger) 78%, var(--text)); }
    .verify-email-notice { display: flex; align-items: center; gap: 1rem; padding: 1.1rem 1.25rem; border-radius: var(--card-radius, 20px); border: 1px solid color-mix(in oklab, var(--a) 40%, var(--line)); background: color-mix(in oklab, var(--a) 8%, var(--surface)); }
    .verify-email-icon { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 2.75rem; height: 2.75rem; border-radius: 50%; background: color-mix(in oklab, var(--a) 18%, var(--surface)); color: var(--accent-text, var(--a)); }
    .verify-email-icon svg { width: 1.35rem; height: 1.35rem; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .verify-email-text { flex: 1 1 auto; min-width: 0; }
    .verify-email-text h2 { margin: 0 0 .3rem; font-size: 1.1rem; line-height: 1.3; }
    .verify-email-text p { margin: 0; line-height: 1.55; overflow-wrap: anywhere; }
    .verify-email-text .verify-email-hint { margin-top: .3rem; color: var(--muted); font-size: .88rem; }
    .verify-email-form { flex: 0 0 auto; margin: 0; }
    .verify-email-form .btn { white-space: nowrap; }
    @media (max-width: 640px) {
      .verify-email-notice { flex-direction: column; align-items: stretch; }
      .verify-email-icon { display: none; }
      .verify-email-form .btn { width: 100%; justify-content: center; }
    }
  </style>
@endif
