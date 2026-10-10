<?php

/**
 * Dossiq SaaS service registrar.
 *
 * The SaaS service that cannot be autowired because its constructor takes
 * plain strings read from app config: the Shillinq invoicing endpoint and API
 * key. Split out of Application so the config-reading factory sits outside
 * the bootstrap class. The tenant token service it also built is deleted
 * (Q2, 2026-10-08); `jwt_signing_secret` stays, read by `PortalAssertionVerifier`.
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

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\ShillinqIntegrationService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;
use Psr\Container\ContainerInterface;

/**
 * Registers the config-driven SaaS service (Shillinq invoicing).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class SaasServiceRegistrar {
	/**
	 * Register the SaaS services the middleware chain factories from app config.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// SaaS chain (member 10): factory the ShillinqIntegrationService with
		// the invoicing endpoint + API key from app config. Without this the
		// string constructor args default to '' and exportInvoice short-circuits
		// to "Shillinq not configured" — leaving every tenant invoice unexported
		// (procest#223 finding 2). Empty config keeps the graceful no-op.
		$context->registerService(
			ShillinqIntegrationService::class,
			static function (ContainerInterface $c): ShillinqIntegrationService {
				$config = $c->get(IConfig::class);
				$baseUrl = (string)$config->getAppValue(Application::APP_ID, 'shillinq_base_url', '');
				$apiKey = (string)$config->getAppValue(Application::APP_ID, 'shillinq_api_key', '');
				return new ShillinqIntegrationService(
					httpClientService: $c->get('OCP\\Http\\Client\\IClientService'),
					logger: $c->get('Psr\\Log\\LoggerInterface'),
					shillinqBaseUrl: $baseUrl,
					shillinqApiKey: $apiKey,
				);
			}
		);
	}//end register()
}//end class
