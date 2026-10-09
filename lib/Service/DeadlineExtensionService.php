<?php

/**
 * Dossiq DeadlineExtensionService.
 *
 * AWB 4:14 verlenging on a TermijnInstance. Validates that the
 * verlenging-count is below the TermijnDefinitie's aantalVerlengingen
 * ceiling, that motivering is non-empty, and that newEinddatum is in
 * the future relative to einddatumActueel. A supervisor-approval
 * override path bypasses the ceiling for exceptional cases.
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Exception\RefusedException;
use RuntimeException;

/**
 * AWB 4:14 verlenging engine on a TermijnInstance.
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
 */
class DeadlineExtensionService {
	/**
	 * Extension mode: the ordinary AWB 4:14 lid 1 verlenging, bound by the
	 * TermijnDefinitie ceiling.
	 *
	 * @var string
	 */
	public const MODE_STANDARD = 'standard';

	/**
	 * Extension mode: the AWB 4:14 lid 3 supervisor-approved verlenging,
	 * which bypasses the TermijnDefinitie ceiling.
	 *
	 * @var string
	 */
	public const MODE_SUPERVISOR = 'supervisor';

	/**
	 * Constructor.
	 *
	 * @param TermijnService $termService TermijnService.
	 * @param CaseDateNormaliser $dates The one date write path.
	 * @param TermijnTimerService|null $timerService Engine timer mapping (optional while the engine rolls out).
	 * @param TermDeclarationReader|null $declarations What the case type declares about
	 *        extending, so `extensionPeriod` is read by the service that moves the
	 *        deadline rather than only by the ZGW mapping. Optional so an existing
	 *        caller that builds this service by hand keeps working; when it is
	 *        absent the declared ceiling is simply not enforced, which is the
	 *        behaviour this change replaces rather than a new silence.
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly CaseDateNormaliser $dates,
		private readonly ?TermijnTimerService $timerService = null,
		private readonly ?TermDeclarationReader $declarations = null,
	) {
	}//end __construct()

	/**
	 * Request an ordinary AWB 4:14 lid 1 verlenging on a TermijnInstance.
	 *
	 * Bound by the TermijnDefinitie's aantalVerlengingen ceiling.
	 *
	 * @param string $termInstanceId Instance id.
	 * @param string $rationale Non-empty reason.
	 * @param string $newEndDate New deadline (YYYY-MM-DD; must be > einddatumActueel).
	 * @param string $documentLink Optional document link (verlengingsbrief).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException With validation failures (cited AWB rule).
	 * @throws RefusedException When the move is longer than the case type declares.
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
	 */
	public function requestExtension(
		string $termInstanceId,
		string $rationale,
		string $newEndDate,
		string $documentLink = '',
	): array {
		return $this->applyExtension(
			termInstanceId: $termInstanceId,
			rationale: $rationale,
			newEndDate: $newEndDate,
			documentLink: $documentLink,
			mode: self::MODE_STANDARD
		);
	}//end requestExtension()

	/**
	 * Request a supervisor-approved AWB 4:14 lid 3 verlenging.
	 *
	 * Bypasses the TermijnDefinitie's aantalVerlengingen ceiling and is
	 * recorded with the supervisor grondslag and actor.
	 *
	 * @param string $termInstanceId Instance id.
	 * @param string $rationale Non-empty reason.
	 * @param string $newEndDate New deadline (YYYY-MM-DD; must be > einddatumActueel).
	 * @param string $documentLink Optional document link (verlengingsbrief).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException With validation failures (cited AWB rule).
	 * @throws RefusedException When the move is longer than the case type declares.
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
	 */
	public function requestSupervisorExtension(
		string $termInstanceId,
		string $rationale,
		string $newEndDate,
		string $documentLink = '',
	): array {
		return $this->applyExtension(
			termInstanceId: $termInstanceId,
			rationale: $rationale,
			newEndDate: $newEndDate,
			documentLink: $documentLink,
			mode: self::MODE_SUPERVISOR
		);
	}//end requestSupervisorExtension()

