<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;

class BillingSubscription extends CashierSubscription
{
    protected $table = 'billing_subscriptions';

    public function items(): HasMany
    {
        return $this->hasMany(Cashier::$subscriptionItemModel, 'subscription_id', 'id');
    }
}
