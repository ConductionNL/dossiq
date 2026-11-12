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
 * One page of a generic ZGW list while it is read: the route and its mapping,
 * and, as it goes, the query, the page asked for and the collection URL.
 *
 * Built and read by ZgwService only.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
final class ZgwListing {

	/**
	 * The query the request carries.
	 *
	 * @var array<string, mixed>
	 */
	public array $params = [];

	/**
	 * The page asked for, from 1.
	 *
	 * @var integer
	 */
	public int $page = 1;

	/**
	 * The page size asked for, between 1 and 100.
	 *
	 * @var integer
	 */
	public int $pageSize = 20;

	/**
	 * The collection URL.
	 *
	 * @var string
	 */
	public string $baseUrl = '';

	/**
	 * Constructor.
	 *
	 * @param ResourceRoute        $route         The route.
	 * @param array<string, mixed> $mappingConfig The resource's ZGW mapping.
	 */
	public function __construct(
		public readonly ResourceRoute $route,
		public readonly array $mappingConfig,
	) {
	}//end __construct()
}//end class
