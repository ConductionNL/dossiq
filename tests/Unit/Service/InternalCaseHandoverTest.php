<?php

/**
 * The handover to the team next door, on the record the federated transfer writes.
 *
 * What these tests are actually watching for is the two failures this change is
 * about. One: a handover that recreates the case instead of moving it, which
 * restarts the Awb clock and is a way to hide a late case rather than a
 * transfer. Two: a handover to a team nobody can name, which takes the case off
 * the sending team and gives it to nothing.
 *
 * The store is a real in-memory register rather than a per-call stub, because
 * every assertion here is about what came back OUT after a write. A stub that
 * answers the same row whatever was saved would pass a save that dropped the
 * team.
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
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\People\CaseSeats;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transfer\CaseSeatReconciler;
use OCA\Dossiq\Service\Transfer\DoorzendingNotifier;
use OCA\Dossiq\Service\Transfer\InternalHandover;
use OCA\Dossiq\Service\Transfer\TeamDirectory;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Handing a case internally, accepting it, refusing it back, and what stays outstanding.
 *
 * @covers \OCA\Dossiq\Service\Transfer\InternalHandover
 * @covers \OCA\Dossiq\Service\Transfer\TeamDirectory
 * @covers \OCA\Dossiq\Service\Transfer\CaseSeatReconciler
 *
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */
class InternalCaseHandoverTest extends TestCase {

	/**
	 * The store every service under test reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The doorzending announcer, recording what it was asked.
	 *
	 * @var DoorzendingNotifier&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $notifier;

	/**
	 * Wire a configured instance holding one open case owned by Vergunningen.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(
			schema: 'case',
			uuid: 'case-1',
			row: [
				'title' => 'Dakkapel Prinsengracht 12',
				'identifier' => 'ZAAK-2026-0001',
				'caseType' => 'ct-vergunning',
				'status' => 'st-in-behandeling',
				'assignedGroup' => 'vergunningen',
				'assignee' => 'jan',
				'deadline' => '2026-11-01',
			],
		);

		$this->notifier = $this->createMock(originalClassName: DoorzendingNotifier::class);
		$this->notifier->method('announce')->willReturn(['announced' => false, 'reason' => 'internal-move']);
	}//end setUp()

	/**
	 * Vergunningen hands a case to Toezicht, and the case is Toezicht's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 */
	public function testAHandoverMovesTheCaseAndKeepsItsIdentity(): void {
		$handover = $this->handover(teams: ['vergunningen' => ['jan'], 'toezicht' => ['sofie']]);

		$record = $handover->initiate(
			caseId: 'case-1',
			targetTeam: 'toezicht',
			reason: 'Dit is handhaving, geen vergunning',
			initiatedBy: 'jan',
		);

		$case = $this->store->row(schema: 'case', uuid: 'case-1');

		self::assertSame('toezicht', $case['assignedGroup'], 'The case must be owned by the receiving team.');
		self::assertSame('ZAAK-2026-0001', $case['identifier'], 'A handover keeps the case number.');
		self::assertSame('2026-11-01', $case['deadline'], 'A handover keeps the running term; a new case would restart the Awb clock.');
		self::assertSame('team', $record['handoverScope'], 'The record must say which boundary was crossed.');
		self::assertSame('vergunningen', $record['sourceTeam']);
		self::assertSame('Dit is handhaving, geen vergunning', $record['reason']);
		self::assertSame('pending', $record['status'], 'Nobody has picked it up yet.');
		self::assertTrue($case['handoverPending'], 'It stays on the sending team\'s outstanding list until it is accepted.');
	}//end testAHandoverMovesTheCaseAndKeepsItsIdentity()

