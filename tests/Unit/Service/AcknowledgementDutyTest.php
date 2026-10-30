<?php

/**
 * AcknowledgementService unit tests: the duty, the record and the refusal.
 *
 * The three intake paths that owe an Awb 4:3a confirmation all end in a `case`
 * object, so what tells them apart is `intakeChannel`, and each is driven here
 * as its own case: the portal writes `website`, the mail intake writes `email`,
 * the ZGW API intake writes `zgw-api`. A case typed at the balie is driven too,
 * because sending one anyway is its own defect.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Service\AcknowledgementService;
use OCA\Dossiq\Service\Notification\RequesterNoticeSender;
use OCA\Dossiq\Service\Termijn\TermNoticeSender;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\CaseTypeAcknowledgement;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Unit\Service\Transitions\SourceL10n;
use OCA\Dossiq\Woo\WooReceivedAnswers;
use OCA\Dossiq\Woo\WooRequestForm;
use OCA\Dossiq\Tests\Support\MakesRealTermNoticeSender;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An in-memory case store with the PATCH seam the writer prefers.
 *
 * `patchObject` is implemented on purpose: the fallback path in
 * {@see \OCA\Dossiq\Service\Support\SearchesObjects} re-reads and full-saves,
 * and testing against the fallback would hide a clobber the real seam prevents.
 */
class AcknowledgementCaseStore {

	/**
	 * Stored cases, keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $cases = [];

	/**
	 * Find one case.
	 *
	 * @param string $id       The case id.
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 *
	 * @return array<string, mixed>|null The case, or null.
	 */
	public function find(string $id, string $register = '', string $schema = ''): ?array {
		return ($this->cases[$id] ?? null);
	}//end find()

	/**
	 * Apply a partial change to one case.
	 *
	 * @param string               $objectId The case id.
	 * @param array<string, mixed> $data     The fields to apply.
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 *
	 * @return array<string, mixed> The case as it now stands.
	 */
	public function patchObject(
		string $objectId,
		array $data,
		string $register = '',
		string $schema = '',
	): array {
		$this->cases[$objectId] = array_merge(($this->cases[$objectId] ?? []), $data);

		return $this->cases[$objectId];
	}//end patchObject()
}//end class

/**
 * Confirming receipt, recording it, and refusing when there is nowhere to send.
 *
 * @covers \OCA\Dossiq\Service\AcknowledgementService
 * @uses \OCA\Dossiq\Service\Support\LetterDays
 *
 * @uses \OCA\Dossiq\Portal\PortalContributionProvider
 * @uses \OCA\Dossiq\Service\Termijn\TermNoticeSender
 * @uses \OCA\Dossiq\Service\Email\OutboundCaseMail
 * @uses \OCA\Dossiq\Service\Email\MailTransportPolicy
 * @uses \OCA\Dossiq\Service\Email\SenderIdentity
 * @uses \OCA\Dossiq\Service\Email\IntakeAccount
 * @uses \OCA\Dossiq\Service\CaseFieldWriter
 * @uses \OCA\Dossiq\Service\CaseTypeAcknowledgement
 * @uses \OCA\Dossiq\Service\Email\CaseContactDirectory
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\TermijnNotificationService
 * @uses \OCA\Dossiq\Service\Termijn\TermLetters
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 * @uses \OCA\Dossiq\Service\Notification\RequesterNoticeSender
 * @uses \OCA\Dossiq\Exception\NoticeNotSentException
 * @uses \OCA\Dossiq\Service\Email\CaseMailOptOut
 * @uses \OCA\Dossiq\Service\OptOutGate
 * @uses \OCA\Dossiq\Support\FleetAppId
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Woo\WooReceivedAnswers
 * @uses \OCA\Dossiq\Woo\WooRequestForm
 * @uses   \OCA\Dossiq\Service\Support\ReadableDay
 */
class AcknowledgementDutyTest extends TestCase {
	use MakesRealTermNoticeSender;

	use MakesCaseDateNormaliser;

	/**
	 * The in-memory case store.
	 *
	 * @var AcknowledgementCaseStore
	 */
	private AcknowledgementCaseStore $store;

	/**
	 * The bound statutory term, when the test wants one.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $term = ['id' => 'ti-1', 'case' => '2026-0042', 'endDateCurrent' => '2026-11-01'];

	/**
	 * What the case type declares.
	 *
	 * @var array<string, mixed>
	 */
	private array $caseType = [];

