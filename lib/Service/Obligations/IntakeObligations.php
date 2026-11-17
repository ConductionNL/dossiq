<?php

/**
 * Obligations a form asked for when it opened the case.
 *
 * A form can ask the case to wait on somebody outside the organisation, such
 * as a second person who signs the request. The submit creates the case at
 * once and writes what it waits on in `intakeObligations` (decision 271).
 * This places an obligation for each entry, settles it when the holder acts
 * from the portal, and applies the kind's rule when the holder refuses or the
 * due date passes. Nothing here names a procedure: the kind, its title, what
 * it blocks and its rule are the case type's `obligationKinds`.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Obligations
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
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Obligations;

use Psr\Log\LoggerInterface;

/**
 * Places, settles and lapses the obligations a case was opened with.
 *
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md
 */
class IntakeObligations {

	/**
	 * The case field the submit writes.
	 *
	 * @var string
	 */
	public const FIELD = IntakeObligationCase::FIELD;

	/**
	 * The prefix of the `source` an intake obligation carries.
	 *
	 * @var string
	 */
	public const SOURCE_PREFIX = 'intake:';

	public const STATE_AWAITING = IntakeObligationCase::STATE_AWAITING;

	public const STATE_SIGNED = 'signed';

	public const STATE_REFUSED = 'refused';

	public const STATE_EXPIRED = 'expired';

	public const ANSWER_SIGN = 'sign';

	public const ANSWER_REFUSE = 'refuse';

