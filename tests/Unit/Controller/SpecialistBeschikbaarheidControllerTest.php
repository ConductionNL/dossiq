<?php

/**
 * SpecialistBeschikbaarheidController Unit Tests.
 *
 * Pins the authentication guard and the skill filter passed to the routing service.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\SpecialistBeschikbaarheidController;
use OCA\Dossiq\Service\BelplanRoutingService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SpecialistBeschikbaarheidController.
 *
 * @covers \OCA\Dossiq\Controller\SpecialistBeschikbaarheidController
 */
class SpecialistBeschikbaarheidControllerTest extends TestCase {

	/**
	 * Build the controller.
	 *
	 * @param BelplanRoutingService $routing  The routing double.
	 * @param bool                  $signedIn Whether a user is signed in.
	 *
	 * @return SpecialistBeschikbaarheidController
	 */
	private function controller(BelplanRoutingService $routing, bool $signedIn): SpecialistBeschikbaarheidController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new SpecialistBeschikbaarheidController('dossiq', $this->createMock(IRequest::class), $routing, $session);
	}//end controller()

	/**
	 * Without a user the answer is 401 and no availability is read.
	 *
	 * @return void
	 */
	public function testIndexRequiresAUser(): void {
		$routing = $this->createMock(BelplanRoutingService::class);
		$routing->expects($this->never())->method('getSpecialistBeschikbaarheid');

		$response = $this->controller($routing, signedIn: false)->index('belasting');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testIndexRequiresAUser()

	/**
	 * The skill filter is passed through, empty by default, and the records are
	 * wrapped in a specialisten member.
	 *
	 * @return void
	 */
	public function testIndexPassesTheSkillFilter(): void {
		$routing = $this->createMock(BelplanRoutingService::class);
		$routing->expects($this->exactly(2))->method('getSpecialistBeschikbaarheid')->willReturnCallback(
			static fn (string $skill): array => [['vaardigheid' => $skill]]
		);
		$controller = $this->controller($routing, signedIn: true);

		$filtered = $controller->index('belasting');
		$this->assertSame(Http::STATUS_OK, $filtered->getStatus());
		$this->assertSame(['specialisten' => [['vaardigheid' => 'belasting']]], $filtered->getData());

		$this->assertSame(['specialisten' => [['vaardigheid' => '']]], $controller->index()->getData());
	}//end testIndexPassesTheSkillFilter()
}//end class
