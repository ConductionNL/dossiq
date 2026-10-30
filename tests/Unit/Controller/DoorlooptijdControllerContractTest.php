<?php

/**
 * DoorlooptijdController Wire-Contract Tests
 *
 * Contract coverage for `GET /api/doorlooptijd/metrics` (gate-25). The
 * controller is a parameter validator in front of DoorlooptijdService: every
 * branch it owns is either a refusal or a coercion, and each one is asserted
 * here rather than only the happy path. These tests pin:
 *
 *  - 401 without a session, before any metric is computed;
 *  - each of the three 400 branches SEPARATELY, with its own message — a
 *    `match`-free chain of three `if`s is exactly where a copy-pasted message
 *    or a mis-ordered guard hides;
 *  - the defaults the service actually receives: `period` = '12m',
 *    `atRiskDays` = int 5, and NO `caseType` key at all when the caller did
 *    not supply one. An empty-string caseType leaking into the params array
 *    would filter the dashboard down to zero cases while still rendering.
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

use OCA\Dossiq\Controller\DoorlooptijdController;
use OCA\Dossiq\Service\DoorlooptijdService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCA\Dossiq\Service\Reporting\ReportingAudience;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for DoorlooptijdController::metrics().
 *
 * @covers \OCA\Dossiq\Controller\DoorlooptijdController
 */
class DoorlooptijdControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The metrics service.
	 *
	 * @var DoorlooptijdService|MockObject
	 */
	private DoorlooptijdService $leadTimeService;

	/**
	 * The user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Who may read a figure about every case.
	 *
	 * @var ReportingAudience
	 */
	private ReportingAudience $audience;

	/**
	 * The controller under test.
	 *
	 * @var DoorlooptijdController
	 */
	private DoorlooptijdController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->leadTimeService = $this->createMock(DoorlooptijdService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->audience = $this->createMock(ReportingAudience::class);
		$this->audience->method('isInAudience')->willReturn(true);

		$this->controller = new DoorlooptijdController(
			request: $this->request,
			leadTimeService: $this->leadTimeService,
			userSession: $this->userSession,
			// A double that SAYS YES keeps this file measuring the metrics
			// contract rather than the gate; the gate has its own test.
			audience: $this->audience,
			l10n: $this->l10n(),
		);
	}//end setUp()

	/**
	 * Put a signed-in user on the session.
	 *
	 * @return void
	 */
	/**
	 * Translations that return the source string.
	 *
	 * @return IL10N The double.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return $l10n;
	}//end l10n()

	/**
	 * The CSV download of the figures per case type and month: the same filter
	 * and period as the page, column labels, the rows, and the BOM.
	 *
	 * @spec openspec/specs/doorlooptijd-csv/spec.md#requirement-the-processing-time-figures-download-as-csv
	 *
	 * @return void
	 */
	public function testMetricsDownloadsTheMonthlyFiguresAsCsv(): void {
		$this->signIn();
		$this->withQuery(['format' => 'csv', 'period' => '3m', 'caseType' => 'ct-woo']);
		$this->leadTimeService->expects($this->never())->method('getMetrics');
		$this->leadTimeService->expects($this->once())->method('getMonthlyRows')
			->with(['period' => '3m', 'caseType' => 'ct-woo'])
			->willReturn([
				['caseType' => 'Woo-verzoek', 'month' => '2026-09', 'closed' => 4, 'avgDays' => 23, 'withinNormPercent' => 75],
				['caseType' => '=HYPERLINK("x")', 'month' => '2026-10', 'closed' => 1, 'avgDays' => 9, 'withinNormPercent' => null],
			]);

		$response = $this->controller->metrics();

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame('text/csv; charset=utf-8', $response->getHeaders()['Content-Type']);
		$this->assertStringContainsString('doorlooptijd.csv', $response->getHeaders()['Content-Disposition']);
		$this->assertSame(
			"\u{FEFF}" . '"Case type","Month","Closed cases","Average processing time (days)","Within the norm (%)"' . "\r\n"
			. '"Woo-verzoek","2026-09","4","23","75"' . "\r\n"
			. '"\'=HYPERLINK(""x"")","2026-10","1","9",""' . "\r\n",
			$response->render()
		);
	}//end testMetricsDownloadsTheMonthlyFiguresAsCsv()

	/**
	 * A format the endpoint does not speak is refused, not answered as JSON.
	 *
	 * @spec openspec/specs/doorlooptijd-csv/spec.md#requirement-the-processing-time-figures-download-as-csv
	 *
	 * @return void
	 */
	public function testMetricsRejectsAnUnknownFormat(): void {
		$this->signIn();
		$this->withQuery(['format' => 'xlsx']);
		$this->leadTimeService->expects($this->never())->method('getMonthlyRows');

		$response = $this->controller->metrics();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'format must be json or csv'], $response->getData());
	}//end testMetricsRejectsAnUnknownFormat()

	/**
	 * The download is refused to a reader outside the reporting audience,
	 * before anything is read.
	 *
	 * @spec openspec/specs/doorlooptijd-csv/spec.md#requirement-the-processing-time-figures-download-as-csv
	 *
	 * @return void
	 */
	public function testMetricsRefusesTheCsvOutsideTheReportingAudience(): void {
		$audience = $this->createMock(ReportingAudience::class);
		$audience->method('isInAudience')->willReturn(false);
		$controller = new DoorlooptijdController(
			request: $this->request,
			leadTimeService: $this->leadTimeService,
			userSession: $this->userSession,
			audience: $audience,
			l10n: $this->l10n(),
		);
		$this->signIn();
		$this->withQuery(['format' => 'csv']);
		$this->leadTimeService->expects($this->never())->method('getMonthlyRows');

		$response = $controller->metrics();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testMetricsRefusesTheCsvOutsideTheReportingAudience()

	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('analist');
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Drive the three query parameters metrics() reads.
	 *
	 * @param array<string,mixed> $overrides Values keyed by parameter name.
	 *
	 * @return void
	 */
	private function withQuery(array $overrides): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($overrides): mixed {
				if (array_key_exists($key, $overrides) === true) {
					return $overrides[$key];
				}

				return $default;
			}
		);
	}//end withQuery()

	/**
	 * An unauthenticated caller is refused before any metric is computed.
	 *
	 * @return void
	 */
	public function testMetricsRefusesAnUnauthenticatedCallerBeforeComputingAnything(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->leadTimeService->expects($this->never())->method('getMetrics');

		$response = $this->controller->metrics();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'unauthenticated'], $response->getData());
	}//end testMetricsRefusesAnUnauthenticatedCallerBeforeComputingAnything()

	/**
	 * A non-string caseType is rejected with its own message.
	 *
	 * @return void
	 */
	public function testMetricsRejectsANonStringCaseTypeWithItsOwnMessage(): void {
		$this->signIn();
		$this->withQuery(['caseType' => ['array-injected-via-query-string']]);
		$this->leadTimeService->expects($this->never())->method('getMetrics');

		$response = $this->controller->metrics();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'caseType must be a string'], $response->getData());
	}//end testMetricsRejectsANonStringCaseTypeWithItsOwnMessage()

	/**
	 * A period that does not match `\d+m` is rejected with its own message.
	 *
	 * @return void
	 */
	public function testMetricsRejectsAMalformedPeriodWithItsOwnMessage(): void {
		$this->signIn();
		$this->withQuery(['period' => 'twelve-months']);
		$this->leadTimeService->expects($this->never())->method('getMetrics');

		$response = $this->controller->metrics();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'period must look like 12m'], $response->getData());
	}//end testMetricsRejectsAMalformedPeriodWithItsOwnMessage()

	/**
	 * A non-numeric atRiskDays is rejected with its own message.
	 *
	 * @return void
	 */
	public function testMetricsRejectsANonNumericAtRiskDaysWithItsOwnMessage(): void {
		$this->signIn();
		$this->withQuery(['atRiskDays' => 'soon']);
		$this->leadTimeService->expects($this->never())->method('getMetrics');

		$response = $this->controller->metrics();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'atRiskDays must be a number'], $response->getData());
	}//end testMetricsRejectsANonNumericAtRiskDaysWithItsOwnMessage()

	/**
	 * With no query at all the service receives the documented defaults and
	 * no caseType key whatsoever.
	 *
	 * @return void
	 */
	public function testMetricsAppliesTheDocumentedDefaultsAndOmitsCaseTypeEntirely(): void {
		$this->signIn();
		$this->withQuery([]);

		$payload = ['average' => 21.5, 'atRisk' => 3];

		$this->leadTimeService->expects($this->once())
			->method('getMetrics')
			->with(['period' => '12m', 'granularity' => 'month', 'atRiskDays' => 5])
			->willReturn($payload);

		$response = $this->controller->metrics();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($payload, $response->getData());
	}//end testMetricsAppliesTheDocumentedDefaultsAndOmitsCaseTypeEntirely()

	/**
	 * A supplied caseType is added to the params and atRiskDays is coerced to
	 * an int — the service declares `array $params` but reads atRiskDays as a
	 * threshold, and the query string always delivers it as a string.
	 *
	 * @return void
	 */
	public function testMetricsForwardsTheCaseTypeAndCoercesAtRiskDaysToAnInteger(): void {
		$this->signIn();
		$this->withQuery(['caseType' => 'bezwaar', 'period' => '6m', 'atRiskDays' => '10']);

		$this->leadTimeService->expects($this->once())
			->method('getMetrics')
			->with(['period' => '6m', 'granularity' => 'month', 'atRiskDays' => 10, 'caseType' => 'bezwaar'])
			->willReturn([]);

		$response = $this->controller->metrics();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testMetricsForwardsTheCaseTypeAndCoercesAtRiskDaysToAnInteger()

	/**
	 * The grouping is forwarded: week, month, quarter or year.
	 *
	 * @return void
	 */
	public function testMetricsForwardsTheGranularity(): void {
		$this->signIn();
		$this->withQuery(['granularity' => 'quarter']);

		$this->leadTimeService->expects($this->once())
			->method('getMetrics')
			->with(['period' => '12m', 'granularity' => 'quarter', 'atRiskDays' => 5])
			->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller->metrics()->getStatus());
	}//end testMetricsForwardsTheGranularity()

	/**
	 * A grouping other than week, month, quarter or year is rejected with its own message.
	 *
	 * @return void
	 */
	public function testMetricsRejectsAnUnknownGranularity(): void {
		$this->signIn();
		$this->withQuery(['granularity' => 'day']);
		$this->leadTimeService->expects($this->never())->method('getMetrics');

		$response = $this->controller->metrics();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'granularity must be week, month, quarter or year'], $response->getData());
	}//end testMetricsRejectsAnUnknownGranularity()
}//end class
