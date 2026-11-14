<?php

/**
 * Contract of AdviceTimer
 *
 * The public surface of AdviceTimer that other components call, so they depend
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
 * Contract of AdviceTimer.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AdviceTimerInterface {

	/**
	 * The metadata source the fired listener recognises the timer by.
	 *
	 * @var string
	 */
	public const METADATA_SOURCE = 'dossiq-advice';

	/**
	 * Outcome: a timer is armed.
	 *
	 * @var string
	 */
	public const ARMED = 'armed';

	/**
	 * Outcome: the deadline is already past; the caller expires the request.
	 *
	 * @var string
	 */
	public const OVERDUE = 'overdue';

	/**
	 * Outcome: the engine is absent or refused.
	 *
	 * @var string
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Bring the request's timer in step with the request.
	 *
	 * @param array<string, mixed> $advice The adviesAanvraag object.
	 *
	 * @return string One of the outcome constants.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function sync(array $advice): string;
}//end interface
