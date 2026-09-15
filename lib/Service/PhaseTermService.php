<?php

/**
 * Dossiq PhaseTermService.
 *
 * A phase carries its own clock, and it never moves the case's.
 *
 * `statusType` had an order and no clock, so an ontvankelijkheidstoets with two
 * weeks inside an eight week term was invisible until the eight weeks ran out.
 * Entering a phase starts a term instance of kind `phase`; leaving it stops that
 * instance. A phase over its term reads overdue on the case while the case term
 * still reads on time, which is exactly the pair a handler needs to see.
 *
 * 🔴 A PHASE TERM IS NOT A SECOND SOURCE OF TRUTH FOR THE CASE TERM.
 * The case term is bound at creation and stays bound. A phase running over eats
 * into it and says so; it never extends it. Nothing in this file writes to a
 * statutory instance.
 *
 * The chain term is the same mechanism read the other way: when a case type
 * declares one term for the whole chain, {@see ChainTermSplitter} splits it over
 * the phases by the share each declares, and a phase that overran shrinks the
 * ones after it. The chain's end does not move.
 *
 * This is NOT the maximum dwell of `what-a-status-declares` REQ-SDC-03. That one
 * is a ceiling on how long a case may sit in a status, breaching on its own
 * timer; this is the share of the case's own term a phase is given.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Starting, stopping and splitting phase terms (REQ-TERM-061, REQ-TERM-065).
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess) {@see TermKind} is a vocabulary: four
 * constants and four pure predicates over an array, with no state, no I/O and
 * nothing to inject. Making it an instance would add a constructor dependency
 * to every class that names a kind, to hide a `::` behind a `->`.
 */
class PhaseTermService {
	/**
	 * The status a stopped phase term carries.
	 *
	 * @var string
	 */
	public const STATUS_COMPLETED = 'completed';

	/**
	 * Constructor.
	 *
	 * @param TermijnService $termService The one writer of a term instance.
	 * @param CaseTermsService $terms Binding and reading the clocks on a case.
	 * @param TermDeclarationReader $declarations What the case type and its phases declare.
	 * @param ChainTermSplitter $splitter Splitting one chain term over its steps.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly CaseTermsService $terms,
		private readonly TermDeclarationReader $declarations,
		private readonly ChainTermSplitter $splitter,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * A case enters a phase: stop the phase clock it left, start this one.
	 *
	 * One call, because the two halves are one move. Two calls let a handler
	 * arrive in a new phase with the previous phase's clock still running, and
	 * the case then reads as overdue in a phase it has left.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $caseTypeId The case type UUID.
	 * @param string $statusTypeId The phase being entered.
	 * @param DateTimeImmutable|null $when When the move happened (default now).
	 *
	 * @return array<string, mixed>|null The started phase term, or null when the
	 *         phase declares no clock and the chain declares no term either.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function enterPhase(
		string $caseId,
		string $caseTypeId,
		string $statusTypeId,
		?DateTimeImmutable $when = null,
	): ?array {
		if ($caseId === '' || $statusTypeId === '') {
			return null;
		}

		$moment = ($when ?? new DateTimeImmutable());
		$this->stopRunningPhases(caseId: $caseId, when: $moment, except: $statusTypeId);

		$days = $this->daysForPhase(caseId: $caseId, caseTypeId: $caseTypeId, statusTypeId: $statusTypeId);
		if ($days <= 0) {
			return null;
		}

		$instance = $this->terms->bindLeadTime(
			caseId: $caseId,
			kind: TermKind::PHASE,
			days: $days,
			start: $moment,
			extra: ['statusType' => $statusTypeId],
		);

		$this->logger->info(
			'Dossiq phase term: a phase clock started',
			['case' => $caseId, 'phase' => $statusTypeId, 'days' => $days]
		);

		return $instance;
	}//end enterPhase()

	/**
	 * Stop every running phase clock on a case.
	 *
	 * @param string $caseId The case UUID.
	 * @param DateTimeImmutable|null $when When they stopped (default now).
	 * @param string $except A phase to leave running, when the case is entering it.
	 *
	 * @return int How many clocks were stopped.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function stopRunningPhases(string $caseId, ?DateTimeImmutable $when = null, string $except = ''): int {
		$moment = ($when ?? new DateTimeImmutable());

		$stopped = 0;
		foreach ($this->termService->instancesForCase(caseId: $caseId) as $row) {
			if (TermKind::ofInstance($row) !== TermKind::PHASE) {
				continue;
			}

			if ((string)($row['statusType'] ?? '') === $except) {
				continue;
			}

			if (in_array((string)($row['status'] ?? ''), ['lopend', 'verlengd', 'paused'], true) === false) {
				continue;
			}

			$this->termService->updateTermijnInstance(
				termInstanceId: (string)($row['id'] ?? ''),
				patch: [
					'status' => self::STATUS_COMPLETED,
					'voltooiDatum' => $moment->format('Y-m-d'),
				]
			);
			$stopped++;
		}//end foreach

		return $stopped;
	}//end stopRunningPhases()

	/**
	 * How many days this phase gets.
	 *
	 * A phase that declares its own term in days gets that. Otherwise, when the
	 * case type declares one term for the whole chain, this phase gets what the
	 * splitter leaves it: its declared share of whatever the phases already
	 * finished have not spent. A phase that overran therefore shrinks the ones
	 * after it, and the chain end stays where it was.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $caseTypeId The case type UUID.
	 * @param string $statusTypeId The phase being entered.
	 *
	 * @return int The phase's days, 0 when nothing declares any.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function daysForPhase(string $caseId, string $caseTypeId, string $statusTypeId): int {
		$own = $this->declarations->forStatusType(statusTypeId: $statusTypeId);
		if ($own['termDays'] > 0) {
			return $own['termDays'];
		}

		$declared = $this->declarations->forCaseType(caseTypeId: $caseTypeId);
		if ($declared['chainTermDays'] <= 0) {
			return 0;
		}

		$plan = $this->chainPlan(
			caseId: $caseId,
			caseTypeId: $caseTypeId,
			chainTermDays: $declared['chainTermDays'],
			statusTypeId: $statusTypeId
		);

		return (int)($plan['days'] ?? 0);
	}//end daysForPhase()

	/**
	 * What is left of the chain term, and how it splits over the phases ahead.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $caseTypeId The case type UUID.
	 * @param int $chainTermDays The whole chain term in days.
	 * @param string $statusTypeId The phase being entered.
	 *
	 * @return array{days: int, remainingDays: int, exhausted: bool, steps: array<int, int>}
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function chainPlan(
		string $caseId,
		string $caseTypeId,
		int $chainTermDays,
		string $statusTypeId,
	): array {
		$phases = $this->declarations->phasesOf(caseTypeId: $caseTypeId);
		$shares = array_map(static fn (array $phase): float => (float)$phase['share'], $phases);
		$position = $this->positionOf(phases: $phases, statusTypeId: $statusTypeId);

		$spent = $this->spentPerPhase(caseId: $caseId, phases: $phases, upTo: $position);
		$plan = $this->splitter->recompute(termDays: $chainTermDays, shares: $shares, consumed: $spent);

		return [
			'days' => (int)($plan['steps'][0] ?? 0),
			'remainingDays' => (int)$plan['remainingDays'],
			'exhausted' => (bool)$plan['exhausted'],
			'steps' => $plan['steps'],
		];
	}//end chainPlan()

	/**
	 * Where a phase sits in the declared order.
	 *
	 * @param array<int, array<string, mixed>> $phases The phases, in order.
	 * @param string $statusTypeId The phase to locate.
	 *
	 * @return int The zero-based position, 0 when the phase is not declared.
	 */
	private function positionOf(array $phases, string $statusTypeId): int {
		foreach ($phases as $index => $phase) {
			if ((string)($phase['id'] ?? '') === $statusTypeId) {
				return $index;
			}
		}//end foreach

		return 0;
	}//end positionOf()

