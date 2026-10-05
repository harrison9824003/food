<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // 錢已經收到，但訂位無法成立（晚到付款名額被搶走、重複付款、取消後才付）。
            // 退款流程在里程碑 6；在那之前由這個旗標標示，後台可以篩出來處理。
            $table->boolean('needs_refund')->default(false)->after('status');
            $table->string('refund_reason', 40)->nullable()->after('needs_refund');
            $table->string('failure_reason')->nullable()->after('refund_reason');
            // 這筆付款是靠哪條路徑確認的：notify（綠界背景通知）／reconcile（對帳排程）／simulator
            $table->string('confirmed_via', 20)->nullable()->after('failure_reason');

            $table->index('needs_refund');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['needs_refund']);
            $table->dropColumn(['needs_refund', 'refund_reason', 'failure_reason', 'confirmed_via']);
        });
    }
};
