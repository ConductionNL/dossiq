<?php

/**
 * Dossiq routed-case acceptance listener.
 *
 * A case routed to someone is accepted by that person's first status move or
 * first edit of it (decision 164): there is no accept button. On a case save
 * by the signed-in user the case was routed to, while its routing is not yet
 * accepted and the save changed something other than the routing itself,
 * this stamps `routing.acceptedAt` and cancels the take-back window. A save by
 * anybody else, or a save that only moved the routing (the router's own
 * write), accepts nothing.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Listener\Support\SavedObjectPayload;
use OCA\Dossiq\Service\Routing\TakeBackWindow;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps the acceptance of a routed case and cancels its take-back window.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/role-based-step-routing/spec.md#requirement-work-not-taken-up-returns-to-the-pool-req-rtp-03
 */
class RoutedCaseAcceptanceListener implements IEventListener {
	use SavedObjectPayload;
	use SearchesObjects;

	/**
	 * Fields whose change is not an edit by the assignee: the routing itself,
	 * the record's own bookkeeping, and what OpenRegister stamps on every save.
	 *
	 * @var string[]
	 */
	private const NOT_AN_EDIT = [
		'@self',
		'id',
		'uuid',
		'assignee',
		'routing',
		'routingTakeBacks',
		'areaFallbackUsed',
		'updated',
		'modified',
		'version',
	];

	/**
	 * Build the listener.
	 *
	 * @param SettingsService $settingsService Names the case schema and holds the object service.
	 * @param TakeBackWindow  $window          Cancels the take-back timer.
	 * @param IUserSession    $userSession     Who saved.
	 * @param ITimeFactory    $time            Now.
	 * @param LoggerInterface $logger          Logs a failed stamp.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TakeBackWindow $window,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle one saved object.
	 *
	 * @param Event $event An ObjectUpdatedEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
	 */
	public function handle(Event $event): void {
		try {
			$saved = $this->savedObject(event: $event);
			if ($saved === null || $this->inSchema(object: $saved, configured: (string) $this->settingsService->getConfigValue('case_schema')) === false) {
				return;
			}

			$routing = $saved['routing'] ?? null;
			if (is_array($routing) === false || $this->accepts(saved: $saved, routing: $routing, before: $this->previousObject(event: $event)) === false) {
				return;
			}

			$this->accept(saved: $saved, routing: $routing);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq routing: the acceptance of a routed case could not be stamped: '.$e->getMessage());
		}
	}//end handle()

	/**
	 * Whether this save is the routed person's first status move or edit.
	 *
	 * @param array<string, mixed>      $saved   The saved case.
	 * @param array<string, mixed>      $routing Its routing record.
	 * @param array<string, mixed>|null $before  The case before the save.
	 *
	 * @return bool
	 */
	private function accepts(array $saved, array $routing, ?array $before): bool {
		$routedTo = (string) ($routing['routedTo'] ?? '');
		if ($routedTo === '' || trim((string) ($routing['acceptedAt'] ?? '')) !== '' || $before === null) {
			return false;
		}

		$user = $this->userSession->getUser();
		if ($user === null || $user->getUID() !== $routedTo || (string) ($saved['assignee'] ?? '') !== $routedTo) {
			return false;
		}

		$fields = array_diff(array_unique([...array_keys($saved), ...array_keys($before)]), self::NOT_AN_EDIT);

		return $this->unchanged(before: $before, after: $saved, fields: array_values($fields)) === false;
	}//end accepts()

	/**
	 * Stamp the acceptance and cancel the window.
	 *
	 * @param array<string, mixed> $saved   The saved case.
	 * @param array<string, mixed> $routing Its routing record.
	 *
	 * @return void
	 */
	private function accept(array $saved, array $routing): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$routing['acceptedAt'] = $this->time->getDateTime()->format(DATE_ATOM);
		$this->patchObjectAsArray(
			objectService: $objectService,
			register: (string) $this->settingsService->getConfigValue('register'),
			schema: (string) $this->settingsService->getConfigValue('case_schema'),
			id: (string) $saved['id'],
			changes: ['routing' => $routing],
		);

		$this->window->cancel(caseId: (string) $saved['id'], reason: 'Accepted by the person it was routed to');
	}//end accept()
}//end class
