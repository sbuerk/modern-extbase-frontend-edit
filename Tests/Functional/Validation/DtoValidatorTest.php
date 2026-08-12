<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Dto\AddressData;
use SBUERK\ModernExtbaseFrontendEdit\Dto\EmailData;
use SBUERK\ModernExtbaseFrontendEdit\Dto\ProfileData;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\AbstractFunctionalTestCase;
use SBUERK\ModernExtbaseFrontendEdit\Validation\AddressRuleSet;
use SBUERK\ModernExtbaseFrontendEdit\Validation\DtoValidator;
use SBUERK\ModernExtbaseFrontendEdit\Validation\EmailRuleSet;
use SBUERK\ModernExtbaseFrontendEdit\Validation\Exception\UnknownPropertyException;
use SBUERK\ModernExtbaseFrontendEdit\Validation\ProfileRuleSet;
use SBUERK\ModernExtbaseFrontendEdit\Validation\RuleSetInterface;
use TYPO3\CMS\Extbase\Error\Error;
use TYPO3\CMS\Extbase\Error\Result;
use TYPO3\CMS\Extbase\Validation\Validator\GenericObjectValidator;
use TYPO3\CMS\Extbase\Validation\Validator\NotEmptyValidator;

/**
 * Every rule of every rule set, in both modes, asserted on error codes.
 *
 * ## Why this is a functional test and not a unit test
 *
 * Adding a validation error is not a pure operation. Every message goes through
 * `AbstractValidator::translateErrorMessage()`, which calls
 * `LocalizationUtility::translate()`, which reaches
 * `Locales::createLocaleFromRequest()`, `LanguageServiceFactory` and the
 * runtime cache through `GeneralUtility::makeInstance()`. Without a container
 * the first `addError()` dies with an `ArgumentCountError`, so a bare
 * `UnitTestCase` cannot execute a single failing rule.
 *
 * The alternative was a stub container installed with
 * `GeneralUtility::setContainer()`. It was rejected on two grounds. It pins
 * three core internals — the signature of `createLocaleFromRequest()`, the one
 * of `LanguageServiceFactory::create()` and which cache identifier
 * `LocalizationUtility` asks for — in a repository that has to keep passing on
 * two core versions, and none of those are API. And a stub language service
 * returns a stubbed message, so the run would prove the rules fire while
 * silently accepting that every one of their message keys is broken — which is
 * precisely the bug `ValidationMessageTest` exists to catch, and it can only be
 * caught where the real XLIFF is loaded.
 *
 * Codes are asserted, never message text: the code is the stable contract of a
 * validator, the text is a translation.
 */
final class DtoValidatorTest extends AbstractFunctionalTestCase
{
    /**
     * The upper bound the `varchar(255)` input columns are validated against.
     */
    private const OVER_INPUT_COLUMN_LENGTH = 256;

    private DtoValidator $subject;

    protected function setUp(): void
    {
        parent::setUp();
        // Constructed rather than fetched from the container: the service is
        // stateless and carries no dependencies, and its container
        // registration is asserted by `ValidationWiringTest`.
        $this->subject = new DtoValidator();
    }

    #[Test]
    #[DataProvider('validPayloads')]
    public function aValidPayloadProducesNoErrors(RuleSetInterface $ruleSet, object $dto): void
    {
        $result = $this->subject->validate($ruleSet, $dto);

        $this->assertFalse($result->hasErrors());
        $this->assertSame([], $this->flattenedCodes($result));
    }

    /**
     * @return \Generator<string, array{ruleSet: RuleSetInterface, dto: object}>
     */
    public static function validPayloads(): \Generator
    {
        yield 'profile, every field filled' => [
            'ruleSet' => new ProfileRuleSet(),
            'dto' => new ProfileData(
                shortname: 'jdoe',
                firstname: 'John',
                lastname: 'Doe',
                birthday: '1980-05-17',
                bio: 'Hello.',
            ),
        ];
        yield 'profile, only the required field filled' => [
            'ruleSet' => new ProfileRuleSet(),
            'dto' => new ProfileData(shortname: 'jdoe'),
        ];
        yield 'address, every field filled' => [
            'ruleSet' => new AddressRuleSet(),
            'dto' => new AddressData(type: 'home', line1: 'Example Street 1', line2: '12345 Example City'),
        ];
        yield 'address, only the required field filled' => [
            'ruleSet' => new AddressRuleSet(),
            'dto' => new AddressData(line1: 'Example Street 1'),
        ];
        yield 'email, every field filled' => [
            'ruleSet' => new EmailRuleSet(),
            'dto' => new EmailData(type: 'business', email: 'john.doe@example.com'),
        ];
        yield 'email, only the required field filled' => [
            'ruleSet' => new EmailRuleSet(),
            'dto' => new EmailData(email: 'john.doe@example.com'),
        ];
    }

