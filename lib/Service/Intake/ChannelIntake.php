<?php

/**
 * What dossiq does with a message integriq routed at a case.
 *
 * Integriq receives on a channel, matches a routing rule and dispatches
 * `IntakeMessageRoutedEvent`, then reads its result slot back. An empty slot
 * makes integriq hold the message with "No app opened a case for this
 * message". That sentence was honest while nothing listened. The moment
 * something listens it becomes a lie, so every path through this class writes
 * to {@see IntakeLog}, which is the page an intake worker already reads.
 *
 * 🔴 WHAT MAKES A SECOND DELIVERY THE SAME MESSAGE IS `channelId` PLUS
 * `externalId`, NEVER integriq's message uuid. `IntakeRoutingService::route()`
 * saves a fresh `intake_message` per delivery, so two deliveries of one Teams
 * post carry two uuids. A duplicate check on the uuid would open a second
 * case, answer the slot, and report success on both. `externalId` is the
 * channel's OWN id for the message and is what survives the retry.
 *
 * 🔴 THE MESSAGE NEVER NAMES THE IDENTITY, THE ASSIGNEE OR THE STATUS. The
 * write runs inside `ObjectService::runAsSystem()`, because a webhook arrives
 * with no Nextcloud session and an anonymous create is fail-closed
 * (OpenRegister #1955). That elevation is why the payload is BUILT here from
 * an allowlist rather than passed through: `runAsSystemIfAvailable()` says in
 * its own docblock to wrap only operations whose inputs come from code, never
 * user-supplied request data, and an inbound channel message is exactly
 * user-supplied request data. So the message may fill a title and a
 * description, and it may not fill an assignee, a status, a grant or a
 * register.
 *
 * 🔴 integriq CALLS ITS TARGET A CASE TYPE AND RESOLVES IT AS A SCHEMA
 * (`SchemaMapper::find()`). dossiq has ONE case schema and holds the case type
 * as an object on `case.caseType`, so the rule's target names the schema and
 * the case type arrives inside the mapped payload. A rule that names no case
 * type, or one this instance does not have, is refused with that sentence
 * rather than opening a case with no type, which would sit outside every
 * status lens with no deadline and look like a working intake.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Opens a case for a routed channel message, or refuses it in writing.
 *
 * @spec openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md
 */
class ChannelIntake {

	use SearchesObjects;

	/**
	 * The fields a message may fill on the case it opens.
	 *
	 * Descriptive only. Everything that decides who may read the case, who
	 * holds it, or where it is in its lifecycle is dossiq's to set, because
	 * the write runs elevated and the values came in off a webhook.
	 *
	 * @var array<int, string>
	 */
	private const ACCEPTED_FIELDS = [
		'title',
		'description',
		'initiatorName',
		'initiatorSourceId',
		'communicationChannel',
	];

	/**
	 * The title a message with nothing to name it gets.
	 *
	 * @var string
	 */
	private const UNTITLED = 'Bericht via kanaal';

	/**
	 * Constructor.
	 *
	 * @param SettingsService     $settingsService Register and schema resolution.
	 * @param IntakeLog           $log             The surface, and the duplicate ledger.
	 * @param CaseDateNormaliser  $dates           The one path a date is written by.
	 * @param LoggerInterface     $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IntakeLog $log,
		private readonly CaseDateNormaliser $dates,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a routed message is addressed at a dossiq case at all.
	 *
	 * A rule naming another app's schema is not this app's to answer, and
	 * answering it would take a message away from whoever it was for.
	 *
	 * @param string $targetSchema The schema the rule named.
	 *
	 * @return boolean True when it names this app's case schema.
	 *
	 * @spec openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md
	 */
	public function isForACase(string $targetSchema): bool {
		$target = strtolower(trim($targetSchema));
		if ($target === '') {
			return false;
		}

		$configured = strtolower(trim($this->settingsService->getConfigValue('case_schema')));

		return ($target === 'case' || ($configured !== '' && $target === $configured));
	}//end isForACase()

