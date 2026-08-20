<?php

/**
 * Procest WOZ registrar.
 *
 * One base register, one registrar: binds the WOZ (Waardering Onroerende Zaken)
 * seam to the Haal Centraal WOZ Bevragen adapter or to the dormant log-only
 * adapter, decided by the `integration.woz.mode` config tier. Split per register
 * so each binding's default — and the fact that the default makes no external
 * call — is auditable on its own.
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

use OCA\Procest\Service\External\IntegrationMode;
use OCA\Procest\Service\External\Woz\LogWozAdapter;
use OCA\Procest\Service\External\Woz\WozAdapterInterface;
use OCA\Procest\Service\External\Woz\WozApiAdapter;
use OCA\Procest\Service\External\Woz\WozResponseMapper;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the WOZ property-valuation port.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class WozRegistrar {
	/**
	 * Register the WOZ adapter.
	 *
	 * Authoritative property-valuation lookup (brk-woz-register-adapters).
	 * Selected by `integration.woz.mode` (external-integrations-test-environments
	 * config-tier model). DEFAULT `log` = dormant (no external call).
	 * `test`/`live` binds the WozApiAdapter (Kadaster Haal Centraal WOZ Bevragen
	 * API). Deliberately NOT bound to the public WOZ-waardeloket, which has no
	 * programmatic API — see
	 * openspec/changes/brk-woz-register-adapters/design.md Decision 2.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			WozAdapterInterface::class,
			static function (ContainerInterface $c): WozAdapterInterface {
				$modeService = $c->get(IntegrationMode::class);
				$mode = $modeService->resolve(
					'woz',
					[
						IntegrationMode::TEST,
						IntegrationMode::LIVE,
					]
				);
				if ($mode !== IntegrationMode::LOG) {
					return new WozApiAdapter(
						clientService: $c->get('OCP\\Http\\Client\\IClientService'),
						mode: $modeService,
						mapper: $c->get(WozResponseMapper::class),
						logger: $c->get('Psr\\Log\\LoggerInterface'),
					);
				}

				return $c->get(LogWozAdapter::class);
			}
		);
	}//end register()
}//end class
