<?php

/**
 * Dossiq Intake Log
 *
 * Every message the mailbox processed, its original source, the filter that
 * decided, the verdict, and the case it became or the reason it did not
 * (design D-8).
 *
 * 🔴 THIS IS A SURFACE, NOT A LOG FILE. "Ik heb wel gemaild" is a weekly
 * dispute and today the answer is in `nextcloud.log`, where the person who has
 * to answer it cannot reach. An entry here is an OpenRegister object, so it is
 * queryable by sender, it obeys the case type's retention like every other
 * object, and it is subject to the same authorization as everything else.
 *
 * IT HOLDS PERSONAL DATA, WHICH IS THE WHOLE POINT AND ALSO THE WHOLE RISK. The
 * original source of a message includes its body, so the reading side is gated
 * on the intake role ({@see IntakePolicy::mayRunIntake()}) rather than on being
 * logged in. Writing is done by the pipeline, which runs as the system.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\Service\Email\Filters\FilterVerdict;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records what happened to every inbound message.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) — the static calls here are named
 *  constructors and value-object factories (`InboundMessage::fromRow()`,
 *  `FilterVerdict::accept()`, `AuthenticationVerdict::unknown()`), which hold no
 *  state and exist so a caller cannot build a half-built value.
 */
class IntakeLog {

	use SearchesObjects;

	/**
	 * The config key naming the schema an entry is stored in.
	 */
	public const SCHEMA_KEY = 'mail_intake_entry_schema';

	/**
	 * The outcome of an entry that is waiting for a person.
	 */
	public const OUTCOME_QUARANTINED = 'quarantined';

	/**
	 * The outcome of an entry a person released into a case.
	 */
	public const OUTCOME_RELEASED = 'released';

	/**
	 * The outcome of an entry that became a case.
	 */
	public const OUTCOME_CASE = 'case';

	/**
	 * The outcome of an entry nothing could place.
	 */
	public const OUTCOME_INBOX = 'inbox';

	/**
	 * The outcome of an entry that was refused.
	 */
	public const OUTCOME_REFUSED = 'refused';

	/**
	 * The outcome of an entry that was sent on to another body.
	 */
	public const OUTCOME_FORWARDED = 'forwarded';

	/**
	 * The outcome of an entry that was filed in another folder.
	 */
	public const OUTCOME_MOVED = 'moved';

