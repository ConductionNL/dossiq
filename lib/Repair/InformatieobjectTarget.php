<?php

/**
 * Where the informatieobject backfill writes.
 *
 * Built once by `BackfillInformatieobjectMetadata` and handed through its private steps:
 * OpenRegister's object service, the register and the informatieobject schema.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

/**
 * Where the informatieobject backfill writes.
 *
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T09
 */
final class InformatieobjectTarget {

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register slug.
	 * @param string $schema        The informatieobject schema slug.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
