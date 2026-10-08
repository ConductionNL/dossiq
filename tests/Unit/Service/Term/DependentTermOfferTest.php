<?php

/**
 * Dossiq DependentTermOffer test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Term
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\Relation\CaseRelationStore;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Term\DependentTermOffer;
use OCA\Dossiq\Service\TermijnService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A term that moved is offered to the cases waiting on it, and nothing moves
 * until a person says so.
 *
 * @spec openspec/changes/dependent-term-follows-predecessor/specs/related-case-linking/spec.md
 */
class DependentTermOfferTest extends TestCase {
	/**
	 * The reverse relation index.
	 *
	 * @var CaseRelationStore&MockObject
	 */
	private CaseRelationStore $store;

	/**
	 * Term instances.
	 *
	 * @var TermijnService&MockObject
	 */
	private TermijnService $terms;

	/**
	 * The one way a term moves.
	 *
	 * @var DeadlineExtensionService&MockObject
	 */
	private DeadlineExtensionService $extension;

	/**
	 * The engine task the offer is.
	 *
	 * @var EngineTaskGateway&MockObject
	 */
	private EngineTaskGateway $tasks;

	/**
	 * The tasks written, in order.
	 *
	 * @var array<int, array{task: array<string, mixed>, case: string}>
	 */
	private array $written = [];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = $this->createMock(CaseRelationStore::class);
		$this->terms = $this->createMock(TermijnService::class);
		$this->extension = $this->createMock(DeadlineExtensionService::class);
		$this->tasks = $this->createMock(EngineTaskGateway::class);
		$this->written = [];

