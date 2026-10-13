<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** MockSmsDriver 的「收件匣」：開發環境不發真的簡訊，把內容存這裡讓人看。 */
    public function up(): void
    {
        Schema::create('mock_sms_messages', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->index();
            $table->string('body', 500);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_sms_messages');
    }
};
