<?php

/**
 * One schema the KCC-werkplek seed writes rows into.
 *
 * Built by `KccWerkplekSeedDataService` for each kind of seed row and handed through its private
 * steps: OpenRegister's object service, the register, the schema and the counter the inserted rows are
 * tallied under.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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

namespace OCA\Dossiq\Service;

/**
 * One schema the KCC-werkplek seed writes rows into.
 *
 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/specs.md
 */
final class KccSeedTarget {

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register      Register id.
	 * @param string $schema        Schema id.
	 * @param string $counterKey    Counter key to increment on insert.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
		public readonly string $counterKey,
	) {
	}//end __construct()
}//end class
