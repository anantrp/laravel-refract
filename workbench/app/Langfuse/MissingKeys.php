<?php

namespace Workbench\App\Langfuse;

use RuntimeException;

class MissingKeys extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Langfuse keys missing. Set LANGFUSE_PUBLIC_KEY and LANGFUSE_SECRET_KEY in workbench/.env.');
    }
}
