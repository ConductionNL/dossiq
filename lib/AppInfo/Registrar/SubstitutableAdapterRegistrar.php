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

use OCA\Dossiq\Service\Beschikking\FilinqTemplateEngineAdapter;
use OCA\Dossiq\Service\Beschikking\MockTemplateEngineAdapter;
use OCA\Dossiq\Service\Beschikking\TemplateEngineAdapterInterface;
use OCA\Dossiq\Service\BerichtenboxAdapter\BerichtenboxAdapterInterface;
use OCA\Dossiq\Service\BerichtenboxAdapter\IntegriqAdapter;
use OCA\Dossiq\Service\BerichtenboxAdapter\MockAdapter;
use OCA\Dossiq\Support\FleetAppId;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\App\IAppManager;
use OCP\IAppConfig;
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
	 * Shorthands an administrator may write instead of a class name.
	 *
	 * `berichtenbox_adapter` has always taken a fully qualified class name, and
	 * it still does. These two exist because the values an operator actually
	 * wants to write are "send it for real" and "do not send it", and asking
	 * them to spell a PHP namespace to say either is how a setting gets typed
	 * wrong once and then read as "no adapter configured" forever.
	 *
	 * @var array<string, class-string<BerichtenboxAdapterInterface>>
	 */
	public const BERICHTENBOX_ALIASES = [
		'integriq' => IntegriqAdapter::class,
		'mock' => MockAdapter::class,
	];

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
				// 🔴 THE DEFAULT IS THE REAL ONE NOW, AND THAT IS THE CHANGE.
				// It used to be MockAdapter, whose own class comment says it
				// simulates sending without external calls, and whose
				// `sendMessage` answers `status: sent` with a generated id. So
				// an instance that had simply never set this key reported every
				// letter to a citizen as delivered, and the only trace was a
				// warning in the boot log. IntegriqAdapter refuses instead, and
				// names what is missing, which is the rule integriq's own
				// factory already follows once its Logius flag is on.
				//
				// The mock is still selectable: `berichtenbox_adapter=mock`, or
				// its class name. What it is no longer is what you get by
				// forgetting.
				self::resolveBerichtenboxAlias(container: $c);

				return ConfiguredAdapter::resolve(
					container: $c,
					configKey: self::BERICHTENBOX_CONFIG_KEY,
					interface: BerichtenboxAdapterInterface::class,
					mockClass: IntegriqAdapter::class,
					fallbackReason: $c->get(IL10N::class)->t(
						'No Berichtenbox adapter is configured, so digital post goes through '
						. 'integriq. An instance without integriq refuses each send and says so, '
						. 'rather than simulating one. Set berichtenbox_adapter to mock to go '
						. 'back to simulating.'
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
	 * Expand a Berichtenbox adapter shorthand into the class it names.
	 *
	 * Written back into app config rather than resolved on every read, so the
	 * value an administrator sees in `occ config:app:get` is the class that is
	 * actually running. A value that is not a shorthand is left exactly as it
	 * is, including a wrong one: {@see ConfiguredAdapter} is what says a named
	 * class cannot be used, and it says so in the log rather than silently.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/digital-post-reaches-integriq/specs/berichtenbox-integration/spec.md
	 */
	private static function resolveBerichtenboxAlias(ContainerInterface $container): void {
		$config = $container->get(IAppConfig::class);
		$named = trim($config->getValueString('dossiq', self::BERICHTENBOX_CONFIG_KEY, ''));

		$class = self::BERICHTENBOX_ALIASES[strtolower($named)] ?? null;
		if ($class === null) {
			return;
		}

		$config->setValueString('dossiq', self::BERICHTENBOX_CONFIG_KEY, $class);
	}//end resolveBerichtenboxAlias()

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
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private static function templateFallbackReason(ContainerInterface $container): string {
		$l10n = $container->get(IL10N::class);
		if (FleetAppId::isEnabledForUser(appManager: $container->get(IAppManager::class), canonical: 'filinq') === true) {
			return $l10n->t(
				'Filinq is installed but no template adapter is configured, so every '
				. 'beschikking is rendered by a mock. Set beschikking_template_adapter to '
				. '%s to render through filinq.',
				[FilinqTemplateEngineAdapter::class]
			);
		}

		return $l10n->t(
			'Filinq is not installed, so every beschikking is rendered by a mock. '
			. 'Install filinq, then set beschikking_template_adapter to %s.',
			[FilinqTemplateEngineAdapter::class]
		);
	}//end templateFallbackReason()
}//end class
