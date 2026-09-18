<?php

/**
 * Dossiq CaseTermsService.
 *
 * The four clocks on a case: bound, read, and reported apart.
 *
 * A gemeente runs four clocks and dossiq ran one. This service binds the three
 * the case type declares beside the statutory term, reads all four back with
 * the kind on each, and computes the progress and the days left at read time.
 *
 * 🔴 IT COMPUTES DATES AND THEREFORE REACHES THE CALENDAR (REQ-TERM-060).
 * Every end date it decides is rolled through
 * {@see TermijnTimerService::rollTermEndFor()}, so the organisation's own
 * working calendar decides the day a term lands on and this app never holds a
 * second holiday list. The statutory path inside {@see TermijnService} is
 * deliberately NOT touched here: it is allowlisted to
 * `terms-on-the-engine-calendar`, and moving it would take another lane's task.
 *
 * Nothing it computes is stored. A progress percentage is wrong the moment a
 * term moves, and terms move on every pause, extension and calendar change, so
 * the figure is derived on every read from the bound terms.
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
use OCA\Dossiq\Service\Termijn\TermMoveHistory;
use Psr\Log\LoggerInterface;

/**
 * Binding, reading and reporting the four clocks on a case.
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess) {@see TermKind} is a vocabulary: four
 * constants and four pure predicates over an array, with no state, no I/O and
 * nothing to inject. Making it an instance would add a constructor dependency
 * to every class that names a kind, to hide a `::` behind a `->`.
 */
