<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 修改門市設定（營業時間、公休日、桌位、時段名額…）的共同授權：
 * 總部或該門市店長才可寫入。先授權再驗證，沒權限的人拿到 403 而不是 422 欄位細節。
 */
abstract class ManagesStoreSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSettings', $this->route('store'));
    }
}
