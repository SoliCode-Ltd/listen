<?php

namespace App\Providers\Filament;

use App\Http\Middleware\EnsureUserIsNotBlocked;
use App\Http\Middleware\ForceAdminLocale;
use App\Http\Middleware\SecurityHeaders;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Colors\Color;
use Filament\Tables\Table;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * Filament 4 changed a few defaults; the admin keeps Filament 3's, so it looks
     * and works as before: form sections, grids and field groups take the full width
     * (not one column each), and a list's filters apply as soon as they are chosen
     * (no Apply button). Settings made on a component itself still win.
     */
    public function boot(): void
    {
        if (! self::packagesReady()) {
            return;
        }

        Section::configureUsing(fn (Section $section) => $section->columnSpanFull());
        Grid::configureUsing(fn (Grid $grid) => $grid->columnSpanFull());
        Fieldset::configureUsing(fn (Fieldset $fieldset) => $fieldset->columnSpanFull());
        Table::configureUsing(fn (Table $table) => $table->deferFilters(false));
    }

    /**
     * A deploy puts the code on the server a minute before its packages (the composer
     * step). The admin is written for Filament 5: on the packages before it (Filament 3),
     * loading it would fail on every page, members' included. Until Filament 5 is there,
     * the admin is left out, so the site keeps working and only /admin waits.
     */
    public function register(): void
    {
        if (self::packagesReady()) {
            parent::register();
        }
    }

    public static function packagesReady(): bool
    {
        return class_exists(\Filament\Schemas\Schema::class);
    }

    /**
     * The admin theme's version: the fingerprint on its first line (what it was built from,
     * scripts/build-admin-theme.mjs). Cloudflare and browsers keep the file for a year, so
     * without it a rebuilt theme would not reach them; Filament versions only its own files.
     */
    public static function themeVersion(): string
    {
        $file = public_path('css/filament/admin/theme.css');
        $handle = is_file($file) ? @fopen($file, 'r') : false;
        $first = $handle ? (string) fgets($handle, 200) : '';
        if ($handle) {
            fclose($handle);
        }

        return preg_match('/sources:([0-9a-f]{12})/', $first, $m) ? $m[1] : (string) (@filemtime($file) ?: '0');
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName(config('app.name', 'Listen') . ' Admin')
            // Filament's stylesheet with the admin's own pages' classes (resources/css/filament/admin/theme.css),
            // at an address that changes with its content (themeVersion()).
            ->theme(asset('css/filament/admin/theme.css').'?v='.self::themeVersion())
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            // App\Filament\Pages\Dashboard (business statistics + report download) replaces Filament's default dashboard.
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                EnsureUserIsNotBlocked::class,
                ShareErrorsFromSession::class,
                // Laravel 13's name, else 12's (between a deploy and its packages).
                class_exists(PreventRequestForgery::class) ? PreventRequestForgery::class : VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                ForceAdminLocale::class,
                SecurityHeaders::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