	/**
	 * The handover joins the custody trail the federated transfer already writes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 */
	public function testTheHandoverJoinsTheOneCustodyTrail(): void {
		$handover = $this->handover(teams: ['vergunningen' => ['jan'], 'toezicht' => ['sofie']]);

		$record = $handover->initiate(caseId: 'case-1', targetTeam: 'toezicht', reason: 'verkeerde afdeling', initiatedBy: 'jan');
		$settled = $handover->accept(transferId: (string)$record['id'], acceptedBy: 'sofie');

		self::assertSame(
			['initiated', 'accepted'],
			array_column($settled['custodyAuditTrail'], 'event'),
			'Both moves must sit on the one trail, in order.',
		);
		self::assertSame('sofie', $settled['acceptedBy']);
		self::assertFalse(
			$this->store->row(schema: 'case', uuid: 'case-1')['handoverPending'],
			'An accepted handover is no longer outstanding.',
		);
	}//end testTheHandoverJoinsTheOneCustodyTrail()

	/**
	 * A team that does not exist refuses the handover, and the case stays put.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 */
	public function testAnUnresolvableTeamRefusesTheHandover(): void {
		$handover = $this->handover(teams: ['vergunningen' => ['jan']]);

		try {
			$handover->initiate(caseId: 'case-1', targetTeam: 'afdeling-die-niet-bestaat', reason: 'x', initiatedBy: 'jan');
			self::fail('A handover to a team nobody can name must be refused.');
		} catch (RefusedException $e) {
			self::assertSame(TeamDirectory::UNRESOLVABLE, $e->getRule());
			self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
		}

		self::assertSame(
			'vergunningen',
			$this->store->row(schema: 'case', uuid: 'case-1')['assignedGroup'],
			'A refused handover must leave the case with its current owner, never unowned.',
		);
	}//end testAnUnresolvableTeamRefusesTheHandover()

