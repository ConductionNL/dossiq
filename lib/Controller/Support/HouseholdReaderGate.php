<?php

/**
 * Dossiq household reader gate.
 *
 * Whether a reader may ask about a case's household: they must be signed in,
 * may see the social domain's sensitive fields, and may read the case.
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\CitizenLookupGuardInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;

/**
 * Who may see a case's household, for the household cases endpoints.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class HouseholdReaderGate {

	/**
	 * Constructor.
	 *
	 * @param CaseAccessGuardInterface    $caseAccess Whether the reader may read a case.
	 * @param CitizenLookupGuardInterface $sensitive  Whether the reader may see the social domain's sensitive fields.
	 */
	public function __construct(
		private readonly CaseAccessGuardInterface $caseAccess,
		private readonly CitizenLookupGuardInterface $sensitive,
	) {
	}//end __construct()

	/**
	 * Why this reader may not ask about this case's household, or null when they may.
	 *
	 * @param IUser|null $user   The reader.
	 * @param string     $caseId The case uuid.
	 *
	 * @return JSONResponse|null 401 when nobody is signed in, 403 without sensitive access or case access, null otherwise.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function refusal(?IUser $user, string $caseId): ?JSONResponse {
		if ($user === null) {
			return $this->unauthenticated();
		}

		if ($this->sensitive->maySeeSensitiveFields(user: $user) === false
			|| $this->mayRead(caseId: $caseId, user: $user) === false
		) {
			return new JSONResponse(
				[
					'message' => 'Only someone who works with the social domain\'s sensitive data on this case can see its household.',
					'error'   => 'household-not-allowed',
				],
				Http::STATUS_FORBIDDEN,
			);
		}

		return null;
	}//end refusal()

	/**
	 * Whether the reader may read a case.
	 *
	 * @param string $caseId The case uuid.
	 * @param IUser  $user   The reader.
	 *
	 * @return bool True when they may.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function mayRead(string $caseId, IUser $user): bool {
		return $this->caseAccess->hasCaseReadAccess(caseId: $caseId, user: $user);
	}//end mayRead()

	/**
	 * The answer to a request nobody signed in for.
	 *
	 * @return JSONResponse 401.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function unauthenticated(): JSONResponse {
		return new JSONResponse(['message' => 'Sign in first.', 'error' => 'not-signed-in'], Http::STATUS_UNAUTHORIZED);
	}//end unauthenticated()
}//end class
