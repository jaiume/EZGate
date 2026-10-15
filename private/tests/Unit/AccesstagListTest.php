<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AccesstagList;
use PHPUnit\Framework\TestCase;

final class AccesstagListTest extends TestCase
{
    public function testHashMatchesTheDocumentedExamples(): void
    {
        $this->assertSame(
            'aaa2b6a1bd052dd558f640c8e84d74ec53a2ad4c89c206198224ef762483917f',
            AccesstagList::hash(['1002', '1001'])
        );
        $this->assertSame(
            'd7d62d8d74bc7a7305ab86700de0c8dba68b22c705ebb7b5f8249f57e3173e48',
            AccesstagList::hash(['1003', '1002', '1002'])
        );
        $this->assertSame(hash('sha256', ''), AccesstagList::hash([]));
    }

    public function testChangesOmitUnchangedNumbersAndSortRemovalsFirst(): void
    {
        $changes = AccesstagList::changes(['1001', '1002'], ['1002', '1003'], false);
        $this->assertSame([
            ['op' => 'remove', 'accesstag' => '1001'],
            ['op' => 'add', 'accesstag' => '1003'],
        ], $changes);
    }

    public function testReplaceSendsEveryPushedNumberAsAnAdd(): void
    {
        $changes = AccesstagList::changes(['1001'], ['1003', '1002'], true);
        $this->assertSame([
            ['op' => 'add', 'accesstag' => '1002'],
            ['op' => 'add', 'accesstag' => '1003'],
        ], $changes);
    }

    public function testShouldReplaceOnNullVersionHashMismatchOrFull(): void
    {
        $hash = AccesstagList::hash(['1001']);
        $this->assertFalse(AccesstagList::shouldReplace('3', $hash, '3', $hash, false));
        $this->assertTrue(AccesstagList::shouldReplace(null, null, '3', $hash, false));
        $this->assertTrue(AccesstagList::shouldReplace('2', $hash, '3', $hash, false));
        $this->assertTrue(AccesstagList::shouldReplace('3', str_repeat('a', 64), '3', $hash, false));
        $this->assertTrue(AccesstagList::shouldReplace('3', $hash, '3', $hash, true));
    }
}
