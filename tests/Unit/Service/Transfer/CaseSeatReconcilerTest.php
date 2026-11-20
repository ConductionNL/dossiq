<?php

/**
 * CaseSeatReconciler: a handover empties the seats the receiving team cannot fill.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transfer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transfer;

use OCA\Dossiq\Service\People\CaseSeats;
use OCA\Dossiq\Service\Transfer\CaseSeatReconciler;
use OCA\Dossiq\Service\Transfer\TeamDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Plan reports what comes off the case; apply clears only the coordinator.
 *
 * @covers \OCA\Dossiq\Service\Transfer\CaseSeatReconciler
 */
class CaseSeatReconcilerTest extends TestCase {

	/**
	 * Case seats that report the given holders.
	 *
	 * @param string $handler     The handler uid.
	 * @param string $coordinator The coordinator uid.
	 *
	 * @return CaseSeats The mock.
	 */
	private function seats(string $handler, string $coordinator): CaseSeats {
		$seats = $this->createMock(CaseSeats::class);
		$seats->method('seatsOf')->willReturn(['handler' => $handler, 'coordinator' => $coordinator]);

		return $seats;
	}//end seats()

	/**
	 * A directory in which only the given uids are members of the given team.
	 *
	 * @param string             $team    The team.
	 * @param array<int, string> $members Its members.
	 *
	 * @return TeamDirectory The mock.
	 */
	private function directory(string $team, array $members): TeamDirectory {
		$teams = $this->createMock(TeamDirectory::class);
		$teams->method('holds')->willReturnCallback(
			static fn (string $uid, string $group): bool => $group === $team && in_array($uid, $members, true)
		);

		return $teams;
	}//end directory()

	/**
	 * Both holders outside the receiving team: both seats are emptied, the handler as a case change.
	 *
	 * @return void
	 */
	public function testBothSeatsOutsideTheReceivingTeamAreEmptied(): void {
		$reconciler = new CaseSeatReconciler(
			seats: $this->seats(handler: 'anna', coordinator: 'bert'),
			teams: $this->directory(team: 'vergunningen', members: ['carla'])
		);

		$plan = $reconciler->plan(case: ['id' => 'zaak-1'], team: 'vergunningen');

		$this->assertSame(
			expected: [
				'emptied' => [
					['seat' => CaseSeats::HANDLER, 'holder' => 'anna', 'reason' => CaseSeatReconciler::NOT_IN_RECEIVING_TEAM],
					['seat' => CaseSeats::COORDINATOR, 'holder' => 'bert', 'reason' => CaseSeatReconciler::NOT_IN_RECEIVING_TEAM],
				],
				'changes' => ['assignee' => ''],
			],
			actual: $plan
		);
	}//end testBothSeatsOutsideTheReceivingTeamAreEmptied()

	/**
	 * Holders who are members of the receiving team keep their seats; empty seats stay unreported.
	 *
	 * @return void
	 */
	public function testMembersKeepTheirSeatsAndEmptySeatsAreNotReported(): void {
		$kept = new CaseSeatReconciler(
			seats: $this->seats(handler: 'anna', coordinator: 'bert'),
			teams: $this->directory(team: 'vergunningen', members: ['anna', 'bert'])
		);
		$this->assertSame(expected: ['emptied' => [], 'changes' => []], actual: $kept->plan(case: [], team: 'vergunningen'));

		$teams = $this->createMock(TeamDirectory::class);
		$teams->expects($this->never())->method('holds');
		$empty = new CaseSeatReconciler(seats: $this->seats(handler: '', coordinator: ''), teams: $teams);
		$this->assertSame(expected: ['emptied' => [], 'changes' => []], actual: $empty->plan(case: [], team: 'vergunningen'));
	}//end testMembersKeepTheirSeatsAndEmptySeatsAreNotReported()

	/**
	 * Only the coordinator outside the team: the coordinator is emptied, and no case change is asked for.
	 *
	 * @return void
	 */
	public function testOnlyTheCoordinatorOutsideTheTeamAsksForNoCaseChange(): void {
		$reconciler = new CaseSeatReconciler(
			seats: $this->seats(handler: 'anna', coordinator: 'bert'),
			teams: $this->directory(team: 'vergunningen', members: ['anna'])
		);

		$plan = $reconciler->plan(case: [], team: 'vergunningen');

		$this->assertSame(expected: [], actual: $plan['changes']);
		$this->assertSame(expected: [['seat' => CaseSeats::COORDINATOR, 'holder' => 'bert', 'reason' => CaseSeatReconciler::NOT_IN_RECEIVING_TEAM]], actual: $plan['emptied']);
	}//end testOnlyTheCoordinatorOutsideTheTeamAsksForNoCaseChange()

	/**
	 * Applying clears the coordinator of the case and leaves the handler to the caller's case write.
	 *
	 * @return void
	 */
	public function testApplyClearsOnlyTheCoordinator(): void {
		$seats = $this->createMock(CaseSeats::class);
		$seats->expects($this->once())->method('clearCoordinator')->with('zaak-1')->willReturn('');

		(new CaseSeatReconciler(seats: $seats, teams: $this->createMock(TeamDirectory::class)))->apply(
			caseId: 'zaak-1',
			emptied: [
				['seat' => CaseSeats::HANDLER, 'holder' => 'anna', 'reason' => CaseSeatReconciler::NOT_IN_RECEIVING_TEAM],
				['seat' => CaseSeats::COORDINATOR, 'holder' => 'bert', 'reason' => CaseSeatReconciler::NOT_IN_RECEIVING_TEAM],
			]
		);
	}//end testApplyClearsOnlyTheCoordinator()

	/**
	 * Without a case id nothing is cleared.
	 *
	 * @return void
	 */
	public function testApplyWithoutACaseIdClearsNothing(): void {
		$seats = $this->createMock(CaseSeats::class);
		$seats->expects($this->never())->method('clearCoordinator');

		(new CaseSeatReconciler(seats: $seats, teams: $this->createMock(TeamDirectory::class)))->apply(
			caseId: '',
			emptied: [['seat' => CaseSeats::COORDINATOR, 'holder' => 'bert', 'reason' => CaseSeatReconciler::NOT_IN_RECEIVING_TEAM]]
		);
	}//end testApplyWithoutACaseIdClearsNothing()
}//end class
