<?php

/**
 * One provisioned surface being linked forward: where its objects live, where progress goes and how its Decision is raised.
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

use OCP\Migration\IOutput;

/**
 * One provisioned surface being linked forward: where its objects live, where progress goes and how its Decision is raised.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class SurfaceLink {
	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister object service
	 * @param IOutput $output The migration output interface
	 * @param string $schemaSlug The surface schema's slug in dossiq's register
	 * @param callable $raise Callback raising the decidesk Decision
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly IOutput $output,
		public readonly string $schemaSlug,
		public readonly mixed $raise,
	) {
	}//end __construct()
}//end class
