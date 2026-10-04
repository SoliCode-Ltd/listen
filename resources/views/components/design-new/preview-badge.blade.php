{{-- Shown only to an admin previewing a design other than the one visitors see. --}}
@php
  $previewDesign = \App\Support\SiteDesign::current();
  $previewIsArabic = str_starts_with(strtolower((string) app()->getLocale()), 'ar');
@endphp
<div class="design-preview-badge" role="status" dir="ltr" style="position: fixed; left: 50%; transform: translateX(-50%); bottom: 16px; max-width: calc(100vw - 32px); z-index: 2147483000; display: flex; align-items: center; gap: 10px; padding: 8px 8px 8px 14px; border-radius: 999px; background: #15141B; color: #F5F4F8; font: 600 13px/1.2 'IBM Plex Sans', system-ui, sans-serif; box-shadow: 0 8px 24px rgba(0,0,0,.35); border: 1px solid rgba(255,255,255,.14);">
  <span>{{ $previewIsArabic ? 'معاينة' : 'Previewing' }}: {{ $previewDesign === \App\Support\SiteDesign::NEW ? ($previewIsArabic ? 'التصميم الجديد' : 'New theme') : ($previewIsArabic ? 'التصميم الرئيسي' : 'Main theme') }}</span>
  <a href="{{ request()->fullUrlWithQuery([\App\Support\SiteDesign::PREVIEW_QUERY_KEY => 'reset']) }}" style="color: #15141B; background: #F5F4F8; text-decoration: none; padding: 6px 12px; border-radius: 999px;">{{ $previewIsArabic ? 'إنهاء المعاينة' : 'End preview' }}</a>
</div>
