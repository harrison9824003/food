<?php

use App\Enums\OutboxStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->string('aggregate_type', 50); // 例：reservation
            $table->unsignedBigInteger('aggregate_id');
            $table->string('event_type', 50);     // 例：reservation.created
            $table->json('payload');
            $table->string('status', 20)->default(OutboxStatus::Pending->value);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable(); // 指數退避的下次重試時間
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // Worker 撈取：WHERE status = 'pending' AND next_attempt_at <= now()
            $table->index(['status', 'next_attempt_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
