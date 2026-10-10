<?php

/**
 * Curated tools an assistant may call on a statutory term.
 *
 * Extending, pausing and resuming a term move a statutory clock, so each tool
 * reads the term, checks the caller may change the CASE it runs on, and then
 * calls the same term service TermijnController calls. The term controller
 * itself only checks the session; the case check is added here because an
 * agent names a term by id and could otherwise move any case's clock.
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

use InvalidArgumentException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\TermijnService;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCP\IUserSession;
use RuntimeException;

/**
 * Tools on a term: extend (verdaging), pause (opschorting) and resume.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
 */
class TermTools {

	use ToolEnvelopes;

	/**
	 * Constructor.
	 *
	 * @param IUserSession             $userSession The caller's session.
	 * @param CaseAccessGuard          $caseAccess  Answers whether the caller may change the case.
	 * @param TermijnService           $terms       Reads a term instance.
	 * @param DeadlineExtensionService $extension   Owns verdaging.
	 * @param DeadlinePauseService     $pause       Owns opschorting and resuming.
	 * @param CaseDateNormaliser       $dates       Reads a date the way the term controller does.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccess,
		private readonly TermijnService $terms,
		private readonly DeadlineExtensionService $extension,
		private readonly DeadlinePauseService $pause,
		private readonly CaseDateNormaliser $dates,
	) {
	}//end __construct()

	/**
	 * Extend a term to a new end date (verdaging).
	 *
	 * @param string $deadlineId   The term instance id.
	 * @param string $rationale    Why the term is extended; it goes on the record.
	 * @param string $newEndDate   The new end date (YYYY-MM-DD).
	 * @param string $documentLink Optional link to the extension letter.
	 *
	 * @return array<string, mixed> The extended term, or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
	 */
	#[McpTool(
		name: 'extendDeadline',
		description: 'Extend the statutory term of a case to a new end date (verdaging), with the reason. The extension count and notices follow.',
		readOnlyHint: false,
		destructiveHint: false,
		scope: 'update',
		reach: 'instance',
		subject: 'deadline',
		action: 'extend'
	)]
	public function extendDeadline(string $deadlineId, string $rationale, string $newEndDate, string $documentLink = ''): array {
		$refused = $this->refuseUnlessCaseMayChange(deadlineId: $deadlineId);
		if ($refused !== null) {
			return $refused;
		}

		try {
			$endDate = $this->dates->toCalendarDate($newEndDate, 'newEndDate');
		} catch (InvalidArgumentException $e) {
			return $this->error(code: 'invalid_date', message: $e->getMessage());
		}

		try {
			return $this->extension->requestExtension($deadlineId, $rationale, $endDate, $documentLink);
		} catch (RuntimeException | InvalidArgumentException $e) {
			return $this->refusal(e: $e);
		}
	}//end extendDeadline()

	/**
	 * Pause a term for a number of days (opschorting).
	 *
	 * @param string $deadlineId   The term instance id.
	 * @param int    $durationDays How many days the term is suspended.
	 * @param string $rationale    Why; it goes on the record.
	 * @param string $pauseReason  The declared reason code, e.g. awaiting-applicant.
	 * @param string $documentLink Optional link to the request for information.
	 *
	 * @return array<string, mixed> The paused term, or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
	 */
	#[McpTool(
		name: 'pauseDeadline',
		description: 'Suspend the statutory term of a case for a number of days (opschorting), with the reason.',
		readOnlyHint: false,
		destructiveHint: false,
		scope: 'update',
		reach: 'instance',
		subject: 'deadline',
		action: 'pause'
	)]
	public function pauseDeadline(
		string $deadlineId,
		int $durationDays,
		string $rationale,
		string $pauseReason = '',
		string $documentLink = '',
	): array {
		$refused = $this->refuseUnlessCaseMayChange(deadlineId: $deadlineId);
		if ($refused !== null) {
			return $refused;
		}

		try {
			return $this->pause->registerPauze($deadlineId, $durationDays, $rationale, $documentLink, $pauseReason);
		} catch (RuntimeException | InvalidArgumentException $e) {
			return $this->refusal(e: $e);
		}
	}//end pauseDeadline()

	/**
	 * Resume a paused term.
	 *
	 * @param string $deadlineId The term instance id.
	 * @param string $resumeDate The day the missing information came in (YYYY-MM-DD); today when empty.
	 *
	 * @return array<string, mixed> The running term, or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
	 */
	#[McpTool(
		name: 'resumeDeadline',
		description: 'Resume a suspended statutory term, from the day the missing information came in (today when left empty).',
		readOnlyHint: false,
		destructiveHint: false,
		scope: 'update',
		reach: 'instance',
		subject: 'deadline',
		action: 'resume'
	)]
	public function resumeDeadline(string $deadlineId, string $resumeDate = ''): array {
		$refused = $this->refuseUnlessCaseMayChange(deadlineId: $deadlineId);
		if ($refused !== null) {
			return $refused;
		}

		$resumeAt = null;
		if (trim($resumeDate) !== '') {
			try {
				$resumeAt = $this->dates->parse($resumeDate, 'resumeDate');
			} catch (InvalidArgumentException $e) {
				return $this->error(code: 'invalid_date', message: $e->getMessage());
			}
		}

		try {
			return $this->pause->resumeAfterPauze($deadlineId, $resumeAt);
		} catch (RuntimeException | InvalidArgumentException $e) {
			return $this->refusal(e: $e);
		}
	}//end resumeDeadline()

	/**
	 * The refusal when the term is unknown or its case may not be changed, or null.
	 *
	 * @param string $deadlineId The term instance id.
	 *
	 * @return array{error: string, message: string}|null
	 */
	private function refuseUnlessCaseMayChange(string $deadlineId): ?array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(code: 'not_authenticated', message: 'No user session.');
		}

		$term = $this->terms->getTermijnInstance($deadlineId);
		$caseId = $this->caseOf(term: $term);
		if ($caseId === '') {
			return $this->error(code: 'deadline_not_found', message: 'That term could not be found on a case.');
		}

		if ($this->caseAccess->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return $this->error(code: 'forbidden', message: 'You may not change this case.');
		}

		return null;
	}//end refuseUnlessCaseMayChange()

	/**
	 * The case a term runs on: a bare uuid or an expanded relation.
	 *
	 * @param array<string, mixed>|null $term The term instance.
	 *
	 * @return string The case id, or ''.
	 */
	private function caseOf(?array $term): string {
		$case = ($term['case'] ?? '');
		if (is_array($case) === true) {
			return (string)($case['id'] ?? ($case['@self']['id'] ?? ''));
		}

		return (string)$case;
	}//end caseOf()
}//end class
