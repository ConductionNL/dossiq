<?php

/**
 * Arm the engine timers of bezwaar triggers already running.
 *
 * BezwaarTermijnJob retires in the release that ships the bezwaartermijn
 * timers, so a trigger active before the upgrade has no timer until this
 * step arms one. Arming supersedes, so the step is safe on every upgrade.
 * A trigger whose archive date passed while nobody watched is acted on
 * here, as the job would have on its next run.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\Beschikking\BezwaarArchiveTimer;
use OCA\Dossiq\Service\Beschikking\BezwaarArchiveTrigger;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Syncs the timer of every active bezwaar trigger once.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class ArmBezwaarArchiveTimers implements IRepairStep {
	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * Build the step.
	 *
	 * @param SettingsService       $settingsService Reaches OpenRegister and the schema.
	 * @param BezwaarArchiveTimer   $timer           Arms each trigger's timer.
	 * @param BezwaarArchiveTrigger $trigger         Acts on the triggers already due.
	 * @param LoggerInterface       $logger          Logs the counts.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly BezwaarArchiveTimer $timer,
		private readonly BezwaarArchiveTrigger $trigger,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function getName(): string {
		return 'Arm OpenRegister engine timers for running Dossiq bezwaartermijnen';
	}//end getName()

	/**
	 * Run the step.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		$register      = (string) $this->settingsService->getConfigValue('register');
		$schema        = (string) $this->settingsService->getConfigValue(BezwaarArchiveTrigger::SCHEMA_CONFIG_KEY);
		if ($this->settingsService->isOpenRegisterAvailable() === false || $objectService === null || $register === '' || $schema === '') {
			$output->warning('OpenRegister or the bezwaarTrigger schema is not available. Skipping bezwaartermijn timer migration.');
			return;
		}

		$counts = ['armed' => 0, 'due' => 0, 'failed' => 0];

		// No session, so OpenRegister sees Anonymous: the read and the archive
		// writes run under the system identity every other writing step uses.
		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $register, $schema, &$counts): void {
				try {
					$triggers = $this->searchObjectsAsArrays(
						objectService: $objectService,
						register: $register,
						schema: $schema,
						filters: ['archiveTriggerActive' => true, '_limit' => 1000],
					);
				} catch (Throwable $e) {
					$this->logger->error('Dossiq bezwaartermijn timer migration: the triggers could not be read', ['error' => $e->getMessage()]);
					$triggers = [];
				}

				foreach ($triggers as $trigger) {
					$this->syncOne(trigger: $trigger, counts: $counts);
				}
			}
		);

		$this->logger->info('Dossiq bezwaartermijn timer migration complete', $counts);
		$output->info(
			sprintf(
				'Bezwaartermijn timer migration: %d armed, %d due and handled, %d failed.',
				$counts['armed'],
				$counts['due'],
				$counts['failed']
			)
		);
	}//end run()

	/**
	 * Sync one trigger and count the outcome.
	 *
	 * @param array<string, mixed> $trigger The trigger.
	 * @param array<string, int>   $counts  The running counts.
	 *
	 * @return void
	 */
	private function syncOne(array $trigger, array &$counts): void {
		$outcome = $this->timer->sync(trigger: $trigger);
		if ($outcome === BezwaarArchiveTimer::ARMED) {
			$counts['armed']++;
			return;
		}

		if ($outcome === BezwaarArchiveTimer::DUE) {
			$this->trigger->process(trigger: $trigger);
			$counts['due']++;
			return;
		}

		if ($outcome === BezwaarArchiveTimer::UNAVAILABLE) {
			$counts['failed']++;
		}
	}//end syncOne()
}//end class
