<?php

/**
 * Dossiq EioDocument.
 *
 * One stored enkelvoudig informatieobject as the lock steps read and write it:
 * OpenRegister's object service, the EIO mapping and the document's uuid.
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
 * A stored document, the object service that holds it and its EIO mapping.
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */
final class EioDocument {

	/**
	 * Constructor.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $mappingConfig The EIO mapping: its source register and schema.
	 * @param string               $uuid          The document UUID.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly array $mappingConfig,
		public readonly string $uuid,
	) {
	}//end __construct()
}//end class
