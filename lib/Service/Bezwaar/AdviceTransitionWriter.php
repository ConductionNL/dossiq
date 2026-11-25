<?php

/**
 * Dossiq AdviceTransitionWriter
 *
 * Writes an advice request's move once its guards have passed: a plain patch,
 * or, for a terminal state, the chair's signature on the request's trail first.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bezwaar
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bezwaar;

use OCA\Dossiq\Service\InternalWriteMarker;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * The write of an advice request's transition (REQ-BAT-003).
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class AdviceTransitionWriter {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param BezwaarAuditTrail   $auditTrail     Records the chair's signature on the request's trail
	 * @param InternalWriteMarker $internalWrites Marks the transition's own save for DeliberationStartGuard
	 */
	public function __construct(
		private readonly BezwaarAuditTrail $auditTrail,
		private readonly InternalWriteMarker $internalWrites,
	) {
	}//end __construct()

	/**
	 * Write a transition's patch, recording the chair's signature first on a terminal state.
	 *
	 * A terminal state carries the chair's signature, with the chair from the
	 * session. It is recorded before the status moves; a status write that
	 * then fails leaves an `advice-signed-by-chair-not-applied` row
	 * (REQ-BAT-003).
	 *
	 * @param array{objectService: object, register: string, schema: string, id: string, current: array<string, mixed>} $request The stored request.
	 * @param array<string, mixed> $update The patch.
	 *
	 * @return array<string, mixed> The updated advice request record.
	 *
	 * @throws RuntimeException When the signature cannot be recorded; nothing is changed.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
	 */
	public function apply(array $request, array $update): array {
		if (isset($update['adviceIssuedAt']) === false) {
			return $this->transitionWrite(request: $request, update: $update)();
		}

		return $this->signThenApply(request: $request, update: $update);
	}//end apply()

	/**
	 * The write of a transition's patch, marked as internal.
	 *
	 * @param array{objectService: object, register: string, schema: string, id: string, current: array<string, mixed>} $request The stored request.
	 * @param array<string, mixed>                                                                                       $update  The patch.
	 *
	 * @return callable(): array<string, mixed> The write.
	 *
	 * @spec exclude one step of apply(), which carries the requirement
	 */
	private function transitionWrite(array $request, array $update): callable {
		// Marked so DeliberationStartGuard lets this save reach an outcome status.
		return fn (): array => $this->internalWrites->runInternal(
			objectId: $request['id'],
			write: fn (): array => ($this->patchObjectAsArray(
				objectService: $request['objectService'],
				register: $request['register'],
				schema: $request['schema'],
				id: $request['id'],
				changes: $update
			) ?? array_merge($request['current'], $update))
		);
	}//end transitionWrite()

	/**
	 * Record the chair's signature, then apply the terminal transition.
	 *
	 * @param array{objectService: object, register: string, schema: string, id: string, current: array<string, mixed>} $request The stored request.
	 * @param array<string, mixed>                                                                                       $update  The patch.
	 *
	 * @return array<string, mixed> The updated advice request record.
	 *
	 * @throws RuntimeException When the signature cannot be recorded; nothing is changed.
	 *
	 * @spec exclude one step of apply(), which carries the requirement
	 */
	private function signThenApply(array $request, array $update): array {
		return $this->auditTrail->recordThenApply(
			register: $request['register'],
			schema: $request['schema'],
			objectUuid: $request['id'],
			event: 'advice-signed-by-chair',
			payload: [
				'chair' => $this->auditTrail->resolveActor(),
				'signatureEvidence' => $update['signatureEvidence'] ?? ($request['current']['signatureEvidence'] ?? null),
				'conclusion' => $update['conclusion'] ?? ($request['current']['conclusion'] ?? null),
			],
			tag: '',
			apply: $this->transitionWrite(request: $request, update: $update),
		);
	}//end signThenApply()
}//end class
