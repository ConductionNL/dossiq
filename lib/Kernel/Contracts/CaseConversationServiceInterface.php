<?php

/**
 * Contract of CaseConversationService
 *
 * The public surface of CaseConversationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of CaseConversationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseConversationServiceInterface {

	/**
	 * Refusal reason: this instance has no Talk, so there is no conversation
	 * to start and the case says so instead of offering a dead affordance.
	 *
	 * @var string
	 */
	public const REASON_NO_TALK = 'talk_not_available';

	/**
	 * Refusal reason: the case is not readable, or OpenRegister is not
	 * configured for cases.
	 *
	 * @var string
	 */
	public const REASON_NO_CASE = 'case_not_found';

	/**
	 * Refusal reason: no conversation on this case carries the room id given,
	 * so there is nothing to record an ending against.
	 *
	 * @var string
	 */
	public const REASON_UNKNOWN_ROOM = 'conversation_not_found';

	/**
	 * Refusal reason: the case type names responders that do not resolve, so
	 * the major declaration is refused rather than opening an empty channel
	 * (ADR-102: absent config fails closed with a status).
	 *
	 * @var string
	 */
	public const REASON_UNRESOLVED_RESPONDERS = 'responders_unresolved';

	/**
	 * Whether a conversation can be started at all, and why not when it cannot.
	 *
	 * @return array{available: bool, reason: string|null} The affordance and its reason.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function availability(): array;

	/**
	 * Record that a conversation ended: the moment, who joined, how long.
	 *
	 * A call whose only trace is a Talk room is a call the archive never sees,
	 * so the case carries the moment, the participants and the duration. What
	 * the conversation produced is filed separately as a case document, under
	 * the case type's visibility declaration.
	 *
	 * @param string        $caseId          Case UUID.
	 * @param string        $roomId          The Talk conversation id started earlier.
	 * @param array<string> $participants    User ids or display names that joined.
	 * @param int           $durationSeconds How long it lasted.
	 *
	 * @return array{ok: bool, reason?: string, conversation?: array<string, mixed>}
	 *         The completed record, or a refusal naming its reason.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function recordConversationEnd(
		string $caseId,
		string $roomId,
		array $participants,
		int $durationSeconds,
	): array;

	/**
	 * Start a live conversation from any case.
	 *
	 * @param string      $caseId    Case UUID.
	 * @param string|null $subject   What the conversation is about, used as the room name.
	 * @param string|null $startedBy User id that started it.
	 *
	 * @return array{ok: bool, reason?: string, conversation?: array<string, mixed>}
	 *         The conversation record, or a refusal naming its reason.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function startConversation(string $caseId, ?string $subject = null, ?string $startedBy = null): array;
}//end interface
