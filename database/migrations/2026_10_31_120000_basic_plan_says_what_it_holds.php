<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Basic plan said "The premium voice for light use", and search engines
     * and AI assistants read those words as a description of the whole studio.
     * It now says what the plan holds: premium voices with all the studio's dialects
     * and accents, for a smaller monthly volume. Only the original wording is replaced, so a
     * tagline edited in the admin since stays as it is.
     */
    private const OLD = [
        'description_en' => 'The premium voice for light use, in your dashboard and the browser extension.',
        'description_ar' => 'الصوت المتميز للاستخدام الخفيف، في لوحة التحكم وإضافة المتصفح.',
    ];

    private const NEW = [
        'description_en' => 'Premium voices with all the studio\'s dialects and accents, for a smaller monthly volume, in your dashboard and the browser extension.',
        'description_ar' => 'أصوات متميزة بجميع لهجات الاستوديو ولكناته، لكمية شهرية أصغر، في لوحة التحكم وإضافة المتصفح.',
    ];

    public function up(): void
    {
        $this->swap(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->swap(self::NEW, self::OLD);
    }

    /**
     * @param  array<string, string>  $from
     * @param  array<string, string>  $to
     */
    private function swap(array $from, array $to): void
    {
        foreach ($to as $column => $text) {
            if (Schema::hasColumn('plans', $column)) {
                DB::table('plans')->where('slug', 'basic')->where($column, $from[$column])->update([$column => $text, 'updated_at' => now()]);
            }
        }
    }
};
