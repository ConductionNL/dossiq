<?php

/**
 * MessageReceivedListener writes as the background service account.
 *
 * Integriq raises MessageReceivedEvent from its own background work, with
 * nobody signed in, so OpenRegister refused the inbound e-mail filed on the
 * case and the intake log entry that records the decision ("User 'Anonymous'
 * does not have permission to 'create' objects in schema 'Mail intake
 * entry'", measured live). This drives the REAL listener and the REAL
 * services (CaseEmailRepository, UnmatchedMailIntake, IntakeLog,
 * AssigneeResolver) into a register that refuses a write from nobody.
 * Doubled: SettingsService (hands over the register and the schema slugs),
 * IAppConfig, ITimeFactory and CaseTimeline.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use DateTime;
use OCA\Dossiq\Listener\MessageReceivedListener;
use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Email\UnmatchedMailIntake;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCA\Integriq\Event\MessageReceivedEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An offered message is answered as the configured account, or not at all.
 *
 * @covers \OCA\Dossiq\Listener\MessageReceivedListener
 * @uses \OCA\Dossiq\Service\Email\CaseEmailRepository
 * @uses \OCA\Dossiq\Service\Email\UnmatchedMailIntake
 * @uses \OCA\Dossiq\Service\Email\IntakeLog
 * @uses \OCA\Dossiq\Service\AssigneeResolver
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 */
class MessageReceivedListenerServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The register the listener writes to.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * One case the message reference can name.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = $this->refusingRegister();
		$this->register->seed(schema: 'case', id: 'case-1', row: ['identifier' => '2026-114', 'title' => 'Aanvraag']);
	}//end setUp()

	/**
	 * The two paths that write: a reference that names a case, and a decline.
	 *
	 * @return array<string, array{0: string|null, 1: array<int, string>, 2: string, 3: string|null}> The rows.
	 */
	public static function paths(): array {
		return [
			'linked' => ['2026-114', ['emailMessage', 'mailIntakeEntry'], MessageReceivedListener::OUTCOME_LINKED, 'case-1'],
			'declined' => [null, ['mailIntakeEntry'], MessageReceivedListener::OUTCOME_DECLINED, null],
		];
	}//end paths()

	/**
	 * Nobody signed in: the answer is written as the service account.
	 *
	 * @param string|null        $reference The reference integriq detected.
	 * @param array<int, string> $schemas   The schemas the path writes.
	 * @param string             $outcome   The outcome the event must carry.
	 * @param string|null        $objectRef The case answered back.
	 *
	 * @return void
	 *
	 * @dataProvider paths
	 */
	public function testTheEventWritesAsTheServiceAccount(
		?string $reference,
		array $schemas,
		string $outcome,
		?string $objectRef,
	): void {
		$event = $this->event(reference: $reference);

		$this->listener()->handle($event);

		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: $schemas);
		$this->assertSame($outcome, $event->getOutcome());
		$this->assertSame($objectRef, $event->getObjectRef());
		$entries = array_values($this->register->rows['mailIntakeEntry']);
		$this->assertCount(1, $entries);
		$this->assertSame('msg-1', $entries[0]['channelMessageId']);
	}//end testTheEventWritesAsTheServiceAccount()

	/**
	 * A signed-in caller keeps writing as themselves.
	 *
	 * @return void
	 */
	public function testASignedInUserStaysTheWriter(): void {
		$this->acting = $this->backgroundUser(uid: 'behandelaar-1');
		$event = $this->event(reference: '2026-114');

		$this->listener()->handle($event);

		$this->assertSame([], $this->register->refusals);
		$this->assertSame(['behandelaar-1'], $this->register->writers());
		$this->assertSame('behandelaar-1', $this->actingUid());
		$this->assertSame(MessageReceivedListener::OUTCOME_LINKED, $event->getOutcome());
	}//end testASignedInUserStaysTheWriter()

	/**
	 * Without an account nothing is written or even attempted, and the offer stays unanswered.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheEventWritesNothing(): void {
		$this->configuredAccount = '';
		$event = $this->event(reference: '2026-114');

		$this->listener()->handle($event);

		$this->assertSame([], $this->register->writes);
		$this->assertSame([], $this->register->refusals);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertNull($event->getOutcome());
	}//end testWithoutAnAccountTheEventWritesNothing()

	/**
	 * One offered message, as integriq raises it.
	 *
	 * @param string|null $reference The reference integriq detected.
	 *
	 * @return MessageReceivedEvent The event.
	 */
	private function event(?string $reference): MessageReceivedEvent {
		return new MessageReceivedEvent(
			message: [
				'from' => 'a.burger@example.nl',
				'to' => 'zaken@gemeente.nl',
				'subject' => 'Vraag over 2026-114',
				'body' => 'Wanneer hoor ik iets?',
			],
			detectedReference: $reference,
			messageUuid: 'msg-1',
			sourceId: 'mailbox-1',
		);
	}//end event()

	/**
	 * The real listener over the real services.
	 *
	 * @return MessageReceivedListener The listener.
	 */
	private function listener(): MessageReceivedListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				'email_message_schema' => 'emailMessage',
				IntakeLog::SCHEMA_KEY => 'mailIntakeEntry',
				default => $default,
			}
		);

		// No fallback case type, so a message naming no case is declined.
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-07T09:00:00+00:00'));

		$logger = new NullLogger();

		return $this->buildWith(
			MessageReceivedListener::class,
			[
				'cases' => new CaseEmailRepository(settingsService: $settings),
				'unmatched' => new UnmatchedMailIntake(
					settingsService: $settings,
					assignees: new AssigneeResolver(logger: $logger),
					appConfig: $appConfig,
					logger: $logger,
				),
				'log' => new IntakeLog(
					settingsService: $settings,
					time: $time,
					logger: $logger,
					timeline: $this->createMock(CaseTimeline::class),
				),
				'logger' => $logger,
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end listener()
}//end class
