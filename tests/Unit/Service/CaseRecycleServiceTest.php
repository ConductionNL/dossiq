<?php

/**
 * The recovery window is read, and a restore is recorded.
 *
 * The window OpenRegister states is the one dossiq publishes. The fallback
 * path here matters more than it looks: a row deleted before openregister#3724
 * carries a `purgeDate` and no `destroyableFrom`, and a lens that showed those
 * rows with an empty date would be indistinguishable from a lens that showed
 * them wrong.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Recycle\CaseRecycleService;
use OCA\Dossiq\Service\SettingsService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The deleted lens, the window fallback and the recorded restore.
 *
 * @covers \OCA\Dossiq\Service\Recycle\CaseRecycleService
 */
class CaseRecycleServiceTest extends TestCase {

	/**
	 * The bridge to OpenRegister.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * OpenRegister's mapper, recording what it was asked.
	 *
	 * @var object
	 */
	private object $mapper;

	/**
	 * OpenRegister's audit trail mapper, recording what was written.
	 *
	 * @var object
	 */
	private object $auditMapper;

	/**
	 * Wire a configured instance with one deleted case in the trash.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$user->method('getDisplayName')->willReturn('Behandelaar');
		$this->userSession->method('getUser')->willReturn($user);

		$this->mapper = $this->magicMapper();
		$this->auditMapper = $this->auditTrailMapper();

		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				default => $default,
			}
		);
		$this->settingsService->method('getOpenRegisterClass')->willReturnCallback(
			fn (string $class): ?object => match ($class) {
				CaseRecycleService::MAGIC_MAPPER => $this->mapper,
				CaseRecycleService::AUDIT_MAPPER => $this->auditMapper,
				default => null,
			}
		);
	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return CaseRecycleService
	 */
	private function service(): CaseRecycleService {
		return new CaseRecycleService(
			settingsService: $this->settingsService,
			userSession: $this->userSession,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end service()

	/**
	 * A soft-deleted case, and a soft-deleted row of another schema beside it.
	 *
	 * @return object The mapper.
	 */
	private function magicMapper(): object {
		return new class {

			/**
			 * The uuid the last restore was asked for.
			 *
			 * @var string
			 */
			public string $restored = '';

			/**
			 * Every soft-deleted row on the instance, cases and others.
			 *
			 * @param int|null $limit Row cap.
			 * @param int|null $offset Rows to skip.
			 *
			 * @return array<int, object> The rows.
			 */
			public function findDeletedAcrossAllMagicTables(?int $limit = null, ?int $offset = null): array {
				return [
					CaseRecycleServiceTestEntity::aCase(),
					CaseRecycleServiceTestEntity::aDocument(),
				];
			}

			/**
			 * One row by identifier, deleted rows included.
			 *
			 * @param string $identifier The UUID.
			 * @param mixed $register The register, unused here.
			 * @param mixed $schema The schema, unused here.
			 * @param bool $includeDeleted Whether the trash is searched too.
			 *
			 * @return object The entity.
			 */
			public function find(
				string $identifier,
				mixed $register = null,
				mixed $schema = null,
				bool $includeDeleted = false,
			): object {
				return CaseRecycleServiceTestEntity::aCase();
			}

			/**
			 * Take one row back out of the trash.
			 *
			 * @param string $uuid The UUID.
			 *
			 * @return bool Always true.
			 */
			public function restoreObject(string $uuid): bool {
				$this->restored = $uuid;
				return true;
			}
		};
	}//end magicMapper()

	/**
	 * An audit trail mapper that keeps what it was handed.
	 *
	 * @return object The mapper.
	 */
	private function auditTrailMapper(): object {
		return new class {

			/**
			 * The entries written, newest last.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $entries = [];

			/**
			 * Keep the entry rather than writing one.
			 *
			 * @param object $object The object acted on.
			 * @param string $action The action.
			 * @param array<string, mixed> $context The record.
			 * @param string|null $actorId The actor.
			 * @param string|null $actorName The actor's display name.
			 *
			 * @return array<string, mixed> The entry.
			 */
			public function createAuditTrailEntry(
				object $object,
				string $action,
				array $context = [],
				?string $actorId = null,
				?string $actorName = null,
			): array {
				$entry = [
					'action' => $action,
					'context' => $context,
					'actorId' => $actorId,
					'actorName' => $actorName,
				];
				$this->entries[] = $entry;

				return $entry;
			}
		};
	}//end auditTrailMapper()

	/**
	 * REQ-CRW-01: the lens lists the deleted cases with the date each window
	 * ends, and nothing that is not a case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testTheLensListsDeletedCasesWithTheirWindow(): void {
		$rows = $this->service()->deletedCases(forUser: 'archivaris', isAdmin: true);

		$this->assertSame(1, $rows['total']);
		$this->assertSame('case-9', $rows['results'][0]['id']);
		$this->assertSame('ZAAK-2026-0009', $rows['results'][0]['identifier']);
		$this->assertSame('2034-01-31', $rows['results'][0]['windowEndsOn']);
		$this->assertSame('behandelaar', $rows['results'][0]['deletedBy']);
	}//end testTheLensListsDeletedCasesWithTheirWindow()

	/**
	 * The lens is scoped to the caller. The trash holds every deleted case on
	 * the instance, so an unscoped lens would hand any signed-in user the
	 * number and title of every bezwaar anybody ever deleted, and it would
	 * look exactly like a working lens.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testAStrangerSeesNothingInTheLens(): void {
		$stranger = $this->service()->deletedCases(forUser: 'iemand-anders', isAdmin: false);
		$deleter = $this->service()->deletedCases(forUser: 'behandelaar', isAdmin: false);

		$this->assertSame(0, $stranger['total']);
		$this->assertSame(1, $deleter['total']);
	}//end testAStrangerSeesNothingInTheLens()

	/**
	 * A row deleted before OpenRegister stated its window carries only a
	 * `purgeDate`, and the lens still says the date rather than an empty cell.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testAnOlderRowStillGetsAWindow(): void {
		$window = $this->service()->window(entity: CaseRecycleServiceTestEntity::anOlderCase());

		$this->assertSame('2034-01-31', substr((string)$window['destroyableFrom'], 0, 10));
		$this->assertSame(30, $window['retentionDays']);
		$this->assertFalse($window['lapsed']);
	}//end testAnOlderRowStillGetsAWindow()

	/**
	 * REQ-CRW-02: restoring records who restored the case and when.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testARestoreIsRecordedWithItsActor(): void {
		$result = $this->service()->restore(caseId: 'case-9');

		$this->assertTrue($result['success']);
		$this->assertSame('case-9', $this->mapper->restored);
		$this->assertCount(1, $this->auditMapper->entries);

		$entry = $this->auditMapper->entries[0];
		$this->assertSame(CaseRecycleService::RESTORE_ACTION, $entry['action']);
		$this->assertSame('behandelaar', $entry['actorId']);
		$this->assertSame('behandelaar', $entry['context']['restoredBy']);
		$this->assertNotSame('', (string)$entry['context']['restoredAt']);
	}//end testARestoreIsRecordedWithItsActor()

	/**
	 * A case that is not in the recycle state cannot be restored out of it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testALiveCaseCannotBeRestored(): void {
		$this->mapper = new class {

			/**
			 * A row that was never deleted.
			 *
			 * @param string $identifier The UUID.
			 * @param mixed $register The register, unused here.
			 * @param mixed $schema The schema, unused here.
			 * @param bool $includeDeleted Whether the trash is searched too.
			 *
			 * @return object The entity.
			 */
			public function find(
				string $identifier,
				mixed $register = null,
				mixed $schema = null,
				bool $includeDeleted = false,
			): object {
				return CaseRecycleServiceTestEntity::aLiveCase();
			}
		};

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('case_not_deleted');

		$this->service()->restore(caseId: 'case-live');
	}//end testALiveCaseCannotBeRestored()
}//end class

