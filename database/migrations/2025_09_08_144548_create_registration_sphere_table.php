<?php



use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_sphere', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sphere_id')->constrained('spheres')->cascadeOnDelete();
            $table->primary(['user_id', 'sphere_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sphere');
    }
};
