<?php

/**
 * AcknowledgementDispatchJob writes as the background service account.
 *
 * Cron has no user, so OpenRegister refused the case write as Anonymous: the
 * mail went out and the duty never read met. These tests drive the REAL job
 * and the REAL AcknowledgementService, CaseFieldWriter and TermijnService into
 * a register that refuses a write from nobody and records who wrote.
 *
 * Mocked, because none of them writes to OpenRegister: the case type lookups
 * (reads), the mail sender, the timeline seam and the job list.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\AcknowledgementDispatchJob;
use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Service\AcknowledgementService;
use OCA\Dossiq\Service\Notification\RequesterNoticeSender;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\CaseTypeAcknowledgement;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\TermNoticeSender;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCA\Dossiq\Tests\Support\MakesRealTermNoticeSender;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The acknowledgement is recorded as the configured account, or the job waits.
 *
 * @covers \OCA\Dossiq\BackgroundJob\AcknowledgementDispatchJob
 * @uses \OCA\Dossiq\Service\AcknowledgementService
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Portal\PortalContributionProvider
 * @uses \OCA\Dossiq\Service\CaseFieldWriter
 * @uses \OCA\Dossiq\Service\CaseTypeAcknowledgement
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 * @uses \OCA\Dossiq\Service\Email\CaseContactDirectory
 * @uses \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 * @uses \OCA\Dossiq\Service\TermijnNotificationService
 * @uses \OCA\Dossiq\Service\TermijnService
 * @uses \OCA\Dossiq\Service\Termijn\TermDefinitions
 * @uses \OCA\Dossiq\Service\Termijn\TermInstanceStore
 * @uses \OCA\Dossiq\Service\Termijn\TermLetters
 * @uses \OCA\Dossiq\Service\Notification\RequesterNoticeSender
 * @uses \OCA\Dossiq\Service\Termijn\TermNoticeSender
 * @uses \OCA\Dossiq\Exception\NoticeNotSentException
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Email\CaseMailOptOut
 * @uses \OCA\Dossiq\Service\OptOutGate
 * @uses \OCA\Dossiq\Support\FleetAppId
 */
class AcknowledgementDispatchJobServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;
	use MakesRealTermNoticeSender;

	/**
	 * The register the job writes to.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * How many mails the sender was asked to send.
	 *
	 * @var int
	 */
	private int $sent = 0;

	/**
	 * Every job the run queued: [class, argument].
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $queued = [];

	/**
	 * Seed one electronic case that owes an acknowledgement.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = $this->refusingRegister();
		$this->sent = 0;
		$this->queued = [];
		$this->register->seed(
			schema: 'case',
			id: 'case-1',
			row: [
				'caseType' => 'ct-1',
				'identifier' => '2026-0042',
				'title' => 'Dakkapel Kerkstraat 12',
				'intakeChannel' => 'website',
				'email' => 'aanvrager@example.nl',
			]
		);
	}//end setUp()

	/**
	 * One run records the met duty on the case as the service account.
	 *
	 * @return void
	 */
	public function testTheRunWritesAsTheServiceAccount(): void {
		$this->runJobOnce(job: $this->job(), argument: ['caseId' => 'case-1', 'attempt' => 1]);

		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: ['case']);
		$this->assertSame(
			AcknowledgementService::STATUS_MET,
			$this->register->row(schema: 'case', id: 'case-1')['acknowledgementDuty']['status']
		);
		$this->assertSame(1, $this->sent);
		$this->assertSame([], $this->queued);
	}//end testTheRunWritesAsTheServiceAccount()

	/**
	 * Without an account nothing is sent or written, and the same argument is queued again.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheRunWritesNothing(): void {
		$this->configuredAccount = '';
		$argument = ['caseId' => 'case-1', 'attempt' => 2];

		$this->runJobOnce(job: $this->job(), argument: $argument);

		$this->assertSame([], $this->register->writes);
		$this->assertSame([], $this->register->refusals);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertSame(0, $this->sent, 'A mail went out without an account to record it.');
		$this->assertSame([[AcknowledgementDispatchJob::class, $argument]], $this->queued);
		$this->assertNull($this->acting);
	}//end testWithoutAnAccountTheRunWritesNothing()

	/**
	 * REQ-WRN-003 through the caller: three runs whose acknowledgement the mail
	 * server refused leave the duty unmet, with the transport's reason as the
	 * last error, on the real AcknowledgementService.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-the-acknowledgement-duty-is-met-only-when-the-acknowledgement-went-out-req-wrn-003
	 */
	public function testThreeUnsentAttemptsLeaveTheDutyUnmet(): void {
		$job = $this->job(realSender: $this->realTermNoticeSender(mailerThrows: true));

		$this->runJobOnce(job: $job, argument: ['caseId' => 'case-1', 'attempt' => 1]);
		$this->assertSame(
			AcknowledgementService::STATUS_PENDING,
			$this->register->row(schema: 'case', id: 'case-1')['acknowledgementDuty']['status']
		);

		$this->runJobOnce(job: $job, argument: ['caseId' => 'case-1', 'attempt' => 2]);
		$this->runJobOnce(job: $job, argument: ['caseId' => 'case-1', 'attempt' => 3]);

		$duty = $this->register->row(schema: 'case', id: 'case-1')['acknowledgementDuty'];
		$this->assertSame(AcknowledgementService::STATUS_UNMET, $duty['status']);
		$this->assertSame(3, $duty['attempts']);
		$this->assertStringContainsString('mail server', $duty['lastError']);
		$this->assertSame(
			[
				[AcknowledgementDispatchJob::class, ['caseId' => 'case-1', 'attempt' => 2]],
				[AcknowledgementDispatchJob::class, ['caseId' => 'case-1', 'attempt' => 3]],
			],
			$this->queued,
			'two retries are queued, and none after the last attempt'
		);
		$this->assertSame([], $this->mailed);
	}//end testThreeUnsentAttemptsLeaveTheDutyUnmet()

	/**
	 * The real job over the real acknowledgement service.
	 *
	 * @return AcknowledgementDispatchJob The job.
	 */
	private function job(?TermNoticeSender $realSender = null): AcknowledgementDispatchJob {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'termijn_instance_schema' => 'termijnInstance',
				default => $default,
			}
		);

		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturn([]);

		$store = $this->createMock(CaseTypeStore::class);
		$store->method('referenceId')->willReturnCallback(static fn (mixed $value): string => (string)$value);

		$sender = $this->createMock(TermNoticeSender::class);
		$sender->method('send')->willReturnCallback(
			function (): array {
				$this->sent++;
				return ['notificationChannel' => 'email', 'sent' => true, 'duplicate' => false];
			}
		);

		if ($realSender !== null) {
			$sender = $realSender;
		}

		$terms = new TermijnService(settingsService: $settings, logger: new NullLogger());

		$acknowledgement = new AcknowledgementService(
			settingsService: $settings,
			caseTypeResolver: $resolver,
			store: $store,
			declaration: new CaseTypeAcknowledgement(),
			termService: $terms,
			notifications: new TermijnNotificationService(
				termService: $terms,
				sender: $sender,
				logger: new NullLogger(),
				requester: new RequesterNoticeSender(email: $sender, settings: $settings, writer: new CaseFieldWriter()),
			),
			contacts: new CaseContactDirectory(),
			portal: new PortalContributionProvider(),
			writer: new CaseFieldWriter(),
			logger: new NullLogger(),
			timeline: $this->createMock(CaseTimeline::class),
		);

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (mixed $job, mixed $argument = null): void {
				$this->queued[] = [(string)$job, $argument];
			}
		);

		return $this->buildWith(
			AcknowledgementDispatchJob::class,
			[
				'time' => $this->createMock(ITimeFactory::class),
				'acknowledgement' => $acknowledgement,
				'jobList' => $jobList,
				'logger' => new NullLogger(),
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end job()
}//end class
