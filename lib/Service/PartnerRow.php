<?php

/**
 * One partnerOrganization row being migrated, with the uuid and slug read from it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/partner-organisations/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * One partnerOrganization row being migrated, with the uuid and slug read from it.
 *
 * @spec openspec/specs/partner-organisations/spec.md
 */
final class PartnerRow {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $row         The partner object.
	 * @param string               $partnerUuid The partner's uuid.
	 * @param string               $slug        The partner's slug, trimmed, or ''.
	 *
	 * @spec openspec/specs/partner-organisations/spec.md
	 */
	public function __construct(
		public readonly array $row,
		public readonly string $partnerUuid,
		public readonly string $slug,
	) {
	}//end __construct()
}//end class
