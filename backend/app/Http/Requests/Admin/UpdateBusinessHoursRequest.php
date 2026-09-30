<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBusinessHoursRequest extends ManagesStoreSettingsRequest
{
    /**
     * 一次送滿 7 天（週日=0 … 週六=6），整組覆寫，避免只改一天造成前後端狀態不一致。
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6', 'distinct'],
            'hours.*.is_closed' => ['required', 'boolean'],
            'hours.*.open_time' => ['nullable', 'date_format:H:i'],
            'hours.*.close_time' => ['nullable', 'date_format:H:i'],
            'hours.*.slot_minutes' => ['nullable', 'integer', Rule::in([15, 30, 60])],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ((array) $this->input('hours', []) as $i => $hour) {
                    if (filter_var($hour['is_closed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                        continue;
                    }

                    foreach (['open_time', 'close_time', 'slot_minutes'] as $field) {
                        if (empty($hour[$field])) {
                            $validator->errors()->add("hours.$i.$field", '營業日必須填寫');
                        }
                    }

                    // 兩者都是零填充的 H:i，字串比較即等同時間比較
                    if (! empty($hour['open_time']) && ! empty($hour['close_time']) && $hour['open_time'] >= $hour['close_time']) {
                        $validator->errors()->add("hours.$i.close_time", '打烊時間必須晚於開店時間');
                    }
                }
            },
        ];
    }
}
