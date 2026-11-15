<?php

/**
 * Mail Parties Report Controller Test
 *
 * The parties report refuses through its caller: off switch, no case access,
 * no user. Switches run for real over a stored-config double.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\MailPartiesReportController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Zaakdossier\MailPartiesReport;
use OCA\Dossiq\Woo\WooReportSwitches;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\MailPartiesReportController
 * @uses   \OCA\Dossiq\Woo\WooReportSwitches
 */
class MailPartiesReportControllerTest extends TestCase {

	/**
	 * The stored app config.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The cases the signed-in user may read.
	 *
	 * @var list<string>
	 */
	private array $readableCases = ['case-x'];

	private MailPartiesReport&MockObject $report;

	protected function setUp(): void {
		$this->report = $this->createMock(MailPartiesReport::class);
	}//end setUp()

	/**
	 * The controller as a user, with request parameters.
	 *
	 * @param string|null           $uid    The signed-in user, or null.
	 * @param array<string, string> $params The request parameters.
	 *
	 * @return MailPartiesReportController The controller.
	 */
	private function controller(?string $uid, array $params = []): MailPartiesReportController {
		$stored = $this->stored;
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($stored[$key] ?? $default)
		);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$readable = $this->readableCases;
		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturnCallback(
			static fn (string $caseId, IUser $user): bool => in_array($caseId, $readable, true)
		);

		return new MailPartiesReportController(
			request: $request,
			switches: new WooReportSwitches(appConfig: $appConfig, groupManager: $this->createMock(IGroupManager::class)),
			accessGuard: $guard,
			partiesReport: $this->report,
			userSession: $session,
			l10n: $l10n,
		);
	}//end controller()

	/**
	 * REQ-WRR-001: while the parties switch is off the report answers 403 and reads nothing.
	 *
	 * @return void
	 */
	public function testPartiesOffAnswers403(): void {
		$this->report->expects(self::never())->method('forCase');

		$response = $this->controller(uid: 'handler')->show(id: 'case-x');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('woo-report-off', $response->getData()['error']);
	}//end testPartiesOffAnswers403()

	/**
	 * REQ-WRR-004: a caller who cannot read the case is refused; no user is unauthorised.
	 *
	 * @return void
	 */
	public function testPartiesRefusesWithoutCaseAccess(): void {
		$this->stored[WooReportSwitches::PARTIES] = 'true';
		$this->readableCases = [];
		$this->report->expects(self::never())->method('forCase');

		$response = $this->controller(uid: 'handler')->show(id: 'case-x');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('case-not-readable', $response->getData()['error']);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(uid: null)->show(id: 'case-x')->getStatus());
	}//end testPartiesRefusesWithoutCaseAccess()

	/**
	 * REQ-WRR-004: switched on and readable, the report answers its counts, and the CSV beside it.
	 *
	 * @return void
	 */
	public function testPartiesAnswersTheReportAndItsCsv(): void {
		$this->stored[WooReportSwitches::PARTIES] = 'true';
		$report = ['mails' => 1, 'unreadable' => 0, 'senders' => [['address' => 'a@b.nl', 'count' => 1]], 'recipients' => [], 'domains' => []];
		$this->report->method('forCase')->with('case-x')->willReturn($report);
		$this->report->method('toCsv')->with($report)->willReturn("kind,party,count\n");

		$json = $this->controller(uid: 'handler')->show(id: 'case-x');
		self::assertSame(Http::STATUS_OK, $json->getStatus());
		self::assertSame(['case' => 'case-x'] + $report, $json->getData());

		$csv = $this->controller(uid: 'handler', params: ['format' => 'csv'])->show(id: 'case-x');
		self::assertInstanceOf(DataDownloadResponse::class, $csv);
		self::assertSame("kind,party,count\n", $csv->render());
	}//end testPartiesAnswersTheReportAndItsCsv()
}//end class
