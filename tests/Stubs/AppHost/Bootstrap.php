<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Bootstrap.
 *
 * Mirrors the public signature of the OpenRegister AppHost one-call bootstrap
 * (ADR-040). Used only where the openregister runtime is not installed (bare
 * CI containers / standalone static analysis). No-ops when the real class is
 * present (i.e. when the openregister app is installed).
 *
 * Loaded by tests/bootstrap.php when the real class is absent. NOT scanned by
 * PHPCS.
 *
 * @category Test
 * @package  OCA\Procest\Tests\Stubs\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

if (class_exists(Bootstrap::class) === false) {
	/**
	 * Stub for the AppHost bootstrap — used only in standalone analysis/tests.
	 */
	class Bootstrap {

		/**
		 * Register the AppHost generics for a leaf app. No-op in the stub.
		 *
		 * @param IRegistrationContext $context The leaf registration context.
		 * @param string $appId The leaf app id.
		 * @param array<string, mixed> $options Bootstrap options.
		 *
		 * @return void
		 */
		public static function register(IRegistrationContext $context, string $appId, array $options = []): void {
		}//end register()
	}//end class
}//end if
