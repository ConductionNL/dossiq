<?php

/**
 * The curated tool an assistant may call to draft a beschikking.
 *
 * DRAFT ONLY (design D3). Approving needs mandate, signing runs the parafering
 * route and sending reaches the resident: an agent doing any of those is a
 * mandate breach, so this class offers composing a draft and nothing else. The
 * caseworker finishes it on the beschikking page.
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Mcp;

use OCA\Dossiq\Service\BeschikkingService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCP\IUserSession;
use RuntimeException;

/**
 * Draft a beschikking for a case.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
 */
class BeschikkingTools {

	use ToolEnvelopes;

	/**
	 * Constructor.
	 *
	 * @param IUserSession       $userSession The caller's session.
	 * @param CaseAccessGuard    $caseAccess  Answers whether the caller may change the case.
	 * @param BeschikkingService $decisions   Composes the draft from a template.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccess,
		private readonly BeschikkingService $decisions,
	) {
	}//end __construct()

	/**
	 * Compose a draft beschikking for a case.
	 *
	 * @param string $caseId       The case UUID.
	 * @param string $templateId   The template; the case type's default when empty.
	 * @param string $decisionType The kind of decision, e.g. toewijzing.
	 * @param string $rationale    The reasoning (motivering) to start from.
	 *
	 * @return array<string, mixed> The draft, with `_required` flags on missing fields, or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
	 */
	#[McpTool(
		name: 'draftBeschikking',
		description: 'Draft a beschikking for a case from a template. Draft only: approving, signing and sending stay with the caseworker.',
		readOnlyHint: false,
		destructiveHint: false,
		scope: 'create',
		reach: 'user',
		subject: 'beschikking',
		action: 'draft'
	)]
	public function draftBeschikking(string $caseId, string $templateId = '', string $decisionType = '', string $rationale = ''): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(code: 'not_authenticated', message: 'No user session.');
		}

		if ($this->caseAccess->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return $this->error(code: 'forbidden', message: 'You may not change this case.');
		}

		$fields = [];
		if (trim($decisionType) !== '') {
			$fields['decisionType'] = $decisionType;
		}

		if (trim($rationale) !== '') {
			$fields['rationale'] = $rationale;
		}

		$template = null;
		if (trim($templateId) !== '') {
			$template = $templateId;
		}

		try {
			return $this->decisions->compose($caseId, $template, $fields);
		} catch (RuntimeException $e) {
			return $this->refusal(e: $e);
		}
	}//end draftBeschikking()
}//end class
