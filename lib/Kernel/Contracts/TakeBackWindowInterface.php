<?php

/**
 * Contract of TakeBackWindow
 *
 * The public surface of TakeBackWindow that other components call, so they depend
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
 * Contract of TakeBackWindow.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TakeBackWindowInterface {

	/**
	 * The metadata source the fired listener recognises the timer by.
	 *
	 * @var string
	 */
	public const METADATA_SOURCE = 'dossiq-take-back';

	/**
	 * Cancel the case's take-back timer (and only that one).
	 *
	 * @param string $caseId The case id.
	 * @param string $reason Why: accepted, superseded, taken back.
	 *
	 * @return string CANCELLED, SKIPPED or UNAVAILABLE.
	 *
	 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
	 */
	public function cancel(string $caseId, string $reason): string;
}//end interface
