<?php

/**
 * Dossiq KvK Handelsregister registrar.
 *
 * One base register, one registrar: binds the KvK Handelsregister seam to the
 * live API adapter or to the dormant log-only adapter, decided by the
 * `integration.kvk.mode` config tier. Split per register so each binding's
 * default — and the fact that the default makes no external call — is auditable
 * on its own.
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

use OCA\Dossiq\Service\External\IntegrationMode;
use OCA\Dossiq\Service\External\Kvk\KvkApiAdapter;
use OCA\Dossiq\Service\External\Kvk\KvkHandelsregisterAdapterInterface;
use OCA\Dossiq\Service\External\Kvk\LogKvkHandelsregisterAdapter;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the KvK Handelsregister port.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class KvkRegistrar {
	/**
	 * Register the KvK Handelsregister adapter.
	 *
	 * Used by the leverancier-zaakportaal eHerkenning kvkNummer enrichment,
	 * bedrijfszaak intake and the brp-kvk-register-sets seed. Selected by
	 * `integration.kvk.mode` (external-integrations-test-environments).
	 * DEFAULT `log` = dormant (no external call); `test`/`live` binds the
	 * KvkApiAdapter (test tier = api.kvk.nl/test, public key).
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			KvkHandelsregisterAdapterInterface::class,
			static function (ContainerInterface $c): KvkHandelsregisterAdapterInterface {
				$modeService = $c->get(IntegrationMode::class);
				$mode = $modeService->resolve(
					'kvk',
					[
						IntegrationMode::TEST,
						IntegrationMode::LIVE,
					]
				);
				if ($mode !== IntegrationMode::LOG) {
					return new KvkApiAdapter(
						clientService: $c->get('OCP\\Http\\Client\\IClientService'),
						mode: $modeService,
						logger: $c->get('Psr\\Log\\LoggerInterface'),
					);
				}

				return $c->get(LogKvkHandelsregisterAdapter::class);
			}
		);
	}//end register()
}//end class
