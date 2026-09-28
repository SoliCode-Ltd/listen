<?php

return [

    /*
    | Titan Pro allows 500 messages a day and 200 an hour
    | to addresses outside the mailbox domain. One admin click
    | sends up to whichever of those is still left.
    */
    'daily_limit' => (int) env('NEWSLETTER_DAILY_LIMIT', 500),

    'hourly_limit' => (int) env('NEWSLETTER_HOURLY_LIMIT', 200),

    // Used by newsletter:work. That command is not on the scheduler.
    'per_minute' => (int) env('NEWSLETTER_PER_MINUTE', 3),

];
