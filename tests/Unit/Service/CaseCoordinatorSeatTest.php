<?php

/**
 * Two seats on one case, and a coordinator who can be found.
 *
 * The finding half is the one worth a test of its own. A coordinator on four
 * cases and handler on none finds nothing at all through `assignee`, which is
 * the whole reason the second seat has to be searchable rather than merely
 * stored.
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
 *
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\People\CaseSeats;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The handler, the coordinator, and finding the cases somebody coordinates.
 *
 * @covers \OCA\Dossiq\Service\People\CaseSeats
 *
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */
class CaseCoordinatorSeatTest extends TestCase {

	/**
	 * The store the seats are read from and written to.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * An instance declaring a coordinator role type and one case.
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
	 * Behandelaar and casemanager are two people on one case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function testACaseCarriesBothSeats(): void {
		$seats = $this->seats();
		$seats->nameCoordinator(caseId: 'case-1', participant: 'sofie', displayName: 'Sofie de Groot');

		$read = $seats->seatsOf(case: $this->store->row(schema: 'case', uuid: 'case-1'));

		self::assertSame('jan', $read['handler'], 'The handler stays `assignee`; a rename would break every lens that reads it.');
		self::assertSame('sofie', $read['coordinator']);
		self::assertNotSame('', $read['coordinatorRole'], 'The coordinator is a role record, not a second column.');
	}//end testACaseCarriesBothSeats()

	/**
	 * The coordinator is stored as a role binding on the case, with its role type.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function testTheCoordinatorIsARoleBindingAndNotACaseField(): void {
		$this->seats()->nameCoordinator(caseId: 'case-1', participant: 'sofie');

		$roles = $this->store->all(schema: 'role');
		self::assertCount(1, $roles);
		self::assertSame('rt-casemanager', $roles[0]['roleType'], 'The binding must name the coordinator role type this instance declares.');
		self::assertSame('user:sofie', $roles[0]['participant'], 'A Nextcloud user is stored the way every other party link is.');
		self::assertArrayNotHasKey(
			'coordinator',
			$this->store->row(schema: 'case', uuid: 'case-1'),
			'A second column beside assignee would be a second place to look and a second thing to keep in step.',
		);
	}//end testTheCoordinatorIsARoleBindingAndNotACaseField()

	/**
	 * Naming a second coordinator replaces the first rather than adding one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function testNamingACoordinatorReplacesTheSeatHolder(): void {
		$seats = $this->seats();
		$seats->nameCoordinator(caseId: 'case-1', participant: 'sofie');
		$seats->nameCoordinator(caseId: 'case-1', participant: 'karim');

		self::assertCount(1, $this->store->all(schema: 'role'), 'There is ONE coordinator seat, not a queue of them.');
		self::assertSame('karim', $seats->seatsOf(case: $this->store->row(schema: 'case', uuid: 'case-1'))['coordinator']);
	}//end testNamingACoordinatorReplacesTheSeatHolder()

	/**
	 * A coordinator finds their cases, though they handle none of them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function testACoordinatorFindsTheirCases(): void {
		$seats = $this->seats();
		foreach (['case-2', 'case-3', 'case-4', 'case-5'] as $caseId) {
			$this->store->seed(schema: 'case', uuid: $caseId, row: ['title' => $caseId, 'assignee' => 'jan']);
			$seats->nameCoordinator(caseId: $caseId, participant: 'sofie');
		}

		$this->store->seed(schema: 'role', uuid: 'role-other', row: [
			'case' => 'case-1',
			'roleType' => 'rt-behandelaar',
			'participant' => 'user:sofie',
		]);

		self::assertSame(
			['case-2', 'case-3', 'case-4', 'case-5'],
			$seats->casesCoordinatedBy(uid: 'sofie'),
			'Four coordinator seats, and a handler binding on a fifth case that must not be counted.',
		);
		self::assertSame([], $seats->casesCoordinatedBy(uid: 'jan'), 'Jan handles cases; he coordinates none.');
	}//end testACoordinatorFindsTheirCases()

	/**
	 * Emptying the seat says who came off it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function testEmptyingTheSeatNamesWhoHeldIt(): void {
		$seats = $this->seats();
		$seats->nameCoordinator(caseId: 'case-1', participant: 'sofie');

		self::assertSame('sofie', $seats->clearCoordinator(caseId: 'case-1'));
		self::assertSame([], $this->store->all(schema: 'role'));
		self::assertSame('', $seats->clearCoordinator(caseId: 'case-1'), 'An already empty seat reports nobody.');
	}//end testEmptyingTheSeatNamesWhoHeldIt()

	/**
	 * An instance that declares no coordinator role type refuses loudly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function testAnInstanceWithNoCoordinatorRoleTypeRefuses(): void {
		$this->store->rows['roleType'] = [];

		$this->expectException(RuntimeException::class);
		$this->seats()->nameCoordinator(caseId: 'case-1', participant: 'sofie');
	}//end testAnInstanceWithNoCoordinatorRoleTypeRefuses()

	/**
	 * A seats reader over the in-memory store.
	 *
	 * @return CaseSeats The service under test.
	 */
	private function seats(): CaseSeats {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'case_schema' => 'case',
					'role_schema' => 'role',
					'role_type_schema' => 'roleType',
				];

				return ($map[$key] ?? $default);
			}
		);

		return new CaseSeats(
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end seats()
}//end class
