<?php

/**
 * A requester notice goes out through a real channel, or is recorded as not sent.
 *
 * The transports are the real e-mail sender (TermNoticeSender over a recording
 * mailer and integriq's opt-out list), the real register fake for the portal
 * inbox and the case record, and a BerichtenboxService double that answers in
 * the shapes the real service returns (a stored record with
 * `externalMessageId`, or one with `refused`, `code` and `error`).
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Notification
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Notification;

use OCA\Dossiq\Service\BerichtenboxService;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\Notification\RequesterNoticeSender;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesRealTermNoticeSender;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Notification\RequesterNoticeSender
 * @uses \OCA\Dossiq\Service\Termijn\TermNoticeSender
 * @uses \OCA\Dossiq\Exception\NoticeNotSentException
 * @uses \OCA\Dossiq\Service\Email\CaseContactDirectory
 * @uses \OCA\Dossiq\Service\Email\CaseMailOptOut
 * @uses \OCA\Dossiq\Service\OptOutGate
 * @uses \OCA\Dossiq\Service\CaseFieldWriter
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 * @uses \OCA\Dossiq\Support\FleetAppId
 */
class RequesterNoticeSenderTest extends TestCase {
	use MakesRealTermNoticeSender;

	/**
	 * The register: cases and portal messages.
	 *
	 * @var FakeTermijnStore
	 */
	private FakeTermijnStore $store;

	/**
	 * Set up the register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new FakeTermijnStore();
		$this->mailed = [];
	}//end setUp()

	/**
	 * The sender over the register, the real e-mail transport and the given digital post.
	 *
	 * @param BerichtenboxService|null $post         Digital post, or null for an instance without it.
	 * @param bool                     $portaliq     Whether portaliq is installed.
	 * @param bool                     $mailerThrows Whether the mail server refuses.
	 *
	 * @return RequesterNoticeSender The sender.
	 */
	private function sender(?BerichtenboxService $post = null, bool $portaliq = false, bool $mailerThrows = false): RequesterNoticeSender {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'portaal_bericht_schema' => 'portaalBericht',
				default => '',
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => ($app === 'portaliq' && $portaliq === true));

