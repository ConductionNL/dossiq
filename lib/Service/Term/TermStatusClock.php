<?php

/**
 * The clock runs while the case is ours to move, and stops while it is not.
 *
 * A case sitting with an external adviser burned the same clock as one on a
 * handler's desk, because nothing declared which statuses a term runs in.
 * `deadlineDefinition.runsInStatuses` now does, and this class keeps the
 * engine timer matching that declaration.
 *
 * 🔴 DECLARING THE STATUSES IS NOT RUNNING A CLOCK. dossiq declares; the
 * engine counts. Entering a status outside the set suspends the engine timer
 * and leaving it resumes, through the same suspend and resume that opschorting
 * already maps onto, so there is no second mechanism keeping a second set of
 * dates.
 *
 * 🔴 IT RESUMES ONLY WHAT IT SUSPENDED. An opschorting under Awb 4:5 is a
 * different suspension for a different reason, and a case leaving an adviser's
 * status must not restart a clock a hersteltermijn stopped. That is what
 * `clockStoppedByStatus` on the instance is for, and why the flag is written
 * here and read nowhere else.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Term
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
 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Term;

use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Suspends and resumes a term's engine timer from the case's status.
 *
 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md#requirement-a-term-declares-the-statuses-its-clock-runs-in-with-thresholds-as-days-or-shares-req-tcf-03
 */
class TermStatusClock {
	/**
	 * Nothing was needed.
	 */
	public const UNCHANGED = 'unchanged';

	/**
	 * The clock was stopped, because the case left the running statuses.
	 */
	public const SUSPENDED = 'suspended';

	/**
	 * The clock was started again, because the case came back.
	 */
	public const RESUMED = 'resumed';

	/**
	 * The reason written on both halves, so a reader of the engine's own log
	 * can tell this suspension from an opschorting.
	 */
	public const REASON = 'Status buiten de lopende statussen van de termijn';

	/**
	 * Term statuses whose clock can be stopped at all.
	 */
	private const RUNNING_STATUSES = ['lopend', 'verlengd'];

	/**
	 * Constructor.
	 *
	 * @param TermijnService      $terms  The instance and its definition.
	 * @param TermijnTimerService $timers The engine's suspend and resume.
	 * @param LoggerInterface     $logger Logger.
	 */
	public function __construct(
		private readonly TermijnService $terms,
		private readonly TermijnTimerService $timers,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Make the timer match the status the case is now in.
	 *
	 * @param string $caseId   The case.
	 * @param string $statusId The status it is now in.
	 * @param string $caseType The case's type, which is what carries the term.
	 *
	 * @return string One of UNCHANGED, SUSPENDED or RESUMED.
	 *
	 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md#requirement-a-term-declares-the-statuses-its-clock-runs-in-with-thresholds-as-days-or-shares-req-tcf-03
	 */
	public function reconcile(string $caseId, string $statusId, string $caseType): string {
		$instance = $this->terms->getTermijnInstanceForZaak(caseId: $caseId);
		if ($instance === null) {
			return self::UNCHANGED;
		}

		$running = $this->runningStatuses(caseType: $caseType);
		if ($running === []) {
			// Nothing declared, so the clock runs everywhere. That is how
			// every term behaved before this was declarable, and a term that
			// declares nothing must keep behaving that way.
			return self::UNCHANGED;
		}

		$inside = in_array($statusId, $running, true);
		$stopped = (($instance['clockStoppedByStatus'] ?? false) === true);

		if ($inside === false && $stopped === false) {
			return $this->stop(instance: $instance);
		}

		if ($inside === true && $stopped === true) {
			return $this->start(instance: $instance);
		}

		return self::UNCHANGED;
	}//end reconcile()

	/**
	 * Stop the clock, and write down that this is why.
	 *
	 * @param array<string, mixed> $instance The term instance.
	 *
	 * @return string What happened.
	 */
	private function stop(array $instance): string {
		if (in_array((string)($instance['status'] ?? ''), self::RUNNING_STATUSES, true) === false) {
			// Already paused for another reason, or already finished. Stopping
			// it again would bank a second suspension for one absence.
			return self::UNCHANGED;
		}

		try {
			$this->timers->suspendBeslistermijn(instance: $instance, reason: self::REASON, until: null);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: a term clock could not be stopped: ' . $e->getMessage());

			return self::UNCHANGED;
		}

		$this->terms->updateTermijnInstance(
			termInstanceId: (string)($instance['id'] ?? ''),
			patch: ['clockStoppedByStatus' => true]
		);

		return self::SUSPENDED;
	}//end stop()

	/**
	 * Start the clock again, and clear the marker.
	 *
	 * @param array<string, mixed> $instance The term instance.
	 *
	 * @return string What happened.
	 */
	private function start(array $instance): string {
		try {
			$this->timers->resumeBeslistermijn(instance: $instance, reason: self::REASON);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: a term clock could not be started again: ' . $e->getMessage());

			return self::UNCHANGED;
		}

		$this->terms->updateTermijnInstance(
			termInstanceId: (string)($instance['id'] ?? ''),
			patch: ['clockStoppedByStatus' => false]
		);

		return self::RESUMED;
	}//end start()

	/**
	 * The statuses this case type's term declares it runs in.
	 *
	 * The case type is asked for rather than read off the instance: an
	 * instance names its definition by id and this service has no lookup by
	 * id, and inventing one to avoid a parameter the caller already holds
	 * would be a second way to reach the same row.
	 *
	 * @param string $caseType The zaaktype slug.
	 *
	 * @return array<int, string> The statuses, empty when the term declares none.
	 */
	private function runningStatuses(string $caseType): array {
		$caseType = trim($caseType);
		if ($caseType === '') {
			return [];
		}

		$definition = $this->terms->getTermijnDefinitie(caseType: $caseType);
		$declared = ($definition['runsInStatuses'] ?? []);
		if (is_array($declared) === false) {
			return [];
		}

		$statuses = [];
		foreach ($declared as $status) {
			$status = trim((string)$status);
			if ($status !== '') {
				$statuses[] = $status;
			}
		}

		return $statuses;
	}//end runningStatuses()
}//end class
