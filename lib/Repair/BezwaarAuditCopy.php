<?php

/**
 * Dossiq BezwaarAuditCopy.
 *
 * One schema's copy of embedded bezwaar audit entries onto the audit trail:
 * the register and schema it copies, and the repair output it reports to.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCP\Migration\IOutput;

/**
 * The register and schema whose embedded bezwaar audit entries are copied, and where the copy reports.
 *
 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
 */
final class BezwaarAuditCopy {

	/**
	 * Constructor.
	 *
	 * @param string  $register The register.
	 * @param string  $schema   The schema.
	 * @param IOutput $output   Repair output.
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
		public readonly IOutput $output,
	) {
	}//end __construct()
}//end class
