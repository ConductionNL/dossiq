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
 * The resource an audit trail is read for: its name, its object id and its ZGW URL.
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */
final class ZgwAuditedResource {
	/**
	 * Constructor.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid     The object id.
	 * @param string $url      The object's ZGW URL.
	 */
	public function __construct(
		public readonly string $resource,
		public readonly string $uuid,
		public readonly string $url,
	) {
	}//end __construct()

	/**
	 * The fields every ZGW audit trail entry of this object carries about it.
	 *
	 * @return array{hoofdObject: string, resource: string, resourceUrl: string, resourceWeergave: string}
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function zgwFields(): array {
		return [
			'hoofdObject' => $this->url,
			'resource' => $this->resource,
			'resourceUrl' => $this->url,
			'resourceWeergave' => $this->resource,
		];
	}//end zgwFields()
}//end class
