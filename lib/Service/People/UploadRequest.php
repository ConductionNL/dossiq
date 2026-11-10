<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

use DateTime;
use OCP\Files\Folder;
use OCP\IUser;

/**
 * One file request that passed its checks: the case folder a party may put a
 * file in, the address it goes to, who asks, until when, and the note.
 *
 * It never leaves FileRequestService, which builds it once every refusal has
 * been checked.
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
 */
final class UploadRequest {

	/**
	 * The case folder.
	 *
	 * @var Folder
	 */
	public Folder $folder;

	/**
	 * The party's address.
	 *
	 * @var string
	 */
	public string $email = '';

	/**
	 * Who asks.
	 *
	 * @var IUser
	 */
	public IUser $user;

	/**
	 * Until when the request stands.
	 *
	 * @var DateTime
	 */
	public DateTime $expires;

	/**
	 * The note to the party, or ''.
	 *
	 * @var string
	 */
	public string $note = '';
}//end class
