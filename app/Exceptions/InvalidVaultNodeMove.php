<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class InvalidVaultNodeMove extends RuntimeException
{
    public const string PARENT_NOT_FOUND = 'parent_not_found';

    public const string PARENT_IS_FILE = 'parent_is_file';

    public const string SELF_PARENT = 'self_parent';

    public const string DESCENDANT_PARENT = 'descendant_parent';

    public const string NAME_CONFLICT = 'name_conflict';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->reason === self::PARENT_NOT_FOUND ? 404 : 422;
    }
}
