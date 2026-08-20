<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\UpdateVaultNode;
use App\Exceptions\VaultNodeVersionConflict;
use App\Mcp\Support\McpVaultAccess;
use App\Models\VaultNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Override;

#[IsOpenWorld(false)]
final class EditDocumentTool extends ManyNotesTool
{
    private const int MAX_CONTENT_LENGTH = 2000000;

    protected string $name = 'edit_document';

    protected string $description =
        'Edit a small part of a Markdown document without uploading the complete file. ' .
        'Use exact_text to replace one unique text block. ' .
        'Use heading_section to replace the body below one unique heading. ' .
        'Exact-text edits can safely rebase over unrelated concurrent changes.';

    public function __construct(McpVaultAccess $access, private readonly UpdateVaultNode $updateVaultNode)
    {
        parent::__construct($access);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'vault_id' => $schema->integer()->description('Vault ID returned by list_vaults.')->required(),
            'document_id' => $schema->integer()->description('Document ID returned by get_document.')->required(),
            'expected_revision' => $schema->integer()
                ->min(1)
                ->description('Exact revision returned by get_document.')
                ->required(),
            'expected_content_hash' => $schema->string()
                ->description('Optional SHA-256 content_hash returned by get_document.'),
            'mode' => $schema->string()
                ->enum(['exact_text', 'heading_section'])
                ->description('How target is located in the document.')
                ->required(),
            'target' => $schema->string()
                ->min(1)
                ->max(500000)
                ->description('Exact old text, or heading text with or without leading # characters.')
                ->required(),
            'replacement' => $schema->string()
                ->max(self::MAX_CONTENT_LENGTH)
                ->description('Replacement text, or the complete new body below the heading.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'vault_id' => ['required', 'integer'],
            'document_id' => ['required', 'integer'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'expected_content_hash' => ['nullable', 'string', 'size:64', 'regex:/^[a-fA-F0-9]{64}$/'],
            'mode' => ['required', 'string', 'in:exact_text,heading_section'],
            'target' => ['required', 'string', 'min:1', 'max:500000'],
            'replacement' => ['present', 'string', 'max:' . self::MAX_CONTENT_LENGTH],
        ]);
        $vault = $this->authorizedVault($request, $this->intValue($data, 'vault_id'), McpVaultAccess::WRITE);

        if ($vault instanceof Response) {
            return $vault;
        }

        $documentId = $this->intValue($data, 'document_id');
        $expectedRevision = $this->intValue($data, 'expected_revision');
        $mode = $this->stringValue($data, 'mode');
        $target = $this->stringValue($data, 'target');
        $replacement = $this->stringValue($data, 'replacement');
        $expectedContentHash = isset($data['expected_content_hash'])
            ? mb_strtolower($this->stringValue($data, 'expected_content_hash'))
            : null;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $document = $this->markdownDocument($vault, $documentId);

            if ($document instanceof Response) {
                return $document;
            }

            $content = $document->content ?? '';
            $isStale = $document->revision !== $expectedRevision;

            if ($expectedRevision > $document->revision) {
                return $this->versionConflict($document, ['reason' => 'expected_revision_is_newer']);
            }

            if (
                !$isStale
                && $expectedContentHash !== null
                && !hash_equals($expectedContentHash, hash('sha256', $content))
            ) {
                return $this->versionConflict($document, ['reason' => 'content_hash_mismatch']);
            }

            if ($mode === 'heading_section' && $isStale) {
                return $this->versionConflict($document, $this->headingConflictDetails($content, $target));
            }

            $edit = $mode === 'exact_text'
                ? $this->replaceExactText($content, $target, $replacement)
                : $this->replaceHeadingSection($content, $target, $replacement);

            if ($edit['error'] !== null) {
                if ($isStale) {
                    return $this->versionConflict($document, [
                        'target_status' => $edit['error'],
                        'target_match_count' => $edit['match_count'],
                    ]);
                }

                $message = $edit['error'] === 'target_not_found'
                    ? 'The edit target was not found in the current document.'
                    : 'The edit target matched more than once; provide a larger unique target.';

                return $this->structuredError($edit['error'], $message, [
                    'current_revision' => $document->revision,
                    'target_match_count' => $edit['match_count'],
                ]);
            }

            if (mb_strlen($edit['content']) > self::MAX_CONTENT_LENGTH) {
                return $this->structuredError(
                    'content_too_large',
                    'The edited document would exceed the 2,000,000 character limit.',
                );
            }

            $previousRevision = $document->revision;

            try {
                $document = $this->updateVaultNode->handle(
                    $document,
                    ['content' => $edit['content']],
                    $previousRevision,
                );
            } catch (VaultNodeVersionConflict) {
                continue;
            }

            return Response::structured([
                'document' => $this->access->documentData($document),
                'edit' => [
                    'mode' => $mode,
                    'changed' => $document->revision !== $previousRevision,
                    'rebased' => $isStale,
                    'expected_revision' => $expectedRevision,
                    'applied_to_revision' => $previousRevision,
                ],
            ]);
        }

