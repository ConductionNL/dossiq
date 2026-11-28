<?php

/**
 * Dossiq SessionWrites.
 *
 * What a hearing session change writes: the Awb art. 7:7 entries and the patch they guard.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Hearing
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Hearing;

use OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Writes an attendance or minutes patch to a stored session, each after its entry.
 *
 * No change stands without its entry (REQ-BAT-003): the entries are recorded
 * first, and a patch that fails afterwards leaves a not-applied row.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class SessionWrites {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param BezwaarAuditTrail $auditTrail Writes each entry onto OpenRegister's audit trail.
	 * @param LoggerInterface   $logger     Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly BezwaarAuditTrail $auditTrail,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record each late correction, then write the attendance.
	 *
	 * Awb art. 7:7. Each late correction is recorded before the attendance
	 * changes; a correction whose entry cannot be written changes nothing. A
	 * patch that fails after the entries leaves an
	 * `attendance-late-correction-not-applied` row per correction (REQ-BAT-003).
	 *
	 * @param SessionStore $store The session store.
	 * @param SessionPatch $patch The attendance patch; its entries are the late corrections.
	 *
	 * @return array<string, mixed> The updated session.
	 *
	 * @throws RuntimeException When an entry or the patch fails.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function attendance(SessionStore $store, SessionPatch $patch): array {
		$this->recordLateCorrections(store: $store, patch: $patch);

		try {
			return $this->patchSession(store: $store, patch: $patch);
		} catch (\Throwable $e) {
			$this->recordUnappliedCorrections(store: $store, patch: $patch, e: $e);

			$this->logger->error(
				'Dossiq hearing: failed to record attendance: ' . $e->getMessage()
			);
			throw new RuntimeException('Could not record attendance');
		}//end try
	}//end attendance()

	/**
	 * Record the verslag entry, then apply the minutes.
	 *
	 * Awb art. 7:7. The verslag is recorded before the session changes.
	 *
	 * @param SessionStore $store The session store.
	 * @param SessionPatch $patch The minutes patch; its one entry is the verslag payload.
	 *
	 * @return array<string, mixed> The updated session.
	 *
	 * @throws RuntimeException When the entry or the patch fails.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function minutes(SessionStore $store, SessionPatch $patch): array {
		try {
			return $this->auditTrail->recordThenApply(
				register: $store->register,
				schema: $store->schema,
				objectUuid: $patch->sessionId,
				event: 'verslag-recorded',
				payload: $patch->entries[0],
				tag: BezwaarAuditTrail::TAG_VERSLAG,
				apply: fn (): array => $this->patchSession(store: $store, patch: $patch),
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq hearing: failed to add minutes: ' . $e->getMessage()
			);
			throw new RuntimeException('Could not add minutes');
		}//end try
	}//end minutes()

	/**
	 * Record each late correction before the attendance changes.
	 *
	 * @param SessionStore $store The session store.
	 * @param SessionPatch $patch The attendance patch; its entries are the late corrections.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When an entry fails.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function recordLateCorrections(SessionStore $store, SessionPatch $patch): void {
		foreach ($patch->entries as $correction) {
			$this->auditTrail->record(
				register: $store->register,
				schema: $store->schema,
				objectUuid: $patch->sessionId,
				event: 'attendance-late-correction',
				payload: $correction,
				tag: BezwaarAuditTrail::TAG_VERSLAG,
			);
		}
	}//end recordLateCorrections()

	/**
	 * Record each late correction as not applied after the patch failed.
	 *
	 * @param SessionStore $store The session store.
	 * @param SessionPatch $patch The attendance patch; its entries are the late corrections.
	 * @param \Throwable   $e     The patch failure.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function recordUnappliedCorrections(SessionStore $store, SessionPatch $patch, \Throwable $e): void {
		foreach ($patch->entries as $correction) {
			$this->auditTrail->recordRefusal(
				register: $store->register,
				schema: $store->schema,
				objectUuid: $patch->sessionId,
				event: 'attendance-late-correction-not-applied',
				payload: $correction + ['error' => $e->getMessage()],
				tag: BezwaarAuditTrail::TAG_VERSLAG,
			);
		}
	}//end recordUnappliedCorrections()

	/**
	 * Apply a patch to a stored session.
	 *
	 * @param SessionStore $store The session store.
	 * @param SessionPatch $patch The patch.
	 *
	 * @return array<string, mixed> The updated session, or the stored one with the changes when the store answers nothing.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function patchSession(SessionStore $store, SessionPatch $patch): array {
		return ($this->patchObjectAsArray(
			objectService: $store->objectService,
			register: $store->register,
			schema: $store->schema,
			id: $patch->sessionId,
			changes: $patch->update
		) ?? array_merge($patch->current, $patch->update));
	}//end patchSession()
}//end class
