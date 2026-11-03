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

use OCP\IRequest;

/**
 * The request, API group, resource and object id a generic API handler acts on.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
final class ResourceRoute {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request  The request.
	 * @param string   $api      The API group.
	 * @param string   $resource The resource name.
	 * @param string   $uuid     The object id, or '' for a create.
	 */
	public function __construct(
		public readonly IRequest $request,
		public readonly string $api,
		public readonly string $resource,
		public readonly string $uuid = '',
	) {
	}//end __construct()

	/**
	 * The object as a log names it: resource and id.
	 *
	 * @return string The resource and id.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function objectLabel(): string {
		return $this->resource . ' ' . $this->uuid;
	}//end objectLabel()
}//end class
