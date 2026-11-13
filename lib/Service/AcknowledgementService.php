<?php

/**
 * Dossiq acknowledgement of receipt (Awb 4:3a).
 *
 * Confirms receipt of an electronically submitted message, records on the case
 * that it was confirmed, and leaves an unmet duty visible on the case when it
 * could not be.
 *
 * WHAT WAS ALREADY HERE AND WHY IT DID NOTHING. The text, the renderer and the
 * requirement all shipped: `lib/Settings/templates/ontvangstbevestiging.json`,
 * {@see TermijnNotificationService::renderTemplate()} and REQ-TERM-008. Nothing
 * triggered any of them. The only caller of `sendTermijnNotification()` is a
 * queued job, nothing enqueued one on case creation, and REQ-TERM-008's
 * scenario reads "WHEN the ontvangstbevestiging is sent" without ever saying
 * who sends it. A requirement with no trigger reads green and performs nothing,
 * which is how a statutory duty sat unshipped behind a spec describing it.
 *
 * SENDING NEVER BLOCKS THE CASE. Creating a case must not fail because a mail
 * server is down: a duty deferred by four minutes is met, and a case that
 * failed to be created is not. So the listener queues and this runs off the
 * request, and every failure here lands on the case rather than only in the
 * log.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Exception\NoticeNotSentException;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\AcknowledgementServiceInterface;
use OCA\Dossiq\Service\Notification\RequesterNoticeSender;
use OCA\Dossiq\Service\Support\RefusesWhenIndeterminate;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Confirms receipt, records it on the case, and shows the duty when it fails.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One acknowledgement needs the
 * declaration on the case type, the statutory term, the notifier, the contact
 * directory, the portal's field list and the field writer. Splitting the class
 * to lower the count would spread one statutory duty over several files, which
 * is the thing this change exists to stop.
 *
 * @spec openspec/specs/burger-notifications/spec.md
 */
class AcknowledgementService implements AcknowledgementServiceInterface {

	use RefusesWhenIndeterminate;
	use SearchesObjects;

	/**
	 * The version of the acknowledgement template these records were rendered from.
	 *
	 * Written into every record so a message a citizen queries a year from now
	 * can be read back against the wording they actually received.
	 *
	 * @var string
	 */
	public const TEMPLATE_VERSION = '2.0.0';

	/**
	 * How often a send is retried before the duty reads unmet.
	 *
	 * @var integer
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * The duty does not apply to this case.
	 *
	 * @var string
	 */
	public const STATUS_NOT_REQUIRED = 'not-required';

	/**
	 * The duty applies and a send is still coming.
	 *
	 * @var string
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Receipt was confirmed, by the app or by a person another way.
	 *
	 * @var string
	 */
	public const STATUS_MET = 'met';

	/**
	 * Every attempt is spent and nobody has confirmed receipt.
	 *
	 * @var string
	 */
	public const STATUS_UNMET = 'unmet';

