<?php

/**
 * The OR federation leaf with the store a share snapshot lives in.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Sharing
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

namespace OCA\Dossiq\Service\Sharing;

/**
 * The OR federation leaf with the store a share snapshot lives in.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class FederatedShareTarget {
	/**
	 * Constructor.
	 *
	 * @param object $federationService The OR FederationShareService
	 * @param FederatedShareStore $store Where the snapshot is saved
	 */
	public function __construct(
		public readonly object $federationService,
		public readonly FederatedShareStore $store,
	) {
	}//end __construct()
}//end class
