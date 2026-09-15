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
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Conversation;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Starts, records and closes live conversations on a case.
 *
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */
class CaseConversationService {

	use SearchesObjects;

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
	 * Refusal reason: the case type names responders that do not resolve, so
	 * the major declaration is refused rather than opening an empty channel
	 * (ADR-102: absent config fails closed with a status).
	 *
	 * @var string
	 */
	public const REASON_UNRESOLVED_RESPONDERS = 'responders_unresolved';

	/**
	 * Constructor.
	 *
	 * @param SettingsService         $settingsService Register and schema configuration.
	 * @param TalkConversationBroker  $broker          The one seam to Nextcloud Talk.
	 * @param ResponderResolver       $responders      Resolves the responders a case type names.
	 * @param LoggerInterface         $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TalkConversationBroker $broker,
		private readonly ResponderResolver $responders,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a conversation can be started at all, and why not when it cannot.
	 *
	 * @return array{available: bool, reason: string|null} The affordance and its reason.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
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
	 * @param string      $caseId  Case UUID.
	 * @param string|null $subject What the conversation is about, used as the room name.
	 * @param string|null $startedBy User id that started it.
	 *
	 * @return array{ok: bool, reason?: string, conversation?: array<string, mixed>}
	 *         The conversation record, or a refusal naming its reason.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function startConversation(string $caseId, ?string $subject = null, ?string $startedBy = null): array {
		$case = $this->readCase(caseId: $caseId);
		if ($case === null) {
			return ['ok' => false, 'reason' => self::REASON_NO_CASE];
		}

		if ($this->broker->isAvailable() === false) {
			return ['ok' => false, 'reason' => self::REASON_NO_TALK];
		}

		$name = $this->roomName(case: $case, subject: $subject);
		$room = $this->broker->createRoom(name: $name, moderators: $this->moderatorsOf(case: $case, startedBy: $startedBy));
		if ($room === null) {
			return ['ok' => false, 'reason' => self::REASON_NO_TALK];
		}

		$record = [
			'kind' => 'conversation',
			'subject' => $name,
			'roomId' => $room['id'],
			'roomUrl' => $room['url'],
			'startedAt' => $this->now(),
			'startedBy' => ($startedBy ?? ''),
			'participants' => [],
			'durationSeconds' => 0,
			'endedAt' => '',
		];

		$this->appendConversation(caseId: $caseId, case: $case, record: $record);

		$this->logger->info(
			'Conversation started on case ' . $caseId,
			['app' => Application::APP_ID],
		);

		return ['ok' => true, 'conversation' => $record];
	}//end startConversation()

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
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function recordConversationEnd(
		string $caseId,
		string $roomId,
		array $participants,
		int $durationSeconds,
	): array {
		$case = $this->readCase(caseId: $caseId);
		if ($case === null) {
			return ['ok' => false, 'reason' => self::REASON_NO_CASE];
		}

		$conversations = $this->conversationsOf(case: $case);
		$found = null;
		foreach ($conversations as $index => $conversation) {
			if (($conversation['roomId'] ?? '') !== $roomId) {
				continue;
			}

			$conversation['participants'] = array_values($participants);
			$conversation['durationSeconds'] = max(0, $durationSeconds);
			$conversation['endedAt'] = $this->now();
			$conversations[$index] = $conversation;
			$found = $conversation;
		}

		if ($found === null) {
			return ['ok' => false, 'reason' => self::REASON_NO_CASE];
		}

		$this->writeCase(caseId: $caseId, changes: ['conversations' => $conversations]);

		return ['ok' => true, 'conversation' => $found];
	}//end recordConversationEnd()

	/**
	 * Declare a case major: one channel, the responders the case type names.
	 *
	 * The declaration is the act and the channel is a consequence, so a case
	 * already declared major is taken to the channel it has rather than given
	 * a second one. A case type whose responders do not resolve is refused,
	 * because a crisis channel with nobody in it is worse than no channel.
	 *
	 * @param string      $caseId     Case UUID.
	 * @param string|null $declaredBy User id that declared it.
	 *
	 * @return array{ok: bool, reason?: string, unresolved?: array<string>, channel?: array<string, mixed>, alreadyMajor?: bool}
	 *         The channel, or a refusal naming its reason and the group that failed.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function declareMajor(string $caseId, ?string $declaredBy = null): array {
		$case = $this->readCase(caseId: $caseId);
		if ($case === null) {
			return ['ok' => false, 'reason' => self::REASON_NO_CASE];
		}

		$existing = $case['majorChannel'] ?? [];
		if (($case['isMajor'] ?? false) === true && is_array($existing) === true && ($existing['roomUrl'] ?? '') !== '') {
			// One channel per case, never one per person who wants one.
			return ['ok' => true, 'alreadyMajor' => true, 'channel' => $existing];
		}

		$resolution = $this->responders->resolve(caseTypeId: $this->caseTypeIdOf(case: $case));
		if ($resolution['ok'] === false) {
			return [
				'ok' => false,
				'reason' => self::REASON_UNRESOLVED_RESPONDERS,
				'unresolved' => $resolution['unresolved'],
			];
		}

		if ($this->broker->isAvailable() === false) {
			return ['ok' => false, 'reason' => self::REASON_NO_TALK];
		}

		$room = $this->broker->createRoom(
			name: $this->channelName(case: $case),
			moderators: $resolution['users'],
		);
		if ($room === null) {
			return ['ok' => false, 'reason' => self::REASON_NO_TALK];
		}

		$channel = [
			'roomId' => $room['id'],
			'roomUrl' => $room['url'],
			'openedAt' => $this->now(),
			'closedAt' => '',
		];

		// The responders are notified by the declared `caseDeclaredMajor`
		// notification on the case schema, which fires on this write. dossiq
		// dispatches nothing imperatively here, per ADR-031.
		$this->writeCase(
			caseId: $caseId,
			changes: [
				'isMajor' => true,
				'majorChannel' => $channel,
				'majorDeclaredBy' => ($declaredBy ?? ''),
				'majorDeclaredAt' => $this->now(),
				'majorResponders' => $resolution['users'],
			],
		);

		$this->logger->info(
			'Case ' . $caseId . ' declared major, ' . count($resolution['users']) . ' responders pulled in',
			['app' => Application::APP_ID],
		);

		return ['ok' => true, 'alreadyMajor' => false, 'channel' => $channel];
	}//end declareMajor()

	/**
	 * Close a major case's channel and keep what was said with the case.
	 *
	 * @param string $caseId Case UUID.
	 *
	 * @return array{ok: bool, reason?: string, closed?: bool}
	 *         Whether a channel was closed, or a refusal naming its reason.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function closeMajorChannel(string $caseId): array {
		$case = $this->readCase(caseId: $caseId);
		if ($case === null) {
			return ['ok' => false, 'reason' => self::REASON_NO_CASE];
		}

		$channel = $case['majorChannel'] ?? [];
		if (is_array($channel) === false || ($channel['roomId'] ?? '') === '') {
			return ['ok' => true, 'closed' => false];
		}

		if (($channel['closedAt'] ?? '') !== '') {
			return ['ok' => true, 'closed' => false];
		}

		$closedAt = $this->now();
		$conversations = $this->conversationsOf(case: $case);
		$conversations[] = [
			'kind' => 'majorChannel',
			'subject' => $this->channelName(case: $case),
			'roomId' => $channel['roomId'],
			'roomUrl' => ($channel['roomUrl'] ?? ''),
			'startedAt' => ($channel['openedAt'] ?? ''),
			'startedBy' => (string)($case['majorDeclaredBy'] ?? ''),
			'participants' => array_values((array)($case['majorResponders'] ?? [])),
			'durationSeconds' => 0,
			'endedAt' => $closedAt,
		];

		$channel['closedAt'] = $closedAt;

		$this->writeCase(
			caseId: $caseId,
			changes: [
				'majorChannel' => $channel,
				'conversations' => $conversations,
			],
		);

		$this->broker->closeRoom(roomId: (string)$channel['roomId']);

		return ['ok' => true, 'closed' => true];
	}//end closeMajorChannel()

	/**
	 * The conversations already recorded on a case.
	 *
	 * @param array<string, mixed> $case The case as stored.
	 *
	 * @return array<int, array<string, mixed>> The records, newest last.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	private function conversationsOf(array $case): array {
		$conversations = ($case['conversations'] ?? []);
		if (is_array($conversations) === false) {
			return [];
		}

		return array_values(array_filter($conversations, 'is_array'));
	}//end conversationsOf()

	/**
	 * Append one conversation record to the case.
	 *
	 * @param string               $caseId Case UUID.
	 * @param array<string, mixed> $case   The case as stored.
	 * @param array<string, mixed> $record The record to append.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	private function appendConversation(string $caseId, array $case, array $record): void {
		$conversations = $this->conversationsOf(case: $case);
		$conversations[] = $record;

		$this->writeCase(caseId: $caseId, changes: ['conversations' => $conversations]);
	}//end appendConversation()

	/**
	 * The room name a conversation on this case carries into Talk.
	 *
	 * @param array<string, mixed> $case    The case as stored.
	 * @param string|null          $subject What the conversation is about.
	 *
	 * @return string The room name.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	private function roomName(array $case, ?string $subject): string {
		if ($subject !== null && trim($subject) !== '') {
			return trim($subject);
		}

		$identifier = (string)($case['identifier'] ?? ($case['title'] ?? ''));
		if ($identifier === '') {
			return 'Gesprek';
		}

		return 'Gesprek ' . $identifier;
	}//end roomName()

	/**
	 * The name a major case's working channel carries into Talk.
	 *
	 * @param array<string, mixed> $case The case as stored.
	 *
	 * @return string The channel name.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	private function channelName(array $case): string {
		$identifier = (string)($case['identifier'] ?? ($case['title'] ?? ''));
		if ($identifier === '') {
			return 'Calamiteit';
		}

		return 'Calamiteit ' . $identifier;
	}//end channelName()

	/**
	 * The moderators a conversation opens with.
	 *
	 * @param array<string, mixed> $case      The case as stored.
	 * @param string|null          $startedBy The user starting it.
	 *
	 * @return array<string> User ids.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
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

	/**
	 * The case type a case names, as an id or slug.
	 *
	 * @param array<string, mixed> $case The case as stored.
	 *
	 * @return string The case type reference, empty when the case names none.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	private function caseTypeIdOf(array $case): string {
		$caseType = ($case['caseType'] ?? '');
		if (is_array($caseType) === true) {
			return (string)($caseType['id'] ?? ($caseType['uuid'] ?? ''));
		}

		return (string)$caseType;
	}//end caseTypeIdOf()

	/**
	 * Read a case through OpenRegister, so OR's own scoping applies.
	 *
	 * @param string $caseId Case UUID.
	 *
	 * @return array<string, mixed>|null The case, or null when it is not readable.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	private function readCase(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');
		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		return $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $caseId,
		);
	}//end readCase()

	/**
	 * Write the fields this service owns onto a case, and nothing else.
	 *
	 * @param string               $caseId  Case UUID.
	 * @param array<string, mixed> $changes The fields to write.
	 *
	 * @return array<string, mixed>|null The stored case.
	 *
	 * @throws RuntimeException When OpenRegister is not configured for cases.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	private function writeCase(string $caseId, array $changes): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');
		if (empty($register) === true || empty($schema) === true) {
			throw new RuntimeException('Case schema not configured');
		}

		return $this->patchObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $caseId,
			changes: $changes,
		);
	}//end writeCase()

	/**
	 * The current moment, in the format the schemas store.
	 *
	 * @return string An ISO 8601 timestamp.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	private function now(): string {
		return (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);
	}//end now()

}//end class
