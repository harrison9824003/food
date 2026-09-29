<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20);
            $table->string('code_hash'); // 驗證碼雜湊，不存明碼
            $table->string('ip', 45)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0); // 錯誤次數，達上限即作廢
            $table->timestamp('expires_at');   // 有效期 5 分鐘
            $table->timestamp('last_sent_at'); // 重送冷卻 60 秒
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['phone', 'created_at']);
            $table->index(['ip', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_verifications');
    }
};
