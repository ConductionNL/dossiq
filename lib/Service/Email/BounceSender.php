<?php

/**
 * The configured from-address and from-name a bounce is sent with.
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
 * The configured from-address and from-name a bounce is sent with.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class BounceSender {
	/**
	 * Constructor.
	 *
	 * @param string $fromAddress The configured from-address
	 * @param string $fromName The configured from-name
	 */
	public function __construct(
		public readonly string $fromAddress,
		public readonly string $fromName,
	) {
	}//end __construct()
}//end class
