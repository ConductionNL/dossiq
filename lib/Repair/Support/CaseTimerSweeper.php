<?php

/**
 * Dossiq Case Timer Sweeper
 *
 * Reads the cases a timer arming step syncs, as the system, and hands each on.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Support;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The guard and the case read the case timer arming steps share.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
final class CaseTimerSweeper {
	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Reaches OpenRegister and the case schema.
	 * @param LoggerInterface $logger          Logs a failed read.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Hand every matching case to the sweep's work, as the system.
	 *
	 * A read that fails is logged and sweeps nothing.
	 *
	 * @param CaseTimerSweep $sweep The sweep.
	 *
	 * @return bool False when OpenRegister or the case schema is not available, so nothing ran.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function sweep(CaseTimerSweep $sweep): bool {
		$sweep->objectService = $this->settingsService->getObjectService();
		$sweep->register      = (string) $this->settingsService->getConfigValue('register');
		$sweep->schema        = (string) $this->settingsService->getConfigValue('case_schema');
		if ($this->unavailable(sweep: $sweep) === true) {
			return false;
		}

		$this->withSystemIdentity(
			objectService: $sweep->objectService,
			work: function () use ($sweep): void {
				foreach ($this->cases(sweep: $sweep) as $case) {
					($sweep->each)($case);
				}
			}
		);

		return true;
	}//end sweep()

	/**
	 * Whether OpenRegister, the register or the case schema is missing.
	 *
	 * @param CaseTimerSweep $sweep The sweep, with what the sweeper found.
	 *
	 * @return bool
	 */
	private function unavailable(CaseTimerSweep $sweep): bool {
		return $this->settingsService->isOpenRegisterAvailable() === false
			|| $sweep->objectService === null
			|| $sweep->register === ''
			|| $sweep->schema === '';
	}//end unavailable()

	/**
	 * The cases the sweep's filters match, or none when the read fails (logged).
	 *
	 * @param CaseTimerSweep $sweep The sweep, with what the sweeper found.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function cases(CaseTimerSweep $sweep): array {
		try {
			return $this->searchObjectsAsArrays(
				objectService: $sweep->objectService,
				register: $sweep->register,
				schema: $sweep->schema,
				filters: $sweep->filters,
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq ' . $sweep->migration . ' migration: the cases could not be read', ['error' => $e->getMessage()]);
			return [];
		}
	}//end cases()
}//end class