	/**
	 * Shared verlenging implementation for both extension modes.
	 *
	 * @param string $termInstanceId Instance id.
	 * @param string $rationale Non-empty reason.
	 * @param string $newEndDate New deadline (YYYY-MM-DD; must be > einddatumActueel).
	 * @param string $documentLink Optional document link (verlengingsbrief).
	 * @param string $mode One of self::MODE_STANDARD or self::MODE_SUPERVISOR.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException With validation failures (cited AWB rule).
	 * @throws RefusedException When the move is longer than the case type declares.
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
	 */
	private function applyExtension(
		string $termInstanceId,
		string $rationale,
		string $newEndDate,
		string $documentLink,
		string $mode,
	): array {
		$this->assertExtensionInput(rationale: $rationale, newEndDate: $newEndDate);

		$instance = $this->termService->getTermijnInstance($termInstanceId);
		if ($instance === null) {
			throw new RuntimeException('TermijnInstance not found: ' . $termInstanceId);
		}

		// Every end date accepted through the API obeys the Algemene
		// termijnenwet (REQ-WTR-002): rolled before the ceiling check and
		// before it is stored, with the supplied date kept beside it.
		$definitie = $this->definitionOf(instance: $instance);
		$suppliedEndDate = $newEndDate;
		$newEndDate = $this->rolled(date: $newEndDate, definitie: $definitie);

		$this->assertExtensionPermitted(instance: $instance, newEndDate: $newEndDate, mode: $mode, definitie: $definitie);

		$current = (string)($instance['endDateCurrent'] ?? '');
		$consumed = (int)($instance['countExtensions'] ?? 0);
		$daysImpact = $this->calculateDaysImpact(current: $current, newEndDate: $newEndDate);

		$this->assertWithinDeclaredPeriod(instance: $instance, days: $daysImpact, mode: $mode);

		$updated = $this->termService->updateTermijnInstance(
			$termInstanceId,
			[
				'endDateCurrent' => $newEndDate,
				'endDateBeforeRoll' => $suppliedEndDate,
				'status' => 'verlengd',
				'countExtensions' => ($consumed + 1),
			]
		);

		// Mirror the verdaging to the engine timer: the domain refusal
		// rules above are authoritative, the engine records the same
		// decision on the clock (standard extend vs the separately
		// authorized supervisor override, AWB 4:14).
		$this->timerService?->extendBeslistermijn(
			instance: $instance,
			days: $daysImpact,
			rationale: $rationale,
			supervisor: ($mode === self::MODE_SUPERVISOR)
		);

		$context = $this->resolveExtensionContext(mode: $mode);

		$this->termService->recordEvent(
			termInstanceId: $termInstanceId,
			type: 'verleng',
			basis: $context['basis'],
			rationale: $rationale,
			daysImpact: $daysImpact,
			documentLink: $documentLink,
			actor: $context['actor'],
		);

		return $updated ?? $instance;
	}//end applyExtension()

