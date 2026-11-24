<?php

/**
 * WidgetAudienceController Unit Tests.
 *
 * Pins the admin-only guard on both actions, the comma-separated body and that a save stores the request.
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
 *
 * @spec openspec/specs/dashboard-widget-visibility/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\WidgetAudienceController;
use OCA\Dossiq\Service\Dashboard\WidgetAudience;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WidgetAudienceController.
 *
 * @covers \OCA\Dossiq\Controller\WidgetAudienceController
 */
class WidgetAudienceControllerTest extends TestCase {

	/**
	 * The audience double.
	 *
	 * @var WidgetAudience&MockObject
	 */
	private WidgetAudience&MockObject $audience;

	/**
	 * Set up the double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->audience = $this->createMock(originalClassName: WidgetAudience::class);
	}//end setUp()

	/**
	 * Build the controller for the given caller.
	 *
	 * @param string|null          $uid     The logged-in UID, or null.
	 * @param bool                 $isAdmin Whether that user is an admin.
	 * @param array<string, mixed> $params  The request parameters.
	 *
	 * @return WidgetAudienceController
	 */
	private function controller(?string $uid, bool $isAdmin, array $params = []): WidgetAudienceController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn($params);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		return new WidgetAudienceController(
			request: $request,
			audience: $this->audience,
			session: $session,
			groups: $groups,
		);
	}//end controller()

	/**
	 * A non-admin, or nobody, is refused on both actions and nothing is read or stored.
	 *
	 * @return void
	 */
	public function testANonAdminIsRefused(): void {
		$this->audience->expects($this->never())->method('all');
		$this->audience->expects($this->never())->method('save');

		foreach ([['anna', false], [null, false]] as [$uid, $isAdmin]) {
			$controller = $this->controller(uid: $uid, isAdmin: $isAdmin, params: ['procest_start_case_widget' => 'kcc']);
			$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->show()->getStatus());
			$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->save()->getStatus());
		}
	}//end testANonAdminIsRefused()

	/**
	 * An admin reads every widget with its groups as comma-separated text.
	 *
	 * @return void
	 */
	public function testAnAdminReadsTheAudience(): void {
		$this->audience->method('all')->willReturn(
			[
				'procest_deadline_alerts_widget' => ['vergunningen', 'toezicht'],
				'procest_start_case_widget'      => [],
			]
		);

		$response = $this->controller(uid: 'admin', isAdmin: true)->show();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(
			expected: ['procest_deadline_alerts_widget' => 'vergunningen, toezicht', 'procest_start_case_widget' => ''],
			actual: $response->getData()
		);
	}//end testAnAdminReadsTheAudience()

	/**
	 * An admin's save hands the request to the audience and answers what was stored.
	 *
	 * @return void
	 */
	public function testAnAdminSaveStoresTheRequest(): void {
		$params = ['procest_deadline_alerts_widget' => 'vergunningen, toezicht'];
		$this->audience->expects($this->once())->method('save')->with($params)->willReturn(
			['procest_deadline_alerts_widget' => ['vergunningen', 'toezicht']]
		);

		$response = $this->controller(uid: 'admin', isAdmin: true, params: $params)->save();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['procest_deadline_alerts_widget' => 'vergunningen, toezicht'], actual: $response->getData());
	}//end testAnAdminSaveStoresTheRequest()
}//end class
