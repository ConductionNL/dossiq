<?php

/**
 * dossiq answers when integriq offers it a message.
 *
 * Integriq offers every new message to the owning app through
 * `MessageReceivedEvent`, with a result slot the listener answers `linked`,
 * `created` or `declined`. dossiq bound one integriq event,
 * `DeliveryConcludedEvent`, and nothing listened for messages, so every offer
 * went unanswered and landed in integriq's `unassigned`. That is correct
 * behaviour on integriq's part; the app that went quiet was this one.
 *
 * 🔴 SILENCE AND A DECLINE ARE NOT THE SAME THING, even though integriq
 * treats them the same on arrival. Its own `isClaimed()` says as much: "a
 * declined message is answered but not claimed: it still needs somewhere to
 * go, so intake treats it exactly like silence." What differs is the RECORD.
 * A declined message is one somebody decided about, with the reason written
 * down where a handler can read it; silence is a question nobody answered.
 *
 * 🔴 AN EXCEPTION LEAVES THE SLOT EMPTY, ON PURPOSE. This is the one place
 * this listener does not answer, and it is deliberate rather than an
 * oversight: after an internal failure no decision was made, and writing
 * `declined` would be this app claiming a judgement it never reached. The
 * spec asks for no silence where a decision exists; a crash is not one.
 *
 * 🔴 IT IMPORTS NO INTEGRIQ CLASS AND TYPE HINTS NOTHING FROM IT, for the
 * reason {@see IntakeMessageRoutedListener} gives: integriq is an optional
 * runtime dependency, a type hint on a class the instance does not have is a
 * fatal when the container builds the listener, and the registration is
 * guarded on `class_exists` with an FQN string.
 *
 * 🔴 IT IS NOT A SECOND MATCHER. The reference integriq detected is the one
 * that decides, resolved against the case identifier this app already knows;
 * the fallback rule is {@see \OCA\Dossiq\Service\Email\UnmatchedMailIntake}'s
 * and is the same one the mailbox poller uses. Two matchers would disagree
 * the first time either was tuned.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Email\UnmatchedMailIntake;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers integriq's offer of a received message.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */
class MessageReceivedListener implements IEventListener {

	/**
	 * The integriq event this listener is registered for.
	 *
	 * A plain string, never imported: see the class docblock.
	 *
	 * @var string
	 */
	public const EVENT = 'OCA\\Integriq\\Event\\MessageReceivedEvent';

	/**
	 * The channel the intake log files these messages under.
	 *
	 * The log is keyed on channel plus the channel's own message id, and
	 * integriq's `message` uuid is what identifies one here. A channel of its
	 * own keeps these apart from the mailbox poller's entries, so an instance
	 * running BOTH paths can tell which one saw a message first.
	 *
	 * @var string
	 */
	public const CHANNEL = 'integriq-message';

	/**
	 * The three outcomes integriq's contract knows.
	 */
	public const OUTCOME_LINKED = 'linked';

	/**
	 * A case was opened for the message.
	 */
	public const OUTCOME_CREATED = 'created';

	/**
	 * Nobody here could place the message.
	 */
	public const OUTCOME_DECLINED = 'declined';

