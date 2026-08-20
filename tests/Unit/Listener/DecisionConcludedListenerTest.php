<?php

/**
 * DecisionConcludedListener Unit Tests
 *
 * Verifies that procest materialises the ZGW Besluit from decidesk's
 * DecisionConcludedEvent: events for this source app with a terminal status are
 * projected onto the matching case via BesluitMaterialisationService; events
 * from another source app, or with a non-terminal status, are ignored
 * (REQ-PDCD-003).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Listener;

use OCA\Decidesk\Event\DecisionConcludedEvent;
use OCA\Procest\Listener\DecisionConcludedListener;
use OCA\Procest\Service\BesluitMaterialisationService;
use OCA\Procest\Service\Bezwaar\AdvisoryCommitteeService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Object-service stub exposing the OpenRegister search/find surface the
 * SearchesObjects trait calls.
 */
interface ConcludedObjectServiceStub {
	/**
	 * @param string $registerSlug Register slug.
	 * @param string $schemaSlug Schema slug.
	 * @param array<string,mixed> $filters Filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters): array;
}//end interface

/**
 * Unit tests for DecisionConcludedListener.
 *
 * @covers \OCA\Procest\Listener\DecisionConcludedListener
 */
class DecisionConcludedListenerTest extends TestCase {
	/**
	 * A terminal decidesk outcome for this app materialises the ZGW Besluit.
	 *
	 * @return void
	 */
	public function testMaterialisesBesluitForProcestSourceApp(): void {
		$objectService = $this->createMock(ConcludedObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')
			->willReturn([['decisionRef' => 'dec-1', 'case' => 'case-9', 'besluitRef' => 'bes-2']]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		$materialiser = $this->createMock(BesluitMaterialisationService::class);
		$materialiser->expects($this->once())
			->method('materialiseFromConcludedEvent')
			->willReturnCallback(
				function (string $caseId, string $decisionId, array $event): array {
					$this->assertSame('case-9', $caseId);
					$this->assertSame('bes-2', $decisionId);
					$this->assertSame('approved', $event['status']);
					return ['ok' => true];
				}
			);

		$listener = new DecisionConcludedListener(
			$settings,
			$materialiser,
			$this->createMock(AdvisoryCommitteeService::class),
			$this->createMock(LoggerInterface::class)
		);

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));
	}//end testMaterialisesBesluitForProcestSourceApp()

	/**
	 * Events from another source app are ignored.
	 *
	 * @return void
	 */
	public function testIgnoresOtherSourceApp(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('getObjectService');

		$materialiser = $this->createMock(BesluitMaterialisationService::class);
		$materialiser->expects($this->never())->method('materialiseFromConcludedEvent');

		$listener = new DecisionConcludedListener(
			$settings,
			$materialiser,
			$this->createMock(AdvisoryCommitteeService::class),
			$this->createMock(LoggerInterface::class)
		);

		$listener->handle($this->event(sourceApp: 'docudesk', status: 'approved'));
	}//end testIgnoresOtherSourceApp()

	/**
	 * A non-terminal (pending) status does not materialise a Besluit.
	 *
	 * @return void
	 */
	public function testIgnoresNonTerminalStatus(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('getObjectService');

		$materialiser = $this->createMock(BesluitMaterialisationService::class);
		$materialiser->expects($this->never())->method('materialiseFromConcludedEvent');

		$listener = new DecisionConcludedListener(
			$settings,
			$materialiser,
			$this->createMock(AdvisoryCommitteeService::class),
			$this->createMock(LoggerInterface::class)
		);

		$listener->handle($this->event(sourceApp: 'procest', status: 'pending'));
	}//end testIgnoresNonTerminalStatus()

	/**
	 * A concluded besluit that departs from the BAC advice mirrors the
	 * deviation onto the advice request's audit trail (Awb art. 7:13 lid 7).
	 *
	 * @return void
	 */
	public function testRecordsCouncilDeviationWhenDecisionDepartsFromAdvice(): void {
		$bac = $this->createMock(AdvisoryCommitteeService::class);

		$listener = $this->listenerForDecision(
			record: [
				'decisionRef' => 'dec-1',
				'case' => 'case-9',
				'besluitRef' => 'bes-2',
				'advisoryOpinion' => 'bac-req-7',
				'followsAdvice' => false,
				'deviationRationale' => 'Commissie miste de nieuwe feiten',
			],
			bac: $bac
		);

		$bac->expects($this->once())
			->method('recordCouncilDeviation')
			->with('bac-req-7', 'bes-2', 'Commissie miste de nieuwe feiten');

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));
	}//end testRecordsCouncilDeviationWhenDecisionDepartsFromAdvice()

	/**
	 * A besluit that follows the committee advice records no deviation.
	 *
	 * @return void
	 */
	public function testRecordsNoDeviationWhenDecisionFollowsAdvice(): void {
		$bac = $this->createMock(AdvisoryCommitteeService::class);

		$listener = $this->listenerForDecision(
			record: [
				'decisionRef' => 'dec-1',
				'case' => 'case-9',
				'besluitRef' => 'bes-2',
				'advisoryOpinion' => 'bac-req-7',
				'followsAdvice' => true,
			],
			bac: $bac
		);

		$bac->expects($this->never())->method('recordCouncilDeviation');

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));
	}//end testRecordsNoDeviationWhenDecisionFollowsAdvice()

	/**
	 * A besluit that was never referred to a committee records no deviation.
	 *
	 * @return void
	 */
	public function testRecordsNoDeviationWhenNoCommitteeWasInvolved(): void {
		$bac = $this->createMock(AdvisoryCommitteeService::class);

		$listener = $this->listenerForDecision(
			record: [
				'decisionRef' => 'dec-1',
				'case' => 'case-9',
				'besluitRef' => 'bes-2',
			],
			bac: $bac
		);

		$bac->expects($this->never())->method('recordCouncilDeviation');

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));
	}//end testRecordsNoDeviationWhenNoCommitteeWasInvolved()

	/**
	 * Build a listener whose decisionRef lookup resolves to $record.
	 *
	 * @param array<string,mixed> $record The bezwaarDecision record the search returns.
	 * @param AdvisoryCommitteeService $bac The BAC service mock the listener writes through.
	 *
	 * @return DecisionConcludedListener
	 */
	private function listenerForDecision(array $record, AdvisoryCommitteeService $bac): DecisionConcludedListener {
		$objectService = $this->createMock(ConcludedObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')->willReturn([$record]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		return new DecisionConcludedListener(
			$settings,
			$this->createMock(BesluitMaterialisationService::class),
			$bac,
			$this->createMock(LoggerInterface::class)
		);
	}//end listenerForDecision()

	/**
	 * Build a DecisionConcludedEvent fixture.
	 *
	 * @param string $sourceApp The source app id.
	 * @param string $status The terminal/non-terminal status.
	 *
	 * @return DecisionConcludedEvent
	 */
	private function event(string $sourceApp, string $status): DecisionConcludedEvent {
		return new DecisionConcludedEvent(
			'dec-1',
			'contract-renewal',
			$status,
			'granted',
			false,
			null,
			[],
			'2026-06-15T10:00:00+00:00',
			$sourceApp,
			'register-slug',
			'supplierContract',
			'sub-1',
			'case-9',
			'corr-1'
		);
	}//end event()
}//end class
