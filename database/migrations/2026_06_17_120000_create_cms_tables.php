<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_pages', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content')->nullable();
            $table->string('status')->default('draft');
            $table->boolean('show_in_footer')->default(false);
            $table->string('footer_column')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cms_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        $now = now();
        $defaults = [
            'footer_company_description' => 'Philippine Amusement and Entertainment Corporation. Your gateway to the best fun experiences in the Philippines.',
            'footer_contact_email' => 'inquire@paec.ph',
            'footer_contact_phone' => '+63 928 297 5671',
            'footer_contact_address' => 'Manila, Philippines',
            'footer_copyright' => '© 2026 PAEC. All rights reserved.',
            'footer_explore_links' => [
                ['label' => 'Fun Activities', 'href' => '/?category=fun'],
                ['label' => 'Events', 'href' => '/?category=events'],
                ['label' => 'Travel', 'href' => '#'],
                ['label' => 'Stay', 'href' => '#'],
                ['label' => 'Eats', 'href' => '#'],
            ],
            'footer_support_links' => [
                ['label' => 'Help Center', 'href' => '/pages/help-center'],
                ['label' => 'Terms of Service', 'href' => '/pages/terms-of-service'],
                ['label' => 'Privacy Policy', 'href' => '/pages/privacy-policy'],
                ['label' => 'Refund Policy', 'href' => '/pages/refund-policy'],
            ],
        ];

        foreach ($defaults as $key => $value) {
            DB::table('cms_settings')->insert([
                'key' => $key,
                'value' => json_encode($value),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $pages = [
            [
                'title' => 'About Us',
                'slug' => 'about-us',
                'content' => '<p>Welcome to PAEC — Philippine Amusement and Entertainment Corporation. We connect you with the best attractions and experiences across the Philippines.</p>',
                'status' => 'published',
                'show_in_footer' => true,
                'footer_column' => 'explore',
                'sort_order' => 1,
            ],
            [
                'title' => 'Contact Us',
                'slug' => 'contact-us',
                'content' => '<p>Reach us at inquire@paec.ph or call +63 928 297 5671. Our team is happy to assist with bookings and general inquiries.</p>',
                'status' => 'published',
                'show_in_footer' => true,
                'footer_column' => 'support',
                'sort_order' => 2,
            ],
            [
                'title' => 'Help Center',
                'slug' => 'help-center',
                'content' => '<p>Find answers to common questions about bookings, tickets, refunds, and account management.</p>',
                'status' => 'published',
                'show_in_footer' => false,
                'footer_column' => null,
                'sort_order' => 3,
            ],
            [
                'title' => 'Terms of Service',
                'slug' => 'terms-of-service',
                'content' => '<p>These terms govern your use of the PAEC marketplace and related services.</p>',
                'status' => 'published',
                'show_in_footer' => false,
                'footer_column' => null,
                'sort_order' => 4,
            ],
            [
                'title' => 'Privacy Policy',
                'slug' => 'privacy-policy',
                'content' => '<p>Learn how PAEC collects, uses, and protects your personal information.</p>',
                'status' => 'published',
                'show_in_footer' => false,
                'footer_column' => null,
                'sort_order' => 5,
            ],
            [
                'title' => 'Refund Policy',
                'slug' => 'refund-policy',
                'content' => '<p>Review our refund and cancellation guidelines for ticket purchases.</p>',
                'status' => 'published',
                'show_in_footer' => false,
                'footer_column' => null,
                'sort_order' => 6,
            ],
        ];

        foreach ($pages as $page) {
            DB::table('cms_pages')->insert([
                'uuid' => (string) Str::uuid(),
                'title' => $page['title'],
                'slug' => $page['slug'],
                'content' => $page['content'],
                'status' => $page['status'],
                'show_in_footer' => $page['show_in_footer'],
                'footer_column' => $page['footer_column'],
                'sort_order' => $page['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_pages');
        Schema::dropIfExists('cms_settings');
    }
};
