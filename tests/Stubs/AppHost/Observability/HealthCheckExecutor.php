<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor.
 *
 * Declaration only. Dossiq's HealthController and MetricsController take these
 * as constructor type-hints and forward them to the engine untouched, so the
 * name is the whole contract this app depends on. NOT scanned by PHPCS.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Stubs\AppHost\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability;

if (class_exists(HealthCheckExecutor::class) === false) {
	/**
	 * Runs an adopter's declared health checks.
	 */
	class HealthCheckExecutor {
	}//end class
}//end if
