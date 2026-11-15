<?php

/**
 * Contract of DsoDeadlineTimer
 *
 * The public surface of DsoDeadlineTimer that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of DsoDeadlineTimer.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DsoDeadlineTimerInterface {

	/**
	 * The metadata source the fired listener recognises the timer by.
	 *
	 * @var string
	 */
	public const METADATA_SOURCE = 'dossiq-dso';

	/**
	 * The DSO statuses whose decision term runs.
	 *
	 * @var string[]
	 */
	public const OPEN_STATUSES = ['submitted', 'in_handling'];

	/**
	 * The rung message of the warning band.
	 *
	 * @var string
	 */
	public const MESSAGE_WARNING = 'dso-termijn-waarschuwing';

	/**
	 * The rung message of the critical band.
	 *
	 * @var string
	 */
	public const MESSAGE_CRITICAL = 'dso-termijn-kritiek';

	/**
	 * Outcome: a timer is armed.
	 *
	 * @var string
	 */
	public const ARMED = 'armed';

	/**
	 * Outcome: the deadline is today or past; the caller marks it overdue now.
	 *
	 * @var string
	 */
	public const DUE = 'due';

	/**
	 * Outcome: the engine is absent or refused.
	 *
	 * @var string
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Bring the case's DSO timer in step with the case.
	 *
	 * @param array<string, mixed> $case The case object.
	 *
	 * @return string One of the outcome constants.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function sync(array $case): string;
}//end interface
