<?php

/**
 * Tests for ConflictRecordController: the resolve endpoint of the conflict card.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ConflictRecordController;
use OCA\Dossiq\Service\Sync\ConflictResolutionOutcome;
use OCA\Dossiq\Service\Sync\ConflictResolutionService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The resolve endpoint: the body is checked, the session user resolves.
 *
 * @covers \OCA\Dossiq\Controller\ConflictRecordController
 * @uses   \OCA\Dossiq\Service\Sync\ConflictResolutionOutcome
 */
class ConflictRecordControllerTest extends TestCase {

	/**
	 * Build the controller.
	 *
	 * @param mixed                     $resolution The body's resolution.
	 * @param string|null               $uid        The session user, or null.
	 * @param ConflictResolutionService $service    The service double.
	 *
	 * @return ConflictRecordController The controller.
	 */
	private function controller(mixed $resolution, ?string $uid, ConflictResolutionService $service): ConflictRecordController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key): mixed => ($key === 'resolution' ? $resolution : null)
		);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ConflictRecordController('dossiq', $request, $service, $session);
	}//end controller()

	/**
	 * The session user resolves, whatever the body says, and the outcome is answered.
	 *
	 * @return void
	 */
	public function testTheSessionUserResolves(): void {
		$service = $this->createMock(ConflictResolutionService::class);
		$service->expects($this->once())->method('resolve')
			->with('c-1', 'client_wins', 'pieter')
			->willReturn(new ConflictResolutionOutcome(status: 200, body: ['id' => 'c-1', 'resolvedBy' => 'pieter']));

		$response = $this->controller('client_wins', 'pieter', $service)->resolve(id: 'c-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['id' => 'c-1', 'resolvedBy' => 'pieter'], $response->getData());
	}//end testTheSessionUserResolves()

	/**
	 * A refusal from the service keeps its status and message.
	 *
	 * @return void
	 */
	public function testARefusalKeepsItsStatus(): void {
		$service = $this->createMock(ConflictResolutionService::class);
		$service->method('resolve')->willReturn(ConflictResolutionOutcome::refused(status: 403, message: 'U mag dit object niet wijzigen.'));

		$response = $this->controller('server_wins', 'pieter', $service)->resolve(id: 'c-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['message' => 'U mag dit object niet wijzigen.'], $response->getData());
	}//end testARefusalKeepsItsStatus()

	/**
	 * Any other resolution is 400 and the service is not asked.
	 *
	 * @return void
	 */
	public function testAnUnknownResolutionIsABadRequest(): void {
		foreach (['manual_merge', '', null, ['client_wins']] as $resolution) {
			$service = $this->createMock(ConflictResolutionService::class);
			$service->expects($this->never())->method('resolve');

			$response = $this->controller($resolution, 'pieter', $service)->resolve(id: 'c-1');

			$this->assertSame(400, $response->getStatus());
		}
	}//end testAnUnknownResolutionIsABadRequest()

	/**
	 * Without a session user nothing is resolved.
	 *
	 * @return void
	 */
	public function testWithoutAUserNothingIsResolved(): void {
		$service = $this->createMock(ConflictResolutionService::class);
		$service->expects($this->never())->method('resolve');

		$response = $this->controller('client_wins', null, $service)->resolve(id: 'c-1');

		$this->assertSame(401, $response->getStatus());
	}//end testWithoutAUserNothingIsResolved()
}//end class
