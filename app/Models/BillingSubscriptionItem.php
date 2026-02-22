<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;

class BillingSubscriptionItem extends CashierSubscriptionItem
{
    protected $table = 'billing_subscription_items';

    public function subscription(): BelongsTo
    {
        $model = Cashier::$subscriptionModel;

        return $this->belongsTo($model, 'subscription_id');
    }
}
