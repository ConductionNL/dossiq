<?php

/**
 * ComplaintAnalyticsController Wire-Contract Tests
 *
 * Contract coverage (gate-25) for `kpi()`, the management KPI summary over
 * complaint (klacht) data. The endpoint is `@NoAdminRequired` and aggregates
 * across EVERY complaint on the instance, so its only gate is the session check
 * `ComplaintAccessGuard::currentUid()`. These tests pin:
 *
 *  - an anonymous caller gets the guard's refusal verbatim and the analytics
 *    service is never entered — an aggregate over all complaints leaks case
 *    volumes, backlogs and employee alerts even without a single case body;
 *  - the caller's `dateFrom`/`dateTo` are forwarded to the summary unchanged
 *    and in that order — transposed bounds would silently produce an empty
 *    report that reads like "no complaints this period";
 *  - with no bounds supplied the window defaults to MONTH-to-date
 *    (`Y-m-01` .. today), which is what makes this endpoint different from its
 *    sibling `analytics()` on the same controller, whose default is YEAR-to-date.
 *    A copy-paste of the sibling's default is the realistic defect here and is
 *    invisible from the response shape.
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

use OCA\Dossiq\Controller\ComplaintAnalyticsController;
use OCA\Dossiq\Service\Complaint\ComplaintAccessGuard;
use OCA\Dossiq\Service\ComplaintAnalyticsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for ComplaintAnalyticsController::kpi().
 *
 * @covers \OCA\Dossiq\Controller\ComplaintAnalyticsController
 */
class ComplaintAnalyticsControllerContractTest extends TestCase {

	/**
	 * The IRequest mock handed to the controller.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The analytics service mock — the aggregate the endpoint protects.
	 *
	 * @var ComplaintAnalyticsService|MockObject
	 */
	private ComplaintAnalyticsService $analyticsService;

	/**
	 * The shared complaint access guard mock — the endpoint's only gate.
	 *
	 * @var ComplaintAccessGuard|MockObject
	 */
	private ComplaintAccessGuard $accessGuard;

	/**
	 * The controller under test.
	 *
	 * @var ComplaintAnalyticsController
	 */
	private ComplaintAnalyticsController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->analyticsService = $this->createMock(ComplaintAnalyticsService::class);
		$this->accessGuard = $this->createMock(ComplaintAccessGuard::class);

		$this->controller = new ComplaintAnalyticsController(
			appName: 'dossiq',
			request: $this->request,
			analyticsService: $this->analyticsService,
			accessGuard: $this->accessGuard,
		);
	}//end setUp()

	/**
	 * Serve the given request parameters, defaulting like the real request.
	 *
	 * @param array<string, mixed> $overrides The parameter values to serve.
	 *
	 * @return void
	 */
	private function withRequestParams(array $overrides): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($overrides): mixed {
				return ($overrides[$key] ?? $default);
			}
		);
	}//end withRequestParams()

	/**
	 * An anonymous caller gets the guard's 401 verbatim and no aggregate is
	 * computed — the KPI body itself is the disclosure.
	 *
	 * @return void
	 */
	public function testKpiReturnsTheGuardRefusalForAnAnonymousCallerAndComputesNothing(): void {
		$refusal = new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		$this->accessGuard->method('currentUid')->willReturn('');
		$this->accessGuard->method('notAuthenticated')->willReturn($refusal);
		$this->analyticsService->expects($this->never())->method('getKpiSummary');

		$response = $this->controller->kpi();

		$this->assertSame($refusal, $response, 'kpi() must return the guard refusal verbatim');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testKpiReturnsTheGuardRefusalForAnAnonymousCallerAndComputesNothing()

	/**
	 * A signed-in caller's explicit window is forwarded unchanged and in order,
	 * and the summary is returned as the response body at 200.
	 *
	 * @return void
	 */
	public function testKpiForwardsTheRequestedWindowInOrderAndReturnsTheSummary(): void {
		$this->accessGuard->method('currentUid')->willReturn('handler-1');
		$this->withRequestParams(['dateFrom' => '2026-01-01', 'dateTo' => '2026-03-31']);

		$summary = ['total' => 12, 'resolved' => 9, 'withinDeadlinePercentage' => 75.0];
		$this->analyticsService->expects($this->once())
			->method('getKpiSummary')
			->with('2026-01-01', '2026-03-31')
			->willReturn($summary);

		$response = $this->controller->kpi();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($summary, $response->getData());
	}//end testKpiForwardsTheRequestedWindowInOrderAndReturnsTheSummary()

	/**
	 * With no bounds supplied the KPI window is MONTH-to-date, not the
	 * year-to-date default its sibling `analytics()` uses on this same
	 * controller. (During January the two defaults coincide, so this test can
	 * only distinguish them in the other eleven months — it still fails on any
	 * other wrong default, e.g. an epoch-start or an off-by-one day.)
	 *
	 * @return void
	 */
	public function testKpiDefaultsToTheCurrentMonthRatherThanTheCurrentYear(): void {
		$this->accessGuard->method('currentUid')->willReturn('handler-1');
		$this->withRequestParams([]);

		$this->analyticsService->expects($this->once())
			->method('getKpiSummary')
			->with(date('Y-m-01'), date('Y-m-d'))
			->willReturn(['total' => 0]);

		$response = $this->controller->kpi();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['total' => 0], $response->getData());
	}//end testKpiDefaultsToTheCurrentMonthRatherThanTheCurrentYear()
}//end class
