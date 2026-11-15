<?php

/**
 * One subsidy scheme whose case type was created, on its way to its property definitions.
 *
 * Built by `MigrateSubsidieRegelingToCaseType` once the case type has an id, and handed through the
 * steps that save its property definitions: the source scheme, its name, the new case type, the
 * register and the upgrade output.
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
 * One subsidy scheme whose case type was created, on its way to its property definitions.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class MigratedScheme {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $scheme     The source subsidieRegeling.
	 * @param string               $name       The scheme name, for messages.
	 * @param string               $caseTypeId The case type its properties belong to.
	 * @param string               $register   Register id.
	 * @param IOutput              $output     Upgrade output.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $scheme,
		public readonly string $name,
		public readonly string $caseTypeId,
		public readonly string $register,
		public readonly IOutput $output,
	) {
	}//end __construct()
}//end class
