<?php

/**
 * The catalogue object being enriched: where it is read, its uuid, and the base its URLs are built on.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

/**
 * The catalogue object being enriched: where it is read, its uuid, and the base its URLs are built on.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
final class ZtcEnrichmentTarget {

	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $uuid          The object's uuid.
	 * @param string $baseUrl       The Catalogi API base URL.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $uuid,
		public readonly string $baseUrl,
	) {
	}//end __construct()
}//end class
