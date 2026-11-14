<?php

/**
 * Dossiq beschikking adapter registrar.
 *
 * Binds the beschikking cross-app integration seams that dossiq itself can
 * satisfy, digital signing and archival ingest, to the implementation this
 * instance actually has. Split out of Application so the LibreSign
 * availability probe and its fallback live with the classes they choose
 * between.
 *
 * The THIRD seam, template render, moved to {@see SubstitutableAdapterRegistrar}.
 * It is not a seam dossiq can satisfy: the renderer is filinq's, so the binding
 * is a config-named class rather than a class this repo ships, and it belongs
 * with the Berichtenbox seam that has the same shape.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\Service\Registrar
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

namespace OCA\Dossiq\Service\Registrar;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\Beschikking\ArchivalAdapterInterface;
use OCA\Dossiq\Service\Beschikking\LibresignApiClient;
use OCA\Dossiq\Service\Beschikking\LibresignSigningAdapter;
use OCA\Dossiq\Service\Beschikking\MockSigningAdapter;
use OCA\Dossiq\Service\Beschikking\OpenRegisterArchivalAdapter;
use OCA\Dossiq\Service\Beschikking\SigningAdapterInterface;
use OCA\Dossiq\Service\Zaakdossier\RestrictedDocumentReaders;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the beschikking signing and archival adapters.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class BeschikkingAdapterRegistrar {

	/**
	 * Register the beschikking cross-app integration adapters.
	 *
	 * Background jobs are declared in appinfo/info.xml under
	 * <background-jobs>; Nextcloud auto-registers them with the IJobList.
	 * IRegistrationContext has no registerJob() method.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// SigningAdapterInterface: LibreSign (LibreCode) when the app is
		// installed+enabled, else the pre-existing MockSigningAdapter stub —
		// see openspec/changes/libresign-besluit-signing/design.md §6.
		// dossiq never hard-depends on LibreSign: its absence is a clean,
		// logged, translated fallback to the unchanged pre-existing
		// behaviour, not an error.
		$context->registerService(
			SigningAdapterInterface::class,
			static function (ContainerInterface $c): SigningAdapterInterface {
				return self::signingAdapter(container: $c);
			}
		);
		// Beschikking archival is repointed onto OpenRegister's declarative
		// archival pipeline (ADR-022 / migrate-archival-to-or): retention/
		// destruction are governed by x-openregister-archival on the case
		// schema; this adapter records the archival marker + Archiefwet
		// vernietigingsdatum. The former app-local MockArchivalAdapter is retired.
		$context->registerServiceAlias(ArchivalAdapterInterface::class, OpenRegisterArchivalAdapter::class);
	}//end register()

	/**
	 * The LibreSign signing adapter when LibreSign is enabled, else the stub (logged).
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return SigningAdapterInterface The adapter.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private static function signingAdapter(ContainerInterface $container): SigningAdapterInterface {
		$appManager = $container->get('OCP\\App\\IAppManager');
		if ($appManager->isEnabledForUser('libresign') === true) {
			return self::libresignAdapter(container: $container, appManager: $appManager);
		}

		return self::stubAdapter(container: $container);
	}//end signingAdapter()

	/**
	 * The LibreSign signing adapter.
	 *
	 * @param ContainerInterface    $container  The DI container.
	 * @param \OCP\App\IAppManager $appManager The app manager.
	 *
	 * @return SigningAdapterInterface The adapter.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private static function libresignAdapter(ContainerInterface $container, $appManager): SigningAdapterInterface {
		return new LibresignSigningAdapter(
			apiClient: self::libresignApiClient(container: $container),
			appManager: $appManager,
			appConfig: $container->get('OCP\\IAppConfig'),
			userManager: $container->get('OCP\\IUserManager'),
			rootFolder: $container->get('OCP\\Files\\IRootFolder'),
			documentService: $container->get(ZgwDocumentService::class),
			logger: $container->get('Psr\\Log\\LoggerInterface'),
			signedVersionOf: static fn (string $originalId, int $signedFileId, string $signatory): bool => $container
				->get(RestrictedDocumentReaders::class)
				->inheritFromId(originalId: $originalId, fileId: $signedFileId, madeBy: $signatory),
		);
	}//end libresignAdapter()

	/**
	 * The LibreSign API client.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return LibresignApiClient The client.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private static function libresignApiClient(ContainerInterface $container): LibresignApiClient {
		return new LibresignApiClient(
			clientService: $container->get('OCP\\Http\\Client\\IClientService'),
			urlGenerator: $container->get('OCP\\IURLGenerator'),
			appConfig: $container->get('OCP\\IAppConfig'),
			logger: $container->get('Psr\\Log\\LoggerInterface'),
		);
	}//end libresignApiClient()

	/**
	 * The built-in stub signing adapter, with a warning that LibreSign is not there.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return SigningAdapterInterface The stub adapter.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private static function stubAdapter(ContainerInterface $container): SigningAdapterInterface {
		$container->get('Psr\\Log\\LoggerInterface')->warning(
			$container->get('OCP\\IL10N')->t(
				'LibreSign is not installed or enabled. Digital signing falls back to '
				. 'the built-in stub adapter — install and enable the LibreSign app to '
				. 'sign beschikkingen with a real eIDAS-aligned signature.'
			),
			['app' => AppIdentity::APP_ID]
		);

		return $container->get(MockSigningAdapter::class);
	}//end stubAdapter()
}//end class
