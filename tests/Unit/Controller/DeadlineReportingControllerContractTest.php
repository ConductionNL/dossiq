<?php

/**
 * DeadlineReportingController Wire-Contract Tests
 *
 * Contract coverage for the three termijnbewaking reporting endpoints
 * (gate-25): the KPI dashboard, the quarterly report and the annual dwangsom
 * audit statement. All three are `@NoAdminRequired`.
 *
 * The contract pinned here:
 *
 *  - an anonymous caller is refused with 403 — NOT the 401 the rest of the app
 *    uses. That is what `ensureAuthenticated()` actually returns, and a client
 *    that retries on 401 must not be told to retry here; the test asserts the
 *    real code so a "tidy-up" to 401 is a visible, deliberate change;
 *  - `dashboard` MASKS an internal failure as a 500 with a fixed message while
 *    `quarterlyReport` / `annualStatement` REPORT the exception message with a
 *    400. That asymmetry is the actual behaviour and is asserted on both sides;
 *  - the period/year arrive either as a route argument or via the Dutch query
 *    parameters `periode` / `jaar` — those literal parameter names are the wire
 *    contract, and reading a differently-named key would make every query-string
 *    call answer 400;
 *  - the year is bounded to 2020..2100 inclusive, asserted at both boundaries
 *    (2019 refused, 2020 accepted) rather than at one arbitrary value.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DeadlineReportingController;
use OCA\Dossiq\Service\DeadlineReportingService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wire-contract tests for DeadlineReportingController.
 *
 * @covers \OCA\Dossiq\Controller\DeadlineReportingController
 */
class DeadlineReportingControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The reporting service mock.
	 *
	 * @var DeadlineReportingService|MockObject
	 */
	private DeadlineReportingService $service;

	/**
	 * The IUserSession mock.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The LoggerInterface mock.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The controller under test.
	 *
	 * @var DeadlineReportingController
	 */
	private DeadlineReportingController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(DeadlineReportingService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new DeadlineReportingController(
			appName: 'dossiq',
			request: $this->request,
			service: $this->service,
			userSession: $this->userSession,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Put a signed-in user on the session.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Answer request parameters from the supplied map.
	 *
	 * @param array<string, mixed> $params The parameter map.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);
	}//end withParams()

	/**
	 * `dashboard` refuses an anonymous caller with 403 and computes no KPI.
	 *
	 * @return void
	 */
	public function testDashboardRefusesAnUnauthenticatedCallerWith403(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->service->expects($this->never())->method('getTermijnKpi');

		$response = $this->controller->dashboard();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());
	}//end testDashboardRefusesAnUnauthenticatedCallerWith403()

	/**
	 * An authenticated `dashboard` answers 200 with the service's KPI row
	 * unchanged.
	 *
	 * @return void
	 */
	public function testDashboardReturnsTheKpiRowUnchanged(): void {
		$this->signIn();
		$this->service->expects($this->once())
			->method('getTermijnKpi')
			->willReturn(['overdue' => 4, 'dwangsomTotal' => 1442.0]);

		$response = $this->controller->dashboard();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['overdue' => 4, 'dwangsomTotal' => 1442.0], $response->getData());
	}//end testDashboardReturnsTheKpiRowUnchanged()

	/**
	 * `dashboard` masks an internal failure as a 500 with a fixed message and
	 * logs the real one — the KPI query touches the whole case store, so its
	 * error text must not reach the browser.
	 *
	 * @return void
	 */
	public function testDashboardMasksAnInternalFailureAs500AndLogsIt(): void {
		$this->signIn();
		$this->service->method('getTermijnKpi')
			->willThrowException(new \RuntimeException('SQLSTATE[42S02] table dossiq_cases missing'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->dashboard();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Internal error'], $response->getData());
		$this->assertStringNotContainsString(
			'SQLSTATE',
			json_encode($response->getData()),
			'the internal error text must not reach the client'
		);
	}//end testDashboardMasksAnInternalFailureAs500AndLogsIt()

	/**
	 * `quarterlyReport` refuses an anonymous caller with 403.
	 *
	 * @return void
	 */
	public function testQuarterlyReportRefusesAnUnauthenticatedCallerWith403(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->service->expects($this->never())->method('generateQuarterlyReport');

		$response = $this->controller->quarterlyReport();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());
	}//end testQuarterlyReportRefusesAnUnauthenticatedCallerWith403()

	/**
	 * With no period anywhere the endpoint answers 400 rather than reporting on
	 * an unnamed quarter.
	 *
	 * @return void
	 */
	public function testQuarterlyReportRejectsAMissingPeriodWith400(): void {
		$this->signIn();
		$this->withParams([]);
		$this->service->expects($this->never())->method('generateQuarterlyReport');

		$response = $this->controller->quarterlyReport();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'periode is required'], $response->getData());
	}//end testQuarterlyReportRejectsAMissingPeriodWith400()

	/**
	 * When the route argument is empty the period is taken from the `periode`
	 * query parameter — that literal Dutch key is the wire contract.
	 *
	 * @return void
	 */
	public function testQuarterlyReportFallsBackToThePeriodeQueryParameter(): void {
		$this->signIn();
		$this->withParams(['periode' => '2026-Q2']);

		$this->service->expects($this->once())
			->method('generateQuarterlyReport')
			->with('2026-Q2', null)
			->willReturn(['periode' => '2026-Q2', 'onTime' => 91]);

		$response = $this->controller->quarterlyReport();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['periode' => '2026-Q2', 'onTime' => 91], $response->getData());
	}//end testQuarterlyReportFallsBackToThePeriodeQueryParameter()

	/**
	 * An explicit route argument wins over the query parameter, and the
	 * department filter is forwarded as given.
	 *
	 * @return void
	 */
	public function testQuarterlyReportPrefersTheRouteArgumentAndForwardsTheDepartment(): void {
		$this->signIn();
		$this->withParams(['periode' => '2026-Q2']);

		$this->service->expects($this->once())
			->method('generateQuarterlyReport')
			->with('2026-Q4', 'Vergunningen')
			->willReturn(['periode' => '2026-Q4']);

		$response = $this->controller->quarterlyReport(period: '2026-Q4', department: 'Vergunningen');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('2026-Q4', $response->getData()['periode']);
	}//end testQuarterlyReportPrefersTheRouteArgumentAndForwardsTheDepartment()

	/**
	 * A rejected period is reported as 400 carrying the service's own message —
	 * unlike `dashboard`, this endpoint does not mask it.
	 *
	 * @return void
	 */
	public function testQuarterlyReportReportsAServiceRefusalAs400WithItsMessage(): void {
		$this->signIn();
		$this->withParams([]);
		$this->service->method('generateQuarterlyReport')
			->willThrowException(new \RuntimeException('Onbekende periode 2026-Q9'));

		$response = $this->controller->quarterlyReport(period: '2026-Q9');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Onbekende periode 2026-Q9'], $response->getData());
	}//end testQuarterlyReportReportsAServiceRefusalAs400WithItsMessage()

	/**
	 * `annualStatement` refuses an anonymous caller with 403.
	 *
	 * @return void
	 */
	public function testAnnualStatementRefusesAnUnauthenticatedCallerWith403(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->service->expects($this->never())->method('generateDwangsomAuditReport');

		$response = $this->controller->annualStatement();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());
	}//end testAnnualStatementRefusesAnUnauthenticatedCallerWith403()

	/**
	 * The year is bounded to 2020..2100: 2019 is refused with 400 and the audit
	 * report is not generated.
	 *
	 * @return void
	 */
	public function testAnnualStatementRefusesAYearBelowTheLowerBound(): void {
		$this->signIn();
		$this->withParams([]);
		$this->service->expects($this->never())->method('generateDwangsomAuditReport');

		$response = $this->controller->annualStatement(year: 2019);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['message' => 'jaar is required and must be between 2020 and 2100'],
			$response->getData()
		);
	}//end testAnnualStatementRefusesAYearBelowTheLowerBound()

	/**
	 * ...and 2101 above it. Asserting both ends proves the bound is a range and
	 * not an accidental single-value check.
	 *
	 * @return void
	 */
	public function testAnnualStatementRefusesAYearAboveTheUpperBound(): void {
		$this->signIn();
		$this->withParams([]);
		$this->service->expects($this->never())->method('generateDwangsomAuditReport');

		$response = $this->controller->annualStatement(year: 2101);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAnnualStatementRefusesAYearAboveTheUpperBound()

	/**
	 * 2020 — the lower boundary itself — is accepted, so the guard is inclusive.
	 *
	 * @return void
	 */
	public function testAnnualStatementAcceptsTheInclusiveLowerBoundYear(): void {
		$this->signIn();
		$this->withParams([]);
		$this->service->expects($this->once())
			->method('generateDwangsomAuditReport')
			->with(2020)
			->willReturn(['jaar' => 2020, 'dwangsommen' => []]);

		$response = $this->controller->annualStatement(year: 2020);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(2020, $response->getData()['jaar']);
	}//end testAnnualStatementAcceptsTheInclusiveLowerBoundYear()

	/**
	 * With no route argument the year is taken from the `jaar` query parameter.
	 *
	 * @return void
	 */
	public function testAnnualStatementFallsBackToTheJaarQueryParameter(): void {
		$this->signIn();
		$this->withParams(['jaar' => '2025']);
		$this->service->expects($this->once())
			->method('generateDwangsomAuditReport')
			->with(2025)
			->willReturn(['jaar' => 2025, 'total' => 3]);

		$response = $this->controller->annualStatement();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(3, $response->getData()['total']);
	}//end testAnnualStatementFallsBackToTheJaarQueryParameter()

	/**
	 * A service refusal on the annual statement is a 400 carrying its message.
	 *
	 * @return void
	 */
	public function testAnnualStatementReportsAServiceRefusalAs400WithItsMessage(): void {
		$this->signIn();
		$this->withParams([]);
		$this->service->method('generateDwangsomAuditReport')
			->willThrowException(new \RuntimeException('Geen dwangsomregister geconfigureerd'));

		$response = $this->controller->annualStatement(year: 2026);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Geen dwangsomregister geconfigureerd'], $response->getData());
	}//end testAnnualStatementReportsAServiceRefusalAs400WithItsMessage()
}//end class