        $document = $this->access->document($vault, $documentId);

        return $document instanceof VaultNode
            ? $this->versionConflict($document, ['reason' => 'concurrent_update_during_edit'])
            : Response::error('Markdown document not found in this vault.');
    }

    /** @return array{content: string, error: string|null, match_count: int} */
    private function replaceExactText(string $content, string $target, string $replacement): array
    {
        $matchCount = mb_substr_count($content, $target);

        if ($matchCount !== 1) {
            return [
                'content' => $content,
                'error' => $matchCount === 0 ? 'target_not_found' : 'ambiguous_target',
                'match_count' => $matchCount,
            ];
        }

        $position = mb_strpos($content, $target);

        return [
            'content' => mb_substr($content, 0, (int) $position)
                . $replacement
                . mb_substr($content, (int) $position + mb_strlen($target)),
            'error' => null,
            'match_count' => 1,
        ];
    }

    /** @return array{content: string, error: string|null, match_count: int} */
    private function replaceHeadingSection(string $content, string $target, string $replacement): array
    {
        $matches = $this->headingMatches($content, $target);
        $matchCount = count($matches);

        if ($matchCount !== 1) {
            return [
                'content' => $content,
                'error' => $matchCount === 0 ? 'target_not_found' : 'ambiguous_target',
                'match_count' => $matchCount,
            ];
        }

        $match = $matches[0];
        $newline = str_contains($content, "\r\n") ? "\r\n" : "\n";

        if ($replacement !== '' && $match['end'] < mb_strlen($content) && !preg_match('/\R$/', $replacement)) {
            $replacement .= $newline;
        }

        return [
            'content' => mb_substr($content, 0, $match['body_start'])
                . $replacement
                . mb_substr($content, $match['end']),
            'error' => null,
            'match_count' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function headingConflictDetails(string $content, string $target): array
    {
        $matches = $this->headingMatches($content, $target);
        $details = [
            'target_status' => count($matches) === 1
                ? 'found_but_not_safe_to_rebase'
                : (count($matches) === 0 ? 'target_not_found' : 'ambiguous_target'),
            'target_match_count' => count($matches),
        ];

        if (count($matches) === 1) {
            $body = mb_substr($content, $matches[0]['body_start'], $matches[0]['end'] - $matches[0]['body_start']);
            $details['current_section'] = mb_substr($body, 0, 50000);
            $details['current_section_truncated'] = mb_strlen($body) > 50000;
        }

        return $details;
    }

    /** @return list<array{level: int, body_start: int, end: int}> */
    private function headingMatches(string $content, string $target): array
    {
        $target = preg_replace('/^#{1,6}[ \t]+/', '', mb_trim($target)) ?? mb_trim($target);
        $target = preg_replace('/[ \t]+#+[ \t]*$/', '', $target) ?? $target;
        $lines = preg_split('/(?<=\n)/', $content) ?: [$content];
        $headings = [];
        $offset = 0;
        $fenceCharacter = null;
        $fenceLength = 0;

        foreach ($lines as $line) {
            $lineWithoutEnding = mb_rtrim($line, "\r\n");

            if (preg_match('/^[ \t]{0,3}(`{3,}|~{3,})/', $lineWithoutEnding, $fenceMatch) === 1) {
                $marker = $fenceMatch[1];
                $character = $marker[0];

                if ($fenceCharacter === null) {
                    $fenceCharacter = $character;
                    $fenceLength = mb_strlen($marker);
                } elseif ($character === $fenceCharacter && mb_strlen($marker) >= $fenceLength) {
                    $fenceCharacter = null;
                    $fenceLength = 0;
                }

                $offset += mb_strlen($line);

                continue;
            }

            if (
                $fenceCharacter === null
                && preg_match('/^[ \t]{0,3}(#{1,6})[ \t]+(.+?)[ \t]*$/', $lineWithoutEnding, $headingMatch) === 1
            ) {
                $text = preg_replace('/[ \t]+#+[ \t]*$/', '', $headingMatch[2]) ?? $headingMatch[2];
                $headings[] = [
                    'level' => mb_strlen($headingMatch[1]),
                    'text' => $text,
                    'body_start' => $offset + mb_strlen($line),
                    'line_start' => $offset,
                ];
            }

            $offset += mb_strlen($line);
        }

        $matches = [];

        foreach ($headings as $index => $heading) {
            if ($heading['text'] !== $target) {
                continue;
            }

            $end = mb_strlen($content);

            for ($nextIndex = $index + 1, $count = count($headings); $nextIndex < $count; $nextIndex++) {
                if ($headings[$nextIndex]['level'] <= $heading['level']) {
                    $end = $headings[$nextIndex]['line_start'];

                    break;
                }
            }

            $matches[] = [
                'level' => $heading['level'],
                'body_start' => $heading['body_start'],
                'end' => $end,
            ];
        }

        return $matches;
    }
}
