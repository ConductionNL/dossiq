<?php

/**
 * Dossiq DeadlineEscalationService.
 *
 * Resolves per-threshold escalation recipients and dispatches escalation
 * notifications when a TermijnInstance reaches a configured proximity
 * (14d / 7d / 2d / 0d). Duplicate suppression: each instance tracks
 * `notificatiesVerstuurd` (the thresholds already sent) and the service
 * skips any threshold already in the list.
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
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
 * @spec openspec/specs/case-priority/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Kernel\Contracts\DeadlineEscalationServiceInterface;
use OCA\Dossiq\Service\Termijn\TermHandlerNotice;
use Psr\Log\LoggerInterface;

/**
 * Threshold-aware escalation dispatcher for the daily termijn scan.
 *
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
 */
class DeadlineEscalationService implements DeadlineEscalationServiceInterface {
	/**
	 * Default escalation matrix.
	 *
	 * Maps threshold-in-days → {recipients, notificationUrgency, template}.
	 *
	 * 🔴 `notificationUrgency` WAS CALLED `priority`, AND WAS NOT ONE. These
	 * four words — low, medium, high, critical — say how loudly a notification
	 * should arrive, on a vocabulary that is not the case's. The case's
	 * priority is low / normal / high / urgent and lives on the case, where a
	 * list can sort by it and a person can override it. Two fields called
	 * priority on two vocabularies is how a reader ends up assuming they agree,
	 * so this one is named for what it is and the escalation reads the case's
	 * priority for the other question.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const DEFAULT_MATRIX = [
		14 => ['recipients' => ['handler'],                        'notificationUrgency' => 'low',      'template' => 'termijn-14d'],
		7 => ['recipients' => ['handler', 'teamleader'],          'notificationUrgency' => 'medium',   'template' => 'termijn-7d'],
		2 => ['recipients' => ['handler', 'teamleader', 'manager'], 'notificationUrgency' => 'high',     'template' => 'termijn-2d'],
		0 => ['recipients' => ['handler', 'teamleader', 'manager'], 'notificationUrgency' => 'critical', 'template' => 'termijn-overschreden'],
	];

	/**
	 * Constructor.
	 *
	 * @param TermijnService $termService TermijnService for instance lookup/update.
	 * @param CasePriorityRaiseService $priorityRaiseService Applies the declared
	 *                                                      term rule and reads
	 *                                                      the case's priority.
	 * @param LoggerInterface $logger Logger.
	 * @param TermHandlerNotice|null $handlerNotice Tells the case's handler on
	 *                                              the rungs the term definition
	 *                                              names (`tellHandlerAt`).
	 *
	 * @spec openspec/specs/case-priority/spec.md
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly CasePriorityRaiseService $priorityRaiseService,
		private readonly LoggerInterface $logger,
		private readonly ?TermHandlerNotice $handlerNotice = null,
	) {
	}//end __construct()

	/**
	 * Available threshold buckets, sorted descending so the earliest first.
	 *
	 * @return array<int, int>
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
	 */
	public function thresholds(): array {
		$keys = array_keys(self::DEFAULT_MATRIX);
		rsort($keys);
		return $keys;
	}//end thresholds()

	/**
	 * Compute the threshold bucket for a number of days remaining.
	 *
	 * Returns null when above the highest threshold, 0 when zero/negative.
	 *
	 * @param int $daysToDeadline Days to deadline (negative = overschreden).
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
	 */
	public function bucketFor(int $daysToDeadline): ?int {
		if ($daysToDeadline <= 0) {
			return 0;
		}

		// Walk ascending so the *tightest* matching threshold wins
		// (7 days remaining → bucket 7, not 14).
		$buckets = $this->thresholds();
		sort($buckets);
		foreach ($buckets as $bucket) {
			if ($bucket > 0 && $daysToDeadline <= $bucket) {
				return $bucket;
			}
		}

		return null;
	}//end bucketFor()

	/**
	 * Notify a threshold for a TermijnInstance (idempotent on duplicates).
	 *
	 * @param array<string, mixed> $instance TermijnInstance row.
	 * @param int $threshold Threshold bucket (14/7/2/0).
	 *
	 * @return bool True if a notification was sent (i.e. not a duplicate).
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
	 * @spec openspec/specs/case-priority/spec.md
	 */
	public function notifyThreshold(array $instance, int $threshold): bool {
		if ($this->isDue(instance: $instance, threshold: $threshold) === false) {
			return false;
		}

		$payload = $this->escalationPayload(instance: $instance, threshold: $threshold);
		$this->logger->info('Dossiq termijn escalation dispatched', $payload);

		$this->markSent(instance: $instance, threshold: $threshold);

		// Recorded first, told second: the dedup above is what keeps a re-fired
		// rung from telling the handler twice, and a notice that fails is
		// logged by the notice rather than undoing the record.
		$this->handlerNotice?->tell(instance: $instance, threshold: $threshold);

		return true;
	}//end notifyThreshold()