		return new RequesterNoticeSender(
			email: $this->realTermNoticeSender(mailerThrows: $mailerThrows),
			digitalPost: $post,
			settings: $settings,
			appManager: $apps,
			writer: new CaseFieldWriter(),
		);
	}//end sender()

	/**
	 * Seed a case and return its row.
	 *
	 * @param array<string, mixed> $fields The case fields.
	 *
	 * @return array<string, mixed> The case.
	 */
	private function seedCase(array $fields): array {
		return $this->store->seed('case', array_merge(['id' => 'case-1', 'identifier' => 'WOO-2026-7'], $fields));
	}//end seedCase()

	/**
	 * Digital post that answers the way BerichtenboxService does.
	 *
	 * @param array<string, mixed> $answer What sendMessage() returns.
	 *
	 * @return BerichtenboxService The double.
	 */
	private function post(array $answer): BerichtenboxService {
		$post = $this->createMock(BerichtenboxService::class);
		$post->method('sendMessage')->willReturn($answer);

		return $post;
	}//end post()

	/**
	 * The rendered notice.
	 *
	 * @return array{subject: string, body: string}
	 */
	private function rendered(): array {
		return ['subject' => 'Ontvangstbevestiging zaak WOO-2026-7', 'body' => "Beste aanvrager,\n\nWij hebben uw verzoek ontvangen."];
	}//end rendered()

	/**
	 * REQ-WRN-002 "No address at all": not-sent, no-channel, no message id, and the case records it.
	 *
	 * @return void
	 */
	public function testNoChannelAnswersNotSentWithoutAMessageId(): void {
		$case = $this->seedCase(fields: []);

		$result = $this->sender()->send($case, 'ontvangstbevestiging', $this->rendered(), 'acknowledgement');

		self::assertSame('not-sent', $result['status']);
		self::assertSame('no-channel', $result['reasonCode']);
		self::assertNotSame('', $result['reason']);
		self::assertArrayNotHasKey('messageId', $result);
		self::assertSame([], $result['channelsTried']);

		$stored = $this->store->get('case', 'case-1')['outboundCommunications'];
		self::assertCount(1, $stored);
		self::assertSame('not-sent', $stored[0]['status']);
		self::assertArrayNotHasKey('messageId', $stored[0]);
		self::assertArrayNotHasKey('sentAt', $stored[0]);
	}//end testNoChannelAnswersNotSentWithoutAMessageId()

	/**
	 * REQ-WRN-001 "No transport answers": only a BSN and no integriq is integriq-missing.
	 *
	 * @return void
	 */
	public function testOnlyABsnWithoutIntegriqIsNotSentWithIntegriqMissing(): void {
		$case = $this->seedCase(fields: ['initiatorType' => 'person', 'initiatorSourceId' => '111222333']);
		$post = $this->post(answer: ['refused' => true, 'code' => 'integriq-missing', 'error' => 'Integriq is not installed. Nothing was sent.']);

		$result = $this->sender(post: $post)->send($case, 'ontvangstbevestiging', $this->rendered(), 'acknowledgement');

		self::assertSame('not-sent', $result['status']);
		self::assertSame('integriq-missing', $result['reasonCode']);
		self::assertArrayNotHasKey('messageId', $result);
		self::assertSame('digital-post', $result['channelsTried'][0]['channel']);
	}//end testOnlyABsnWithoutIntegriqIsNotSentWithIntegriqMissing()

	/**
	 * REQ-WRN-001 "Digital post is accepted": the tracked id is the message id.
	 *
	 * @return void
	 */
	public function testAcceptedDigitalPostCarriesIntegriqsTrackedId(): void {
		$case = $this->seedCase(fields: ['initiatorType' => 'person', 'initiatorSourceId' => '111222333']);
		$post = $this->post(answer: ['externalMessageId' => 'ip-123', 'status' => 'sent', 'sentAt' => '2026-10-10T09:00:00+02:00']);

		$result = $this->sender(post: $post)->send($case, 'hersteltermijn-request', $this->rendered(), 'stage');

		self::assertSame('sent', $result['status']);
		self::assertSame('digital-post', $result['channel']);
		self::assertSame('ip-123', $result['messageId']);
	}//end testAcceptedDigitalPostCarriesIntegriqsTrackedId()

	/**
	 * REQ-WRN-002 "E-mail when digital post refuses": mailed, and the refusal is listed.
	 *
	 * @return void
	 */
	public function testAnIntegriqRefusalFallsThroughToEmailAndIsListedAsTried(): void {
		$case = $this->seedCase(fields: ['initiatorType' => 'person', 'initiatorSourceId' => '111222333', 'email' => 'verzoeker@example.nl']);
		$post = $this->post(answer: ['refused' => true, 'code' => 'digital-post-source-unset', 'error' => 'No integriq digital post source is set.']);

		$result = $this->sender(post: $post)->send($case, 'hersteltermijn-request', $this->rendered(), 'information-request');

		self::assertSame('sent', $result['status']);
		self::assertSame('email', $result['channel']);
		self::assertNotSame('', $result['messageId']);
		self::assertSame(
			[['channel' => 'digital-post', 'reasonCode' => 'digital-post-source-unset', 'reason' => 'No integriq digital post source is set.']],
			$result['channelsTried']
		);
		self::assertCount(1, $this->mailed);
		self::assertSame($result['channelsTried'], $this->store->get('case', 'case-1')['outboundCommunications'][0]['channelsTried']);
	}//end testAnIntegriqRefusalFallsThroughToEmailAndIsListedAsTried()

	/**
	 * REQ-WRN-002 "A portal requester gets the notice in the portal inbox", and no digital post.
	 *
	 * @return void
	 */
	public function testAPortalRequesterGetsAPortalMessageAndNoDigitalPost(): void {
		$case = $this->seedCase(fields: ['portalSubject' => 'ps-abc', 'initiatorType' => 'person', 'initiatorSourceId' => '111222333']);
		$post = $this->createMock(BerichtenboxService::class);
		$post->expects(self::never())->method('sendMessage');

		$result = $this->sender(post: $post, portaliq: true)->send($case, 'ontvangstbevestiging', $this->rendered(), 'acknowledgement');

		self::assertSame('sent', $result['status']);
		self::assertSame('portal-inbox', $result['channel']);

		$message = $this->store->get('portaalBericht', $result['messageId']);
		self::assertNotNull($message, 'the message id is the portaalBericht that exists');
		self::assertSame('ps-abc', $message['recipientRef']);
		self::assertSame('handler_to_citizen', $message['direction']);
		self::assertStringContainsString('ontvangen', $message['content']);
		self::assertSame([], (new RealSchemaValidator())->errors('portaalBericht', $message), 'the message fits the real schema');
	}//end testAPortalRequesterGetsAPortalMessageAndNoDigitalPost()

	/**
	 * Without portaliq the inbox is not offered, and the notice goes on to the next channel.
	 *
	 * @return void
	 */
	public function testWithoutPortaliqThePortalInboxIsNotOffered(): void {
		$case = $this->seedCase(fields: ['portalSubject' => 'ps-abc', 'email' => 'verzoeker@example.nl']);

		$result = $this->sender(portaliq: false)->send($case, 'ontvangstbevestiging', $this->rendered(), 'acknowledgement');

		self::assertSame('email', $result['channel']);
		self::assertSame([], $result['channelsTried']);
		self::assertSame([], $this->store->findObjects('dossiq', 'portaalBericht'));
	}//end testWithoutPortaliqThePortalInboxIsNotOffered()

	/**
	 * A mail server that throws is not-sent, with the transport's reason.
	 *
	 * @return void
	 */
	public function testAThrowingMailerIsNotSentWithItsReason(): void {
		$case = $this->seedCase(fields: ['email' => 'verzoeker@example.nl']);

		$result = $this->sender(mailerThrows: true)->send($case, 'ontvangstbevestiging', $this->rendered(), 'acknowledgement');

		self::assertSame('not-sent', $result['status']);
		self::assertSame('mail-failed', $result['reasonCode']);
		self::assertStringContainsString('mail server', $result['reason']);
		self::assertArrayNotHasKey('messageId', $result);
	}//end testAThrowingMailerIsNotSentWithItsReason()

	/**
	 * The e-mail channel needs no signed-in user: nothing here reads a session,
	 * and the case read and write go through the system path.
	 *
	 * @return void
	 */
	public function testTheEmailChannelWorksFromABackgroundJobWithNoUser(): void {
		$case = ['id' => 'case-1'];
		$this->seedCase(fields: ['wooRequest' => ['verzoekerEmail' => 'Verzoeker@Example.NL']]);

		$result = $this->sender()->send($case + $this->store->get('case', 'case-1'), 'extension', $this->rendered(), 'extension');

		self::assertSame('sent', $result['status']);
		self::assertSame('email', $result['channel']);
		self::assertSame(['verzoeker@example.nl'], $this->mailed[0]->to);
	}//end testTheEmailChannelWorksFromABackgroundJobWithNoUser()

	/**
	 * REQ-WRN-006 "Stage notices are each stored with their own result".
	 *
	 * @return void
	 */
	public function testEachStageNoticeIsStoredWithItsOwnResult(): void {
		$case = $this->seedCase(fields: ['email' => 'verzoeker@example.nl']);
		$sender = $this->sender();

		$first = $sender->send($case, 'status-zoeken', ['subject' => 'Zoeken documenten', 'body' => 'We zoeken de documenten.'], 'stage');
		$second = $sender->send($case, 'status-beoordelen', ['subject' => 'Beoordelen', 'body' => 'We beoordelen de documenten.'], 'stage');

		$stored = $this->store->get('case', 'case-1')['outboundCommunications'];
		self::assertCount(2, $stored);
		self::assertSame($first['messageId'], $stored[0]['messageId']);
		self::assertSame($second['messageId'], $stored[1]['messageId']);
		self::assertNotSame($stored[0]['messageId'], $stored[1]['messageId']);
		self::assertSame(['stage', 'stage'], array_column($stored, 'moment'));
	}//end testEachStageNoticeIsStoredWithItsOwnResult()

	/**
	 * REQ-WRN-006: the record the sender writes fits the real case schema, sent and not sent.
	 *
	 * @return void
	 */
	public function testANoticeRecordValidatesAgainstTheCaseSchema(): void {
		$case = $this->seedCase(fields: ['initiatorType' => 'person', 'initiatorSourceId' => '111222333', 'email' => 'verzoeker@example.nl']);
		$post = $this->post(answer: ['refused' => true, 'code' => 'digital-post-source-unset', 'error' => 'No source.']);
		$sender = $this->sender(post: $post);
		$sender->send($case, 'ontvangstbevestiging', $this->rendered(), 'acknowledgement', ['recordExtras' => ['recipient' => 'verzoeker@example.nl', 'templateVersion' => '1', 'contentWithheld' => false]]);
		$this->sender(post: $post, mailerThrows: true)->send($case, 'extension', $this->rendered(), 'extension');

		$records = $this->store->get('case', 'case-1')['outboundCommunications'];
		self::assertSame(['sent', 'not-sent'], array_column($records, 'status'));
		self::assertSame([], (new RealSchemaValidator())->errors('case', ['outboundCommunications' => $records], false));
	}//end testANoticeRecordValidatesAgainstTheCaseSchema()

	/**
	 * Every term template maps to a moment, and an unnamed one is a stage notice.
	 *
	 * @return void
	 */
	public function testEveryTemplateHasAMoment(): void {
		$sender = $this->sender();
		self::assertSame('acknowledgement', $sender->momentFor('ontvangstbevestiging'));
		self::assertSame('extension', $sender->momentFor('extension'));
		self::assertSame('transfer', $sender->momentFor('doorzending'));
		self::assertSame('stage', $sender->momentFor('status-beoordelen'));
	}//end testEveryTemplateHasAMoment()
}//end class
