<?php

/**
 * Procest Voorstel submit guard.
 *
 * OpenRegister lifecycle guard (consumed via x-openregister-lifecycle
 * `requires`) gating the `startParafering` transition on the `voorstel`
 * schema. The transition itself is enforced server-side by OR's
 * LifecycleValidationListener on every saveObject; this guard adds the
 * precondition that the mandatory `onderwerp` and `type` fields are filled
 * before a draft may enter the parafering route. Read-only: it makes no
 * mutations and triggers no side effects (ADR-022, ADR-031).
 *
 * @category Lifecycle
 * @package  OCA\Procest\Lifecycle
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Allows the voorstel `startParafering` transition only when the required
 * `onderwerp` and `type` fields are non-empty.
 *
 * @spec openspec/changes/migrate-status-engine-to-or-lifecycle/tasks.md#P-1.2
 */
class VoorstelSubmitGuard implements LifecycleGuardInterface {
	/**
	 * Authorise (or deny) the transition.
	 *
	 * StaticAccess is suppressed below rather than decomposed: OpenRegister's
	 * GuardResult is an immutable value object whose constructor is private,
	 * so allow()/deny() are its only construction path. A local factory
	 * collaborator would have to make the very same static call, which would
	 * move the finding instead of removing it.
	 *
	 * @param array<string, mixed> $object The loaded voorstel payload at its current state.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow when both required fields are filled, deny otherwise.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $action/$userId are mandated by the interface; this guard reads only the payload.
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult has a private constructor upstream; see the note above.
	 *
	 * @spec openspec/changes/migrate-status-engine-to-or-lifecycle/tasks.md#P-1.2
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$subject = trim((string)($object['subject'] ?? ''));
		$type = trim((string)($object['type'] ?? ''));

		if ($subject === '' || $type === '') {
			return GuardResult::deny(
				'Het voorstel kan pas in parafering worden gebracht als onderwerp en type zijn ingevuld.'
			);
		}

		return GuardResult::allow();
	}//end check()
}//end class