	/**
	 * Open a case for one routed message, or say why not.
	 *
	 * @param array<string, mixed> $message       The message, as integriq stored it.
	 * @param array<string, mixed> $targetPayload The rule's field mapping, already applied.
	 * @param string               $ruleName      The rule that matched, for the log.
	 *
	 * @return string The case id, or '' when no case was opened.
	 *
	 * @spec openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md
	 */
	public function caseFor(array $message, array $targetPayload, string $ruleName = ''): string {
		$channel = trim((string)($message['channelId'] ?? ''));
		$externalId = trim((string)($message['externalId'] ?? ''));

		// A message with no channel or no id of its own cannot be recognised
		// on a second delivery. Opening a case for it means accepting that a
		// retry opens another one, so it is refused instead, in writing.
		if ($channel === '' || $externalId === '') {
			$this->refuse(
				channel: $channel,
				externalId: $externalId,
				message: $message,
				reason: 'The message carries no channel or no id of its own, so a second delivery of it '
					. 'could not be told apart from a new message.'
			);
			return '';
		}

		// 🔴 THE DUPLICATE CHECK COMES BEFORE EVERYTHING, AND IT NEEDS A PLACE
		// TO READ. An unprovisioned log cannot answer "have I seen this", and
		// opening a case on an unanswerable question is how a retry silently
		// doubles a citizen's report.
		if ($this->log->isConfigured() === false) {
			$this->logger->warning(
				'ChannelIntake: the intake log is not provisioned, so a channel message was refused rather '
					. 'than opened without a duplicate check',
				['channel' => $channel, 'message' => $externalId]
			);
			return '';
		}

		$seen = $this->log->findChannelEntry(channel: $channel, channelMessageId: $externalId);
		if ($seen !== null) {
			$existing = trim((string)($seen['case'] ?? ''));
			$this->logger->info(
				'ChannelIntake: this message was already handled, so no second case was opened',
				['channel' => $channel, 'message' => $externalId, 'case' => $existing]
			);

			// The same case is answered back, so integriq marks the second
			// delivery routed at the case the first one opened rather than
			// holding it as unanswered.
			return $existing;
		}

		$caseTypeId = $this->caseTypeIdIn(payload: $targetPayload);
		if ($caseTypeId === '') {
			$this->refuse(
				channel: $channel,
				externalId: $externalId,
				message: $message,
				reason: 'The routing rule "' . $ruleName . '" names no case type this instance has. '
					. 'A dossiq rule maps a case type id onto `caseType`.'
			);
			return '';
		}

		$caseId = $this->write(caseTypeId: $caseTypeId, message: $message, payload: $targetPayload);
		if ($caseId === '') {
			$this->refuse(
				channel: $channel,
				externalId: $externalId,
				message: $message,
				reason: 'The case could not be written. The reason is in the log beside this entry.'
			);
			return '';
		}

		$this->log->recordChannelMessage(
			channel: $channel,
			channelMessageId: $externalId,
			sender: $this->correspondent(message: $message),
			subject: $this->titleFor(message: $message, payload: $targetPayload),
			outcome: IntakeLog::OUTCOME_CASE,
			reason: 'Opened by routing rule "' . $ruleName . '".',
			caseId: $caseId
		);

		return $caseId;
	}//end caseFor()

	/**
	 * Write the case, elevated, from fields this app chose.
	 *
	 * @param string               $caseTypeId The case type, already resolved.
	 * @param array<string, mixed> $message    The message.
	 * @param array<string, mixed> $payload    The rule's mapped payload.
	 *
	 * @return string The new case's id, or '' when nothing was written.
	 *
	 * @spec openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md
	 */
	private function write(string $caseTypeId, array $message, array $payload): string {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $caseSchema === '') {
			$this->logger->warning(
				'ChannelIntake: the register is not configured, so a routed message opened no case',
				['caseType' => $caseTypeId]
			);
			return '';
		}

