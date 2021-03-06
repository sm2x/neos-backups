<?php

declare(strict_types=1);

namespace Breadlesscode\Backups\Compressor;

interface BackupCompressorInterface
{
    public function compress(string $source, string $targetFolder): ?string;
    public function decompress(string $source, string $targetFolder): ?string;
    public function generateFilename($name): string;
}
