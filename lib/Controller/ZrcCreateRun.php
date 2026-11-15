<?php

/**
 * Dossiq ZrcCreateRun.
 *
 * One validated ZRC create: the resource, its mapping, the request body as
 * sent and the body the business rules enriched.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

/**
 * A validated ZRC create on its way to the store.
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */
final class ZrcCreateRun {

	/**
	 * Constructor.
	 *
	 * @param string               $resource      The ZGW resource name.
	 * @param array<string, mixed> $mappingConfig The resource mapping.
	 * @param array<string, mixed> $originalBody  The request body as sent.
	 * @param array<string, mixed> $body          The enriched body.
	 */
	public function __construct(
		public readonly string $resource,
		public readonly array $mappingConfig,
		public readonly array $originalBody,
		public readonly array $body,
	) {
	}//end __construct()
}//end class