		$object = [
			'title' => $this->titleFor(message: $message, payload: $payload),
			'caseType' => $caseTypeId,
			'intakeChannel' => trim((string)($message['channelId'] ?? '')),
			// The statutory clock starts the day it came in. `deadline` is
			// dateAdd(startDate, caseType.processingDeadline), so a startDate
			// left out means no deadline at all.
			'startDate' => $this->receivedDate(message: $message),
		];

		foreach (self::ACCEPTED_FIELDS as $field) {
			$value = ($payload[$field] ?? null);
			if (is_scalar($value) === false) {
				continue;
			}

			$text = trim((string)$value);
			if ($text !== '') {
				$object[$field] = $text;
			}
		}

		$description = trim((string)($message['text'] ?? ''));
		if (($object['description'] ?? '') === '' && $description !== '') {
			$object['description'] = $description;
		}

		// 🔴 THE CORRESPONDENT IS WRITTEN AS A FIELD, NOT LEFT IN THE PROSE.
		// Awb 4:3a owes whoever wrote in a confirmation of receipt, and
		// AcknowledgementService reads `initiatorSourceId`. A correspondent
		// living only inside the description is an address no code can reach,
		// which is the defect UnmatchedMailIntake wrote down for mail. NO
		// PERSON RECORD IS CREATED: a handle that wrote in once is not a
		// citizen record, and resolving one to a party dossiq holds is a
		// lookup this change does not do.
		$correspondent = $this->correspondent(message: $message);
		if (($object['initiatorSourceId'] ?? '') === '' && $correspondent !== '') {
			$object['initiatorSourceId'] = $correspondent;
			$object['initiatorType'] = 'contact';
		}

		// A case with no status is off every status-filtered lens, has no
		// available transitions and reads as Unknown in the header. The status
		// comes from the case TYPE and never from the message.
		$initial = $this->initialStatusOf(caseTypeId: $caseTypeId);
		if ($initial !== '') {
			$object['status'] = $initial;
		}

