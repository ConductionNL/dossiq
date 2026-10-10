<?php

/**
 * CaseEmailService Security Unit Tests
 *
 * Tests for C4/H6/L1 security fixes in CaseEmailService.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RecipientOptedOutException;
use OCA\Dossiq\Service\CaseEmailService;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\CaseMailOptOut;
use OCA\Dossiq\Service\Email\IntakeAccount;
use OCA\Dossiq\Service\Email\MailGatewayInterface;
use OCA\Dossiq\Service\Email\OutboundCaseMail;
use OCA\Dossiq\Service\Email\OutboundState;
use OCA\Dossiq\Service\Email\RecipientAllowlist;
use OCA\Dossiq\Service\Email\SenderIdentity;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\OptOutGate;
use OCA\Dossiq\Tests\Support\FakeIntegriqOptOuts;
use OCA\Dossiq\Tests\Support\InMemoryEventDispatcher;
use OCA\OpenRegister\Service\Notification\UnsubscribeHeaders;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Security-focused unit tests for CaseEmailService.
 *
 * Covers C4 (IDOR + file-disclosure), H6 (XSS + reserved-domain), L1 (log-injection).
 *
 * @covers \OCA\Dossiq\Service\CaseEmailService
 *
 * @uses \OCA\Dossiq\Service\Email\CaseContactDirectory
 * @uses \OCA\Dossiq\Service\Email\OutboundCaseMail
 * @uses \OCA\Dossiq\Service\Email\SenderIdentity
 * @uses \OCA\Dossiq\Service\Email\IntakeAccount
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Email\CaseEmailRepository
 * @uses \OCA\Dossiq\Service\Email\RecipientAllowlist
 * @uses \OCA\Dossiq\Exception\RecipientOptedOutException
 * @uses \OCA\Dossiq\Service\Email\CaseMailOptOut
 * @uses \OCA\Dossiq\Service\OptOutGate
 * @uses \OCA\Dossiq\Support\FleetAppId
 */
class CaseEmailServiceTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The mocked Nextcloud Mail gateway: case mail leaves through its account (decision 165).
	 *
	 * @var MailGatewayInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private MailGatewayInterface $mailer;

	/**
	 * Every message handed to the Mail account.
	 *
	 * @var array<int, array{accountId: int, message: array<string, mixed>}>
	 */
	private array $sentMessages = [];

	/**
	 * The Mail accounts the gateway answers.
	 *
	 * @var array<int, array{id: int, name: string, email: string}>
	 */
	private array $accounts = [];

	/**
	 * The state the next send answers.
	 *
	 * @var string
	 */
	private string $sendState = OutboundState::SENT;

	/**
	 * The mocked app config.
	 *
	 * @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The service under test.
	 *
	 * @var CaseEmailService
	 */
	private CaseEmailService $service;

	/**
	 * The dispatcher the opt-out question travels through.
	 *
	 * @var InMemoryEventDispatcher
	 */
	private InMemoryEventDispatcher $dispatcher;

	/**
	 * integriq's opt-out list, answering on that dispatcher.
	 *
	 * @var FakeIntegriqOptOuts
	 */
	private FakeIntegriqOptOuts $optOuts;

	/**
	 * How many sent-mail records were written.
	 *
	 * @var int
	 */
	private int $sentRecords = 0;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dispatcher = new InMemoryEventDispatcher();
		$this->optOuts = FakeIntegriqOptOuts::on($this->dispatcher);
		$this->sentRecords = 0;
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->sentMessages = [];
		$this->accounts = [];
		$this->sendState = OutboundState::SENT;
		$this->mailer = $this->createMock(MailGatewayInterface::class);
		$this->mailer->method('accounts')->willReturnCallback(fn (): array => $this->accounts);
		// Registered first, so its answer wins over a later expects() that only counts.
		$this->mailer->method('sendMessage')->willReturnCallback(
			function (int $accountId, array $message): array {
				$this->sentMessages[] = ['accountId' => $accountId, 'message' => $message];

				return ['state' => $this->sendState, 'outboxId' => count($this->sentMessages)];
			}
		);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// The repository and contact directory are real collaborators, not mocks:
		// every assertion below is about behaviour they inherited verbatim from
		// CaseEmailService, and the repository is still driven entirely by the
		// mocked SettingsService (getObjectService() === null ⇒ no case data).
		$this->service = new CaseEmailService(
			$this->logger,
			new CaseEmailRepository($this->settingsService),
			new CaseContactDirectory(),
			$this->outbound(),
			new RecipientAllowlist($this->appConfig),
			$this->createMock(CaseTimeline::class),
			new CaseMailOptOut($this->gate(), $this->l10n(), new UnsubscribeHeaders(new NullLogger())),
		);

	}//end setUp()

	/**
	 * Build a service whose case record is readable, so the recipient policy runs.
	 *
	 * The repository is mocked here on purpose: the default fixture drives it
	 * through a SettingsService whose getObjectService() is null, so every case
	 * reads as "not found" and sendEmail() throws before it ever reaches the
	 * guard. These tests are about the guard.
	 *
	 * @param string $fromAddress The address of the Mail account the case mail leaves from
	 * @param string $allowlist The configured allow-list value
	 * @param array<string, mixed> $caseRecord The raw case record OR returns
	 *
	 * @return CaseEmailService The service under test
	 */
	private function serviceWithCase(
		string $fromAddress,
		string $allowlist,
		array $caseRecord = ['identifier' => '2026-0001', 'title' => 'Dakkapel'],
		?CaseTimeline $timeline = null,
		?array $template = null,
	): CaseEmailService {
		$this->accounts = [['id' => 7, 'name' => 'Zaken', 'email' => $fromAddress]];
		$this->appConfig
			->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') use ($allowlist): string {
					return match ($key) {
						IntakeAccount::ACCOUNT_KEY => '7',
						'email_recipient_allowlist' => $allowlist,
						default => $default,
					};
				}
			);

		$repository = $this->createMock(CaseEmailRepository::class);
		$repository->method('loadCaseRecord')->willReturn($caseRecord);
		$repository->method('recordSentEmail')->willReturnCallback(
			function () {
				$this->sentRecords++;
				return 'msg-test';
			}
		);
		$repository->method('findTemplate')->willReturn($template);
		$repository->method('loadCaseVariables')->willReturn([]);

		return new CaseEmailService(
			$this->logger,
			$repository,
			new CaseContactDirectory(),
			$this->outbound(),
			new RecipientAllowlist($this->appConfig),
			($timeline ?? $this->createMock(CaseTimeline::class)),
			new CaseMailOptOut($this->gate(), $this->l10n(), new UnsubscribeHeaders(new NullLogger())),
		);
	}//end serviceWithCase()

	/**
	 * H4: a recipient outside a populated allow-list is rejected.
	 *
	 * THE assertion this guard never made. Until 2026-09-10 the guard was fed
	 * `loadCaseVariables()`'s six-key projection, which carries none of the
	 * contact fields it reads, so its address list was empty on every call and
	 * an empty list meant "no restriction". Every address ever supplied passed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function testSendEmailRejectsRecipientOutsidePopulatedAllowlist(): void {
		$service = $this->serviceWithCase(
			fromAddress: 'zaken@gemeente.nl',
			allowlist: '@gemeente.nl, team@partner.nl',
		);

		// The Mail account is fully stubbed, NOT constrained to never(): a guard
		// that wrongly passes must then reach a working send and fail this test
		// on the missing exception. The never() case is asserted by
		// testUnconfiguredAllowlistRejectsAForeignDomain.

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Ontvanger staat niet op de lijst');

		$service->sendEmail(
			caseId: 'case-1',
			to: 'attacker@evil.example',
			subject: 'Hallo',
			body: 'Tekst',
		);
	}//end testSendEmailRejectsRecipientOutsidePopulatedAllowlist()

	/**
	 * H4: a recipient on the configured allow-list is sent to.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function testSendEmailAllowsRecipientOnConfiguredAllowlist(): void {
		$service = $this->serviceWithCase(
			fromAddress: 'zaken@gemeente.nl',
			allowlist: '@gemeente.nl, team@partner.nl',
		);

		$this->mailer->expects($this->once())->method('sendMessage');

		$result = $service->sendEmail(
			caseId: 'case-1',
			to: 'TEAM@partner.nl',
			subject: 'Hallo',
			body: 'Tekst',
		);

		$this->assertSame('TEAM@partner.nl', $result['to']);
	}//end testSendEmailAllowsRecipientOnConfiguredAllowlist()

	/**
	 * A sent mail puts a PUBLIC line on the case's timeline, carrying the
	 * recipient, the subject and the id of the document it was recorded as.
	 *
	 * Public because the recipient already holds this message: withholding
	 * its line from the timeline the portal reads would hide it only from the
	 * person who has it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testASentMailReachesTheTimelineAsAPublicEntry(): void {
		$seen = [];
		$timeline = $this->createMock(CaseTimeline::class);
		$timeline->method('record')->willReturnCallback(
			static function (
				string $caseId,
				string $kind,
				string $message,
				array $fields = [],
				string $visibility = 'internal',
				array $relatedCaseIds = [],
			) use (&$seen): string {
				$seen = compact('caseId', 'kind', 'fields', 'visibility');

				return 'entry-1';
			}
		);

		$service = $this->serviceWithCase(
			fromAddress: 'zaken@gemeente.nl',
			allowlist: '@gemeente.nl, team@partner.nl',
			timeline: $timeline,
		);


		$service->sendEmail(
			caseId: 'case-1',
			to: 'team@partner.nl',
			subject: 'Hallo',
			body: 'Tekst',
		);

		$this->assertSame('case-1', $seen['caseId']);
		$this->assertSame('mail-uitgaand', $seen['kind']);
		$this->assertSame('public', $seen['visibility']);
		$this->assertSame('team@partner.nl', $seen['fields']['recipient']);
		$this->assertSame('Hallo', $seen['fields']['subject']);
		$this->assertSame('msg-test', $seen['fields']['documentId']);
	}//end testASentMailReachesTheTimelineAsAPublicEntry()

	/**
	 * A mail the allow-list refuses never reaches the timeline either: the
	 * entry records a message that was SENT, and nothing was.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testARefusedMailWritesNoTimelineEntry(): void {
		$timeline = $this->createMock(CaseTimeline::class);
		$timeline->expects($this->never())->method('record');

		$service = $this->serviceWithCase(
			fromAddress: 'zaken@gemeente.nl',
			allowlist: '@gemeente.nl',
			timeline: $timeline,
		);

		$this->expectException(\RuntimeException::class);

		$service->sendEmail(
			caseId: 'case-1',
			to: 'someone@elsewhere.example',
			subject: 'Hallo',
			body: 'Tekst',
		);
	}//end testARefusedMailWritesNoTimelineEntry()

	/**
	 * H4: an unconfigured allow-list defaults to the from-address's own domain.
	 *
	 * This is the half that keeps "fail closed" from meaning "send nothing".
	 * An empty allow-list that rejected everything would stop outbound mail on
	 * every instance that never configured one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function testUnconfiguredAllowlistDefaultsToTheSenderDomain(): void {
		$service = $this->serviceWithCase(
			fromAddress: 'zaken@gemeente.nl',
			allowlist: '',
		);

		$this->mailer->expects($this->once())->method('sendMessage');

		$result = $service->sendEmail(
			caseId: 'case-1',
			to: 'behandelaar@gemeente.nl',
			subject: 'Hallo',
			body: 'Tekst',
		);

		$this->assertSame('behandelaar@gemeente.nl', $result['to']);
	}//end testUnconfiguredAllowlistDefaultsToTheSenderDomain()

	/**
	 * H4: the default allow-list still rejects a foreign domain.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function testUnconfiguredAllowlistRejectsAForeignDomain(): void {
		$service = $this->serviceWithCase(
			fromAddress: 'zaken@gemeente.nl',
			allowlist: '',
		);

		$this->mailer->expects($this->never())->method('sendMessage');

		$this->expectException(\RuntimeException::class);

		$service->sendEmail(
			caseId: 'case-1',
			to: 'burger@elders.example',
			subject: 'Hallo',
			body: 'Tekst',
		);
	}//end testUnconfiguredAllowlistRejectsAForeignDomain()

	/**
	 * H4: `*` opens the relay, deliberately and visibly.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function testWildcardAllowlistPermitsAnyRecipient(): void {
		$service = $this->serviceWithCase(
			fromAddress: 'zaken@gemeente.nl',
			allowlist: '*',
		);

		$this->mailer->expects($this->once())->method('sendMessage');

		$result = $service->sendEmail(
			caseId: 'case-1',
			to: 'anyone@elders.example',
			subject: 'Hallo',
			body: 'Tekst',
		);

		$this->assertSame('anyone@elders.example', $result['to']);
	}//end testWildcardAllowlistPermitsAnyRecipient()

	/**
	 * H4: a contact registered on the case is allowed off-domain.
	 *
	 * The `case` schema declares no contact field today, so this asserts the
	 * wiring rather than a live data path: the guard reads the RAW case record,
	 * not the six-key variable projection that made it blind.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function testCaseContactIsAllowedEvenOffDomain(): void {
		$service = $this->serviceWithCase(
			fromAddress: 'zaken@gemeente.nl',
			allowlist: '@gemeente.nl',
			caseRecord: [
				'identifier' => '2026-0001',
				'contacts' => [['email' => 'burger@elders.example']],
			],
		);

		$this->mailer->expects($this->once())->method('sendMessage');

		$result = $service->sendEmail(
			caseId: 'case-1',
			to: 'burger@elders.example',
			subject: 'Hallo',
			body: 'Tekst',
		);

		$this->assertSame('burger@elders.example', $result['to']);
	}//end testCaseContactIsAllowedEvenOffDomain()

	/**
	 * No Mail account picked: the send is refused as unavailable, and nothing goes out.
	 *
	 * Replaces the old "from-address not configured" refusal: the sender is now
	 * the account, so a missing account is the configuration error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
	 */
	public function testNoPickedAccountRefusesAsUnavailable(): void {
		$service = $this->serviceWithCase(fromAddress: 'zaken@gemeente.nl', allowlist: '*');
		// The account the instance points at is gone from Nextcloud Mail.
		$this->accounts = [['id' => 9, 'name' => 'Ander', 'email' => 'ander@gemeente.nl']];
		$this->mailer->expects($this->never())->method('sendMessage');

		try {
			$service->sendEmail('case-1', 'burger@example.nl', 'Subject', 'Body');
			$this->fail('A send with no usable account must be refused.');
		} catch (RefusedException $e) {
			$this->assertSame('mail-account-unavailable', $e->getRule());
			$this->assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
		}

		$this->assertSame(0, $this->sentRecords);
	}//end testNoPickedAccountRefusesAsUnavailable()

	/**
	 * A case mail leaves from the picked account, and the sender reported is that account's address.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
	 */
	public function testACaseMailLeavesThroughThePickedAccount(): void {
		$service = $this->serviceWithCase(fromAddress: 'zaken@gemeente.nl', allowlist: '*');

		$result = $service->sendEmail('case-1', 'burger@example.nl', 'Uw zaak', '<p>Tekst</p>', ['Documenten/brief.pdf']);

		$this->assertSame(7, $this->sentMessages[0]['accountId']);
		$this->assertSame(['Documenten/brief.pdf'], $this->lastSent()['attachments']);
		$this->assertSame('zaken@gemeente.nl', $result['from']);
		$this->assertSame(OutboundState::SENT, $result['state']);
	}//end testACaseMailLeavesThroughThePickedAccount()

	/**
	 * A message the account took but could not send stays visible on the case as queued.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
	 */
	public function testAQueuedMessageIsRecordedOnTheCaseAsQueued(): void {
		$seen = [];
		$timeline = $this->createMock(CaseTimeline::class);
		$timeline->method('record')->willReturnCallback(
			static function (string $caseId, string $kind, string $message, array $fields = []) use (&$seen): string {
				$seen = $fields;

				return 'entry-1';
			}
		);
		$service = $this->serviceWithCase(fromAddress: 'zaken@gemeente.nl', allowlist: '*', timeline: $timeline);
		$this->sendState = OutboundState::QUEUED;

		$result = $service->sendEmail('case-1', 'burger@example.nl', 'Uw zaak', 'Tekst');

		$this->assertSame(OutboundState::QUEUED, $result['state']);
		$this->assertSame(OutboundState::QUEUED, $seen['delivery']);
		$this->assertSame('zaken@gemeente.nl', $seen['sender']);
		$this->assertSame(1, $this->sentRecords);
	}//end testAQueuedMessageIsRecordedOnTheCaseAsQueued()

	/**
	 * C4 IDOR: sendEmail throws when case is not found (access denied).
	 *
	 * @return void
	 */
	public function testSendEmailThrowsWhenCaseNotFound(): void {
		$this->appConfig
			->method('getValueString')
			->willReturnCallback(
				function (string $app, string $key, string $default = '') {
					if ($key === 'email_from_address') {
						return 'real@municipality.nl';
					}

					return $default;
				}
			);

		// getObjectService returns null → loadCaseData returns [] → IDOR check fires.
		$this->settingsService->method('getObjectService')->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/Zaak niet gevonden/i');

		$this->service->sendEmail('nonexistent-case', 'to@example.com', 'Subject', 'Body');

	}//end testSendEmailThrowsWhenCaseNotFound()

	/**
	 * H6 XSS: resolveVariables escapes HTML characters by default.
	 *
	 * @return void
	 */
	public function testResolveVariablesEscapesHtml(): void {
		$template = 'Beste {{name}}, uw zaak: {{omschrijving}}';
		$data = [
			'name' => 'Jan <script>alert(1)</script>',
			'omschrijving' => '<img src=x onerror="steal()">',
		];

		$result = $this->service->resolveVariables($template, $data);

		$this->assertStringContainsString('Jan &lt;script&gt;', $result);
		$this->assertStringNotContainsString('<script>', $result);
		$this->assertStringContainsString('&lt;img', $result);
		$this->assertStringNotContainsString('<img', $result);

	}//end testResolveVariablesEscapesHtml()

	/**
	 * H6 XSS: resolveVariablesRaw passes through raw values.
	 *
	 * @return void
	 */
	public function testResolveVariablesPlaintextContextSkipsEscape(): void {
		$template = 'Beste {{name}}';
		$data = ['name' => 'Jan & Piet'];

		$result = $this->service->resolveVariablesRaw($template, $data);

		$this->assertSame('Beste Jan & Piet', $result);

	}//end testResolveVariablesPlaintextContextSkipsEscape()

	/**
	 * H6 XSS: resolveVariables leaves unresolved variables unchanged.
	 *
	 * @return void
	 */
	public function testResolveVariablesLeavesUnresolvedUnchanged(): void {
		$template = 'Zaak {{nummer}} van {{name}}';
		$data = ['name' => 'Henk'];

		$result = $this->service->resolveVariables($template, $data);

		$this->assertStringContainsString('{{nummer}}', $result);
		$this->assertStringContainsString('Henk', $result);

	}//end testResolveVariablesLeavesUnresolvedUnchanged()

	/**
	 * The opt-out gate, asking through the test dispatcher with the check on.
	 *
	 * @return OptOutGate The gate.
	 */
	private function gate(): OptOutGate {
		$gateConfig = $this->createMock(IAppConfig::class);
		$gateConfig->method('getValueString')->willReturnArgument(2);

		return new OptOutGate($this->dispatcher, $gateConfig, new NullLogger());
	}//end gate()

	/**
	 * A translator that formats like Nextcloud's: vsprintf on the source text.
	 *
	 * @return IL10N The translator.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters)
		);

		return $l10n;
	}//end l10n()

	/**
	 * The outbound path over the mocked gateway, with the default account picked.
	 *
	 * @return OutboundCaseMail The real outbound path.
	 */
	private function outbound(): OutboundCaseMail {
		$caseTypes = $this->createMock(CaseTypeResolver::class);
		$caseTypes->method('effectiveCaseType')->willReturn([]);
		$store = $this->createMock(CaseTypeStore::class);
		$store->method('referenceId')->willReturnCallback(static fn ($value): string => trim((string)$value));

		return new OutboundCaseMail(
			$this->mailer,
			new SenderIdentity($this->mailer, new IntakeAccount($this->appConfig), $caseTypes, $store)
		);
	}//end outbound()

	/**
	 * The last message handed to the Mail account.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function lastSent(): array {
		$this->assertNotSame([], $this->sentMessages, 'nothing was handed to the Mail account');

		return $this->sentMessages[array_key_last($this->sentMessages)]['message'];
	}//end lastSent()

	/**
	 * A recipient who stopped this case gets no mail and no sent record (REQ-COO-001).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-case-mail-asks-integriq-before-it-is-sent-req-coo-001
	 */
	public function testARecipientWhoStoppedThisCaseGetsNoMailAndNoSentRecord(): void {
		$service = $this->serviceWithCase(fromAddress: 'zaken@gemeente.nl', allowlist: '*');
		$this->optOuts->optOut('burger@example.nl', 'case-1');
		$this->mailer->expects($this->never())->method('sendMessage');

		try {
			$service->sendEmail(caseId: 'case-1', to: 'burger@example.nl', subject: 'Uw zaak', body: '<p>Tekst</p>');
			$this->fail('An opted-out recipient must not be mailed.');
		} catch (RecipientOptedOutException $e) {
			$this->assertSame('opted-out', $e->getReasonCode());
		}

		$this->assertSame(0, $this->sentRecords);
		$this->assertSame('case-1', $this->optOuts->log[0]['caseRef']);
		$this->assertSame('case-update', $this->optOuts->log[0]['category']);
	}//end testARecipientWhoStoppedThisCaseGetsNoMailAndNoSentRecord()

	/**
	 * A case-update carries integriq's link in the body (REQ-COO-003).
	 *
	 * Case mail leaves through the Mail account, which builds its own headers,
	 * so the link travels in the body only (decision 165). The RFC 8058
	 * headers stay on term notices and service mail, which keep IMailer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-every-non-exempt-case-mail-carries-the-unsubscribe-link-req-coo-003
	 */
	public function testACaseUpdateCarriesTheLinkInTheBody(): void {
		$service = $this->serviceWithCase(fromAddress: 'zaken@gemeente.nl', allowlist: '*');
		$this->optOuts->optOut('burger@example.nl', 'case-1');
				$this->mailer->expects($this->once())->method('sendMessage');

		$service->sendEmail(caseId: 'case-2', to: 'burger@example.nl', subject: 'Uw zaak', body: '<p>Tekst</p>');

		$url = 'https://nc.example/index.php/apps/integriq/unsubscribe/tok-' . md5('burger@example.nl|case-2');
		$sent = $this->lastSent();
		$this->assertStringContainsString('href="' . $url . '"', $sent['html']);
		$this->assertStringContainsString($url, $sent['plain']);
		$this->assertSame(['burger@example.nl'], $sent['to']);
		$this->assertSame(1, $this->sentRecords);
	}//end testACaseUpdateCarriesTheLinkInTheBody()

	/**
	 * A besluit reaches an instance-wide opt-out, without a link (REQ-COO-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-a-handler-can-send-a-besluit-that-is-always-delivered-req-coo-002
	 */
	public function testABesluitReachesAnOptedOutRecipientWithoutALink(): void {
		$service = $this->serviceWithCase(fromAddress: 'zaken@gemeente.nl', allowlist: '*');
		$this->optOuts->optOut('burger@example.nl');
				$this->mailer->expects($this->once())->method('sendMessage');

		$service->sendEmail(
			caseId: 'case-1',
			to: 'burger@example.nl',
			subject: 'Besluit',
			body: '<p>Besluit</p>',
			category: 'besluit',
		);

		$this->assertSame('<p>Besluit</p>', $this->lastSent()['html']);
		$this->assertTrue($this->optOuts->log[0]['overridden']);
	}//end testABesluitReachesAnOptedOutRecipientWithoutALink()

	/**
	 * An unknown category is refused before anything is asked or sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-a-handler-can-send-a-besluit-that-is-always-delivered-req-coo-002
	 */
	public function testAnUnknownCategoryIsRefused(): void {
		$service = $this->serviceWithCase(fromAddress: 'zaken@gemeente.nl', allowlist: '*');
		$this->mailer->expects($this->never())->method('sendMessage');

		$this->expectException(\InvalidArgumentException::class);

		$service->sendEmail(caseId: 'case-1', to: 'burger@example.nl', subject: 'S', body: 'B', category: 'marketing');
	}//end testAnUnknownCategoryIsRefused()

	/**
	 * A template's messageCategory decides; a besluit template reaches an opt-out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-a-handler-can-send-a-besluit-that-is-always-delivered-req-coo-002
	 */
	public function testATemplateMarkedBesluitIsSentToAnOptedOutRecipient(): void {
		$service = $this->serviceWithCase(
			fromAddress: 'zaken@gemeente.nl',
			allowlist: '*',
			template: ['subjectPattern' => 'Besluit', 'body' => 'Uw besluit', 'messageCategory' => 'besluit'],
		);
		$this->optOuts->optOut('burger@example.nl');
		$this->mailer->expects($this->once())->method('sendMessage');

		$service->sendFromTemplate(caseId: 'case-1', templateId: 'tpl-1', to: 'burger@example.nl');

		$this->assertSame('besluit', $this->optOuts->log[0]['category']);
	}//end testATemplateMarkedBesluitIsSentToAnOptedOutRecipient()

	/**
	 * A template without a category is a case-update and respects the opt-out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-case-mail-asks-integriq-before-it-is-sent-req-coo-001
	 */
	public function testATemplateWithoutACategoryRespectsTheOptOut(): void {
		$service = $this->serviceWithCase(
			fromAddress: 'zaken@gemeente.nl',
			allowlist: '*',
			template: ['subjectPattern' => 'Stand', 'body' => 'Uw zaak loopt', 'messageCategory' => 'nonsense'],
		);
		$this->optOuts->optOut('burger@example.nl');
		$this->mailer->expects($this->never())->method('sendMessage');

		$this->expectException(RecipientOptedOutException::class);

		$service->sendFromTemplate(caseId: 'case-1', templateId: 'tpl-1', to: 'burger@example.nl');
	}//end testATemplateWithoutACategoryRespectsTheOptOut()
}//end class
