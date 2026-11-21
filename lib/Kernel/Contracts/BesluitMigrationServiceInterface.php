<?php

/**
 * Contract of BesluitMigrationService
 *
 * The public surface of BesluitMigrationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of BesluitMigrationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BesluitMigrationServiceInterface {

	/**
	 * Migrate, or report what a migration would do.
	 *
	 * @param bool $commit False to read and report only, true to write.
	 *
	 * @return array{status:string, total:int, migrated:int, skipped:int, failed:int, detached:bool, message:string}
	 *         A summary. `status` is 'ok' when the run completed and 'blocked'
	 *         when a precondition stopped it before any read.
	 *
	 * @spec openspec/specs/zgw-brc/spec.md#requirement-besluiten-move-onto-decidiq-only-when-asked-req-brc-021
	 */
	public function migrate(bool $commit): array;
}//end interface
