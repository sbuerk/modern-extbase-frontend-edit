<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Unit\Domain\Model;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Address;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AddressTest extends UnitTestCase
{
    /**
     * The `type` default is the one default that is stated three times.
     *
     * It is `'others'` in the TCA, pinned to `DEFAULT 'others'` in
     * `ext_tables.sql` because the auto-generated definition of a
     * `type=select` column differs between TYPO3 v13 and v14, and repeated
     * here so a child created in PHP is not the odd one out. The assertion
     * exists to catch the three drifting apart.
     */
    #[Test]
    public function freshAddressCarriesTheDocumentedDefaults(): void
    {
        $subject = new Address();

        $this->assertSame('others', $subject->getType());
        $this->assertSame('', $subject->getLine1());
        $this->assertSame('', $subject->getLine2());
        $this->assertFalse($subject->isHidden());
    }

    #[Test]
    public function propertiesAreReadBackThroughTheirAccessors(): void
    {
        $subject = new Address();
        $subject->setType('work');
        $subject->setLine1('Example Street 1');
        $subject->setLine2('12345 Example City');
        $subject->setHidden(true);

        $this->assertSame('work', $subject->getType());
        $this->assertSame('Example Street 1', $subject->getLine1());
        $this->assertSame('12345 Example City', $subject->getLine2());
        $this->assertTrue($subject->isHidden());
    }
}
