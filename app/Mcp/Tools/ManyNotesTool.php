<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\McpVaultAccess;
use App\Models\Vault;
use App\Models\VaultNode;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

abstract class ManyNotesTool extends Tool
{
    public function __construct(protected McpVaultAccess $access)
    {
        //
    }

    protected function authorizedVault(Request $request, int $vaultId, string $ability): Vault|Response
    {
        $vault = $this->access->vault($request, $vaultId, $ability);

        return $vault ?? Response::error('Access denied for this vault.');
    }

    protected function markdownDocument(Vault $vault, int $documentId): VaultNode|Response
    {
        $document = $this->access->document($vault, $documentId);

        return $document ?? Response::error('Markdown document not found in this vault.');
    }

    protected function parentFolder(Vault $vault, ?int $folderId): VaultNode|null|Response
    {
        if ($folderId === null) {
            return null;
        }

        $folder = $this->access->folder($vault, $folderId);

        return $folder ?? Response::error('Parent folder not found in this vault.');
    }

    protected function noteName(string $name): string
    {
        return preg_replace('/\.md$/i', '', mb_trim($name)) ?? mb_trim($name);
    }

    /** @param array<string, mixed> $data */
    protected function intValue(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException("{$key} must be an integer.");
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $data */
    protected function nullableIntValue(array $data, string $key): ?int
    {
        return isset($data[$key]) ? $this->intValue($data, $key) : null;
    }

    /** @param array<string, mixed> $data */
    protected function stringValue(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;

        if (!is_string($value)) {
            throw new InvalidArgumentException("{$key} must be a string.");
        }

        return $value;
    }
}
