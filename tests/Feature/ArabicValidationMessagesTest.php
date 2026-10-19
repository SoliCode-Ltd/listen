<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Form errors on Arabic pages are in Arabic (sign-up, the InstaPay and
 * Vodafone Cash proof upload, the contact form), not the framework's English.
 */
class ArabicValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_up_errors_are_in_arabic(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        app()->setLocale('ar');

        $errors = Validator::make(
            ['name' => '', 'email' => 'taken@example.com', 'password' => 'short', 'password_confirmation' => 'other'],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ],
        )->errors();

        $this->assertSame('الاسم مطلوب.', $errors->first('name'));
        $this->assertSame('البريد الإلكتروني مستخدم من قبل.', $errors->first('email'));
        $this->assertSame('يجب أن يكون طول كلمة المرور 8 أحرف على الأقل.', $errors->first('password'));
    }

    public function test_payment_proof_errors_are_in_arabic(): void
    {
        app()->setLocale('ar');

        $errors = Validator::make(['proof_image' => 'not-a-file'], ['proof_image' => ['required', 'image', 'max:5120']])->errors();

        $this->assertSame('يجب أن يكون صورة إثبات الدفع صورة.', $errors->first('proof_image'));
    }

    public function test_the_summary_of_several_errors_is_in_arabic(): void
    {
        app()->setLocale('ar');

        $validator = Validator::make(['name' => '', 'email' => '', 'subject' => ''], [
            'name' => 'required', 'email' => 'required', 'subject' => 'required',
        ]);
        $validator->fails();

        $this->assertSame('الاسم مطلوب. (و2 أخطاء أخرى)', (new ValidationException($validator))->getMessage());
    }

    public function test_english_pages_keep_the_english_messages(): void
    {
        app()->setLocale('en');

        $errors = Validator::make(['email' => ''], ['email' => 'required'])->errors();

        $this->assertSame('The email field is required.', $errors->first('email'));
    }

    public function test_every_framework_rule_has_an_arabic_message(): void
    {
        $english = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $arabic = require lang_path('ar/validation.php');

        foreach ($english as $rule => $message) {
            if (in_array($rule, ['custom', 'attributes'], true)) {
                continue;
            }
            $this->assertArrayHasKey($rule, $arabic, "No Arabic message for the {$rule} rule.");
            foreach (is_array($message) ? array_keys($message) : [] as $type) {
                $this->assertArrayHasKey($type, $arabic[$rule], "No Arabic message for {$rule}.{$type}.");
            }
        }
    }
}
