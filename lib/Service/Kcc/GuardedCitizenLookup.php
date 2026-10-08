<?php

/**
 * Dossiq guarded citizen lookup.
 *
 * Who may look a citizen up, and the audit row that says they did. The two are
 * one class rather than two collaborators a caller holds side by side, because
 * the refusal is the half that catches enumeration: an account refused four
 * hundred times in an afternoon is not a handler who mistyped a BSN. A caller
 * that remembers the guard and forgets the recorder leaves exactly that
 * pattern unrecorded, and nothing fails while it happens.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Kcc
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
 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

use OCA\Dossiq\Service\CitizenLookupGuard;
use OCP\IUser;

/**
 * The citizen-lookup guard and its audit row, together.
 *
 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
 */
class GuardedCitizenLookup {
	/**
	 * Constructor.
	 *
	 * @param CitizenLookupGuard    $guard    Whether this account holds the kcc role.
	 * @param CitizenLookupRecorder $recorder Writes one audit row per attempt.
	 */
	public function __construct(
		private readonly CitizenLookupGuard $guard,
		private readonly CitizenLookupRecorder $recorder,
	) {
	}//end __construct()

	/**
	 * Whether this caller may look a citizen up at all.
	 *
	 * @param IUser $user The caller.
	 *
	 * @return bool True when the lookup may go ahead.
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	public function isAllowed(IUser $user): bool {
		return $this->guard->isCitizenLookupAllowed(user: $user);
	}//end isAllowed()

	/**
	 * Record a lookup that was refused.
	 *
	 * @param string $uid      The account that was refused.
	 * @param string $burgerId The citizen reference it looked with.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	public function recordRefusal(string $uid, string $burgerId): void {
		$this->recorder->record(
			employeeId: $uid,
			subjectId: $burgerId,
			allowed: false,
			fields: [],
			ground: 'geen kcc-rol',
		);
	}//end recordRefusal()

	/**
	 * Record a lookup that was answered, and the fields it revealed.
	 *
	 * @param IUser  $user     The caller.
	 * @param string $burgerId The citizen reference.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	public function recordAnswer(IUser $user, string $burgerId): void {
		$this->recorder->record(
			employeeId: $user->getUID(),
			subjectId: $burgerId,
			allowed: true,
			fields: $this->guard->revealedFieldsFor(user: $user),
			ground: 'kcc-rol',
		);
	}//end recordAnswer()

	/**
	 * The payload with every field this caller may not see taken out.
	 *
	 * @param IUser                $user    The caller.
	 * @param array<string, mixed> $payload What was read.
	 *
	 * @return array<string, mixed> What the caller may see.
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	public function redactForCaller(IUser $user, array $payload): array {
		return $this->guard->redactForCaller(user: $user, payload: $payload);
	}//end redactForCaller()
}//end class
