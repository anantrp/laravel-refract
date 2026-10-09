<?php

namespace Anantrp\Refract\Tests\Support\Ai;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * An uploaded file that keeps every call Refract makes to a method that reads, names or describes it.
 */
class TrapUpload extends UploadedFile
{
    public function __construct()
    {
        parent::__construct(__FILE__, 'TRAP-UPLOAD.pdf', 'application/x-trap', null, true);
    }

    public function getClientOriginalName(): string
    {
        TrapImage::called('getClientOriginalName');

        return 'TRAP-UPLOAD.pdf';
    }

    public function getClientMimeType(): string
    {
        TrapImage::called('getClientMimeType');

        return 'application/x-trap';
    }

    public function getMimeType(): ?string
    {
        TrapImage::called('getMimeType');

        return 'application/x-trap';
    }

    public function getSize(): int|false
    {
        TrapImage::called('getSize');

        return 4242;
    }

    public function getContent(): string
    {
        TrapImage::called('getContent');

        return 'TRAP-BYTES';
    }

    public function __toString(): string
    {
        TrapImage::called('__toString');

        return 'TRAP-PATH';
    }
}
