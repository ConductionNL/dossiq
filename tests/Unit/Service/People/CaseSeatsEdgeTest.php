<?php

/**
 * The coordinator seat where the register cannot answer the ordinary way.
 *
 * A case without an id, an instance without a coordinator role type, a blank
 * user, an OpenRegister that is not there or refuses a delete, and a save that
 * answers with an entity instead of an array. Each answers empty or keeps
 * what is stored instead of breaking the caller, and a participant that is
 * already a typed reference is stored as given.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\People
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\CaseSeats;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for CaseSeats at its edges.
 */
class CaseSeatsEdgeTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * Warnings logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * Seed one coordinator and one handler role type and a case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'roleType', uuid: 'rt-behandelaar', row: ['name' => 'Behandelaar', 'genericRole' => 'handler']);
		$this->store->seed(schema: 'roleType', uuid: 'rt-casemanager', row: ['name' => 'Casemanager', 'genericRole' => 'coordinator']);
		$this->store->seed(schema: 'case', uuid: 'case-1', row: ['title' => 'Dakkapel', 'assignee' => 'jan']);
	}//end setUp()

	/**
	 * A case without an id has no coordinator, and its handler is still read.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnIdHasNoCoordinator(): void {
		$this->store->seed(schema: 'role', uuid: 'role-1', row: ['case' => '', 'roleType' => 'rt-casemanager', 'participant' => 'user:sofie']);

		self::assertSame([], $this->seats()->coordinatorBinding(caseId: ''));
		self::assertSame(
			['handler' => 'jan', 'coordinator' => '', 'coordinatorRole' => ''],
			$this->seats()->seatsOf(case: ['assignee' => ' jan '])
		);
	}//end testACaseWithoutAnIdHasNoCoordinator()

	/**
	 * Without a coordinator role type no seat exists and nobody coordinates.
	 *
	 * @return void
	 */
	public function testWithoutACoordinatorRoleTypeThereIsNoSeat(): void {
		$this->store->seed(schema: 'role', uuid: 'role-1', row: ['case' => 'case-1', 'roleType' => 'rt-behandelaar', 'participant' => 'user:sofie']);
		unset($this->store->rows['roleType']['rt-casemanager']);

		self::assertSame([], $this->seats()->coordinatorRoleTypes());
		self::assertSame([], $this->seats()->coordinatorBinding(caseId: 'case-1'));
		self::assertSame([], $this->seats()->casesCoordinatedBy(uid: 'sofie'));
	}//end testWithoutACoordinatorRoleTypeThereIsNoSeat()

	/**
	 * A blank user coordinates nothing, whatever is stored.
	 *
	 * @return void
	 */
	public function testABlankUserCoordinatesNothing(): void {
		$this->store->seed(schema: 'role', uuid: 'role-1', row: ['case' => 'case-1', 'roleType' => 'rt-casemanager', 'participant' => 'user:']);

		self::assertSame([], $this->seats()->casesCoordinatedBy(uid: '  '));
	}//end testABlankUserCoordinatesNothing()

	/**
	 * Without OpenRegister every lookup answers empty and says why.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterEveryLookupAnswersEmpty(): void {
		$seats = $this->seats(objectService: null);

		self::assertSame([], $seats->coordinatorRoleTypes());
		self::assertSame([], $seats->coordinatorBinding(caseId: 'case-1'));
		self::assertSame(['Dossiq seats: a seat lookup could not be answered', 'Dossiq seats: a seat lookup could not be answered'], $this->warnings);
	}//end testWithoutOpenRegisterEveryLookupAnswersEmpty()

	/**
	 * A delete OpenRegister refuses leaves the seat as it was and names nobody.
	 *
	 * @return void
	 */
	public function testARefusedDeleteKeepsTheSeat(): void {
		$store = new class extends InMemoryRegister {
			/**
			 * Refuse every delete.
			 *
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 * @param string     $uuid     The object.
			 *
			 * @return bool
			 */
			public function deleteObject(int|string $register = '', int|string $schema = '', string $uuid = ''): bool {
				throw new RuntimeException('delete refused');
			}//end deleteObject()
		};
		$store->rows = $this->store->rows;
		$store->seed(schema: 'role', uuid: 'role-1', row: ['case' => 'case-1', 'roleType' => 'rt-casemanager', 'participant' => 'user:sofie']);

		self::assertSame('', $this->seats(objectService: $store)->clearCoordinator(caseId: 'case-1'));
		self::assertCount(1, $store->all(schema: 'role'));
		self::assertSame(['Dossiq seats: the coordinator seat could not be emptied'], $this->warnings);
	}//end testARefusedDeleteKeepsTheSeat()

	/**
	 * A save that answers with an entity still yields the new seat's id; one
	 * that answers with neither an array nor an entity yields none.
	 *
	 * @return void
	 */
	public function testTheNewSeatIdIsReadFromAnEntityAnswer(): void {
		$entity = new class implements \JsonSerializable {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'role-new', 'participant' => 'user:sofie'];
			}
		};

		self::assertSame('role-new', $this->seats(objectService: $this->answering(answer: $entity))->nameCoordinator(caseId: 'case-1', participant: 'sofie'));
		self::assertSame('', $this->seats(objectService: $this->answering(answer: true))->nameCoordinator(caseId: 'case-1', participant: 'sofie'));
	}//end testTheNewSeatIdIsReadFromAnEntityAnswer()

	/**
	 * A participant that already names its kind is stored as given, and the
	 * seat label falls back to it.
	 *
	 * @return void
	 */
	public function testATypedParticipantIsStoredAsGiven(): void {
		$this->seats()->nameCoordinator(caseId: 'case-1', participant: ' group:vergunningen ');

		$roles = $this->store->all(schema: 'role');
		self::assertCount(1, $roles);
		self::assertSame('group:vergunningen', $roles[0]['participant']);
		self::assertSame('group:vergunningen', $roles[0]['name']);
		self::assertSame(['case-1'], $this->seats()->casesCoordinatedBy(uid: 'group:vergunningen'));
	}//end testATypedParticipantIsStoredAsGiven()

	/**
	 * An ObjectService that lists the seeded rows and answers every save with the given value.
	 *
	 * @param mixed $answer What saveObject answers.
	 *
	 * @return object
	 */
	private function answering(mixed $answer): object {
		return new class ($this->store, $answer) {
			/**
			 * Constructor.
			 *
			 * @param InMemoryRegister $store  The rows to list.
			 * @param mixed            $answer What saveObject answers.
			 */
			public function __construct(private InMemoryRegister $store, private mixed $answer) {
			}//end __construct()

			/**
			 * List rows the way the register does.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				return $this->store->searchObjectsBySlug(register: $register, schema: $schema, filters: $filters);
			}//end searchObjectsBySlug()

			/**
			 * Answer a save with the configured value.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param int|string           $register The register.
			 * @param int|string           $schema   The schema.
			 *
			 * @return mixed
			 */
			public function saveObject(array $object, int|string $register = '', int|string $schema = ''): mixed {
				return $this->answer;
			}//end saveObject()
		};
	}//end answering()

	/**
	 * The seats over an ObjectService (the in-memory register unless given; null for none).
	 *
	 * @param object|null|false $objectService The ObjectService, null for none, false for the default store.
	 *
	 * @return CaseSeats
	 */
	private function seats(object|null|false $objectService = false): CaseSeats {
		$service = $objectService;
		if ($objectService === false) {
			$service = $this->store;
		}

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($service);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = ['register' => 'dossiq', 'case_schema' => 'case', 'role_schema' => 'role', 'role_type_schema' => 'roleType'];
				return ($map[$key] ?? $default);
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return new CaseSeats(settingsService: $settings, logger: $logger);
	}//end seats()
}//end class
