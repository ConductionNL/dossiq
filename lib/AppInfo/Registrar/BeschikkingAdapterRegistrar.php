<?php

/**
 * Dossiq beschikking adapter registrar.
 *
 * Binds the three beschikking cross-app integration seams — template render,
 * digital signing and archival ingest — to the implementation that is actually
 * available on this instance. Split out of Application so the LibreSign
 * availability probe and its fallback live with the classes they choose between.
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

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Beschikking\ArchivalAdapterInterface;
use OCA\Dossiq\Service\Beschikking\LibresignApiClient;
use OCA\Dossiq\Service\Beschikking\LibresignSigningAdapter;
use OCA\Dossiq\Service\Beschikking\MockSigningAdapter;
use OCA\Dossiq\Service\Beschikking\MockTemplateEngineAdapter;
use OCA\Dossiq\Service\Beschikking\OpenRegisterArchivalAdapter;
use OCA\Dossiq\Service\Beschikking\SigningAdapterInterface;
use OCA\Dossiq\Service\Beschikking\TemplateEngineAdapterInterface;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the beschikking template / signing / archival adapters.
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
		// Template render resolves to a mock implementation until the real
		// Docudesk endpoint lands in its own repo (tasks T23-T26).
		$context->registerServiceAlias(TemplateEngineAdapterInterface::class, MockTemplateEngineAdapter::class);
		// SigningAdapterInterface: LibreSign (LibreCode) when the app is
		// installed+enabled, else the pre-existing MockSigningAdapter stub —
		// see openspec/changes/libresign-besluit-signing/design.md §6.
		// dossiq never hard-depends on LibreSign: its absence is a clean,
		// logged, translated fallback to the unchanged pre-existing
		// behaviour, not an error.
		$context->registerService(
			SigningAdapterInterface::class,
			static function (ContainerInterface $c): SigningAdapterInterface {
				$appManager = $c->get('OCP\\App\\IAppManager');
				if ($appManager->isEnabledForUser('libresign') === true) {
					return new LibresignSigningAdapter(
						apiClient: new LibresignApiClient(
							clientService: $c->get('OCP\\Http\\Client\\IClientService'),
							urlGenerator: $c->get('OCP\\IURLGenerator'),
							appConfig: $c->get('OCP\\IAppConfig'),
							logger: $c->get('Psr\\Log\\LoggerInterface'),
						),
						appManager: $appManager,
						appConfig: $c->get('OCP\\IAppConfig'),
						userManager: $c->get('OCP\\IUserManager'),
						rootFolder: $c->get('OCP\\Files\\IRootFolder'),
						documentService: $c->get(ZgwDocumentService::class),
						logger: $c->get('Psr\\Log\\LoggerInterface'),
					);
				}

				$c->get('Psr\\Log\\LoggerInterface')->warning(
					$c->get('OCP\\IL10N')->t(
						'LibreSign is not installed or enabled. Digital signing falls back to '
						. 'the built-in stub adapter — install and enable the LibreSign app to '
						. 'sign beschikkingen with a real eIDAS-aligned signature.'
					),
					['app' => Application::APP_ID]
				);

				return $c->get(MockSigningAdapter::class);
			}
		);
		// Beschikking archival is repointed onto OpenRegister's declarative
		// archival pipeline (ADR-022 / migrate-archival-to-or): retention/
		// destruction are governed by x-openregister-archival on the case
		// schema; this adapter records the archival marker + Archiefwet
		// vernietigingsdatum. The former app-local MockArchivalAdapter is retired.
		$context->registerServiceAlias(ArchivalAdapterInterface::class, OpenRegisterArchivalAdapter::class);
	}//end register()
}//end class
