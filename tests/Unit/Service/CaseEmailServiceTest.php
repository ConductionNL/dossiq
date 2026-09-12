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

use OCA\Dossiq\Service\CaseEmailService;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\Email\CaseEmailAttachmentResolver;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\RecipientAllowlist;
use OCA\Dossiq\Service\SettingsService;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Security-focused unit tests for CaseEmailService.
 *
 * Covers C4 (IDOR + file-disclosure), H6 (XSS + reserved-domain), L1 (log-injection).
 *
 * @covers \OCA\Dossiq\Service\CaseEmailService
 *
 * @uses \OCA\Dossiq\Service\Email\CaseContactDirectory
 * @uses \OCA\Dossiq\Service\Email\CaseEmailAttachmentResolver
 * @uses \OCA\Dossiq\Service\Email\CaseEmailRepository
 * @uses \OCA\Dossiq\Service\Email\RecipientAllowlist
 */
class CaseEmailServiceTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The mocked mailer.
	 *
	 * @var IMailer|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IMailer $mailer;

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
	 * The mocked root folder.
	 *
	 * @var IRootFolder|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRootFolder $rootFolder;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The service under test.
	 *
	 * @var CaseEmailService
	 */
	private CaseEmailService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userSession = $this->createMock(IUserSession::class);

		// The repository and contact directory are real collaborators, not mocks:
		// every assertion below is about behaviour they inherited verbatim from
		// CaseEmailService, and the repository is still driven entirely by the
		// mocked SettingsService (getObjectService() === null ⇒ no case data).
		$this->service = new CaseEmailService(
			$this->mailer,
			$this->appConfig,
			$this->logger,
			new CaseEmailRepository($this->settingsService),
			new CaseContactDirectory(),
			new CaseEmailAttachmentResolver($this->rootFolder, $this->userSession, $this->logger),
			new RecipientAllowlist($this->appConfig),
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
	 * @param string $fromAddress The configured envelope from-address
	 * @param string $allowlist The configured allow-list value
	 * @param array<string, mixed> $caseRecord The raw case record OR returns
	 *
	 * @return CaseEmailService The service under test
	 */
	private function serviceWithCase(
		string $fromAddress,
		string $allowlist,
		array $caseRecord = ['identifier' => '2026-0001', 'title' => 'Dakkapel'],
	): CaseEmailService {
		$this->appConfig
			->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') use ($fromAddress, $allowlist): string {
					return match ($key) {
						'email_from_address' => $fromAddress,
						'email_recipient_allowlist' => $allowlist,
						default => $default,
					};
				}
			);

		$repository = $this->createMock(CaseEmailRepository::class);
		$repository->method('loadCaseRecord')->willReturn($caseRecord);
		$repository->method('recordSentEmail')->willReturn('msg-test');

		return new CaseEmailService(
			$this->mailer,
			$this->appConfig,
			$this->logger,
			$repository,
			new CaseContactDirectory(),
			new CaseEmailAttachmentResolver($this->rootFolder, $this->userSession, $this->logger),
			new RecipientAllowlist($this->appConfig),
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

		// The mailer is fully stubbed, NOT constrained to never(): a guard that
		// wrongly passes must then reach a working send() and fail this test on
		// the missing exception. A never() expectation here would be swallowed by
		// dispatchMessage()'s catch and reported as 'email_send_failed', which
		// hides which assertion actually broke. The never() case is asserted by
		// testUnconfiguredAllowlistRejectsAForeignDomain.
		$this->mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));

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

		$this->mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$this->mailer->expects($this->once())->method('send');

		$result = $service->sendEmail(
			caseId: 'case-1',
			to: 'TEAM@partner.nl',
			subject: 'Hallo',
			body: 'Tekst',
		);

		$this->assertSame('TEAM@partner.nl', $result['to']);
	}//end testSendEmailAllowsRecipientOnConfiguredAllowlist()

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

		$this->mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$this->mailer->expects($this->once())->method('send');

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

		$this->mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$this->mailer->expects($this->never())->method('send');

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

		$this->mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$this->mailer->expects($this->once())->method('send');

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

		$this->mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$this->mailer->expects($this->once())->method('send');

		$result = $service->sendEmail(
			caseId: 'case-1',
			to: 'burger@elders.example',
			subject: 'Hallo',
			body: 'Tekst',
		);

		$this->assertSame('burger@elders.example', $result['to']);
	}//end testCaseContactIsAllowedEvenOffDomain()

	/**
	 * H6: sendEmail throws when from-address is empty.
	 *
	 * @return void
	 */
	public function testSendEmailThrowsWhenFromAddressEmpty(): void {
		$this->appConfig
			->method('getValueString')
			->willReturnCallback(
				function (string $app, string $key, string $default = '') {
					if ($key === 'email_from_address') {
						return '';
					}

					return $default;
				}
			);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/geconfigureerd/i');

		$this->service->sendEmail('case-uuid', 'to@example.com', 'Subject', 'Body');

	}//end testSendEmailThrowsWhenFromAddressEmpty()

	/**
	 * H6: sendEmail throws when from-address is the reserved example.nl domain.
	 *
	 * @return void
	 */
	public function testSendEmailThrowsWhenFromAddressIsReservedDomain(): void {
		$this->appConfig
			->method('getValueString')
			->willReturnCallback(
				function (string $app, string $key, string $default = '') {
					if ($key === 'email_from_address') {
						return 'noreply@example.nl';
					}

					return $default;
				}
			);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/geconfigureerd/i');

		$this->service->sendEmail('case-uuid', 'to@example.com', 'Subject', 'Body');

	}//end testSendEmailThrowsWhenFromAddressIsReservedDomain()

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
}//end class
