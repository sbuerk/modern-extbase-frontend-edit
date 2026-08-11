<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Unit\Domain\Model;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Address;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Email;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\ProfileImage;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference as CoreFileReference;
use TYPO3\CMS\Extbase\Domain\Model\FileReference as ExtbaseFileReference;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ProfileTest extends UnitTestCase
{
    #[Test]
    public function freshProfileCarriesTheDocumentedDefaults(): void
    {
        $subject = new Profile();

        $this->assertSame('', $subject->getShortname());
        $this->assertSame('', $subject->getFirstname());
        $this->assertSame('', $subject->getLastname());
        $this->assertNull($subject->getImage());
        $this->assertNull($subject->getBirthday());
        $this->assertSame(0, $subject->getFeUser());
        $this->assertFalse($subject->isHidden());
    }

    /**
     * Pins the `''` invariant of the biography, which the schema cannot pin.
     *
     * The database column is a nullable `longtext`, because MySQL rejects a
     * literal `DEFAULT` on a `TEXT` column and Doctrine emits one for every
     * string-ish type, so `ext_tables.sql` has no default to give. The model is
     * the only place the invariant can live, which makes it a contract rather
     * than a coincidence: a non-nullable `string` property, defaulting to `''`,
     * behind a non-nullable `string` getter.
     *
     * The reflection assertions are the point of this test. Turning the
     * property into `?string` to "match the column" would keep every ordinary
     * getter and setter test green while handing `null` to every consumer that
     * was promised a string.
     */
    #[Test]
    public function bioDefaultsToAnEmptyStringAndIsNeverNullable(): void
    {
        $this->assertSame('', (new Profile())->getBio());

        $property = new \ReflectionProperty(Profile::class, 'bio');
        $propertyType = $property->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $propertyType);
        $this->assertSame('string', $propertyType->getName());
        $this->assertFalse($propertyType->allowsNull());
        $this->assertSame('', $property->getDefaultValue());

        $returnType = (new \ReflectionMethod(Profile::class, 'getBio'))->getReturnType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $returnType);
        $this->assertSame('string', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());
    }

    #[Test]
    public function scalarPropertiesAreReadBackThroughTheirAccessors(): void
    {
        $birthday = new \DateTimeImmutable('1975-03-17');
        $image = $this->createMock(ExtbaseFileReference::class);

        $subject = new Profile();
        $subject->setShortname('jdoe');
        $subject->setFirstname('Jane');
        $subject->setLastname('Doe');
        $subject->setImage($image);
        $subject->setBirthday($birthday);
        $subject->setBio('Writes about databases.');
        $subject->setFeUser(12);
        $subject->setHidden(true);

        $this->assertSame('jdoe', $subject->getShortname());
        $this->assertSame('Jane', $subject->getFirstname());
        $this->assertSame('Doe', $subject->getLastname());
        $this->assertSame($image, $subject->getImage());
        $this->assertSame($birthday, $subject->getBirthday());
        $this->assertSame('Writes about databases.', $subject->getBio());
        $this->assertSame(12, $subject->getFeUser());
        $this->assertTrue($subject->isHidden());
    }

    #[Test]
    public function collectionsAreInitializedAndEmptyOnConstruction(): void
    {
        $subject = new Profile();

        $this->assertCount(0, $subject->getAddresses());
        $this->assertCount(0, $subject->getEmails());
    }

    /**
     * The data mapper never calls the constructor.
     *
     * `DataMapper::createEmptyObject()` instantiates the model without invoking
     * the constructor and then calls `initializeObject()` before assigning the
     * mapped properties, so a typed collection property left uninitialized here
     * fatals on first access — for every record read from the database, and for
     * none created in a test by hand. The instance below is built the same way
     * the data mapper builds one.
     */
    #[Test]
    public function initializeObjectInitializesTheCollectionsForDataMapperCreatedInstances(): void
    {
        $subject = (new \ReflectionClass(Profile::class))->newInstanceWithoutConstructor();
        $subject->initializeObject();

        $this->assertCount(0, $subject->getAddresses());
        $this->assertCount(0, $subject->getEmails());
    }

    #[Test]
    public function addAddressAttachesAndRemoveAddressDetachesTheChild(): void
    {
        $kept = new Address();
        $removed = new Address();

        $subject = new Profile();
        $subject->addAddress($kept);
        $subject->addAddress($removed);
        $this->assertCount(2, $subject->getAddresses());

        $subject->removeAddress($removed);

        $this->assertCount(1, $subject->getAddresses());
        $this->assertTrue($subject->getAddresses()->contains($kept));
        $this->assertFalse($subject->getAddresses()->contains($removed));
    }

    #[Test]
    public function addEmailAttachesAndRemoveEmailDetachesTheChild(): void
    {
        $kept = new Email();
        $removed = new Email();

        $subject = new Profile();
        $subject->addEmail($kept);
        $subject->addEmail($removed);
        $this->assertCount(2, $subject->getEmails());

        $subject->removeEmail($removed);

        $this->assertCount(1, $subject->getEmails());
        $this->assertTrue($subject->getEmails()->contains($kept));
        $this->assertFalse($subject->getEmails()->contains($removed));
    }

    /**
     * The address getter hands out the live storage, not a copy.
     *
     * This is a requirement of the reorder path, not an implementation detail.
     * `ObjectStorage` has no `sort()`, `move()` or `setOrder()`, and `attach()`
     * on an already contained object updates the element in place without
     * changing the iteration order, so the only way to reorder a collection is
     * to detach every member and re-attach them in the target order — on the
     * very instance the model holds. A getter returning a clone or an array
     * would take that reordering and drop it on the floor: the code would run
     * without error, the collection would look sorted to whoever did the
     * sorting, and the database would keep the old order. Nothing about that
     * failure is loud, which is why it is pinned here.
     */
    #[Test]
    public function getAddressesReturnsTheLiveCollectionNotACopy(): void
    {
        /** @var ObjectStorage<Address> $storage */
        $storage = new ObjectStorage();
        $subject = new Profile();
        $subject->setAddresses($storage);

        $this->assertSame($storage, $subject->getAddresses());

        // A mutation on the handed out storage reaches the model.
        $storage->attach(new Address());
        $this->assertCount(1, $subject->getAddresses());

        // And a mutation on the getter result survives the next call.
        $subject->getAddresses()->attach(new Address());
        $this->assertCount(2, $subject->getAddresses());
    }

    /**
     * The email getter hands out the live storage — see
     * {@see getAddressesReturnsTheLiveCollectionNotACopy()} for why that is a
     * requirement of the reorder path rather than a detail.
     */
    #[Test]
    public function getEmailsReturnsTheLiveCollectionNotACopy(): void
    {
        /** @var ObjectStorage<Email> $storage */
        $storage = new ObjectStorage();
        $subject = new Profile();
        $subject->setEmails($storage);

        $this->assertSame($storage, $subject->getEmails());

        $storage->attach(new Email());
        $this->assertCount(1, $subject->getEmails());

        $subject->getEmails()->attach(new Email());
        $this->assertCount(2, $subject->getEmails());
    }

    /**
     * No image means `null`, not a null object.
     *
     * The alternative — a `ProfileImage` with meaningless scalars — was
     * rejected because a template rendering `{profile.profileImage.publicUrl}`
     * without a guard would then emit an empty `src` instead of nothing at all.
     */
    #[Test]
    public function getProfileImageReturnsNullWhenNoImageIsSet(): void
    {
        $this->assertNull((new Profile())->getProfileImage());
    }

    /**
     * The value object is derived on every call and never stored.
     *
     * Two calls therefore yield equal but distinct instances. If one day the
     * result is cached in a property, that property is persisted state on an
     * Extbase model and needs `#[Transient]` — which this design deliberately
     * avoids, because no attribute at all cannot be spelled in a version
     * specific way by accident.
     */
    #[Test]
    public function getProfileImageIsDerivedFromTheFileReferenceOnEveryCall(): void
    {
        $subject = new Profile();
        $subject->setImage($this->createExtbaseFileReference());

        $first = $subject->getProfileImage();
        $second = $subject->getProfileImage();

        $this->assertInstanceOf(ProfileImage::class, $first);
        $this->assertSame(42, $first->uid);
        $this->assertSame('portrait.jpg', $first->name);
        $this->assertEquals($first, $second);
        $this->assertNotSame($first, $second);
    }

    private function createExtbaseFileReference(): ExtbaseFileReference
    {
        $originalFile = $this->createMock(File::class);
        $originalFile->method('getUid')->willReturn(7);

        $resource = $this->createMock(CoreFileReference::class);
        $resource->method('getUid')->willReturn(42);
        $resource->method('getOriginalFile')->willReturn($originalFile);
        $resource->method('getPublicUrl')->willReturn('/fileadmin/user_upload/portrait.jpg');
        $resource->method('getName')->willReturn('portrait.jpg');
        $resource->method('getExtension')->willReturn('jpg');
        $resource->method('getMimeType')->willReturn('image/jpeg');
        $resource->method('getSize')->willReturn(4711);
        $resource->method('getTitle')->willReturn('Portrait');
        $resource->method('getAlternative')->willReturn('A portrait of Jane Doe');
        $resource->method('hasProperty')->willReturn(true);
        $resource->method('getProperty')->willReturnMap([
            ['width', 800],
            ['height', 600],
        ]);

        $fileReference = $this->createMock(ExtbaseFileReference::class);
        $fileReference->method('getOriginalResource')->willReturn($resource);

        return $fileReference;
    }
}
