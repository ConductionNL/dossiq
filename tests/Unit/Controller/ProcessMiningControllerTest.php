<?php

/**
 * ProcessMiningController Unit Tests
 *
 * Verifies the controller/beheerder/admin-only auth gate (401
 * unauthenticated / 403 for a caller outside ALLOWED_GROUPS — same shape
 * as the retired Iv3ReportControllerTest did), parameter validation, and the happy path
 * delegating to {@see ProcessMiningService::getReport()}.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ProcessMiningController;
use OCA\Dossiq\Service\ProcessMining\ReportViews;
use OCA\Dossiq\Service\ProcessMiningService;
use OCA\Dossiq\Service\Reporting\ReportPeriod;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Controller\ProcessMiningController
 */
class ProcessMiningControllerTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The display shaper with a translator that echoes.
	 *
	 * @return ReportViews
	 */
	private function views(): ReportViews {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ReportViews(l10n: $l10n);
	}//end views()
	/**
	 * @return void
	 */
	public function testReportReturns401WhenUnauthenticated(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = new ProcessMiningController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			groupManager: $this->createMock(IGroupManager::class),
			processMiningService: $this->createMock(ProcessMiningService::class),
			logger: $this->createMock(LoggerInterface::class),
			views: $this->views(),
			periods: new ReportPeriod(),
			dates: $this->caseDatesFrozenAt(instant: '2026-06-15T10:00:00+00:00'),
			l10n: $this->createMock(IL10N::class),
		);

		$response = $controller->report();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testReportReturns401WhenUnauthenticated()

	/**
	 * @return void
	 */
	public function testReportReturns403WhenOutsideAllowedGroups(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('regular-user');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(false);
		$groupManager->method('isAdmin')->with('regular-user')->willReturn(false);

		$controller = new ProcessMiningController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			groupManager: $groupManager,
			processMiningService: $this->createMock(ProcessMiningService::class),
			logger: $this->createMock(LoggerInterface::class),
			views: $this->views(),
			periods: new ReportPeriod(),
			dates: $this->caseDatesFrozenAt(instant: '2026-06-15T10:00:00+00:00'),
			l10n: $this->createMock(IL10N::class),
		);

		$response = $controller->report();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testReportReturns403WhenOutsideAllowedGroups()

	/**
	 * A member of the 'controllers' group (not an NC admin) is allowed.
	 *
	 * @return void
	 */
	public function testReportAllowsControllersGroupMember(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('controller-user');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group) => ($group === 'controllers')
		);
		$groupManager->method('isAdmin')->willReturn(false);

		$service = $this->createMock(ProcessMiningService::class);
		$service->method('getReport')->willReturn(['period' => [], 'caseTypes' => []]);

		$controller = new ProcessMiningController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			groupManager: $groupManager,
			processMiningService: $service,
			logger: $this->createMock(LoggerInterface::class),
			views: $this->views(),
			periods: new ReportPeriod(),
			dates: $this->caseDatesFrozenAt(instant: '2026-06-15T10:00:00+00:00'),
			l10n: $this->createMock(IL10N::class),
		);

		$response = $controller->report();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testReportAllowsControllersGroupMember()

	/**
	 * @return void
	 */
	public function testReportRejectsInvalidFromDate(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin-user');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(false);
		$groupManager->method('isAdmin')->willReturn(true);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key) => ($key === 'from' ? 'not-a-date' : null)
		);

		$controller = new ProcessMiningController(
			appName: 'dossiq',
			request: $request,
			userSession: $userSession,
			groupManager: $groupManager,
			processMiningService: $this->createMock(ProcessMiningService::class),
			logger: $this->createMock(LoggerInterface::class),
			views: $this->views(),
			periods: new ReportPeriod(),
			dates: $this->caseDatesFrozenAt(instant: '2026-06-15T10:00:00+00:00'),
			l10n: $this->createMock(IL10N::class),
		);

		$response = $controller->report();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testReportRejectsInvalidFromDate()

	/**
	 * @return void
	 */
	public function testReportDelegatesToServiceForAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin-user');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(false);
		$groupManager->method('isAdmin')->willReturn(true);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key) {
				return match ($key) {
					'from' => '2026-01-01',
					'to' => '2026-12-31',
					'caseType' => 'ct-1',
					default => null,
				};
			}
		);

		$service = $this->createMock(ProcessMiningService::class);
		$service->expects(self::once())
			->method('getReport')
			->with(['from' => '2026-01-01', 'to' => '2026-12-31', 'caseType' => 'ct-1'])
			->willReturn(['period' => ['from' => '2026-01-01', 'to' => '2026-12-31'], 'caseTypes' => []]);

		$controller = new ProcessMiningController(
			appName: 'dossiq',
			request: $request,
			userSession: $userSession,
			groupManager: $groupManager,
			processMiningService: $service,
			logger: $this->createMock(LoggerInterface::class),
			views: $this->views(),
			periods: new ReportPeriod(),
			dates: $this->caseDatesFrozenAt(instant: '2026-06-15T10:00:00+00:00'),
			l10n: $this->createMock(IL10N::class),
		);

		$response = $controller->report();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame(['from' => '2026-01-01', 'to' => '2026-12-31'], $data['period']);
		self::assertSame([], $data['caseTypes']);
		self::assertSame([], $data['bottlenecks']);
		self::assertSame('Hours, wall clock', $data['labels']['workingHours']);
	}//end testReportDelegatesToServiceForAdmin()

	/**
	 * A service exception is caught and translated into a static 500 —
	 * `$e->getMessage()` must never reach the response body.
	 *
	 * @return void
	 */
	public function testReportTranslatesServiceExceptionTo500(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin-user');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(false);
		$groupManager->method('isAdmin')->willReturn(true);

		$service = $this->createMock(ProcessMiningService::class);
		$service->method('getReport')->willThrowException(new \RuntimeException('secret internal detail'));

		$controller = new ProcessMiningController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			groupManager: $groupManager,
			processMiningService: $service,
			logger: $this->createMock(LoggerInterface::class),
			views: $this->views(),
			periods: new ReportPeriod(),
			dates: $this->caseDatesFrozenAt(instant: '2026-06-15T10:00:00+00:00'),
			l10n: $this->createMock(IL10N::class),
		);

		$response = $controller->report();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsString('secret internal detail', json_encode($response->getData()));
	}//end testReportTranslatesServiceExceptionTo500()

	/**
	 * An admin controller for a query, with the service double given.
	 *
	 * @param array<string, mixed> $params  The query.
	 * @param ProcessMiningService $service The service double.
	 *
	 * @return ProcessMiningController
	 */
	private function adminController(array $params, ProcessMiningService $service): ProcessMiningController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin-user');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		return new ProcessMiningController(
			appName: 'dossiq',
			request: $request,
			userSession: $userSession,
			groupManager: $groupManager,
			processMiningService: $service,
			logger: $this->createMock(LoggerInterface::class),
			views: $this->views(),
			periods: new ReportPeriod(),
			dates: $this->caseDatesFrozenAt(instant: '2026-06-15T10:00:00+00:00'),
			l10n: $this->createMock(IL10N::class),
		);
	}//end adminController()

	/**
	 * A period preset becomes the `from` date; `all` reaches back to the
	 * earliest date rather than the report's twelve-month default.
	 *
	 * @return void
	 */
	public function testPeriodPresetSetsFrom(): void {
		$service = $this->createMock(ProcessMiningService::class);
		$service->expects(self::exactly(2))->method('getReport')
			->willReturnCallback(
				static function (array $params): array {
					self::assertContains($params['from'], ['2026-03-01', '1970-01-01']);
					return ['caseTypes' => []];
				}
			);

		$this->adminController(params: ['period' => '3m'], service: $service)->report();
		$this->adminController(params: ['period' => 'all'], service: $service)->report();
	}//end testPeriodPresetSetsFrom()

	/**
	 * Unknown presets and views are refused before the report is built.
	 *
	 * @return void
	 */
	public function testUnknownPeriodOrViewIsRefused(): void {
		$service = $this->createMock(ProcessMiningService::class);
		$service->expects(self::never())->method('getReport');

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->adminController(params: ['period' => 'forever'], service: $service)->report()->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->adminController(params: ['view' => 'everything'], service: $service)->report()->getStatus());
	}//end testUnknownPeriodOrViewIsRefused()

	/**
	 * A view answers its block with the period, clock, labels and truncation.
	 *
	 * @return void
	 */
	public function testViewNarrowsTheAnswer(): void {
		$service = $this->createMock(ProcessMiningService::class);
		$service->method('getReport')->willReturn(
			[
				'period' => ['from' => '2025-06-01', 'to' => '2026-06-15'],
				'clock' => 'working-calendar',
				'truncated' => false,
				'caseTypes' => [['id' => 'a', 'title' => 'A', 'caseVolume' => 2, 'transitionCount' => 0]],
				'throughputTrend' => [['week' => '2026-W23', 'count' => 3]],
			]
		);

		$data = $this->adminController(params: ['view' => 'summary'], service: $service)->report()->getData();

		self::assertSame(['period', 'clock', 'truncated', 'labels', 'summary'], array_keys($data));
		self::assertSame(2, $data['summary']['totalCases']);
		self::assertSame('Working hours', $data['labels']['workingHours']);
	}//end testViewNarrowsTheAnswer()
}//end class
