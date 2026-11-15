<?php

/**
 * One move of a message: the folder it goes to, the handler's note and who moved it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

/**
 * One move of a message: the folder it goes to, the handler's note and who moved it.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class MailMove {
	/**
	 * Constructor.
	 *
	 * @param string $target The folder it goes to
	 * @param string $note The handler's note
	 * @param string $actorId Who moved it, or '' for the pipeline
	 */
	public function __construct(
		public readonly string $target,
		public readonly string $note,
		public readonly string $actorId,
	) {
	}//end __construct()
}//end class
