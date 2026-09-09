<?php

/**
 * Binding an integration seam to a real adapter, or saying that it is not one.
 *
 * Two seams in dossiq render or send through an adapter that dossiq itself
 * cannot ship: the Berichtenbox transport and the beschikking template engine.
 * Both are per-customer contracts, and both belong in integriq or filinq rather
 * than here (ADR-041, and the `dossiq-delivers-nothing` ruling).
 *
 * 🔴 THE DEFECT THIS EXISTS TO FIX IS NOT THE MISSING TRANSPORT. It is that
 * the mock said nothing. `BerichtenboxService::getAdapter()` constructed
 * `MockAdapter` inline with the comment "For MVP, always use mock adapter":
 * no DI registration and no config switch, so an integrator could not
 * substitute a real adapter without editing dossiq, and everything around the
 * mock was real. A compose dialog, a routing service, a read-status job and
 * four Awb templates all worked, a send returned a message id, and nothing
 * reached Mijn Overheid. That reads as a working channel and is not one.
 *
 * So the seam does two things now. It resolves whatever class the admin named,
 * which is the substitution point. And when nothing is named it falls back to
 * the mock LOUDLY: a translated warning in the log, and a Simulated row on the
 * Integrations page, which is the half a reader actually sees.
 *
 * The shape is the `SigningAdapterInterface` one that already shipped in
 * {@see BeschikkingAdapterRegistrar}: probe, use the real thing when it is
 * there, fall back with a translated warning when it is not.
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
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves an adapter named in app config, or the mock, saying which.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
final class ConfiguredAdapter {

	/**
	 * The adapter this instance actually has for one seam.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param string $configKey App-config key naming the adapter class.
	 * @param string $interface The seam's interface, which a named class must implement.
	 * @param string $mockClass The built-in mock, used when nothing else answers.
	 * @param string $fallbackReason Translated sentence saying why the mock is running.
	 *
	 * @return object The adapter. Never null: a seam with no adapter would fail
	 *                at the call site, where the caller can do nothing about it.
	 *
	 * @template T of object
	 *
	 * @psalm-param class-string<T> $interface
	 * @psalm-param class-string<T> $mockClass
	 * @psalm-return T
	 *
	 * @phpstan-param class-string<T> $interface
	 * @phpstan-param class-string<T> $mockClass
	 * @phpstan-return T
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public static function resolve(
		ContainerInterface $container,
		string $configKey,
		string $interface,
		string $mockClass,
		string $fallbackReason,
	): object {
		$logger = $container->get(LoggerInterface::class);
		$named = trim($container->get(IAppConfig::class)->getValueString(Application::APP_ID, $configKey, ''));

		if ($named === '') {
			$logger->warning($fallbackReason, ['app' => Application::APP_ID, 'configKey' => $configKey]);

			return $container->get($mockClass);
		}

		// A NAMED CLASS THAT DOES NOT ANSWER IS AN ERROR, NOT A FALLBACK. The
		// admin asked for a real adapter; quietly running the mock instead
		// would put the page back to claiming a channel it does not have. The
		// mock still runs, because refusing to boot the app over one config
		// value helps nobody, but the log says plainly what happened.
		if (class_exists($named) === false || is_a($named, $interface, true) === false) {
			$logger->error(
				$container->get(IL10N::class)->t(
					'The adapter class named in %1$s cannot be used: %2$s is missing or does not '
					. 'implement the seam. A mock adapter is running in its place.',
					[$configKey, $named]
				),
				['app' => Application::APP_ID]
			);

			return $container->get($mockClass);
		}

		try {
			return $container->get($named);
		} catch (Throwable $e) {
			$logger->error(
				$container->get(IL10N::class)->t(
					'The adapter class named in %1$s could not be built. A mock adapter is running '
					. 'in its place.',
					[$configKey]
				),
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);

			return $container->get($mockClass);
		}
	}//end resolve()
}//end class
