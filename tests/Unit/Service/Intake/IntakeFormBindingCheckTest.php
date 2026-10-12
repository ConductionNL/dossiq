<?php

/**
 * The intake form binding of a case type is judged by OpenRegister's validator.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * What is handed to the validator, and what comes back.
 *
 * @covers \OCA\Dossiq\Service\Intake\IntakeFormBindingCheck
 * @uses   \OCA\Dossiq\Service\Intake\IntakeFormBinding
 */
class IntakeFormBindingCheckTest extends TestCase {

	/**
	 * What the validator was asked, per call.
	 *
	 * @var array<int, array{mapping: array<string, mixed>, schema: object}>
	 */
	private array $asked = [];

	/**
	 * The check over a validator that reports a property the schema does not have.
	 *
	 * @param bool        $hasValidator Whether OpenRegister has the validator.
	 * @param object|null $mapper       The schema mapper, or null for one that finds the case schema.
	 *
	 * @return IntakeFormBindingCheck The check.
	 */
	private function check(bool $hasValidator = true, ?object $mapper = null): IntakeFormBindingCheck {
		$asked = &$this->asked;
		$validator = new class($asked) {
			public function __construct(private array &$asked) {
			}//end __construct()

			public function validate(array $mapping, object $schema, array $options = []): array {
				$this->asked[] = ['mapping' => $mapping, 'schema' => $schema];
				$findings = [];
				foreach ($mapping['fields'] as $field) {
					if (in_array($field['property'], $schema->properties, true) === false) {
						$findings[] = [
							'field' => $field['field'],
							'property' => $field['property'],
							'code' => 'property-unknown',
							'message' => 'Field "' . $field['field'] . '" writes into "' . $field['property'] . '", which schema "case" does not have.',
						];
					}
				}

				return $findings;
			}//end validate()
		};

		$mapper ??= new class() {
			public function find(string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): object {
				return (object)['id' => $id, 'rbac' => $_rbac, 'properties' => ['title', 'description', 'caseType']];
			}//end find()
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['case_schema' => '42'][$key] ?? $default)
		);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			static function (string $class) use ($validator, $mapper, $hasValidator): ?object {
				if ($class === IntakeFormBindingCheck::VALIDATOR && $hasValidator === true) {
					return $validator;
				}

				if ($class === IntakeFormBindingCheck::SCHEMA_MAPPER) {
					return $mapper;
				}

				return null;
			}
		);

		return new IntakeFormBindingCheck($settings, new IntakeFormBinding(), $this->createMock(LoggerInterface::class));
	}//end check()

	/**
	 * A case type bound to a form, with one answer into a property.
	 *
	 * @param string $property The case property the answer fills.
	 *
	 * @return array<string, mixed> The case type.
	 */
	private static function boundType(string $property): array {
		return [
			'id' => '6f1d0c7a-52a4-4b61-9d3e-0c2f8a7b1e10',
			'intakeFormRef' => 'form-abc',
			'intakeFormMapping' => ['fields' => [['field' => 'Onderwerp', 'property' => $property, 'type' => 'text']]],
		];
	}//end boundType()

	/**
	 * The names of OpenRegister's classes are the ones it ships.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function testTheClassNamesAreOpenRegistersOwn(): void {
		self::assertSame('OCA\OpenRegister\Service\Form\FormDestinationValidator', IntakeFormBindingCheck::VALIDATOR);
		self::assertSame('OCA\OpenRegister\Db\SchemaMapper', IntakeFormBindingCheck::SCHEMA_MAPPER);
	}//end testTheClassNamesAreOpenRegistersOwn()

	/**
	 * A valid binding has no findings, and the validator saw the binding's mapping and the live case schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function testAValidBindingHasNoFindings(): void {
		self::assertSame([], $this->check()->findingsFor(caseType: self::boundType('title')));

		self::assertCount(1, $this->asked);
		self::assertSame('42', $this->asked[0]['schema']->id);
		self::assertFalse($this->asked[0]['schema']->rbac, 'The schema is read whoever saves the case type.');
		self::assertSame('forms', $this->asked[0]['mapping']['fixed']['intakeChannel']);
		self::assertSame('title', $this->asked[0]['mapping']['fields'][0]['property']);
	}//end testAValidBindingHasNoFindings()

	/**
	 * An answer into a property the case schema does not have is a finding, with a sentence that says so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function testAnAnswerIntoAnUnknownPropertyIsAFinding(): void {
		$check = $this->check();
		$findings = $check->findingsFor(caseType: self::boundType('onderwerp'));

		self::assertCount(1, $findings);
		self::assertSame('property-unknown', $findings[0]['code']);
		self::assertSame('onderwerp', $findings[0]['property']);
		self::assertStringContainsString('"onderwerp"', $check->sentenceFor(findings: $findings));
		self::assertStringContainsString(
			'title: required-unmapped',
			$check->sentenceFor(findings: [['property' => 'title', 'code' => 'required-unmapped']]),
			'A finding without a message still names its property and code.'
		);
	}//end testAnAnswerIntoAnUnknownPropertyIsAFinding()

	/**
	 * A case type with no form bound is not judged at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function testACaseTypeWithoutAFormIsNotJudged(): void {
		$type = self::boundType('onderwerp');
		$type['intakeFormRef'] = '';

		self::assertSame([], $this->check()->findingsFor(caseType: $type));
		self::assertSame([], $this->asked);
	}//end testACaseTypeWithoutAFormIsNotJudged()

	/**
	 * Without the validator or the schema the binding is saved unjudged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function testWithoutOpenRegistersValidatorNothingIsRefused(): void {
		self::assertSame([], $this->check(hasValidator: false)->findingsFor(caseType: self::boundType('onderwerp')));

		$failing = new class() {
			public function find(string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): object {
				throw new RuntimeException('no such schema');
			}//end find()
		};
		self::assertSame([], $this->check(mapper: $failing)->findingsFor(caseType: self::boundType('onderwerp')));
		self::assertSame([], $this->asked, 'The validator is not asked without a schema.');
	}//end testWithoutOpenRegistersValidatorNothingIsRefused()
}//end class
