<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\CaseRoleProjection;
use OCA\Dossiq\Service\People\PersonLinkReader;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A person linked to a case becomes a role record on that case.
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-001-a-person-linked-to-a-case-shall-become-a-role-record-on-that-case
 */
class CaseRoleProjectionTest extends TestCase {

	/**
	 * The configured register and schemas.
	 */
	private const CONFIG = [
		'register' => 'dossiq',
		'case_schema' => 'case',
		'role_schema' => 'role',
		'role_type_schema' => 'roleType',
	];

	/**
	 * The doubled object service.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The settings.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The projection under test.
	 *
	 * @var CaseRoleProjection
	 */
	private CaseRoleProjection $projection;

	/**
	 * Build the projection on a doubled object service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/**
			 * Rows by uuid.
			 *
			 * @var array<string, array<string, mixed>>
			 */
			public array $rows = [];

			/**
			 * Search answers per schema.
			 *
			 * @var array<string, array<int, array<string, mixed>>>
			 */
			public array $answers = [];

			/**
			 * Every save, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * Every deleted uuid.
			 *
			 * @var array<int, string>
			 */
			public array $deleted = [];

			/**
			 * One row by uuid.
			 *
			 * @param string $id The uuid.
			 * @param string|int|null $register The register.
			 * @param string|int|null $schema The schema.
			 *
			 * @return array<string, mixed> The row.
			 */
			public function find(string $id, string|int|null $register = null, string|int|null $schema = null): array {
				if (isset($this->rows[$id]) === false) {
					throw new RuntimeException('not found: ' . $id);
				}

				$row = $this->rows[$id];
				if (($row['@schema'] ?? $schema) !== $schema) {
					throw new RuntimeException('wrong schema for ' . $id);
				}

				unset($row['@schema']);

				return $row;
			}

