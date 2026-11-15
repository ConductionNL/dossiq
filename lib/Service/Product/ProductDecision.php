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

namespace OCA\Dossiq\Service\Product;

/**
 * The concluded decision a product is issued for.
 *
 * @spec openspec/changes/archive/2026-10-30-portal-permits-as-held-products/tasks.md#2.1
 */
final class ProductDecision {

	/**
	 * Constructor.
	 *
	 * @param string      $id        The decision.
	 * @param string|null $decidedAt When it concluded (ISO 8601), or null.
	 */
	public function __construct(
		public readonly string $id,
		public readonly ?string $decidedAt,
	) {
	}//end __construct()
}//end class
