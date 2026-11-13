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

use OCA\Dossiq\Kernel\Contracts\IntakeLogInterface;
use OCA\Dossiq\Listener\Support\EventGetters;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\UnmatchedMailIntake;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
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
	 * @param CaseEmailRepository      $cases          Resolves a case reference and files the message.
	 * @param UnmatchedMailIntake      $unmatched      The fallback rule, shared with the poller.
	 * @param IntakeLogInterface                $log            The per-message record, and the duplicate guard.
	 * @param LoggerInterface          $logger         Logger.
	 * @param BackgroundServiceAccount $serviceAccount Writes for integriq's background work, which has no user.
	 */
	public function __construct(
		private readonly CaseEmailRepository $cases,
		private readonly UnmatchedMailIntake $unmatched,
		private readonly IntakeLogInterface $log,
		private readonly LoggerInterface $logger,
		private readonly BackgroundServiceAccount $serviceAccount,
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
		try {
			// Integriq raises this from its own background work, with nobody
			// signed in, and OpenRegister refuses a write from nobody. A
			// signed-in caller keeps writing as themselves.
			$this->serviceAccount->runAsWhenNobodyIsSignedIn(operation: fn () => $this->answer(event: $event));
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged as an error and told to the admins. Nothing was
			// written; the offer stays unanswered, so integriq files the
			// message under `unassigned`.
			return;
		}
	}//end handle()

	/**
	 * Answer the event, as whoever is writing.
	 *
	 * @param Event $event The integriq event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	private function answer(Event $event): void {
		if (method_exists($event, 'setOutcome') === false || method_exists($event, 'getMessage') === false) {
			return;
		}

		try {
			$this->settle(event: $event);
		} catch (Throwable $e) {
			// The slot STAYS EMPTY: see the class docblock. After an internal
			// failure no decision was made, and a `declined` here would be a
			// judgement this app never reached.
			$this->logger->error(
				'MessageReceivedListener: an offered message could not be answered, so it stays unassigned',
				['error' => $e->getMessage()]
			);
		}
	}//end answer()

	/**
	 * Answer the offer: filed before, linked by its reference, opened as a new case, or declined.
	 *
	 * @param Event $event The integriq event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	private function settle(Event $event): void {
		$filing = $this->filingOf(event: $event);

		if ($this->answerFiledBefore(event: $event, filing: $filing) === true) {
			return;
		}

		if ($this->linkByReference(event: $event, filing: $filing) === true) {
			return;
		}

		if ($this->openFallbackCase(event: $event, filing: $filing) === true) {
			return;
		}

		$this->decline(event: $event, filing: $filing);
	}//end settle()

	/**
	 * The offered message, its id and the reference integriq detected in it.
	 *
	 * @param Event $event The integriq event.
	 *
	 * @return MessageFiling
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	private function filingOf(Event $event): MessageFiling {
		$getters = new EventGetters();

		$filing = new MessageFiling();
		$filing->message = $getters->arrayFrom(event: $event, method: 'getMessage');
		$filing->messageUuid = $getters->stringFrom(event: $event, method: 'getMessageUuid');
		$filing->reference = $getters->stringFrom(event: $event, method: 'getDetectedReference');

		return $filing;
	}//end filingOf()

	/**
	 * Answer a message this app filed before with the same case, or declined.
	 *
	 * 🔴 THE GUARD AGAINST FILING TWICE. The poller and the event can both
	 * reach the same message on an instance running both paths. The SAME case
	 * is answered back, so integriq marks the second offer linked where the
	 * first one filed it rather than holding it; answering declined would send
	 * a message integriq already has a home for back to `unassigned`.
	 *
	 * @param Event         $event  The integriq event.
	 * @param MessageFiling $filing The offered message.
	 *
	 * @return bool True when the offer was answered.
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	private function answerFiledBefore(Event $event, MessageFiling $filing): bool {
		$already = $this->alreadyFiled(messageUuid: $filing->messageUuid);
		if ($already === null) {
			return false;
		}

		$filing->caseId = $already;
		$filing->answer = self::OUTCOME_LINKED;
		if ($already === '') {
			$filing->answer = self::OUTCOME_DECLINED;
		}

		$this->tell(event: $event, filing: $filing);
		return true;
	}//end answerFiledBefore()

	/**
	 * File the message on the case its reference names, and answer linked.
	 *
	 * @param Event         $event  The integriq event.
	 * @param MessageFiling $filing The offered message.
	 *
	 * @return bool True when the reference named a case.
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	private function linkByReference(Event $event, MessageFiling $filing): bool {
		if ($filing->reference === '') {
			return false;
		}

		$caseId = trim((string)$this->cases->findCaseIdByIdentifier($filing->reference));
		if ($caseId === '') {
			return false;
		}

		$filing->caseId = $caseId;
		$filing->reason = 'Linked to the case its reference names (' . $filing->reference . ').';
		$filing->answer = self::OUTCOME_LINKED;
		$this->fileOnCase(filing: $filing);
		$this->tell(event: $event, filing: $filing);
		return true;
	}//end linkByReference()

	/**
	 * Open a case of the fallback case type for the message, and answer created.
	 *
	 * @param Event         $event  The integriq event.
	 * @param MessageFiling $filing The offered message.
	 *
	 * @return bool True when a case was opened.
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	private function openFallbackCase(Event $event, MessageFiling $filing): bool {
		$created = trim((string)$this->unmatched->caseFor($filing->message));
		if ($created === '') {
			return false;
		}

		$filing->caseId = $created;
		$filing->reason = 'Opened as a new case of the fallback case type.';
		$filing->answer = self::OUTCOME_CREATED;
		$this->fileOnCase(filing: $filing);
		$this->tell(event: $event, filing: $filing);
		return true;
	}//end openFallbackCase()

	/**
	 * File the message on its case and log it there.
	 *
	 * @param MessageFiling $filing The message, with its case and reason.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	private function fileOnCase(MessageFiling $filing): void {
		$this->file(caseId: $filing->caseId, message: $filing->message);
		$filing->logOutcome = IntakeLogInterface::OUTCOME_CASE;
		$this->record(filing: $filing);
	}//end fileOnCase()

	/**
	 * Log the message to the inbox with the reason, and answer declined.
	 *
	 * 🔴 THE REASON GOES IN DOSSIQ'S OWN LOG, because integriq's contract has
	 * nowhere to put one: `setOutcome()` takes an outcome and an object
	 * reference and nothing else. So the decline that reaches integriq is
	 * bare, and the sentence saying WHY is on the intake log entry this app
	 * just wrote, which is the surface a handler opens anyway.
	 *
	 * @param Event         $event  The integriq event.
	 * @param MessageFiling $filing The offered message.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	private function decline(Event $event, MessageFiling $filing): void {
		$filing->caseId = '';
		$filing->logOutcome = IntakeLogInterface::OUTCOME_INBOX;
		$filing->reason = $this->declineReason(reference: $filing->reference);
		$this->record(filing: $filing);

		$filing->answer = self::OUTCOME_DECLINED;
		$this->tell(event: $event, filing: $filing);
	}//end decline()

	/**
	 * Tell integriq the outcome, with the case when there is one.
	 *
	 * @param Event         $event  The integriq event; answer() checked it can take an outcome.
	 * @param MessageFiling $filing The message, with its answer and case.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	private function tell(Event $event, MessageFiling $filing): void {
		if (method_exists($event, 'setOutcome') === false) {
			return;
		}

		if ($filing->caseId === '') {
			$event->setOutcome($filing->answer);
			return;
		}

		$event->setOutcome($filing->answer, $filing->caseId);
	}//end tell()

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
	 * @param MessageFiling $filing The message, with its outcome, reason and case.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	private function record(MessageFiling $filing): void {
		$this->log->recordChannelMessage(
			channel: self::CHANNEL,
			channelMessageId: $filing->messageUuid,
			sender: $this->correspondent(message: $filing->message),
			subject: trim((string)($filing->message['subject'] ?? '')),
			outcome: $filing->logOutcome,
			reason: $filing->reason,
			caseId: $filing->caseId
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
}//end class
