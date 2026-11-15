<?php

/**
 * Edges of the permit plate change: a missing register, a case write that
 * fails or answers no id, case types without an initial status or property
 * definitions, and how the plate is normalised.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Permit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Permit;

use OCA\Dossiq\Service\Permit\PermitChangeRefused;
use OCA\Dossiq\Service\Permit\PermitPlateChange;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * The paths of PermitPlateChange the main test does not take.
 *
 * @covers \OCA\Dossiq\Service\Permit\PermitPlateChange
 * @covers \OCA\Dossiq\Service\Permit\PermitChangeRefused
 * @uses   \OCA\Dossiq\Service\Permit\PermitRegister
 * @uses   \OCA\Dossiq\Service\Permit\PlateChange
 * @uses   \OCA\Dossiq\Service\Permit\PlateChangeRequest
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class PermitPlateChangeEdgesTest extends TestCase {

	/**
	 * The change case type.
	 */
	private const CHANGE_TYPE = 'type-change';

	/**
	 * The object service double.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The configuration the settings double answers.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Set up a register holding one active permit whose case type names a change case type.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/** @var array<string, array<string, array<string, mixed>>> Objects by schema and id. */
			public array $store = [];

			/** @var array<int, array<string, mixed>> Every save as schema and object. */
			public array $saved = [];

			/** @var mixed What a save answers; a Throwable is thrown. */
			public mixed $saveAnswer = ['id' => 'case-new', 'identifier' => '2026-0042'];

			/**
			 * Find one object.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, string $register = '', string $schema = ''): array {
				if (isset($this->store[$schema][$id]) === false) {
					throw new DoesNotExistException('none');
				}

				return $this->store[$schema][$id];
			}

			/**
			 * Search objects of a schema by case type.
			 *
			 * @param string               $register      The register.
			 * @param string               $schema        The schema.
			 * @param array<string, mixed> $filters       The filters.
			 * @param bool                 $_rbac         Unused.
			 * @param bool                 $_multitenancy Unused.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters, bool $_rbac = true, bool $_multitenancy = true): array {
				return array_values(
					array_filter(
						($this->store[$schema] ?? []),
						static fn (array $row): bool => ($row['caseType'] ?? null) === ($filters['caseType'] ?? '')
					)
				);
			}

			/**
			 * Record a save and answer the configured answer.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return mixed
			 */
			public function saveObject(array $object, string $register = '', string $schema = '', ?string $uuid = null): mixed {
				$this->saved[] = ['schema' => $schema, 'object' => $object];
				if ($this->saveAnswer instanceof \Throwable) {
					throw $this->saveAnswer;
				}

				return $this->saveAnswer;
			}
		};
		$this->objects->store = [
			'permit'   => [
				'permit-1' => ['id' => 'permit-1', 'title' => 'Bewonersvergunning', 'portalSubject' => 'subj-1', 'status' => 'active', 'case' => 'case-1'],
			],
			'case'     => ['case-1' => ['id' => 'case-1', 'caseType' => 'type-permit']],
			'caseType' => [
				'type-permit'     => ['id' => 'type-permit', 'issuesPermit' => ['changeCaseType' => self::CHANGE_TYPE]],
				self::CHANGE_TYPE => ['id' => self::CHANGE_TYPE, 'initialStatus' => '  status-new  '],
			],
		];
		$this->config = ['register' => 'dossiq', 'property_definition_schema' => 'propertyDefinition'];
	}//end setUp()

	/**
	 * The service under test, on the current configuration and object service.
	 *
	 * @param bool $withObjectService Whether OpenRegister is available.
	 *
	 * @return PermitPlateChange
	 */
	private function change(bool $withObjectService = true): PermitPlateChange {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($withObjectService === true ? $this->objects : null);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		return new PermitPlateChange(settingsService: $settings);
	}//end change()

	/**
	 * The refusal a request ends in.
	 *
	 * @param PermitPlateChange $change The service.
	 *
	 * @return PermitChangeRefused
	 */
	private function refusalOf(PermitPlateChange $change): PermitChangeRefused {
		try {
			$change->request(subjectRef: 'subj-1', permitId: 'permit-1', plate: 'HX901B');
		} catch (PermitChangeRefused $refused) {
			return $refused;
		}

		$this->fail('The request was not refused.');
	}//end refusalOf()

	/**
	 * Without OpenRegister the request is unavailable and nothing is written.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheRequestIsUnavailable(): void {
		$refused = $this->refusalOf(change: $this->change(withObjectService: false));

		$this->assertSame(PermitChangeRefused::UNAVAILABLE, $refused->getReason());
		$this->assertSame('OpenRegister or the dossiq register is missing.', $refused->getDetail());
		$this->assertSame([], $this->objects->saved);
	}//end testWithoutOpenRegisterTheRequestIsUnavailable()

	/**
	 * Without a configured register the request is unavailable and nothing is written.
	 *
	 * @return void
	 */
	public function testWithoutARegisterTheRequestIsUnavailable(): void {
		unset($this->config['register']);

		$refused = $this->refusalOf(change: $this->change());

		$this->assertSame(PermitChangeRefused::UNAVAILABLE, $refused->getReason());
		$this->assertSame('OpenRegister or the dossiq register is missing.', $refused->getDetail());
		$this->assertSame([], $this->objects->saved);
	}//end testWithoutARegisterTheRequestIsUnavailable()

	/**
	 * A case write that throws is reported as a case that was not written, without the exception's text.
	 *
	 * @return void
	 */
	public function testAThrowingCaseWriteIsNotWritten(): void {
		$this->objects->saveAnswer = new \RuntimeException('disk full');

		$refused = $this->refusalOf(change: $this->change());

		$this->assertSame(PermitChangeRefused::UNAVAILABLE, $refused->getReason());
		$this->assertSame('The change case was not written.', $refused->getDetail());
		$this->assertCount(1, $this->objects->saved);
	}//end testAThrowingCaseWriteIsNotWritten()

	/**
	 * Case writes that answer no id at all are reported as not written.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function answersWithoutAnId(): array {
		return [
			'nothing'        => [null],
			'no id'          => [['identifier' => '2026-0042']],
			'empty id'       => [['id' => '', '@self' => ['id' => '']]],
			'not serialised' => [new \stdClass()],
		];
	}//end answersWithoutAnId()

	/**
	 * A write that answers no id is reported as a case that was not written.
	 *
	 * @param mixed $answer What the write answers.
	 *
	 * @return void
	 *
	 * @dataProvider answersWithoutAnId
	 */
	public function testAWriteWithoutAnIdIsNotWritten(mixed $answer): void {
		$this->objects->saveAnswer = $answer;

		$refused = $this->refusalOf(change: $this->change());

		$this->assertSame(PermitChangeRefused::UNAVAILABLE, $refused->getReason());
		$this->assertSame('The change case was not written.', $refused->getDetail());
	}//end testAWriteWithoutAnIdIsNotWritten()

	/**
	 * The case id is taken from `@self` when the write answers no top-level id; a missing identifier answers ''.
	 *
	 * @return void
	 */
	public function testTheCaseIdFallsBackToSelf(): void {
		$this->objects->saveAnswer = ['@self' => ['id' => 'case-self']];

		$this->assertSame(
			['caseId' => 'case-self', 'identifier' => ''],
			$this->change()->request(subjectRef: 'subj-1', permitId: 'permit-1', plate: 'HX901B')
		);
	}//end testTheCaseIdFallsBackToSelf()

	/**
	 * The case goes to the configured case schema, defaulting to `case`, with the trimmed initial status.
	 *
	 * @return void
	 */
	public function testTheCaseGoesToTheConfiguredSchemaWithTheTrimmedStatus(): void {
		$this->change()->request(subjectRef: 'subj-1', permitId: 'permit-1', plate: 'HX901B');
		$this->config['case_schema'] = 'zaak';
		$this->change()->request(subjectRef: 'subj-1', permitId: 'permit-1', plate: 'HX901B');

		$this->assertSame(['case', 'zaak'], array_column($this->objects->saved, 'schema'));
		$this->assertSame('status-new', $this->objects->saved[0]['object']['status']);
	}//end testTheCaseGoesToTheConfiguredSchemaWithTheTrimmedStatus()

	/**
	 * A change case type without an initial status writes no status, and without property definitions no
	 * properties.
	 *
	 * @return void
	 */
	public function testWithoutStatusOrDefinitionsTheCaseCarriesNeither(): void {
		$this->objects->store['caseType'][self::CHANGE_TYPE]['initialStatus'] = '   ';

		$this->change()->request(subjectRef: 'subj-1', permitId: 'permit-1', plate: 'HX901B');

		$case = $this->objects->saved[0]['object'];
		$this->assertArrayNotHasKey('status', $case);
		$this->assertArrayNotHasKey('properties', $case);
		$this->assertSame(self::CHANGE_TYPE, $case['caseType']);
	}//end testWithoutStatusOrDefinitionsTheCaseCarriesNeither()

	/**
	 * Without a property definition schema configured no definitions are searched and no properties written.
	 *
	 * @return void
	 */
	public function testWithoutADefinitionSchemaNoPropertiesAreWritten(): void {
		unset($this->config['property_definition_schema']);
		$this->objects->store['propertyDefinition'] = [
			'def-1' => ['id' => 'def-1', 'caseType' => self::CHANGE_TYPE, 'name' => 'permit'],
		];

		$this->change()->request(subjectRef: 'subj-1', permitId: 'permit-1', plate: 'HX901B');

		$this->assertArrayNotHasKey('properties', $this->objects->saved[0]['object']);
	}//end testWithoutADefinitionSchemaNoPropertiesAreWritten()

	/**
	 * A definition answers with its `@self` id when it has no top-level one; definitions without an id, or
	 * for a name the request does not answer, are left out.
	 *
	 * @return void
	 */
	public function testOnlyDefinitionsWithAnIdAndAnAnsweredNameAreFilled(): void {
		$this->objects->store['propertyDefinition'] = [
			'a' => ['@self' => ['id' => 'def-self'], 'caseType' => self::CHANGE_TYPE, 'name' => ' nieuwKenteken '],
			'b' => ['id' => '  ', 'caseType' => self::CHANGE_TYPE, 'name' => 'permit'],
			'c' => ['id' => 'def-other', 'caseType' => self::CHANGE_TYPE, 'name' => 'reden'],
			'd' => ['id' => 'def-permit', 'caseType' => self::CHANGE_TYPE, 'name' => 'permit'],
			'e' => ['id' => 'def-foreign', 'caseType' => 'type-permit', 'name' => 'permit'],
		];

		$this->change()->request(subjectRef: 'subj-1', permitId: 'permit-1', plate: 'hx 901 b');

		$this->assertSame(
			[
				['propertyDefinition' => 'def-self', 'name' => 'nieuwKenteken', 'value' => 'HX901B'],
				['propertyDefinition' => 'def-permit', 'name' => 'permit', 'value' => 'permit-1'],
			],
			$this->objects->saved[0]['object']['properties']
		);
	}//end testOnlyDefinitionsWithAnIdAndAnAnsweredNameAreFilled()

	/**
	 * A plate loses its spaces and dashes and is upper-cased; other characters stay.
	 *
	 * @return void
	 */
	public function testAPlateIsNormalised(): void {
		$this->assertSame('HX901B', PermitPlateChange::normalisePlate(plate: " hx-901\t- b\n"));
		$this->assertSame('AB.12', PermitPlateChange::normalisePlate(plate: 'ab.12'));
		$this->assertSame('', PermitPlateChange::normalisePlate(plate: ' - '));
	}//end testAPlateIsNormalised()

	/**
	 * A refusal carries its reason as the message and its detail apart.
	 *
	 * @return void
	 */
	public function testARefusalCarriesItsReasonAndDetail(): void {
		$refused = new PermitChangeRefused(PermitChangeRefused::NOT_FOUND, 'No such permit.');

		$this->assertSame('not_found', $refused->getReason());
		$this->assertSame('not_found', $refused->getMessage());
		$this->assertSame('No such permit.', $refused->getDetail());
		$this->assertInstanceOf(\RuntimeException::class, $refused);
		$this->assertSame(
			['invalid', 'not_found', 'not_active', 'unavailable'],
			[PermitChangeRefused::INVALID, PermitChangeRefused::NOT_FOUND, PermitChangeRefused::NOT_ACTIVE, PermitChangeRefused::UNAVAILABLE]
		);
	}//end testARefusalCarriesItsReasonAndDetail()
}//end class
