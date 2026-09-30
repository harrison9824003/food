<?php

namespace App\Http\Requests\Admin;

use App\Models\DiningTable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDiningTableRequest extends ManagesStoreSettingsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var DiningTable $table */
        $table = $this->route('diningTable');

        return [
            'code' => [
                'sometimes', 'string', 'max:20',
                Rule::unique('dining_tables', 'code')->where('store_id', $table->store_id)->ignore($table->id),
            ],
            'min_seats' => ['sometimes', 'integer', 'between:1,50'],
            'max_seats' => ['sometimes', 'integer', 'between:1,50'],
            'is_combinable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * 只送 min 或只送 max 時，要拿另一個欄位的現值比對，避免改出「下限大於上限」。
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var DiningTable $table */
                $table = $this->route('diningTable');

                $min = (int) $this->input('min_seats', $table->min_seats);
                $max = (int) $this->input('max_seats', $table->max_seats);

                if ($min > $max) {
                    $validator->errors()->add('max_seats', '人數上限不可小於下限');
                }
            },
        ];
    }
}
