<?php

return [

    /*
    | Titan Pro allows about 500 messages a day and 200 an hour
    | to addresses outside the mailbox domain. Stay under both.
    */
    'daily_limit' => (int) env('NEWSLETTER_DAILY_LIMIT', 500),

    'hourly_limit' => (int) env('NEWSLETTER_HOURLY_LIMIT', 180),

    // The scheduler sends this many each minute (180 per hour).
    'per_minute' => (int) env('NEWSLETTER_PER_MINUTE', 3),

    // Sent inside the admin click, same path as an example email.
    'per_click' => (int) env('NEWSLETTER_PER_CLICK', 10),

];
