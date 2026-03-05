<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add localized fields to plans table for Arabic/English support.
 * 
 * This allows admins to configure plan names, descriptions, and features
 * in both languages directly from the admin panel without editing language files.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Localized name (fallback to 'name' column if empty)
            $table->string('name_en')->nullable()->after('name');
            $table->string('name_ar')->nullable()->after('name_en');
            
            // Localized description (shown on pricing cards)
            $table->text('description_en')->nullable()->after('name_ar');
            $table->text('description_ar')->nullable()->after('description_en');
            
            // Localized features arrays (JSON with label/value/icon)
            // If empty, auto-generated features will be used
            $table->json('features_en')->nullable()->after('features');
            $table->json('features_ar')->nullable()->after('features_en');
            
            // Button text customization
            $table->string('button_text_en')->nullable()->after('features_ar');
            $table->string('button_text_ar')->nullable()->after('button_text_en');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'name_en',
                'name_ar',
                'description_en',
                'description_ar',
                'features_en',
                'features_ar',
                'button_text_en',
                'button_text_ar',
            ]);
        });
    }
};