	/**
	 * Constructor.
	 *
	 * @param SettingsService             $settingsService   Bridge to OpenRegister and the configured schemas.
	 * @param AcknowledgementDeclaration $caseTypes The case type resolver and store, and the case type's acknowledgement declaration.
	 * @param AcknowledgementTerms $terms The term instances and the term notifications.
	 * @param AcknowledgementDelivery $delivery The case contacts, the portal contribution and the case field writer.
	 * @param LoggerInterface             $logger            The logger.
	 * @param CaseTimeline                $timeline          The one seam that writes a timeline entry.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Eleven collaborators, each
	 * injected rather than reached for, which is what makes the acknowledgement
	 * testable without a running register. Bundling them into a parameter object
	 * would hide the dependency list rather than shorten it.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly AcknowledgementDeclaration $caseTypes,
		private readonly AcknowledgementTerms $terms,
		private readonly AcknowledgementDelivery $delivery,
		private readonly LoggerInterface $logger,
		private readonly CaseTimeline $timeline,
	) {
	}//end __construct()

	/**
	 * Confirm receipt of this case, once.
	 *
	 * Sending twice is worse than sending late: the citizen reads the second
	 * one as a second case. So a case whose duty already reads met returns the
	 * record it already has and sends nothing.
	 *
	 * @param string  $caseId  The case UUID.
	 * @param integer $attempt Which attempt this is, counting from one.
	 *
	 * @return array<string, mixed> `{sent: bool, reason?: string, duty: array, record?: array}`.
	 *
	 * @throws RefusedException When the case carries no address, or no transport took the acknowledgement.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-the-acknowledgement-duty-is-met-only-when-the-acknowledgement-went-out-req-wrn-003
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-a-case-type-may-send-its-acknowledgement-by-e-mail-as-well-req-ack-copy-001
	 */
	public function acknowledge(string $caseId, int $attempt = 1): array {
		$case = $this->readCase(caseId: $caseId) ?? throw new RefusedException(
			rule: 'acknowledgement-register-not-configured',
			sentence: 'We could not read the case, so receipt could not be confirmed.',
			status: RefusedException::STATUS_INDETERMINATE,
		);
		$caseType = $this->caseTypeOf(case: $case);

		$settled = $this->nothingToSend(caseId: $caseId, case: $case, caseType: $caseType);
		if ($settled !== null) {
			return $settled;
		}

		$ack = $this->prepared(ack: ['caseId' => $caseId, 'case' => $case, 'caseType' => $caseType]);

		// The sender picks the channel (portal inbox, digital post, e-mail),
		// calls its transport and records the result on the case. A notice no
		// transport took throws, so nothing below runs for it (REQ-WRN-003).
		try {
			$payload = $this->sendNotice(ack: $ack, context: $ack['context']);
		} catch (NoticeNotSentException $e) {
			throw $this->notSent(caseId: $caseId, notice: $e);
		}

		$dispatch = (array)($payload['dispatch'] ?? []);
		$met = [
			'required' => true,
			'status' => self::STATUS_MET,
			'channel' => (string)($dispatch['channel'] ?? ''),
			'recipient' => $ack['recipient'],
			'sentAt' => (string)($dispatch['sentAt'] ?? ''),
			'attempts' => $attempt,
			'lastError' => '',
		];
		$this->recordMet(ack: $ack, met: $met);

		return ['sent' => true, 'duty' => $met, 'record' => (array)($dispatch['record'] ?? []), 'payload' => $payload];
	}//end acknowledge()

	/**
	 * The acknowledgement ready to send: its address, its term and the context the template reads.
	 *
	 * @param array{caseId: string, case: array<string, mixed>, caseType: array<string, mixed>} $ack The case and its case type.
	 *
	 * @return array<string, mixed> The same, with `recipient`, `term` and `context`.
	 */
	private function prepared(array $ack): array {
		$recipient = $this->addressOn(case: $ack['case']);
		$channel = $this->caseTypes->declaration->channelFor(case: $ack['case'], caseType: $ack['caseType']);
		$withheld = $this->caseTypes->declaration->contentStaysOnPlatform(caseType: $ack['caseType']);
		$term = $this->terms->termService->getTermijnInstanceForZaak(caseId: $ack['caseId']);

		$context = $this->contextFor(
			case: $ack['case'],
			caseType: $ack['caseType'],
			term: $term,
			channel: $channel,
			withheld: $withheld,
		);
		$context['recordExtras'] = [
			'recipient' => $recipient,
			'templateVersion' => self::TEMPLATE_VERSION,
			'contentWithheld' => $withheld,
		];

		return $ack + ['recipient' => $recipient, 'term' => $term, 'context' => $context];
	}//end prepared()

	/**
	 * Hand the acknowledgement to the sender.
	 *
	 * @param array<string, mixed> $ack     The prepared acknowledgement.
	 * @param array<string, mixed> $context The context to send it with.
	 *
	 * @return array<string, mixed> What the sender answered.
	 *
	 * @throws NoticeNotSentException When no transport took it.
	 */
	private function sendNotice(array $ack, array $context): array {
		return $this->terms->notifications->sendTermijnNotification(
			CaseTypeAcknowledgement::TEMPLATE,
			(string)($ack['term']['id'] ?? ($ack['term']['uuid'] ?? '')),
			$ack['recipient'],
			$context,
			$ack['case'],
		);
	}//end sendNotice()

