<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanSync\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\FileScannerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ScanVerdict;

/**
 * A scanner at its PORT, with the verdict chosen by the test.
 *
 * It records the path it was asked about and the bytes it found there, so a test
 * can prove the scanner saw exactly the bytes that were then stored. The real
 * ClamAvScanner needs a running clamd and is NOT exercised by this suite.
 */
final class FakeScanner implements FileScannerPort
{
    /** @var list<string> */
    public array $paths = [];

    /** @var list<string> */
    public array $seen = [];

    private ?ScanVerdict $verdict = null;

    public function answer(ScanVerdict $verdict): void
    {
        $this->verdict = $verdict;
    }

    public function scan(string $path): ScanVerdict
    {
        $this->paths[] = $path;
        $this->seen[]  = (string) file_get_contents($path);

        return $this->verdict ?? ScanVerdict::clean();
    }

    public function count(): int
    {
        return count($this->paths);
    }
}
