<?php

/**
 * Procest BRP / Haal Centraal registrar.
 *
 * One base register, one registrar: binds the BRP personen seam to the Haal
 * Centraal adapter or to the dormant log-only adapter, decided by the
 * `integration.brp.mode` config tier. Split per register so each binding's
 * default — and the fact that the default makes no external call — is auditable
 * on its own.
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

use OCA\Procest\Service\External\Brp\BrpHaalCentraalAdapterInterface;
use OCA\Procest\Service\External\Brp\HaalCentraalBrpAdapter;
use OCA\Procest\Service\External\Brp\LogBrpHaalCentraalAdapter;
use OCA\Procest\Service\External\IntegrationMode;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the BRP / Haal Centraal personen port.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class BrpRegistrar {
	/**
	 * Register the BRP / Haal Centraal adapter.
	 *
	 * Used by citizen zaak intake (DigiD BSN → persoon envelope), briefcode
	 * resolution and the register-set seed. Selected by `integration.brp.mode`
	 * (external-integrations-test-environments). DEFAULT `log` = dormant (no
	 * external call); `mock`/`test` binds the HaalCentraalBrpAdapter (mock =
	 * ghcr.io/brp-api/personen-mock offline; test = proefomgeving once the
	 * X-API-KEY is granted).
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			BrpHaalCentraalAdapterInterface::class,
			static function (ContainerInterface $c): BrpHaalCentraalAdapterInterface {
				$modeService = $c->get(IntegrationMode::class);
				$mode = $modeService->resolve(
					'brp',
					[
						IntegrationMode::MOCK,
						IntegrationMode::TEST,
					]
				);
				if ($mode !== IntegrationMode::LOG) {
					return new HaalCentraalBrpAdapter(
						clientService: $c->get('OCP\\Http\\Client\\IClientService'),
						mode: $modeService,
						logger: $c->get('Psr\\Log\\LoggerInterface'),
					);
				}

				return $c->get(LogBrpHaalCentraalAdapter::class);
			}
		);
	}//end register()
}//end class
