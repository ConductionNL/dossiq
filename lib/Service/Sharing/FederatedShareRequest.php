<?php

/**
 * What a federated case share was asked to carry, and who asked.
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
 * What a federated case share was asked to carry, and who asked.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class FederatedShareRequest {
	/**
	 * Constructor.
	 *
	 * @param string $caseId The UUID of the case to share
	 * @param string $remoteCloudId The federated target (slug@host)
	 * @param array<string> $sharedFields Requested case field names
	 * @param array<string> $sharedDocuments Requested document references
	 * @param string $permissionLevel Permission level slug
	 * @param string $createdBy User ID of the share creator
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $remoteCloudId,
		public readonly array $sharedFields,
		public readonly array $sharedDocuments,
		public readonly string $permissionLevel,
		public readonly string $createdBy,
	) {
	}//end __construct()
}//end class
