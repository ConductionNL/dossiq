<?php

/**
 * Contract of PlannedItemDefaults
 *
 * The public surface of PlannedItemDefaults that other components call, so they depend
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
 * Contract of PlannedItemDefaults.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PlannedItemDefaultsInterface {

	/**
	 * The start a new planned item opens with: the next working day after
	 * `now` at 10:00, as a `datetime-local` value.
	 *
	 * @param DateTimeImmutable|null $now The moment to look from; now by default.
	 *
	 * @return array{startsAt: string} The defaults.
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function startsAt(?DateTimeImmutable $now = null): array;
}//end interface
