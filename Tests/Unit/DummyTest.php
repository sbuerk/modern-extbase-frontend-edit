<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Dummy;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DummyTest extends UnitTestCase
{
    #[Test]
    public function getExtensionKeyReturnsExtensionKey(): void
    {
        $this->assertSame('modern_extbase_frontend_edit', (new Dummy())->getExtensionKey());
    }
}