	/**
	 * How much of an original is kept, in bytes.
	 *
	 * A raw message can carry tens of megabytes of attachment, and the dispute
	 * this log settles is about headers and text. The cap is stated here so
	 * that a truncated original reads as a decision rather than as corruption:
	 * every truncated entry says so in `originalTruncated`.
	 */
	public const ORIGINAL_LIMIT_BYTES = 262144;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register and schema resolution.
	 * @param ITimeFactory    $time            Clock.
	 * @param LoggerInterface $logger          Logger.
	 * @param CaseTimeline    $timeline        The one seam that writes a timeline entry.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
		private readonly CaseTimeline $timeline,
	) {
	}//end __construct()

	/**
	 * Whether the log has somewhere to write.
	 *
	 * @return boolean True when the register and schema resolve.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function isConfigured(): bool {
		return ($this->settingsService->getObjectService() !== null
			&& $this->settingsService->getConfigValue('register') !== ''
			&& $this->settingsService->getConfigValue(self::SCHEMA_KEY) !== '');
	}//end isConfigured()

	/**
	 * Record one message and what happened to it.
	 *
	 * Answers the stored entry's id so a caller can point a case at it, and ''
	 * when nothing could be stored. A failure to record is logged and never
	 * thrown: a message that was handled correctly but not written down is
	 * better than a message that was not handled because the log was full.
	 *
	 * @param InboundMessage        $message The message.
	 * @param FilterVerdict         $verdict The filter's decision.
	 * @param array<string, string> $results The four authentication results.
	 * @param string                $outcome What became of it, one of the OUTCOME_ values.
	 * @param string                $reason  Why, in a sentence a handler can read.
	 * @param string                $caseId  The case it became, or ''.
	 *
	 * @return string The entry id, or '' when nothing was stored.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function record(
		InboundMessage $message,
		FilterVerdict $verdict,
		array $results,
		string $outcome,
		string $reason,
		string $caseId = '',
	): string {
		$target = $this->target();
		if ($target === null) {
			$this->logger->warning(
				'Dossiq: the intake log is not provisioned, so a processed message was not recorded',
				['messageId' => $message->messageId, 'outcome' => $outcome]
			);
			return '';
		}

		$payload = $this->messageFields(message: $message)
			+ $this->verdictFields(verdict: $verdict, results: $results)
			+ ['outcome' => $outcome, 'reason' => $reason, 'case' => $caseId]
			+ $this->originalFields(message: $message);

		$entryId = $this->saveEntry(
			write: $target + ['object' => $payload],
			failure: ['Dossiq: writing an intake log entry failed', ['messageId' => $message->messageId]]
		);
		if ($entryId === null) {
			return '';
		}

		$this->recordOnTimeline(message: $message, placed: ['outcome' => $outcome, 'caseId' => $caseId, 'entryId' => $entryId]);

		return $entryId;
	}//end record()

	/**
	 * Record a message that arrived on a channel rather than in the mailbox.
	 *
	 * 🔴 THIS IS THE SURFACE, AND IT IS ALSO THE DUPLICATE CHECK. integriq's
	 * channel adapters hand a message over as `IntakeMessageRoutedEvent`, and
	 * whatever dossiq decides has to be readable by the intake worker who
	 * already reads this page. Writing only to `nextcloud.log` would put the
	 * answer where the person who has to give it cannot reach, which is the
	 * failure this log was built to end. {@see ChannelIntake} then reads its
	 * own entries back to recognise a second delivery.
	 *
	 * 🔴 NO TIMELINE ENTRY, DELIBERATELY. {@see self::recordOnTimeline()}
	 * writes `TimelineKinds::MAIL_IN`, and a Teams message is not an incoming
	 * e-mail. The kinds are DECLARED with their required properties and
	 * provisioned once, so a channel kind is a declaration and a migration
	 * rather than a constant, and it is a change of its own. Until it lands
	 * the case carries `intakeChannel` and the message lives here.
	 *
	 * The mail-shaped columns stay empty on purpose: an entry with no
	 * `mailMessageId` and a `channel` is how a reader tells the two kinds of
	 * entry apart without a second schema.
	 *
	 * @param string $channel          The channel id, as integriq's adapter names it.
	 * @param string $channelMessageId The channel's own id for the message.
	 * @param string $sender           The correspondent, as the channel gave it.
	 * @param string $subject          A one-line description of the message.
	 * @param string $outcome          What became of it, one of the OUTCOME_ values.
	 * @param string $reason           Why, in a sentence a handler can read.
	 * @param string $caseId           The case it became, or ''.
	 *
	 * @return string The entry id, or '' when nothing was stored.
	 *
	 * @spec openspec/specs/intake-from-a-channel/spec.md
	 */
	public function recordChannelMessage(
		string $channel,
		string $channelMessageId,
		string $sender,
		string $subject,
		string $outcome,
		string $reason,
		string $caseId = '',
	): string {
		$target = $this->target();
		if ($target === null) {
			$this->logger->warning(
				'Dossiq: the intake log is not provisioned, so a channel message was not recorded',
				['channel' => $channel, 'message' => $channelMessageId, 'outcome' => $outcome]
			);
			return '';
		}

		$payload = array_map(
			static fn (string $value): string => mb_substr($value, 0, 255),
			['channel' => $channel, 'channelMessageId' => $channelMessageId, 'sender' => $sender, 'subject' => $subject]
		) + [
			'receivedAt' => $this->time->getDateTime()->format(DATE_ATOM),
			'outcome' => $outcome,
			'reason' => $reason,
			'case' => $caseId,
		];

		return ($this->saveEntry(
			write: $target + ['object' => $payload],
			failure: ['Dossiq: writing a channel intake log entry failed', ['channel' => $channel, 'message' => $channelMessageId]]
		) ?? '');
	}//end recordChannelMessage()

	/**
	 * The entry this channel already holds for this message, when there is one.
	 *
	 * 🔴 BARE KEYS, NOT `filter[channel]`. OpenRegister's objects endpoint
	 * reads a bare property name and answers the EMPTY SET for a `filter[...]`
	 * one, with no error either way (openregister#3611). An empty set here
	 * reads as "this message is new", so the wrong spelling would open a
	 * second case on every duplicate and report success both times.
	 *
	 * @param string $channel          The channel id.
	 * @param string $channelMessageId The channel's own id for the message.
	 *
	 * @return array<string, mixed>|null The entry, or null when this message is new.
	 *
	 * @spec openspec/specs/intake-from-a-channel/spec.md
	 */
	public function findChannelEntry(string $channel, string $channelMessageId): ?array {
		if (trim($channel) === '' || trim($channelMessageId) === '') {
			return null;
		}

		$rows = $this->search(
			filters: [
				'channel' => $channel,
				'channelMessageId' => $channelMessageId,
				'_limit' => 1,
			]
		);

		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findChannelEntry()

	/**
	 * Put a message that reached a case on that case's timeline.
	 *
	 * Only a message that BECAME a case gets a line. A message the filters
	 * refused, junked or bounced has no case to hang on, and the intake log
	 * page is where those are read; putting them somewhere else would make
	 * the case timeline a spam folder.
	 *
	 * The entry is INTERNAL even though a citizen wrote the message. It
	 * carries the intake outcome and points at the log entry, which hold the
	 * filter verdict and the four authentication results: facts about this
	 * instance rather than about the sender, and not the applicant's to read.
	 *
	 * @param InboundMessage                                         $message The message.
	 * @param array{outcome: string, caseId: string, entryId: string} $placed  What became of it, the case it reached
	 *                                                                        (or '') and the log entry just written.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-history-surface/spec.md
	 */
	private function recordOnTimeline(InboundMessage $message, array $placed): void {
		$caseId = $placed['caseId'];
		if (trim($caseId) === '') {
			return;
		}

		$subject = mb_substr($message->subject, 0, 255);
		$text = trim($subject);
		if ($text === '') {
			$text = 'Bericht ontvangen zonder onderwerp';
		}

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::MAIL_IN,
			message: $text,
			fields: [
				'sender' => $message->senderAddress(),
				'subject' => $subject,
				'outcome' => $placed['outcome'],
				'intakeEntryId' => $placed['entryId'],
			],
			visibility: CaseTimeline::INTERNAL,
		);
	}//end recordOnTimeline()

	/**
	 * Write a few fields onto an entry that already exists.
	 *
	 * @param string               $entryId The entry.
	 * @param array<string, mixed> $changes The fields to write.
	 *
	 * @return boolean True when the write landed.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function amend(string $entryId, array $changes): bool {
		$target = $this->target();
		if ($entryId === '' || $target === null) {
			return false;
		}

		try {
			$patched = $this->patchObjectAsArray(
				objectService: $target['objectService'],
				register: $target['register'],
				schema: $target['schema'],
				id: $entryId,
				changes: $changes
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: amending an intake log entry failed',
				['entry' => $entryId, 'error' => $e->getMessage()]
			);
			return false;
		}

		return ($patched !== null);
	}//end amend()

	/**
	 * One entry.
	 *
	 * @param string $entryId The entry.
	 *
	 * @return array<string, mixed>|null The entry, or null.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function find(string $entryId): ?array {
		$target = $this->target();
		if ($entryId === '' || $target === null) {
			return null;
		}

		try {
			return $this->findObjectAsArray(
				objectService: $target['objectService'],
				register: $target['register'],
				schema: $target['schema'],
				id: $entryId
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: reading an intake log entry failed',
				['entry' => $entryId, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end find()

	/**
	 * The entries matching a search.
	 *
	 * @param array<string, mixed> $filters Object-field filters, plus `_limit`.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function search(array $filters = []): array {
		$target = $this->target();
		if ($target === null) {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $target['objectService'],
				register: $target['register'],
				schema: $target['schema'],
				filters: $filters
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: searching the intake log failed',
				['error' => $e->getMessage()]
			);
			return [];
		}
	}//end search()

	/**
	 * Where entries are stored, or null when the log is not provisioned.
	 *
	 * @return array{objectService: object, register: string, schema: string}|null The store, register and schema.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function target(): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue(self::SCHEMA_KEY);
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		return ['objectService' => $objectService, 'register' => $register, 'schema' => $schema];
	}//end target()

	/**
	 * Store one entry and answer its id; a failure is logged, never thrown.
	 *
	 * @param array<string, mixed>                       $write   The target plus the `object` to store.
	 * @param array{0: string, 1: array<string, string>} $failure The log line and its context for a failed write.
	 *
	 * @return string|null The stored entry's id ('' when it has none), or null when nothing was stored.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function saveEntry(array $write, array $failure): ?string {
		$stored = null;
		try {
			$stored = $this->saveObjectAsArray(
				objectService: $write['objectService'],
				register: $write['register'],
				schema: $write['schema'],
				object: $write['object']
			);
		} catch (Throwable $e) {
			$this->logger->error($failure[0], $failure[1] + ['error' => $e->getMessage()]);
		}

		if ($stored === null) {
			return null;
		}

		return (string)($stored['@self']['id'] ?? ($stored['id'] ?? ''));
	}//end saveEntry()

	/**
	 * The entry fields that describe the message itself.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return array<string, mixed> Identity, correspondents, subject and dates.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function messageFields(InboundMessage $message): array {
		return [
			'mailMessageId' => $message->messageId,
			'accountId' => $message->accountId,
			'mailbox' => $message->mailbox,
			'uid' => $message->uid,
			'sender' => $message->senderAddress(),
			'recipient' => InboundMessage::addressIn(value: $message->to),
			'subject' => mb_substr($message->subject, 0, 255),
			'receivedAt' => $this->time->getDateTime()->format(DATE_ATOM),
			'sentAt' => $message->sentAt,
		];
	}//end messageFields()

	/**
	 * The entry fields that hold the filter's decision and the authentication results.
	 *
	 * @param FilterVerdict         $verdict The filter's decision.
	 * @param array<string, string> $results The four authentication results.
	 *
	 * @return array<string, string> The verdict, then spf, dkim, dmarc and threading.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function verdictFields(FilterVerdict $verdict, array $results): array {
		return [
			'decidingFilter' => $verdict->filterName,
			'filterOutcome' => $verdict->outcome,
			'filterReason' => $verdict->reason,
			'spfResult' => ($results['spf'] ?? AuthenticationResult::UNAVAILABLE),
			'dkimResult' => ($results['dkim'] ?? AuthenticationResult::UNAVAILABLE),
			'dmarcResult' => ($results['dmarc'] ?? AuthenticationResult::UNAVAILABLE),
			'threadingResult' => ($results['threading'] ?? AuthenticationResult::UNAVAILABLE),
		];
	}//end verdictFields()

	/**
	 * The kept original, cut at ORIGINAL_LIMIT_BYTES, and whether it was cut.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return array{original: string, originalTruncated: bool} The original fields.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function originalFields(InboundMessage $message): array {
		if (strlen($message->source) > self::ORIGINAL_LIMIT_BYTES) {
			return ['original' => substr($message->source, 0, self::ORIGINAL_LIMIT_BYTES), 'originalTruncated' => true];
		}

		return ['original' => $message->source, 'originalTruncated' => false];
	}//end originalFields()
}//end class
