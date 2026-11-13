<?php

/**
 * Contract of PersonalQueueService
 *
 * The public surface of PersonalQueueService that other components call, so they depend
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
 * Contract of PersonalQueueService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PersonalQueueServiceInterface {

	/**
	 * Everything waiting on one person, most pressing first.
	 *
	 * @param string                 $userId The person.
	 * @param DateTimeImmutable|null $now    The moment to score against; the current one when null.
	 *
	 * @return array<string, mixed> The queue: items, groups, unavailable sources and the reader's view settings.
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function forPerson(string $userId, ?DateTimeImmutable $now = null): array;
}//end interface
