<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreVaultMcpTokenRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:80', 'regex:/\S/u'],
            'expires' => ['required', 'integer', 'between:1,3650'],
            'read_all_vaults' => ['required', 'boolean'],
            'can_write' => ['required', 'boolean'],
        ];
    }
}