	/**
	 * Whether this rung is still to be sent: the instance has an id, the rung
	 * was not sent before, and the matrix knows it.
	 *
	 * @param array<string, mixed> $instance  TermijnInstance row.
	 * @param int                  $threshold Threshold bucket (14/7/2/0).
	 *
	 * @return bool
	 */
	private function isDue(array $instance, int $threshold): bool {
		if ((string)($instance['id'] ?? '') === '') {
			return false;
		}

		$alreadySent = (array)($instance['notificatiesVerstuurd'] ?? []);
		if (in_array($threshold, array_map(static fn ($v): int => (int)$v, $alreadySent), true) === true) {
			return false;
		}

		return isset(self::DEFAULT_MATRIX[$threshold]);
	}//end isDue()

	/**
	 * What the escalation reports: the rung, its template, urgency and
	 * recipients, the case priority after the raise, and the instance.
	 *
	 * @param array<string, mixed> $instance  TermijnInstance row.
	 * @param int                  $threshold Threshold bucket the matrix knows.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function escalationPayload(array $instance, int $threshold): array {
		$config = self::DEFAULT_MATRIX[$threshold];
		$caseId = (string)($instance['case'] ?? '');

		return [
			'threshold' => $threshold,
			'template' => $config['template'],
			'notificationUrgency' => $config['notificationUrgency'],
			'casePriority' => $this->raisedPriority(caseId: $caseId, threshold: $threshold),
			'recipients' => $config['recipients'],
			'instanceId' => (string)$instance['id'],
			'caseId' => $caseId,
			'deadline' => (string)($instance['endDateCurrent'] ?? ''),
		];
	}//end escalationPayload()

	/**
	 * Apply the declared term rule and read back the case's priority, '' when unreadable.
	 *
	 * The declared term rule runs BEFORE the payload is built, so the
	 * priority reported is the one the case carries after the raise rather
	 * than the one it carried a moment earlier. A threshold the
	 * declaration does not name raises nothing and this reads back
	 * unchanged.
	 *
	 * THIS IS WHERE A STORE FAILURE IS CONTAINED, and it is contained here
	 * rather than inside the raise service because this is the method that
	 * knows what the failure costs. A termijn notification is a statutory
	 * deadline warning: it goes out whether or not the case could be read.
	 * So a failed read leaves the priority unknown and says so, rather than
	 * reporting a case as `normal` when nobody actually asked it.
	 *
	 * @param string $caseId    The case.
	 * @param int    $threshold Threshold bucket.
	 *
	 * @return string The case priority, or ''.
	 */
	private function raisedPriority(string $caseId, int $threshold): string {
		try {
			return $this->priorityRaiseService->raiseForThreshold(
				caseId: $caseId,
				threshold: $threshold
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq termijn escalation: the case priority was unreadable, notifying without it',
				['case' => $caseId, 'threshold' => $threshold, 'error' => $e->getMessage()]
			);
			return '';
		}
	}//end raisedPriority()

	/**
	 * Record the rung as sent on the instance (duplicate suppression).
	 *
	 * @param array<string, mixed> $instance  TermijnInstance row.
	 * @param int                  $threshold Threshold bucket just sent.
	 *
	 * @return void
	 */
	private function markSent(array $instance, int $threshold): void {
		$alreadySent = (array)($instance['notificatiesVerstuurd'] ?? []);
		$alreadySent[] = $threshold;
		$this->termService->updateTermijnInstance(
			(string)$instance['id'],
			['notificatiesVerstuurd' => array_values(array_unique(array_map('intval', $alreadySent)))]
		);
	}//end markSent()

	/**
	 * Get the full escalation matrix (for admin UI rendering).
	 *
	 * Each row's urgency reads `notificationUrgency`. It was `priority`, which
	 * made an admin screen show two columns called priority on two different
	 * vocabularies, neither of them the one a handler sorts the queue by.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
	 * @spec openspec/specs/case-priority/spec.md
	 */
	public function matrix(): array {
		return self::DEFAULT_MATRIX;
	}//end matrix()
}//end class
