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
 * How a Dutch request body is mapped to English: the inbound mapping entity and the resource's ZGW mapping.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
final class ZgwInboundMapping {
	/**
	 * Constructor.
	 *
	 * @param object               $mapping       The inbound mapping entity.
	 * @param array<string, mixed> $mappingConfig The ZGW mapping configuration.
	 */
	public function __construct(
		public readonly object $mapping,
		public readonly array $mappingConfig,
	) {
	}//end __construct()
}//end class
