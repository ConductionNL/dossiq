<?php

/**
 * The two seams an integrator may substitute, and what they say when nobody has.
 *
 * Berichtenbox delivery and beschikking template rendering are the two places
 * dossiq hands work to something it does not ship. Both are per-customer
 * contracts, both belong in integriq or filinq, and dossiq must carry neither
 * (ADR-041, and the `dossiq-delivers-nothing` ruling). What dossiq owns is
 * composing the message or the besluit, and recording what happened to it.
 *
 * 🔴 THE DEFECT WAS THE SILENCE, NOT THE MISSING TRANSPORT. Neither seam could
 * be substituted at all: `BerichtenboxService::getAdapter()` built `MockAdapter`
 * inline behind the comment "For MVP, always use mock adapter", and the template
 * engine was aliased onto its mock UNCONDITIONALLY, twenty lines above a sibling
 * seam that already probed for LibreSign and warned when it fell back. So an
 * integrator could not point either at a real implementation without editing
 * dossiq, and nothing anywhere said the channel was not real. Everything around
 * the Berichtenbox mock worked: the compose dialog, the routing service, the
 * read-status job and four Awb templates. A send returned a message id. Nothing
 * left the instance.
 *
 * Both seams now resolve a class the admin names in app config, and both fall
 * back LOUDLY: a translated warning in the log, and a Simulated row on the
 * Integrations page, which is the half a reader actually sees.
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
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Service\Beschikking\MockTemplateEngineAdapter;
use OCA\Dossiq\Service\Beschikking\TemplateEngineAdapterInterface;
use OCA\Dossiq\Service\BerichtenboxAdapter\BerichtenboxAdapterInterface;
use OCA\Dossiq\Service\BerichtenboxAdapter\MockAdapter;
use OCA\Dossiq\Support\FleetAppId;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\App\IAppManager;
use OCP\IL10N;
use Psr\Container\ContainerInterface;

/**
 * Registers the Berichtenbox and template-render seams.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */
class SubstitutableAdapterRegistrar {

	/**
	 * The app-config key an integrator names their Berichtenbox adapter in.
	 */
	public const BERICHTENBOX_CONFIG_KEY = 'berichtenbox_adapter';

	/**
	 * The app-config key an integrator names their template adapter in.
	 */
	public const TEMPLATE_CONFIG_KEY = 'beschikking_template_adapter';

	/**
	 * Register both substitutable adapters.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ConfiguredAdapter and FleetAppId are
	 *      stateless resolvers, and this method IS the composition root: the one
	 *      place a service locator is the right tool rather than a smell.
	 *
	 * @spec openspec/specs/berichtenbox-integration/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			BerichtenboxAdapterInterface::class,
			static function (ContainerInterface $c): BerichtenboxAdapterInterface {
				return ConfiguredAdapter::resolve(
					container: $c,
					configKey: self::BERICHTENBOX_CONFIG_KEY,
					interface: BerichtenboxAdapterInterface::class,
					mockClass: MockAdapter::class,
					fallbackReason: $c->get(IL10N::class)->t(
						'No Berichtenbox adapter is configured, so messages are simulated. '
						. 'Nothing reaches Mijn Overheid. Name a real adapter class in the '
						. 'berichtenbox_adapter setting to send for real.'
					),
				);
			}
		);

		// Filinq is the intended renderer, so its absence is what the warning
		// names. The probe goes through FleetAppId rather than a literal app id:
		// filinq renamed from docudesk, both names are in the field, and a
		// hardcoded lookup against the wrong one returns false and takes the
		// integration dark without erroring.
		$context->registerService(
			TemplateEngineAdapterInterface::class,
			static function (ContainerInterface $c): TemplateEngineAdapterInterface {
				return ConfiguredAdapter::resolve(
					container: $c,
					configKey: self::TEMPLATE_CONFIG_KEY,
					interface: TemplateEngineAdapterInterface::class,
					mockClass: MockTemplateEngineAdapter::class,
					fallbackReason: self::templateFallbackReason(container: $c),
				);
			}
		);
	}//end register()

	/**
	 * Why the template mock is running, in the words the reader needs.
	 *
	 * Two different sentences, because they ask for two different things. An
	 * instance without filinq needs to install it; an instance with filinq needs
	 * to name its adapter. One message covering both would tell each reader half
	 * of what they have to do.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return string The translated sentence.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is a stateless resolver.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private static function templateFallbackReason(ContainerInterface $container): string {
		$l10n = $container->get(IL10N::class);
		if (FleetAppId::isEnabledForUser(appManager: $container->get(IAppManager::class), canonical: 'filinq') === true) {
			return $l10n->t(
				'Filinq is installed but no template adapter is configured, so every '
				. 'beschikking is rendered by a mock. Name filinq\'s adapter class in the '
				. 'beschikking_template_adapter setting.'
			);
		}

		return $l10n->t(
			'Filinq is not installed, so every beschikking is rendered by a mock. '
			. 'Install filinq, then name its adapter class in the '
			. 'beschikking_template_adapter setting.'
		);
	}//end templateFallbackReason()
}//end class
