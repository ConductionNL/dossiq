<?php

/**
 * Dossiq external base-register registrar.
 *
 * The composite over the wave-4 external base-register ports. It owns nothing
 * itself beyond the order in which the per-register registrars run, so adding a
 * sixth base register is a one-line change here plus one new small class.
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

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Runs every external base-register port registrar.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class ExternalRegisterRegistrar {
	/**
	 * Register the wave-4 external base-register ports plus the dormant
	 * external-ZGW / ZTC client aliases.
	 *
	 * All ports are dormant log-only by default; flip the matching
	 * `integration.<name>.mode` config tier and, where a downstream deployment
	 * needs a bespoke client, override the alias in its own
	 * Application::register() to activate.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		(new KvkRegistrar())->register(context: $context);
		(new BrpRegistrar())->register(context: $context);
		(new BagRegistrar())->register(context: $context);
		(new BrkRegistrar())->register(context: $context);
		(new WozRegistrar())->register(context: $context);
		(new ExternalZgwRegistrar())->register(context: $context);
	}//end register()
}//end class
