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
 * A write on its way to the store: the route, its mapping, the stored object and the request body.
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */
final class ResourceWrite {
	/**
	 * Constructor.
	 *
	 * @param ResourceRoute $route    The route.
	 * @param array         $mapping  The mapping configuration (`sourceRegister`, `sourceSchema`).
	 * @param array|null    $existing The stored object an update or delete acts on.
	 * @param array         $body     The request body.
	 */
	public function __construct(
		public readonly ResourceRoute $route,
		public readonly array $mapping,
		public readonly ?array $existing = null,
		public readonly array $body = [],
	) {
	}//end __construct()

	/**
	 * The same write with the request body read.
	 *
	 * @param array $body The request body.
	 *
	 * @return self The write.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function withBody(array $body): self {
		return new self(route: $this->route, mapping: $this->mapping, existing: $this->existing, body: $body);
	}//end withBody()
}//end class