	/**
	 * Record a met duty on the case, send the e-mail copy when declared, and write the public line.
	 *
	 * @param array<string, mixed> $ack The prepared acknowledgement.
	 * @param array<string, mixed> $met The met duty.
	 *
	 * @return void
	 */
	private function recordMet(array $ack, array $met): void {
		$this->write(caseId: $ack['caseId'], changes: ['acknowledgementDuty' => $met]);

		$declaration = $this->caseTypes->declaration;
		if ($met['channel'] !== RequesterNoticeSender::CHANNEL_EMAIL && $declaration->sendsEmailCopy(caseType: $ack['caseType']) === true) {
			$this->sendEmailCopy(ack: $ack);
		}

		// PUBLIC, because the acknowledgement is the one message on this path
		// the applicant has already received. Withholding its own line from
		// the timeline the portal reads would tell a citizen nothing was sent
		// on the very act the law requires be sent to them. Written only here,
		// after a transport took it, with the transport's own moment.
		$this->timeline->record(
			caseId: $ack['caseId'],
			kind: TimelineKinds::ACKNOWLEDGEMENT,
			message: 'Ontvangstbevestiging verzonden',
			fields: [
				'channel' => (string)$met['channel'],
				'recipient' => (string)$met['recipient'],
				'template' => CaseTypeAcknowledgement::TEMPLATE,
				'sentAt' => $met['sentAt'],
			],
			visibility: CaseTimeline::PUBLIC_ENTRY,
		);

		$this->logger->info(
			'Dossiq acknowledgement: receipt of case {case} confirmed through {channel}',
			['case' => $ack['caseId'], 'channel' => $met['channel'], 'attempt' => $met['attempts']],
		);
	}//end recordMet()

	/**
	 * The same acknowledgement by e-mail as well, when the case type asks for it.
	 *
	 * The sender records the copy on the case as a message of its own. A copy
	 * no transport took is recorded as not sent there and leaves the duty,
	 * which the first channel already met, as it is.
	 *
	 * @param array<string, mixed> $ack The prepared acknowledgement.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-a-case-type-may-send-its-acknowledgement-by-e-mail-as-well-req-ack-copy-001
	 */
	private function sendEmailCopy(array $ack): void {
		try {
			$this->sendNotice(ack: $ack, context: $ack['context'] + ['channels' => [RequesterNoticeSender::CHANNEL_EMAIL]]);
		} catch (NoticeNotSentException $e) {
			$this->logger->warning(
				'Dossiq acknowledgement: the e-mail copy for case {case} was not sent: {reason}',
				['case' => (string)($ack['case']['id'] ?? ''), 'reason' => $e->getMessage()],
			);
		}
	}//end sendEmailCopy()

	/**
	 * The answer for a case that gets no acknowledgement now, or null when one is due.
	 *
	 * A draft is not received yet (decision 180): nothing is sent and nothing
	 * is recorded, because the duty starts when the draft is sent and the
	 * listener queues this again at that moment. A case that owes none records
	 * that. A case whose duty already reads met is left as it is.
	 *
	 * @param string               $caseId   The case UUID.
	 * @param array<string, mixed> $case     The case row.
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<string, mixed>|null `{sent: false, reason, duty}`, or null when the acknowledgement is due.
	 */
	private function nothingToSend(string $caseId, array $case, array $caseType): ?array {
		if ($this->isDraft(case: $case) === true) {
			return ['sent' => false, 'reason' => 'draft', 'duty' => $this->dutyOn(case: $case)];
		}

		if ($this->caseTypes->declaration->owesAcknowledgement(case: $case, caseType: $caseType) === false) {
			$duty = ['required' => false, 'status' => self::STATUS_NOT_REQUIRED];
			$this->write(caseId: $caseId, changes: ['acknowledgementDuty' => $duty]);

			return ['sent' => false, 'reason' => 'not-required', 'duty' => $duty];
		}

		$duty = $this->dutyOn(case: $case);
		if (($duty['status'] ?? '') === self::STATUS_MET) {
			return ['sent' => false, 'reason' => 'already-met', 'duty' => $duty];
		}

		return null;
	}//end nothingToSend()