    /**
     * Full mode: one rule per case, so the expected map is asserted whole
     * rather than by key.
     *
     * @param array<string, list<int>> $expectedCodes
     */
    #[Test]
    #[DataProvider('payloadsViolatingOneRule')]
    public function fullValidationReportsTheViolatedRule(
        RuleSetInterface $ruleSet,
        object $dto,
        array $expectedCodes,
    ): void {
        $this->assertSame($expectedCodes, $this->flattenedCodes($this->subject->validate($ruleSet, $dto)));
    }

    /**
     * @return \Generator<string, array{ruleSet: RuleSetInterface, dto: object, expectedCodes: array<string, list<int>>}>
     */
    public static function payloadsViolatingOneRule(): \Generator
    {
        $tooLong = str_repeat('x', self::OVER_INPUT_COLUMN_LENGTH);

        yield 'profile: shortname is required' => [
            'ruleSet' => new ProfileRuleSet(),
            'dto' => new ProfileData(shortname: ''),
            'expectedCodes' => ['shortname' => [1221560718]],
        ];
        yield 'profile: shortname below the minimum' => [
            'ruleSet' => new ProfileRuleSet(),
            'dto' => new ProfileData(shortname: 'a'),
            'expectedCodes' => ['shortname' => [1428504122]],
        ];
        yield 'profile: shortname above the maximum' => [
            'ruleSet' => new ProfileRuleSet(),
            'dto' => new ProfileData(shortname: $tooLong),
            'expectedCodes' => ['shortname' => [1428504122]],
        ];
        yield 'profile: firstname above the maximum' => [
            'ruleSet' => new ProfileRuleSet(),
            'dto' => new ProfileData(shortname: 'jdoe', firstname: $tooLong),
            'expectedCodes' => ['firstname' => [1238108069]],
        ];
        yield 'profile: lastname above the maximum' => [
            'ruleSet' => new ProfileRuleSet(),
            'dto' => new ProfileData(shortname: 'jdoe', lastname: $tooLong),
            'expectedCodes' => ['lastname' => [1238108069]],
        ];
        yield 'profile: birthday is not a date' => [
            'ruleSet' => new ProfileRuleSet(),
            'dto' => new ProfileData(shortname: 'jdoe', birthday: 'yesterday'),
            'expectedCodes' => ['birthday' => [1786492305]],
        ];
        yield 'profile: birthday is a day that does not exist' => [
            'ruleSet' => new ProfileRuleSet(),
            'dto' => new ProfileData(shortname: 'jdoe', birthday: '2026-02-30'),
            'expectedCodes' => ['birthday' => [1786492305]],
        ];
        yield 'profile: bio above the payload limit' => [
            'ruleSet' => new ProfileRuleSet(),
            'dto' => new ProfileData(shortname: 'jdoe', bio: str_repeat('x', 5001)),
            'expectedCodes' => ['bio' => [1238108069]],
        ];

        yield 'address: type outside the accepted set' => [
            'ruleSet' => new AddressRuleSet(),
            'dto' => new AddressData(type: 'nope', line1: 'Example Street 1'),
            'expectedCodes' => ['type' => [1786492302]],
        ];
        yield 'address: type submitted empty' => [
            'ruleSet' => new AddressRuleSet(),
            'dto' => new AddressData(type: '', line1: 'Example Street 1'),
            'expectedCodes' => ['type' => [1786492302]],
        ];
        yield 'address: line1 is required' => [
            'ruleSet' => new AddressRuleSet(),
            'dto' => new AddressData(line1: ''),
            'expectedCodes' => ['line1' => [1221560718]],
        ];
        yield 'address: line1 above the maximum' => [
            'ruleSet' => new AddressRuleSet(),
            'dto' => new AddressData(line1: $tooLong),
            'expectedCodes' => ['line1' => [1238108069]],
        ];
        yield 'address: line2 above the maximum' => [
            'ruleSet' => new AddressRuleSet(),
            'dto' => new AddressData(line1: 'Example Street 1', line2: $tooLong),
            'expectedCodes' => ['line2' => [1238108069]],
        ];

        yield 'email: type outside the accepted set' => [
            'ruleSet' => new EmailRuleSet(),
            'dto' => new EmailData(type: 'nope', email: 'john.doe@example.com'),
            'expectedCodes' => ['type' => [1786492302]],
        ];
        yield 'email: type submitted empty' => [
            'ruleSet' => new EmailRuleSet(),
            'dto' => new EmailData(type: '', email: 'john.doe@example.com'),
            'expectedCodes' => ['type' => [1786492302]],
        ];
        yield 'email: address is required' => [
            'ruleSet' => new EmailRuleSet(),
            'dto' => new EmailData(email: ''),
            'expectedCodes' => ['email' => [1221560718]],
        ];
        yield 'email: address is malformed' => [
            'ruleSet' => new EmailRuleSet(),
            'dto' => new EmailData(email: 'not-an-email'),
            'expectedCodes' => ['email' => [1221559976]],
        ];
        yield 'email: a valid address above the maximum' => [
            'ruleSet' => new EmailRuleSet(),
            'dto' => new EmailData(email: self::aValidEmailAddressLongerThanTheColumn()),
            'expectedCodes' => ['email' => [1238108069]],
        ];
    }

