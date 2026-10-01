<?php

namespace App\Console\Commands;

use App\Models\Slot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LoadtestReport extends Command
{
    protected $signature = 'loadtest:report {slot : 時段 ID} {--json : 只輸出 JSON}';

    protected $description = '壓測後檢查某個時段有沒有超賣';

    public function handle(): int
    {
        $slot = Slot::findOrFail($this->argument('slot'));

        // 有效訂位 = 仍佔用名額的狀態。超賣看的是「訂位人數總和」，不是 slots.booked：
        // 無防護版本的 lost update 會讓 booked 被蓋小，看起來剛好客滿，實際訂位人數卻已超過名額。
        $active = DB::table('reservations')
            ->where('slot_id', $slot->id)
            ->whereIn('status', ['pending_payment', 'confirmed', 'arrived']);

        $reservations = (clone $active)->count();
        $guests = (int) (clone $active)->sum('party_size');
        $tableRows = DB::table('reservation_tables')->where('slot_id', $slot->id)->count();

        $report = [
            'slot_id' => $slot->id,
            'capacity' => $slot->capacity,
            'booked_counter' => $slot->booked,
            'reservations' => $reservations,
            'guests' => $guests,
            // 超賣人數：實際訂位人數超過名額的部分
            'oversold_guests' => max(0, $guests - $slot->capacity),
            // 計數器漂移：slots.booked 與實際訂位人數的落差，非 0 代表 lost update
            'counter_drift' => $guests - $slot->booked,
            'table_rows' => $tableRows,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report));

            return self::SUCCESS;
        }

        $this->table(['項目', '數值'], collect($report)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->line($report['oversold_guests'] > 0
            ? "<fg=red>超賣 {$report['oversold_guests']} 位</>"
            : '<fg=green>沒有超賣</>');

        return self::SUCCESS;
    }
}
