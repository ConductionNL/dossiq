<?php

/**
 * FlowRunMigrationServicePresence fixture.
 *
 * Declares OpenRegister's run migration service class, empty, for a test that
 * needs EngineRunMigration to see it as shipped. EngineRunMigration only asks
 * whether the class exists and then calls the verb on whatever the container
 * hands back, so the class carries nothing. A real OpenRegister on the
 * autoloader wins: the declaration is skipped when the class already loads.
 * Required from inside separate-process tests only, so no other test sees it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

if (class_exists(FlowRunMigrationService::class) === true) {
	return;
}

/**
 * Stand-in for OpenRegister's run migration service class.
 */
class FlowRunMigrationService {
}//end class
