<?php

/**
 * Dossiq Rearm Beslistermijn Timers Repair Step.
 *
 * A beslistermijn timer armed before one-term-engine was anchored at the
 * moment the term started, so it breached on the term's last day and the
 * fired listener marked the term exceeded while it still had hours to run.
 * This step re-arms the timer of every running statutory term once, at the
 * new anchor (the day after the start day), and marks the term
 * `timerBreachesAfterLastDay`. Idempotent through that mark; counted; never
 * fails the upgrade.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-existing-cases-are-repaired-once-req-ote-08
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-arms every running beslistermijn timer once, so it breaches the day after the last day.
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-existing-cases-are-repaired-once-req-ote-08
 */
class RearmBeslistermijnTimers implements IRepairStep {
	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * The statuses whose timer is running. A paused term's timer is suspended
	 * and is re-armed by the resume, which arms at the new anchor already.
	 */
	private const RUNNING = ['lopend', 'verlengd'];

	/**
	 * The most term instances one run reads.
	 */
	private const PAGE = 10000;

	/**
	 * Constructor.
	 *
	 * @param SettingsService     $settingsService Settings and ObjectService access.
	 * @param TermijnService      $termService     Writes the new timer id and the mark.
	 * @param TermijnTimerService $timerService    Cancels and arms the engine timer.
	 * @param CaseDeadlineMirror  $mirror          Which terms are statutory.
	 * @param LoggerInterface     $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TermijnService $termService,
		private readonly TermijnTimerService $timerService,
		private readonly CaseDeadlineMirror $mirror,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair-step display name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-existing-cases-are-repaired-once-req-ote-08
	 */
	public function getName(): string {
		return 'Re-arm Dossiq decision term timers so they breach the day after the last day';
	}//end getName()

	/**
	 * Run the repair.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-existing-cases-are-repaired-once-req-ote-08
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister is not available. Skipping the term timer re-arm.');
			return;
		}

		$rows = null;
		$counts = ['rearmed' => 0, 'unchanged' => 0, 'failed' => 0];

		$this->withSystemIdentity(
			objectService: $this->settingsService->getObjectService(),
			work: function () use (&$rows, &$counts): void {
				$rows = $this->instances();
				foreach (($rows ?? []) as $row) {
					$this->rearm(row: $row, counts: $counts);
				}
			}
		);

		if ($rows === null) {
			$output->warning('Term instances not readable (schemas unconfigured, or the read was refused). Skipping the term timer re-arm.');
			return;
		}

		$this->logger->info('Dossiq term timer re-arm complete', $counts);
		$output->info(
			sprintf(
				'Decision term timers: %d re-armed, %d already right, %d failed.',
				$counts['rearmed'],
				$counts['unchanged'],
				$counts['failed']
			)
		);
	}//end run()

	/**
	 * Re-arm one instance when it needs it.
	 *
	 * @param array<string, mixed> $row    The term instance.
	 * @param array<string, int>   $counts Running counts (by reference).
	 *
	 * @return void
	 */
	private function rearm(array $row, array &$counts): void {
		if ($this->needsRearm(row: $row) === false) {
			$counts['unchanged']++;
			return;
		}

		$rowId = (string)$row['id'];
		try {
			$this->timerService->cancelForInstance(instanceId: $rowId, reason: 'Opnieuw gezet: de termijn verloopt pas na de laatste dag');
			$timerId = $this->timerService->armBeslistermijn(instance: $row, definitie: $this->definitionFor(row: $row));
			if ($timerId === null) {
				$counts['failed']++;
				$this->logger->warning('Dossiq term timer re-arm: arming failed', ['instance' => $rowId]);
				return;
			}

			$this->termService->updateTermijnInstance($rowId, ['engineTimerId' => $timerId, 'timerBreachesAfterLastDay' => true]);
			$counts['rearmed']++;
		} catch (Throwable $e) {
			$counts['failed']++;
			$this->logger->warning('Dossiq term timer re-arm: an instance could not be re-armed', ['instance' => $rowId, 'error' => $e->getMessage()]);
		}
	}//end rearm()

	/**
	 * Whether an instance runs a timer armed at the old anchor.
	 *
	 * @param array<string, mixed> $row The term instance.
	 *
	 * @return bool True when it is a running statutory term with a timer and no mark.
	 */
	private function needsRearm(array $row): bool {
		return (string)($row['id'] ?? '') !== ''
			&& in_array((string)($row['status'] ?? ''), self::RUNNING, true) === true
			&& (string)($row['engineTimerId'] ?? '') !== ''
			&& ($row['timerBreachesAfterLastDay'] ?? false) !== true
			&& $this->mirror->isStatutory(instance: $row) === true;
	}//end needsRearm()

	/**
	 * Every term instance, or null when the store is unreachable.
	 *
	 * @return array<int, array<string, mixed>>|null The rows.
	 */
	private function instances(): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_instance_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::PAGE]
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq term timer re-arm: listing the terms failed', ['error' => $e->getMessage()]);
			return null;
		}
	}//end instances()

	/**
	 * The definition behind an instance, for the extension ceiling and the counting mode.
	 *
	 * A failed read throws, and the instance is counted failed: arming without
	 * the definition would arm a working-day term in calendar days.
	 *
	 * @param array<string, mixed> $row The term instance.
	 *
	 * @return array<string, mixed> The definition, or empty when the instance names none.
	 */
	private function definitionFor(array $row): array {
		$defId = (string)($row['deadlineDefinition'] ?? '');
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_definitie_schema');
		if ($defId === '' || $objectService === null || $register === '' || $schema === '') {
			return [];
		}

		return ($this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $defId) ?? []);
	}//end definitionFor()
}//end class
