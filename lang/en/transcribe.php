<?php

/*
 * Transcribe (speech to text): the page, and the messages the page, the API
 * and MCP give. Arabic twin: lang/ar/transcribe.php.
 */
return [
    'time' => [
        'hours_minutes' => ':h h :m min',
        'minutes_seconds' => ':m min :s s',
        'minutes' => ':m min',
        'seconds' => ':s s',
    ],

    'refused' => [
        'not_open' => 'Transcribe is not available on your account yet.',
        'no_audio' => 'We could not find any sound in this file. Try an MP3, WAV, M4A, OGG, FLAC, WEBM or MP4 file.',
        'too_big' => 'This file is larger than :mb MB.',
        'not_in_plan' => 'Your plan does not include Transcribe. The :plan plan includes it.',
        'not_in_any_plan' => 'Your plan does not include Transcribe.',
        'too_long_plan' => 'This file is :length long and your plan takes files up to :max. The :plan plan takes it.',
        'too_long_any' => 'This file is :length long and your plan takes files up to :max. Split it into shorter files.',
        'not_enough' => 'This file needs :need and you have :left left this month. Your minutes start again on :date. The :plan plan has more.',
        'not_enough_any' => 'This file needs :need and you have :left left this month. Your minutes start again on :date.',
        'free_used' => 'You have used your free try. The :plan plan includes Transcribe.',
        'free_too_long' => 'The free try takes recordings up to :max, and this one is :length. The :plan plan takes longer files.',
        'free_day_full' => 'Today\'s free tries are all taken. Try again tomorrow, or choose a plan.',
        'busy' => '{1} You already have a file being transcribed. Wait for it to finish.|[2,*] You already have :n files being transcribed. Wait for one to finish.',
        'site_busy' => 'Transcribe is very busy today. Please try again tomorrow.',
        'bad_link' => 'We could not get audio from this link. Use a direct link to an audio or video file.',
        'too_short' => 'This recording is too short to transcribe.',
    ],

    'failed' => [
        'unreadable' => 'We could not read the sound in this file.',
        'not_written' => 'We could not write out this recording. Nothing was taken from your minutes.',
        'stuck' => 'This took too long and was stopped. Nothing was taken from your minutes.',
        'default' => 'Something went wrong. Nothing was taken from your minutes.',
    ],
];
