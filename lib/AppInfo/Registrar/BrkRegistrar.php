<?php

/**
 * Procest BRK registrar.
 *
 * One base register, one registrar: binds the BRK (Basisregistratie Kadaster)
 * seam to the Haal Centraal BRK Bevragen adapter or to the dormant log-only
 * adapter, decided by the `integration.brk.mode` config tier. Split per register
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

use OCA\Procest\Service\External\Brk\BrkAdapterInterface;
use OCA\Procest\Service\External\Brk\BrkApiAdapter;
use OCA\Procest\Service\External\Brk\BrkResponseMapper;
use OCA\Procest\Service\External\Brk\LogBrkAdapter;
use OCA\Procest\Service\External\IntegrationMode;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the BRK parcel / ownership-reference port.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class BrkRegistrar
{
    /**
     * Register the BRK adapter.
     *
     * Authoritative parcel/ownership-reference lookup (brk-woz-register-adapters).
     * Selected by `integration.brk.mode` (external-integrations-test-environments
     * config-tier model). DEFAULT `log` = dormant (no external call).
     * `test`/`live` binds the BrkApiAdapter (Kadaster Haal Centraal BRK Bevragen
     * API v2) — see openspec/changes/brk-woz-register-adapters/design.md.
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     *
     * @spec openspec/specs/beschikking-generatie/spec.md
     */
    public function register(IRegistrationContext $context): void
    {
        $context->registerService(
            BrkAdapterInterface::class,
            static function (ContainerInterface $c): BrkAdapterInterface {
                $modeService = $c->get(IntegrationMode::class);
                $mode        = $modeService->resolve(
                    'brk',
                    [
                        IntegrationMode::TEST,
                        IntegrationMode::LIVE,
                    ]
                );
                if ($mode !== IntegrationMode::LOG) {
                    return new BrkApiAdapter(
                        clientService: $c->get('OCP\\Http\\Client\\IClientService'),
                        mode: $modeService,
                        mapper: $c->get(BrkResponseMapper::class),
                        logger: $c->get('Psr\\Log\\LoggerInterface'),
                    );
                }

                return $c->get(LogBrkAdapter::class);
            }
        );
    }//end register()
}//end class
