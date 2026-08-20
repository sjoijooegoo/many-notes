<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Enums\VaultNodeType;
use App\Models\VaultNode;

final readonly class McpNodeReferenceFormatter
{
    public function type(VaultNode $node): string
    {
        return match ($node->type()) {
            VaultNodeType::NOTE => 'document',
            default => $node->type()->value,
        };
    }

    public function path(VaultNode $node): string
    {
        $extension = $node->is_file && $node->extension ? ".{$node->extension}" : '';

        return "/{$node->fullPath()}{$extension}";
    }

    /**
     * @return array{
     *   path: string,
     *   link: string,
     *   embed: string|null,
     *   recommended: string,
     * }
     */
    public function reference(VaultNode $node): array
    {
        $label = $this->escapeLabel($node->name);
        $path = $this->path($node);
        $markdownPath = $this->escapeDestination($path);
        $link = "[{$label}]({$markdownPath})";
        $embed = $node->type() === VaultNodeType::IMAGE
            ? "![{$label}]({$markdownPath})"
            : null;

        return [
            'path' => $path,
            'link' => $link,
            'embed' => $embed,
            'recommended' => $embed ?? $link,
        ];
    }

    private function escapeLabel(string $label): string
    {
        return str_replace(
            ['\\', '[', ']'],
            ['\\\\', '\\[', '\\]'],
            $label,
        );
    }

    private function escapeDestination(string $path): string
    {
        return preg_replace_callback(
            '/[^\pL\pN\pM\/._~-]+/u',
            static fn(array $matches): string => rawurlencode($matches[0]),
            $path,
        ) ?? $path;
    }
}
