<?php

/**
 * WidgetVisibilityController tests.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\WidgetVisibilityController;
use OCA\Dossiq\Service\Dashboard\DashboardWidgetScope;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\WidgetVisibilityController
 */
class WidgetVisibilityControllerTest extends TestCase {

	public function testAnonymousCallerIsRefusedWithoutAskingTheScope(): void {
		$scope = $this->createMock(DashboardWidgetScope::class);
		$scope->expects($this->never())->method('visibilityFor');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$response = (new WidgetVisibilityController('dossiq', $this->createMock(IRequest::class), $scope, $session))->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}

	public function testReturnsTheVisibilityOfTheSignedInUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$scope = $this->createMock(DashboardWidgetScope::class);
		$scope->expects($this->once())->method('visibilityFor')->with('alice')
			->willReturn(['dossiq_my_cases' => true, 'dossiq_team' => false]);

		$response = (new WidgetVisibilityController('dossiq', $this->createMock(IRequest::class), $scope, $session))->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['visible' => ['dossiq_my_cases' => true, 'dossiq_team' => false]], $response->getData());
	}
}
