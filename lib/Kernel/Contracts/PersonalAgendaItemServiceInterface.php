<?php

/**
 * Contract of PersonalAgendaItemService
 *
 * The public surface of PersonalAgendaItemService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of PersonalAgendaItemService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PersonalAgendaItemServiceInterface {

	/**
	 * Plan an item on this person's own calendar.
	 *
	 * @param string $userId   The person.
	 * @param string $title    What the item is.
	 * @param string $startsAt When it starts, any format DateTimeImmutable reads.
	 * @param string $template Which template it came from, or an empty string.
	 *
	 * @return array{uid: string, startsAt: string, minutes: int} What was written.
	 *
	 * @throws RuntimeException When the person has no writable calendar, or the moment is unreadable.
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function plan(string $userId, string $title, string $startsAt, string $template = ''): array;
}//end interface
