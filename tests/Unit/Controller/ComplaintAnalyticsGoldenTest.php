<?php

/**
 * Golden record of the complaint analytics endpoint.
 *
 * Records, per world, the status and body the endpoint answers with and every
 * call it makes on the analytics service, so a refactor cannot change who sees
 * the employee alerts or which figures are computed for which window.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/complaint-management/spec.md#scenario-employee-complaint-threshold-alert
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ComplaintAnalyticsController;
use OCA\Dossiq\Service\Complaint\ComplaintAccessGuard;
use OCA\Dossiq\Service\Complaint\EmployeeAlertAudience;
use OCA\Dossiq\Service\ComplaintAnalyticsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for ComplaintAnalyticsController::analytics.
 *
 * @covers \OCA\Dossiq\Controller\ComplaintAnalyticsController
 */
class ComplaintAnalyticsGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Calls made on the analytics service in the current world.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every world the endpoint distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testAnalyticsAcrossWorlds(): void {
		$window = ['dateFrom' => '2026-02-01', 'dateTo' => '2026-03-31'];
		$worlds = [
			'anonymous' => ['uid' => '', 'audience' => true, 'params' => $window],
			'outside the audience' => ['uid' => 'clerk', 'audience' => false, 'params' => $window],
			'in the audience' => ['uid' => 'hr-1', 'audience' => true, 'params' => $window],
			'only a start date' => ['uid' => 'hr-1', 'audience' => false, 'params' => ['dateFrom' => '2026-02-01']],
			'only an end date' => ['uid' => 'hr-1', 'audience' => false, 'params' => ['dateTo' => '2026-03-31']],
		];

		$record = [];
		foreach ($worlds as $name => $world) {
			$this->calls = [];
			$response = $this->controllerFor(world: $world)->analytics();
			$record[$name] = [
				'status' => $response->getStatus(),
				'body' => $response->getData(),
				'calls' => $this->calls,
			];
		}

		$this->assertMatchesGolden(name: 'complaint-analytics', value: $record);
	}//end testAnalyticsAcrossWorlds()

	/**
	 * Without a window the endpoint asks for this year up to today.
	 *
	 * @return void
	 */
	public function testAnalyticsDefaultsToThisYearUpToToday(): void {
		$this->calls = [];
		$this->controllerFor(world: ['uid' => 'hr-1', 'audience' => false, 'params' => []])->analytics();

		$window = date('Y-01-01') . '..' . date('Y-m-d');
		self::assertSame(
			expected: [
				'audience hr-1',
				'frequency category ' . $window,
				'frequency involvedDepartment ' . $window,
				'frequency receiptChannel ' . $window,
				'trend ' . $window,
				'resolution ' . $window,
			],
			actual: $this->calls
		);
	}//end testAnalyticsDefaultsToThisYearUpToToday()

	/**
	 * Build a controller for one world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return ComplaintAnalyticsController
	 */
	private function controllerFor(array $world): ComplaintAnalyticsController {
		$params = $world['params'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$guard = $this->createMock(ComplaintAccessGuard::class);
		$guard->method('currentUid')->willReturn($world['uid']);
		$guard->method('notAuthenticated')->willReturn(
			new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED)
		);

		$audience = $this->createMock(EmployeeAlertAudience::class);
		$audience->method('mayReadEmployeeAlerts')->willReturnCallback(
			function (string $uid) use ($world): bool {
				$this->calls[] = 'audience ' . $uid;
				return $world['audience'];
			}
		);

		return new ComplaintAnalyticsController(
			appName: 'dossiq',
			request: $request,
			analyticsService: $this->recordingService(),
			accessGuard: $guard,
			alertAudience: $audience,
		);
	}//end controllerFor()

	/**
	 * An analytics service that records each call and answers with its name.
	 *
	 * @return ComplaintAnalyticsService
	 */
	private function recordingService(): ComplaintAnalyticsService {
		$service = $this->createMock(ComplaintAnalyticsService::class);
		$service->method('getFrequencyByDimension')->willReturnCallback(
			function (string $dimension, ?string $dateFrom = null, ?string $dateTo = null): array {
				$this->calls[] = 'frequency ' . $dimension . ' ' . $dateFrom . '..' . $dateTo;
				return ['dimension' => $dimension];
			}
		);
		$service->method('getMonthlyTrend')->willReturnCallback(
			function (?string $dateFrom = null, ?string $dateTo = null): array {
				$this->calls[] = 'trend ' . $dateFrom . '..' . $dateTo;
				return ['trend'];
			}
		);
		$service->method('getAverageResolutionTime')->willReturnCallback(
			function (?string $dateFrom = null, ?string $dateTo = null): array {
				$this->calls[] = 'resolution ' . $dateFrom . '..' . $dateTo;
				return ['resolution'];
			}
		);
		$service->method('checkEmployeeThresholdAlerts')->willReturnCallback(
			function (): array {
				$this->calls[] = 'alerts';
				return [['employee' => 'e-1']];
			}
		);

		return $service;
	}//end recordingService()
}//end class