	/**
	 * The receiving team sends it back, with a reason, on the same trail.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	public function testTheWrongTeamSendsItBack(): void {
		$handover = $this->handover(teams: ['vergunningen' => ['jan'], 'toezicht' => ['sofie']]);

		$record = $handover->initiate(caseId: 'case-1', targetTeam: 'toezicht', reason: 'handhaving', initiatedBy: 'jan');
		$settled = $handover->refuse(transferId: (string)$record['id'], reason: 'Dit is wel degelijk een vergunning', refusedBy: 'sofie');

		self::assertSame('rejected', $settled['status']);
		self::assertSame('Dit is wel degelijk een vergunning', $settled['rejectionReason']);
		self::assertSame(
			['initiated', 'rejected'],
			array_column($settled['custodyAuditTrail'], 'event'),
			'The refusal is recorded on the custody trail, not only in a log.',
		);
		self::assertSame(
			'vergunningen',
			$this->store->row(schema: 'case', uuid: 'case-1')['assignedGroup'],
			'A refusal returns the case to the sending team.',
		);
	}//end testTheWrongTeamSendsItBack()

	/**
	 * A handover nobody accepted stays on the sending team's list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	public function testAnUnacceptedHandoverDoesNotVanish(): void {
		$handover = $this->handover(teams: ['vergunningen' => ['jan'], 'toezicht' => ['sofie']]);
		$handover->initiate(caseId: 'case-1', targetTeam: 'toezicht', reason: 'handhaving', initiatedBy: 'jan');

		$outstanding = $handover->outstandingFor(team: 'vergunningen');

		self::assertCount(1, $outstanding, 'The sending team must still see what it handed on.');
		self::assertSame('case-1', $outstanding[0]['caseId']);

		self::assertSame(
			[],
			$handover->outstandingFor(team: 'toezicht'),
			'Outstanding is the SENDER\'s list; reading it for the receiver would answer every team\'s cases.',
		);
	}//end testAnUnacceptedHandoverDoesNotVanish()

	/**
	 * A settled handover cannot be settled a second way.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	public function testASettledHandoverRefusesTheOppositeAnswer(): void {
		$handover = $this->handover(teams: ['vergunningen' => ['jan'], 'toezicht' => ['sofie']]);
		$record = $handover->initiate(caseId: 'case-1', targetTeam: 'toezicht', reason: 'handhaving', initiatedBy: 'jan');
		$handover->accept(transferId: (string)$record['id'], acceptedBy: 'sofie');

		self::assertSame(
			'accepted',
			$handover->accept(transferId: (string)$record['id'], acceptedBy: 'sofie')['status'],
			'A repeated accept is idempotent, like the federated one beside it.',
		);

		$this->expectException(RefusedException::class);
		$handover->refuse(transferId: (string)$record['id'], reason: 'te laat', refusedBy: 'sofie');
	}//end testASettledHandoverRefusesTheOppositeAnswer()

	/**
	 * A seat the receiving team cannot fill is emptied, and said so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function testAHandoverEmptiesASeatTheReceivingTeamCannotFill(): void {
		$handover = $this->handover(teams: ['vergunningen' => ['jan'], 'toezicht' => ['sofie']]);

		$record = $handover->initiate(caseId: 'case-1', targetTeam: 'toezicht', reason: 'handhaving', initiatedBy: 'jan');

		self::assertSame(
			[['seat' => 'handler', 'holder' => 'jan', 'reason' => CaseSeatReconciler::NOT_IN_RECEIVING_TEAM]],
			$record['emptiedSeats'],
			'A seat emptied by a handover must be on the record beside the reason.',
		);
		self::assertSame(
			'',
			$this->store->row(schema: 'case', uuid: 'case-1')['assignee'],
			'Jan is not in Toezicht, so the handler seat comes off.',
		);
	}//end testAHandoverEmptiesASeatTheReceivingTeamCannotFill()

	/**
	 * A seat whose holder IS in the receiving team survives the move.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function testAHandoverKeepsASeatTheReceivingTeamShares(): void {
		$handover = $this->handover(teams: ['vergunningen' => ['jan'], 'toezicht' => ['jan', 'sofie']]);

		$record = $handover->initiate(caseId: 'case-1', targetTeam: 'toezicht', reason: 'handhaving', initiatedBy: 'jan');

		self::assertSame([], $record['emptiedSeats'], 'Nothing was emptied.');
		self::assertSame(
			'jan',
			$this->store->row(schema: 'case', uuid: 'case-1')['assignee'],
			'Emptying a seat the receiving team was happy with is work nobody asked for.',
		);
	}//end testAHandoverKeepsASeatTheReceivingTeamShares()

	/**
	 * A wired handover over the in-memory store.
	 *
	 * @param array<string, array<int, string>> $teams The teams on the instance, and who is in each.
	 *
	 * @return InternalHandover The service under test.
	 */
	private function handover(array $teams): InternalHandover {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$settings = $this->settings();
		$directory = new TeamDirectory(groups: $this->groupManager(teams: $teams));

		return new InternalHandover(
			settingsService: $settings,
			teams: $directory,
			seats: new CaseSeatReconciler(
				seats: new CaseSeats(settingsService: $settings, logger: $logger),
				teams: $directory,
			),
			doorzending: $this->notifier,
			logger: $logger,
		);
	}//end handover()

	/**
	 * A settings service answering the schemas this path reads.
	 *
	 * @return SettingsService&\PHPUnit\Framework\MockObject\MockObject The double.
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_transfer_schema' => 'casetransfer',
					'role_schema' => 'role',
					'role_type_schema' => 'roleType',
				];

				return ($map[$key] ?? $default);
			}
		);

		return $settings;
	}//end settings()

	/**
	 * A group manager over a fixed set of teams.
	 *
	 * @param array<string, array<int, string>> $teams The teams and their members.
	 *
	 * @return IGroupManager&\PHPUnit\Framework\MockObject\MockObject The double.
	 */
	private function groupManager(array $teams): IGroupManager {
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(
			static fn (string $gid): bool => array_key_exists($gid, $teams)
		);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => in_array($uid, ($teams[$gid] ?? []), true)
		);

		return $groups;
	}//end groupManager()
}//end class
