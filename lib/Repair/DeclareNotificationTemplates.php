<?php

/**
 * Dossiq Declare Notification Templates Repair Step.
 *
 * Fills the platform's Dutch template gaps, and only the gaps.
 *
 * WHY A GAP AND NOT AN OVERWRITE. OpenRegister's template store is app config
 * on `openregister`, so one text serves every app on the instance. Writing case
 * wording over a template another app is already using would relabel that app's
 * notices, and nobody would see it happen. The step therefore asks
 * `NotificationTemplateRegistry::gaps()` which events have no text at all, and
 * writes only those. An administrator's own edit is a template, so it is never
 * a gap, so it is never touched.
 *
 * IT IS IDEMPOTENT BY CONSTRUCTION. A filled gap is no longer a gap, so the
 * second run writes nothing. Nothing is counted as written that was not.
 *
 * IT NEVER THROWS. It is registered under `<install>` as well as
 * `<post-migration>`, and a step that throws during install aborts the install
 * and takes every route in the app with it. An instance whose OpenRegister
 * predates the template registry has no such service; that is a line in the
 * output, not a failed upgrade.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-dossiq-fills-the-platforms-dutch-template-gaps-req-urs-07
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Notification\PlatformEventTemplates;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes Dutch text for platform events that ship without any.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-dossiq-fills-the-platforms-dutch-template-gaps-req-urs-07
 */
class DeclareNotificationTemplates implements IRepairStep {

	/**
	 * OpenRegister's template registry, named as a string so this app never
	 * imports a class an older OpenRegister does not ship.
	 *
	 * @var string
	 */
	public const TEMPLATE_REGISTRY = 'OCA\\OpenRegister\\Service\\Notification\\NotificationTemplateRegistry';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container, for the lazy resolve.
	 * @param LoggerInterface    $logger    Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The name shown while the step runs.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-dossiq-fills-the-platforms-dutch-template-gaps-req-urs-07
	 */
	public function getName(): string {
		return 'Fill the Dutch notification template gaps dossiq can answer';
	}//end getName()

	/**
	 * Fill every gap dossiq has Dutch text for.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-dossiq-fills-the-platforms-dutch-template-gaps-req-urs-07
	 */
	public function run(IOutput $output): void {
		$registry = $this->registry();
		if ($registry === null) {
			$output->info('OpenRegister carries no notification templates on this instance, so none were filled.');
			return;
		}

		$gaps = $this->gaps(registry: $registry);
		if ($gaps === []) {
			$output->info('Every platform event already has text, so dossiq wrote none.');
			return;
		}

		$filled = $this->fill(registry: $registry, gaps: $gaps);

		$output->info(
			sprintf(
				'Filled %d of %d notification template gap(s) with Dutch text.',
				count($filled),
				count($gaps)
			)
		);
	}//end run()

	/**
	 * The events the platform reports as having no text.
	 *
	 * @param object $registry OpenRegister's template registry.
	 *
	 * @return array<int, string> The event names.
	 */
	private function gaps(object $registry): array {
		if (method_exists($registry, 'gaps') === false) {
			return [];
		}

		try {
			$gaps = $registry->gaps();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq notifications: the template gap list was not read, {reason}',
				['app' => Application::APP_ID, 'reason' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($gaps) === false) {
			return [];
		}

		$names = [];
		foreach ($gaps as $gap) {
			if (is_string($gap) === true && $gap !== '') {
				$names[] = $gap;
			}
		}

		return $names;
	}//end gaps()

	/**
	 * Write dossiq's Dutch text for each gap it can answer.
	 *
	 * @param object             $registry OpenRegister's template registry.
	 * @param array<int, string> $gaps     The events with no text.
	 *
	 * @return array<int, string> The events actually written.
	 */
	private function fill(object $registry, array $gaps): array {
		if (method_exists($registry, 'edit') === false) {
			return [];
		}

		$written = [];
		foreach ($gaps as $event) {
			$dutch = (PlatformEventTemplates::DUTCH[$event] ?? null);
			if ($dutch === null) {
				// Nothing dossiq can say about this event. Leaving it in the
				// gap list is the point: it stays finishable by somebody else.
				continue;
			}

			try {
				$registry->edit(event: $event, template: ['nl' => $dutch]);
				$written[] = $event;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq notifications: template {event} was not written, {reason}',
					[
						'app' => Application::APP_ID,
						'event' => $event,
						'reason' => $e->getMessage(),
					]
				);
			}
		}

		return $written;
	}//end fill()

	/**
	 * Resolve OpenRegister's template registry.
	 *
	 * @return object|null The registry, or null when this instance has none.
	 */
	private function registry(): ?object {
		try {
			$registry = $this->container->get(self::TEMPLATE_REGISTRY);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($registry) === false) {
			return null;
		}

		return $registry;
	}//end registry()
}//end class
