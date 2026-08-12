<?php

/**
 * Procest SaaS service registrar.
 *
 * The two SaaS services that cannot be autowired because their constructors take
 * plain strings read from app config: the tenant JWT signing secret and the
 * Shillinq invoicing endpoint + API key. Split out of Application so those
 * config-reading factories sit next to each other rather than inside the
 * bootstrap class.
 *
 * @category AppInfo
 * @package  OCA\Procest\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\AppInfo\Registrar;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\ShillinqIntegrationService;
use OCA\Procest\Service\TenantJwtService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;
use Psr\Container\ContainerInterface;

/**
 * Registers the config-driven SaaS services (tenant JWT, Shillinq invoicing).
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
		// SaaS chain (member 05): factory the TenantJwtService with the secret
		// from app config (procest.jwt_signing_secret). Generates a
		// per-instance random fallback when unset (dev-friendly; production
		// must set the secret via occ config:app:set procest jwt_signing_secret).
		$context->registerService(
			TenantJwtService::class,
			static function (ContainerInterface $c): TenantJwtService {
				$config = $c->get(IConfig::class);
				$secret = (string)$config->getAppValue(Application::APP_ID, 'jwt_signing_secret', '');
				if ($secret === '' || strlen($secret) < 16) {
					$secret = (string)$config->getSystemValue(
						'secret',
						str_pad(Application::APP_ID, 32, '_')
					);
				}

				return new TenantJwtService(signingSecret: $secret);
			}
		);

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
