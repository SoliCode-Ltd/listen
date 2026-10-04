@if(\App\Support\SiteDesign::isNew())
@include('components.design-new.not-found')
@else
{!! view()->file(base_path('vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/views/404.blade.php'), get_defined_vars())->render() !!}@endif