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
use OCP\IRequest;
use OCP\IUser;
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

		$this->controller = new DoorlooptijdController(
			request: $this->request,
			leadTimeService: $this->leadTimeService,
			userSession: $this->userSession,
		);
	}//end setUp()

	/**
	 * Put a signed-in user on the session.
	 *
	 * @return void
	 */
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
			->with(['period' => '12m', 'atRiskDays' => 5])
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
			->with(['period' => '6m', 'atRiskDays' => 10, 'caseType' => 'bezwaar'])
			->willReturn([]);

		$response = $this->controller->metrics();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testMetricsForwardsTheCaseTypeAndCoercesAtRiskDaysToAnInteger()
}//end class
