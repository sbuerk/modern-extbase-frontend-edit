<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Unit\Domain\Model;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Email;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class EmailTest extends UnitTestCase
{
    /**
     * The `type` default is the one default that is stated three times — see
     * {@see AddressTest::freshAddressCarriesTheDocumentedDefaults()}.
     */
    #[Test]
    public function freshEmailCarriesTheDocumentedDefaults(): void
    {
        $subject = new Email();

        $this->assertSame('others', $subject->getType());
        $this->assertSame('', $subject->getEmail());
        $this->assertFalse($subject->isHidden());
    }

    /**
     * The address is stored verbatim.
     *
     * There is no `#[Validate]` on the property and no normalisation in the
     * setter: the attribute has no spelling that is valid on TYPO3 v13 and free
     * of deprecations on v14, so validation is carried as data by a rule set in
     * front of the model. The model itself accepts what it is given, which this
     * test states rather than discovers.
     */
    #[Test]
    public function propertiesAreReadBackThroughTheirAccessors(): void
    {
        $subject = new Email();
        $subject->setType('work');
        $subject->setEmail('Jane.Doe@Example.COM');
        $subject->setHidden(true);

        $this->assertSame('work', $subject->getType());
        $this->assertSame('Jane.Doe@Example.COM', $subject->getEmail());
        $this->assertTrue($subject->isHidden());
    }
}
