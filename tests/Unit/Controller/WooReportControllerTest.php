<?php

/**
 * Unit tests for the Woo review reports controller.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\WooReportController;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\EntityAnsweringRegister;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RecordingAuditTrailMapper;
use OCA\Dossiq\Woo\WooReportReadLog;
use OCA\Dossiq\Woo\WooReportSwitches;
use OCA\Dossiq\Woo\WooThroughputReport;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The throughput route through the real switches, report and read log, over an in-memory register.
 *
 * @covers \OCA\Dossiq\Controller\WooReportController
 * @covers \OCA\Dossiq\Woo\WooReportSwitches
 * @covers \OCA\Dossiq\Woo\WooThroughputReport
 * @covers \OCA\Dossiq\Woo\WooReportReadLog
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md
 */
class WooReportControllerTest extends TestCase {

	/**
	 * The store holding the cases and assessments.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The audit trail.
	 *
	 * @var RecordingAuditTrailMapper
	 */
	private RecordingAuditTrailMapper $trail;

	/**
	 * The stored app config.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * Seed the scenario: on 2026-11-10 A assessed 3 openbaar and 1 niet_openbaar, B 2 deels_openbaar.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->trail = new RecordingAuditTrailMapper();
		$this->store->seed(schema: 'case', uuid: 'case-x', row: ['title' => 'Woo verzoek Stationsweg']);
		$verdicts = [['reviewer-a', 'openbaar'], ['reviewer-a', 'openbaar'], ['reviewer-a', 'openbaar'], ['reviewer-a', 'niet_openbaar'], ['reviewer-b', 'deels_openbaar'], ['reviewer-b', 'deels_openbaar']];
		foreach ($verdicts as $n => [$reviewer, $verdict]) {
			$this->store->seed(
				schema: 'wooDocumentAssessment',
				uuid: 'assessment-'.$n,
				row: ['caseRef' => 'case-x', 'documentRef' => 'doc-'.$n, 'classification' => $verdict, 'assessedBy' => $reviewer, 'assessedAt' => '2026-11-10T10:00:00Z']
			);
		}

		$this->stored = [
			WooReportSwitches::THROUGHPUT => 'true',
			WooReportSwitches::READERS => 'woo-leiding',
		];
	}//end setUp()

	/**
	 * The controller as a user, with request parameters.
	 *
	 * @param string|null $uid The signed-in user, or null.
	 * @param array<string, string> $params The request parameters.
	 *
	 * @return WooReportController The controller.
	 */
	private function controller(?string $uid, array $params): WooReportController {
		$stored = $this->stored;
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($stored[$key] ?? $default)
		);
		$members = ['woo-leiding' => ['lead']];
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => isset($members[$gid]));
		$groups->method('isInGroup')->willReturnCallback(static fn (string $u, string $g): bool => in_array($u, ($members[$g] ?? []), true));
		// An administrator: the group check must not care.
		$groups->method('isAdmin')->willReturn(true);

		$store = $this->store;
		$objects = new class($store) {
			/**
			 * Constructor.
			 *
			 * @param InMemoryRegister $store The store.
			 */
			public function __construct(private InMemoryRegister $store) {
			}

			/**
			 * Search by slug.
			 *
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				return $this->store->searchObjectsBySlug(register: $register, schema: $schema, filters: $filters);
			}

			/**
			 * Find as an entity.
			 *
			 * @param string $id The uuid.
			 * @param mixed ...$rest Register and schema.
			 *
			 * @return object The entity.
			 */
			public function find(string $id, mixed ...$rest): object {
				return (new EntityAnsweringRegister(register: $this->store))->find($id, ...$rest);
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getOpenRegisterClass')->willReturn($this->trail);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'case_schema' => 'case', 'woo_assessment_schema' => 'wooDocumentAssessment'][$key] ?? $default)
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('Europe/Amsterdam');
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(static fn (string $u): string => strtoupper($u));

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new WooReportController(
			request: $request,
			switches: new WooReportSwitches(appConfig: $appConfig, groupManager: $groups),
			throughputReport: new WooThroughputReport(settingsService: $settings, config: $config, userManager: $users),
			readLog: new WooReportReadLog(settingsService: $settings, logger: $this->createMock(LoggerInterface::class)),
			userSession: $session,
			l10n: $l10n,
		);
	}//end controller()

	/**
	 * Off on a fresh install: 403 with the not-switched-on sentence, and nothing is read or recorded.
	 *
	 * @return void
	 */
	public function testOffAnswers403(): void {
		$this->stored = [];

		$response = $this->controller(uid: 'lead', params: ['case' => 'case-x'])->throughput();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(403, $response->getStatus());
		$this->assertSame('woo-report-off', $response->getData()['error']);
		$this->assertSame('Your organisation has not switched this report on.', $response->getData()['message']);
		$this->assertSame([], $this->trail->rows);
	}//end testOffAnswers403()

	/**
	 * A reviewer outside the reader group cannot read their colleagues' numbers.
	 *
	 * @return void
	 */
	public function testAReviewerOutsideTheGroupIsRefused(): void {
		$response = $this->controller(uid: 'reviewer-a', params: ['case' => 'case-x'])->throughput();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('woo-throughput-not-a-reader', $response->getData()['error']);
		$this->assertSame([], $this->trail->rows);
	}//end testAReviewerOutsideTheGroupIsRefused()

	/**
	 * An administrator outside the group is refused like anyone else.
	 *
	 * @return void
	 */
	public function testAnAdministratorOutsideTheGroupIsRefused(): void {
		$response = $this->controller(uid: 'admin', params: ['case' => 'case-x'])->throughput();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('woo-throughput-not-a-reader', $response->getData()['error']);
	}//end testAnAdministratorOutsideTheGroupIsRefused()

	/**
	 * Signed out answers 401.
	 *
	 * @return void
	 */
	public function testSignedOutAnswers401(): void {
		$this->assertSame(401, $this->controller(uid: null, params: [])->throughput()->getStatus());
	}//end testSignedOutAnswers401()

	/**
	 * A member reads the scenario rows, and the read is on the case's audit trail with reader, time and scope.
	 *
	 * @return void
	 */
	public function testAReadIsAudited(): void {
		$response = $this->controller(uid: 'lead', params: ['case' => 'case-x'])->throughput();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(['case' => 'case-x'], $data['scope']);
		$this->assertSame(
			[
				['reviewer' => 'reviewer-a', 'displayName' => 'REVIEWER-A', 'day' => '2026-11-10', 'openbaar' => 3, 'deels_openbaar' => 0, 'niet_openbaar' => 1, 'total' => 4],
				['reviewer' => 'reviewer-b', 'displayName' => 'REVIEWER-B', 'day' => '2026-11-10', 'openbaar' => 0, 'deels_openbaar' => 2, 'niet_openbaar' => 0, 'total' => 2],
			],
			$data['rows']
		);
		$this->assertCount(1, $this->trail->rows);
		$this->assertSame('case-x', $this->trail->rows[0]['object']);
		$this->assertSame(WooReportReadLog::ACTION_THROUGHPUT, $this->trail->rows[0]['action']);
		$this->assertSame('lead', $this->trail->rows[0]['context']['reader']);
		$this->assertSame(['case' => 'case-x'], $this->trail->rows[0]['context']['scope']);
	}//end testAReadIsAudited()

	/**
	 * A read the audit trail refuses is not answered.
	 *
	 * @return void
	 */
	public function testAnUnrecordedReadIsNotAnswered(): void {
		$this->trail->failsAll = true;

		$response = $this->controller(uid: 'lead', params: ['case' => 'case-x'])->throughput();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(503, $response->getStatus());
		$this->assertArrayNotHasKey('rows', $response->getData());
	}//end testAnUnrecordedReadIsNotAnswered()

	/**
	 * A period read answers per period, and without two dates it is refused.
	 *
	 * @return void
	 */
	public function testAPeriodReadAndAMissingPeriod(): void {
		$ok = $this->controller(uid: 'lead', params: ['from' => '2026-11-01', 'to' => '2026-11-30'])->throughput();
		$this->assertSame(200, $ok->getStatus());
		$this->assertCount(2, $ok->getData()['rows']);
		$this->assertSame(['from' => '2026-11-01', 'to' => '2026-11-30'], $this->trail->rows[0]['context']['scope']);

		$refused = $this->controller(uid: 'lead', params: [])->throughput();
		$this->assertSame(422, $refused->getStatus());
		$this->assertSame('woo-throughput-period', $refused->getData()['error']);
	}//end testAPeriodReadAndAMissingPeriod()

	/**
	 * The CSV holds the same rows as the JSON answer, and is recorded too.
	 *
	 * @return void
	 */
	public function testTheCsvHasTheSameRows(): void {
		$json = $this->controller(uid: 'lead', params: ['case' => 'case-x'])->throughput()->getData()['rows'];
		$csv = $this->controller(uid: 'lead', params: ['case' => 'case-x', 'format' => 'csv'])->throughput();

		$this->assertInstanceOf(DataDownloadResponse::class, $csv);
		$this->assertSame(WooThroughputReport::toCsv(rows: $json), $csv->render());
		$lines = array_values(array_filter(explode("\r\n", $csv->render())));
		$this->assertCount(1 + count($json), $lines);
		$this->assertCount(2, $this->trail->rows);
	}//end testTheCsvHasTheSameRows()
}//end class