		try {
			$created = $this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): mixed => $objectService->saveObject(
					object: $object,
					register: $register,
					schema: $caseSchema,
				)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'ChannelIntake: could not open a case for a routed message',
				['caseType' => $caseTypeId, 'error' => $e->getMessage()]
			);
			return '';
		}

		$row = $this->objectAsArray(value: $created);
		$caseId = (string)($row['@self']['id'] ?? ($row['id'] ?? ''));
		if ($caseId === '') {
			$this->logger->error(
				'ChannelIntake: the case was written but answered no id, so the message cannot be linked to it',
				['caseType' => $caseTypeId]
			);
			return '';
		}

		return $caseId;
	}//end write()

	/**
	 * The case type the rule mapped, when this instance has it.
	 *
	 * @param array<string, mixed> $payload The rule's mapped payload.
	 *
	 * @return string The case type id, or '' when it names none or none resolves.
	 *
	 * @spec openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md
	 */
	private function caseTypeIdIn(array $payload): string {
		$caseTypeId = trim((string)($payload['caseType'] ?? ''));
		if ($caseTypeId === '') {
			return '';
		}

		if ($this->caseType(caseTypeId: $caseTypeId) === null) {
			return '';
		}

		return $caseTypeId;
	}//end caseTypeIdIn()

	/**
	 * The case type object, read elevated, or null when it is not there.
	 *
	 * @param string $caseTypeId The case type id.
	 *
	 * 🔴 IT CATCHES NOTHING, DELIBERATELY. A case type that is not there comes
	 * back as null from `findObjectAsArray()`, which is the answer this method
	 * exists to give, and it becomes a refusal a handler can read. Anything
	 * else that throws here is the register being unreachable, and swallowing
	 * that would turn "I could not look" into "there is no such case type",
	 * which is a different sentence and a wrong one. The listener catches it,
	 * the slot stays empty, and integriq holds the message until somebody can
	 * look again.
	 *
	 * @return array<string, mixed>|null The case type, or null when it is not there.
	 */
	private function caseType(string $caseTypeId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_type_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		return $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): ?array => $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseTypeId
			)
		);
	}//end caseType()

	/**
	 * The status a case of this type starts in.
	 *
	 * @param string $caseTypeId The case type id.
	 *
	 * @return string The status, or '' when the type declares none.
	 */
	private function initialStatusOf(string $caseTypeId): string {
		$caseType = $this->caseType(caseTypeId: $caseTypeId);

		return trim((string)($caseType['initialStatus'] ?? ''));
	}//end initialStatusOf()

	/**
	 * Record a refusal where the intake worker reads it.
	 *
	 * @param string               $channel    The channel id.
	 * @param string               $externalId The channel's id for the message.
	 * @param array<string, mixed> $message    The message.
	 * @param string               $reason     Why, in a sentence a handler can read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md
	 */
	private function refuse(string $channel, string $externalId, array $message, string $reason): void {
		$this->logger->warning(
			'ChannelIntake: a routed message opened no case',
			['channel' => $channel, 'message' => $externalId, 'reason' => $reason]
		);

		$this->log->recordChannelMessage(
			channel: $channel,
			channelMessageId: $externalId,
			sender: $this->correspondent(message: $message),
			subject: $this->titleFor(message: $message, payload: []),
			outcome: IntakeLog::OUTCOME_REFUSED,
			reason: $reason
		);
	}//end refuse()

	/**
	 * A one-line name for the message.
	 *
	 * @param array<string, mixed> $message The message.
	 * @param array<string, mixed> $payload The rule's mapped payload.
	 *
	 * @return string The title.
	 */
	private function titleFor(array $message, array $payload): string {
		$mapped = ($payload['title'] ?? null);
		if (is_scalar($mapped) === true && trim((string)$mapped) !== '') {
			return mb_substr(trim((string)$mapped), 0, 255);
		}

		$text = trim((string)($message['text'] ?? ''));
		if ($text === '') {
			return self::UNTITLED;
		}

		$firstLine = trim((string)(preg_split('/\R/', $text)[0] ?? ''));

		if ($firstLine === '') {
			return self::UNTITLED;
		}

		return mb_substr($firstLine, 0, 120);
	}//end titleFor()

	/**
	 * Who wrote, as the channel gave them.
	 *
	 * 🔴 NO PERSON RECORD IS CREATED HERE. A telephone number that wrote in
	 * once is not a citizen record, and a register filling up with them is
	 * worse than a case naming a string.
	 *
	 * @param array<string, mixed> $message The message.
	 *
	 * @return string The correspondent, or ''.
	 */
	private function correspondent(array $message): string {
		$correspondent = ($message['correspondent'] ?? null);
		if (is_scalar($correspondent) === true) {
			return trim((string)$correspondent);
		}

		if (is_array($correspondent) === false) {
			return '';
		}

		foreach (['address', 'handle', 'id', 'name'] as $key) {
			$value = ($correspondent[$key] ?? null);
			if (is_scalar($value) === true && trim((string)$value) !== '') {
				return trim((string)$value);
			}
		}

		return '';
	}//end correspondent()

	/**
	 * The day the message came in, as `Y-m-d`.
	 *
	 * Read through {@see CaseDateNormaliser}, which is the one path a date is
	 * written by. A private parser here would be a second rule for what a date
	 * is, and the statutory clock starts on this value: an instant read in the
	 * process time zone rather than the administered one moves a deadline by a
	 * day between two servers.
	 *
	 * An unreadable stamp falls back to today rather than refusing. The
	 * message did arrive; the day it says so is the channel's to get right.
	 *
	 * @param array<string, mixed> $message The message.
	 *
	 * @return string The date.
	 */
	private function receivedDate(array $message): string {
		$received = $this->dates->toCalendarDateOrNull(value: ($message['receivedAt'] ?? null));

		return ($received ?? $this->dates->today()->format('Y-m-d'));
	}//end receivedDate()

	/**
	 * Coerce whatever the object service answered into an array.
	 *
	 * @param mixed $value The answer.
	 *
	 * @return array<string, mixed> The row, or an empty array.
	 */
	private function objectAsArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialized = $value->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return [];
	}//end objectAsArray()
}//end class
