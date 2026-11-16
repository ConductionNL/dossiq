<?php

/**
 * InternalHandover: what happens when the store, the record or the case is not there.
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
 * @spec openspec/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transfer;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\People\CaseSeats;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transfer\CaseSeatReconciler;
use OCA\Dossiq\Service\Transfer\DoorzendingNotifier;
use OCA\Dossiq\Service\Transfer\InternalHandover;
use OCA\Dossiq\Service\Transfer\TeamDirectory;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Settling and listing handovers degrade the way each refusal says.
 *
 * @covers \OCA\Dossiq\Service\Transfer\InternalHandover
 * @uses   \OCA\Dossiq\Exception\RefusedException
 * @uses   \OCA\Dossiq\Service\Custody\DossierRegister
 * @uses   \OCA\Dossiq\Service\Transfer\HandoverSettlement
 * @uses   \OCA\Dossiq\Service\Routing\RoutingNote
 */
class InternalHandoverFailuresTest extends TestCase {

	/**
	 * The store the handover reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The logger, for the assertions that read it.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * A store with one case and one pending handover of it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(
			schema: 'case',
			uuid: 'case-1',
			row: ['id' => 'case-1', 'title' => 'Dakkapel', 'assignedGroup' => 'toezicht', 'handoverPending' => true, 'handoverTo' => 'toezicht'],
		);
		$this->store->seed(
			schema: 'casetransfer',
			uuid: 't-1',
			row: [
				'id' => 't-1',
				'caseId' => 'case-1',
				'sourceTeam' => 'vergunningen',
				'targetTeam' => 'toezicht',
				'status' => 'pending',
				'handoverScope' => InternalHandover::SCOPE_TEAM,
				'custodyAuditTrail' => [['event' => 'initiated', 'actor' => 'jan']],
			],
		);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The service over the store.
	 *
	 * @return InternalHandover
	 */
	private function handover(): InternalHandover {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ([
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_transfer_schema' => 'casetransfer',
			][$key] ?? $default)
		);

		$directory = new TeamDirectory(groups: $this->createMock(originalClassName: IGroupManager::class));

