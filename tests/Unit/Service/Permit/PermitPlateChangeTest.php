<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/portal-permits-as-held-products/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Permit;

use OCA\Dossiq\Service\Permit\PermitChangeRefused;
use OCA\Dossiq\Service\Permit\PermitPlateChange;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Task 3.2: "Kenteken wijzigen" opens a change case and leaves the permit as it is.
 */
class PermitPlateChangeTest extends TestCase {
	/** @var object The object service double. */
	private object $objects;

	/** @var PermitPlateChange The service under test. */
	private PermitPlateChange $change;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/** @var array<string, array<string, array<string, mixed>>> Objects by schema and id. */
			public array $store = [];

			/** @var array<int, array<string, mixed>> Every save as schema and object. */
			public array $saved = [];

			/**
			 * @param string $id The id.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return array<string, mixed> The object.
			 *
			 * @throws DoesNotExistException When there is none.
			 */
			public function find(string $id, string $register = '', string $schema = ''): array {
				if (isset($this->store[$schema][$id]) === false) {
					throw new DoesNotExistException('none');
				}

				return $this->store[$schema][$id];
			}

			/**
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 * @param bool $_rbac Whether RBAC applies.
			 * @param bool $_multitenancy Whether tenancy applies.
			 *
			 * @return array<int, array<string, mixed>> The matching objects.
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
			 * @param array<string, mixed> $object The object.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param string|null $uuid The id.
			 *
			 * @return array<string, mixed> The stored object.
			 */
			public function saveObject(array $object, string $register = '', string $schema = '', ?string $uuid = null): array {
				$this->saved[] = ['schema' => $schema, 'object' => $object];
				return array_merge($object, ['id' => 'case-new', 'identifier' => '2026-0042']);
			}
		};
		$this->objects->store = [
			'permit' => [
				'permit-1' => ['id' => 'permit-1', 'title' => 'Bewonersvergunning binnenstad', 'kind' => 'parkeren-bewoner', 'portalSubject' => 'subj-sanne', 'status' => 'active', 'kenteken' => 'GZ482K', 'case' => '5e7a1000-0000-4000-a000-00000000ac01'],
				'permit-revoked' => ['id' => 'permit-revoked', 'title' => 'Oud', 'portalSubject' => 'subj-sanne', 'status' => 'revoked', 'case' => '5e7a1000-0000-4000-a000-00000000ac01'],
			],
			'propertyDefinition' => [
				'5e7a1000-0000-4000-a000-00000000ad01' => ['id' => '5e7a1000-0000-4000-a000-00000000ad01', 'caseType' => '5e7a1000-0000-4000-a000-00000000aa02', 'name' => 'permit'],
				'5e7a1000-0000-4000-a000-00000000ad02' => ['id' => '5e7a1000-0000-4000-a000-00000000ad02', 'caseType' => '5e7a1000-0000-4000-a000-00000000aa02', 'name' => 'nieuwKenteken'],
			],
			'case' => ['5e7a1000-0000-4000-a000-00000000ac01' => ['id' => '5e7a1000-0000-4000-a000-00000000ac01', 'caseType' => '5e7a1000-0000-4000-a000-00000000aa01']],
			'caseType' => [
				'5e7a1000-0000-4000-a000-00000000aa01' => ['id' => '5e7a1000-0000-4000-a000-00000000aa01', 'issuesPermit' => ['changeCaseType' => '5e7a1000-0000-4000-a000-00000000aa02']],
				'5e7a1000-0000-4000-a000-00000000aa02' => ['id' => '5e7a1000-0000-4000-a000-00000000aa02', 'initialStatus' => '5e7a1000-0000-4000-a000-00000000ab01'],
			],
		];
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'case_schema' => 'case', 'property_definition_schema' => 'propertyDefinition'][$key] ?? $default)
		);
		$this->change = new PermitPlateChange(settingsService: $settings);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testANewPlateOpensAChangeCaseAndThePermitStaysAsItIs(): void {
		$answer = $this->change->request(subjectRef: 'subj-sanne', permitId: 'permit-1', plate: 'hx-901-b');

		$this->assertSame(['caseId' => 'case-new', 'identifier' => '2026-0042'], $answer);
		$this->assertCount(1, $this->objects->saved);
		$saved = $this->objects->saved[0];
		$this->assertSame('case', $saved['schema'], 'only a case is written, never the permit');
		$this->assertSame('5e7a1000-0000-4000-a000-00000000aa02', $saved['object']['caseType']);
		$this->assertSame('5e7a1000-0000-4000-a000-00000000ab01', $saved['object']['status']);
		$this->assertSame('subj-sanne', $saved['object']['portalSubject']);
		$this->assertSame(
			[
				['propertyDefinition' => '5e7a1000-0000-4000-a000-00000000ad01', 'name' => 'permit', 'value' => 'permit-1'],
				['propertyDefinition' => '5e7a1000-0000-4000-a000-00000000ad02', 'name' => 'nieuwKenteken', 'value' => 'HX901B'],
			],
			$saved['object']['properties']
		);
		$this->assertStringContainsString('HX901B', $saved['object']['description']);
		$this->assertSame('subject:subj-sanne', $saved['object']['portalParty']);
		$this->assertSame('GZ482K', $this->objects->store['permit']['permit-1']['kenteken']);

		$register = new RealSchemaValidator();
		$this->assertSame([], $register->errors(slug: 'case', payload: $saved['object']), 'the change case is one the case schema takes');
	}//end testANewPlateOpensAChangeCaseAndThePermitStaysAsItIs()

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function refusals(): array {
		return [
			'no plate' => ['subj-sanne', 'permit-1', '', PermitChangeRefused::INVALID],
			'a plate too long' => ['subj-sanne', 'permit-1', 'GZ-482-KXX', PermitChangeRefused::INVALID],
			'someone else\'s permit' => ['subj-bob', 'permit-1', 'HX901B', PermitChangeRefused::NOT_FOUND],
			'no such permit' => ['subj-sanne', 'permit-404', 'HX901B', PermitChangeRefused::NOT_FOUND],
			'a revoked permit' => ['subj-sanne', 'permit-revoked', 'HX901B', PermitChangeRefused::NOT_ACTIVE],
		];
	}//end refusals()

	/**
	 * @param string $subject The resident.
	 * @param string $permit The permit.
	 * @param string $plate The plate.
	 * @param string $reason The expected refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 */
	public function testARefusalWritesNothing(string $subject, string $permit, string $plate, string $reason): void {
		try {
			$this->change->request(subjectRef: $subject, permitId: $permit, plate: $plate);
			$this->fail('refused');
		} catch (PermitChangeRefused $refused) {
			$this->assertSame($reason, $refused->getReason());
		}

		$this->assertSame([], $this->objects->saved);
	}//end testARefusalWritesNothing()

	/**
	 * A permit whose case type names no change type cannot be changed from the portal.
	 *
	 * @return void
	 */
	public function testWithoutAChangeCaseTypeTheRequestIsUnavailable(): void {
		unset($this->objects->store['caseType']['5e7a1000-0000-4000-a000-00000000aa01']['issuesPermit']);
		$this->expectExceptionObject(new PermitChangeRefused(PermitChangeRefused::UNAVAILABLE, ''));

		$this->change->request(subjectRef: 'subj-sanne', permitId: 'permit-1', plate: 'HX901B');
	}//end testWithoutAChangeCaseTypeTheRequestIsUnavailable()
}//end class