		$this->tasks->method('mirrorImport')->willReturnCallback(
			function (array $task, string $caseId, ?string $actor): string {
				$this->written[] = ['task' => $task, 'case' => $caseId];
				return 'engine-' . count($this->written);
			}
		);
	}//end setUp()

	/**
	 * Two cases waiting on the moved one get two offers.
	 *
	 * @return void
	 */
	public function testEveryWaitingCaseIsOfferedTheMove(): void {
		$this->waitingOn(
			[
				['id' => 'case-b', 'title' => 'Vergunning B'],
				['id' => 'case-c', 'title' => 'Vergunning C'],
			]
		);
		$this->termsAreRunning();

		$made = $this->offer()->offer(
			sourceCaseId: 'case-a',
			sourceTitle: 'Bezwaar A',
			daysImpact: 14
		);

		$this->assertSame(2, $made);
		$this->assertSame(['case-b', 'case-c'], array_column($this->written, 'case'));
		$this->assertStringContainsString('Bezwaar A', $this->written[0]['task']['title']);
		$this->assertStringContainsString('14', $this->written[0]['task']['title']);
		$this->assertSame(
			[
				'kind' => 'term-follow',
				'sourceCase' => 'case-a',
				'daysImpact' => 14,
				'deadlineInstance' => 'term-case-b',
			],
			$this->written[0]['task']['metadata']['dossiq']
		);
	}//end testEveryWaitingCaseIsOfferedTheMove()

	/**
	 * A case nobody waits on offers nothing.
	 *
	 * @return void
	 */
	public function testACaseNobodyWaitsOnOffersNothing(): void {
		$this->waitingOn([]);

		$this->assertSame(0, $this->offer()->offer(sourceCaseId: 'case-a', sourceTitle: 'A', daysImpact: 14));
		$this->assertSame([], $this->written);
	}//end testACaseNobodyWaitsOnOffersNothing()

	/**
	 * A link of another type is not a wait, so it is offered nothing.
	 *
	 * @return void
	 */
	public function testOnlyTheWaitsOnLinkIsOfferedTheMove(): void {
		$this->store->method('relationRows')->willReturn(
			[
				[
					'id' => 'case-b',
					'title' => 'Vervolgzaak B',
					'relation' => ['property' => 'followUpCases', 'type' => 'vervolg'],
				],
			]
		);
		$this->termsAreRunning();

		$this->assertSame(0, $this->offer()->offer(sourceCaseId: 'case-a', sourceTitle: 'A', daysImpact: 14));
	}//end testOnlyTheWaitsOnLinkIsOfferedTheMove()

	/**
	 * A waiting case whose own term already ended has nothing to decide.
	 *
	 * @return void
	 */
	public function testAWaitingCaseWithNoRunningTermIsNotBothered(): void {
		$this->waitingOn([['id' => 'case-b', 'title' => 'Vergunning B']]);
		$this->terms->method('getTermijnInstanceForZaak')
			->willReturn(['id' => 'term-case-b', 'status' => 'completed']);

		$this->assertSame(0, $this->offer()->offer(sourceCaseId: 'case-a', sourceTitle: 'A', daysImpact: 14));
	}//end testAWaitingCaseWithNoRunningTermIsNotBothered()

	/**
	 * The fan-out is bounded, and says so.
	 *
	 * @return void
	 */
	public function testTheFanOutIsBounded(): void {
		$rows = [];
		for ($i = 0; $i < DependentTermOffer::MAX_DEPENDENTS + 5; $i++) {
			$rows[] = ['id' => 'case-' . $i, 'title' => 'Zaak ' . $i];
		}

		$this->waitingOn($rows);

		$this->assertCount(
			DependentTermOffer::MAX_DEPENDENTS,
			$this->offer()->dependentsOf(sourceCaseId: 'case-a')
		);
	}//end testTheFanOutIsBounded()

	/**
	 * Scenario: Accepting extends with the reason.
	 *
	 * @return void
	 */
	public function testAcceptingExtendsByTheOfferedDaysWithTheReason(): void {
		$this->offerIsOnTheTask();
		$this->terms->method('getTermijnInstance')
			->willReturn(['id' => 'term-case-b', 'endDateCurrent' => '2026-10-01']);

		$asked = [];
		$this->extension->method('requestExtension')->willReturnCallback(
			static function (string $termInstanceId, string $rationale, string $newEndDate, string $documentLink = '') use (&$asked): array {
				$asked = [
					'instance' => $termInstanceId,
					'rationale' => $rationale,
					'end' => $newEndDate,
				];
				return ['type' => 'verleng', 'rationale' => $rationale];
			}
		);
		$this->tasks->expects($this->once())->method('complete')
			->with('task-1', [], 'accepted', 'behandelaar');

		$outcome = $this->offer()->accept(taskId: 'task-1', actor: 'behandelaar');

		$this->assertArrayNotHasKey('refused', $outcome);
		$this->assertSame('term-case-b', $asked['instance']);
		$this->assertSame('follows case-a', $asked['rationale']);
		$this->assertSame('2026-10-15', $asked['end']);
	}//end testAcceptingExtendsByTheOfferedDaysWithTheReason()

	/**
	 * Scenario: The ceiling still applies. The refusal is the ordinary one and
	 * the task stays open, because the handler still has a decision to make.
	 *
	 * @return void
	 */
	public function testTheExtensionCeilingRefusesAnOfferedMoveToo(): void {
		$this->offerIsOnTheTask();
		$this->terms->method('getTermijnInstance')
			->willReturn(['id' => 'term-case-b', 'endDateCurrent' => '2026-10-01']);
		$this->extension->method('requestExtension')
			->willThrowException(new RuntimeException('AWB 4:14 lid 1: het aantal verlengingen is bereikt'));
		$this->tasks->expects($this->never())->method('complete');

		$outcome = $this->offer()->accept(taskId: 'task-1', actor: 'behandelaar');

		$this->assertSame('extension-refused', $outcome['refused']);
	}//end testTheExtensionCeilingRefusesAnOfferedMoveToo()

	/**
	 * Declining moves nothing and closes the offer.
	 *
	 * @return void
	 */
	public function testDecliningMovesNothing(): void {
		$this->offerIsOnTheTask();
		$this->extension->expects($this->never())->method('requestExtension');
		$this->tasks->expects($this->once())->method('complete')
			->with('task-1', [], 'declined', 'behandelaar')
			->willReturn(true);

		$this->assertTrue($this->offer()->decline(taskId: 'task-1', actor: 'behandelaar'));
	}//end testDecliningMovesNothing()

	/**
	 * A task that is not one of ours is not settled through this door.
	 *
	 * @return void
	 */
	public function testAnUnrelatedTaskIsRefused(): void {
		$this->tasks->method('find')->willReturn(
			['id' => 'task-9', 'objectUuid' => 'case-b', 'metadata' => []]
		);
		$this->tasks->expects($this->never())->method('complete');

		$this->assertSame(
			'not-a-term-follow-task',
			$this->offer()->accept(taskId: 'task-9', actor: 'behandelaar')['refused']
		);
		$this->assertFalse($this->offer()->decline(taskId: 'task-9', actor: 'behandelaar'));
	}//end testAnUnrelatedTaskIsRefused()

	/**
	 * The reverse index answers these rows as waits.
	 *
	 * @param array<int, array<string, mixed>> $rows The waiting cases.
	 *
	 * @return void
	 */
	private function waitingOn(array $rows): void {
		$this->store->method('relationRows')->willReturn(
			array_map(
				static fn (array $row): array => ($row + [
					'relation' => ['property' => 'blockingCases', 'type' => 'waitsOn'],
				]),
				$rows
			)
		);
	}//end waitingOn()

	/**
	 * Every waiting case has a running term.
	 *
	 * @return void
	 */
	private function termsAreRunning(): void {
		$this->terms->method('getTermijnInstanceForZaak')->willReturnCallback(
			static fn (string $caseId): array => ['id' => 'term-' . $caseId, 'status' => 'lopend']
		);
	}//end termsAreRunning()

	/**
	 * The engine answers task-1 with the offer this service wrote.
	 *
	 * @return void
	 */
	private function offerIsOnTheTask(): void {
		$this->tasks->method('find')->willReturn(
			[
				'id' => 'task-1',
				'objectUuid' => 'case-b',
				'metadata' => [
					'dossiq' => [
						'kind' => 'term-follow',
						'sourceCase' => 'case-a',
						'daysImpact' => 14,
						'deadlineInstance' => 'term-case-b',
					],
				],
			]
		);
	}//end offerIsOnTheTask()

	/**
	 * The service over the doubles.
	 *
	 * @return DependentTermOffer The service under test.
	 */
	private function offer(): DependentTermOffer {
		return new DependentTermOffer(
			store: $this->store,
			terms: $this->terms,
			extension: $this->extension,
			tasks: $this->tasks,
			logger: new NullLogger()
		);
	}//end offer()
}//end class