	/**
	 * Whether the case is still a draft in OpenRegister.
	 *
	 * @param array<string, mixed> $case The case row, with its `@self` envelope when the store gives one.
	 *
	 * @return bool True for a draft.
	 */
	private function isDraft(array $case): bool {
		$self = ($case['@self'] ?? null);

		return (is_array($self) === true && ($self['status'] ?? null) === 'draft');
	}//end isDraft()

	/**
	 * The refusal for an acknowledgement no transport took, after an internal line says so.
	 *
	 * INTERNAL, never public: the citizen's timeline must not read that we
	 * confirmed receipt, and telling them we tried and failed is not what the
	 * portal is for. The handler reads why on the case.
	 *
	 * @param string                 $caseId The case UUID.
	 * @param NoticeNotSentException $notice What the sender answered.
	 *
	 * @return RefusedException The refusal, carrying the transport's sentence.
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-the-acknowledgement-duty-is-met-only-when-the-acknowledgement-went-out-req-wrn-003
	 */
	private function notSent(string $caseId, NoticeNotSentException $notice): RefusedException {
		$delivery = $notice->getDelivery();
		$code = $notice->getReasonCode();

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::ACKNOWLEDGEMENT,
			message: 'Ontvangstbevestiging niet verzonden',
			fields: [
				'template' => CaseTypeAcknowledgement::TEMPLATE,
				'reasonCode' => $code,
				'reason' => $notice->getMessage(),
				'channelsTried' => count((array)($delivery['channelsTried'] ?? [])),
			],
			visibility: CaseTimeline::INTERNAL,
		);

		if ($code === 'no-channel') {
			return new RefusedException(
				rule: 'acknowledgement-no-address',
				sentence: 'This case carries no address, so receipt could not be confirmed. '
					. 'Add the applicant\'s address, or record that you confirmed it another way.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return new RefusedException(
			rule: 'acknowledgement-not-sent',
			sentence: $notice->getMessage(),
			status: RefusedException::STATUS_INDETERMINATE,
		);
	}//end notSent()

	/**
	 * Record that an attempt failed, and whether any are left.
	 *
	 * The duty stays pending while a retry is still coming and reads unmet once
	 * they are spent, because at that point a person has to perform it by hand
	 * and needs to be able to find the case. Neither state is a log line.
	 *
	 * @param string  $caseId   The case UUID.
	 * @param string  $sentence What went wrong, as one sentence.
	 * @param integer $attempt  Which attempt failed, counting from one.
	 *
	 * @return boolean TRUE when another attempt is due.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 */
	public function recordFailedAttempt(string $caseId, string $sentence, int $attempt): bool {
		$more = ($attempt < self::MAX_ATTEMPTS);

		$status = self::STATUS_UNMET;
		$outcome = 'the duty now reads unmet';
		if ($more === true) {
			$status = self::STATUS_PENDING;
			$outcome = 'another is due';
		}

		$this->write(
			caseId: $caseId,
			changes: [
				'acknowledgementDuty' => [
					'required' => true,
					'status' => $status,
					'attempts' => $attempt,
					'lastError' => $sentence,
				],
			],
		);

		$this->logger->warning(
			'Dossiq acknowledgement: attempt {attempt} for case {case} failed, {outcome}',
			[
				'case' => $caseId,
				'attempt' => $attempt,
				'outcome' => $outcome,
				'reason' => $sentence,
			],
		);

		return $more;
	}//end recordFailedAttempt()

