<?php

/**
 * ProcessMiningController CSV download tests
 *
 * Board DqProcesanalyse "Downloaden": the days per phase per case type the
 * page shows, as CSV, in the statistic (mean or median) and for the case
 * type the page is filtered on.
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
 * @spec openspec/specs/procesanalyse-csv/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ProcessMiningController;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\ProcessMining\ReportViews;
use OCA\Dossiq\Service\ProcessMiningService;
use OCA\Dossiq\Service\Reporting\ReportPeriod;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
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
class ProcessMiningCsvTest extends TestCase {

	/**
	 * The report the service answers: two case types, one that never passes a phase.
	 *
	 * @var array<string, mixed>
	 */
	private const REPORT = [
		'caseTypes' => [],
		'phaseDays' => [
			'mean' => [
				'columns' => ['Intake', 'Besluit'],
				'rows' => [
					['label' => 'Woo-verzoek', 'values' => [2, 9]],
					['label' => '=Vergunning', 'values' => [3, 'none']],
				],
			],
			'median' => [
				'columns' => ['Intake', 'Besluit'],
				'rows' => [
					['label' => 'Woo-verzoek', 'values' => [1, 7]],
				],
			],
		],
	];

	/**
	 * A controller for an allowed reader with these query parameters.
	 *
	 * @param array<string, mixed> $query The query parameters.
	 * @param ProcessMiningService|null $service The report service.
	 *
	 * @return ProcessMiningController The controller.
	 */
	private function controller(array $query, ?ProcessMiningService $service=null): ProcessMiningController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('controller-user');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(true);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default=null) => ($query[$key] ?? $default)
		);

		if ($service === null) {
			$service = $this->createMock(ProcessMiningService::class);
			$service->method('getReport')->willReturn(self::REPORT);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => ($text === 'none' ? 'geen' : $text));

		return new ProcessMiningController(
			appName: 'dossiq',
			request: $request,
			userSession: $userSession,
			groupManager: $groupManager,
			processMiningService: $service,
			logger: $this->createMock(LoggerInterface::class),
			views: new ReportViews(l10n: $l10n),
			periods: new ReportPeriod(),
			dates: $this->createMock(CaseDateNormaliser::class),
			l10n: $l10n,
		);
	}//end controller()

	/**
	 * The mean days per phase download as CSV, a phase never passed in words, a formula neutralised.
	 *
	 * @return void
	 */
	public function testDownloadsTheMeanDaysPerPhaseAsCsv(): void {
		$response = $this->controller(query: ['format' => 'csv'])->report();

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame(
			"\u{FEFF}\"Case type\",\"Intake\",\"Besluit\"\r\n\"Woo-verzoek\",\"2\",\"9\"\r\n\"'=Vergunning\",\"3\",\"geen\"\r\n",
			$response->render()
		);
		self::assertStringContainsString('procesanalyse.csv', (string)$response->getHeaders()['Content-Disposition']);
		self::assertStringContainsString('text/csv', (string)$response->getHeaders()['Content-Type']);
	}//end testDownloadsTheMeanDaysPerPhaseAsCsv()

	/**
	 * The median when the page shows it.
	 *
	 * @return void
	 */
	public function testDownloadsTheMedianWhenAsked(): void {
		$response = $this->controller(query: ['format' => 'csv', 'statistic' => 'median'])->report();

		self::assertSame("\u{FEFF}\"Case type\",\"Intake\",\"Besluit\"\r\n\"Woo-verzoek\",\"1\",\"7\"\r\n", $response->render());
	}//end testDownloadsTheMedianWhenAsked()

	/**
	 * The case type filter reaches the report, as it does for the page.
	 *
	 * @return void
	 */
	public function testPassesTheCaseTypeFilter(): void {
		$service = $this->createMock(ProcessMiningService::class);
		$service->expects($this->once())->method('getReport')->with(['caseType' => 'ct-1'])->willReturn(self::REPORT);

		$this->controller(query: ['format' => 'csv', 'caseType' => 'ct-1'], service: $service)->report();
	}//end testPassesTheCaseTypeFilter()

	/**
	 * An unknown format or statistic is refused.
	 *
	 * @return void
	 */
	public function testRefusesAnUnknownFormatOrStatistic(): void {
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller(query: ['format' => 'xlsx'])->report()->getStatus());
		self::assertSame(
			Http::STATUS_BAD_REQUEST,
			$this->controller(query: ['format' => 'csv', 'statistic' => 'mode'])->report()->getStatus()
		);
	}//end testRefusesAnUnknownFormatOrStatistic()
}//end class
