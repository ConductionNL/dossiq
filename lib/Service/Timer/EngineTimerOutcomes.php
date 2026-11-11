<?php

/**
 * The engine class and the outcomes shared by dossiq's engine timers.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Timer
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

namespace OCA\Dossiq\Service\Timer;

/**
 * What a timer's sync() answers, whichever subject it times.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
interface EngineTimerOutcomes {

	/**
	 * The engine class, resolved lazily so dossiq runs without OpenRegister.
	 *
	 * @var string
	 */
	public const ENGINE_CLASS = 'OCA\\OpenRegister\\Service\\Flow\\Timer\\FlowTimerService';

	/**
	 * Outcome: a timer is armed.
	 *
	 * @var string
	 */
	public const ARMED = 'armed';

	/**
	 * Outcome: the subject needs no timer and its timer is cancelled.
	 *
	 * @var string
	 */
	public const CANCELLED = 'cancelled';

	/**
	 * Outcome: nothing to time.
	 *
	 * @var string
	 */
	public const SKIPPED = 'skipped';

	/**
	 * Outcome: the engine is absent or refused.
	 *
	 * @var string
	 */
	public const UNAVAILABLE = 'unavailable';
}//end interface
