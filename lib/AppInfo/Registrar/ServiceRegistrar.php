<?php

/**
 * Procest service registrar.
 *
 * The composite over everything Application::register() binds into the
 * container: the AppHost engine, the bespoke Settings plumbing, the SaaS
 * middleware chain and its config-driven services, the beschikking adapters, the
 * auth brokers and the external base registers. It owns no registration itself —
 * only the order in which the specialised registrars run.
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

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Runs every container-binding registrar in dependency order.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class ServiceRegistrar
{
    /**
     * Register every procest service binding.
     *
     * Order matters at exactly one point: the AppHost engine aliases procest's
     * Settings plumbing to its generics, so BespokeServiceRegistrar must run
     * after it to override those keys back onto the concrete procest classes.
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     *
     * @spec openspec/specs/beschikking-generatie/spec.md
     */
    public function register(IRegistrationContext $context): void
    {
        (new AppHostRegistrar())->register(context: $context);
        (new BespokeServiceRegistrar())->register(context: $context);

        (new MiddlewareRegistrar())->register(context: $context);
        (new SaasServiceRegistrar())->register(context: $context);

        (new BeschikkingAdapterRegistrar())->register(context: $context);
        (new AuthAdapterRegistrar())->register(context: $context);
        (new ExternalRegisterRegistrar())->register(context: $context);
    }//end register()
}//end class
