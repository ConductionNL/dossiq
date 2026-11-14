<?php

/**
 * One stored informatieobject: where it lives and its id.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

/**
 * An informatieobject in OpenRegister.
 *
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
 */
final class StoredDocument {
	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The register.
	 * @param string $schema        The informatieobject schema.
	 * @param string $id            The informatieobject UUID.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
		public readonly string $id,
	) {
	}//end __construct()
}//end class
