<?php

/**
 * Procest external auth-broker registrar.
 *
 * Binds the DigiD and eHerkenning SAML seams to the adapter selected by the
 * `integration.<name>.mode` config tier. Split out of Application so the
 * fail-closed default — the dormant Log* adapters — is stated once, next to the
 * simulator alternative it guards.
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

use OCA\Procest\Service\Auth\DigidSamlAdapterInterface;
use OCA\Procest\Service\Auth\EHerkenningSamlAdapterInterface;
use OCA\Procest\Service\Auth\LogDigidSamlAdapter;
use OCA\Procest\Service\Auth\LogEHerkenningSamlAdapter;
use OCA\Procest\Service\Auth\SimulatorDigidSamlAdapter;
use OCA\Procest\Service\Auth\SimulatorEHerkenningSamlAdapter;
use OCA\Procest\Service\External\IntegrationMode;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the DigiD / eHerkenning SAML broker adapters.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class AuthAdapterRegistrar
{
    /**
     * Register the external auth-broker adapters (DigiD / eHerkenning).
     *
     * External auth-broker adapters (lib/Service/Auth/), selected by the
     * `integration.digid.mode` config tier (external-integrations-test-environments).
     * DEFAULT `log` = the dormant Log* implementations which throw + log
     * so a misconfigured environment surfaces "broker not configured"
     * immediately and NEVER makes an external call. `simulator` binds the
     * maykinmedia-pattern local login simulator (no real SAML — capped at
     * beta). `preprod`/`live` (certificate-bound Logius koppelvlak) are
     * documented in docs/admin/integrations.md and bound in a follow-up
     * once the aansluiting + PKIoverheid cert are granted; until then they
     * fall through to the Log adapter (fail-closed).
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
            DigidSamlAdapterInterface::class,
            static function (ContainerInterface $c): DigidSamlAdapterInterface {
                $mode = $c->get(IntegrationMode::class)
                    ->resolve('digid', [IntegrationMode::SIMULATOR]);
                if ($mode === IntegrationMode::SIMULATOR) {
                    return new SimulatorDigidSamlAdapter();
                }

                return $c->get(LogDigidSamlAdapter::class);
            }
        );
        $context->registerService(
            EHerkenningSamlAdapterInterface::class,
            static function (ContainerInterface $c): EHerkenningSamlAdapterInterface {
                $mode = $c->get(IntegrationMode::class)
                    ->resolve('digid', [IntegrationMode::SIMULATOR]);
                if ($mode === IntegrationMode::SIMULATOR) {
                    return new SimulatorEHerkenningSamlAdapter();
                }

                return $c->get(LogEHerkenningSamlAdapter::class);
            }
        );
    }//end register()
}//end class
