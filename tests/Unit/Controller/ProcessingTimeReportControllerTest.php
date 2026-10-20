<?php

/**
 * Locks who may read the processing-time report and which queries it refuses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ProcessingTimeReportController;
use OCA\Dossiq\Service\Reporting\ProcessingTimeReportService;
use OCA\Dossiq\Service\Reporting\ReportingAudience;
use OCA\Dossiq\Service\Reporting\ReportPeriod;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The read refuses before it builds anything.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
class ProcessingTimeReportControllerTest extends TestCase {

	/**
	 * The report service double.
	 *
	 * @var ProcessingTimeReportService&MockObject
	 */
	private ProcessingTimeReportService $reports;

	/**
	 * A fresh report service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->reports = $this->createMock(ProcessingTimeReportService::class);
	}//end setUp()

	/**
	 * The controller for a request, a reader and an audience answer.
	 *
	 * @param array<string, mixed> $params     The query.
	 * @param bool                 $signedIn   Whether a user is signed in.
	 * @param bool                 $inAudience Whether the reader is in the reporting audience.
	 *
	 * @return ProcessingTimeReportController
	 */
	private function controller(array $params = [], bool $signedIn = true, bool $inAudience = true): ProcessingTimeReportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		$audience = $this->createMock(ReportingAudience::class);
		$audience->method('isInAudience')->willReturn($inAudience);

		return new ProcessingTimeReportController(
			request: $request,
			reports: $this->reports,
			periods: new ReportPeriod(),
			userSession: $session,
			audience: $audience,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * No session, no report.
	 *
	 * @return void
	 */
	public function testUnauthenticatedIsRefused(): void {
		$this->reports->expects(self::never())->method('getReport');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(signedIn: false)->show()->getStatus());
	}//end testUnauthenticatedIsRefused()

	/**
	 * A reader outside the reporting audience gets the refusal sentence.
	 *
	 * @return void
	 */
	public function testOutsideTheAudienceIsRefusedWithTheSentence(): void {
		$this->reports->expects(self::never())->method('getReport');

		$response = $this->controller(inAudience: false)->show();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => ReportingAudience::REFUSAL], $response->getData());
	}//end testOutsideTheAudienceIsRefusedWithTheSentence()

	/**
	 * Malformed queries are refused before the report is built.
	 *
	 * @return void
	 */
	public function testMalformedQueriesAreRefused(): void {
		$this->reports->expects(self::never())->method('getReport');

		$queries = [
			['period' => 'forever'],
			['caseType' => ['a']],
			['view' => 'everything'],
			['threshold' => 'half'],
			['threshold' => '1.5'],
		];
		foreach ($queries as $query) {
			self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller(params: $query)->show()->getStatus(), json_encode($query));
		}
	}//end testMalformedQueriesAreRefused()

	/**
	 * A well-formed query reaches the service with its values; empty strings
	 * read as absent.
	 *
	 * @return void
	 */
	public function testWellFormedQueryIsAnswered(): void {
		$this->reports->expects(self::once())->method('getReport')
			->with('3m', 'permit', null, 1.0)
			->willReturn(['atRisk' => []]);

		$response = $this->controller(params: ['period' => '3m', 'caseType' => 'permit', 'view' => '', 'threshold' => '1'])->show();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['atRisk' => []], $response->getData());
	}//end testWellFormedQueryIsAnswered()

	/**
	 * The defaults are twelve months and the standard threshold.
	 *
	 * @return void
	 */
	public function testDefaults(): void {
		$this->reports->expects(self::once())->method('getReport')
			->with('12m', null, null, ProcessingTimeReportService::DEFAULT_THRESHOLD)
			->willReturn([]);

		$this->controller()->show();
	}//end testDefaults()

	/**
	 * A failing build answers 500 without the exception text.
	 *
	 * @return void
	 */
	public function testFailureIsA500(): void {
		$this->reports->method('getReport')->willThrowException(new RuntimeException('database exploded'));

		$response = $this->controller()->show();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['message' => 'Report generation failed'], $response->getData());
	}//end testFailureIsA500()
}//end class
