<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Contact;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function show()
    {
        return view('contact');
    }

    public function submit(Request $request)
    {
        $enabled = SiteSetting::get('contact_form_enabled', true);
        if (!$enabled) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => __('ui.backend.contact_disabled'),
                ], 403);
            }

            return redirect()->back()->with('error', __('ui.backend.contact_disabled'));
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        Contact::create($validated);

        $recipientEmail = trim((string) SiteSetting::get(
            'contact_submission_email',
            SiteSetting::get('contact_email', config('mail.from.address', ''))
        ));
        $recipientName = trim((string) SiteSetting::get(
            'contact_submission_name',
            config('app.name', 'Listen') . ' Contact'
        ));

        if ($recipientEmail !== '') {
            $subject = '[Contact Us] ' . $validated['subject'];
            $bodyLines = [
                'You received a new contact form submission.',
                '',
                'Name: ' . $validated['name'],
                'Email: ' . $validated['email'],
                'Subject: ' . $validated['subject'],
                '',
                'Message:',
                $validated['message'],
            ];

            try {
                Mail::raw(implode(PHP_EOL, $bodyLines), function ($message) use ($recipientEmail, $recipientName, $validated, $subject): void {
                    $message
                        ->to($recipientEmail, $recipientName !== '' ? $recipientName : null)
                        ->replyTo($validated['email'], $validated['name'])
                        ->subject($subject);
                });
            } catch (\Throwable $exception) {
                Log::warning('Failed to send contact form email notification.', [
                    'recipient_email' => $recipientEmail,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Your message has been sent successfully!',
            ]);
        }

        return redirect()->back()->with('success', 'Your message has been sent successfully!');
    }
}