	/**
	 * How many open obligations one lapse sweep reads.
	 *
	 * @var int
	 */
	private const SWEEP_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ObligationService     $obligations Places and settles the obligation rows.
	 * @param IntakeObligationKinds $kinds       The obligation kinds the case type declares.
	 * @param IntakeObligationCase  $cases       Reads the entries, writes their state, knows today.
	 * @param LoggerInterface       $logger      Records a kind the case type does not declare.
	 */
	public function __construct(
		private readonly ObligationService $obligations,
		private readonly IntakeObligationKinds $kinds,
		private readonly IntakeObligationCase $cases,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The `source` of the obligation placed for one kind on one case.
	 *
	 * @param string $caseId The case.
	 * @param string $kind   The kind.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-case-opened-with-intake-obligations-waits-on-them
	 */
	public static function sourceFor(string $caseId, string $kind): string {
		return self::SOURCE_PREFIX . $caseId . ':' . $kind;
	}//end sourceFor()

	/**
	 * Place an obligation for every awaiting entry on a newly received case.
	 *
	 * Runs once per entry: an entry whose obligation already exists is skipped.
	 *
	 * @param array<string, mixed> $case The case as it was stored.
	 *
	 * @return int How many obligations were placed.
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-case-opened-with-intake-obligations-waits-on-them
	 */
	public function placeFor(array $case): int {
		$caseId = $this->cases->idOf(row: $case);
		if ($caseId === '' || $this->cases->entries(case: $case) === []) {
			return 0;
		}

		return (int)$this->cases->asSystem(operation: fn (): int => $this->placeAs(caseId: $caseId, case: $case), fallback: 0);
	}//end placeFor()

	/**
	 * Record the holder's answer from the portal.
	 *
	 * Order, fail-closed: a bad answer (400), no such case or entry (404),
	 * the applicant answering for themselves (403), anybody but the subject
	 * the entry is bound to, or an entry bound to none (403), an entry no longer
	 * awaiting (409), an obligation that cannot be found or written (503).
	 *
	 * @param string               $caseId The case.
	 * @param string               $kind   The obligation kind.
	 * @param string               $answer `sign` or `refuse`.
	 * @param array<string, mixed> $holder `subject`: the holder's pseudonymous portal subject;
	 *                                     `trust`: the level of assurance the portal stated.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal
	 */
	public function settle(string $caseId, string $kind, string $answer, array $holder): array {
		$holder = ['subject' => trim((string)($holder['subject'] ?? '')), 'trust' => (string)($holder['trust'] ?? '')];
		if (in_array($answer, [self::ANSWER_SIGN, self::ANSWER_REFUSE], true) === false || $holder['subject'] === '') {
			return ['status' => 400, 'body' => ['error' => 'invalid']];
		}

		return $this->cases->asSystem(
			operation: fn (): array => $this->settleAs(caseId: $caseId, kind: $kind, answer: $answer, holder: $holder),
			fallback: ['status' => 503, 'body' => ['error' => 'try_again_later']]
		);
	}//end settle()

	/**
	 * Mark every intake obligation whose due date has passed as expired, and
	 * apply its kind's rule.
	 *
	 * @return int How many lapsed.
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-refused-or-lapsed-intake-obligation-follows-its-kinds-rule
	 */
	public function lapse(): int {
		$today  = $this->cases->today();
		$lapsed = 0;
		foreach ($this->obligations->openRows(limit: self::SWEEP_LIMIT) as $row) {
			if ($this->isOverdueIntakeRow(row: $row, today: $today) === true && $this->lapseOne(row: $row) === true) {
				$lapsed++;
			}
		}

		return $lapsed;
	}//end lapse()

	/**
	 * Whether an open obligation row was placed at intake and its due date has passed.
	 *
	 * @param array<string, mixed> $row   The obligation row.
	 * @param string               $today Today, as Y-m-d.
	 *
	 * @return bool
	 */
	private function isOverdueIntakeRow(array $row, string $today): bool {
		if (str_starts_with((string)($row['source'] ?? ''), self::SOURCE_PREFIX) === false) {
			return false;
		}

		$dueAt = substr((string)($row['dueAt'] ?? ''), 0, 10);
		return $dueAt !== '' && $dueAt < $today;
	}//end isOverdueIntakeRow()

	/**
	 * Lapse one overdue obligation whose entry is still awaiting: apply its kind's rule and mark it expired.
	 *
	 * When the rule's withdrawal fails the entry is not marked expired, so the
	 * case does not read as lapsed while its obligation still blocks it.
	 *
	 * @param array<string, mixed> $row The obligation row.
	 *
	 * @return bool True when the entry was marked expired.
	 */
	private function lapseOne(array $row): bool {
		$case = $this->cases->read(caseId: (string)($row['case'] ?? ''));
		$kind = (string)($row['kind'] ?? '');
		if ($case === null || $this->cases->stateOf(case: $case, kind: $kind) !== self::STATE_AWAITING) {
			return false;
		}

		// A withdrawal that could not be written leaves the entry awaiting, so the next sweep tries again.
		if ($this->applyUnmetRule(case: $case, kind: $kind, obligationId: $this->cases->idOf(row: $row), reason: 'The due date passed.') === false) {
			return false;
		}

		return $this->cases->writeState(case: $case, kind: $kind, state: self::STATE_EXPIRED);
	}//end lapseOne()

	/**
	 * The placing itself, see placeFor().
	 *
	 * @param string               $caseId The case.
	 * @param array<string, mixed> $case   The case as it was stored.
	 *
	 * @return int How many obligations were placed.
	 */
	private function placeAs(string $caseId, array $case): int {
		$kinds  = $this->kinds->of(case: $case);
		$placed = 0;
		foreach ($this->cases->entries(case: $case) as $entry) {
			$kind = trim((string)($entry['kind'] ?? ''));
			if ($kind === '' || (string)($entry['state'] ?? self::STATE_AWAITING) !== self::STATE_AWAITING) {
				continue;
			}

			if ($this->placeOne(caseId: $caseId, kind: $kind, entry: $entry, declared: ($kinds[$kind] ?? [])) === true) {
				$placed++;
			}
		}

		return $placed;
	}//end placeAs()

	/**
	 * Place the obligation for one entry, unless it is already there.
	 *
	 * @param string               $caseId   The case.
	 * @param string               $kind     The kind.
	 * @param array<string, mixed> $entry    The entry.
	 * @param array<string, mixed> $declared The case type's declaration of the kind, or [].
	 *
	 * @return bool True when an obligation was placed.
	 */
	private function placeOne(string $caseId, string $kind, array $entry, array $declared): bool {
		$source = self::sourceFor(caseId: $caseId, kind: $kind);
		if ($this->obligations->idForSource(source: $source) !== '') {
			return false;
		}

		if ($declared === []) {
			// Still placed, blocking the closing statuses: a case that should
			// wait must not run on because its type forgot a line.
			$this->logger->warning('Dossiq intake obligations: the case type does not declare this kind', ['case' => $caseId, 'kind' => $kind]);
		}

		$title = trim((string)($entry['title'] ?? ''));
		if ($title === '') {
			$title = (string)($declared['title'] ?? $kind);
		}

		return $this->obligations->place(
			obligation: [
				'case' => $caseId,
				'kind' => $kind,
				'title' => $title,
				'holderName' => (string)($entry['holderName'] ?? ''),
				'dueAt' => (string)($entry['dueAt'] ?? ''),
				'blocks' => ($declared['blocks'] ?? [ObligationDeclaration::BLOCKS_CLOSING]),
				'source' => $source,
			]
		) !== '';
	}//end placeOne()

	/**
	 * The settlement itself, see settle().
	 *
	 * @param string                                 $caseId The case.
	 * @param string                                 $kind   The obligation kind.
	 * @param string                                 $answer `sign` or `refuse`.
	 * @param array{subject: string, trust: string} $holder Who answers, and their level of assurance.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private function settleAs(string $caseId, string $kind, string $answer, array $holder): array {
		$case    = $this->cases->read(caseId: $caseId);
		$refusal = $this->refusalFor(case: $case, kind: $kind, subjectRef: $holder['subject']);
		if ($refusal !== null || $case === null) {
			return ($refusal ?? ['status' => 404, 'body' => ['error' => 'not_found']]);
		}

		$obligationId = $this->obligations->idForSource(source: self::sourceFor(caseId: $caseId, kind: $kind));
		if ($obligationId === '') {
			return ['status' => 503, 'body' => ['error' => 'try_again_later']];
		}

		if ($answer === self::ANSWER_REFUSE) {
			$state = self::STATE_REFUSED;
			$done  = $this->applyUnmetRule(case: $case, kind: $kind, obligationId: $obligationId, reason: 'Refused from the portal.');
		} else {
			$state = self::STATE_SIGNED;
			$done  = $this->obligations->meet(
				obligationId: $obligationId,
				note: 'Signed from the portal.',
				fields: ['settledBySubject' => $holder['subject'], 'settledTrust' => $holder['trust']]
			);
		}

		if ($done === false || $this->cases->writeState(case: $case, kind: $kind, state: $state) === false) {
			return ['status' => 503, 'body' => ['error' => 'try_again_later']];
		}

		return ['status' => 200, 'body' => ['case' => $caseId, 'kind' => $kind, 'state' => $state]];
	}//end settleAs()

	/**
	 * Why this holder may not answer this entry, or null when they may.
	 *
	 * @param array<string, mixed>|null $case       The case, or null when it could not be read.
	 * @param string                    $kind       The kind.
	 * @param string                    $subjectRef The holder's portal subject.
	 *
	 * @return array{status: int, body: array<string, mixed>}|null
	 */
	private function refusalFor(?array $case, string $kind, string $subjectRef): ?array {
		$state = null;
		if ($case !== null) {
			$state = $this->cases->stateOf(case: $case, kind: $kind);
		}

		if ($case === null || $state === null) {
			return ['status' => 404, 'body' => ['error' => 'not_found']];
		}

		$refusal = $this->holderRefusal(case: $case, kind: $kind, subjectRef: trim($subjectRef));
		if ($refusal === null && $state !== self::STATE_AWAITING) {
			$refusal = ['status' => 409, 'body' => ['error' => 'not_awaiting', 'state' => $state]];
		}

		return $refusal;
	}//end refusalFor()

	/**
	 * Why this subject is not the one to answer this entry, or null when they are.
	 *
	 * @param array<string, mixed> $case       The case.
	 * @param string               $kind       The kind.
	 * @param string               $subjectRef The holder's portal subject, trimmed.
	 *
	 * @return array{status: int, body: array<string, mixed>}|null
	 */
	private function holderRefusal(array $case, string $kind, string $subjectRef): ?array {
		if (trim((string)($case['portalSubject'] ?? '')) === $subjectRef) {
			return ['status' => 403, 'body' => ['error' => 'same_subject']];
		}

		// Fail closed: an entry bound to no subject, or to another one, is not this person's to answer.
		$holder = $this->cases->holderSubjectOf(case: $case, kind: $kind);
		if ($holder === '' || hash_equals($holder, $subjectRef) === false) {
			return ['status' => 403, 'body' => ['error' => 'not_holder']];
		}

		return null;
	}//end holderRefusal()

	/**
	 * Apply the kind's `whenUnmet`: withdraw releases the case, stay keeps it blocked.
	 *
	 * @param array<string, mixed> $case         The case.
	 * @param string               $kind         The kind.
	 * @param string               $obligationId The obligation.
	 * @param string               $reason       Why it was not met.
	 *
	 * @return bool False when a withdrawal could not be written.
	 */
	private function applyUnmetRule(array $case, string $kind, string $obligationId, string $reason): bool {
		$rule = (string)($this->kinds->of(case: $case)[$kind]['whenUnmet'] ?? ObligationDeclaration::WHEN_UNMET_STAY);
		if ($rule !== ObligationDeclaration::WHEN_UNMET_WITHDRAW) {
			return true;
		}

		return $this->obligations->withdraw(obligationId: $obligationId, reason: $reason);
	}//end applyUnmetRule()
}//end class