/**
 * The object entities this test hands the service.
 *
 * A named class rather than an anonymous one, because three tests need the
 * same shapes and an anonymous class cannot be referred to twice.
 */
class CaseRecycleServiceTestEntity {

	/**
	 * Build one entity.
	 *
	 * @param string $uuid The UUID.
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $payload The object payload.
	 * @param array<string, mixed> $deleted The deletion marker.
	 *
	 * @return object The entity.
	 */
	public static function make(string $uuid, string $schema, array $payload, array $deleted): object {
		return new class($uuid, $schema, $payload, $deleted) {

			/**
			 * Build the entity.
			 *
			 * @param string $uuid The UUID.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $payload The object payload.
			 * @param array<string, mixed> $deleted The deletion marker.
			 */
			public function __construct(
				private readonly string $uuid,
				private readonly string $schema,
				private readonly array $payload,
				private readonly array $deleted,
			) {
			}

			/**
			 * The UUID.
			 *
			 * @return string The UUID.
			 */
			public function getUuid(): string {
				return $this->uuid;
			}

			/**
			 * The schema slug.
			 *
			 * @return string The slug.
			 */
			public function getSchema(): string {
				return $this->schema;
			}

			/**
			 * The payload.
			 *
			 * @return array<string, mixed> The payload.
			 */
			public function getObject(): array {
				return $this->payload;
			}

			/**
			 * The deletion marker.
			 *
			 * @return array<string, mixed> The marker.
			 */
			public function getDeleted(): array {
				return $this->deleted;
			}

			/**
			 * Whether the row is in the recycle state.
			 *
			 * @return bool True when soft-deleted.
			 */
			public function isSoftDeleted(): bool {
				return ($this->deleted !== []);
			}
		};
	}//end make()

