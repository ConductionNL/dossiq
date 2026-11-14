<?php

/**
 * Dossiq intake role gate.
 *
 * The signed-in caller, and the refusal an intake endpoint answers when there
 * is none or when the intake policy does not let them run intake.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\Contracts\IntakePolicyInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUserSession;

/**
 * Who may run intake, for the mail intake and intake triage endpoints.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
final class IntakeRoleGate {

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The signed-in caller.
	 * @param IntakePolicyInterface $policy      Who may run intake.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IntakePolicyInterface $policy,
	) {
	}//end __construct()

	/**
	 * The refusal for a caller who may not run intake, or null.
	 *
	 * @return JSONResponse|null 401 when nobody is signed in, 403 without the intake role, null otherwise.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function refusal(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->mayRunIntake(userId: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refusal()

	/**
	 * The signed-in caller's uid, or '' when nobody is signed in.
	 *
	 * @return string The uid.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function callerId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end callerId()
}//end class
