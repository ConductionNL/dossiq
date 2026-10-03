<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Observability\ManifestLoader.
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

if (class_exists(ManifestLoader::class) === false) {
	/**
	 * Loads an adopter's declarative observability manifest.
	 */
	class ManifestLoader {
	}//end class
}//end if
