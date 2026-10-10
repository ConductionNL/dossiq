<?php

/**
 * Curated tools an assistant may call on one case.
 *
 * Each method is an `#[McpTool]` that OpenRegister's attribute scanner
 * registers as `dossiq.<name>`. It runs the gate the matching controller runs
 * and then calls the owning service, so the agent path and the human path are
 * the same path. Nothing here reads or writes an object directly.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Mcp
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Mcp;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCP\IUserSession;

/**
 * Tools on a single case.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
 */
class CaseTools {

	/**
	 * Constructor.
	 *
	 * @param IUserSession            $userSession The caller's session.
	 * @param CaseAccessGuard         $caseAccess  Answers whether the caller may read or change the case.
	 * @param StatusTransitionService $transitions Owns the status machine.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccess,
		private readonly StatusTransitionService $transitions,
	) {
	}//end __construct()

	/**
	 * The status changes the caller may make on a case now.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The current status and the allowed transitions, or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
	 */
	#[McpTool(
		name: 'listAvailableTransitions',
		description: 'The status a case is in and the status changes the current user may make on it now, with any guard that blocks one.',
		readOnlyHint: true,
		scope: 'read',
		reach: 'user',
		subject: 'case',
		action: 'listTransitions'
	)]
	public function listAvailableTransitions(string $caseId): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(code: 'not_authenticated', message: 'No user session.');
		}

		// The same check StatusTransitionController::available() runs: without
		// it the current status of any case would be readable by anyone.
		if ($this->caseAccess->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return $this->error(code: 'forbidden', message: 'You may not read this case.');
		}

		return $this->transitions->getAvailableTransitions(caseId: $caseId, userId: $user->getUID());
	}//end listAvailableTransitions()

	/**
	 * An error envelope the assistant can read back.
	 *
	 * @param string $code    A stable machine code.
	 * @param string $message A sentence for the person.
	 *
	 * @return array{error: string, message: string}
	 */
	private function error(string $code, string $message): array {
		return ['error' => $code, 'message' => $message];
	}//end error()
}//end class
