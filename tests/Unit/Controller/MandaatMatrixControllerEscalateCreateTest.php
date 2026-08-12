<?php

/**
 * MandaatMatrixController::escalateCreate() unit tests.
 *
 * `MandaatEscalatieService::createEscalatie()` was fully implemented by
 * mandaat-matrix-03 and reachable from nowhere: the controller injected the
 * service and exposed only approve/reject, so an escalation could be resolved
 * but never opened. These tests pin the endpoint that closes that gap.
 *
 * The load-bearing assertion is `testInitiatorComesFromTheSessionNotTheBody`:
 * `initiatorId` is the identity the escalation is recorded against, and a
 * caller must never be able to supply it.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\MandaatMatrixController;
use OCA\Procest\Service\MandaatCheckService;
use OCA\Procest\Service\MandaatEscalatieService;
use OCA\Procest\Service\MandaatGebruikService;
use OCA\Procest\Service\MandaatImportService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for MandaatMatrixController::escalateCreate().
 *
 * @covers \OCA\Procest\Controller\MandaatMatrixController
 */
final class MandaatMatrixControllerEscalateCreateTest extends TestCase {

	/**
	 * Inbound request.
	 *
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRequest $request;

	/**
	 * Escalation service under the endpoint.
	 *
	 * @var MandaatEscalatieService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private MandaatEscalatieService $escalatie;

	/**
	 * Current user session.
	 *
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The controller under test.
	 *
	 * @var MandaatMatrixController
	 */
	private MandaatMatrixController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->escalatie = $this->createMock(MandaatEscalatieService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new MandaatMatrixController(
			appName: 'procest',
			request: $this->request,
			userSession: $this->userSession,
			check: $this->createMock(MandaatCheckService::class),
			escalatie: $this->escalatie,
			gebruik: $this->createMock(MandaatGebruikService::class),
			import: $this->createMock(MandaatImportService::class),
			settings: $this->createMock(SettingsService::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Mark the session as authenticated for user `alice`.
	 *
	 * @return void
	 */
	private function authenticate(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}//end authenticate()

	/**
	 * Feed the request parameter bag.
	 *
	 * @param array<string, mixed> $params The body parameters.
	 *
	 * @return void
	 */
	private function withBody(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);
	}//end withBody()

	/**
	 * An anonymous caller is rejected before the service is consulted.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsRejectedAndServiceNeverRuns(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->escalatie->expects($this->never())->method('createEscalatie');

		$response = $this->controller->escalateCreate();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());
	}//end testAnonymousCallerIsRejectedAndServiceNeverRuns()

	/**
	 * A complete body creates the escalation and returns 201.
	 *
	 * @return void
	 */
	public function testCreatesEscalationAndReturnsCreated(): void {
		$this->authenticate();
		$this->withBody(
			[
				'zaakId' => 'Z/2026/1',
				'decisionType' => 'wmo-toekenning',
				'escalatieReden' => 'plafond_overschreden',
			]
		);

		$created = [
			'zaakId' => 'Z/2026/1',
			'status' => 'open',
			'targetUserId' => 'bob',
		];

		$this->escalatie->expects($this->once())
			->method('createEscalatie')
			->with('Z/2026/1', 'wmo-toekenning', 'alice', 'plafond_overschreden')
			->willReturn($created);

		$response = $this->controller->escalateCreate();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame($created, $response->getData());
	}//end testCreatesEscalationAndReturnsCreated()

	/**
	 * The initiator is taken from the session, never from the request body.
	 *
	 * A caller that sends `initiatorId: mallory` must still have the escalation
	 * recorded against its own session identity.
	 *
	 * @return void
	 */
	public function testInitiatorComesFromTheSessionNotTheBody(): void {
		$this->authenticate();
		$this->withBody(
			[
				'zaakId' => 'Z/2026/2',
				'decisionType' => 'wmo-toekenning',
				'escalatieReden' => 'niet_bevoegd',
				'initiatorId' => 'mallory',
			]
		);

		$this->escalatie->expects($this->once())
			->method('createEscalatie')
			->with('Z/2026/2', 'wmo-toekenning', 'alice', 'niet_bevoegd')
			->willReturn(['status' => 'open']);

		$response = $this->controller->escalateCreate();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testInitiatorComesFromTheSessionNotTheBody()

	/**
	 * A missing required field is a 400, and the service is not consulted.
	 *
	 * @return void
	 */
	public function testMissingRequiredFieldIsBadRequest(): void {
		$this->authenticate();
		$this->withBody(['zaakId' => 'Z/2026/3']);
		$this->escalatie->expects($this->never())->method('createEscalatie');

		$response = $this->controller->escalateCreate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['message' => 'zaakId, decisionType and escalatieReden are required'],
			$response->getData()
		);
	}//end testMissingRequiredFieldIsBadRequest()

	/**
	 * A service failure becomes a 400, not a 500.
	 *
	 * @return void
	 */
	public function testServiceFailureBecomesBadRequest(): void {
		$this->authenticate();
		$this->withBody(
			[
				'zaakId' => 'Z/2026/4',
				'decisionType' => 'wmo-toekenning',
				'escalatieReden' => 'niet_bevoegd',
			]
		);

		$this->escalatie->method('createEscalatie')
			->willThrowException(new RuntimeException('register not configured'));

		$response = $this->controller->escalateCreate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'register not configured'], $response->getData());
	}//end testServiceFailureBecomesBadRequest()
}//end class
