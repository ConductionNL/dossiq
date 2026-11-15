<?php

/**
 * Where the work queue reads its cases from.
 *
 * The object store and the register and case schema the cases live in, or
 * nothing at all when the app is not configured yet.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
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
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

/**
 * The configured object store, register and case schema.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
final class CaseQueueSource {

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register      Register slug/id.
	 * @param string $caseSchema    Case schema slug/id.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $caseSchema,
	) {
	}//end __construct()
}//end class
