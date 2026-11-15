<?php

/**
 * The pipeline's ordering, short-circuit and failure logging, with scripted filters.
 *
 * The shipped-filter behaviour is covered by FilterPipelineTest; this file pins
 * the mechanics that test cannot reach: the name tiebreak when two filters
 * claim the same slot, that the walk stops at the first decisive verdict, the
 * exact log line a throwing filter leaves, and the written-down default.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email\Filters
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email\Filters;

use OCA\Dossiq\Service\Email\Filters\FilterOutcome;
use OCA\Dossiq\Service\Email\Filters\FilterPipeline;
use OCA\Dossiq\Service\Email\Filters\FilterVerdict;
use OCA\Dossiq\Service\Email\Filters\InboundMailFilter;
use OCA\Dossiq\Service\Email\InboundMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for FilterPipeline's ordering and walk.
 *
 * @covers \OCA\Dossiq\Service\Email\Filters\FilterPipeline
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterOutcome
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterVerdict
 * @uses \OCA\Dossiq\Service\Email\InboundMessage
 */
class FilterPipelineOrderTest extends TestCase {

	/**
	 * Names of the filters whose decide() ran, in call order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * A filter with a fixed name and order that answers with a fixed verdict.
	 *
	 * @param string             $name    The filter name.
	 * @param integer            $order   The declared order.
	 * @param FilterVerdict|null $verdict The verdict, or null to throw.
	 *
	 * @return InboundMailFilter The filter.
	 */
	private function filter(string $name, int $order, ?FilterVerdict $verdict): InboundMailFilter {
		$calls = &$this->calls;

		return new class ($name, $order, $verdict, $calls) implements InboundMailFilter {
			/**
			 * Constructor.
			 *
			 * @param string             $name    The name.
			 * @param integer            $order   The order.
			 * @param FilterVerdict|null $verdict The verdict, or null to throw.
			 * @param array<int, string> $calls   The shared call log.
			 */
			public function __construct(
				private string $name,
				private int $order,
				private ?FilterVerdict $verdict,
				private array &$calls,
			) {
			}

			/**
			 * The name.
			 *
			 * @return string The name.
			 */
			public function name(): string {
				return $this->name;
			}

			/**
			 * The order.
			 *
			 * @return integer The order.
			 */
			public function order(): int {
				return $this->order;
			}

			/**
			 * Record the call and answer, or throw when no verdict was given.
			 *
			 * @param InboundMessage $message The message.
			 *
			 * @return FilterVerdict The fixed verdict.
			 */
			public function decide(InboundMessage $message): FilterVerdict {
				unset($message);
				$this->calls[] = $this->name;
				if ($this->verdict === null) {
					throw new RuntimeException('boom from ' . $this->name);
				}

				return $this->verdict;
			}
		};
	}//end filter()

	/**
	 * A message the scripted filters can be asked about.
	 *
	 * @return InboundMessage The message.
	 */
	private function message(): InboundMessage {
		return new InboundMessage(
			accountId: 3,
			mailbox: 'INBOX',
			uid: 11,
			subject: 'Aanvraag',
			from: 'aanvrager@voorbeeld.nl',
		);
	}//end message()

	/**
	 * Filters run by their declared order, not by the order they were handed over in.
	 *
	 * @return void
	 */
	public function testTheDeclaredOrderFollowsOrderNotArgumentPosition(): void {
		$pipeline = new FilterPipeline(
			logger: new NullLogger(),
			filters: [
				$this->filter(name: 'late', order: 90, verdict: FilterVerdict::pass()),
				$this->filter(name: 'early', order: 5, verdict: FilterVerdict::pass()),
				$this->filter(name: 'middle', order: 40, verdict: FilterVerdict::pass()),
			]
		);

		self::assertSame(['early', 'middle', 'late'], $pipeline->declaredOrder());

		$pipeline->run(message: $this->message());
		self::assertSame(['early', 'middle', 'late'], $this->calls);
	}//end testTheDeclaredOrderFollowsOrderNotArgumentPosition()

	/**
	 * Two filters claiming the same slot are ordered by name, both ways round.
	 *
	 * @return void
	 */
	public function testASharedSlotIsBrokenByName(): void {
		$zeta  = $this->filter(name: 'zeta', order: 10, verdict: FilterVerdict::pass());
		$alpha = $this->filter(name: 'alpha', order: 10, verdict: FilterVerdict::pass());
		$first = $this->filter(name: 'first', order: 1, verdict: FilterVerdict::pass());

		$forward  = new FilterPipeline(logger: new NullLogger(), filters: [$zeta, $alpha, $first]);
		$backward = new FilterPipeline(logger: new NullLogger(), filters: [$first, $alpha, $zeta]);

		self::assertSame(['first', 'alpha', 'zeta'], $forward->declaredOrder());
		self::assertSame(['first', 'alpha', 'zeta'], $backward->declaredOrder());
	}//end testASharedSlotIsBrokenByName()

