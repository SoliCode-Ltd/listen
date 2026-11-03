<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listen Agents: voice agents a business puts on its website, a link page or a
 * QR code, that talk to its customers in their dialect.
 *
 *   agents               one agent: who it is, how it sounds, what it knows
 *   agent_sources        what it was taught from (the website, notes, files)
 *   agent_widgets        where it can be reached (a call button, the link page)
 *   agent_conversations  every call: transcript, summary, the customer's
 *                        details, how long it lasted and the credits it used
 *   agent_contacts       the customers who left their details
 *
 * Only new tables: nothing that exists changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agents')) {
            Schema::create('agents', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                // Public, unguessable id for links, the widget and the API.
                $t->string('public_id', 32)->unique();
                $t->string('name', 80);
                $t->string('business_name', 120);
                $t->string('business_type', 40)->default('other');
                $t->json('jobs')->nullable();
                $t->string('website_url', 500)->nullable();
                $t->string('language', 8)->default('ar');
                $t->string('dialect', 20)->nullable();
                // The speaker from the voice library, and the live voice it speaks with.
                $t->string('speaker_id', 80)->nullable();
                $t->string('live_voice', 60)->nullable();
                $t->text('first_message')->nullable();
                // What it is, what it must never do, its steps, when to pass to a person, what to collect.
                $t->json('script')->nullable();
                // The business facts it answers from.
                $t->longText('knowledge')->nullable();
                // The policy questions asked at setup, with the answers chosen.
                $t->json('choices')->nullable();
                $t->json('settings')->nullable();
                // draft: still being set up; ready: set up, not live yet; live; paused.
                $t->string('status', 16)->default('draft');
                $t->unsignedSmallInteger('max_call_seconds')->default(600);
                $t->timestamp('tested_at')->nullable();
                $t->timestamp('went_live_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['user_id', 'status']);
            });
        }

        if (! Schema::hasTable('agent_sources')) {
            Schema::create('agent_sources', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('agent_id')->constrained()->cascadeOnDelete();
                // website, text, file or sheet
                $t->string('kind', 16);
                $t->string('title', 200)->nullable();
                $t->string('url', 500)->nullable();
                $t->string('path', 500)->nullable();
                $t->longText('content')->nullable();
                $t->unsignedInteger('chars')->default(0);
                // pending, ready or failed
                $t->string('status', 16)->default('pending');
                $t->string('error', 500)->nullable();
                $t->timestamp('read_at')->nullable();
                $t->timestamps();
                $t->index(['agent_id', 'kind']);
            });
        }

        if (! Schema::hasTable('agent_widgets')) {
            Schema::create('agent_widgets', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('agent_id')->constrained()->cascadeOnDelete();
                // The key the embed code carries; the link page lives at /talk/{slug}.
                $t->string('public_key', 40)->unique();
                $t->string('slug', 80)->unique();
                $t->json('allowed_origins')->nullable();
                $t->json('appearance')->nullable();
                $t->boolean('link_enabled')->default(true);
                $t->boolean('enabled')->default(true);
                // Where it was installed from (code, wordpress, shopify, wix, laravel ...), and the last page that loaded it.
                $t->string('installed_via', 24)->nullable();
                $t->string('last_seen_origin', 255)->nullable();
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('agent_conversations')) {
            Schema::create('agent_conversations', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('agent_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->foreignId('agent_widget_id')->nullable()->constrained()->nullOnDelete();
                $t->string('public_id', 32)->unique();
                // The key the caller's browser holds for this call (only its hash is kept).
                $t->string('call_key_hash', 64)->nullable();
                // test (the owner trying it), widget or link
                $t->string('channel', 16);
                $t->string('visitor_hash', 64)->nullable();
                $t->string('origin', 255)->nullable();
                $t->string('page_url', 500)->nullable();
                $t->string('country', 2)->nullable();
                // live, ended or failed
                $t->string('status', 16)->default('live');
                $t->string('end_reason', 32)->nullable();
                $t->timestamp('started_at');
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamp('ended_at')->nullable();
                $t->unsignedInteger('duration_seconds')->default(0);
                $t->unsignedInteger('billed_seconds')->default(0);
                $t->unsignedInteger('credits')->default(0);
                // The plan credits held when the call started (MemberUsageMeter::reserveCredits), and
                // their month; what the call did not use is given back when it ends. A free test call holds none.
                $t->json('reservation')->nullable();
                $t->string('period_ym', 20)->nullable();
                $t->boolean('free')->default(false);
                $t->timestamp('settled_at')->nullable();
                $t->json('transcript')->nullable();
                $t->text('summary')->nullable();
                $t->json('lead')->nullable();
                $t->string('outcome', 40)->nullable();
                $t->json('usage')->nullable();
                $t->decimal('cost_usd', 10, 5)->nullable();
                $t->timestamps();
                $t->index(['user_id', 'started_at']);
                $t->index(['agent_id', 'started_at']);
                $t->index(['status', 'last_seen_at']);
            });
        }

        if (! Schema::hasTable('agent_contacts')) {
            Schema::create('agent_contacts', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('agent_conversation_id')->nullable()->constrained()->nullOnDelete();
                $t->string('name', 120)->nullable();
                $t->string('phone', 40)->nullable();
                $t->string('email', 190)->nullable();
                $t->text('note')->nullable();
                $t->timestamp('last_contact_at')->nullable();
                $t->timestamps();
                $t->index(['user_id', 'last_contact_at']);
                $t->index(['user_id', 'phone']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_contacts');
        Schema::dropIfExists('agent_conversations');
        Schema::dropIfExists('agent_widgets');
        Schema::dropIfExists('agent_sources');
        Schema::dropIfExists('agents');
    }
};
