<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer testimonials shown on the public homepage and managed by
 * super-admins at /admin/testimonials.
 *
 * These are rows, not key→value copy, so they live in their own table rather
 * than in site_settings alongside the rest of the landing-page content: the
 * operator adds and removes them freely, and the homepage carousel simply
 * renders whatever is active, ordered by `sort_order`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('role', 120)->nullable();       // job title
            $table->string('company', 120)->nullable();
            $table->text('quote');
            $table->string('avatar_url', 500)->nullable(); // uploaded path or external URL
            $table->unsignedTinyInteger('rating')->default(5);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // The homepage query is exactly this: active rows in display order.
            $table->index(['is_active', 'sort_order']);
        });

        // Seed a starter set so the section is never an empty hole on a fresh
        // install. They're ordinary rows — the operator edits or deletes them
        // from the ops console like any other testimonial.
        $now  = date('Y-m-d H:i:s');
        $seed = [
            ['Ayesha Khan',   'Practice Manager',   'Smile Dental Clinic',   5,
             'We were losing three or four patients a week to unanswered evening calls. Serve AI picks up every one of them, books the slot, and the confirmation is in my inbox before I get to the office.'],
            ['Bilal Ahmed',   'Founder',            'Northline Interiors',   5,
             'It answers WhatsApp faster than I ever could, and it answers correctly — it only knows what we told it. Our quote requests went up noticeably in the first month.'],
            ['Sara Malik',    'Head of Sales',      'Meridian Properties',   5,
             'Every listing enquiry gets a reply in seconds, day or night. By the time an agent opens the CRM the lead is already qualified and a viewing is on the calendar.'],
            ['Daniyal Raza',  'Operations Lead',    'Karachi Kitchen Co.',   5,
             'The dinner rush used to mean a phone ringing off the hook. Now reservations and menu questions handle themselves and my team stays on the floor.'],
            ['Hina Siddiqui', 'Customer Care Head', 'Vero Cosmetics',        5,
             'Customers asking where their order is used to eat our whole support day. It now resolves on its own, and my team only sees the conversations that genuinely need a human.'],
            ['Omar Farooq',   'Managing Director',  'Aegis Business Group',  5,
             'What sold me was the control. Our own database, our own rules about what the AI can read, and a full transcript of everything it ever said. That is rare.'],
        ];

        $rows = [];
        foreach ($seed as $i => [$name, $role, $company, $rating, $quote]) {
            $rows[] = [
                'name'       => $name,
                'role'       => $role,
                'company'    => $company,
                'quote'      => $quote,
                'avatar_url' => null,
                'rating'     => $rating,
                'sort_order' => ($i + 1) * 10,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('testimonials')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('testimonials');
    }
};
