<?php

namespace App\Http\Controllers;

use App\Models\NewsletterSuppression;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NewsletterUnsubscribeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $email = strtolower(trim((string) $request->query('email', '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(404);
        }

        NewsletterSuppression::query()->updateOrCreate(
            ['email' => $email],
            ['unsubscribed_at' => now()],
        );

        $locale = $request->query('lang') === 'ar' ? 'ar' : 'en';

        return response()->view('newsletter.unsubscribed', [
            'email' => $email,
            'locale' => $locale,
        ]);
    }
}
