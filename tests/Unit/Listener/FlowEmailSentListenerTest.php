<?php

/**
 * Unit tests for FlowEmailSentListener.
 *
 * Built on the REAL FlowEmailSentEvent (a verbatim copy of OpenRegister's class
 * when that OpenRegister is absent), and on the REAL CaseEmailService with its
 * repository and timeline as doubles, so the test asserts the record the case
 * gets rather than that some method was called.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\FlowEmailSentListener;
use OCA\Dossiq\Service\CaseEmailService;
use OCA\Dossiq\Service\Email\CaseMailOptOut;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\Email\CaseEmailAttachmentResolver;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\RecipientAllowlist;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\CaseObjectReference;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use OCA\OpenRegister\Event\FlowEmailSentEvent;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Listener\FlowEmailSentListener
 * @covers \OCA\Dossiq\Service\CaseEmailService::recordSentEmail
 */
class FlowEmailSentListenerTest extends TestCase {

	/**
	 * What the repository stored.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $stored = [];

	/**
	 * What the timeline recorded.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $lines = [];

	/**
	 * The listener over the real email service.
	 *
	 * @param bool $repositoryThrows Whether storing the message fails.
	 *
	 * @return FlowEmailSentListener The listener.
	 */
	private function listener(bool $repositoryThrows = false): FlowEmailSentListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => '12', 'case_schema' => '34'][$key] ?? ''
		);

		$repository = $this->createMock(CaseEmailRepository::class);
		$repository->method('recordSentEmail')->willReturnCallback(
			function (string $caseId, string $fromAddress, string $to, string $subject, string $body) use ($repositoryThrows): string {
				if ($repositoryThrows === true) {
					throw new RuntimeException('store down');
				}

				$this->stored[] = ['caseId' => $caseId, 'fromAddress' => $fromAddress, 'to' => $to, 'subject' => $subject, 'body' => $body];

				return 'msg-1';
			}
		);

		$timeline = $this->createMock(CaseTimeline::class);
		$timeline->method('record')->willReturnCallback(
			function (string $caseId, string $kind, string $message, array $fields = [], string $visibility = ''): string {
				$this->lines[] = ['caseId' => $caseId, 'kind' => $kind, 'message' => $message, 'fields' => $fields, 'visibility' => $visibility];

				return 'line-1';
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'email_from_address') ? 'zaken@gemeente.example' : $default
		);

		$emails = new CaseEmailService(
			$this->createMock(IMailer::class),
			$appConfig,
			new NullLogger(),
			$repository,
			$this->createMock(CaseContactDirectory::class),
			$this->createMock(CaseEmailAttachmentResolver::class),
			$this->createMock(RecipientAllowlist::class),
			$timeline,
			$this->createMock(CaseMailOptOut::class)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('jan@gemeente.example');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $uid === 'jan' ? $user : null);

		return new FlowEmailSentListener(new CaseObjectReference($settings), $emails, $users, $appConfig, new NullLogger());
	}//end listener()

	/**
	 * A mail as OpenRegister announces it.
	 *
	 * @param string      $recipient The recipient.
	 * @param string      $kind      The channel kind.
	 * @param string|null $schema    The item's schema.
	 *
	 * @return FlowEmailSentEvent The event.
	 */
	private function event(string $recipient, string $kind, ?string $schema = '34'): FlowEmailSentEvent {
		return new FlowEmailSentEvent(
			register: '12',
			schema: $schema,
			objectUuid: 'case-1',
			recipient: $recipient,
			channelKind: $kind,
			subject: 'Uw aanvraag',
			body: 'Wij hebben uw aanvraag ontvangen.',
			flowId: 'flow-1',
			runId: 'run-1',
			stepName: 'mail-indiener',
			actingUser: 'jan'
		);
	}//end event()

	/**
	 * A mail to an address about a case is stored on the case and gets its timeline line.
	 *
	 * @return void
	 */
	public function testAMailAboutACaseIsRecordedOnTheCase(): void {
		$this->listener()->handle($this->event('indiener@example.org', FlowEmailSentEvent::KIND_EXTERNAL));

		self::assertSame(
			[['caseId' => 'case-1', 'fromAddress' => 'zaken@gemeente.example', 'to' => 'indiener@example.org', 'subject' => 'Uw aanvraag', 'body' => 'Wij hebben uw aanvraag ontvangen.']],
			$this->stored
		);
		self::assertCount(1, $this->lines);
		self::assertSame(TimelineKinds::MAIL_OUT, $this->lines[0]['kind']);
		self::assertSame('indiener@example.org', $this->lines[0]['fields']['recipient']);
		self::assertSame('msg-1', $this->lines[0]['fields']['documentId']);
	}//end testAMailAboutACaseIsRecordedOnTheCase()

	/**
	 * A mail to a user is recorded under the user's address.
	 *
	 * @return void
	 */
	public function testAMailToAUserIsRecordedUnderTheirAddress(): void {
		$this->listener()->handle($this->event('jan', FlowEmailSentEvent::KIND_USER));

		self::assertSame('jan@gemeente.example', $this->stored[0]['to']);
	}//end testAMailToAUserIsRecordedUnderTheirAddress()

	/**
	 * A mail about something that is not a dossiq case is not recorded.
	 *
	 * @return void
	 */
	public function testAMailAboutAnotherObjectIsLeftAlone(): void {
		$this->listener()->handle($this->event('a@example.org', FlowEmailSentEvent::KIND_EXTERNAL, schema: '99'));
		$this->listener()->handle($this->event('a@example.org', FlowEmailSentEvent::KIND_EXTERNAL, schema: null));

		self::assertSame([], $this->stored);
		self::assertSame([], $this->lines);
	}//end testAMailAboutAnotherObjectIsLeftAlone()

	/**
	 * A failed record is logged and does not reach the step, which would send the mail again.
	 *
	 * @return void
	 */
	public function testAFailedRecordDoesNotFailTheSend(): void {
		$this->listener(repositoryThrows: true)->handle($this->event('a@example.org', FlowEmailSentEvent::KIND_EXTERNAL));

		self::assertSame([], $this->lines);
	}//end testAFailedRecordDoesNotFailTheSend()
}//end class
