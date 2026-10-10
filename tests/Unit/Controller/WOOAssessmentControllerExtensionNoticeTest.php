<?php

/**
 * Extending a Woo term tells the requester, with the reason and the new end date.
 *
 * Driven through WOOAssessmentController::extendDeadline(), over the real
 * WOODeadlineService, WooTermExtension and term engine, the real
 * ExtensionNotice, TermijnNotificationService and RequesterNoticeSender, and
 * the real e-mail transport. Only the register is a fake.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-an-extension-reaches-the-requester-with-its-reason-req-wrn-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\WOOAssessmentController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\Notification\RequesterNoticeSender;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\CaseDeadlineFollower;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\Dossiq\Service\Termijn\ExtensionNotice;
use OCA\Dossiq\Service\Termijn\WooTermExtension;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\WOOAnonymisationAssistService;
use OCA\Dossiq\Service\WOODeadlineService;
use OCA\Dossiq\Service\WOODecisionService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\WooPublicationService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Support\MakesRealTermNoticeSender;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Controller\WOOAssessmentController
 * @covers \OCA\Dossiq\Service\WOODeadlineService
 * @covers \OCA\Dossiq\Service\Termijn\WooTermExtension
 * @covers \OCA\Dossiq\Service\Termijn\ExtensionNotice
 * @uses \OCA\Dossiq\Service\CaseAccessGuard
 * @uses \OCA\Dossiq\Service\Notification\RequesterNoticeSender
 * @uses \OCA\Dossiq\Service\TermijnNotificationService
 * @uses \OCA\Dossiq\Service\Termijn\TermLetters
 * @uses \OCA\Dossiq\Service\Termijn\TermNoticeSender
 * @uses \OCA\Dossiq\Service\Termijn\TermKindClassifier
 * @uses \OCA\Dossiq\Service\Email\CaseContactDirectory
 * @uses \OCA\Dossiq\Service\Email\CaseMailOptOut
 * @uses \OCA\Dossiq\Service\OptOutGate
 * @uses \OCA\Dossiq\Service\CaseFieldWriter
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\TermijnService
 * @uses \OCA\Dossiq\Service\DeadlineExtensionService
 * @uses \OCA\Dossiq\Service\TermijnTimerService
 * @uses \OCA\Dossiq\Service\TermKind
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
 * @uses \OCA\Dossiq\Service\Termijn\CaseDeadlineFollower
 * @uses \OCA\Dossiq\Service\Termijn\CaseDeadlineMirror
 * @uses \OCA\Dossiq\Service\Termijn\TermDefinitions
 * @uses \OCA\Dossiq\Service\Termijn\TermEndRoll
 * @uses \OCA\Dossiq\Service\Termijn\TermInstanceStore
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Exception\NoticeNotSentException
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class WOOAssessmentControllerExtensionNoticeTest extends TestCase {
	use MakesCaseDateNormaliser;
	use MakesRealTermNoticeSender;

	/**
	 * The register.
	 *
	 * @var FakeTermijnStore
	 */
	private FakeTermijnStore $store;

	/**
	 * Seed the Woo definition and a running term on case-woo.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new FakeTermijnStore();
		$this->mailed = [];

		$seed = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/termijnbewaking_seed_data.json'), true);
		foreach ($seed['termijnDefinities'] as $definition) {
			if ($definition['caseType'] === 'woo-verzoek') {
				$this->store->seed('deadlineDefinition', $definition);
			}
		}

		$this->store->seed('deadlineInstance', [
			'id' => 'ti-woo',
			'case' => 'case-woo',
			'kind' => 'statutory',
			'deadlineDefinition' => 'td-woo-verzoek',
			'startDate' => '2026-10-05T09:00:00+02:00',
			'endDateCalculated' => '2026-11-02',
			'endDateCurrent' => '2026-11-02',
			'status' => 'lopend',
			'countExtensions' => 0,
		]);
	}//end setUp()

	/**
	 * The controller over the real chain.
	 *
	 * @param string $reason The reason the handler gives.
	 *
	 * @return WOOAssessmentController The controller.
	 */
	private function controller(string $reason): WOOAssessmentController {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getOpenRegisterClass')->willReturn(null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'portaal_bericht_schema' => 'portaalBericht',
				'termijn_definitie_schema' => 'deadlineDefinition',
				'termijn_instance_schema' => 'deadlineInstance',
				'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
				default => '',
			}
		);

		$logger = new NullLogger();
		$timer = new TermijnTimerService(settingsService: $settings, logger: $logger, dates: $this->caseDates(), fallbackCalendar: new WorkingDayCalculator());
		$terms = new TermijnService($settings, $logger, follower: new CaseDeadlineFollower($settings, new CaseDeadlineMirror(), $logger));

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $app === 'portaliq');

		$email = $this->realTermNoticeSender();
		$notifications = new TermijnNotificationService(
			termService: $terms,
			sender: $email,
			logger: $logger,
			requester: new RequesterNoticeSender(email: $email, settings: $settings, appManager: $apps, writer: new CaseFieldWriter()),
		);

		$deadlines = new WOODeadlineService(
			$settings,
			$this->createMock(INotificationManager::class),
			$logger,
			$this->caseDates(),
			$timer,
			new WooTermExtension(
				termService: $terms,
				extension: new DeadlineExtensionService(termService: $terms, dates: $this->caseDates(), timerService: $timer),
				logger: $logger,
				notice: new ExtensionNotice(settings: $settings, notifications: $notifications, logger: $logger),
			),
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['reason', '', $reason]]);

		return new WOOAssessmentController(
			'dossiq',
			$request,
			$this->createMock(WOODocumentAssessmentService::class),
			$deadlines,
			$this->createMock(WOODecisionService::class),
			$this->createMock(WooPublicationService::class),
			$this->createMock(WOOAnonymisationAssistService::class),
			$session,
			new CaseAccessGuard(settingsService: $settings, groupManager: $groups, logger: $logger),
			$logger,
			$this->createMock(IL10N::class),
		);
	}//end controller()

	/**
	 * REQ-WRN-005 "The requester is told about the extension": a portal message
	 * carries the reason and the new end date, and the case stores it as sent.
	 *
	 * @return void
	 */
	public function testExtendingTellsTheRequesterWithTheReason(): void {
		$this->store->seed('case', ['id' => 'case-woo', 'identifier' => 'WOO-2026-7', 'portalSubject' => 'ps-abc', 'deadline' => '2026-11-02']);

		$response = $this->controller(reason: 'Veel documenten van derden, zienswijzen nodig')->extendDeadline('case-woo');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('2026-11-16', $data['deadline']);
		self::assertSame('sent', $data['noticeStatus']);
		self::assertSame('portal-inbox', $data['noticeChannel']);

		$messages = $this->store->findObjects('dossiq', 'portaalBericht');
		self::assertCount(1, $messages);
		self::assertSame('ps-abc', $messages[0]['recipientRef']);
		self::assertStringContainsString('Veel documenten van derden, zienswijzen nodig', $messages[0]['content']);
		self::assertStringContainsString('2026-11-16', $messages[0]['content']);

		$records = $this->store->get('case', 'case-woo')['outboundCommunications'];
		self::assertSame('extension', $records[0]['moment']);
		self::assertSame('sent', $records[0]['status']);
	}//end testExtendingTellsTheRequesterWithTheReason()

	/**
	 * REQ-WRN-005 "The extension notice could not go out": the term is extended,
	 * the case stores the notice as not sent with no-channel, and the answer says so.
	 *
	 * @return void
	 */
	public function testAnUnsentExtensionNoticeIsReportedButTheExtensionStands(): void {
		$this->store->seed('case', ['id' => 'case-woo', 'identifier' => 'WOO-2026-7', 'deadline' => '2026-11-02']);

		$response = $this->controller(reason: 'Zienswijzen van derden')->extendDeadline('case-woo');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('2026-11-16', $data['deadline']);
		self::assertSame('2026-11-16', $this->store->get('deadlineInstance', 'ti-woo')['endDateCurrent'], 'the extension stands');
		self::assertSame('not-sent', $data['noticeStatus']);
		self::assertSame('no-channel', $data['noticeReasonCode']);
		self::assertNotSame('', $data['noticeReason']);

		$records = $this->store->get('case', 'case-woo')['outboundCommunications'];
		self::assertSame('not-sent', $records[0]['status']);
		self::assertSame('no-channel', $records[0]['reasonCode']);
		self::assertSame([], $this->mailed);
	}//end testAnUnsentExtensionNoticeIsReportedButTheExtensionStands()
}//end class
