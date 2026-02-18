<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Replacer;

interface ReplacerInterface
{
    /**
     * Apply replacements to file contents.
     *
     * @param string $contents The file contents to process
     * @return string The modified contents
     */
    public function replace(string $contents): string;
}
