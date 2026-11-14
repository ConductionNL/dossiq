<?php

/**
 * Contract of WalkthroughCompletion
 *
 * The public surface of WalkthroughCompletion that other components call, so they depend
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
 * Contract of WalkthroughCompletion.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WalkthroughCompletionInterface {

	/**
	 * The initial-state key the frontend reads.
	 *
	 * @var string
	 */
	public const STATE_KEY = 'walkthrough_seen';

	/**
	 * Whether the caller has finished or skipped the welcome tour.
	 *
	 * A request with no user answers true, so nothing is held back for a
	 * page that has no tour to show first.
	 *
	 * @return bool True when the tour is behind them.
	 *
	 * @spec openspec/specs/first-load-onboarding/spec.md
	 */
	public function seen(): bool;
}//end interface
