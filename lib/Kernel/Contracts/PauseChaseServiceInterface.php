<?php

/**
 * Contract of PauseChaseService
 *
 * The public surface of PauseChaseService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of PauseChaseService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PauseChaseServiceInterface {

	/**
	 * Look at every paused instance once, and chase or escalate the ones due.
	 *
	 * @param DateTimeImmutable|null $now The moment to judge against.
	 *
	 * @return array{chased: int, escalated: int} What the sweep did.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function sweep(?DateTimeImmutable $now = null): array;

	/**
	 * Chase one instance, if one is due on it right now.
	 *
	 * @param array<string, mixed>   $instance The TermijnInstance row.
	 * @param DateTimeImmutable|null $now      The moment to judge against.
	 *
	 * @return bool True when a reminder actually went out.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function chaseIfDue(array $instance, ?DateTimeImmutable $now = null): bool;
}//end interface
