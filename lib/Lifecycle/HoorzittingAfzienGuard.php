<?php

/**
 * Dossiq hearing-waiver guard.
 *
 * OpenRegister lifecycle guard (consumed via x-openregister-lifecycle
 * `requires`) gating the `hoorzitting_overslaan` transition on the `bezwaar`
 * schema. AWB art. 7:3 permits skipping the hearing only when the
 * belanghebbende has waived the right to be heard; this guard enforces that
 * the `hearingWaived` flag is set before the bezwaar may bypass the hearing
 * stage. Read-only: no mutations, no side effects (ADR-022, ADR-031).
 *
 * @category Lifecycle
 * @package  OCA\Dossiq\Lifecycle
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

namespace OCA\Dossiq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Allows the bezwaar `hoorzitting_overslaan` transition only when the
 * belanghebbende has waived the right to be heard (AWB art. 7:3).
 *
 * @spec openspec/changes/migrate-status-engine-to-or-lifecycle/tasks.md#P-3.2
 */
class HoorzittingAfzienGuard implements LifecycleGuardInterface {
	/**
	 * Authorise (or deny) the transition.
	 *
	 * StaticAccess is suppressed below rather than decomposed: OpenRegister's
	 * GuardResult is an immutable value object whose constructor is private,
	 * so allow()/deny() are its only construction path. A local factory
	 * collaborator would have to make the very same static call, which would
	 * move the finding instead of removing it.
	 *
	 * @param array<string, mixed> $object The loaded bezwaar payload at its current state.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow when hearingWaived is true, deny otherwise.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $action/$userId are mandated by the interface; this guard reads only the payload.
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult has a private constructor upstream; see the note above.
	 *
	 * @spec openspec/changes/migrate-status-engine-to-or-lifecycle/tasks.md#P-3.2
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$waived = ($object['hearingWaived'] ?? false);

		if ($waived === true || $waived === 'true' || $waived === 1 || $waived === '1') {
			return GuardResult::allow();
		}

		return GuardResult::deny(
			'De hoorzitting mag alleen worden overgeslagen als het hoorrecht is afgezien (AWB art. 7:3).'
		);
	}//end check()
}//end class
