<?php

/**
 * Dossiq lifecycle registrar.
 *
 * Owns what this app hands to OpenRegister's lifecycle engine. Split out of
 * Application for the reason every registrar here is: the class references a
 * subsystem needs belong next to that subsystem, not on the bootstrap class.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Lifecycle\CaseActionProvider;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Binds the lifecycle services OpenRegister resolves by name.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class LifecycleRegistrar {
	/**
	 * Register the case lifecycle action provider.
	 *
	 * The `case` schema's `x-openregister-lifecycle.provider` names this FQCN,
	 * and OpenRegister's `LifecycleActionProviderRegistry` resolves it through
	 * the server container, which does reach this app's container. Autowiring
	 * alone would therefore probably work. It is stated anyway, for the same
	 * reason the `ObjectServiceInterface` alias in Application is: that
	 * registry is fail-closed, so a resolution that quietly failed would make
	 * the whole available-actions call answer 502, and a user would read that
	 * as a case whose timeline is dead rather than as a missing binding.
	 *
	 * The two guards this app already hands to the same engine
	 * ({@see \OCA\Dossiq\Lifecycle\BezwaarDeadlineGuard} and
	 * {@see \OCA\Dossiq\Lifecycle\HoorzittingAfzienGuard}) are named the same
	 * way, from a transition's `requires`, and are left to autowiring. Neither
	 * takes a constructor dependency, so there is nothing to state about them.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			CaseActionProvider::class,
			static fn (ContainerInterface $container): CaseActionProvider => new CaseActionProvider(
				transitionEngine: $container->get(StatusTransitionService::class),
				resultWriter: $container->get(CaseResultWriter::class),
				logger: $container->get(LoggerInterface::class),
			)
		);
	}//end register()
}//end class
