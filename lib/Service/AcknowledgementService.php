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
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Confirms receipt, records it on the case, and shows the duty when it fails.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 */
class AcknowledgementService {

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
	 * @param CaseTypeResolver            $caseTypeResolver  The effective case type, parents included.
	 * @param CaseTypeStore               $store             Reference resolution for the case's case type.
	 * @param CaseTypeAcknowledgement     $declaration       What the case type declares about confirming receipt.
	 * @param TermijnService              $termService       The statutory term bound to the case, when there is one.
	 * @param TermijnNotificationService  $notifications     The template renderer and the notification router.
	 * @param CaseContactDirectory        $contacts          The addresses registered on a case.
	 * @param PortalContributionProvider  $portal            The one list of case fields a citizen may see.
	 * @param CaseFieldWriter             $writer            Partial writes to the stored case.
	 * @param LoggerInterface             $logger            The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeResolver $caseTypeResolver,
		private readonly CaseTypeStore $store,
		private readonly CaseTypeAcknowledgement $declaration,
		private readonly TermijnService $termService,
		private readonly TermijnNotificationService $notifications,
		private readonly CaseContactDirectory $contacts,
		private readonly PortalContributionProvider $portal,
		private readonly CaseFieldWriter $writer,
		private readonly LoggerInterface $logger,
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
	 * @throws RefusedException When the case carries no address to confirm to.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function acknowledge(string $caseId, int $attempt = 1): array {
		$case = $this->readCase(caseId: $caseId);
		if ($case === null) {
			throw RefusedException::indeterminate(
				rule: 'acknowledgement-case-unreadable',
				sentence: 'We could not read the case, so receipt could not be confirmed.',
			);
		}

		$caseType = $this->caseTypeOf(case: $case);

		if ($this->declaration->owesAcknowledgement(case: $case, caseType: $caseType) === false) {
			$duty = ['required' => false, 'status' => self::STATUS_NOT_REQUIRED];
			$this->write(caseId: $caseId, changes: ['acknowledgementDuty' => $duty]);

			return ['sent' => false, 'reason' => 'not-required', 'duty' => $duty];
		}

		$duty = $this->dutyOn(case: $case);
		if (($duty['status'] ?? '') === self::STATUS_MET) {
			return ['sent' => false, 'reason' => 'already-met', 'duty' => $duty];
		}

		$recipient = $this->recipientFor(case: $case);
		$channel = $this->declaration->channelFor(case: $case, caseType: $caseType);
		$withheld = $this->declaration->contentStaysOnPlatform(caseType: $caseType);

		$sentAt = (new DateTimeImmutable())->format('c');
		$term = $this->termService->getTermijnInstanceForZaak(caseId: $caseId);

		$payload = $this->notifications->sendTermijnNotification(
			CaseTypeAcknowledgement::TEMPLATE,
			(string)($term['id'] ?? ($term['uuid'] ?? '')),
			$recipient,
			$this->contextFor(
				case: $case,
				caseType: $caseType,
				term: $term,
				channel: $channel,
				withheld: $withheld,
			),
		);

		$record = [
			'moment' => CaseTypeAcknowledgement::MOMENT_RECEIVED,
			'channel' => $channel,
			'recipient' => $recipient,
			'template' => CaseTypeAcknowledgement::TEMPLATE,
			'templateVersion' => self::TEMPLATE_VERSION,
			'sentAt' => $sentAt,
			'contentWithheld' => $withheld,
		];

		$met = [
			'required' => true,
			'status' => self::STATUS_MET,
			'channel' => $channel,
			'recipient' => $recipient,
			'sentAt' => $sentAt,
			'attempts' => $attempt,
			'lastError' => '',
		];

		$this->write(
			caseId: $caseId,
			changes: [
				'acknowledgementDuty' => $met,
				'outboundCommunications' => $this->appendRecord(case: $case, record: $record),
			],
		);

		$this->logger->info(
			'Dossiq acknowledgement: receipt of case {case} confirmed through {channel}',
			['case' => $caseId, 'channel' => $channel, 'attempt' => $attempt],
		);

		return ['sent' => true, 'duty' => $met, 'record' => $record, 'payload' => $payload];
	}//end acknowledge()

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
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function recordFailedAttempt(string $caseId, string $sentence, int $attempt): bool {
		$more = ($attempt < self::MAX_ATTEMPTS);

		$this->write(
			caseId: $caseId,
			changes: [
				'acknowledgementDuty' => [
					'required' => true,
					'status' => ($more === true) ? self::STATUS_PENDING : self::STATUS_UNMET,
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
				'outcome' => ($more === true) ? 'another is due' : 'the duty now reads unmet',
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
	 * @param string $by     The user id of the person recording it.
	 *
	 * @return array<string, mixed> The duty as it now reads.
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function recordMetAnotherWay(string $caseId, string $how, string $by): array {
		$case = $this->readCase(caseId: $caseId);
		if ($case === null) {
			throw RefusedException::indeterminate(
				rule: 'acknowledgement-case-unreadable',
				sentence: 'We could not read the case, so nothing was recorded.',
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
			'metBy' => $by,
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
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function dutyFor(string $caseId): array {
		$case = $this->readCase(caseId: $caseId);
		if ($case === null) {
			return [];
		}

		return $this->dutyOn(case: $case);
	}//end dutyFor()

	/**
	 * The address this acknowledgement goes to.
	 *
	 * 🔴 A REFUSAL, NOT AN EMPTY SEND. `CaseContactDirectory` says in its own
	 * docblock that none of the fields it reads is declared on today's `case`
	 * schema, so for most cases it answers nothing at all. An empty recipient
	 * handed to a mail transport is a message that goes nowhere and reports
	 * success, which is exactly the shape that let this duty ship unperformed
	 * the first time. So a case with no address refuses, with a status and a
	 * sentence a handler can act on, and the duty stays visible on the case.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return string The address.
	 *
	 * @throws RefusedException When nothing on the case is an address.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function recipientFor(array $case): string {
		$address = $this->addressOn(case: $case);
		if ($address !== '') {
			return $address;
		}

		throw new RefusedException(
			rule: 'acknowledgement-no-address',
			sentence: 'This case carries no address, so receipt could not be confirmed. '
				. 'Add the applicant\'s address, or record that you confirmed it another way.',
			status: RefusedException::STATUS_UNPROCESSABLE,
		);
	}//end recipientFor()

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
		$addresses = $this->contacts->collectAddresses(caseData: $case);
		if ($addresses !== []) {
			return (string)reset($addresses);
		}

		$source = trim((string)($case['initiatorSourceId'] ?? ''));
		if ($source !== '' && filter_var($source, FILTER_VALIDATE_EMAIL) !== false) {
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
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function quotableFieldsOf(array $case): array {
		$allowed = $this->portal->citizenCaseFields();

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
			'locale' => $this->declaration->languageFor(caseType: $caseType),
			'case' => (string)($quotable['identifier'] ?? ''),
			'subject' => (string)($quotable['title'] ?? ''),
			'endDate' => (string)($term['endDateCurrent'] ?? ($quotable['deadline'] ?? '')),
			'hasTerm' => ($term !== null && ($term['endDateCurrent'] ?? '') !== ''),
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
		$reference = $this->store->referenceId(value: ($case['caseType'] ?? ''));
		if ($reference === '') {
			return [];
		}

		return $this->caseTypeResolver->effectiveCaseType(caseTypeId: $reference);
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

		return (is_array($duty) === true) ? $duty : [];
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
	 * @return array<string, mixed>|null The case row, or null.
	 */
	private function readCase(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');

		if ($objectService === null || $register === '' || $schema === '' || $caseId === '') {
			return null;
		}

		try {
			return $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq acknowledgement: case {case} could not be read',
				['case' => $caseId, 'reason' => $e->getMessage()],
			);

			return null;
		}
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
			$this->writer->write(
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