	/**
	 * How many days each finished phase actually used.
	 *
	 * Read off the phase instances the case carries, so an early phase and a
	 * late one are both counted at what they cost rather than at what they were
	 * given. A phase the case never visited counts as zero, which is the
	 * reading that leaves its days to the phases ahead.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<int, array<string, mixed>> $phases The phases, in order.
	 * @param int $upTo How many phases are behind the case.
	 *
	 * @return array<int, int> Days spent, one per finished phase.
	 */
	private function spentPerPhase(string $caseId, array $phases, int $upTo): array {
		$byPhase = [];
		foreach ($this->termService->instancesForCase(caseId: $caseId) as $row) {
			if (TermKind::ofInstance($row) !== TermKind::PHASE) {
				continue;
			}

			$phaseId = (string)($row['statusType'] ?? '');
			$start = (string)($row['startDate'] ?? '');
			$stop = (string)($row['voltooiDatum'] ?? '');
			if ($phaseId === '' || $start === '' || $stop === '') {
				continue;
			}

			$byPhase[$phaseId] = $this->daysBetween(start: $start, stop: $stop);
		}//end foreach

		$spent = [];
		for ($index = 0; $index < $upTo; $index++) {
			$phaseId = (string)($phases[$index]['id'] ?? '');
			$spent[] = (int)($byPhase[$phaseId] ?? 0);
		}//end for

		return $spent;
	}//end spentPerPhase()

	/**
	 * Whole days between two readable dates.
	 *
	 * @param string $start The start.
	 * @param string $stop The stop.
	 *
	 * @return int The count, 0 when either side does not read as a date.
	 */
	private function daysBetween(string $start, string $stop): int {
		try {
			$from = (new DateTimeImmutable($start))->setTime(0, 0);
			$to = (new DateTimeImmutable($stop))->setTime(0, 0);
		} catch (\Throwable $e) {
			return 0;
		}

		return (int)$from->diff($to)->days;
	}//end daysBetween()
}//end class
