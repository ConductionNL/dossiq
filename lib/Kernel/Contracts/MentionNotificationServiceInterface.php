<?php

/**
 * Contract of MentionNotificationService
 *
 * The public surface of MentionNotificationService that other components call, so they depend
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

use OCA\Dossiq\Service\Notes\MentionedNote;

/**
 * Contract of MentionNotificationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MentionNotificationServiceInterface {

	/**
	 * Notify every mentioned user, skipping the note's own author.
	 *
	 * @param string $actorUserId The note author's user id
	 * @param string $actorDisplayName The note author's display name
	 * @param MentionedNote $note The note, the object it is attached to and where that lives
	 * @param array<string> $mentionedUserIds The mentioned users' NC user ids
	 *
	 * @return int Number of notifications actually dispatched
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	public function notifyMention(
		string $actorUserId,
		string $actorDisplayName,
		MentionedNote $note,
		array $mentionedUserIds,
	): int;
}//end interface
