<?php

/**
 * Judging a form's mapping before it is bound, and the unreadable-schema paths.
 *
 * IntakeFormBindingCheckTest covers a case type that is already bound. This
 * covers `findingsOfMapping()`, which the form editor calls with a mapping
 * not yet saved: the mapping is normalised before the validator sees it, an
 * audience is passed through only when one is given, and when the case schema
 * or OpenRegister's validator cannot be read the intake requirements are
 * still enforced on their own. It also pins that an unreadable case schema
 * never refuses a binding, and that a malformed validator answer is ignored.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\IntakeFormBinding;
use OCA\Dossiq\Service\Intake\IntakeFormBindingCheck;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unbound mappings and unreadable schemas.
 *
 * @covers \OCA\Dossiq\Service\Intake\IntakeFormBindingCheck
 * @uses   \OCA\Dossiq\Service\Intake\IntakeFormBinding
 * @uses   \OCA\Dossiq\Service\Intake\IntakeRequirements
 */
class IntakeFormBindingCheckMappingTest extends TestCase {

	/**
	 * Every validate() call: the mapping, the schema and the options.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * The logger of the last check built.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * A check over a validator that answers $answer.
	 *
	 * @param mixed       $answer       What validate() returns.
	 * @param bool        $hasValidator Whether OpenRegister has the validator.
	 * @param object|null $mapper       The schema mapper; null for none.
	 * @param string      $schemaId     The configured case schema id.
	 *
	 * @return IntakeFormBindingCheck The check.
	 */
	private function check(mixed $answer = [], bool $hasValidator = true, ?object $mapper = null, string $schemaId = '42'): IntakeFormBindingCheck {
		$asked = &$this->asked;
		$validator = new class ($asked, $answer) {
			/**
			 * @param array<int, mixed> $asked  The call log.
			 * @param mixed             $answer The answer.
			 */
			public function __construct(private array &$asked, private mixed $answer) {
			}

			/**
			 * @param array<string, mixed> $mapping The mapping.
			 * @param object               $schema  The schema.
			 * @param array<string, mixed> $options The options.
			 *
			 * @return mixed
			 */
			public function validate(array $mapping, object $schema, array $options = []): mixed {
				$this->asked[] = ['mapping' => $mapping, 'schema' => $schema, 'options' => $options];
				return $this->answer;
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['case_schema' => $schemaId][$key] ?? $default)
		);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			static function (string $class) use ($validator, $mapper, $hasValidator): ?object {
				if ($class === IntakeFormBindingCheck::VALIDATOR) {
					return $hasValidator === true ? $validator : null;
				}

				return $class === IntakeFormBindingCheck::SCHEMA_MAPPER ? $mapper : null;
			}
		);

		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		return new IntakeFormBindingCheck($settings, new IntakeFormBinding(), $this->logger);
	}//end check()

	/**
	 * A schema mapper that finds a schema object, or throws.
	 *
	 * @param bool $throws Whether find() throws.
	 *
	 * @return object The mapper.
	 */
	private static function mapper(bool $throws = false): object {
		return new class ($throws) {
			/**
			 * @param bool $throws Whether find() throws.
			 */
			public function __construct(private bool $throws) {
			}

			/**
			 * @param string|int $id            The id.
			 * @param array|null $_extend       Extensions.
			 * @param bool       $_rbac         RBAC.
			 * @param bool       $_multitenancy Multitenancy.
			 *
			 * @return object
			 */
			public function find(string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): object {
				if ($this->throws === true) {
					throw new RuntimeException('schema gone');
				}

				return (object)['id' => $id, 'rbac' => $_rbac, 'tenancy' => $_multitenancy];
			}
		};
	}//end mapper()

	/**
	 * A published case type that needs `title` before a case exists.
	 *
	 * @return array<string, mixed>
	 */
	private static function caseType(): array {
		return [
			'isDraft' => false,
			'intakeFormRef' => 'form-1',
			'intakeRequirements' => ['requiredBeforeCreation' => ['title']],
		];
	}//end caseType()

	/**
	 * The mapping is normalised, the audience passed, and both kinds of finding merged.
	 *
	 * @return void
	 */
	public function testAnUnboundMappingIsValidatedWithItsAudience(): void {
		$finding = ['property' => 'x', 'code' => 'property-unknown', 'message' => 'x is unknown'];
		$check = $this->check(answer: [$finding, 'not a finding'], mapper: self::mapper());

		$findings = $check->findingsOfMapping(
			caseType: self::caseType(),
			mapping: ['fields' => [['field' => 'A', 'property' => 'x'], 'junk'], 'extra' => true],
			audience: 'public'
		);

		$this->assertCount(expectedCount: 1, haystack: $this->asked);
		$this->assertSame(
			expected: ['fields' => [['field' => 'A', 'property' => 'x']], 'fixed' => []],
			actual: $this->asked[0]['mapping']
		);
		$this->assertSame(expected: ['audience' => 'public'], actual: $this->asked[0]['options']);
		$this->assertSame(expected: ['id' => '42', 'rbac' => false, 'tenancy' => false], actual: (array)$this->asked[0]['schema']);
		$this->assertSame(expected: $finding, actual: $findings[0]);
		$this->assertSame(expected: 'title', actual: $findings[1]['property']);
		$this->assertSame(expected: IntakeFormBindingCheck::REQUIRED_UNMAPPED, actual: $findings[1]['code']);
		$this->assertCount(expectedCount: 2, haystack: $findings);
	}//end testAnUnboundMappingIsValidatedWithItsAudience()

	/**
	 * Without an audience no options are passed; a fixed value fills a requirement.
	 *
	 * @return void
	 */
	public function testWithoutAnAudienceNoOptionsArePassed(): void {
		$check = $this->check(answer: 'garbage', mapper: self::mapper());

		$findings = $check->findingsOfMapping(caseType: self::caseType(), mapping: ['fixed' => ['title' => 'Aanvraag']]);

		$this->assertSame(expected: [], actual: $this->asked[0]['options']);
		$this->assertSame(expected: [], actual: $findings, message: 'A non-array validator answer is no finding.');
	}//end testWithoutAnAudienceNoOptionsArePassed()

	/**
	 * Without the validator the requirements are still judged, with a warning.
	 *
	 * @return void
	 */
	public function testWithoutTheValidatorOnlyTheRequirementsAreJudged(): void {
		$check = $this->check(hasValidator: false, mapper: self::mapper());
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('intake requirements only'));

		$findings = $check->findingsOfMapping(caseType: self::caseType(), mapping: []);

		$this->assertSame(expected: ['title'], actual: array_column($findings, 'property'));
		$this->assertSame(expected: [], actual: $this->asked);
	}//end testWithoutTheValidatorOnlyTheRequirementsAreJudged()

	/**
	 * An unreadable schema in the unbound path also falls back to requirements only.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaFallsBackToTheRequirements(): void {
		$check = $this->check(mapper: self::mapper(throws: true));

		$findings = $check->findingsOfMapping(caseType: self::caseType(), mapping: []);

		$this->assertSame(expected: ['title'], actual: array_column($findings, 'property'));
		$this->assertSame(expected: [], actual: $this->asked);
	}//end testAnUnreadableSchemaFallsBackToTheRequirements()

	/**
	 * A bound case type whose schema cannot be read is saved unchecked, with a warning.
	 *
	 * @return void
	 */
	public function testABoundTypeWithAnUnreadableSchemaIsNotRefused(): void {
		$check = $this->check(mapper: self::mapper(throws: true));
		$this->logger->expects($this->exactly(2))->method('warning');

		$this->assertSame(expected: [], actual: $check->findingsFor(caseType: self::caseType()));
		$this->assertSame(expected: [], actual: $this->asked);
	}//end testABoundTypeWithAnUnreadableSchemaIsNotRefused()

	/**
	 * No configured case schema, or no mapper, means no schema.
	 *
	 * @return void
	 */
	public function testNoConfiguredSchemaOrMapperMeansNoSchema(): void {
		$this->assertNull(actual: $this->check(mapper: self::mapper(), schemaId: '')->caseSchema());
		$this->assertNull(actual: $this->check(mapper: null)->caseSchema());
		$this->assertNull(actual: $this->check(mapper: new \stdClass())->caseSchema());
	}//end testNoConfiguredSchemaOrMapperMeansNoSchema()

	/**
	 * A bound type with a malformed validator answer keeps only its requirement findings.
	 *
	 * @return void
	 */
	public function testABoundTypeIgnoresAMalformedValidatorAnswer(): void {
		// A bound form always sources `title` (a derived fixed value), so the
		// requirement left open here is another one.
		$type = array_merge(self::caseType(), ['intakeRequirements' => ['requiredBeforeCreation' => ['title', 'communicationChannel']]]);

		$findings = $this->check(answer: null, mapper: self::mapper())->findingsFor(caseType: $type);

		$this->assertSame(expected: ['communicationChannel'], actual: array_column($findings, 'property'));
		$this->assertCount(expectedCount: 1, haystack: $this->asked);
	}//end testABoundTypeIgnoresAMalformedValidatorAnswer()
}//end class
