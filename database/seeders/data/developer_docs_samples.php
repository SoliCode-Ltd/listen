<?php

/*
 * The request at the top of the developer docs, one for each page language,
 * and the recording it returned. Each recording is made by sending exactly
 * this request to the API (scripts/api/make_docs_samples.php, run by the
 * "Developer docs samples" workflow), and the docs show the same request, so
 * readers hear what it returns: no signature, nothing added. To change one,
 * edit its request, delete its file and run the workflow again.
 */

return [
    'ar' => [
        // Sumaya, in Lebanese: the voice and dialect clients ask for most.
        'voice' => 'ar-Gemini-HD-Callirrhoe',
        'audio' => 'audio/developers/ar-sumaya-lebanese.mp3',
        'body' => [
            'model_id' => 'listen_tts_premium_v1',
            'text' => 'أهلين فيك! أنا مساعدك الصوتي. فيك تسألني عن أي شي، وأنا هون لساعدك بكل يلّي بتحتاجه.',
            'language_code' => 'ar',
            'dialect' => 'ar-LB',
            'style' => 'assistant',
            'emotion' => 'warm',
            'voice_settings' => ['speed' => 1.0, 'pitch' => 0.0],
        ],
    ],
    'en' => [
        // Maya, in American English.
        'voice' => 'en-Gemini-HD-Callirrhoe',
        'audio' => 'audio/developers/en-maya-american.mp3',
        'body' => [
            'model_id' => 'listen_tts_premium_v1',
            // No apostrophes: in the cURL example they would show as shell escapes.
            'text' => 'Hi there, welcome back! Ask me anything you like, and I will help you right away.',
            'language_code' => 'en',
            'dialect' => 'en-US',
            'style' => 'assistant',
            'emotion' => 'warm',
            'voice_settings' => ['speed' => 1.0, 'pitch' => 0.0],
        ],
    ],
];
