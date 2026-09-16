<?php

/**
 * Dossiq major case declaration.
 *
 * Declaring a case major is the act; the working channel is a consequence.
 * Jira Service Management marks an incident major and then opens the channel
 * and pulls the responders in, and the order is what matters: a channel that
 * somebody opened by hand is not a declaration, and a declaration with no
 * channel leaves a calamiteit coordinated in a corridor.
 *
 * One channel per case, never one per person who wants one. The clause behind
 * this is fifteen minutes, and two channels is how a calamiteit ends up half
 * coordinated in each.
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

use OCA\Dossiq\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Declares a case major, and closes the channel that declaration opened.
 *
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */
class MajorCaseDeclaration {

	/**
	 * What a major case's working channel is called when the case names itself
	 * nothing.
	 *
	 * @var string
	 */
	private const CHANNEL_PREFIX = 'Calamiteit';

	/**
	 * Constructor.
	 *
	 * @param CaseRecordStore        $cases      Reads the case and writes the fields this owns.
	 * @param TalkConversationBroker $broker     The one seam to Nextcloud Talk.
	 * @param ResponderResolver      $responders Resolves the responders a case type names.
	 * @param LoggerInterface        $logger     Logger.
	 */
	public function __construct(
		private readonly CaseRecordStore $cases,
		private readonly TalkConversationBroker $broker,
		private readonly ResponderResolver $responders,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Declare a case major: one channel, the responders the case type names.
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
		$case = $this->cases->read(caseId: $caseId);
		if ($case === null) {
			return ['ok' => false, 'reason' => CaseConversationService::REASON_NO_CASE];
		}

		$standing = $this->openChannelOf(case: $case);
		if ($standing !== null && ($case['isMajor'] ?? false) === true) {
			return ['ok' => true, 'alreadyMajor' => true, 'channel' => $standing];
		}

		$resolution = $this->responders->resolve(caseTypeId: $this->cases->caseTypeIdOf(case: $case));
		if ($resolution['ok'] === false) {
			return [
				'ok' => false,
				'reason' => CaseConversationService::REASON_UNRESOLVED_RESPONDERS,
				'unresolved' => $resolution['unresolved'],
			];
		}

		$room = $this->openChannel(case: $case, moderators: $resolution['users']);
		if ($room === null) {
			return ['ok' => false, 'reason' => CaseConversationService::REASON_NO_TALK];
		}

		$channel = [
			'roomId' => $room['id'],
			'roomUrl' => $room['url'],
			'openedAt' => $this->cases->now(),
			'closedAt' => '',
		];

		// The responders are notified by the declared `caseDeclaredMajor` rule
		// on the case schema, which fires on this write because `isMajor`
		// changes to true here. dossiq dispatches nothing itself, per ADR-031.
		$this->cases->write(
			caseId: $caseId,
			changes: [
				'isMajor' => true,
				'majorChannel' => $channel,
				'majorDeclaredBy' => ($declaredBy ?? ''),
				'majorDeclaredAt' => $this->cases->now(),
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
		$case = $this->cases->read(caseId: $caseId);
		if ($case === null) {
			return ['ok' => false, 'reason' => CaseConversationService::REASON_NO_CASE];
		}

		$channel = $this->openChannelOf(case: $case);
		if ($channel === null) {
			return ['ok' => true, 'closed' => false];
		}

		$closedAt = $this->cases->now();
		$conversations = $this->cases->conversationsOf(case: $case);
		$conversations[] = [
			'kind' => 'majorChannel',
			'subject' => $this->cases->roomNameFor(case: $case, fallback: self::CHANNEL_PREFIX),
			'roomId' => $channel['roomId'],
			'roomUrl' => ($channel['roomUrl'] ?? ''),
			'startedAt' => ($channel['openedAt'] ?? ''),
			'startedBy' => (string)($case['majorDeclaredBy'] ?? ''),
			'participants' => array_values((array)($case['majorResponders'] ?? [])),
			'durationSeconds' => 0,
			'endedAt' => $closedAt,
		];

		$channel['closedAt'] = $closedAt;

		// Filed BEFORE the room is closed: a room Talk has deleted can no
		// longer be read, so a write that failed after the delete would lose
		// what was said rather than merely fail.
		$this->cases->write(
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
	 * The channel this case already has, when it has one that is still open.
	 *
	 * Whether the case is also flagged major is the caller's question, not
	 * this one's: declaring asks both, closing asks only whether a room is
	 * standing, and folding that into a flag argument here would make one
	 * method answer two questions.
	 *
	 * @param array<string, mixed> $case The case as stored.
	 *
	 * @return array<string, mixed>|null The open channel, or null.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	private function openChannelOf(array $case): ?array {
		$channel = ($case['majorChannel'] ?? []);
		if (is_array($channel) === false) {
			return null;
		}

		if (($channel['roomId'] ?? '') === '' || ($channel['closedAt'] ?? '') !== '') {
			return null;
		}

		return $channel;
	}//end openChannelOf()

	/**
	 * Open the one room a declaration gets.
	 *
	 * @param array<string, mixed> $case       The case as stored.
	 * @param array<string>        $moderators The responders to pull in.
	 *
	 * @return array{id: string, url: string}|null The room, or null when Talk cannot give one.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	private function openChannel(array $case, array $moderators): ?array {
		if ($this->broker->isAvailable() === false) {
			return null;
		}

		return $this->broker->createRoom(
			name: $this->cases->roomNameFor(case: $case, fallback: self::CHANNEL_PREFIX),
			moderators: $moderators,
		);
	}//end openChannel()

}//end class
