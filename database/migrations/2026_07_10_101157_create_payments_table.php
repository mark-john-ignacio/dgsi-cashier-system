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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained();
            $table->foreignId('school_year_id')->constrained();
            $table->string('or_number');
            $table->date('payment_date');
            $table->decimal('amount', 12, 2);
            $table->string('method'); // cash | gcash | bank
            $table->foreignId('received_by')->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['school_year_id', 'or_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