	/**
	 * Constructor.
	 *
	 * @param CaseEmailRepository $cases     Resolves a case reference and files the message.
	 * @param UnmatchedMailIntake $unmatched The fallback rule, shared with the poller.
	 * @param IntakeLog           $log       The per-message record, and the duplicate guard.
	 * @param LoggerInterface     $logger    Logger.
	 */
	public function __construct(
		private readonly CaseEmailRepository $cases,
		private readonly UnmatchedMailIntake $unmatched,
		private readonly IntakeLog $log,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Answer one offered message.
	 *
	 * @param Event $event The integriq offer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	public function handle(Event $event): void {
		if (method_exists($event, 'setOutcome') === false || method_exists($event, 'getMessage') === false) {
			return;
		}

		try {
			$message = $this->arrayFrom(event: $event, method: 'getMessage');
			$messageUuid = $this->stringFrom(event: $event, method: 'getMessageUuid');

			$already = $this->alreadyFiled(messageUuid: $messageUuid);
			if ($already !== null) {
				// 🔴 THE GUARD AGAINST FILING TWICE. The poller and the event
				// can both reach the same message on an instance running both
				// paths. The SAME case is answered back, so integriq marks the
				// second offer linked where the first one filed it rather than
				// holding it; answering declined would send a message integriq
				// already has a home for back to `unassigned`.
				if ($already === '') {
					$event->setOutcome(self::OUTCOME_DECLINED);
					return;
				}

				$event->setOutcome(self::OUTCOME_LINKED, $already);
				return;
			}

			$reference = $this->stringFrom(event: $event, method: 'getDetectedReference');
			if ($reference !== '') {
				$caseId = trim((string)$this->cases->findCaseIdByIdentifier($reference));
				if ($caseId !== '') {
					$this->file(caseId: $caseId, message: $message);
					$this->record(
						messageUuid: $messageUuid,
						message: $message,
						outcome: IntakeLog::OUTCOME_CASE,
						reason: 'Linked to the case its reference names (' . $reference . ').',
						caseId: $caseId
					);
					$event->setOutcome(self::OUTCOME_LINKED, $caseId);
					return;
				}
			}

			$created = trim((string)$this->unmatched->caseFor($message));
			if ($created !== '') {
				$this->file(caseId: $created, message: $message);
				$this->record(
					messageUuid: $messageUuid,
					message: $message,
					outcome: IntakeLog::OUTCOME_CASE,
					reason: 'Opened as a new case of the fallback case type.',
					caseId: $created
				);
				$event->setOutcome(self::OUTCOME_CREATED, $created);
				return;
			}

			$reason = $this->declineReason(reference: $reference);
			$this->record(
				messageUuid: $messageUuid,
				message: $message,
				outcome: IntakeLog::OUTCOME_INBOX,
				reason: $reason,
				caseId: ''
			);

			// 🔴 THE REASON GOES IN DOSSIQ'S OWN LOG, because integriq's
			// contract has nowhere to put one: `setOutcome()` takes an outcome
			// and an object reference and nothing else. So the decline that
			// reaches integriq is bare, and the sentence saying WHY is on the
			// intake log entry this app just wrote, which is the surface a
			// handler opens anyway.
			$event->setOutcome(self::OUTCOME_DECLINED);
		} catch (Throwable $e) {
			// The slot STAYS EMPTY: see the class docblock. After an internal
			// failure no decision was made, and a `declined` here would be a
			// judgement this app never reached.
			$this->logger->error(
				'MessageReceivedListener: an offered message could not be answered, so it stays unassigned',
				['error' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * The case this message was already filed on, when it was.
	 *
	 * @param string $messageUuid The integriq message uuid.
	 *
	 * @return string|null The case id (possibly ''), or null when this message is new.
	 */
	private function alreadyFiled(?string $messageUuid): ?string {
		if ($messageUuid === null || trim($messageUuid) === '') {
			return null;
		}

		$seen = $this->log->findChannelEntry(channel: self::CHANNEL, channelMessageId: $messageUuid);
		if ($seen === null) {
			return null;
		}

		return trim((string)($seen['case'] ?? ''));
	}//end alreadyFiled()

	/**
	 * File the message on the case, as an inbound email on it.
	 *
	 * @param string               $caseId  The case.
	 * @param array<string, mixed> $message The message integriq offered.
	 *
	 * @return void
	 */
	private function file(string $caseId, array $message): void {
		$this->cases->recordReceivedEmail(
			caseId: $caseId,
			from: $this->correspondent(message: $message),
			recipient: trim((string)($message['to'] ?? $message['recipient'] ?? '')),
			subject: trim((string)($message['subject'] ?? '')),
			body: trim((string)($message['body'] ?? $message['content'] ?? '')),
			inReplyTo: trim((string)($message['inReplyTo'] ?? ''))
		);
	}//end file()

	/**
	 * Write the per-message record, which is also the duplicate guard.
	 *
	 * @param string               $messageUuid The integriq message uuid.
	 * @param array<string, mixed> $message     The message.
	 * @param string               $outcome     The log outcome.
	 * @param string               $reason      Why, in a sentence.
	 * @param string               $caseId      The case it became, or ''.
	 *
	 * @return void
	 */
	private function record(
		string $messageUuid,
		array $message,
		string $outcome,
		string $reason,
		string $caseId,
	): void {
		$this->log->recordChannelMessage(
			channel: self::CHANNEL,
			channelMessageId: $messageUuid,
			sender: $this->correspondent(message: $message),
			subject: trim((string)($message['subject'] ?? '')),
			outcome: $outcome,
			reason: $reason,
			caseId: $caseId
		);
	}//end record()

	/**
	 * Why this message was declined, in words integriq can record.
	 *
	 * Two different sentences, because they ask for two different things. A
	 * message whose reference matched nothing needs somebody to look at the
	 * reference; a message with no reference at all needs a fallback case type
	 * configured. One sentence covering both would tell each reader half of
	 * what they have to do.
	 *
	 * @param string $reference The reference integriq detected, or ''.
	 *
	 * @return string The reason.
	 */
	private function declineReason(string $reference): string {
		if ($reference !== '') {
			return 'The reference "' . $reference . '" names no case in this instance, and no '
				. 'fallback case type is configured, so no case was opened.';
		}

		return 'The message names no case, and no fallback case type is configured, so there is '
			. 'nothing to open one from. Set a fallback case type to accept messages like this.';
	}//end declineReason()

	/**
	 * Who the message came from.
	 *
	 * @param array<string, mixed> $message The message.
	 *
	 * @return string The correspondent.
	 */
	private function correspondent(array $message): string {
		foreach (['from', 'sender', 'correspondent'] as $key) {
			$value = trim((string)($message[$key] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}//end correspondent()

	/**
	 * Read an array off the event, whatever it answers.
	 *
	 * @param Event  $event  The event.
	 * @param string $method The getter.
	 *
	 * @return array<string, mixed> The value, or an empty array.
	 */
	private function arrayFrom(Event $event, string $method): array {
		if (method_exists($event, $method) === false) {
			return [];
		}

		$value = $event->$method();

		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end arrayFrom()

	/**
	 * Read a string off the event, whatever it answers.
	 *
	 * @param Event  $event  The event.
	 * @param string $method The getter.
	 *
	 * @return string The value, or ''.
	 */
	private function stringFrom(Event $event, string $method): string {
		if (method_exists($event, $method) === false) {
			return '';
		}

		$value = $event->$method();

		if (is_scalar($value) === true) {
			return trim((string)$value);
		}

		return '';
	}//end stringFrom()
}//end class
