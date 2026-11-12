<?php

/**
 * Dossiq document update run.
 *
 * What one PUT or PATCH of an enkelvoudiginformatieobject shares between its
 * steps.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

/**
 * One document update: which document, full or partial, and, as it goes,
 * its mapping, the request body and the uuid it was saved under.
 *
 * Built by {@see DrcController} only; it never leaves it.
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */
final class EioUpdateRun {

	/**
	 * The EIO mapping, set once it is loaded.
	 *
	 * @var array<string, mixed>
	 */
	public array $mappingConfig = [];

	/**
	 * The request body, enriched by the business rules once they accept it.
	 *
	 * @var array<string, mixed>
	 */
	public array $body = [];

	/**
	 * The uuid the document was saved under, set once it is saved.
	 *
	 * @var string
	 */
	public string $objectUuid = '';

	/**
	 * Constructor.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid     The document UUID.
	 * @param bool   $partial  Whether this is a partial (PATCH) update.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $resource,
		public readonly string $uuid,
		public readonly bool $partial,
	) {
	}//end __construct()
}//end class
