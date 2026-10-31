<?php

/**
 * A statutory term behaves as opencatalogi's did, proven on its scenarios.
 *
 * opencatalogi kept the Woo decision term on its own request object. Dossiq
 * takes the request over (woo-request-takes-over-from-opencatalogi), and a
 * case type's statutory term replaces that clock. This suite replays the
 * scenarios opencatalogi's own tests assert, one test each, against dossiq's
 * real call sites: the extend endpoint, the ask-and-receive pair that suspends
 * and resumes the term, and the quarterly report endpoint. Nothing here is
 * named after the procedure: the term, the extension, the pause and the share
 * are generic, and the Woo numbers (28 days, one extension of 14) are the term
 * definition's, handed in as configuration.
 *
 * The clock is fixed by the dates the scenario names (a case received Monday
 * 5 October 2026, so the term ends Monday 2 November) and the calendar is
 * fixed by a roll that moves nothing, so every expected date is a weekday the
 * Algemene termijnenwet leaves where it is. The pause starts on the real day it
 * is asked, so the resume is told it arrived seven days after that day; the
 * credit is the seven days either way.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Term
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-the-term-behaves-as-opencatalogis-did-proven-on-its-fixtures-req-wto-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use DateTimeImmutable;
use OCA\Dossiq\Controller\DeadlineReportingController;
use OCA\Dossiq\Controller\WOOAssessmentController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\DeadlineReportingService;
use OCA\Dossiq\Service\InformationRequestService;
use OCA\Dossiq\Service\Reporting\ReportingAudience;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\Dossiq\Service\Termijn\TermDefinitions;
use OCA\Dossiq\Service\Termijn\TermInstanceStore;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\TermKind;
use OCA\Dossiq\Service\WOOAnonymisationAssistService;
use OCA\Dossiq\Service\WOODeadlineService;
use OCA\Dossiq\Service\WOODecisionService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\WooPublicationService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * REQ-WTO-003: the term behaves as opencatalogi's did.
 *
 * @covers \OCA\Dossiq\Service\DeadlineReportingService
 * @uses \OCA\Dossiq\Service\Termijn\TermCaseTypeResolver
 * @uses \OCA\Dossiq\Service\Termijn\TermOutcome
 * @uses \OCA\Dossiq\Controller\DeadlineReportingController
 * @uses \OCA\Dossiq\Controller\WOOAssessmentController
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\DeadlineExtensionService
 * @uses \OCA\Dossiq\Service\DeadlinePauseService
 * @uses \OCA\Dossiq\Service\InformationRequestService
 * @uses \OCA\Dossiq\Service\TermKind
 * @uses \OCA\Dossiq\Service\Termijn\CaseDeadlineMirror
 * @uses \OCA\Dossiq\Service\Termijn\TermInstanceStore
 * @uses \OCA\Dossiq\Service\WOODeadlineService
 * @uses \OCA\Dossiq\Service\Term\DayCount
 */
class StatutoryTermParityTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The case every scenario runs on.
	 */
	private const CASE_ID = 'case-woo-1';

	/**
	 * The term instances, by id, as the stateful store holds them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $terms = [];

	/**
	 * Every event the store was handed, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $events = [];

	/**
	 * The term store, stateful over {@see $terms}.
	 *
	 * @var TermijnService&MockObject
	 */
	private TermijnService $termService;

	/**
	 * The engine timers, with a roll that leaves a weekday where it is.
	 *
	 * @var TermijnTimerService&MockObject
	 */
	private TermijnTimerService $timers;

	/**
	 * Seed one running statutory term received 5 October 2026, ending 2 November.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->terms = [
			't1' => [
				'id' => 't1',
				'case' => self::CASE_ID,
				'kind' => TermKind::STATUTORY,
				'startDate' => '2026-10-05T09:00:00+02:00',
				'endDateCalculated' => '2026-11-02',
				'endDateCurrent' => '2026-11-02',
				'status' => 'lopend',
				'countExtensions' => 0,
				'deadlineDefinition' => '',
			],
		];
		$this->events = [];

		$this->termService = $this->createMock(TermijnService::class);
		$this->termService->method('instancesForCase')->willReturnCallback(
			fn (string $caseId): array => array_values(
				array_filter($this->terms, static fn (array $term): bool => $term['case'] === $caseId)
			)
		);
		$this->termService->method('getTermijnInstance')->willReturnCallback(
			fn (string $id): ?array => ($this->terms[$id] ?? null)
		);
		$this->termService->method('updateTermijnInstance')->willReturnCallback(
			function (string $id, array $patch): ?array {
				if (isset($this->terms[$id]) === false) {
					return null;
				}

				$this->terms[$id] = array_merge($this->terms[$id], $patch);

				return $this->terms[$id];
			}
		);
		$this->termService->method('recordEvent')->willReturnCallback(
			function (string $termInstanceId, string $type, string $basis, string $rationale, int $daysImpact): array {
				$event = ['instance' => $termInstanceId, 'type' => $type, 'basis' => $basis, 'rationale' => $rationale, 'daysImpact' => $daysImpact];
				$this->events[] = $event;

				return $event;
			}
		);

		$this->timers = $this->createMock(TermijnTimerService::class);
		$this->timers->method('rollTermEndFor')->willReturnCallback(
			static fn (DateTimeImmutable $date): DateTimeImmutable => $date
		);
	}//end setUp()

	/**
	 * The first extension moves the term two weeks, counted from the end.
	 *
	 * Mirrors opencatalogi tests/Unit/Service/Woo/StatutoryTermTest::testTheFirstExtensionIsGrantedForTwoWeeks
	 *
	 * @return void
	 */
	public function testTheFirstExtensionIsGrantedForTwoWeeks(): void {
		$response = $this->extendController()->extendDeadline(self::CASE_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('2026-11-16', $response->getData()['deadline']);
		self::assertSame('2026-11-02', $response->getData()['previousDeadline']);
		self::assertSame(1, $this->terms['t1']['countExtensions']);
		self::assertSame('verlengd', $this->terms['t1']['status']);
		self::assertSame('Zienswijzen van derden', $this->events[0]['rationale']);
	}//end testTheFirstExtensionIsGrantedForTwoWeeks()

	/**
	 * A second extension is refused with 409 and names its rule.
	 *
	 * Mirrors opencatalogi tests/Unit/Service/Woo/StatutoryTermTest::testTheSecondExtensionIsRefused
	 *
	 * @return void
	 */
	public function testTheSecondExtensionIsRefused(): void {
		$controller = $this->extendController();
		$controller->extendDeadline(self::CASE_ID);

		$second = $controller->extendDeadline(self::CASE_ID);

		self::assertSame(Http::STATUS_CONFLICT, $second->getStatus());
		self::assertSame('extension-ceiling-reached', $second->getData()['error']);
	}//end testTheSecondExtensionIsRefused()

	/**
	 * A refused second extension leaves the term exactly as the first left it.
	 *
	 * Mirrors opencatalogi tests/Unit/Service/Woo/StatutoryTermTest::testARefusedSecondExtensionChangesNothing
	 *
	 * @return void
	 */
	public function testARefusedSecondExtensionChangesNothing(): void {
		$controller = $this->extendController();
		$controller->extendDeadline(self::CASE_ID);
		$before = $this->terms['t1'];
		$eventsBefore = count($this->events);

		$controller->extendDeadline(self::CASE_ID);

		self::assertSame($before, $this->terms['t1']);
		self::assertSame('2026-11-16', $this->terms['t1']['endDateCurrent']);
		self::assertCount($eventsBefore, $this->events, 'A refusal records no extension.');
	}//end testARefusedSecondExtensionChangesNothing()

	/**
	 * Asking for clarification suspends the term, and the engine stops its clock.
	 *
	 * Mirrors opencatalogi tests/Unit/Service/Woo/StatutoryTermTest::testAPauseSuspendsTheTermAndLeavesNoDueMoment
	 *
	 * In dossiq the due moment that cannot pass is the engine timer's: it is
	 * suspended, so no reminder and no expiry fires while the term is paused.
	 *
	 * @return void
	 */
	public function testAPauseSuspendsTheTermAndLeavesNoDueMoment(): void {
		$this->timers->expects(self::once())->method('suspendBeslistermijn');

		$result = $this->informationRequests()->ask(
			caseId: self::CASE_ID,
			items: ['Welke periode bedoelt u?'],
			recipient: 'verzoeker@example.nl',
			durationDays: 28,
		);

		self::assertTrue($result['suspended']);
		self::assertSame('paused', $this->terms['t1']['status']);
	}//end testAPauseSuspendsTheTermAndLeavesNoDueMoment()

	/**
	 * An answer seven days later puts the term back on the clock, seven days on.
	 *
	 * Mirrors opencatalogi tests/Unit/Service/Woo/StatutoryTermTest::testAResumePutsTheTermBackOnTheClock
	 *
	 * @return void
	 */
	public function testAResumePutsTheTermBackOnTheClock(): void {
		$this->timers->expects(self::once())->method('resumeBeslistermijn');
		$requests = $this->informationRequests();
		$requests->ask(caseId: self::CASE_ID, items: ['Welke periode bedoelt u?'], recipient: 'verzoeker@example.nl', durationDays: 28);
		$asked = new DateTimeImmutable((string)$this->terms['t1']['pauzeStartDatum']);

		$result = $requests->receive(caseId: self::CASE_ID, items: ['Januari tot en met maart'], when: $asked->modify('+7 days'));

		self::assertTrue($result['resumed']);
		self::assertSame('lopend', $this->terms['t1']['status']);
		self::assertSame('2026-11-09', $this->terms['t1']['endDateCurrent']);
	}//end testAResumePutsTheTermBackOnTheClock()

	/**
	 * Resuming a term that is running is refused, and nothing moves.
	 *
	 * Mirrors opencatalogi tests/Unit/Service/Woo/StatutoryTermTest::testResumingARunningTermIsRefused
	 *
	 * @return void
	 */
	public function testResumingARunningTermIsRefused(): void {
		$before = $this->terms['t1'];

		try {
			$this->informationRequests()->receive(caseId: self::CASE_ID, items: ['Iets']);
			self::fail('A running term has nothing to resume.');
		} catch (RefusedException $refusal) {
			self::assertSame('no-suspended-term-to-resume', $refusal->getRule());
		}

		self::assertSame($before, $this->terms['t1']);
		self::assertSame([], $this->events);
	}//end testResumingARunningTermIsRefused()

	/**
	 * The quarterly report counts met, missed, running and suspended per case type.
	 *
	 * Mirrors opencatalogi tests/Unit/Service/Woo/WooRequestServiceTest::testTermsReportCountsEveryOutcome
	 *
	 * @return void
	 */
	public function testTermsReportCountsEveryOutcome(): void {
		$store = new FakeTermijnStore();
		$store->seed('deadlineInstance', $this->reportRow('on-time', 'completed', '2026-11-02', '2026-10-30'));
		$store->seed('deadlineInstance', $this->reportRow('late', 'completed', '2026-11-02', '2026-11-05'));
		$store->seed('deadlineInstance', $this->reportRow('running', 'lopend', '2026-11-02'));
		$store->seed('deadlineInstance', $this->reportRow('suspended', 'paused', '2026-11-30'));

		$response = $this->reportController(store: $store)->quarterlyReport('2026-Q4');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$row = $response->getData()['perType']['woo-verzoek'];
		self::assertSame(1, $row['met']);
		self::assertSame(1, $row['missed'], 'A term decided after its end is missed, even though it is completed.');
		self::assertSame(1, $row['running']);
		self::assertSame(1, $row['suspended']);
		self::assertSame(50.0, $row['metShare']);
		self::assertSame(50.0, $row['binnenTermijnPct'], 'The published share is the same figure.');
	}//end testTermsReportCountsEveryOutcome()

	/**
	 * With nothing decided the share is absent, not full compliance and not zero.
	 *
	 * Mirrors opencatalogi tests/Unit/Service/Woo/WooRequestServiceTest::testAShareOfNothingIsNotFullCompliance
	 *
	 * @return void
	 */
	public function testAShareOfNothingIsNotFullCompliance(): void {
		$store = new FakeTermijnStore();
		$store->seed('deadlineInstance', $this->reportRow('running-1', 'lopend', '2026-11-02'));
		$store->seed('deadlineInstance', $this->reportRow('running-2', 'verlengd', '2026-11-16'));

		$row = $this->reportController(store: $store)->quarterlyReport('2026-Q4')->getData()['perType']['woo-verzoek'];

		self::assertSame(2, $row['running']);
		self::assertNull($row['metShare']);
		self::assertNull($row['binnenTermijnPct']);
	}//end testAShareOfNothingIsNotFullCompliance()

	/**
	 * The extend endpoint over the real Woo deadline path and the real extension engine.
	 *
	 * The Woo numbers come from the term definition, as configuration: 28 days,
	 * an extension capacity of 14.
	 *
	 * @return WOOAssessmentController The controller, asked with a reason.
	 */
	private function extendController(): WOOAssessmentController {
		$definitions = $this->createMock(TermDefinitions::class);
		$definitions->method('activeFor')->willReturn(
			['standardDurationDays' => 28, 'extensionCapacity' => 14, 'countExtensions' => 1]
		);
		$definitions->method('countedEndDateFor')->willReturnCallback(
			static fn (DateTimeImmutable $start, int $days): DateTimeImmutable => $start->modify('+' . $days . ' days')
		);

		$settings = $this->createMock(SettingsService::class);
		$extensions = new DeadlineExtensionService(
			termService: $this->termService,
			dates: $this->caseDates(),
			timerService: $this->timers,
			mirror: new CaseDeadlineMirror(
				settingsService: $settings,
				store: new TermInstanceStore(settingsService: $settings, logger: new NullLogger()),
				logger: new NullLogger(),
			),
		);
		$deadlines = new WOODeadlineService(
			settingsService: $settings,
			notificationManager: $this->createMock(INotificationManager::class),
			logger: new NullLogger(),
			dates: $this->caseDates(),
			definitions: $definitions,
			extensions: $extensions,
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $key === 'reason' ? 'Zienswijzen van derden' : $default
		);

		return new WOOAssessmentController(
			appName: 'dossiq',
			request: $request,
			assessmentService: $this->createMock(WOODocumentAssessmentService::class),
			deadlineService: $deadlines,
			decisionService: $this->createMock(WOODecisionService::class),
			publicationService: $this->createMock(WooPublicationService::class),
			anonymisationAssist: $this->createMock(WOOAnonymisationAssistService::class),
			userSession: $this->signedIn(),
			caseAccessGuard: $this->createMock(CaseAccessGuard::class),
			logger: $this->createMock(LoggerInterface::class),
			l10n: $this->createMock(IL10N::class),
			handlerActs: $this->createMock(\OCA\Dossiq\Service\Cases\HandlerActsAsSystem::class),
		);
	}//end extendController()

	/**
	 * Ask-and-receive over the real pause engine.
	 *
	 * @return InformationRequestService The service, with a transport that delivers.
	 */
	private function informationRequests(): InformationRequestService {
		$terms = $this->createMock(CaseTermsService::class);
		$terms->method('endAfter')->willReturn('2026-11-09');

		return new InformationRequestService(
			termService: $this->termService,
			pause: new DeadlinePauseService(termService: $this->termService, timerService: $this->timers),
			notifications: $this->createMock(TermijnNotificationService::class),
			terms: $terms,
			logger: new NullLogger(),
		);
	}//end informationRequests()

	/**
	 * The quarterly report endpoint over the real reporting service.
	 *
	 * @param FakeTermijnStore $store The term rows the report reads.
	 *
	 * @return DeadlineReportingController The controller, read by someone in the audience.
	 */
	private function reportController(FakeTermijnStore $store): DeadlineReportingController {
		// The report resolves each term's case type through its definition
		// when no case is stored (REQ-WTR-004), so every report row points at
		// the Woo definition.
		$store->seed('deadlineDefinition', ['id' => 'td-woo', 'caseType' => 'woo-verzoek']);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'termijn_instance_schema' => 'deadlineInstance',
				'termijn_definitie_schema' => 'deadlineDefinition',
				default => '',
			}
		);

		$audience = $this->createMock(ReportingAudience::class);
		$audience->method('isInAudience')->willReturn(true);

		return new DeadlineReportingController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			service: new DeadlineReportingService($settings),
			userSession: $this->signedIn(),
			logger: new NullLogger(),
			audience: $audience,
		);
	}//end reportController()

	/**
	 * One term row for the report.
	 *
	 * @param string $id Its id.
	 * @param string $status Its lifecycle status.
	 * @param string $end Its current end.
	 * @param string $decided The day it was decided, empty while open.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function reportRow(string $id, string $status, string $end, string $decided = ''): array {
		$row = [
			'id' => $id,
			'caseType' => 'woo-verzoek',
			'case' => 'case-' . $id,
			'kind' => TermKind::STATUTORY,
			'deadlineDefinition' => 'td-woo',
			'startDate' => '2026-10-05T09:00:00+02:00',
			'endDateCurrent' => $end,
			'status' => $status,
			'countExtensions' => 0,
		];
		if ($decided !== '') {
			$row['voltooiDatum'] = $decided;
		}

		return $row;
	}//end reportRow()

	/**
	 * A session with a signed-in handler.
	 *
	 * @return IUserSession The session.
	 */
	private function signedIn(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end signedIn()
}//end class
