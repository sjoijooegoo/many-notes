<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VaultNodeType;
use App\Services\VaultFiles\Types\Audio;
use App\Services\VaultFiles\Types\Image;
use App\Services\VaultFiles\Types\Note;
use App\Services\VaultFiles\Types\Pdf;
use App\Services\VaultFiles\Types\Video;
use Carbon\CarbonImmutable;
use Database\Factories\VaultNodeFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;
use Override;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships;

/**
 * @property-read int $id
 * @property-read int $vault_id
 * @property-read int|null $parent_id
 * @property-read bool $is_file
 * @property-read string $name
 * @property-read string|null $extension
 * @property-read string|null $mime_type
 * @property-read string $full_path
 * @property-read string|null $content
 * @property int $revision
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable $updated_at
 * @property-read Vault $vault
 * @property-read Collection<int, VaultNode> $childs
 * @property-read Collection<int, VaultNode> $links
 * @property-read Collection<int, VaultNode> $backlinks
 * @property-read Collection<int, Tag> $tags
 */
final class VaultNode extends Model
{
    /** @use HasFactory<VaultNodeFactory> */
    use HasFactory;

    use HasRecursiveRelationships;
    use Searchable;

    /** @return BelongsTo<Vault, $this> */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /** @return HasMany<VaultNode, $this> */
    public function childs(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsToMany<VaultNode, $this> */
    public function links(): BelongsToMany
    {
        return $this->belongsToMany(self::class, null, 'source_id', 'destination_id')
            ->withPivot('position');
    }

    /** @return BelongsToMany<VaultNode, $this> */
    public function backlinks(): BelongsToMany
    {
        return $this->belongsToMany(self::class, null, 'destination_id', 'source_id')
            ->withPivot('position');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, null, 'vault_node_id', 'tag_id')
            ->withPivot('position');
    }

    public function isTemplate(): bool
    {
        return $this->vault->templates_node_id !== null
            && $this->parent_id !== null
            && $this->is_file === true
            && $this->extension === 'md'
            /** @phpstan-ignore-next-line larastan.noUnnecessaryCollectionCall */
            && $this->ancestors()->get()->contains($this->vault->templates_node_id);
    }

    public function type(): VaultNodeType
    {
        if (!$this->is_file) {
            return VaultNodeType::FOLDER;
        }

        $extension = $this->extension ?? '';

        if ($this->mime_type !== null) {
            return match (true) {
                $extension === 'md' && $this->content !== null => VaultNodeType::NOTE,
                Audio::validate($extension, $this->mime_type) => VaultNodeType::AUDIO,
                Image::validate($extension, $this->mime_type) => VaultNodeType::IMAGE,
                Pdf::validate($extension, $this->mime_type) => VaultNodeType::PDF,
                Video::validate($extension, $this->mime_type) => VaultNodeType::VIDEO,
                $this->content !== null => VaultNodeType::TEXT,
                default => VaultNodeType::FILE,
            };
        }

        // Legacy nodes predate MIME tracking and retain extension-based previews.
        return match (true) {
            in_array($extension, Audio::extensions()) => VaultNodeType::AUDIO,
            in_array($extension, Note::extensions()) => VaultNodeType::NOTE,
            in_array($extension, Image::extensions()) => VaultNodeType::IMAGE,
            in_array($extension, Pdf::extensions()) => VaultNodeType::PDF,
            in_array($extension, Video::extensions()) => VaultNodeType::VIDEO,
            $this->content !== null => VaultNodeType::TEXT,
            default => VaultNodeType::FILE,
        };
    }

    public function isEditableText(): bool
    {
        return in_array($this->type(), [VaultNodeType::NOTE, VaultNodeType::TEXT], true);
    }

    public function fullPath(): string
    {
        return (string) $this->ancestorsAndSelf()->orderBy('depth')->first()?->full_path;
    }

    /** @return list<array{name: string, column: string, separator: string, reverse: bool}> */
    public function getCustomPaths(): array
    {
        return [
            [
                'name' => 'full_path',
                'column' => 'name',
                'separator' => '/',
                'reverse' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'vault_id' => (string) $this->vault_id,
            'name' => $this->name,
            'content' => (string) $this->content,
            'updated_at' => $this->updated_at->timestamp,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return (bool) $this->is_file;
    }

    #[Override]
    protected static function booted(): void
    {
        self::creating(function (self $node): void {
            $node->revision ??= 1;
        });

        self::updating(function (self $node): void {
            if (!$node->isDirty('revision')) {
                $originalRevision = $node->getOriginal('revision');
                $node->revision = match (true) {
                    is_int($originalRevision) => $originalRevision + 1,
                    is_string($originalRevision) && ctype_digit($originalRevision) => (int) $originalRevision + 1,
                    default => 1,
                };
            }
        });
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'is_file' => 'boolean',
            'revision' => 'integer',
        ];
    }
}
