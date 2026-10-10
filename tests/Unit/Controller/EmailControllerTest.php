<?php

/**
 * Tests for EmailController's opt-out answers.
 *
 * Through the real path: the controller drives the real CaseEmailService and
 * OptOutGate, and the question is answered by integriq's decision shape on a
 * dispatcher that really dispatches. Only the mailer and the case store are
 * doubles.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-a-handler-can-send-a-besluit-that-is-always-delivered-req-coo-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\EmailController;
use OCA\Dossiq\Service\CaseEmailService;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\CaseMailOptOut;
use OCA\Dossiq\Service\Email\IntakeAccount;
use OCA\Dossiq\Service\Email\OutboundCaseMail;
use OCA\Dossiq\Service\Email\OutboundState;
use OCA\Dossiq\Service\Email\RecipientAllowlist;
use OCA\Dossiq\Service\Email\SenderIdentity;
use OCA\Dossiq\Service\OptOutGate;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Tests\Support\FakeIntegriqOptOuts;
use OCA\Dossiq\Tests\Support\FakeMailGateway;
use OCA\Dossiq\Tests\Support\InMemoryEventDispatcher;
use OCA\Dossiq\Tests\Support\PhpInputStream;
use OCA\OpenRegister\Service\Notification\UnsubscribeHeaders;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-case-mail-asks-integriq-before-it-is-sent-req-coo-001
 */
class EmailControllerTest extends TestCase {

	private InMemoryEventDispatcher $dispatcher;

	private FakeIntegriqOptOuts $optOuts;

	private FakeMailGateway $gateway;

	private string $gateRelative = OptOutGate::DECISION_EVENT;

	protected function setUp(): void {
		$this->dispatcher = new InMemoryEventDispatcher();
		$this->optOuts = FakeIntegriqOptOuts::on($this->dispatcher);
		$this->gateway = new FakeMailGateway();
		$this->gateRelative = OptOutGate::DECISION_EVENT;
	}//end setUp()

