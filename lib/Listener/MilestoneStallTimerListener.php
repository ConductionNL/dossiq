<?php

/**
 * Dossiq milestone stall timer listener.
 *
 * Re-syncs a case's milestone timer when what it waits on can have moved:
 * a case save that changed its status, start date or case type, and any
 * save of a milestone record (a milestone reached or un-reached), which
 * re-syncs the record's case. A case already stalled when synced is told
 * at once.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Listener\Support\SavedObjectPayload;
use OCA\Dossiq\Service\Milestone\MilestoneStallActs;
use OCA\Dossiq\Service\Milestone\MilestoneStallTimer;
use OCA\Dossiq\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Syncs the milestone timer on case and milestone-record saves.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class MilestoneStallTimerListener implements IEventListener {
	use SavedObjectPayload;

	/**
	 * The case fields whose change can move the milestone a case waits on.
	 *
	 * @var string[]
	 */
	private const CASE_FIELDS = ['status', 'startDate', 'caseType'];

	/**
	 * Build the listener.
	 *
	 * @param SettingsService     $settingsService Names the case and milestone record schemas.
	 * @param MilestoneStallTimer $timer           Arms and cancels the timer.
	 * @param MilestoneStallActs  $acts            Reads a case and tells a stalled one.
	 * @param LoggerInterface     $logger          Logs a failed sync.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly MilestoneStallTimer $timer,
		private readonly MilestoneStallActs $acts,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle one saved object.
	 *
	 * @param Event $event An ObjectCreatedEvent or ObjectUpdatedEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function handle(Event $event): void {
		try {
			$saved = $this->savedObject(event: $event);
			if ($saved === null) {
				return;
			}

			$case = $this->caseToSync(event: $event, saved: $saved);
			if ($case !== null && $this->timer->sync(case: $case) === MilestoneStallTimer::DUE) {
				$this->acts->notifyIfStalled(case: $case);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq milestone: the stall timer could not be synced: '.$e->getMessage());
		}
	}//end handle()

	/**
	 * The case this save re-syncs, or null when it moves nothing.
	 *
	 * @param Event                $event The event.
	 * @param array<string, mixed> $saved The saved object.
	 *
	 * @return array<string, mixed>|null
	 */
	private function caseToSync(Event $event, array $saved): ?array {
		if ($this->inSchema(object: $saved, configured: (string) $this->settingsService->getConfigValue('case_schema')) === true) {
			if ($this->unchanged(before: $this->previousObject(event: $event), after: $saved, fields: self::CASE_FIELDS) === true) {
				return null;
			}

			return $saved;
		}

		if ($this->inSchema(object: $saved, configured: (string) $this->settingsService->getConfigValue('milestone_record_schema')) === true) {
			$caseRef = $saved['case'] ?? '';
			$caseId  = is_array($caseRef) === true ? (string) ($caseRef['id'] ?? '') : (string) $caseRef;
			return $this->acts->find(caseId: $caseId);
		}

		return null;
	}//end caseToSync()
}//end class
