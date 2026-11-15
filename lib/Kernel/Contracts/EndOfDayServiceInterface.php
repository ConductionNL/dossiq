<?php

/**
 * Contract of EndOfDayService
 *
 * The public surface of EndOfDayService that other components call, so they depend
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
 * Contract of EndOfDayService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EndOfDayServiceInterface {

	/**
	 * The reader's day.
	 *
	 * @param string                 $userId The reader.
	 * @param DateTimeImmutable|null $now    Now, in the reader's time zone; the server's now when absent.
	 *
	 * @return array<string, mixed> `open`, `recorded`, `tomorrow`, `figures`, the counts and what could not be read.
	 *
	 * @spec openspec/specs/end-of-day-manifest/spec.md#requirement-the-day-lists-are-worked-out-on-the-server
	 */
	public function dayFor(string $userId, ?DateTimeImmutable $now=null): array;
}//end interface
