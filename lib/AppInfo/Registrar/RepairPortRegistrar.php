<?php

/**
 * Dossiq repair-port registrar.
 *
 * The storage seams the repair steps depend on, bound to their database
 * implementations.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo\Registrar
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
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Repair\DbValueMigrationPort;
use OCA\Dossiq\Repair\ValueMigrationPort;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds the repair steps' storage seams.
 *
 * Storage seam for the Dutch-to-English value migration: the repair step
 * depends on the interface so its own logic can be exercised against a fake;
 * only this binding knows the database.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class RepairPortRegistrar {
	/**
	 * Register the repair-port bindings.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(ValueMigrationPort::class, DbValueMigrationPort::class);
	}//end register()
}//end class
