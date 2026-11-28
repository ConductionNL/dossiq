<?php

/**
 * The search for a document link's counterpart in the Zaken or Besluiten API (drc-004).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

/**
 * Where and how the counterpart of a document link is looked up.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
final class RelationSearch {
	/**
	 * Constructor.
	 *
	 * @param string               $register      The register.
	 * @param string               $schema        The relation schema.
	 * @param array<string, mixed> $requestParams The search parameters.
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
		public readonly array $requestParams,
	) {
	}//end __construct()
}//end class
