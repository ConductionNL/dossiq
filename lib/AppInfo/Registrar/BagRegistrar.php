<?php

/**
 * Procest BAG registrar.
 *
 * One base register, one registrar: binds the BAG (Basisregistratie Adressen en
 * Gebouwen) seam to the Kadaster API adapter or to the dormant log-only adapter,
 * decided by the `integration.bag.mode` config tier. Split per register so each
 * binding's default — and the fact that the default makes no external call — is
 * auditable on its own.
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

use OCA\Procest\Service\External\Bag\BagAdapterInterface;
use OCA\Procest\Service\External\Bag\BagApiAdapter;
use OCA\Procest\Service\External\Bag\BagResponseMapper;
use OCA\Procest\Service\External\Bag\LogBagAdapter;
use OCA\Procest\Service\External\IntegrationMode;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the BAG address / pand / verblijfsobject port.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class BagRegistrar {
	/**
	 * Register the BAG adapter.
	 *
	 * Authoritative address + pand/verblijfsobject lookup (bag-register-adapter).
	 * Selected by `integration.bag.mode` (external-integrations-test-environments
	 * config-tier model). DEFAULT `log` = dormant (no external call).
	 * `test`/`live` binds the BagApiAdapter (Kadaster BAG API Individuele
	 * Bevragingen v2). Deliberately distinct from PdokBagService's free/open BAG
	 * WFS mirror — see openspec/changes/bag-register-adapter/design.md.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			BagAdapterInterface::class,
			static function (ContainerInterface $c): BagAdapterInterface {
				$modeService = $c->get(IntegrationMode::class);
				$mode = $modeService->resolve(
					'bag',
					[
						IntegrationMode::TEST,
						IntegrationMode::LIVE,
					]
				);
				if ($mode !== IntegrationMode::LOG) {
					return new BagApiAdapter(
						clientService: $c->get('OCP\\Http\\Client\\IClientService'),
						mode: $modeService,
						mapper: $c->get(BagResponseMapper::class),
						logger: $c->get('Psr\\Log\\LoggerInterface'),
					);
				}

				return $c->get(LogBagAdapter::class);
			}
		);
	}//end register()
}//end class
