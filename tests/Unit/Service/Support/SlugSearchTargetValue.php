<?php

/**
 * Stands in for OpenRegister's Contract\RegisterSchemaSlugs in the slug-search tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/or-object-search/spec.md#requirement-a-slug-search-passes-the-target-openregister-asks-for-req-sst-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

/**
 * A register and schema slug pair, as OpenRegister main passes it.
 */
final class SlugSearchTargetValue {
	/**
	 * Constructor.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