	/**
	 * The mocked timeline seam.
	 *
	 * @var CaseTimeline|MockObject
	 */
	private CaseTimeline $timeline;

	/**
	 * Set up the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new AcknowledgementCaseStore();
		$this->timeline = $this->createMock(originalClassName: CaseTimeline::class);
	}//end setUp()

	/**
	 * The service, wired against the in-memory store.
	 *
	 * @param TermNoticeSender|null      $sender    The e-mail transport; a mock that always sends when null.
	 * @param RequesterNoticeSender|null $requester The channel picker; the real one over the store when null.
	 *
	 * @return AcknowledgementService The service under test.
	 */
	private function service(?TermNoticeSender $sender = null, ?RequesterNoticeSender $requester = null): AcknowledgementService {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => 'case',
					default => '',
				};
			}
		);

		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(fn (): array => $this->caseType);

		$store = $this->createMock(originalClassName: CaseTypeStore::class);
		$store->method('referenceId')->willReturnCallback(
			static fn (mixed $value): string => (string)$value
		);

		$terms = $this->createMock(originalClassName: TermijnService::class);
		$terms->method('getTermijnInstanceForZaak')->willReturnCallback(fn (): ?array => $this->term);

		// The sender reads the same term back by its id, the way production does,
		// so a term whose case link is a UUID reaches the letter the way it does live.
		$noticeTerms = $this->createMock(originalClassName: TermijnService::class);
		$noticeTerms->method('getTermijnInstance')->willReturnCallback(fn (): ?array => $this->term);

		return new AcknowledgementService(
			settingsService: $settings,
			caseTypeResolver: $resolver,
			store: $store,
			declaration: new CaseTypeAcknowledgement(l10n: new SourceL10n()),
			termService: $terms,
			notifications: new TermijnNotificationService(
				termService: $noticeTerms,
				sender: ($sender ?? $this->sentNotices()),
				logger: $logger,
				requester: ($requester ?? new RequesterNoticeSender(
					email: ($sender ?? $this->sentNotices()),
					settings: $settings,
					writer: new CaseFieldWriter(),
				)),
			),
			contacts: new CaseContactDirectory(),
			portal: new PortalContributionProvider(),
			writer: new CaseFieldWriter(),
			logger: $logger,
			timeline: $this->timeline,
		);
	}//end service()

	/**
	 * Seed one case in the store.
	 *
	 * @param array<string, mixed> $overrides Fields to set on the case.
	 *
	 * @return string The case id.
	 */
	private function seedCase(array $overrides = []): string {
		$case = array_merge(
			[
				'id' => 'case-1',
				'caseType' => 'ct-1',
				'identifier' => '2026-0042',
				'title' => 'Dakkapel Kerkstraat 12',
				'intakeChannel' => 'website',
				'email' => 'aanvrager@example.nl',
			],
			$overrides
		);

		$this->store->cases[$case['id']] = $case;

		return (string)$case['id'];
	}//end seedCase()

	/**
	 * Every electronic intake path sends one, once, and records it.
	 *
	 * @param string $channel The intake channel.
	 *
	 * @return void
	 *
	 * @dataProvider electronicIntakePaths
	 */
	public function testAnElectronicIntakeIsConfirmedOnceAndRecorded(string $channel): void {
		$caseId = $this->seedCase(overrides: ['intakeChannel' => $channel]);
		$service = $this->service();

		$first = $service->acknowledge(caseId: $caseId);
		self::assertTrue(condition: $first['sent'], message: $channel . ' owes a confirmation of receipt');
		self::assertSame(expected: AcknowledgementService::STATUS_MET, actual: $first['duty']['status']);

		$stored = $this->store->cases[$caseId];
		self::assertCount(expectedCount: 1, haystack: $stored['outboundCommunications']);

		$record = $stored['outboundCommunications'][0];
		self::assertSame(expected: 'acknowledgement', actual: $record['moment']);
		self::assertSame(expected: 'sent', actual: $record['status']);
		self::assertSame(expected: 'email', actual: $record['channel']);
		self::assertSame(expected: 'aanvrager@example.nl', actual: $record['recipient']);
		self::assertSame(expected: 'ontvangstbevestiging', actual: $record['template']);
		self::assertSame(expected: AcknowledgementService::TEMPLATE_VERSION, actual: $record['templateVersion']);
		self::assertNotSame(expected: '', actual: (string)$record['sentAt']);

		// Sending twice is worse than sending late: the citizen reads the
		// second one as a second case.
		$second = $service->acknowledge(caseId: $caseId);
		self::assertFalse(condition: $second['sent']);
		self::assertSame(expected: 'already-met', actual: $second['reason']);
		self::assertCount(expectedCount: 1, haystack: $this->store->cases[$caseId]['outboundCommunications']);
	}//end testAnElectronicIntakeIsConfirmedOnceAndRecorded()

	/**
	 * The three electronic intake paths.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function electronicIntakePaths(): array {
		return [
			'the portal aanvraag' => ['website'],
			'the mail intake' => ['email'],
			'a Nextcloud Forms form' => ['forms'],
			'the API case creation' => ['zgw-api'],
		];
	}//end electronicIntakePaths()

	/**
	 * REQ-ACK-COPY-001: a case type that declares `emailCopy` asks the
	 * sender for a copy by e-mail; one that does not, does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-receipt-also-by-email/specs/burger-notifications/spec.md#requirement-a-case-type-may-send-its-acknowledgement-by-e-mail-as-well-req-ack-copy-001
	 */
	public function testACaseTypeDeclaresAnEmailCopy(): void {
		$asked = [];
		$requester = $this->createMock(originalClassName: RequesterNoticeSender::class);
		$requester->method('momentFor')->willReturn('acknowledgement');
		$requester->method('send')->willReturnCallback(
			static function (array $case, string $template, array $rendered, string $moment, array $options) use (&$asked): array {
				$asked[] = [(string)$case['id'], $options['channels']];
				$channel = 'portal-inbox';
				if ($options['channels'] !== null) {
					$channel = 'email';
				}

				return ['status' => 'sent', 'channel' => $channel, 'messageId' => 'm-1', 'sentAt' => '2026-10-16T10:00:00+02:00', 'record' => []];
			}
		);

		$this->caseType = ['acknowledgement' => ['emailCopy' => true]];
		$met = $this->service(requester: $requester)->acknowledge(caseId: $this->seedCase());

		$this->caseType = [];
		$this->service(requester: $requester)->acknowledge(caseId: $this->seedCase(overrides: ['id' => 'case-2']));

		self::assertSame(expected: [['case-1', null], ['case-1', ['email']], ['case-2', null]], actual: $asked);
		self::assertSame(expected: 'portal-inbox', actual: $met['duty']['channel'], message: 'the duty stays met by the first channel');
	}//end testACaseTypeDeclaresAnEmailCopy()

	/**
	 * REQ-ACK-COPY-001: a copy that does not go out leaves the duty met.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-receipt-also-by-email/specs/burger-notifications/spec.md#requirement-a-case-type-may-send-its-acknowledgement-by-e-mail-as-well-req-ack-copy-001
	 */
	public function testACopyThatIsNotSentLeavesTheDutyMet(): void {
		$requester = $this->createMock(originalClassName: RequesterNoticeSender::class);
		$requester->method('momentFor')->willReturn('acknowledgement');
		$requester->method('send')->willReturnCallback(
			static function (array $case, string $template, array $rendered, string $moment, array $options): array {
				if ($options['channels'] !== null) {
					return ['status' => 'not-sent', 'channel' => '', 'reasonCode' => 'no-channel', 'reason' => 'No address.', 'record' => []];
				}

				return ['status' => 'sent', 'channel' => 'portal-inbox', 'messageId' => 'm-1', 'sentAt' => '2026-10-16T10:00:00+02:00', 'record' => []];
			}
		);

		$this->caseType = ['acknowledgement' => ['emailCopy' => true]];
		$result = $this->service(requester: $requester)->acknowledge(caseId: $this->seedCase());

		self::assertTrue(condition: $result['sent']);
		self::assertSame(expected: AcknowledgementService::STATUS_MET, actual: $this->store->cases['case-1']['acknowledgementDuty']['status']);
	}//end testACopyThatIsNotSentLeavesTheDutyMet()

	/**
	 * A case typed at the balie is not mailed.
	 *
	 * @return void
	 */
	public function testACaseTypedAtTheBalieIsNotMailed(): void {
		$caseId = $this->seedCase(overrides: ['intakeChannel' => 'balie']);

		$result = $this->service()->acknowledge(caseId: $caseId);

		self::assertFalse(condition: $result['sent']);
		self::assertSame(expected: 'not-required', actual: $result['reason']);
		self::assertSame(
			expected: AcknowledgementService::STATUS_NOT_REQUIRED,
			actual: $this->store->cases[$caseId]['acknowledgementDuty']['status']
		);
		self::assertArrayNotHasKey(key: 'outboundCommunications', array: $this->store->cases[$caseId]);
	}//end testACaseTypedAtTheBalieIsNotMailed()

	/**
	 * A case type that leaves `forms` out of its channel list sends none for a forms case.
	 *
	 * Decision 236: on by default, and the case type can switch it off.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/burger-notifications/spec.md#requirement-the-acknowledgement-of-receipt-has-a-trigger-req-term-020
	 */
	public function testACaseTypeSwitchesTheFormsAcknowledgementOff(): void {
		$caseId = $this->seedCase(overrides: ['intakeChannel' => 'forms']);
		$this->caseType = ['acknowledgement' => ['intakeChannels' => ['email', 'website', 'zgw-api']]];
		$sender = $this->createMock(originalClassName: TermNoticeSender::class);
		$sender->expects(self::never())->method('send');

		$result = $this->service(sender: $sender)->acknowledge(caseId: $caseId);

		self::assertFalse(condition: $result['sent']);
		self::assertSame(expected: 'not-required', actual: $result['reason']);
		self::assertArrayNotHasKey(key: 'outboundCommunications', array: $this->store->cases[$caseId]);
	}//end testACaseTypeSwitchesTheFormsAcknowledgementOff()

	/**
	 * A draft is not confirmed and records nothing; the same case, sent, is confirmed once.
	 *
	 * The listener queues nothing for a draft. This is the service's own
	 * refusal, for a job that was queued some other way.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/burger-notifications/spec.md#requirement-the-acknowledgement-of-receipt-has-a-trigger-req-term-020
	 */
	public function testADraftIsNotConfirmedUntilItIsSent(): void {
		$caseId = $this->seedCase(overrides: ['intakeChannel' => 'forms', '@self' => ['status' => 'draft']]);
		$sender = $this->createMock(originalClassName: TermNoticeSender::class);
		$sender->expects(self::once())->method('send')
			->willReturn(['notificationChannel' => 'email', 'sent' => true, 'duplicate' => false]);
		$service = $this->service(sender: $sender);

		$draft = $service->acknowledge(caseId: $caseId);
		self::assertFalse(condition: $draft['sent']);
		self::assertSame(expected: 'draft', actual: $draft['reason']);
		self::assertArrayNotHasKey(key: 'acknowledgementDuty', array: $this->store->cases[$caseId]);
		self::assertArrayNotHasKey(key: 'outboundCommunications', array: $this->store->cases[$caseId]);

		$this->store->cases[$caseId]['@self'] = ['status' => 'active'];
		self::assertTrue(condition: $service->acknowledge(caseId: $caseId)['sent']);
		self::assertSame(expected: 'already-met', actual: $service->acknowledge(caseId: $caseId)['reason']);
	}//end testADraftIsNotConfirmedUntilItIsSent()

	/**
	 * An address that is not an address gets no mail: the case refuses as one without an address.
	 *
	 * A form's answer is typed by the sender. What fails the address check is
	 * never handed to a transport.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/burger-notifications/spec.md#requirement-the-acknowledgement-of-receipt-has-a-trigger-req-term-020
	 */
	public function testAnAddressThatFailsValidationIsNeverMailed(): void {
		$caseId = $this->seedCase(
			overrides: [
				'intakeChannel' => 'forms',
				'email' => 'jan at example',
				'initiator' => ['email' => 'jan@'],
				'initiatorSourceId' => 'jan.example.nl',
			]
		);
		$sender = $this->createMock(originalClassName: TermNoticeSender::class);
		$sender->expects(self::never())->method('send');

		try {
			$this->service(sender: $sender)->acknowledge(caseId: $caseId);
			self::fail(message: 'an address that fails validation must refuse rather than be mailed');
		} catch (RefusedException $e) {
			self::assertSame(expected: 'acknowledgement-no-address', actual: $e->getRule());
		}

		$records = $this->store->cases[$caseId]['outboundCommunications'];
		self::assertCount(expectedCount: 1, haystack: $records);
		self::assertSame(expected: 'not-sent', actual: $records[0]['status']);
		self::assertNotSame(expected: AcknowledgementService::STATUS_MET, actual: ($this->store->cases[$caseId]['acknowledgementDuty']['status'] ?? ''));
	}//end testAnAddressThatFailsValidationIsNeverMailed()

	/**
	 * A case with no address refuses, with a status and a sentence.
	 *
	 * 🔴 NOT AN EMPTY SEND. A recipient of '' handed to a mail transport is a
	 * message that goes nowhere and reports success, which is the exact shape
	 * that let this duty ship unperformed the first time.
	 *
	 * @return void
	 */
	public function testACaseWithNoAddressRefusesWithAStatusAndASentence(): void {
		$caseId = $this->seedCase(overrides: ['email' => null]);

		try {
			$this->service()->acknowledge(caseId: $caseId);
			self::fail(message: 'a case with no address must refuse rather than send nowhere');
		} catch (RefusedException $e) {
			self::assertSame(expected: 'acknowledgement-no-address', actual: $e->getRule());
			self::assertSame(expected: RefusedException::STATUS_UNPROCESSABLE, actual: $e->getStatus());
			self::assertStringContainsString(needle: 'no address', haystack: $e->getSentence());
		}

		// The case records the notice that did not go out, and why (REQ-WRN-006).
		$records = $this->store->cases[$caseId]['outboundCommunications'];
		self::assertCount(expectedCount: 1, haystack: $records);
		self::assertSame(expected: 'not-sent', actual: $records[0]['status']);
		self::assertSame(expected: 'no-channel', actual: $records[0]['reasonCode']);
		self::assertArrayNotHasKey(key: 'messageId', array: $records[0]);
	}//end testACaseWithNoAddressRefusesWithAStatusAndASentence()

	/**
	 * The address a mail-filed case carries is the sender it came from.
	 *
	 * @return void
	 */
	public function testTheMailIntakeSenderIsAnAddress(): void {
		$caseId = $this->seedCase(
			overrides: ['email' => null, 'intakeChannel' => 'email', 'initiatorSourceId' => 'Afzender@Example.NL']
		);

		$result = $this->service()->acknowledge(caseId: $caseId);

		self::assertTrue(condition: $result['sent']);
		self::assertSame(expected: 'afzender@example.nl', actual: $result['duty']['recipient']);
	}//end testTheMailIntakeSenderIsAnAddress()

	/**
	 * A failed attempt stays pending while retries are left, then reads unmet.
	 *
	 * @return void
	 */
	public function testAFailureIsPendingThenUnmetAndNeverOnlyALogLine(): void {
		$caseId = $this->seedCase();
		$service = $this->service();

		self::assertTrue(condition: $service->recordFailedAttempt(caseId: $caseId, sentence: 'SMTP refused', attempt: 1));
		self::assertSame(
			expected: AcknowledgementService::STATUS_PENDING,
			actual: $this->store->cases[$caseId]['acknowledgementDuty']['status']
		);

		self::assertFalse(
			condition: $service->recordFailedAttempt(
				caseId: $caseId,
				sentence: 'SMTP refused',
				attempt: AcknowledgementService::MAX_ATTEMPTS,
			)
		);

		$duty = $this->store->cases[$caseId]['acknowledgementDuty'];
		self::assertSame(expected: AcknowledgementService::STATUS_UNMET, actual: $duty['status']);
		self::assertSame(expected: 'SMTP refused', actual: $duty['lastError']);
		self::assertSame(expected: AcknowledgementService::MAX_ATTEMPTS, actual: $duty['attempts']);
	}//end testAFailureIsPendingThenUnmetAndNeverOnlyALogLine()

	/**
	 * A handler records that receipt was confirmed another way.
	 *
	 * The record has to name who said so and when, because an auditor asking
	 * "did we confirm receipt" is entitled to more than a green tick.
	 *
	 * @return void
	 */
	public function testAHandlerCanRecordThatItWasMetAnotherWay(): void {
		$caseId = $this->seedCase();
		$service = $this->service();
		$service->recordFailedAttempt(caseId: $caseId, sentence: 'SMTP refused', attempt: 3);

		$duty = $service->recordMetAnotherWay(caseId: $caseId, how: 'Confirmed by post', recordedBy: 'ruben');

		self::assertSame(expected: AcknowledgementService::STATUS_MET, actual: $duty['status']);
		self::assertSame(expected: 'ruben', actual: $duty['metBy']);
		self::assertSame(expected: 'Confirmed by post', actual: $duty['metHow']);
		self::assertNotSame(expected: '', actual: (string)$duty['metAt']);
		self::assertCount(expectedCount: 1, haystack: $this->store->cases[$caseId]['outboundCommunications']);
	}//end testAHandlerCanRecordThatItWasMetAnotherWay()

	/**
	 * Clearing the duty without saying how is refused.
	 *
	 * @return void
	 */
	public function testClearingTheDutyWithoutSayingHowIsRefused(): void {
		$caseId = $this->seedCase();

		$this->expectException(exception: RefusedException::class);
		$this->service()->recordMetAnotherWay(caseId: $caseId, how: '  ', recordedBy: 'ruben');
	}//end testClearingTheDutyWithoutSayingHowIsRefused()

	/**
	 * A case with no statutory term still confirms receipt, and says so.
	 *
	 * @return void
	 */
	public function testACaseWithNoTermStillConfirmsReceipt(): void {
		$this->term = null;
		$caseId = $this->seedCase();

		$result = $this->service()->acknowledge(caseId: $caseId);

		self::assertTrue(condition: $result['sent']);
		self::assertStringContainsString(needle: 'geen wettelijke beslistermijn', haystack: $result['payload']['body']);
	}//end testACaseWithNoTermStillConfirmsReceipt()

	/**
	 * A field the citizen may not see is not quoted back.
	 *
	 * The quotable set is the portal's own list, read from the portal rather
	 * than kept as a second copy that diverges quietly.
	 *
	 * @return void
	 */
	public function testAFieldTheCitizenMayNotSeeIsNotQuotedBack(): void {
		$quotable = $this->service()->quotableFieldsOf(
			case: [
				'identifier' => '2026-0042',
				'title' => 'Dakkapel',
				'initiatorSourceId' => '123456782',
				'assignee' => 'ruben',
				'portalSubject' => 'sub-1',
			]
		);

		self::assertSame(expected: ['identifier', 'title'], actual: array_keys($quotable));
		self::assertArrayNotHasKey(key: 'initiatorSourceId', array: $quotable);
		self::assertArrayNotHasKey(key: 'assignee', array: $quotable);
		self::assertSame(
			expected: PortalContributionProvider::CITIZEN_CASE_FIELDS,
			actual: (new PortalContributionProvider())->citizenCaseFields(),
			message: 'the acknowledgement reads the portal\'s own list, not a second copy'
		);
	}//end testAFieldTheCitizenMayNotSeeIsNotQuotedBack()

	/**
	 * Content that stays on the platform leaves the case out of the e-mail.
	 *
	 * @return void
	 */
	public function testContentOnThePlatformCarriesNoCaseContent(): void {
		$this->caseType = ['acknowledgement' => ['contentOnPlatform' => true]];
		$caseId = $this->seedCase();

		$result = $this->service()->acknowledge(caseId: $caseId);

		self::assertTrue(condition: $result['sent']);
		self::assertTrue(condition: $result['record']['contentWithheld']);
		self::assertStringNotContainsString(needle: '2026-0042', haystack: $result['payload']['body']);
		self::assertStringNotContainsString(needle: 'Dakkapel', haystack: $result['payload']['body']);
		self::assertStringContainsString(needle: 'bericht', haystack: $result['payload']['body']);
	}//end testContentOnThePlatformCarriesNoCaseContent()

	/**
	 * The record names the channel the acknowledgement actually went through.
	 *
	 * This test used to assert `portal` for a citizen who chose the portal,
	 * while the notice was mailed: the record named a channel nothing went
	 * through. A case without a portal subject cannot reach the portal inbox,
	 * so the transport that took it was e-mail, and that is what is recorded
	 * (REQ-WRN-002).
	 *
	 * @return void
	 */
	public function testTheCitizensRecordedChannelIsTheOneRecorded(): void {
		$caseId = $this->seedCase(overrides: ['communicationChannel' => 'portal']);

		$result = $this->service()->acknowledge(caseId: $caseId);

		self::assertSame(expected: 'email', actual: $result['record']['channel']);
		self::assertSame(expected: 'email', actual: $this->store->cases[$caseId]['acknowledgementDuty']['channel']);
	}//end testTheCitizensRecordedChannelIsTheOneRecorded()

	/**
	 * The message carries the kenmerk and the end date of the term.
	 *
	 * @return void
	 */
	public function testTheMessageCarriesTheKenmerkAndTheDeadline(): void {
		$caseId = $this->seedCase();

		$result = $this->service()->acknowledge(caseId: $caseId);

		self::assertStringContainsString(needle: '2026-0042', haystack: $result['payload']['subject']);
		self::assertStringContainsString(needle: '1 november 2026', haystack: $result['payload']['body']);
		self::assertStringContainsString(needle: 'Dakkapel Kerkstraat 12', haystack: $result['payload']['body']);
	}//end testTheMessageCarriesTheKenmerkAndTheDeadline()

	/**
	 * The receipt names the case by its number, also when the term links the case by UUID.
	 *
	 * A term instance points at its case by id. The letter read that link first,
	 * so a resident's inbox showed "Ontvangstbevestiging zaak cecc92cc-..." for a
	 * case numbered 2026-0117 (Zuiddrecht live round 3).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-a-requester-letter-names-the-case-by-its-number
	 */
	public function testTheReceiptNamesTheCaseNumberNotTheTermsCaseLink(): void {
		$uuid = 'cecc92cc-6e45-4edd-b38f-908fb543e710';
		$this->term = ['id' => 'ti-1', 'case' => $uuid, 'endDateCurrent' => '2026-11-01'];
		$caseId = $this->seedCase(overrides: ['id' => $uuid, 'identifier' => '2026-0117']);

		$result = $this->service()->acknowledge(caseId: $caseId);

		self::assertSame(expected: 'Ontvangstbevestiging zaak 2026-0117', actual: $result['payload']['subject']);
		self::assertStringContainsString(needle: 'kenmerk 2026-0117', haystack: $result['payload']['body']);
		self::assertStringNotContainsString(needle: $uuid, haystack: $result['payload']['body']);
	}//end testTheReceiptNamesTheCaseNumberNotTheTermsCaseLink()

	/**
	 * REQ-WRN-003: the mail server refused the acknowledgement, so the duty is
	 * not met. The refusal carries the transport's reason, which is what
	 * AcknowledgementDispatchJob hands to recordFailedAttempt().
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-the-acknowledgement-duty-is-met-only-when-the-acknowledgement-went-out-req-wrn-003
	 */
	public function testTheDutyIsNotMetWhenNothingWentOut(): void {
		$caseId = $this->seedCase();

		try {
			$this->service(sender: $this->realTermNoticeSender(mailerThrows: true))->acknowledge(caseId: $caseId);
			self::fail(message: 'an acknowledgement nobody received must not come back as sent');
		} catch (RefusedException $e) {
			self::assertSame(expected: 'acknowledgement-not-sent', actual: $e->getRule());
			self::assertStringContainsString(needle: 'mail server', haystack: $e->getSentence());
		}

		$duty = ($this->store->cases[$caseId]['acknowledgementDuty'] ?? []);
		self::assertNotSame(expected: AcknowledgementService::STATUS_MET, actual: ($duty['status'] ?? ''));
	}//end testTheDutyIsNotMetWhenNothingWentOut()

	/**
	 * REQ-WRN-003: no public "verzonden" line when nothing went out, and an
	 * internal line that says it was not sent and why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-the-acknowledgement-duty-is-met-only-when-the-acknowledgement-went-out-req-wrn-003
	 */
	public function testNoPublicSentLineWhenNothingWentOut(): void {
		$caseId = $this->seedCase();
		$entries = [];
		$this->timeline->method('record')->willReturnCallback(
			static function (string $caseId, string $kind, string $message, array $fields = [], string $visibility = CaseTimeline::INTERNAL) use (&$entries): string {
				$entries[] = ['message' => $message, 'fields' => $fields, 'visibility' => $visibility];
				return 'entry-' . count($entries);
			}
		);

		try {
			$this->service(sender: $this->realTermNoticeSender(mailerThrows: true))->acknowledge(caseId: $caseId);
		} catch (RefusedException $e) {
			// The refusal is the previous test's subject.
		}

		$public = array_filter($entries, static fn (array $entry): bool => $entry['visibility'] === CaseTimeline::PUBLIC_ENTRY);
		self::assertSame(expected: [], actual: array_values($public), message: 'a citizen must not read that it was sent');

		self::assertCount(expectedCount: 1, haystack: $entries);
		self::assertSame(expected: CaseTimeline::INTERNAL, actual: $entries[0]['visibility']);
		self::assertStringContainsString(needle: 'niet verzonden', haystack: $entries[0]['message']);
		self::assertStringContainsString(needle: 'mail server', haystack: (string)($entries[0]['fields']['reason'] ?? ''));
	}//end testNoPublicSentLineWhenNothingWentOut()

	/**
	 * A sender that answers a delivery record, so these tests stay about the duty.
	 *
	 * The mail itself is TermNoticeDeliveryTest's subject.
	 *
	 * @return TermNoticeSender The sender.
	 */
	private function sentNotices(): TermNoticeSender {
		$sender = $this->createMock(originalClassName: TermNoticeSender::class);
		$sender->method('send')->willReturn(['notificationChannel' => 'email', 'sent' => true, 'duplicate' => false]);

		return $sender;
	}//end sentNotices()

	/**
	 * Row 10.8 (woo-request-takes-over-from-opencatalogi 2.1): the confirmation
	 * of a Woo request received through the portal form names the day it was
	 * received, the day its term starts and its due date.
	 *
	 * The case is the one `WooRequestIntake::receive()` writes, built with the
	 * same mapping (WooReceivedAnswers, WooRequestForm), plus the stamp
	 * IntakeTermStartListener writes on create (a Friday morning, so the term
	 * starts on arrival), and the statutory term the case-created listener binds.
	 *
	 * @return void
	 */
	public function testTheAcknowledgementNamesTheStartAndTheDueDate(): void {
		$answers = [
			'requestedInformation' => 'Alle e-mails over de brug.',
			'requesterName' => 'Sanne de Groot',
			'requesterEmail' => 'sanne@example.org',
			'channel' => 'web',
		];
		$map = new WooReceivedAnswers(dates: $this->caseDates());
		$wooRequest = (new WooRequestForm())->normalise(request: $map->toRequest(answers: $answers, origin: 'portal-form'));
		$case = [
			'id' => 'case-woo',
			'caseType' => 'ct-woo',
			'identifier' => 'WOO-2026-0007',
			'title' => $wooRequest['onderwerp'],
		] + $map->intake(answers: $answers, origin: 'portal-form', receivedAt: '2026-11-27T10:00:00+01:00') + [
			'wooRequest' => $wooRequest,
			'termStartsAt' => '2026-11-27T10:00:00+01:00',
			'receivedOutsideWorkingHours' => false,
		];
		$this->store->cases['case-woo'] = $case;
		$this->term = ['id' => 'ti-woo', 'case' => 'case-woo', 'endDateCurrent' => '2026-12-28'];
		// The Woo case type keeps its requester in the request as sent, and says so
		// (register.d/81-woo-verzoek.json); without the declaration no address is found.
		$this->caseType = ['acknowledgement' => ['addressFields' => ['wooRequest.verzoekerEmail']]];

		$result = $this->service()->acknowledge(caseId: 'case-woo');

		self::assertTrue(condition: $result['sent'], message: (string)($result['reason'] ?? ''));
		$body = $result['payload']['body'];
		self::assertStringContainsString(needle: 'ontvangen op 27 november 2026', haystack: $body);
		self::assertStringContainsString(needle: 'beslistermijn start op 27 november 2026', haystack: $body);
		self::assertStringContainsString(needle: 'uiterlijk op 28 december 2026', haystack: $body);
		// Every date in the receipt reads the same way (letters-print-every-date-the-same-way):
		// the due date is stored as 2026-12-28 and was once printed so, next to 27-11-2026.
		self::assertDoesNotMatchRegularExpression(pattern: '/\b\d{4}-\d{2}-\d{2}\b/', string: $body);
		self::assertSame(expected: 'sanne@example.org', actual: $this->store->cases['case-woo']['outboundCommunications'][0]['recipient']);
	}//end testTheAcknowledgementNamesTheStartAndTheDueDate()
}//end class
