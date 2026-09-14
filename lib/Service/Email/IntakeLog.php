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
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records what happened to every inbound message.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
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
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
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
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue(self::SCHEMA_KEY);
		if ($objectService === null || $register === '' || $schema === '') {
			$this->logger->warning(
				'Dossiq: the intake log is not provisioned, so a processed message was not recorded',
				['messageId' => $message->messageId, 'outcome' => $outcome]
			);
			return '';
		}

		$original = $message->source;
		$truncated = false;
		if (strlen($original) > self::ORIGINAL_LIMIT_BYTES) {
			$original = substr($original, 0, self::ORIGINAL_LIMIT_BYTES);
			$truncated = true;
		}

		$payload = [
			'mailMessageId' => $message->messageId,
			'accountId' => $message->accountId,
			'mailbox' => $message->mailbox,
			'uid' => $message->uid,
			'sender' => $message->senderAddress(),
			'recipient' => InboundMessage::addressIn(value: $message->to),
			'subject' => mb_substr($message->subject, 0, 255),
			'receivedAt' => $this->time->getDateTime()->format(DATE_ATOM),
			'sentAt' => $message->sentAt,
			'decidingFilter' => $verdict->filterName,
			'filterOutcome' => $verdict->outcome,
			'filterReason' => $verdict->reason,
			'spfResult' => ($results['spf'] ?? AuthenticationResult::UNAVAILABLE),
			'dkimResult' => ($results['dkim'] ?? AuthenticationResult::UNAVAILABLE),
			'dmarcResult' => ($results['dmarc'] ?? AuthenticationResult::UNAVAILABLE),
			'threadingResult' => ($results['threading'] ?? AuthenticationResult::UNAVAILABLE),
			'outcome' => $outcome,
			'reason' => $reason,
			'case' => $caseId,
			'original' => $original,
			'originalTruncated' => $truncated,
		];

		try {
			$stored = $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $payload
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: writing an intake log entry failed',
				['messageId' => $message->messageId, 'error' => $e->getMessage()]
			);
			return '';
		}

		if ($stored === null) {
			return '';
		}

		return (string)($stored['@self']['id'] ?? ($stored['id'] ?? ''));
	}//end record()

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
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue(self::SCHEMA_KEY);
		if ($entryId === '' || $objectService === null || $register === '' || $schema === '') {
			return false;
		}

		try {
			$patched = $this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
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
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue(self::SCHEMA_KEY);
		if ($entryId === '' || $objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			return $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
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
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue(self::SCHEMA_KEY);
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
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
}//end class