	/**
	 * When two filters in one slot both refuse, the name that sorts first is recorded.
	 *
	 * @return void
	 */
	public function testTheRecordedNameIsStableWhenTwoFiltersInOneSlotRefuse(): void {
		$pipeline = new FilterPipeline(
			logger: new NullLogger(),
			filters: [
				$this->filter(name: 'out-of-office', order: 40, verdict: FilterVerdict::reject(filterName: 'out-of-office', reason: 'ooo')),
				$this->filter(name: 'auto-reply', order: 40, verdict: FilterVerdict::reject(filterName: 'auto-reply', reason: 'auto')),
			]
		);

		$verdict = $pipeline->run(message: $this->message());

		self::assertSame('auto-reply', $verdict->filterName);
		self::assertSame('auto', $verdict->reason);
		self::assertSame(['auto-reply'], $this->calls, 'the second refusal is never asked');
	}//end testTheRecordedNameIsStableWhenTwoFiltersInOneSlotRefuse()

	/**
	 * A pass moves on; the first decisive verdict is returned as-is and ends the walk.
	 *
	 * @return void
	 */
	public function testThePassMovesOnAndTheFirstDecisiveVerdictEndsTheWalk(): void {
		$forward = FilterVerdict::forward(filterName: 'router', reason: 'belongs elsewhere', forwardTo: 'post@voorbeeld.nl');

		$pipeline = new FilterPipeline(
			logger: new NullLogger(),
			filters: [
				$this->filter(name: 'quiet', order: 1, verdict: FilterVerdict::pass(filterName: 'quiet')),
				$this->filter(name: 'router', order: 2, verdict: $forward),
				$this->filter(name: 'never', order: 3, verdict: FilterVerdict::reject(filterName: 'never', reason: 'x')),
			]
		);

		$verdict = $pipeline->run(message: $this->message());

		self::assertSame($forward, $verdict);
		self::assertSame(FilterOutcome::FORWARD, $verdict->outcome);
		self::assertSame('post@voorbeeld.nl', $verdict->forwardTo);
		self::assertSame(['quiet', 'router'], $this->calls);
	}//end testThePassMovesOnAndTheFirstDecisiveVerdictEndsTheWalk()

	/**
	 * An explicit accept from a filter is decisive too, so later filters are not asked.
	 *
	 * @return void
	 */
	public function testAnExplicitAcceptStopsTheWalk(): void {
		$pipeline = new FilterPipeline(
			logger: new NullLogger(),
			filters: [
				$this->filter(name: 'allow', order: 1, verdict: FilterVerdict::accept(filterName: 'allow', reason: 'trusted sender')),
				$this->filter(name: 'junk', order: 2, verdict: FilterVerdict::quarantine(filterName: 'junk', reason: 'spam')),
			]
		);

		$verdict = $pipeline->run(message: $this->message());

		self::assertSame(FilterOutcome::ACCEPT, $verdict->outcome);
		self::assertSame('allow', $verdict->filterName);
		self::assertSame('trusted sender', $verdict->reason);
		self::assertSame(['allow'], $this->calls);
	}//end testAnExplicitAcceptStopsTheWalk()

	/**
	 * A throwing filter is logged once, by name and error, and the walk continues.
	 *
	 * @return void
	 */
	public function testAThrowingFilterIsLoggedByNameAndTheWalkContinues(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('error')
			->with(
				'Dossiq: the {filter} intake filter threw, so it decided nothing',
				['filter' => 'broken', 'error' => 'boom from broken']
			);

		$pipeline = new FilterPipeline(
			logger: $logger,
			filters: [
				$this->filter(name: 'broken', order: 1, verdict: null),
				$this->filter(name: 'junk', order: 2, verdict: FilterVerdict::quarantine(filterName: 'junk', reason: 'spam')),
			]
		);

		$verdict = $pipeline->run(message: $this->message());

		self::assertSame(FilterOutcome::QUARANTINE, $verdict->outcome);
		self::assertSame('junk', $verdict->filterName);
		self::assertSame(['broken', 'junk'], $this->calls);
	}//end testAThrowingFilterIsLoggedByNameAndTheWalkContinues()

	/**
	 * When every filter throws or passes, the written-down default accepts.
	 *
	 * @return void
	 */
	public function testOnlyThrowsAndPassesFallThroughToTheDefault(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');

		$pipeline = new FilterPipeline(
			logger: $logger,
			filters: [
				$this->filter(name: 'broken', order: 1, verdict: null),
				$this->filter(name: 'quiet', order: 2, verdict: FilterVerdict::pass(filterName: 'quiet')),
			]
		);

		$verdict = $pipeline->run(message: $this->message());

		self::assertSame(FilterOutcome::DEFAULT_OUTCOME, $verdict->outcome);
		self::assertSame('', $verdict->filterName);
		self::assertSame('No filter objected, and the declared default is to accept.', $verdict->reason);
		self::assertSame('', $verdict->forwardTo);
	}//end testOnlyThrowsAndPassesFallThroughToTheDefault()

	/**
	 * An empty pipeline declares no order and accepts by the default.
	 *
	 * @return void
	 */
	public function testAnEmptyPipelineDeclaresNothingAndAcceptsByDefault(): void {
		$pipeline = new FilterPipeline(logger: new NullLogger());

		self::assertSame([], $pipeline->declaredOrder());

		$verdict = $pipeline->run(message: $this->message());
		self::assertSame(FilterOutcome::ACCEPT, $verdict->outcome);
		self::assertSame('', $verdict->filterName);
	}//end testAnEmptyPipelineDeclaresNothingAndAcceptsByDefault()
}//end class
