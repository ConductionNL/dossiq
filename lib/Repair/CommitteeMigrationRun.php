<?php

/**
 * Dossiq CommitteeMigrationRun.
 *
 * One run of the committee migration: OpenRegister's object service, the
 * register and committee schema it reads and writes, and the output it reports to.
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
 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCP\Migration\IOutput;

/**
 * Where the committee migration reads, writes and reports.
 *
 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
 */
final class CommitteeMigrationRun {

	/**
	 * Constructor.
	 *
	 * @param object  $objectService OpenRegister's object service.
	 * @param string  $register      The register slug.
	 * @param string  $schema        The committee schema slug.
	 * @param IOutput $output        The migration output.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
		public readonly IOutput $output,
	) {
	}//end __construct()
}//end class
