{{-- New site design: fonts and stylesheet. Only included when SiteDesign::isNew(). --}}
@php
  $designNewCss = public_path('css/design-new.css');
  $designNewVersion = is_file($designNewCss) ? filemtime($designNewCss) : '1';
@endphp
  <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('css/design-new.css') }}?v={{ $designNewVersion }}">