	/**
	 * Validate the raw verlenging input before any lookup is performed.
	 *
	 * @param string $rationale Non-empty reason.
	 * @param string $newEndDate New deadline (YYYY-MM-DD).
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the motivering is empty or the date is malformed.
	 */
	private function assertExtensionInput(string $rationale, string $newEndDate): void {
		if (trim($rationale) === '') {
			throw new RuntimeException('Motivering is required for AWB 4:14 verlenging');
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $newEndDate) !== 1) {
			throw new RuntimeException('newEinddatum must be in YYYY-MM-DD format');
		}
	}//end assertExtensionInput()

	/**
	 * Validate the verlenging against the instance state and the AWB 4:14 ceiling.
	 *
	 * @param array<string, mixed> $instance Instance row.
	 * @param string $newEndDate New deadline (YYYY-MM-DD).
	 * @param string $mode One of self::MODE_STANDARD or self::MODE_SUPERVISOR.
	 * @param array<string, mixed> $definitie The instance's TermijnDefinitie, or an empty array.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the deadline does not move forward or the ceiling is exhausted.
	 */
	private function assertExtensionPermitted(array $instance, string $newEndDate, string $mode, array $definitie): void {
		$current = (string)($instance['endDateCurrent'] ?? '');
		if ($current !== '' && $newEndDate <= $current) {
			throw new RuntimeException('newEinddatum must be later than current einddatumActueel');
		}

		$consumed = (int)($instance['countExtensions'] ?? 0);
		$maxExt = $this->resolveMaxExtensions(definitie: $definitie);
		if ($mode !== self::MODE_SUPERVISOR && $consumed >= $maxExt) {
			throw new RuntimeException('AWB 4:14 lid 3: maximum aantal verlengingen al verbruikt (' . $maxExt . ')');
		}
	}//end assertExtensionPermitted()

	/**
	 * Refuse a verlenging longer than the case type declares (REQ-TERM-066).
	 *
	 * `caseType.extensionPeriod` existed and nothing read it but the ZGW
	 * mapping, so any case could be extended by any amount and the Awb does not
	 * allow that. The refusal names the rule and the declared period, per
	 * ADR-050, because a handler told only "no" retries with the same number.
	 *
	 * The supervisor path (Awb 4:14 lid 3) is separately authorised and passes,
	 * exactly as it passes the count ceiling: that is what the override is for.
	 *
	 * @param array<string, mixed> $instance The instance being extended.
	 * @param int $days How many days the deadline moves by.
	 * @param string $mode One of self::MODE_STANDARD or self::MODE_SUPERVISOR.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the move is longer than the declared period.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-pause-extension/spec.md
	 */
	private function assertWithinDeclaredPeriod(array $instance, int $days, string $mode): void {
		if ($mode === self::MODE_SUPERVISOR || $this->declarations === null) {
			return;
		}

		$declared = $this->declarations->forCase(caseId: (string)($instance['case'] ?? ''));

		if ($declared['extensionAllowed'] === false) {
			throw new RefusedException(
				rule: 'extension-not-allowed',
				sentence: 'This case type does not allow the term to be extended.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$period = (int)$declared['extensionPeriodDays'];
		if ($period > 0 && $days > $period) {
			throw new RefusedException(
				rule: 'extension-beyond-declared-period',
				sentence: 'This case type allows an extension of at most ' . $period
					. ' days, and you asked for ' . $days . '.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}
	}//end assertWithinDeclaredPeriod()

	/**
	 * Compute the number of days the deadline moves by.
	 *
	 * @param string $current Current einddatumActueel, empty when unset.
	 * @param string $newEndDate New deadline (YYYY-MM-DD).
	 *
	 * @return int Absolute number of days between the current and the new deadline.
	 */
	private function calculateDaysImpact(string $current, string $newEndDate): int {
		$currentDate = $this->dates->now();
		if ($current !== '' && $current !== 'now') {
			$currentDate = $this->dates->parse($current, 'einddatumActueel');
		}

		$newDate = $this->dates->parse($newEndDate, 'newEinddatum');

		return (int)$currentDate->diff($newDate)->days;
	}//end calculateDagenImpact()

	/**
	 * Resolve the grondslag and actor recorded with the verlenging event.
	 *
	 * @param string $mode One of self::MODE_STANDARD or self::MODE_SUPERVISOR.
	 *
	 * @return array{basis: string, actor: string} Event grondslag and actor for the mode.
	 */
	private function resolveExtensionContext(string $mode): array {
		if ($mode === self::MODE_SUPERVISOR) {
			return [
				'basis' => 'AWB 4:14 lid 3 (supervisor)',
				'actor' => 'supervisor',
			];
		}

		return [
			'basis' => 'AWB 4:14 lid 1',
			'actor' => 'system',
		];
	}//end resolveExtensionContext()

	/**
	 * The TermijnDefinitie a term instance names, read by id.
	 *
	 * @param array<string, mixed> $instance Instance row.
	 *
	 * @return array<string, mixed> The definition, or an empty array when it cannot be read.
	 */
	private function definitionOf(array $instance): array {
		$defId = (string)($instance['deadlineDefinition'] ?? '');
		if ($defId === '') {
			return [];
		}

		return ($this->termService->getTermijnDefinitieById($defId) ?? []);
	}//end definitionOf()

	/**
	 * A supplied end date, rolled by the Algemene termijnenwet when the term declares it.
	 *
	 * Without the engine bridge the date is answered as supplied, which is what
	 * a build without a calendar always did.
	 *
	 * @param string               $date      The supplied end date (YYYY-MM-DD).
	 * @param array<string, mixed> $definitie The instance's TermijnDefinitie.
	 *
	 * @return string The end date the term actually ends on (YYYY-MM-DD).
	 */
	private function rolled(string $date, array $definitie): string {
		if ($this->timerService === null) {
			return $date;
		}

		$parsed = $this->dates->parse($date, 'newEinddatum');

		return $this->timerService->rollTermEndFor(date: $parsed, definitie: $definitie)->format('Y-m-d');
	}//end rolled()

	/**
	 * The maximum number of standard extensions the definition allows.
	 *
	 * Read from the definition's `countExtensions` (the schema's "Maximum
	 * Extensions"). A missing definition counts as one, the safe value: Woo
	 * art. 4.4 lid 2 and Awb 4:14 allow one.
	 *
	 * @param array<string, mixed> $definitie The instance's TermijnDefinitie, or an empty array.
	 *
	 * @return int The ceiling.
	 */
	private function resolveMaxExtensions(array $definitie): int {
		if ($definitie === [] || array_key_exists('countExtensions', $definitie) === false) {
			return 1;
		}

		return max(0, (int)$definitie['countExtensions']);
	}//end resolveMaxExtensions()
}//end class
