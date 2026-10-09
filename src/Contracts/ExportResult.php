<?php

namespace Anantrp\Refract\Contracts;

enum ExportResult: string
{
    case Ok = 'ok';
    case Retryable = 'retryable';
    case Rejected = 'rejected';
}
