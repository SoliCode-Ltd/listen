<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_REASON_EN = 'Your account has been suspended because multiple accounts were created from the same network. Each person is allowed one account only.';
    private const NEW_REASON_EN = 'Your account has been suspended because multiple accounts were created from the same network.';
    private const OLD_REASON_AR = 'تم تعليق حسابك بسبب إنشاء حسابات متعددة من نفس الشبكة. يُسمح لكل شخص بحساب واحد فقط.';
    private const NEW_REASON_AR = 'تم تعليق حسابك بسبب إنشاء حسابات متعددة من نفس الشبكة.';

    public function up(): void
    {
        DB::table('users')
            ->where('is_blocked', true)
            ->where('blocked_reason_en', self::OLD_REASON_EN)
            ->update([
                'blocked_reason_en' => self::NEW_REASON_EN,
            ]);

        DB::table('users')
            ->where('is_blocked', true)
            ->where('blocked_reason_ar', self::OLD_REASON_AR)
            ->update([
                'blocked_reason_ar' => self::NEW_REASON_AR,
            ]);

        DB::table('users')
            ->where('is_blocked', true)
            ->where('blocked_reason', self::OLD_REASON_EN)
            ->update([
                'blocked_reason' => self::NEW_REASON_EN,
            ]);

        DB::table('users')
            ->where('is_blocked', true)
            ->where('blocked_reason', self::OLD_REASON_AR)
            ->update([
                'blocked_reason' => self::NEW_REASON_AR,
            ]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('is_blocked', true)
            ->where('blocked_reason_en', self::NEW_REASON_EN)
            ->update([
                'blocked_reason_en' => self::OLD_REASON_EN,
            ]);

        DB::table('users')
            ->where('is_blocked', true)
            ->where('blocked_reason_ar', self::NEW_REASON_AR)
            ->update([
                'blocked_reason_ar' => self::OLD_REASON_AR,
            ]);

        DB::table('users')
            ->where('is_blocked', true)
            ->where('blocked_reason', self::NEW_REASON_EN)
            ->update([
                'blocked_reason' => self::OLD_REASON_EN,
            ]);

        DB::table('users')
            ->where('is_blocked', true)
            ->where('blocked_reason', self::NEW_REASON_AR)
            ->update([
                'blocked_reason' => self::OLD_REASON_AR,
            ]);
    }
};
