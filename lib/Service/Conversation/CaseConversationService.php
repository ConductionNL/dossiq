<?php

/**
 * Dossiq case conversation service.
 *
 * A live conversation started from any case, and the record it leaves behind.
 *
 * The hoorzitting already held a video conversation through
 * `OCP\Talk\IBroker`, for exactly one moment of exactly one case type. This
 * service is that same act lifted to the case, with the hoorzitting as one
 * configured use of it. A second mechanism beside the hearing would give two
 * attendance records that disagree, and the bezwaar one is the one a
 * commissie relies on.
 *
 * dossiq owns the declaration, the record on the case and the rules about
 * what may be seen. It owns no transport, no recorder and no model.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Conversation
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Conversation;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\CaseConversationServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Starts live conversations on a case and records what they were.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseConversationService implements CaseConversationServiceInterface {

	/**
	 * What a conversation on a case is called when no subject is given.
	 *
	 * @var string
	 */
	private const ROOM_PREFIX = 'Gesprek';

	/**
	 * Constructor.
	 *
	 * @param CaseRecordStore        $cases  Reads the case and writes the fields this owns.
	 * @param TalkConversationBroker $broker The one seam to Nextcloud Talk.
	 * @param LoggerInterface        $logger Logger.
	 */
	public function __construct(
		private readonly CaseRecordStore $cases,
		private readonly TalkConversationBroker $broker,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a conversation can be started at all, and why not when it cannot.
	 *
	 * @return array{available: bool, reason: string|null} The affordance and its reason.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function availability(): array {
		if ($this->broker->isAvailable() === false) {
			return ['available' => false, 'reason' => self::REASON_NO_TALK];
		}

		return ['available' => true, 'reason' => null];
	}//end availability()

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
	public function startConversation(string $caseId, ?string $subject = null, ?string $startedBy = null): array {
		$case = $this->cases->read(caseId: $caseId);
		if ($case === null) {
			return ['ok' => false, 'reason' => self::REASON_NO_CASE];
		}

		if ($this->broker->isAvailable() === false) {
			return ['ok' => false, 'reason' => self::REASON_NO_TALK];
		}

		$name = $this->roomName(case: $case, subject: $subject);
		$room = $this->broker->createRoom(
			name: $name,
			moderators: $this->moderatorsOf(case: $case, startedBy: $startedBy),
		);
		if ($room === null) {
			return ['ok' => false, 'reason' => self::REASON_NO_TALK];
		}

		$record = $this->conversationRecord(name: $name, room: $room, startedBy: $startedBy);

		$this->appendConversation(caseId: $caseId, case: $case, record: $record);

		return ['ok' => true, 'conversation' => $record];
	}//end startConversation()

	/**
	 * The record of a conversation just started.
	 *
	 * @param string               $name      The room name, which is the subject.
	 * @param array<string, mixed> $room      The Talk room, with its id and url.
	 * @param string|null          $startedBy User id that started it.
	 *
	 * @return array<string, mixed> The conversation record.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function conversationRecord(string $name, array $room, ?string $startedBy): array {
		return [
			'kind' => 'conversation',
			'subject' => $name,
			'roomId' => $room['id'],
			'roomUrl' => $room['url'],
			'startedAt' => $this->cases->now(),
			'startedBy' => ($startedBy ?? ''),
			'participants' => [],
			'durationSeconds' => 0,
			'endedAt' => '',
		];
	}//end conversationRecord()

	/**
	 * Append a conversation record to the case and log it.
	 *
	 * @param string               $caseId Case UUID.
	 * @param array<string, mixed> $case   The case.
	 * @param array<string, mixed> $record The conversation record.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function appendConversation(string $caseId, array $case, array $record): void {
		$conversations = $this->cases->conversationsOf(case: $case);
		$conversations[] = $record;
		$this->cases->write(caseId: $caseId, changes: ['conversations' => $conversations]);

		$this->logger->info(
			'Conversation started on case ' . $caseId,
			['app' => AppIdentity::APP_ID],
		);
	}//end appendConversation()

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
	public function recordConversationEnd(string $caseId, string $roomId, array $participants, int $durationSeconds): array {
		$case = $this->cases->read(caseId: $caseId);
		if ($case === null) {
			return ['ok' => false, 'reason' => self::REASON_NO_CASE];
		}

		[$conversations, $found] = $this->ended(caseData: $case, roomId: $roomId, participants: $participants, durationSeconds: $durationSeconds);

		if ($found === null) {
			return ['ok' => false, 'reason' => self::REASON_UNKNOWN_ROOM];
		}

		$this->cases->write(caseId: $caseId, changes: ['conversations' => $conversations]);

		return ['ok' => true, 'conversation' => $found];
	}//end recordConversationEnd()

	/**
	 * The case's conversations with the room's conversation completed, and that conversation.
	 *
	 * @param array<string, mixed>                    $caseData        The case.
	 * @param string                                  $roomId          The Talk conversation id.
	 * @param array<string>                           $participants    User ids or display names that joined.
	 * @param int                                     $durationSeconds How long it lasted.
	 *
	 * @return array{0: array<int|string, array<string, mixed>>, 1: array<string, mixed>|null} The conversations, and the completed one or null.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function ended(array $caseData, string $roomId, array $participants, int $durationSeconds): array {
		$conversations = $this->cases->conversationsOf(case: $caseData);
		$found = null;
		foreach ($conversations as $index => $conversation) {
			if (($conversation['roomId'] ?? '') !== $roomId) {
				continue;
			}

			$conversation['participants'] = array_values($participants);
			$conversation['durationSeconds'] = max(0, $durationSeconds);
			$conversation['endedAt'] = $this->cases->now();
			$conversations[$index] = $conversation;
			$found = $conversation;
		}

		return [$conversations, $found];
	}//end ended()

	/**
	 * The room name a conversation on this case carries into Talk.
	 *
	 * @param array<string, mixed> $case    The case as stored.
	 * @param string|null          $subject What the conversation is about.
	 *
	 * @return string The room name.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function roomName(array $case, ?string $subject): string {
		if ($subject !== null && trim($subject) !== '') {
			return trim($subject);
		}

		return $this->cases->roomNameFor(case: $case, fallback: self::ROOM_PREFIX);
	}//end roomName()

	/**
	 * The moderators a conversation opens with.
	 *
	 * @param array<string, mixed> $case      The case as stored.
	 * @param string|null          $startedBy The user starting it.
	 *
	 * @return array<string> User ids.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function moderatorsOf(array $case, ?string $startedBy): array {
		$moderators = [];
		if ($startedBy !== null && $startedBy !== '') {
			$moderators[] = $startedBy;
		}

		$assignee = (string)($case['assignee'] ?? '');
		if ($assignee !== '' && in_array($assignee, $moderators, true) === false) {
			$moderators[] = $assignee;
		}

		return $moderators;
	}//end moderatorsOf()

}//end class