			/**
			 * Rows matching the filters.
			 *
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$fields = array_filter($filters, static fn (string $key): bool => str_starts_with($key, '_') === false, ARRAY_FILTER_USE_KEY);

				return array_values(
					array_filter(
						($this->answers[$schema] ?? []),
						static function (array $row) use ($fields): bool {
							foreach ($fields as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}

			/**
			 * Store a row.
			 *
			 * @param array<string, mixed> $object The row.
			 * @param string|int|null $register The register.
			 * @param string|int|null $schema The schema.
			 * @param string|null $uuid The uuid, null to create.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function saveObject(array $object, string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): array {
				$uuid = ($uuid ?? ('new-' . count($this->saves)));
				$this->saves[] = ['schema' => $schema, 'uuid' => $uuid, 'object' => $object];
				$this->rows[$uuid] = ($object + ['id' => $uuid, '@schema' => $schema]);

				return ['id' => $uuid] + $object;
			}

			/**
			 * Remove a row.
			 *
			 * @param string $uuid The uuid.
			 * @param string|int|null $register The register.
			 * @param string|int|null $schema The schema.
			 *
			 * @return void
			 */
			public function deleteObject(string $uuid, string|int|null $register = null, string|int|null $schema = null): void {
				$this->deleted[] = $uuid;
			}
		};

		$this->settings = $this->createMock(originalClassName: SettingsService::class);
		$this->settings->method('getObjectService')->willReturn($this->objects);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (self::CONFIG[$key] ?? $default)
		);

		$this->projection = new CaseRoleProjection(
			settingsService: $this->settings,
			people: new PersonLinkReader(settingsService: $this->settings),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Put a case and a role type in place.
	 *
	 * @param string $genericRole The role type's generic role.
	 *
	 * @return void
	 */
	private function seed(string $genericRole = 'handler'): void {
		$this->objects->rows['case-1'] = ['@self' => ['id' => 'case-1'], '@schema' => 'case', 'title' => 'Dakkapel'];
		$this->objects->rows['rt-1'] = ['@self' => ['id' => 'rt-1'], '@schema' => 'roleType', 'name' => 'Behandelaar', 'genericRole' => $genericRole];
	}//end seed()

	/**
	 * One link, one role record, with the person's identity on it.
	 *
	 * @return void
	 */
	public function testALinkBecomesARoleRecord(): void {
		$this->seed();

		$uuid = $this->projection->project(
			link: [
				'objectUuid' => 'case-1',
				'contactUid' => 'user:jan',
				'role' => 'rt-1',
				'displayName' => 'Jan de Vries',
				'note' => 'Handles the permit',
			]
		);

		$this->assertNotSame(expected: '', actual: $uuid);
		$saved = $this->objects->saves[0];
		$this->assertSame(expected: 'role', actual: $saved['schema']);
		$this->assertSame(
			expected: [
				'case' => 'case-1',
				'roleType' => 'rt-1',
				'participant' => 'user:jan',
				'name' => 'Jan de Vries',
				'description' => 'Handles the permit',
			],
			actual: $saved['object'],
		);
	}//end testALinkBecomesARoleRecord()

	/**
	 * A second projection of the same link updates the record it wrote.
	 *
	 * @return void
	 */
	public function testProjectingTwiceUpdatesTheSameRecord(): void {
		$this->seed();
		$this->objects->answers['role'] = [
			['@self' => ['id' => 'role-7'], 'case' => 'case-1', 'participant' => 'user:jan', 'roleType' => 'rt-1', 'name' => 'Jan'],
		];

		$uuid = $this->projection->project(
			link: ['objectUuid' => 'case-1', 'contactUid' => 'user:jan', 'role' => 'rt-1', 'displayName' => 'Jan de Vries']
		);

		$this->assertSame(expected: 'role-7', actual: $uuid);
		$this->assertCount(expectedCount: 1, haystack: $this->objects->saves);
		$this->assertSame(expected: 'role-7', actual: $this->objects->saves[0]['uuid']);
		$this->assertSame(expected: 'Jan de Vries', actual: $this->objects->saves[0]['object']['name']);
	}//end testProjectingTwiceUpdatesTheSameRecord()

	/**
	 * A link on something that is not a case writes nothing.
	 *
	 * @return void
	 */
	public function testALinkOnAnotherObjectWritesNothing(): void {
		$this->objects->rows['rt-1'] = ['@self' => ['id' => 'rt-1'], '@schema' => 'roleType', 'name' => 'Behandelaar'];

		$this->assertSame(
			expected: '',
			actual: $this->projection->project(
				link: ['objectUuid' => 'permit-9', 'contactUid' => 'user:jan', 'role' => 'rt-1']
			),
		);
		$this->assertSame(expected: [], actual: $this->objects->saves);
	}//end testALinkOnAnotherObjectWritesNothing()

	/**
	 * A role that names no role type writes nothing.
	 *
	 * @return void
	 */
	public function testARoleThatNamesNoRoleTypeWritesNothing(): void {
		$this->seed();

		$this->assertSame(
			expected: '',
			actual: $this->projection->project(
				link: ['objectUuid' => 'case-1', 'contactUid' => 'user:jan', 'role' => 'made-up']
			),
		);
		$this->assertSame(expected: [], actual: $this->objects->saves);
	}//end testARoleThatNamesNoRoleTypeWritesNothing()

	/**
	 * An initiator link names the requester, and leaves the reference alone.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-003-an-initiator-link-shall-name-the-requester-on-the-case
	 */
	public function testAnInitiatorLinkNamesTheRequester(): void {
		$this->seed(genericRole: 'initiator');

		$this->projection->project(
			link: ['objectUuid' => 'case-1', 'contactUid' => 'contact-8', 'role' => 'rt-1', 'displayName' => 'Piet Pietersen']
		);

		$caseSave = array_values(array_filter($this->objects->saves, static fn (array $save): bool => $save['schema'] === 'case'));
		$this->assertCount(expectedCount: 1, haystack: $caseSave);
		$this->assertSame(expected: 'Piet Pietersen', actual: $caseSave[0]['object']['initiatorDisplayName']);
		$this->assertSame(expected: 'contact-8', actual: $caseSave[0]['object']['initiatorSourceId']);
		$this->assertArrayNotHasKey(key: 'requester', array: $caseSave[0]['object']);
		$this->assertArrayNotHasKey(key: 'initiatorType', array: $caseSave[0]['object']);
	}//end testAnInitiatorLinkNamesTheRequester()

	/**
	 * A handler link leaves the requester alone.
	 *
	 * @return void
	 */
	public function testAHandlerLinkDoesNotTouchTheRequester(): void {
		$this->seed();

		$this->projection->project(
			link: ['objectUuid' => 'case-1', 'contactUid' => 'user:jan', 'role' => 'rt-1', 'displayName' => 'Jan']
		);

		$this->assertSame(
			expected: [],
			actual: array_values(array_filter($this->objects->saves, static fn (array $save): bool => $save['schema'] === 'case')),
		);
	}//end testAHandlerLinkDoesNotTouchTheRequester()

	/**
	 * Unlinking removes the record the projection wrote, and clears the initiator it set.
	 *
	 * @return void
	 */
	public function testUnlinkingRemovesTheRecordAndClearsTheInitiator(): void {
		$this->seed(genericRole: 'initiator');
		$this->objects->rows['case-1']['initiatorDisplayName'] = 'Piet Pietersen';
		$this->objects->rows['case-1']['initiatorSourceId'] = 'contact-8';
		$this->objects->answers['role'] = [
			['@self' => ['id' => 'role-7'], 'case' => 'case-1', 'participant' => 'contact-8', 'roleType' => 'rt-1'],
		];

		$removed = $this->projection->retire(
			link: ['objectUuid' => 'case-1', 'contactUid' => 'contact-8', 'role' => 'rt-1']
		);

		$this->assertTrue(condition: $removed);
		$this->assertSame(expected: ['role-7'], actual: $this->objects->deleted);
		$caseSave = array_values(array_filter($this->objects->saves, static fn (array $save): bool => $save['schema'] === 'case'));
		$this->assertSame(expected: '', actual: $caseSave[0]['object']['initiatorDisplayName']);
		$this->assertSame(expected: '', actual: $caseSave[0]['object']['initiatorSourceId']);
	}//end testUnlinkingRemovesTheRecordAndClearsTheInitiator()

	/**
	 * Unlinking somebody else leaves an initiator this projection did not set.
	 *
	 * @return void
	 */
	public function testUnlinkingSomebodyElseLeavesTheInitiatorAlone(): void {
		$this->seed(genericRole: 'initiator');
		$this->objects->rows['case-1']['initiatorDisplayName'] = 'Piet Pietersen';
		$this->objects->rows['case-1']['initiatorSourceId'] = 'contact-8';

		$this->assertFalse(
			condition: $this->projection->retire(
				link: ['objectUuid' => 'case-1', 'contactUid' => 'user:jan', 'role' => 'rt-1']
			),
		);
		$this->assertSame(expected: [], actual: $this->objects->deleted);
		$this->assertSame(expected: [], actual: $this->objects->saves);
	}//end testUnlinkingSomebodyElseLeavesTheInitiatorAlone()

	/**
	 * A link with no case or no person is not a link at all.
	 *
	 * @return void
	 */
	public function testAnIncompleteLinkIsIgnored(): void {
		$this->assertSame(expected: '', actual: $this->projection->project(link: ['contactUid' => 'user:jan']));
		$this->assertSame(expected: '', actual: $this->projection->project(link: ['objectUuid' => 'case-1']));
		$this->assertFalse(condition: $this->projection->retire(link: []));
		$this->assertSame(expected: [], actual: $this->objects->saves);
	}//end testAnIncompleteLinkIsIgnored()

	/**
	 * Without OpenRegister nothing is projected, and nothing throws either: a
	 * link is somebody else's write, and failing it would fail theirs.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsProjected(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('getConfigValue')->willReturn('');
		$projection = new CaseRoleProjection(
			settingsService: $settings,
			people: new PersonLinkReader(settingsService: $settings),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$link = ['objectUuid' => 'case-1', 'contactUid' => 'user:jan', 'role' => 'rt-1'];
		$this->assertSame(expected: '', actual: $projection->project(link: $link));
		$this->assertFalse(condition: $projection->retire(link: $link));
	}//end testWithoutOpenRegisterNothingIsProjected()

	/**
	 * An object service that hands back entities rather than rows still yields
	 * the record's uuid.
	 *
	 * @return void
	 */
	public function testAnEntityAnswerStillYieldsTheUuid(): void {
		$this->seed();
		$objects = $this->objects;
		$entityService = new class($objects) {
			/**
			 * @param object $inner The row-shaped double to delegate to.
			 */
			public function __construct(private object $inner) {
			}

			/**
			 * One row by uuid.
			 *
			 * @param string $id The uuid.
			 * @param string|int|null $register The register.
			 * @param string|int|null $schema The schema.
			 *
			 * @return array<string, mixed> The row.
			 */
			public function find(string $id, string|int|null $register = null, string|int|null $schema = null): array {
				return $this->inner->find($id, $register, $schema);
			}

			/**
			 * Rows matching the filters.
			 *
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return $this->inner->searchObjectsBySlug($register, $schema, $filters);
			}

			/**
			 * Store a row, answering an entity the way OpenRegister does.
			 *
			 * @param array<string, mixed> $object The row.
			 * @param string|int|null $register The register.
			 * @param string|int|null $schema The schema.
			 * @param string|null $uuid The uuid.
			 *
			 * @return object The saved entity.
			 */
			public function saveObject(array $object, string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): object {
				$this->inner->saveObject($object, $register, $schema, $uuid);

				return new class {
					/**
					 * The stored uuid.
					 *
					 * @return string The uuid.
					 */
					public function getUuid(): string {
						return 'role-entity';
					}
				};
			}

			/**
			 * Remove a row.
			 *
			 * @param string $uuid The uuid.
			 * @param string|int|null $register The register.
			 * @param string|int|null $schema The schema.
			 *
			 * @return void
			 */
			public function deleteObject(string $uuid, string|int|null $register = null, string|int|null $schema = null): void {
				$this->inner->deleteObject($uuid, $register, $schema);
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($entityService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (self::CONFIG[$key] ?? $default)
		);
		$projection = new CaseRoleProjection(
			settingsService: $settings,
			people: new PersonLinkReader(settingsService: $settings),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->assertSame(
			expected: 'role-entity',
			actual: $projection->project(
				link: ['objectUuid' => 'case-1', 'contactUid' => 'user:jan', 'role' => 'rt-1', 'displayName' => 'Jan']
			),
		);
	}//end testAnEntityAnswerStillYieldsTheUuid()

	/**
	 * A case row with no uuid is not saved: the initiator name has nowhere to
	 * go, and a save with no uuid would create a second case.
	 *
	 * @return void
	 */
	public function testACaseRowWithNoUuidIsNotSaved(): void {
		// The case answers without an id of any kind, which findRow tolerates
		// by falling back to the uuid asked for; strip that too.
		$this->objects->rows['case-1'] = ['@schema' => 'case', 'title' => 'Dakkapel', 'id' => ''];
		$this->objects->rows['rt-1'] = ['@self' => ['id' => 'rt-1'], '@schema' => 'roleType', 'name' => 'Indiener', 'genericRole' => 'initiator'];

		$this->projection->project(
			link: ['objectUuid' => 'case-1', 'contactUid' => 'user:jan', 'role' => 'rt-1', 'displayName' => 'Jan']
		);

		$this->assertSame(
			expected: [],
			actual: array_values(array_filter($this->objects->saves, static fn (array $save): bool => $save['schema'] === 'case')),
		);
	}//end testACaseRowWithNoUuidIsNotSaved()

	/**
	 * An empty uuid finds nothing, and a row the object service answers empty
	 * is no row: neither reaches the role schema.
	 *
	 * @return void
	 */
	public function testAnEmptyUuidAndAnEmptyRowFindNothing(): void {
		// A case that answers an empty row is not a case.
		$this->objects->rows['case-1'] = ['@schema' => 'case'];

		$this->assertSame(
			expected: '',
			actual: $this->projection->project(
				link: ['objectUuid' => 'case-1', 'contactUid' => 'user:jan', 'role' => 'rt-1']
			),
		);
		$this->assertSame(expected: [], actual: $this->objects->saves);
	}//end testAnEmptyUuidAndAnEmptyRowFindNothing()

	/**
	 * A link with no role at all names no role type, so the case is read and
	 * nothing is written: OpenRegister allows a link without a role, and this
	 * is what it means on a case.
	 *
	 * @return void
	 */
	public function testALinkWithNoRoleWritesNothing(): void {
		$this->seed();

		$this->assertSame(
			expected: '',
			actual: $this->projection->project(
				link: ['objectUuid' => 'case-1', 'contactUid' => 'user:jan', 'role' => '', 'displayName' => 'Jan']
			),
		);
		$this->assertSame(expected: [], actual: $this->objects->saves);
	}//end testALinkWithNoRoleWritesNothing()

	/**
	 * A configured register with an unconfigured schema stops the projection
	 * rather than writing against nothing.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaStopsTheProjection(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				if ($key === 'register') {
					return 'dossiq';
				}

				return $default;
			}
		);
		$projection = new CaseRoleProjection(
			settingsService: $settings,
			people: new PersonLinkReader(settingsService: $settings),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->assertSame(
			expected: '',
			actual: $projection->project(
				link: ['objectUuid' => 'case-1', 'contactUid' => 'user:jan', 'role' => 'rt-1']
			),
		);
	}//end testAnUnconfiguredSchemaStopsTheProjection()

	/**
	 * A register that is not configured at all stops it too, with no write.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterStopsTheProjection(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturn('');
		$projection = new CaseRoleProjection(
			settingsService: $settings,
			people: new PersonLinkReader(settingsService: $settings),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->assertSame(
			expected: '',
			actual: $projection->project(
				link: ['objectUuid' => 'case-1', 'contactUid' => 'user:jan', 'role' => 'rt-1']
			),
		);
		$this->assertSame(expected: [], actual: $this->objects->saves);
	}//end testAnUnconfiguredRegisterStopsTheProjection()
}//end class