class CaseTermsService {
	/**
	 * Constructor.
	 *
	 * @param TermijnService $termService The one writer of a term instance.
	 * @param TermDeclarationReader $declarations What the case type and its phases declare.
	 * @param TermijnTimerService $timers The engine calendar bridge.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly TermDeclarationReader $declarations,
		private readonly TermijnTimerService $timers,
		private readonly LoggerInterface $logger,
		private readonly ?TermMoveHistory $moves = null,
	) {
	}//end __construct()

	/**
	 * Bind everything the case type declares beside the statutory term.
	 *
	 * Called after the statutory term is bound, so a case type declaring a
	 * fixed closing date has its statutory end moved onto that date and a case
	 * type declaring none is left alone. The planned end and the internal
	 * target are their own instances, never fields on the case.
	 *
	 * A clock that is declared nowhere is not bound. That is not a silent
	 * failure: `termsForCase()` answers with the clocks that exist, and a case
	 * showing one clock is a case whose type declares one.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $caseTypeId The case type UUID.
	 * @param DateTimeImmutable|null $start When the clocks start (default now).
	 * @param string $plannedStart The planned start date, `Y-m-d`, when the case carries one.
	 *
	 * @return array<int, array<string, mixed>> The instances this call created or moved.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function bindForCase(
		string $caseId,
		string $caseTypeId,
		?DateTimeImmutable $start = null,
		string $plannedStart = '',
	): array {
		if ($caseId === '' || $caseTypeId === '') {
			return [];
		}

		$from = ($start ?? new DateTimeImmutable());
		$declared = $this->declarations->forCaseType(caseTypeId: $caseTypeId);

		$bound = [];

		$statutory = $this->bindStatutory(caseId: $caseId, declared: $declared, start: $from);
		if ($statutory !== null) {
			$bound[] = $statutory;
		}

		$plannedExtra = [];
		if ($plannedStart !== '') {
			$plannedExtra = ['plannedStartDate' => $plannedStart];
		}

		$planned = $this->bindLeadTime(
			caseId: $caseId,
			kind: TermKind::PLANNED,
			days: $declared['plannedLeadTimeDays'],
			start: $from,
			extra: $plannedExtra,
		);
		if ($planned !== null) {
			$bound[] = $planned;
		}

		$internal = $this->bindLeadTime(
			caseId: $caseId,
			kind: TermKind::INTERNAL,
			days: $declared['internalTargetDays'],
			start: $from,
		);
		if ($internal !== null) {
			$bound[] = $internal;
		}

		return $bound;
	}//end bindForCase()

	/**
	 * Put the statutory term on the case type's fixed closing date (REQ-TERM-064).
	 *
	 * A subsidy round closes on a date, not so many days after each
	 * application. When the case type declares one, every case of that type
	 * ends on that day, whenever it came in, and everything downstream reads a
	 * bound end date without caring which form produced it.
	 *
	 * A date already past binds an expired term VISIBLY rather than refusing
	 * the case. A late application to a closed round is a real case with a real
	 * answer, and refusing to create it would lose the application.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<string, mixed> $declared The case type's declarations.
	 * @param DateTimeImmutable $start When the clock starts.
	 *
	 * @return array<string, mixed>|null The instance, or null when no fixed date is declared.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	private function bindStatutory(string $caseId, array $declared, DateTimeImmutable $start): ?array {
		$fixed = (string)$declared['fixedEndDate'];
		if ($fixed === '') {
			return null;
		}

		$end = $this->timers->rollTermEndFor(date: new DateTimeImmutable($fixed))->format('Y-m-d');
		$existing = $this->termService->getTermijnInstanceForZaak(caseId: $caseId);

		if ($existing !== null && TermKind::ofInstance($existing) === TermKind::STATUTORY) {
			return $this->termService->updateTermijnInstance(
				termInstanceId: (string)($existing['id'] ?? ''),
				patch: [
					'kind' => TermKind::STATUTORY,
					'endDateCalculated' => $end,
					'endDateCurrent' => $end,
				]
			);
		}

		return $this->termService->saveTermInstance(
			instance: [
				'case' => $caseId,
				'kind' => TermKind::STATUTORY,
				'startDate' => $start->format('Y-m-d\TH:i:sP'),
				'endDateCalculated' => $end,
				'endDateCurrent' => $end,
				'status' => 'lopend',
				'countExtensions' => 0,
				'notificatiesVerstuurd' => [],
			]
		);
	}//end bindStatutory()

	/**
	 * Bind one lead-time clock, on the administered calendar.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $kind The kind this clock carries.
	 * @param int $days The declared lead time in days, 0 when undeclared.
	 * @param DateTimeImmutable $start When it starts.
	 * @param array<string, mixed> $extra Extra fields to write on the instance.
	 *
	 * @return array<string, mixed>|null The instance, or null when nothing is declared.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function bindLeadTime(
		string $caseId,
		string $kind,
		int $days,
		DateTimeImmutable $start,
		array $extra = [],
	): ?array {
		if ($days <= 0 || TermKind::isKnown($kind) === false) {
			return null;
		}

		$end = $this->endAfter(start: $start, days: $days);

		return $this->termService->saveTermInstance(
			instance: array_merge(
				[
					'case' => $caseId,
					'kind' => $kind,
					'startDate' => $start->format('Y-m-d\TH:i:sP'),
					'endDateCalculated' => $end,
					'endDateCurrent' => $end,
					'status' => 'lopend',
					'countExtensions' => 0,
					'notificatiesVerstuurd' => [],
				],
				$extra
			)
		);
	}//end bindLeadTime()

	/**
	 * The day a clock of N days starting here ends on.
	 *
	 * The count is case data and stays here, as REQ-TOT-002 requires; only the
	 * day it lands on is decided by the calendar the organisation administers.
	 *
	 * @param DateTimeImmutable $start When the clock starts.
	 * @param int $days How many days it runs.
	 *
	 * @return string The end date as `Y-m-d`.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function endAfter(DateTimeImmutable $start, int $days): string {
		$raw = $start->modify('+' . max(0, $days) . ' days');

		return $this->timers->rollTermEndFor(date: $raw)->format('Y-m-d');
	}//end endAfter()

	/**
	 * Every clock on a case, each naming its kind (REQ-TERM-060).
	 *
	 * @param string $caseId The case UUID.
	 * @param DateTimeImmutable|null $now The moment to judge against (default now).
	 *
	 * @return array<int, array{id: string, kind: string, startDate: string, endDate: string,
	 *               status: string, statusType: string, plannedStartDate: string,
	 *               daysLeft: int, overdue: bool, citizenVisible: bool,
	 *               pauseReason: string, pauseWaitingOn: string, chasesSent: int}>
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function termsForCase(string $caseId, ?DateTimeImmutable $now = null): array {
		$today = ($now ?? new DateTimeImmutable())->setTime(0, 0);

		$terms = [];
		foreach ($this->termService->instancesForCase(caseId: $caseId) as $row) {
			$kind = TermKind::ofInstance($row);
			$end = (string)($row['endDateCurrent'] ?? ($row['endDateCalculated'] ?? ''));
			$daysLeft = $this->daysLeft(end: $end, today: $today);

			$terms[] = [
				'id' => (string)($row['id'] ?? ($row['uuid'] ?? '')),
				'kind' => $kind,
				'startDate' => (string)($row['startDate'] ?? ''),
				'endDate' => $end,
				'status' => (string)($row['status'] ?? ''),
				'statusType' => (string)($row['statusType'] ?? ''),
				'plannedStartDate' => (string)($row['plannedStartDate'] ?? ''),
				'daysLeft' => $daysLeft,
				'overdue' => ($end !== '' && $daysLeft < 0 && $this->isRunning(row: $row) === true),
				'citizenVisible' => TermKind::isCitizenVisible($kind),
				// A suspended clock reads differently from a stopped one: the
				// panel has to say WHY it stopped and what has been tried since,
				// or a handler looking at a case that has sat still for nine
				// days cannot tell a reminder is already overdue.
				'pauseReason' => (string)($row['pauseReason'] ?? ''),
				'pauseWaitingOn' => (string)($row['pauseWaitingOn'] ?? ''),
				'chasesSent' => max(0, (int)($row['chasesSent'] ?? 0)),
				// WHY THIS DATE IS NOT THE DATE IT WAS. A deadline that
				// quietly became another deadline is the thing a handler
				// cannot see and an applicant will argue about, so every move
				// the engine recorded travels with the term rather than
				// waiting for somebody to go and look for it.
				'moves' => ($this->moves?->movesFor(instance: $row) ?? []),
			];
		}//end foreach

		return $terms;
	}//end termsForCase()

	/**
	 * How far the case has got, and how long it has left (REQ-TERM-068).
	 *
	 * Progress is the phases completed and the term consumed, halved together:
	 * a case two phases into four with half its term gone reads fifty. Neither
	 * half is stored, and the same call is what a list column will read when
	 * the library offers one, so the list and the case page cannot disagree.
	 *
	 * Days left is counted against the statutory term, because that is the one
	 * a handler is judged on. The planned end and the internal target carry
	 * their own counts in `termsForCase()`.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $caseTypeId The case type UUID.
	 * @param string $statusTypeId The phase the case is in now.
	 * @param DateTimeImmutable|null $now The moment to judge against (default now).
	 *
	 * @return array{progress: int, daysLeft: int, phasesDone: int, phasesTotal: int,
	 *               termConsumed: int, phaseOverdue: bool, plannedOverdue: bool,
	 *               statutoryOverdue: bool}
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
	 */
	public function progressFor(
		string $caseId,
		string $caseTypeId,
		string $statusTypeId = '',
		?DateTimeImmutable $now = null,
	): array {
		$today = ($now ?? new DateTimeImmutable())->setTime(0, 0);
		$terms = $this->termsForCase(caseId: $caseId, now: $today);
		$phases = $this->declarations->phasesOf(caseTypeId: $caseTypeId);

		$statutory = $this->firstOfKind(terms: $terms, kind: TermKind::STATUTORY);
		$consumed = $this->consumedPercent(term: $statutory, today: $today);
		$done = $this->phasesDone(phases: $phases, statusTypeId: $statusTypeId);
		$total = count($phases);

		$byPhases = $consumed;
		if ($total > 0) {
			$byPhases = (int)round((($done / $total) * 100));
		}

		// A case with no statutory term reads zero days left rather than
		// nothing. There is no honest number here, and a null would have to be
		// rendered as something anyway.
		$daysLeft = 0;
		if ($statutory !== null) {
			$daysLeft = (int)($statutory['daysLeft'] ?? 0);
		}

		return [
			'progress' => max(0, min(100, (int)round(((($byPhases + $consumed) / 2))))),
			'daysLeft' => $daysLeft,
			'phasesDone' => $done,
			'phasesTotal' => $total,
			'termConsumed' => $consumed,
			'phaseOverdue' => $this->anyOverdue(terms: $terms, kind: TermKind::PHASE),
			'plannedOverdue' => $this->anyOverdue(terms: $terms, kind: TermKind::PLANNED),
			'statutoryOverdue' => $this->anyOverdue(terms: $terms, kind: TermKind::STATUTORY),
		];
	}//end progressFor()

