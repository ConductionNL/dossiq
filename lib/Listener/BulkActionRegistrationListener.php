<?php

/**
 * Dossiq: declare the five case bulk actions to OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\BulkAction\LifecycleCasesAction;
use OCA\Dossiq\BulkAction\MoveCaseTypeVersionAction;
use OCA\Dossiq\BulkAction\ReassignCasesAction;
use OCA\Dossiq\BulkAction\SetCaseAttributeAction;
use OCA\Dossiq\BulkAction\TransitionCasesAction;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registers dossiq's bulk actions when OpenRegister collects them.
 *
 * Every action is resolved lazily from the container, one at a time, and a
 * single action that cannot be built is logged and left out rather than
 * taking the others with it: a registry that answers nothing looks
 * exactly like an instance where dossiq is not installed.
 *
 * @template-implements IEventListener<Event>
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
class BulkActionRegistrationListener implements IEventListener {

	/**
	 * The five actions, in the order an operator meets them.
	 *
	 * @var array<int, class-string>
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	private const ACTIONS = [
		TransitionCasesAction::class,
		LifecycleCasesAction::class,
		ReassignCasesAction::class,
		SetCaseAttributeAction::class,
		MoveCaseTypeVersionAction::class,
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The app container.
	 * @param LoggerInterface    $logger    The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Register dossiq's five case actions.
	 *
	 * @param Event $event The registration event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function handle(Event $event): void {
		if (method_exists($event, 'registerAction') === false) {
			return;
		}

		foreach (self::ACTIONS as $class) {
			try {
				$event->registerAction($this->container->get($class));
			} catch (Throwable $e) {
				$this->logger->error(
					'Dossiq: a bulk action could not be registered',
					['action' => $class, 'exception' => $e->getMessage()],
				);
			}
		}
	}//end handle()
}//end class
