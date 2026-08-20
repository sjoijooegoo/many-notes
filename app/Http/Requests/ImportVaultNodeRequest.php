<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImportVaultNodeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $uploadMaxFilesize = ini_get('upload_max_filesize') ?: '0';
        $maxFileSizeKilobytes = max(1, intdiv(ini_parse_quantity($uploadMaxFilesize), 1024));

        return [
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'root_name' => ['nullable', 'string', 'max:255'],
            'relative_paths' => ['nullable', 'array', 'max:20'],
            'relative_paths.*' => ['required', 'string', 'max:4096'],
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['required', 'file', 'max:' . $maxFileSizeKilobytes],
        ];
    }
}