		return new InternalHandover(
			settingsService: $settings,
			teams: $directory,
			seats: new CaseSeatReconciler(seats: $this->createMock(originalClassName: CaseSeats::class), teams: $directory),
			doorzending: $this->createMock(originalClassName: DoorzendingNotifier::class),
			logger: $this->logger,
		);
	}//end handover()

	/**
	 * Run a call and hand back the refusal it raised.
	 *
	 * @param callable $call The call.
	 *
	 * @return RefusedException The refusal.
	 */
	private function refusalOf(callable $call): RefusedException {
		try {
			$call();
		} catch (RefusedException $e) {
			return $e;
		}

		$this->fail('Expected a refusal.');
	}//end refusalOf()

	/**
	 * A blank team has nothing outstanding.
	 *
	 * @return void
	 */
	public function testABlankTeamHasNothingOutstanding(): void {
		$this->store->missingSchemas = ['casetransfer'];
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(expected: [], actual: $this->handover()->outstandingFor(team: '  '));
	}//end testABlankTeamHasNothingOutstanding()

	/**
	 * An unreadable list is logged and answered as empty.
	 *
	 * @return void
	 */
	public function testAnUnreadableOutstandingListIsLoggedAndEmpty(): void {
		$this->store->missingSchemas = ['casetransfer'];
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Dossiq handover: the outstanding handovers could not be read',
				['team' => 'vergunningen', 'exception' => 'No schema casetransfer in this register']
			);

		$this->assertSame(expected: [], actual: $this->handover()->outstandingFor(team: ' vergunningen '));
	}//end testAnUnreadableOutstandingListIsLoggedAndEmpty()

	/**
	 * Settling a handover that does not exist is refused as not found.
	 *
	 * @return void
	 */
	public function testAnUnknownHandoverIsRefusedAsNotFound(): void {
		$refusal = $this->refusalOf(fn () => $this->handover()->accept(transferId: 't-404', acceptedBy: 'sofie'));

		$this->assertSame(expected: InternalHandover::HANDOVER_UNKNOWN, actual: $refusal->getRule());
		$this->assertSame(expected: RefusedException::STATUS_UNPROCESSABLE, actual: $refusal->getStatus());
		$this->assertSame(expected: 'That handover could not be found.', actual: $refusal->getSentence());
	}//end testAnUnknownHandoverIsRefusedAsNotFound()

	/**
	 * A handover that cannot be read is refused as indeterminate, carrying the cause.
	 *
	 * @return void
	 */
	public function testAnUnreadableHandoverIsRefusedAsIndeterminate(): void {
		$this->store->refusedToCaller = ['casetransfer'];

		$refusal = $this->refusalOf(fn () => $this->handover()->refuse(transferId: 't-1', reason: 'fout', refusedBy: 'sofie'));

		$this->assertSame(expected: InternalHandover::HANDOVER_UNKNOWN, actual: $refusal->getRule());
		$this->assertSame(expected: RefusedException::STATUS_INDETERMINATE, actual: $refusal->getStatus());
		$this->assertSame(expected: 'We could not read that handover, so nothing was changed.', actual: $refusal->getSentence());
		$this->assertInstanceOf(expected: \RuntimeException::class, actual: $refusal->getPrevious());
		$this->assertSame(expected: 'toezicht', actual: $this->store->row(schema: 'case', uuid: 'case-1')['assignedGroup']);
	}//end testAnUnreadableHandoverIsRefusedAsIndeterminate()

	/**
	 * A settlement that cannot be recorded is refused, and the case is left where it is.
	 *
	 * @return void
	 */
	public function testAnUnrecordableSettlementLeavesTheCaseAlone(): void {
		$this->store->refusedSchemas = ['casetransfer'];

		$refusal = $this->refusalOf(fn () => $this->handover()->refuse(transferId: 't-1', reason: 'fout', refusedBy: 'sofie'));

		$this->assertSame(expected: InternalHandover::CASE_UNREADABLE, actual: $refusal->getRule());
		$this->assertSame(expected: RefusedException::STATUS_INDETERMINATE, actual: $refusal->getStatus());
		$this->assertSame(expected: 'The handover could not be recorded, so the case stays where it is.', actual: $refusal->getSentence());
		$this->assertSame(expected: 'pending', actual: $this->store->row(schema: 'casetransfer', uuid: 't-1')['status']);
		$this->assertSame(expected: 'toezicht', actual: $this->store->row(schema: 'case', uuid: 'case-1')['assignedGroup']);
		$this->assertSame(expected: 0, actual: $this->store->writes);
	}//end testAnUnrecordableSettlementLeavesTheCaseAlone()

	/**
	 * A settled handover whose case has gone is still settled, and the gap is logged.
	 *
	 * @return void
	 */
	public function testASettlementWhoseCaseIsGoneIsLoggedNotRefused(): void {
		$this->store->rows['case'] = [];
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Dossiq handover: the case could not be brought in line with its settled handover',
				['caseId' => 'case-1', 'rule' => InternalHandover::CASE_UNREADABLE]
			);

		$settled = $this->handover()->accept(transferId: 't-1', acceptedBy: 'sofie');

		$this->assertSame(expected: 'accepted', actual: $settled['status']);
		$this->assertSame(expected: [], actual: $this->store->rows['case']);
	}//end testASettlementWhoseCaseIsGoneIsLoggedNotRefused()

	/**
	 * A case write that fails after a refusal is logged; the refusal itself stands.
	 *
	 * @return void
	 */
	public function testAFailingCaseWriteIsLoggedAndTheRefusalStands(): void {
		$this->store->refusedSchemas = ['case'];
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'Dossiq handover: the case could not be written',
				['caseId' => 'case-1', 'exception' => 'Save refused for case']
			);

		$settled = $this->handover()->refuse(transferId: 't-1', reason: 'Niet voor ons', refusedBy: 'sofie');

		$this->assertSame(expected: 'rejected', actual: $settled['status']);
		$this->assertSame(expected: 'Niet voor ons', actual: $this->store->row(schema: 'casetransfer', uuid: 't-1')['rejectionReason']);
		$this->assertSame(expected: 'toezicht', actual: $this->store->row(schema: 'case', uuid: 'case-1')['assignedGroup']);
	}//end testAFailingCaseWriteIsLoggedAndTheRefusalStands()

	/**
	 * A handover without a case id is settled without touching any case.
	 *
	 * @return void
	 */
	public function testAHandoverWithoutACaseIsSettledAlone(): void {
		$row = $this->store->row(schema: 'casetransfer', uuid: 't-1');
		$row['caseId'] = '';
		$this->store->seed(schema: 'casetransfer', uuid: 't-1', row: $row);

		$settled = $this->handover()->accept(transferId: 't-1', acceptedBy: 'sofie');

		$this->assertSame(expected: 'accepted', actual: $settled['status']);
		$this->assertSame(expected: 1, actual: $this->store->writes, message: 'Only the transfer record is written.');
		$this->assertTrue(condition: $this->store->row(schema: 'case', uuid: 'case-1')['handoverPending']);
	}//end testAHandoverWithoutACaseIsSettledAlone()
}//end class
