<?php

namespace App\Filament\Resources\PlanResource\Pages;

use App\Filament\Resources\PlanResource;
use App\Models\Plan;
use App\Services\Billing\KeptCredits;
use App\Services\StripeProductSyncService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Schema;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    /** The credits before this save, and who gets lower ones (KeptCredits), when lowered. */
    private ?int $creditsBefore = null;

    private ?string $lowerCreditsFor = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $rawState = $this->form->getRawState();
        $this->creditsBefore = (int) $this->record->monthly_chars;
        $this->lowerCreditsFor = $rawState['lower_credits_for'] ?? null;

        // Members can only keep their credits once the database has the columns for it.
        if ($this->lowerCreditsFor !== null && (int) ($data['monthly_chars'] ?? $this->creditsBefore) < $this->creditsBefore
            && ! Schema::hasColumns('subscriptions', KeptCredits::COLUMNS)) {
            Notification::make()
                ->title('Credits not lowered')
                ->body('The database update for keeping members\' credits has not been run on this server yet. Run the migrations, then save again.')
                ->danger()
                ->persistent()
                ->send();
            $this->halt();
        }

        // The hand-written lists are only in the form while "Use my own text instead"
        // is on; otherwise they are left exactly as saved.
        foreach (['features_en', 'features_ar', 'pricing_extra_lines_en', 'pricing_extra_lines_ar'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = PlanResource::normalizeRepeaterItems($data[$field]);
            }
        }

        $priceDollars = (float) ($rawState['price_dollars'] ?? 0);
        $data['price_cents'] = (int) round($priceDollars * 100);

        if (! empty($rawState['overage_price_dollars'])) {
            $data['overage_price_per_million_cents'] = (int) round(((float) $rawState['overage_price_dollars']) * 100);
        } elseif (empty($data['allow_overage'])) {
            $data['overage_price_per_million_cents'] = null;
        }

        if (! empty($rawState['topup_char_price_dollars'])) {
            $data['topup_char_price_cents'] = (int) round(((float) $rawState['topup_char_price_dollars']) * 100);
        } else {
            $data['topup_char_price_cents'] = null;
        }

        if (! empty($rawState['topup_minute_price_dollars'])) {
            $data['topup_minute_price_cents'] = (int) round(((float) $rawState['topup_minute_price_dollars']) * 100);
        } else {
            $data['topup_minute_price_cents'] = null;
        }

        // Clear discount_percentage to null when empty
        if (empty($data['discount_percentage'])) {
            $data['discount_percentage'] = null;
            $data['discount_ends_at'] = null;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->record && (bool) $this->record->is_featured) {
            Plan::query()
                ->whereKeyNot($this->record->getKey())
                ->update(['is_featured' => false]);
        }

        // Auto-sync to Stripe after save
        if ($this->record && $this->record->price_cents > 0 && ! $this->record->isPack()) {
            try {
                $syncService = app(StripeProductSyncService::class);
                if ($syncService->isConfigured()) {
                    // The plan is saved either way; the notice says what Stripe got.
                    PlanResource::stripeSyncNotice(
                        $syncService->syncPlan($this->record),
                        'Stripe Synced',
                        'Plan automatically synced to Stripe.',
                    )->send();
                }
            } catch (\Exception $e) {
                Notification::make()
                    ->title('Stripe Sync Failed')
                    ->body($e->getMessage())
                    ->warning()
                    ->send();
            }
        }

        // Lowered credits: members on the plan now keep theirs as the admin chose.
        // Last, so their plan rows are held only until the save commits.
        if ($this->record && $this->lowerCreditsFor !== null && $this->creditsBefore !== null
            && (int) $this->record->monthly_chars < $this->creditsBefore) {
            $members = app(KeptCredits::class)->keepForCurrentMembers($this->record, $this->creditsBefore, $this->lowerCreditsFor);
            Notification::make()
                ->title('Credits lowered')
                ->body($this->lowerCreditsFor === KeptCredits::NEW_ONLY
                    ? number_format($members).' members on this plan keep their current credits for as long as they stay on it. New subscribers get the new amount.'
                    : number_format($members).' members on this plan keep their current credits until their paid period ends, then get the new amount.')
                ->success()
                ->send();
        }
        // Lowering it again asks again.
        $this->data['lower_credits_for'] = null;
    }
}