	private function controller(): EmailController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				IntakeAccount::ACCOUNT_KEY => '7',
				'email_recipient_allowlist' => '*',
				default => $default,
			}
		);

		$repository = $this->createMock(CaseEmailRepository::class);
		$repository->method('loadCaseRecord')->willReturn(['identifier' => 'Z-2026-001']);
		$repository->method('recordSentEmail')->willReturn('msg-1');
		$repository->method('findTemplate')->willReturn(['subjectPattern' => 'Stand van zaken', 'body' => 'Uw zaak loopt']);
		$repository->method('loadCaseVariables')->willReturn([]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$caseTypes = $this->createMock(CaseTypeResolver::class);
		$caseTypes->method('effectiveCaseType')->willReturn([]);
		$store = $this->createMock(CaseTypeStore::class);
		$store->method('referenceId')->willReturn('');

		$service = new CaseEmailService(
			new NullLogger(),
			$repository,
			new CaseContactDirectory(),
			new OutboundCaseMail(
				$this->gateway,
				new SenderIdentity($this->gateway, new IntakeAccount($appConfig), $caseTypes, $store)
			),
			new RecipientAllowlist($appConfig),
			$this->createMock(CaseTimeline::class),
			new CaseMailOptOut(
				new OptOutGate($this->dispatcher, $appConfig, new NullLogger(), $this->gateRelative),
				$l10n,
				new UnsubscribeHeaders(new NullLogger())
			),
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		return new EmailController('dossiq', $this->createMock(IRequest::class), $service, $session);
	}//end controller()

	/**
	 * @param array<string,mixed> $body The JSON body.
	 */
	private function send(array $body): JSONResponse {
		$controller = $this->controller();
		return PhpInputStream::with(
			(string)json_encode($body),
			static fn (): JSONResponse => $controller->send('case-1')
		);
	}//end send()

	public function testAnOptedOutRecipientIsA409WithTheCode(): void {
		$this->optOuts->optOut('burger@example.nl', 'case-1');

		$response = $this->send(['to' => 'burger@example.nl', 'subject' => 'S', 'body' => 'B']);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('opted-out', $response->getData()['error']);
		$this->assertSame(0, count($this->gateway->sent));
	}//end testAnOptedOutRecipientIsA409WithTheCode()

	public function testWithoutIntegriqACaseUpdateIsA409AuthorityUnavailable(): void {
		$this->gateRelative = 'Event\\NoSuchDecisionEvent';

		$response = $this->send(['to' => 'burger@example.nl', 'subject' => 'S', 'body' => 'B']);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('authority-unavailable', $response->getData()['error']);
		$this->assertSame(0, count($this->gateway->sent));
	}//end testWithoutIntegriqACaseUpdateIsA409AuthorityUnavailable()

	public function testABesluitIsSentToAnOptedOutRecipient(): void {
		$this->optOuts->optOut('burger@example.nl');

		$response = $this->send(['to' => 'burger@example.nl', 'subject' => 'S', 'body' => 'B', 'category' => 'besluit']);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, count($this->gateway->sent));
		$this->assertSame('besluit', $this->optOuts->log[0]['category']);
	}//end testABesluitIsSentToAnOptedOutRecipient()

	public function testAHandlerCannotPickMarketing(): void {
		$response = $this->send(['to' => 'burger@example.nl', 'subject' => 'S', 'body' => 'B', 'category' => 'marketing']);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('invalid-category', $response->getData()['error']);
		$this->assertSame(0, count($this->gateway->sent));
		$this->assertSame([], $this->optOuts->log);
	}//end testAHandlerCannotPickMarketing()

	public function testAHandlerCannotPickStatutoryEither(): void {
		$response = $this->send(['to' => 'burger@example.nl', 'subject' => 'S', 'body' => 'B', 'category' => 'statutory']);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(0, count($this->gateway->sent));
	}//end testAHandlerCannotPickStatutoryEither()

	public function testATemplateSendToAnOptedOutRecipientIsA409(): void {
		$this->optOuts->optOut('burger@example.nl', 'case-1');
		$controller = $this->controller();

		$response = PhpInputStream::with(
			(string)json_encode(['templateId' => 'tpl-1', 'to' => 'burger@example.nl']),
			static fn (): JSONResponse => $controller->sendFromTemplate('case-1')
		);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('opted-out', $response->getData()['error']);
		$this->assertSame(0, count($this->gateway->sent));
	}//end testATemplateSendToAnOptedOutRecipientIsA409()

	/**
	 * A message the Mail account took but could not send is a 503 that says it is queued.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
	 */
	public function testAQueuedSendIsA503ThatSaysQueued(): void {
		$this->gateway->sendState = OutboundState::QUEUED;

		$response = $this->send(['to' => 'burger@example.nl', 'subject' => 'S', 'body' => 'B', 'category' => 'besluit']);

		$this->assertSame(503, $response->getStatus());
		$this->assertSame('mail-account-unavailable', $response->getData()['error']);
		$this->assertSame(OutboundState::QUEUED, $response->getData()['state']);
		$this->assertSame('msg-1', $response->getData()['messageId']);
	}//end testAQueuedSendIsA503ThatSaysQueued()

	/**
	 * An account the mail app cannot reach takes nothing and answers 503.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
	 */
	public function testAnUnreachableAccountIsA503(): void {
		$this->gateway->sendState = OutboundState::UNAVAILABLE;

		$response = $this->send(['to' => 'burger@example.nl', 'subject' => 'S', 'body' => 'B', 'category' => 'besluit']);

		$this->assertSame(503, $response->getStatus());
		$this->assertSame('mail-account-unavailable', $response->getData()['error']);
		$this->assertSame([], $this->gateway->sent);
	}//end testAnUnreachableAccountIsA503()
}//end class