	/**
	 * Record that a person confirmed receipt another way.
	 *
	 * A duty met by post is met. What the case has to carry is who said so and
	 * when, because an auditor asking "did we confirm receipt" is entitled to
	 * an answer that names a person rather than a green tick.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $how    How receipt was confirmed, in the recorder's words.
	 * @param string $recordedBy The user id of the person recording it.
	 *
	 * @return array<string, mixed> The duty as it now reads.
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 */
	public function recordMetAnotherWay(string $caseId, string $how, string $recordedBy): array {
		$case = $this->readCase(caseId: $caseId);
		if ($case === null) {
			throw new RefusedException(
				rule: 'acknowledgement-register-not-configured',
				sentence: 'We could not read the case, so nothing was recorded.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		$how = trim($how);
		if ($how === '') {
			throw new RefusedException(
				rule: 'acknowledgement-needs-a-reason',
				sentence: 'Say how receipt was confirmed, so the case records more than a tick.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$metAt = (new DateTimeImmutable())->format('c');
		$duty = [
			'required' => true,
			'status' => self::STATUS_MET,
			'metBy' => $recordedBy,
			'metAt' => $metAt,
			'metHow' => $how,
		];

		$record = [
			'moment' => CaseTypeAcknowledgement::MOMENT_RECEIVED,
			'channel' => 'recorded-by-hand',
			'recipient' => $this->addressOn(case: $case),
			'template' => CaseTypeAcknowledgement::TEMPLATE,
			'templateVersion' => self::TEMPLATE_VERSION,
			'sentAt' => $metAt,
			'contentWithheld' => false,
		];

		$this->write(
			caseId: $caseId,
			changes: [
				'acknowledgementDuty' => $duty,
				'outboundCommunications' => $this->appendRecord(case: $case, record: $record),
			],
		);

		return $duty;
	}//end recordMetAnotherWay()

	/**
	 * The duty as the case carries it.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The duty, empty when the case cannot be read.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 */
	public function dutyFor(string $caseId): array {
		$case = $this->readCase(caseId: $caseId);
		if ($case === null) {
			return [];
		}

		return $this->dutyOn(case: $case);
	}//end dutyFor()

	/**
	 * The first usable address on the case, or the empty string.
	 *
	 * The portal's pseudonymous subject reference counts: a citizen reading in
	 * the portal is reachable there without an e-mail address existing at all.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return string The address, or ''.
	 */
	private function addressOn(array $case): string {
		$addresses = array_merge(
			$this->caseTypes->declaration->addressesOn(case: $case, caseType: $this->caseTypeOf(case: $case)),
			$this->delivery->contacts->collectAddresses(caseData: $case)
		);
		if ($addresses !== []) {
			return (string)reset($addresses);
		}

		$source = trim((string)($case['initiatorSourceId'] ?? ''));
		if (filter_var($source, FILTER_VALIDATE_EMAIL) !== false) {
			return strtolower($source);
		}

		return trim((string)($case['portalSubject'] ?? ''));
	}//end addressOn()

	/**
	 * What the template may quote back to the citizen.
	 *
	 * 🔴 ONE DEFINITION OF WHAT THE CITIZEN MAY SEE, READ FROM WHERE THE PORTAL
	 * READS IT. The acknowledgement quotes the case back, so it has to read the
	 * same list as the portal and not a second one of its own. Two lists that
	 * agree today diverge quietly, and the first time anyone notices is a
	 * data-protection incident rather than a bug.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return array<string, mixed> Only the fields the citizen may see.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 */
	public function quotableFieldsOf(array $case): array {
		$allowed = $this->delivery->portal->citizenCaseFields();

		$quotable = [];
		foreach ($allowed as $field) {
			if (array_key_exists($field, $case) === true) {
				$quotable[$field] = $case[$field];
			}
		}

		return $quotable;
	}//end quotableFieldsOf()

	/**
	 * The render context the template reads.
	 *
	 * @param array<string, mixed>      $case     The case row.
	 * @param array<string, mixed>      $caseType The effective case type.
	 * @param array<string, mixed>|null $term     The bound statutory term, when there is one.
	 * @param string                    $channel  The channel it goes out through.
	 * @param boolean                   $withheld Whether the content stays on the platform.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function contextFor(
		array $case,
		array $caseType,
		?array $term,
		string $channel,
		bool $withheld,
	): array {
		$quotable = $this->quotableFieldsOf(case: $case);

		return [
			'locale' => $this->caseTypes->declaration->languageFor(caseType: $caseType),
			'case' => (string)($quotable['identifier'] ?? ''),
			'subject' => (string)($quotable['title'] ?? ''),
			'endDate' => (string)($term['endDateCurrent'] ?? ($quotable['deadline'] ?? '')),
			// THE STORED STAMP, NOT A RECOMPUTATION. The mail has to name the
			// same start the case carries, or a citizen quoting it back is
			// quoting a date the term does not use. An absent stamp stays
			// absent: the template leaves the lines out rather than guessing.
			'receivedAt' => (string)($quotable['receivedAt'] ?? ''),
			'termStartsAt' => (string)($quotable['termStartsAt'] ?? ''),
			'receivedOutsideWorkingHours' => (($quotable['receivedOutsideWorkingHours'] ?? false) === true),
			'contentWithheld' => $withheld,
			'notificationChannel' => $channel,
			'contact' => (string)($caseType['responsible'] ?? ''),
			'addressee' => ['type' => 'burger'],
		];
	}//end contextFor()

	/**
	 * The case type behind this case, parents resolved.
	 *
	 * A `case` carries its case type as a UUID; the resolver wants an id, and
	 * a type that derives its declaration from a parent needs the effective row
	 * rather than its own.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return array<string, mixed> The effective case type, empty when unreadable.
	 */
	private function caseTypeOf(array $case): array {
		$reference = $this->caseTypes->store->referenceId(value: ($case['caseType'] ?? ''));
		if ($reference === '') {
			return [];
		}

		return $this->caseTypes->caseTypeResolver->effectiveCaseType(caseTypeId: $reference);
	}//end caseTypeOf()

	/**
	 * The duty block on a case row.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return array<string, mixed> The duty, empty when the case carries none.
	 */
	private function dutyOn(array $case): array {
		$duty = ($case['acknowledgementDuty'] ?? null);
		if (is_array($duty) === false) {
			return [];
		}

		return $duty;
	}//end dutyOn()

	/**
	 * This case's outbound communications with one more on the end.
	 *
	 * @param array<string, mixed> $case   The case row.
	 * @param array<string, mixed> $record The record to append.
	 *
	 * @return array<int, array<string, mixed>> The list.
	 */
	private function appendRecord(array $case, array $record): array {
		$existing = ($case['outboundCommunications'] ?? null);
		if (is_array($existing) === false) {
			$existing = [];
		}

		$existing[] = $record;

		return array_values($existing);
	}//end appendRecord()

	/**
	 * Read one case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed>|null The case row, or null when the register is not configured.
	 *
	 * @throws RefusedException When the register is configured but the read fails.
	 */
	private function readCase(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');

		if ($objectService === null || $register === '' || $schema === '' || $caseId === '') {
			return null;
		}

		// 🔴 A READ THAT THREW IS NOT A CASE WITHOUT A DUTY. Answering null here
		// made "the register is down" and "this case owes no acknowledgement"
		// the same sentence, and the second one closes a statutory duty. The
		// refusal carries 503, which is neither yes nor no.
		return $this->readOrRefuse(
			read: fn (): ?array => $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId,
			),
			what: 'case ' . $caseId . ' for its acknowledgement of receipt',
			rule: 'acknowledgement-case-unreadable',
			sentence: 'We could not read the case, so we cannot say whether receipt was confirmed.',
		);
	}//end readCase()

	/**
	 * Apply changes to the stored case.
	 *
	 * Through {@see CaseFieldWriter} and never a full save: the duty and the
	 * record are this service's own two fields, and a full save from a stale
	 * read would erase whatever a handler wrote while the send was queued.
	 *
	 * @param string               $caseId  The case UUID.
	 * @param array<string, mixed> $changes The fields this service owns.
	 *
	 * @return void
	 */
	private function write(string $caseId, array $changes): void {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');

		if ($objectService === null || $register === '' || $schema === '') {
			$this->logger->error(
				'Dossiq acknowledgement: the register is not configured, so case {case} records nothing',
				['case' => $caseId],
			);

			return;
		}

		try {
			$this->delivery->writer->write(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				case: ['id' => $caseId],
				changes: $changes,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq acknowledgement: case {case} could not record the acknowledgement',
				['case' => $caseId, 'reason' => $e->getMessage()],
			);
		}
	}//end write()
}//end class
