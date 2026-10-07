<?php

/**
 * IntakeMessageRoutedListener writes as the background service account.
 *
 * Integriq raises IntakeMessageRoutedEvent from its own background work, with
 * nobody signed in, so OpenRegister refused the case a routed message should
 * open and the channel intake log entry that records it ("User 'Anonymous'
 * does not have permission to 'create' objects in schema 'Mail intake
 * entry'", measured live). This drives the REAL listener and the REAL intake
 * path (ChannelIntake, IntakeLog, MessageFacts) into a register that refuses
 * a write from nobody. Doubled: SettingsService (hands over the register and
 * the schema slugs), ITimeFactory, CaseTimeline and CaseDateNormaliser.
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
use DateTimeImmutable;
use OCA\Dossiq\Listener\IntakeMessageRoutedListener;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Intake\ChannelIntake;
use OCA\Dossiq\Service\Intake\MessageFacts;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCA\Integriq\Event\IntakeMessageRoutedEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A routed message opens its case as the configured account, or not at all.
 *
 * @covers \OCA\Dossiq\Listener\IntakeMessageRoutedListener
 * @uses \OCA\Dossiq\Service\Intake\ChannelIntake
 * @uses \OCA\Dossiq\Service\Intake\MessageFacts
 * @uses \OCA\Dossiq\Service\Email\IntakeLog
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 */
class IntakeMessageRoutedListenerServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The register the intake writes to.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * The case type the routing rule names.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = $this->refusingRegister();
		$this->register->seed(schema: 'caseType', id: 'ct-mor', row: ['title' => 'Melding', 'initialStatus' => 'status-ontvangen']);
	}//end setUp()

	/**
	 * Nobody signed in: the case and its log entry are written as the service account.
	 *
	 * @return void
	 */
	public function testTheEventWritesAsTheServiceAccount(): void {
		$event = $this->event();

		$this->listener()->handle($event);

		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: ['case', 'mailIntakeEntry']);
		$cases = array_values($this->register->rows['case']);
		$this->assertCount(1, $cases);
		$this->assertSame($cases[0]['id'], $event->getCreatedRef());
		$entries = array_values($this->register->rows['mailIntakeEntry']);
		$this->assertSame(IntakeLog::OUTCOME_CASE, $entries[0]['outcome']);
		$this->assertSame($cases[0]['id'], $entries[0]['case']);
	}//end testTheEventWritesAsTheServiceAccount()

	/**
	 * A signed-in caller keeps writing as themselves.
	 *
	 * @return void
	 */
	public function testASignedInUserStaysTheWriter(): void {
		$this->acting = $this->backgroundUser(uid: 'behandelaar-1');
		$event = $this->event();

		$this->listener()->handle($event);

		$this->assertSame([], $this->register->refusals);
		$this->assertSame(['behandelaar-1'], $this->register->writers());
		$this->assertSame('behandelaar-1', $this->actingUid());
		$this->assertNotNull($event->getCreatedRef());
	}//end testASignedInUserStaysTheWriter()

	/**
	 * Without an account nothing is written or even attempted, and the slot stays empty.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheEventWritesNothing(): void {
		$this->configuredAccount = '';
		$event = $this->event();

		$this->listener()->handle($event);

		$this->assertSame([], $this->register->writes);
		$this->assertSame([], $this->register->refusals);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertNull($event->getCreatedRef());
	}//end testWithoutAnAccountTheEventWritesNothing()

	/**
	 * One routed message, as integriq raises it.
	 *
	 * @return IntakeMessageRoutedEvent The event.
	 */
	private function event(): IntakeMessageRoutedEvent {
		return new IntakeMessageRoutedEvent(
			message: [
				'channelId' => 'teams',
				'externalId' => 'teams-msg-9',
				'correspondent' => ['handle' => 'jan@example.org'],
				'text' => "Lantaarnpaal kapot\nOp de hoek bij de school.",
				'receivedAt' => '2026-10-07T08:30:00+00:00',
			],
			targetSchema: 'case',
			targetPayload: ['caseType' => 'ct-mor'],
			files: [],
			messageUuid: 'im-1',
			ruleName: 'Meldingen via Teams',
		);
	}//end event()

	/**
	 * The real listener over the real intake.
	 *
	 * @return IntakeMessageRoutedListener The listener.
	 */
	private function listener(): IntakeMessageRoutedListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				IntakeLog::SCHEMA_KEY => 'mailIntakeEntry',
				default => $default,
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-07T09:00:00+00:00'));

		$dates = $this->createMock(CaseDateNormaliser::class);
		$dates->method('toCalendarDateOrNull')->willReturn('2026-10-07');
		$dates->method('today')->willReturn(new DateTimeImmutable('2026-10-07 00:00:00'));

		$logger = new NullLogger();
		$intake = new ChannelIntake(
			settingsService: $settings,
			log: new IntakeLog(
				settingsService: $settings,
				time: $time,
				logger: $logger,
				timeline: $this->createMock(CaseTimeline::class),
			),
			logger: $logger,
			facts: new MessageFacts($dates),
		);

		return $this->buildWith(
			IntakeMessageRoutedListener::class,
			[
				'intake' => $intake,
				'logger' => $logger,
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end listener()
}//end class
