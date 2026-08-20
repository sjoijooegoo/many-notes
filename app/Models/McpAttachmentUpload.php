<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Facades\Storage;
use Override;

/**
 * @property-read string $id
 * @property int $personal_access_token_id
 * @property int $vault_id
 * @property int|null $parent_id
 * @property int|null $attachment_id
 * @property string $file_name
 * @property int $expected_bytes
 * @property string|null $expected_sha256
 * @property string $token_hash
 * @property string $status
 * @property string|null $temp_path
 * @property int|null $actual_bytes
 * @property string|null $actual_sha256
 * @property string|null $mime_type
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $uploaded_at
 * @property CarbonImmutable|null $completed_at
 */
final class McpAttachmentUpload extends Model
{
    use HasUuids;
    use Prunable;

    public const string PENDING = 'pending';

    public const string UPLOADING = 'uploading';

    public const string UPLOADED = 'uploaded';

    public const string COMPLETED = 'completed';

    public const string EXPIRED = 'expired';

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where(function (Builder $query): void {
            $query->where(function (Builder $query): void {
                $query->whereIn('status', [self::PENDING, self::UPLOADED, self::EXPIRED])
                    ->where('expires_at', '<=', now());
            })
                ->orWhere(function (Builder $query): void {
                    $query->where('status', self::UPLOADING)
                        ->where('expires_at', '<=', now()->subMinutes(5));
                })
                ->orWhere(function (Builder $query): void {
                    $query->where('status', self::COMPLETED)
                        ->where('completed_at', '<=', now()->subDay());
                });
        });
    }

    public function pruning(): void
    {
        if ($this->temp_path !== null) {
            Storage::disk('local')->delete($this->temp_path);
        }
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'personal_access_token_id' => 'integer',
            'vault_id' => 'integer',
            'parent_id' => 'integer',
            'attachment_id' => 'integer',
            'expected_bytes' => 'integer',
            'actual_bytes' => 'integer',
            'expires_at' => 'immutable_datetime',
            'uploaded_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
