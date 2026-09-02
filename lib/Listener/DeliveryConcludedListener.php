<?php

/**
 * Dossiq DeliveryConcluded listener.
 *
 * Consumes integriq's terminal `DeliveryConcludedEvent` (ADR-041 delivery
 * seam) and projects the outcome onto the case's publication record, so the
 * delivery status of a besluit publication is visible as case data. The
 * projection is local and idempotent, filtered to events this app raised
 * (`getSourceApp() === 'dossiq'`), and never advances anything on a
 * non-terminal outcome.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/dossiq-delivers-nothing/specs/besluitvorming-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Projects integriq's terminal delivery outcome onto the case publication
 * record.
 *
 * @psalm-suppress UnusedClass -- registered via ListenerRegistrar by FQN string.
 *
 * @spec openspec/changes/dossiq-delivers-nothing/specs/besluitvorming-delivery/spec.md
 */
class DeliveryConcludedListener implements IEventListener {
	/**
	 * Terminal statuses the seam can conclude with.
	 *
	 * @var array<int, string>
	 */
	private const TERMINAL_STATUSES = ['delivered', 'abandoned'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the ObjectService + register config.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an integriq `DeliveryConcludedEvent`.
	 *
	 * @param Event $event The dispatched event (integriq DeliveryConcludedEvent).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-delivers-nothing/specs/besluitvorming-delivery/spec.md
	 */
	public function handle(Event $event): void {
		// Defensive duck-typing: the event class is integriq's and optional at
		// runtime, so guard against any non-conforming dispatch.
		if (method_exists($event, 'getSourceApp') === false || method_exists($event, 'getCorrelationId') === false) {
			return;
		}

		try {
			if ((string)$event->getSourceApp() !== Application::APP_ID) {
				return;
			}

			$status = strtolower((string)$event->getStatus());
			if (in_array($status, self::TERMINAL_STATUSES, true) === false) {
				return;
			}

			$this->projectOntoCase(
				caseId: (string)$event->getSubjectId(),
				correlationId: (string)$event->getCorrelationId(),
				channel: (string)$event->getChannel(),
				status: $status,
				attempts: (int)$event->getAttempts(),
				error: $event->getError(),
				concludedAt: (string)$event->getConcludedAt()
			);
		} catch (\Throwable $e) {
			// A projection failure must never bubble into integriq's delivery
			// bookkeeping — its message record stays the source of truth.
			$this->logger->error(
				'Dossiq DeliveryConcludedListener failed to project delivery outcome',
				['app' => Application::APP_ID, 'error' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * Write the terminal delivery status onto the case's publication record.
	 *
	 * Matches the publication by the delivery correlation id, falling back to
	 * the channel. Idempotent: re-delivering the same terminal state is a
	 * no-op write.
	 *
	 * @param string $caseId The case id.
	 * @param string $correlationId The delivery correlation id.
	 * @param string $channel The publication channel.
	 * @param string $status Terminal status: `delivered` or `abandoned`.
	 * @param int $attempts Delivery attempts made.
	 * @param string|null $error The last delivery error, or null.
	 * @param string $concludedAt ISO 8601 terminal timestamp.
	 *
	 * @return void
	 */
	private function projectOntoCase(
		string $caseId,
		string $correlationId,
		string $channel,
		string $status,
		int $attempts,
		?string $error,
		string $concludedAt,
	): void {
		if ($caseId === '' || $correlationId === '') {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');

		$obj = $objectService->find(id: $caseId, register: $register, schema: $schema);
		if ($obj === null) {
			$this->logger->warning(
				'Dossiq DeliveryConcludedListener: case not found for concluded delivery',
				['caseId' => $caseId, 'correlationId' => $correlationId]
			);
			return;
		}

		$case = (array)$obj;
		if (is_array($obj) === false && method_exists($obj, 'jsonSerialize') === true) {
			$case = $obj->jsonSerialize();
		}

		$publications = $case['publications'] ?? [];
		if (is_array($publications) === false) {
			return;
		}

		$updated = false;
		foreach ($publications as $i => $pub) {
			if (is_array($pub) === false) {
				continue;
			}

			$delivery = (array)($pub['delivery'] ?? []);
			$matchesCorrelation = ((string)($delivery['correlationId'] ?? '') === $correlationId);
			$matchesChannel = ($correlationId === '' && (string)($pub['channel'] ?? '') === $channel);
			if ($matchesCorrelation === false && $matchesChannel === false) {
				continue;
			}

			if ((string)($delivery['status'] ?? '') === $status) {
				// Idempotent: this terminal state is already projected.
				return;
			}

			$delivery['status'] = $status;
			$delivery['attempts'] = $attempts;
			$delivery['error'] = $error;
			$delivery['concludedAt'] = $concludedAt;
			$publications[$i]['delivery'] = $delivery;
			$updated = true;
			break;
		}//end foreach

		if ($updated === false) {
			$this->logger->warning(
				'Dossiq DeliveryConcludedListener: no publication matches the concluded delivery',
				['caseId' => $caseId, 'correlationId' => $correlationId, 'channel' => $channel]
			);
			return;
		}

		$case['publications'] = $publications;
		$objectService->saveObject(
			object: $case,
			register: $register,
			schema: $schema,
		);
	}//end projectOntoCase()
}//end class
