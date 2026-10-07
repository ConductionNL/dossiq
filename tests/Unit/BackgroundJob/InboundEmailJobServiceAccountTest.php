<?php

/**
 * InboundEmailJob writes as the background service account.
 *
 * Cron has no user, so OpenRegister refused every intake write as Anonymous:
 * the case an unmatched mail should have opened, the document filing it, and
 * the intake log entry that says what happened to it. The mail was read and
 * nothing recorded it. This drives the REAL job and the REAL intake path
 * (InboundMailIntake, FilterPipeline, AuthenticationVerdict, ThreadingCheck,
 * IntakePolicy, IntakeLog, CaseEmailRepository, UnmatchedMailIntake,
 * AssigneeResolver, EmailArchivalService) over FakeMailGateway into a register
 * that refuses a write from nobody. Doubled: SettingsService (hands over the
 * register and the schema slugs), IAppConfig, IGroupManager, ITimeFactory,
 * CaseTimeline and CorrespondentWriter.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\Dossiq\BackgroundJob\InboundEmailJob;
use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\Email\AuthenticationVerdict;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\Filters\FilterPipeline;
use OCA\Dossiq\Service\Email\InboundMailIntake;
use OCA\Dossiq\Service\Email\IntakeAccount;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Email\IntakePolicy;
use OCA\Dossiq\Service\Email\ThreadingCheck;
use OCA\Dossiq\Service\Email\UnmatchedMailIntake;
use OCA\Dossiq\Service\EmailArchivalService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Zaakdossier\CorrespondentWriter;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\FakeMailGateway;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Mail intake runs as the configured account, or not at all.
 *
 * @covers \OCA\Dossiq\BackgroundJob\InboundEmailJob
 * @uses \OCA\Dossiq\Service\Email\InboundMailIntake
 * @uses \OCA\Dossiq\Service\Email\IntakeLog
 * @uses \OCA\Dossiq\Service\Email\UnmatchedMailIntake
 * @uses \OCA\Dossiq\Service\EmailArchivalService
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 */
class InboundEmailJobServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The register the intake writes to.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * The mail app.
	 *
	 * @var FakeMailGateway
	 */
	private FakeMailGateway $gateway;

	/**
	 * How many timeline entries were asked for.
	 *
	 * @var int
	 */
	private int $timelineCalls = 0;

	/**
	 * One unmatched message in the intake folder, and the fallback case type.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->timelineCalls = 0;
		$this->register = $this->refusingRegister();
		$this->register->seed(schema: 'caseType', id: 'ct-1', row: ['title' => 'Algemene vraag']);

		$this->gateway = new FakeMailGateway();
		$this->gateway->messages['7|INBOX'] = [
			[
				'id' => 11,
				'uid' => 11,
				'messageId' => 'abc@example.org',
				'subject' => 'Vraag over mijn aanvraag',
				'from' => 'Inwoner <inwoner@example.org>',
				'to' => 'zaken@gemeente.nl',
				'sentAt' => '2026-10-07T09:00:00+00:00',
			],
		];
	}//end setUp()

	/**
	 * One run files the message as a case and logs it, as the service account.
	 *
	 * @return void
	 */
	public function testTheRunWritesAsTheServiceAccount(): void {
		$this->runJobOnce(job: $this->job());

		$this->assertWroteAsTheServiceAccount(
			register: $this->register,
			schemas: ['case', 'caseDocument', 'mailIntakeEntry']
		);

		$entries = array_values($this->register->rows['mailIntakeEntry']);
		$this->assertCount(1, $entries);
		$this->assertSame(IntakeLog::OUTCOME_CASE, $entries[0]['outcome']);
		$this->assertSame('abc@example.org', $entries[0]['mailMessageId']);

		$cases = array_values($this->register->rows['case']);
		$this->assertSame('Vraag over mijn aanvraag', $cases[0]['title']);
		$this->assertSame($cases[0]['id'], $entries[0]['case']);
		$this->assertSame(1, $this->timelineCalls);
	}//end testTheRunWritesAsTheServiceAccount()

	/**
	 * Without an account nothing is read, written or even attempted.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheRunWritesNothing(): void {
		$this->configuredAccount = '';

		$this->runJobOnce(job: $this->job());

		$this->assertSame([], $this->register->writes);
		$this->assertSame([], $this->register->refusals);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertSame(0, $this->timelineCalls);
		$this->assertSame([], $this->gateway->moves);
	}//end testWithoutAnAccountTheRunWritesNothing()

	/**
	 * The real job over the real intake.
	 *
	 * @return object The job.
	 */
	private function job(): object {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				IntakeAccount::ACCOUNT_KEY => '7',
				IntakeAccount::FOLDER_KEY => 'INBOX',
				UnmatchedMailIntake::FALLBACK_CASE_TYPE_KEY => 'ct-1',
				default => $default,
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				'case_document_schema' => 'caseDocument',
				IntakeLog::SCHEMA_KEY => 'mailIntakeEntry',
				default => $default,
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-07T09:05:00+00:00'));

		$timeline = $this->createMock(CaseTimeline::class);
		$timeline->method($this->anything())->willReturnCallback(
			function (): mixed {
				$this->timelineCalls++;
				return null;
			}
		);

		$correspondents = $this->createMock(CorrespondentWriter::class);
		$correspondents->method('partyHolding')->willReturn('');

		$threading = new ThreadingCheck(gateway: $this->gateway);
		$logger = new NullLogger();

		$intake = new InboundMailIntake(
			gateway: $this->gateway,
			pipeline: new FilterPipeline(logger: $logger),
			verdicts: new AuthenticationVerdict(gateway: $this->gateway, threading: $threading),
			threading: $threading,
			policy: new IntakePolicy(
				settingsService: $settings,
				appConfig: $appConfig,
				groupManager: $this->createMock(IGroupManager::class),
				logger: $logger,
			),
			log: new IntakeLog(settingsService: $settings, time: $time, logger: $logger, timeline: $timeline),
			cases: new CaseEmailRepository(settingsService: $settings),
			unmatched: new UnmatchedMailIntake(
				settingsService: $settings,
				assignees: new AssigneeResolver(logger: $logger),
				appConfig: $appConfig,
				logger: $logger,
			),
			archival: new EmailArchivalService(
				settingsService: $settings,
				logger: $logger,
				correspondents: $correspondents,
			),
			logger: $logger,
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);

		return $this->buildWith(
			InboundEmailJob::class,
			[
				'time' => $this->createMock(ITimeFactory::class),
				'appConfig' => $appConfig,
				'appManager' => $apps,
				'gateway' => $this->gateway,
				'account' => new IntakeAccount(appConfig: $appConfig),
				'intake' => $intake,
				'logger' => $logger,
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end job()
}//end class
