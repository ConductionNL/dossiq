<?php

/**
 * Dossiq informatieobject status lifecycle.
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
 * @package  OCA\Dossiq\Service\Zaakdossier
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
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use InvalidArgumentException;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\Custody\DossierRegister;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The forward-only status state machine for a ZGW informatieobject.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
 */
class InformatieobjectStatusLifecycle {

	use SearchesObjects;

	/**
	 * Valid informatieobject statuses.
	 *
	 * @var string[]
	 */
	public const VALID_STATUSES = [
		'draft',
		'final',
		'archived',
	];

	/**
	 * Allowed forward-only status transitions (from => [allowed-to, ...]).
	 *
	 * @var array<string, string[]>
	 */
	public const STATUS_TRANSITIONS = [
		'draft' => ['final'],
		'final' => ['archived'],
		'archived' => [],
	];

	/**
	 * The register, read and refused the one shared way.
	 *
	 * @var DossierRegister
	 */
	private readonly DossierRegister $dossierRegister;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service (config + ObjectService).
	 * @param LoggerInterface $logger Logger.
	 * @param DocumentApprovalClearance|null $approvals decidiq's answer about
	 *        this document's approval routes. Nullable and LAST so every
	 *        existing construction site keeps working; a null reader means no
	 *        route is known, which is where every document was before this
	 *        change, and is the same answer an instance without decidiq gives.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?DocumentApprovalClearance $approvals = null,
	) {
		$this->dossierRegister = new DossierRegister(settingsService: $settingsService);
	}//end __construct()

	/**
	 * Determine whether a status transition is permitted (forward-only).
	 *
	 * @param string $from The current status.
	 * @param string $to The requested status.
	 *
	 * @return bool True when allowed.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
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
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	public function transition(string $infoObjectId, string $newStatus): array {
		if (in_array($newStatus, self::VALID_STATUSES, true) === false) {
			throw new InvalidArgumentException('Invalid status: ' . $newStatus);
		}

		[$objectService, $register] = $this->requireRegister();
		$document = new StoredDocument(
			objectService: $objectService,
			register: $register,
			schema: $this->settingsService->getConfigValue('dossier_informatieobject_schema'),
			id: $infoObjectId,
		);

		$move = new DocumentStatusMove(from: $this->storedStatus(document: $document), to: $newStatus);
		if ($this->isTransitionAllowed(from: $move->from, to: $move->to) === false) {
			throw new InvalidArgumentException(
				'Invalid status transition from ' . $move->from . ' to ' . $move->to
			);
		}

		$this->requireApprovalCleared(infoObjectId: $infoObjectId, newStatus: $newStatus);

		return $this->applyStatus(document: $document, move: $move);
	}//end transition()

	/**
	 * The status a stored document carries, draft when it carries none.
	 *
	 * @param StoredDocument $document The document.
	 *
	 * @return string The status.
	 *
	 * @throws RuntimeException When the document does not exist.
	 */
	private function storedStatus(StoredDocument $document): string {
		$current = $this->findObjectAsArray(
			objectService: $document->objectService,
			register: $document->register,
			schema: $document->schema,
			id: $document->id,
		);

		if ($current === null) {
			throw new RuntimeException('Informatieobject not found: ' . $document->id);
		}

		return (string)($current['status'] ?? 'draft');
	}//end storedStatus()

	/**
	 * Write the new status, stamping the lock on a move to final, and log it.
	 *
	 * THE TRANSITION'S FIELDS, APPLIED TO THE STORED DOCUMENT. This used to
	 * hand the changes straight to `saveObject()` with the uuid, but that save
	 * is PUT-semantic: the payload IS the new object. Every property it left
	 * out was dropped, and because four of them are required OpenRegister
	 * refused the write, so no document status change ever completed and the
	 * bulk run failed every document it was given. patchObjectAsArray() is
	 * the app's partial-write seam: `patchObject()` where OpenRegister has it,
	 * a fresh read-then-save where it does not.
	 *
	 * @param StoredDocument     $document The document.
	 * @param DocumentStatusMove $move     Its status before and after.
	 *
	 * @return array<string, mixed> The updated informatieobject summary.
	 */
	private function applyStatus(StoredDocument $document, DocumentStatusMove $move): array {
		$updateData = self::statusChanges(newStatus: $move->to);
		$this->patchObjectAsArray(
			objectService: $document->objectService,
			register: $document->register,
			schema: $document->schema,
			id: $document->id,
			changes: $updateData,
		);

		$this->logger->info(
			'Dossiq dossier: informatieobject ' . $document->id . ' transitioned ' . $move->from . ' -> ' . $move->to,
			['app' => AppIdentity::APP_ID],
		);

		return self::summary(infoObjectId: $document->id, updateData: $updateData);
	}//end applyStatus()

	/**
	 * The fields a move to a status writes: the status, and the lock stamp for final.
	 *
	 * @param string $newStatus The requested status.
	 *
	 * @return array<string, string> The changes.
	 */
	private static function statusChanges(string $newStatus): array {
		$updateData = ['status' => $newStatus];
		if ($newStatus === 'final') {
			$updateData['lockedOn'] = date('Y-m-d\TH:i:s');
		}

		return $updateData;
	}//end statusChanges()

	/**
	 * The summary a transition answers.
	 *
	 * Carry `vergrendeldOp` through only when the transition set it to a
	 * non-null value. Kept as an isset() test rather than
	 * array_intersect_key(), which would also carry an explicitly-null value
	 * through and write a null back over the stored field.
	 *
	 * @param string                $infoObjectId The informatieobject UUID.
	 * @param array<string, string> $updateData   The changes written.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private static function summary(string $infoObjectId, array $updateData): array {
		$lockedOn = [];
		if (isset($updateData['lockedOn']) === true) {
			$lockedOn = ['lockedOn' => $updateData['lockedOn']];
		}

		return array_merge(
			['id' => $infoObjectId, 'status' => $updateData['status']],
			$lockedOn,
		);
	}//end summary()

	/**
	 * Refuse the lock while the document's approval route is still running.
	 *
	 * 🔑 THE ROUTE IS THE GROUND FOR THE ACT, NEVER THE ACT. Making a document
	 * final LOCKS it and stamps `lockedOn`, and the person who has to defend
	 * that lock is the handler on the case, not the last approver clicking
	 * Approve in a widget. So an approved route makes the transition available
	 * and a person makes it; nothing here moves a document on decidiq's say-so.
	 *
	 * 🔴 IT GUARDS ONE TRANSITION. `final` is the one that locks. `archived`
	 * happens after it and its own guard is the forward-only rule, and a
	 * document nobody ever routed is untouched, which is where every document
	 * on every instance without decidiq stays.
	 *
	 * @param string $infoObjectId The informatieobject UUID.
	 * @param string $newStatus The requested status.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a route is still open.
	 *
	 * @spec openspec/specs/besluitvorming-leaf/spec.md
	 */
	private function requireApprovalCleared(string $infoObjectId, string $newStatus): void {
		if ($newStatus !== 'final' || $this->approvals === null) {
			return;
		}

		$clearance = $this->approvals->forDocument(documentId: $infoObjectId);
		if (($clearance['routed'] ?? false) !== true || ($clearance['cleared'] ?? true) === true) {
			return;
		}

		$reason = $this->approvals->describe(clearance: $clearance);
		if ($reason === '') {
			$reason = 'a route is still open.';
		}

		throw new InvalidArgumentException(
			'This document is in an approval route that has not finished, so it cannot be made final yet: '
			. $reason
		);
	}//end requireApprovalCleared()


	/**
	 * Apply a bulk status transition, returning a per-id success/failure list.
	 *
	 * @param string[] $infoObjectIds The informatieobject UUIDs.
	 * @param string $newStatus The requested status.
	 *
	 * @return array<int, array<string, mixed>> Per-id results with `id`, `success`, optional `error`.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
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
		return $this->dossierRegister->context();
	}//end requireRegister()
}//end class