	/**
	 * A deleted case carrying the window OpenRegister states.
	 *
	 * @return object The entity.
	 */
	public static function aCase(): object {
		return self::make(
			uuid: 'case-9',
			schema: 'case',
			payload: ['identifier' => 'ZAAK-2026-0009', 'title' => 'Bezwaar tegen de aanslag'],
			deleted: [
				'deletedAt' => '2026-01-02T10:00:00+00:00',
				'deletedBy' => 'behandelaar',
				'retentionPeriod' => 30,
				'destroyableFrom' => '2034-01-31T10:00:00+00:00',
			]
		);
	}//end aCase()

	/**
	 * A deleted case from before the window was stated: a purge date, no more.
	 *
	 * @return object The entity.
	 */
	public static function anOlderCase(): object {
		return self::make(
			uuid: 'case-8',
			schema: 'case',
			payload: ['identifier' => 'ZAAK-2026-0008'],
			deleted: [
				'deleted' => '2026-01-02T10:00:00+00:00',
				'deletedBy' => 'behandelaar',
				'purgeDate' => '2034-01-31T10:00:00+00:00',
			]
		);
	}//end anOlderCase()

	/**
	 * A deleted row of another schema, which the lens leaves alone.
	 *
	 * @return object The entity.
	 */
	public static function aDocument(): object {
		return self::make(
			uuid: 'doc-1',
			schema: 'caseDocument',
			payload: ['title' => 'Bijlage'],
			deleted: ['deletedAt' => '2026-01-02T10:00:00+00:00']
		);
	}//end aDocument()

	/**
	 * A case that was never deleted.
	 *
	 * @return object The entity.
	 */
	public static function aLiveCase(): object {
		return self::make(
			uuid: 'case-live',
			schema: 'case',
			payload: ['identifier' => 'ZAAK-2026-0100'],
			deleted: []
		);
	}//end aLiveCase()
}//end class
