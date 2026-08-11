<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Dummy;

final class DummyTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function dummyIsRetrievableFromDependencyInjectionContainer(): void
    {
        $this->assertInstanceOf(Dummy::class, $this->get(Dummy::class));
    }
}
