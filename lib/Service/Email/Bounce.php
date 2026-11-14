<?php

/**
 * One bounce: the address it goes to, why, and who asked for it.
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
 * One bounce: the address it goes to, why, and who asked for it.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class Bounce {
	/**
	 * Constructor.
	 *
	 * @param string $recipient The address it goes to
	 * @param string $reason The handler's reason
	 * @param string $actorId Who bounced it, or '' for the pipeline
	 */
	public function __construct(
		public readonly string $recipient,
		public readonly string $reason,
		public readonly string $actorId,
	) {
	}//end __construct()
}//end class
