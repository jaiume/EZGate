<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LoraKeys;
use PHPUnit\Framework\TestCase;

final class LoraKeysTest extends TestCase
{
    public function testWrapAndUnwrapSiteKey(): void
    {
        $cred = LoraKeys::randomHex();
        $site = LoraKeys::randomHex();
        $wrapped = LoraKeys::wrapSiteKey($cred, $site);
        $this->assertNotNull($wrapped);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{88}$/', $wrapped);
        $this->assertSame($site, LoraKeys::unwrapSiteKey($cred, $wrapped));
    }

    public function testRejectsWrongCredential(): void
    {
        $wrapped = LoraKeys::wrapSiteKey(LoraKeys::randomHex(), LoraKeys::randomHex());
        $this->assertNull(LoraKeys::unwrapSiteKey(LoraKeys::randomHex(), (string) $wrapped));
    }
}
