<?php

/**
 * Procest informatieobject status lifecycle.
 *
 * Owns the ZGW DRC `concept -> definitief -> gearchiveerd` lifecycle for a
 * single informatieobject: which transitions are legal, what a legal
 * transition writes back (including the `vergrendeldOp` lock stamp that
 * `definitief` sets), and how a bulk run reports per-id success and failure.
 * Split out of ZaakdossierService so that service keeps the dossier as a whole
 * — upload, join, grouping, metadata — while the state machine that governs a
 * single document lives in one place.
 *
 * The transition table is forward-only and a same-state transition is refused,
 * so a document can never be silently un-locked by replaying its own status.
 *
 * @category Service
 * @package  OCA\Procest\Service\Zaakdossier
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
 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Zaakdossier;

use InvalidArgumentException;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The forward-only status state machine for a ZGW informatieobject.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
 */
class InformatieobjectStatusLifecycle {

	use SearchesObjects;

	/**
	 * Valid informatieobject statuses.
	 *
	 * @var string[]
	 */
	public const VALID_STATUSES = [
		'concept',
		'definitief',
		'gearchiveerd',
	];

	/**
	 * Allowed forward-only status transitions (from => [allowed-to, ...]).
	 *
	 * @var array<string, string[]>
	 */
	public const STATUS_TRANSITIONS = [
		'concept' => ['definitief'],
		'definitief' => ['gearchiveerd'],
		'gearchiveerd' => [],
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service (config + ObjectService).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Determine whether a status transition is permitted (forward-only).
	 *
	 * @param string $from The current status.
	 * @param string $to The requested status.
	 *
	 * @return bool True when allowed.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
	 */
	public function isTransitionAllowed(string $from, string $to): bool {
		if ($from === $to) {
			return false;
		}

		$allowed = (self::STATUS_TRANSITIONS[$from] ?? []);

		return in_array($to, $allowed, true);
	}//end isTransitionAllowed()

	/**
	 * Transition a single informatieobject to a new status.
	 *
	 * @param string $infoObjectId The informatieobject UUID.
	 * @param string $newStatus The requested status.
	 *
	 * @return array<string, mixed> The updated informatieobject summary.
	 *
	 * @throws RuntimeException When OpenRegister/config is unavailable or the document is missing.
	 * @throws InvalidArgumentException When the status is unknown or the transition is not permitted.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
	 */
	public function transition(string $infoObjectId, string $newStatus): array {
		if (in_array($newStatus, self::VALID_STATUSES, true) === false) {
			throw new InvalidArgumentException('Invalid status: ' . $newStatus);
		}

		[$objectService, $register] = $this->requireRegister();
		$infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');

		$current = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $infoSchema,
			id: $infoObjectId,
		);

		if ($current === null) {
			throw new RuntimeException('Informatieobject not found: ' . $infoObjectId);
		}

		$currentStatus = (string)($current['status'] ?? 'concept');
		if ($this->isTransitionAllowed(from: $currentStatus, to: $newStatus) === false) {
			throw new InvalidArgumentException(
				'Invalid status transition from ' . $currentStatus . ' to ' . $newStatus
			);
		}

		$updateData = ['status' => $newStatus];
		if ($newStatus === 'definitief') {
			$updateData['vergrendeldOp'] = date('Y-m-d\TH:i:s');
		}

		$objectService->saveObject(object: $updateData, register: $register, schema: $infoSchema, uuid: $infoObjectId);

		$this->logger->info(
			'Procest dossier: informatieobject ' . $infoObjectId . ' transitioned ' . $currentStatus . ' -> ' . $newStatus,
			['app' => Application::APP_ID],
		);

		// Carry `vergrendeldOp` through only when the transition set it to a
		// non-null value. Kept as an isset() test rather than
		// array_intersect_key(), which would also carry an explicitly-null
		// value through and write a null back over the stored field.
		$vergrendeldOn = [];
		if (isset($updateData['vergrendeldOp']) === true) {
			$vergrendeldOn = ['vergrendeldOp' => $updateData['vergrendeldOp']];
		}

		return array_merge(
			['id' => $infoObjectId, 'status' => $newStatus],
			$vergrendeldOn,
		);
	}//end transition()

	/**
	 * Apply a bulk status transition, returning a per-id success/failure list.
	 *
	 * @param string[] $infoObjectIds The informatieobject UUIDs.
	 * @param string $newStatus The requested status.
	 *
	 * @return array<int, array<string, mixed>> Per-id results with `id`, `success`, optional `error`.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
	 */
	public function transitionMany(array $infoObjectIds, string $newStatus): array {
		$results = [];
		foreach ($infoObjectIds as $id) {
			$id = (string)$id;
			try {
				$this->transition(infoObjectId: $id, newStatus: $newStatus);
				$results[] = ['id' => $id, 'success' => true];
			} catch (\Throwable $e) {
				$results[] = ['id' => $id, 'success' => false, 'error' => $e->getMessage()];
			}
		}

		return $results;
	}//end transitionMany()

	/**
	 * Resolve the ObjectService and register, throwing when unavailable.
	 *
	 * @return array{0: object, 1: string} The object service and register slug.
	 *
	 * @throws RuntimeException When OpenRegister or the register config is unavailable.
	 */
	private function requireRegister(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		if ($register === '') {
			throw new RuntimeException('Dossier register not configured');
		}

		return [$objectService, $register];
	}//end requireRegister()
}//end class