    /**
     * Partial mode runs the leaf validators against the raw submitted value,
     * so it sees values a DTO cannot hold — `null` and everything JSON yields
     * that is not a string.
     *
     * The expected map is asserted whole, which is also the general form of
     * "an absent field produces no error": every case names exactly one
     * property, so any error under a second one fails the assertion.
     *
     * @param array<string, list<int>> $expectedCodes
     */
    #[Test]
    #[DataProvider('submittedValuesViolatingOneRule')]
    public function partialValidationReportsTheViolatedRule(
        RuleSetInterface $ruleSet,
        string $propertyName,
        mixed $value,
        array $expectedCodes,
    ): void {
        $result = $this->subject->validateProperty($ruleSet, $propertyName, $value);

        $this->assertSame($expectedCodes, $this->flattenedCodes($result));
        $this->assertSame([$propertyName], array_keys($result->getSubResults()));
    }

    /**
     * @return \Generator<string, array{ruleSet: RuleSetInterface, propertyName: string, value: mixed, expectedCodes: array<string, list<int>>}>
     */
    public static function submittedValuesViolatingOneRule(): \Generator
    {
        $tooLong = str_repeat('x', self::OVER_INPUT_COLUMN_LENGTH);

        yield 'profile: shortname submitted empty' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'shortname',
            'value' => '',
            'expectedCodes' => ['shortname' => [1221560718]],
        ];
        yield 'profile: shortname submitted as null' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'shortname',
            'value' => null,
            'expectedCodes' => ['shortname' => [1221560910]],
        ];
        yield 'profile: shortname below the minimum' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'shortname',
            'value' => 'a',
            'expectedCodes' => ['shortname' => [1428504122]],
        ];
        yield 'profile: shortname above the maximum' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'shortname',
            'value' => $tooLong,
            'expectedCodes' => ['shortname' => [1428504122]],
        ];
        yield 'profile: firstname above the maximum' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'firstname',
            'value' => $tooLong,
            'expectedCodes' => ['firstname' => [1238108069]],
        ];
        yield 'profile: firstname submitted as a number' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'firstname',
            'value' => 42,
            'expectedCodes' => ['firstname' => [1269883975]],
        ];
        yield 'profile: lastname above the maximum' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'lastname',
            'value' => $tooLong,
            'expectedCodes' => ['lastname' => [1238108069]],
        ];
        yield 'profile: birthday is not a date' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'birthday',
            'value' => 'yesterday',
            'expectedCodes' => ['birthday' => [1786492305]],
        ];
        yield 'profile: birthday is a day that does not exist' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'birthday',
            'value' => '2026-02-30',
            'expectedCodes' => ['birthday' => [1786492305]],
        ];
        yield 'profile: birthday submitted as a number' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'birthday',
            'value' => 42,
            'expectedCodes' => ['birthday' => [1786492304]],
        ];
        yield 'profile: bio above the payload limit' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'bio',
            'value' => str_repeat('x', 5001),
            'expectedCodes' => ['bio' => [1238108069]],
        ];
        yield 'profile: bio submitted as an array' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'bio',
            'value' => ['x'],
            'expectedCodes' => ['bio' => [1269883975]],
        ];

        yield 'address: type outside the accepted set' => [
            'ruleSet' => new AddressRuleSet(),
            'propertyName' => 'type',
            'value' => 'nope',
            'expectedCodes' => ['type' => [1786492302]],
        ];
        yield 'address: type submitted empty' => [
            'ruleSet' => new AddressRuleSet(),
            'propertyName' => 'type',
            'value' => '',
            'expectedCodes' => ['type' => [1786492302]],
        ];
        yield 'address: type submitted as null' => [
            'ruleSet' => new AddressRuleSet(),
            'propertyName' => 'type',
            'value' => null,
            'expectedCodes' => ['type' => [1786492302]],
        ];
        yield 'address: type submitted as a number' => [
            'ruleSet' => new AddressRuleSet(),
            'propertyName' => 'type',
            'value' => 7,
            'expectedCodes' => ['type' => [1786492302]],
        ];
        yield 'address: line1 submitted empty' => [
            'ruleSet' => new AddressRuleSet(),
            'propertyName' => 'line1',
            'value' => '',
            'expectedCodes' => ['line1' => [1221560718]],
        ];
        yield 'address: line1 submitted as null' => [
            'ruleSet' => new AddressRuleSet(),
            'propertyName' => 'line1',
            'value' => null,
            'expectedCodes' => ['line1' => [1221560910]],
        ];
        yield 'address: line1 above the maximum' => [
            'ruleSet' => new AddressRuleSet(),
            'propertyName' => 'line1',
            'value' => $tooLong,
            'expectedCodes' => ['line1' => [1238108069]],
        ];
        yield 'address: line2 above the maximum' => [
            'ruleSet' => new AddressRuleSet(),
            'propertyName' => 'line2',
            'value' => $tooLong,
            'expectedCodes' => ['line2' => [1238108069]],
        ];
        yield 'address: line2 submitted as a number' => [
            'ruleSet' => new AddressRuleSet(),
            'propertyName' => 'line2',
            'value' => 42,
            'expectedCodes' => ['line2' => [1269883975]],
        ];

        yield 'email: type outside the accepted set' => [
            'ruleSet' => new EmailRuleSet(),
            'propertyName' => 'type',
            'value' => 'nope',
            'expectedCodes' => ['type' => [1786492302]],
        ];
        yield 'email: type submitted empty' => [
            'ruleSet' => new EmailRuleSet(),
            'propertyName' => 'type',
            'value' => '',
            'expectedCodes' => ['type' => [1786492302]],
        ];
        yield 'email: address submitted empty' => [
            'ruleSet' => new EmailRuleSet(),
            'propertyName' => 'email',
            'value' => '',
            'expectedCodes' => ['email' => [1221560718]],
        ];
        yield 'email: address submitted as null' => [
            'ruleSet' => new EmailRuleSet(),
            'propertyName' => 'email',
            'value' => null,
            'expectedCodes' => ['email' => [1221560910]],
        ];
        yield 'email: address is malformed' => [
            'ruleSet' => new EmailRuleSet(),
            'propertyName' => 'email',
            'value' => 'not-an-email',
            'expectedCodes' => ['email' => [1221559976]],
        ];
        yield 'email: a valid address above the maximum' => [
            'ruleSet' => new EmailRuleSet(),
            'propertyName' => 'email',
            'value' => self::aValidEmailAddressLongerThanTheColumn(),
            'expectedCodes' => ['email' => [1238108069]],
        ];
        // Both leaf validators run and both report: the entry point merges
        // every rule of the property rather than stopping at the first.
        yield 'email: address submitted as a number' => [
            'ruleSet' => new EmailRuleSet(),
            'propertyName' => 'email',
            'value' => 42,
            'expectedCodes' => ['email' => [1221559976, 1269883975]],
        ];
    }

    /**
     * A submitted value that satisfies its rules produces an empty `Result`,
     * not a `Result` carrying an empty sub result — `forProperty()` creates one
     * on first access, so the merge is guarded.
     */
    #[Test]
    #[DataProvider('submittedValuesThatSatisfyTheirRules')]
    public function aValidSubmittedValueLeavesNoSubResultBehind(
        RuleSetInterface $ruleSet,
        string $propertyName,
        mixed $value,
    ): void {
        $result = $this->subject->validateProperty($ruleSet, $propertyName, $value);

        $this->assertFalse($result->hasMessages());
        $this->assertSame([], $result->getSubResults());
        $this->assertSame([], $this->flattenedCodes($result));
    }

    /**
     * @return \Generator<string, array{ruleSet: RuleSetInterface, propertyName: string, value: mixed}>
     */
    public static function submittedValuesThatSatisfyTheirRules(): \Generator
    {
        yield 'profile: a shortname within the bounds' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'shortname',
            'value' => 'jdoe',
        ];
        // An optional field submitted empty passes, because only NotEmpty and
        // ChoiceValidator see an empty value at all.
        yield 'profile: firstname cleared' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'firstname',
            'value' => '',
        ];
        yield 'profile: lastname cleared' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'lastname',
            'value' => '',
        ];
        yield 'profile: birthday cleared' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'birthday',
            'value' => '',
        ];
        yield 'profile: birthday in the wire format' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'birthday',
            'value' => '1980-05-17',
        ];
        yield 'profile: bio cleared' => [
            'ruleSet' => new ProfileRuleSet(),
            'propertyName' => 'bio',
            'value' => '',
        ];
        yield 'address: an accepted type' => [
            'ruleSet' => new AddressRuleSet(),
            'propertyName' => 'type',
            'value' => 'work',
        ];
        yield 'address: a filled line1' => [
            'ruleSet' => new AddressRuleSet(),
            'propertyName' => 'line1',
            'value' => 'Example Street 1',
        ];
        yield 'address: line2 cleared' => [
            'ruleSet' => new AddressRuleSet(),
            'propertyName' => 'line2',
            'value' => '',
        ];
        yield 'email: an accepted type' => [
            'ruleSet' => new EmailRuleSet(),
            'propertyName' => 'type',
            'value' => 'private',
        ];
        yield 'email: a valid address' => [
            'ruleSet' => new EmailRuleSet(),
            'propertyName' => 'email',
            'value' => 'john.doe@example.com',
        ];
    }

    /**
     * The point of the partial entry point: an absent field cannot produce an
     * error because its validators are never built.
     *
     * The pair is what makes it an assertion rather than a coincidence — the
     * same rule set and the same `type` value, and full mode reports the
     * missing `email` while partial mode reports nothing at all.
     */
    #[Test]
    public function partialValidationExcludesAbsentFieldsStructurally(): void
    {
        $ruleSet = new EmailRuleSet();

        $full = $this->subject->validate($ruleSet, new EmailData(type: 'private'));
        $partial = $this->subject->validateProperty($ruleSet, 'type', 'private');

        $this->assertSame(['email' => [1221560718]], $this->flattenedCodes($full));
        $this->assertSame([], $this->flattenedCodes($partial));
    }

    /**
     * A client sees the same response structure for both kinds of save: a
     * `Result` rooted at the object, with the errors nested under the property
     * name and nothing on the root.
     */
    #[Test]
    public function bothModesProduceTheSameResultShape(): void
    {
        $ruleSet = new EmailRuleSet();

        $full = $this->subject->validate($ruleSet, new EmailData(type: 'nope', email: 'john.doe@example.com'));
        $partial = $this->subject->validateProperty($ruleSet, 'type', 'nope');

        foreach (['full' => $full, 'partial' => $partial] as $mode => $result) {
            $this->assertSame(['type'], array_keys($result->getSubResults()), $mode);
            $this->assertSame(['type' => [1786492302]], $this->flattenedCodes($result), $mode);
            // Rooted at the object: the errors belong to the property, the root
            // itself carries none of its own.
            $this->assertSame([], $result->getErrors(), $mode);
            $this->assertNotSame([], $result->forProperty('type')->getErrors(), $mode);
        }
    }

    /**
     * An unknown field name is a protocol error, not a validation error. It is
     * an exception rather than an entry in the `Result` so that a careless
     * caller cannot treat the save as "validated with errors" and carry on, and
     * so that a renamed field fails loudly instead of quietly saving nothing.
     */
    #[Test]
    #[DataProvider('fieldNamesNoRuleSetDeclares')]
    public function anUnknownFieldNameIsRejectedRatherThanValidated(
        RuleSetInterface $ruleSet,
        string $propertyName,
    ): void {
        $this->expectException(UnknownPropertyException::class);
        $this->expectExceptionCode(1786492306);

        $this->subject->validateProperty($ruleSet, $propertyName, 'anything');
    }

    /**
     * @return \Generator<string, array{ruleSet: RuleSetInterface, propertyName: string}>
     */
    public static function fieldNamesNoRuleSetDeclares(): \Generator
    {
        foreach (['profile' => new ProfileRuleSet(), 'address' => new AddressRuleSet(), 'email' => new EmailRuleSet()] as $name => $ruleSet) {
            yield $name . ': storage location' => ['ruleSet' => $ruleSet, 'propertyName' => 'pid'];
            yield $name . ': record identity' => ['ruleSet' => $ruleSet, 'propertyName' => 'uid'];
            yield $name . ': publication state' => ['ruleSet' => $ruleSet, 'propertyName' => 'hidden'];
            yield $name . ': an empty name' => ['ruleSet' => $ruleSet, 'propertyName' => ''];
            yield $name . ': invented by a client' => ['ruleSet' => $ruleSet, 'propertyName' => 'somethingElse'];
        }
        yield 'profile: ownership' => ['ruleSet' => new ProfileRuleSet(), 'propertyName' => 'feUser'];
        // The lookup is exact: a differently cased name is a different name.
        yield 'profile: the same name in another case' => ['ruleSet' => new ProfileRuleSet(), 'propertyName' => 'Shortname'];
        // A rule set is the whitelist of *its own* payload only.
        yield 'profile rules do not accept an address field' => ['ruleSet' => new ProfileRuleSet(), 'propertyName' => 'line1'];
        yield 'address rules do not accept a profile field' => ['ruleSet' => new AddressRuleSet(), 'propertyName' => 'shortname'];
    }

    /**
     * The message names both the field and the rule set that rejected it,
     * because "not writable" is only actionable together with the whitelist
     * that says so.
     */
    #[Test]
    public function theUnknownFieldExceptionCarriesTheAddressedName(): void
    {
        try {
            $this->subject->validateProperty(new ProfileRuleSet(), 'pid', 42);
            $this->fail('An unknown field name must not be answered with a Result.');
        } catch (UnknownPropertyException $exception) {
            $this->assertStringContainsString('pid', $exception->getMessage());
            $this->assertStringContainsString(ProfileRuleSet::class, $exception->getMessage());
        }
    }

    /**
     * The reason `ValidatorResolver::getBaseValidatorConjunction()` is not used:
     * it caches a `GenericObjectValidator` per target class on a singleton, and
     * `AbstractGenericObjectValidator` returns `$this->result` unchanged for an
     * instance it has already seen — `$validatedInstancesContainer` is only ever
     * added to.
     *
     * The second half of this test demonstrates that failure mode with a reused
     * validator, so the first half is a guarantee rather than an observation.
     */
    #[Test]
    public function revalidatingTheSameInstanceDoesNotReturnAStaleResult(): void
    {
        $ruleSet = new ProfileRuleSet();
        $dto = new ProfileData(shortname: '');

        $first = $this->subject->validate($ruleSet, $dto);
        $second = $this->subject->validate($ruleSet, $dto);

        $this->assertNotSame($first, $second);
        $this->assertSame(['shortname' => [1221560718]], $this->flattenedCodes($second));

        $reused = new GenericObjectValidator();
        $notEmpty = new NotEmptyValidator();
        $notEmpty->setOptions([]);
        $reused->addPropertyValidator('shortname', $notEmpty);
        $mutable = new class () {
            public string $shortname = '';
        };
        $staleFirst = $reused->validate($mutable);
        $mutable->shortname = 'jdoe';
        $staleSecond = $reused->validate($mutable);

        $this->assertSame($staleFirst, $staleSecond);
        $this->assertTrue($staleSecond->forProperty('shortname')->hasErrors());
    }

    /**
     * A valid address of 261 characters: longer than the `varchar(255)` column,
     * and accepted by `EmailAddressValidator`, so the length rule is the only
     * one that can fire.
     */
    private static function aValidEmailAddressLongerThanTheColumn(): string
    {
        return str_repeat('a', 60) . '@' . implode('.', array_fill(0, 3, str_repeat('b', 62))) . '.example.com';
    }

    /**
     * @return array<string, list<int>>
     */
    private function flattenedCodes(Result $result): array
    {
        $codes = [];
        foreach ($result->getFlattenedErrors() as $propertyPath => $errors) {
            $codes[$propertyPath] = array_values(
                array_map(static fn(Error $error): int => $error->getCode(), $errors),
            );
        }

        return $codes;
    }
}
