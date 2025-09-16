<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Basic user info (from users table)
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('middle_initial', 10)->nullable();
            $table->string('email')->nullable();
            $table->string('profile_image')->nullable();
            $table->enum('role', ['super_admin', 'admin', 'user'])->default('user');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();

            // Additional registration info (from registrations table)
            $table->string('title')->nullable();
            $table->string('mobile_number', 50)->nullable();

            // Address / church
            $table->text('home_address')->nullable();
            $table->string('church_name')->nullable();
            $table->text('church_address')->nullable();

            // Status / classification
            $table->enum('working_or_student', ['working', 'student'])->nullable();

            // Vocation spheres handled in pivot table (registration_sphere)
            $table->text('vocation_work_sphere')->nullable();

            // Payment
            $table->enum('mode_of_payment', ['gcash', 'bank', 'cash', 'other'])->nullable();
            $table->text('proof_of_payment_url')->nullable();
            $table->text('notes')->nullable();

            // Misc
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference_number')->nullable();

            // Flags / statuses
            $table->boolean('reconciled')->default(false);
            $table->boolean('finance_checked')->default(false); // Victory Pampanga Finance / Ms. Abbey
            $table->boolean('email_confirmed')->default(false); // TN Secretariat
            $table->boolean('attendance')->default(false);
            $table->boolean('id_issued')->default(false);
            $table->boolean('book_given')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
