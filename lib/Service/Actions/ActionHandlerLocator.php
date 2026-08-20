<?php

/**
 * Procest ActionHandlerLocator.
 *
 * Owns the automatic-action handler table: the list of handler implementations,
 * their lazy resolution out of the DI container, and the `type` slug index used
 * to dispatch. Split out of ActionRegistry, which is otherwise a read-only
 * lookup over OpenRegister objects and has no business also knowing the
 * concrete handler classes — carrying that list is what pushed ActionRegistry
 * over the CouplingBetweenObjects threshold.
 *
 * Resolution stays lazy and failure-tolerant: a handler that cannot be built is
 * logged at error level and skipped, so one broken handler never takes the whole
 * dispatch table down.
 *
 * @category Service
 * @package  OCA\Procest\Service\Actions
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
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Actions;

use OCA\Procest\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves automatic-action handlers by their `type` slug.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class ActionHandlerLocator {

	/**
	 * In-memory handler index keyed by handler `type` slug.
	 *
	 * Populated lazily from the DI container the first time a handler is
	 * requested, so the container stays lean until a transition actually
	 * dispatches a side effect.
	 *
	 * @var array<string, ActionHandlerInterface>|null
	 */
	private ?array $handlerIndex = null;

	/**
	 * Constructor for ActionHandlerLocator.
	 *
	 * @param ContainerInterface $container DI container — used to lazily resolve the handler implementations.
	 * @param LoggerInterface $logger PSR-3 logger for handler-resolution failures.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Lookup a registered handler by its `type` slug.
	 *
	 * @param string $type Handler `type` slug (matches ActionHandlerInterface::type()).
	 *
	 * @return ActionHandlerInterface|null Null when no handler is registered for the slug.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function get(string $type): ?ActionHandlerInterface {
		if ($this->handlerIndex === null) {
			$this->handlerIndex = $this->buildIndex();
		}

		return ($this->handlerIndex[$type] ?? null);
	}//end get()

	/**
	 * Resolve every known handler class and index it by its `type` slug.
	 *
	 * Each handler class is registered as a regular DI service and referenced by
	 * FQCN; they are resolved lazily so the container can stay lean.
	 *
	 * @return array<string, ActionHandlerInterface> The handler index, keyed by type slug.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	private function buildIndex(): array {
		$index = [];
		$candidates = [
			SendEmailHandler::class,
			CreateDocumentHandler::class,
			NotifyRoleHandler::class,
			CallWebhookHandler::class,
			MergeTemplateHandler::class,
			ScheduleReminderHandler::class,
		];

		foreach ($candidates as $fqcn) {
			$handler = $this->resolve(fqcn: $fqcn);
			if ($handler !== null) {
				$index[$handler->type()] = $handler;
			}
		}

		return $index;
	}//end buildIndex()

	/**
	 * Resolve one handler out of the container, tolerating a broken handler.
	 *
	 * @param string $fqcn Fully-qualified handler class name.
	 *
	 * @return ActionHandlerInterface|null The handler, or null when it cannot be built or is not a handler.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	private function resolve(string $fqcn): ?ActionHandlerInterface {
		try {
			$handler = $this->container->get($fqcn);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ActionRegistry: failed to resolve handler',
				[
					'app' => Application::APP_ID,
					'fqcn' => $fqcn,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}

		if ($handler instanceof ActionHandlerInterface) {
			return $handler;
		}

		return null;
	}//end resolve()
}//end class
