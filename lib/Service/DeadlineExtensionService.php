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
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use ReflectionClass;
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
	 * Extend the statutory term of a case by a number of days.
	 *
	 * For a law that names its extension in days rather than as an end date
	 * (Woo art. 4.4 lid 2: at most two weeks). The term is the one that
	 * decides the case's deadline ({@see CaseDeadlineMirror::decidingInstance()});
	 * the requested end is its current end plus the days, and from there it is
	 * the same extension as {@see requestExtension()}: rolled, checked against
	 * the definition's ceiling, stored, and followed by the case's deadline.
	 *
	 * @param string $caseId    The case uuid.
	 * @param string $rationale Why (the `verleng` event's rationale).
	 * @param int    $days      How many days to add.
	 *
	 * @return array{previous: string, instance: array<string, mixed>} The end before, and the extended term.
	 *
	 * @throws RefusedException When the case has no running statutory term, or it has had
	 *         every extension it allows (409), or a declared period refuses it (422).
	 *
	 * @spec openspec/changes/one-term-engine/specs/woo-case-type/spec.md#requirement-woo-deadline-tracking-and-extension
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `CaseDeadlineMirror::decidingInstance()` and
	 * `endOf()` are pure functions over an array: the one rule for which statutory term
	 * decides a case, kept in one place.
	 */
	public function extendStatutoryTermOfCase(string $caseId, string $rationale, int $days): array {
		$term = CaseDeadlineMirror::decidingInstance(instances: $this->termService->instancesForCase(caseId: $caseId));
		if ($term === null || (string)($term['status'] ?? '') === 'completed') {
			throw new RefusedException(
				rule: 'no-running-statutory-term',
				sentence: 'This case has no running term to extend.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		$previous = CaseDeadlineMirror::endOf(instance: $term);
		$requested = $this->dates->formatCalendarDate(
			$this->dates->parse($previous, 'endDateCurrent')->modify('+' . max(0, $days) . ' days')
		);

		try {
			$extended = $this->requestExtension(
				termInstanceId: (string)($term['id'] ?? ''),
				rationale: $rationale,
				newEndDate: $requested,
			);
		} catch (RefusedException $e) {
			throw $e;
		} catch (RuntimeException $e) {
			// With a positive number of days the end always moves forward, so
			// what is left is the ceiling: a 409 the reader can act on, not a
			// 500. The engine's own message is for the log.
			throw new RefusedException(
				rule: 'extension-ceiling-reached',
				sentence: 'This term has had every extension it allows.',
				status: RefusedException::STATUS_REFUSED,
				previous: $e,
			);
		}

		return ['previous' => $previous, 'instance' => $extended];
	}//end extendStatutoryTermOfCase()

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
	 * @spec openspec/changes/one-term-engine/specs/termijn-pause-extension/spec.md
	 */
	private function applyExtension(
		string $termInstanceId,
		string $rationale,
		string $newEndDate,
		string $documentLink,
		string $mode,
	): array {
		$this->assertExtensionInput(rationale: $rationale, newEndDate: $newEndDate);

		// ROLLED BEFORE IT IS CHECKED OR STORED (REQ-OTE-03). An end date a
		// caller sends is a term end like any other, so the Algemene
		// termijnenwet decides the day it lands on; the ceiling and the days
		// impact are then measured to the day the term actually ends. The date
		// as asked is kept beside it, so a reader can see the roll happened.
		$requestedEndDate = $newEndDate;
		$newEndDate = $this->rolledEndDate(endDate: $newEndDate);

		$instance = $this->termService->getTermijnInstance($termInstanceId);
		if ($instance === null) {
			throw new RuntimeException('TermijnInstance not found: ' . $termInstanceId);
		}

		$this->assertExtensionPermitted(instance: $instance, newEndDate: $newEndDate, mode: $mode);

		$current = (string)($instance['endDateCurrent'] ?? '');
		$consumed = (int)($instance['countExtensions'] ?? 0);
		$daysImpact = $this->calculateDaysImpact(current: $current, newEndDate: $newEndDate);

		// The ceiling is measured to the day ASKED FOR. The Algemene termijnenwet
		// moves an end off a weekend or holiday by law; a fourteen day extension
		// that the roll carries to the Monday is still the fourteen days the
		// law allows, not sixteen the case type refuses.
		$this->assertWithinDeclaredPeriod(
			instance: $instance,
			days: $this->calculateDaysImpact(current: $current, newEndDate: $requestedEndDate),
			mode: $mode
		);

		$updated = $this->termService->updateTermijnInstance(
			$termInstanceId,
			[
				'endDateCurrent' => $newEndDate,
				'endDateBeforeRoll' => $requestedEndDate,
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
	 * The requested end date, rolled off a day the Awt does not let a term end on.
	 *
	 * Without a timer service there is no calendar to ask, and the date stays
	 * as requested, which is what this service did before.
	 *
	 * @param string $endDate The requested end date (YYYY-MM-DD).
	 *
	 * @return string The end date the term actually gets (YYYY-MM-DD).
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-pause-extension/spec.md#requirement-an-extensions-end-date-is-rolled-and-reaches-the-case-req-ote-03
	 */
	private function rolledEndDate(string $endDate): string {
		if ($this->timerService === null) {
			return $endDate;
		}

		$date = $this->dates->parse($endDate, 'newEinddatum');

		return $this->dates->formatCalendarDate($this->timerService->rollTermEndFor(date: $date));
	}//end rolledEndDate()

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
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the deadline does not move forward or the ceiling is exhausted.
	 */
	private function assertExtensionPermitted(array $instance, string $newEndDate, string $mode): void {
		$current = (string)($instance['endDateCurrent'] ?? '');
		if ($current !== '' && $newEndDate <= $current) {
			throw new RuntimeException('newEinddatum must be later than current einddatumActueel');
		}

		$consumed = (int)($instance['countExtensions'] ?? 0);
		$maxExt = $this->resolveMaxExtensions(instance: $instance);
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
	 * Resolve the maximum number of extensions allowed for this instance.
	 *
	 * Looks up the TermijnDefinitie via the instance reference and reads
	 * aantalVerlengingen; falls back to 1 when missing (AWB default).
	 *
	 * @param array<string, mixed> $instance Instance row.
	 *
	 * @return int
	 */
	private function resolveMaxExtensions(array $instance): int {
		// Prefer to look up the definition by the linked id.
		$defId = (string)($instance['deadlineDefinition'] ?? '');
		if ($defId === '') {
			return 1;
		}

		// Walk the TermijnService cache by zaaktype if available. As a
		// safe fallback, return the default 1 — a real lookup would
		// call SettingsService->getObjectService()->find($defId) here,
		// but TermijnService already caches lookups by zaaktype which
		// is the data we actually need.
		$svcDef = null;
		try {
			$reflection = new ReflectionClass($this->termService);
			if ($reflection->hasProperty('definitieCache') === true) {
				$prop = $reflection->getProperty('definitieCache');
				$cache = $prop->getValue($this->termService);
				if (is_array($cache) === true) {
					foreach ($cache as $row) {
						if (is_array($row) === true && (string)($row['id'] ?? '') === $defId) {
							$svcDef = $row;
							break;
						}
					}
				}
			}
		} catch (\Throwable $e) {
			$svcDef = null;
		}

		if (is_array($svcDef) === true) {
			return (int)($svcDef['countExtensions'] ?? 1);
		}

		return 1;
	}//end resolveMaxExtensions()
}//end class