	/**
	 * Everything the case page needs about the clocks, in one read.
	 *
	 * The case names its own type and its own phase, so a caller holding a case
	 * id does not have to find either first. This is what the Terms panel reads
	 * and what a list column will read, so the two cannot disagree about a
	 * number neither of them stores.
	 *
	 * @param string $caseId The case UUID.
	 * @param DateTimeImmutable|null $now The moment to judge against (default now).
	 *
	 * @return array{case: string, terms: array<int, array<string, mixed>>,
	 *               progress: array<string, mixed>}
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
	 */
	public function overviewFor(string $caseId, ?DateTimeImmutable $now = null): array {
		$context = $this->declarations->caseContext(caseId: $caseId);

		return [
			'case' => $caseId,
			'terms' => $this->termsForCase(caseId: $caseId, now: $now),
			'progress' => $this->progressFor(
				caseId: $caseId,
				caseTypeId: $context['caseType'],
				statusTypeId: $context['status'],
				now: $now
			),
		];
	}//end overviewFor()

	/**
	 * The clocks a citizen-facing surface may render (REQ-TERM-063).
	 *
	 * @param string $caseId The case UUID.
	 * @param DateTimeImmutable|null $now The moment to judge against.
	 *
	 * @return array<int, array<string, mixed>> The statutory term, and nothing else.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function citizenTermsFor(string $caseId, ?DateTimeImmutable $now = null): array {
		return TermKind::citizenVisible($this->termsForCase(caseId: $caseId, now: $now));
	}//end citizenTermsFor()

	/**
	 * How much of a term has been consumed, as a percentage.
	 *
	 * @param array<string, mixed>|null $term One shaped term from `termsForCase()`.
	 * @param DateTimeImmutable $today The day to judge against.
	 *
	 * @return int 0 to 100, and 0 when there is no term to read.
	 */
	private function consumedPercent(?array $term, DateTimeImmutable $today): int {
		if ($term === null) {
			return 0;
		}

		$start = $this->dayOf(value: (string)($term['startDate'] ?? ''));
		$end = $this->dayOf(value: (string)($term['endDate'] ?? ''));
		if ($start === null || $end === null) {
			return 0;
		}

		$span = (int)$start->diff($end)->days;
		if ($span <= 0) {
			return 100;
		}

		$gone = (int)$start->diff($today)->days;
		if ($today < $start) {
			return 0;
		}

		return max(0, min(100, (int)round(((($gone / $span)) * 100))));
	}//end consumedPercent()

