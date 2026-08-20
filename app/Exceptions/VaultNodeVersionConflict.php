<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class VaultNodeVersionConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The vault node changed before the update could be saved.');
    }
}
