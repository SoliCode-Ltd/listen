{{-- The credits coin used everywhere (public/images/credit-coin.webp). Sized by font-size: 1em tall.
     On left-to-right pages it is mirrored so the stack sits on the outer side, as in Arabic. --}}
<img {{ $attributes->merge(['class' => 'credit-icon']) }}
     src="{{ \App\Support\VersionedAsset::url('images/credit-coin.webp') }}"
     alt="{{ __('ui.pricing.credits_icon_label') }}"
     width="247" height="192" decoding="async"
     style="display:inline-block;flex:none;height:1em;width:auto;vertical-align:-0.18em;{{ app()->getLocale() === 'ar' ? '' : 'transform:scaleX(-1);' }}">