	/**
	 * How many declared phases the case has left behind.
	 *
	 * @param array<int, array<string, mixed>> $phases The phases, in order.
	 * @param string $statusTypeId The phase the case is in now.
	 *
	 * @return int The count of phases before this one.
	 */
	private function phasesDone(array $phases, string $statusTypeId): int {
		if ($statusTypeId === '') {
			return 0;
		}

		foreach ($phases as $index => $phase) {
			if ((string)($phase['id'] ?? '') === $statusTypeId) {
				return $index;
			}
		}//end foreach

		return 0;
	}//end phasesDone()

	/**
	 * The first term of a kind, or null.
	 *
	 * @param array<int, array<string, mixed>> $terms The shaped terms.
	 * @param string $kind The kind to look for.
	 *
	 * @return array<string, mixed>|null The term.
	 */
	private function firstOfKind(array $terms, string $kind): ?array {
		foreach ($terms as $term) {
			if (($term['kind'] ?? '') === $kind) {
				return $term;
			}
		}//end foreach

		return null;
	}//end firstOfKind()

	/**
	 * Whether any running clock of a kind is past its end.
	 *
	 * @param array<int, array<string, mixed>> $terms The shaped terms.
	 * @param string $kind The kind to look at.
	 *
	 * @return bool True when at least one is overdue.
	 */
	private function anyOverdue(array $terms, string $kind): bool {
		foreach ($terms as $term) {
			if (($term['kind'] ?? '') === $kind && ($term['overdue'] ?? false) === true) {
				return true;
			}
		}//end foreach

		return false;
	}//end anyOverdue()

	/**
	 * Days between today and an end date, negative once it has passed.
	 *
	 * @param string $end The end date, `Y-m-d`.
	 * @param DateTimeImmutable $today The day to count from.
	 *
	 * @return int The count, 0 when there is no readable end.
	 */
	private function daysLeft(string $end, DateTimeImmutable $today): int {
		$endDay = $this->dayOf(value: $end);
		if ($endDay === null) {
			return 0;
		}

		$days = (int)$today->diff($endDay)->days;
		if ($endDay < $today) {
			return (-1 * $days);
		}

		return $days;
	}//end daysLeft()

	/**
	 * Whether a clock is still running.
	 *
	 * A completed or withdrawn term is not overdue, however far past its end
	 * the day is: it was answered.
	 *
	 * @param array<string, mixed> $row The instance row.
	 *
	 * @return bool True when the clock is still ticking.
	 */
	private function isRunning(array $row): bool {
		return in_array((string)($row['status'] ?? 'lopend'), ['lopend', 'verlengd', 'paused', 'exceeded'], true);
	}//end isRunning()

	/**
	 * A date string as a day, or null when it does not read as one.
	 *
	 * @param string $value The raw value.
	 *
	 * @return DateTimeImmutable|null The day at midnight.
	 */
	private function dayOf(string $value): ?DateTimeImmutable {
		if ($value === '') {
			return null;
		}

		// `date_create_immutable()` answers FALSE where the constructor throws,
		// so a stored value nobody can parse is a value this reads as absent
		// rather than an exception this swallows. The distinction matters: a
		// catch that returns null cannot tell a malformed date from a store
		// that fell over, and this one only ever sees the first.
		$parsed = date_create_immutable($value);
		if ($parsed === false) {
			$this->logger->warning(
				'Dossiq terms: a term instance carries a date nothing can read, so it is treated as unset',
				['value' => $value]
			);

			return null;
		}

		return $parsed->setTime(0, 0);
	}//end dayOf()
}//end class
