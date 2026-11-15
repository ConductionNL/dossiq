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

namespace OCA\Dossiq\Service\Zgw;

/**
 * How a stored object is mapped to its ZGW shape: the outbound mapping
 * entity, the resource's ZGW mapping and the collection URL.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
final class ZgwOutboundMapping {
	/**
	 * Constructor.
	 *
	 * @param object               $mapping       The outbound mapping entity.
	 * @param array<string, mixed> $mappingConfig The ZGW mapping configuration.
	 * @param string               $baseUrl       The collection URL.
	 */
	public function __construct(
		public readonly object $mapping,
		public readonly array $mappingConfig,
		public readonly string $baseUrl,
	) {
	}//end __construct()
}//end class
