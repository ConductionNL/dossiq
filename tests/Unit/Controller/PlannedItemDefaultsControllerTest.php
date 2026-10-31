<?php

/**
 * PlannedItemDefaultsController tests.
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
 * @spec openspec/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Dossiq\Controller\PlannedItemDefaultsController;
use OCA\Dossiq\Service\Queue\PlannedItemDefaults;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The defaults a new planned item opens with.
 *
 * @spec openspec/specs/my-work/spec.md
 */
class PlannedItemDefaultsControllerTest extends TestCase {

	/**
	 * Build the controller for a caller, or for nobody.
	 *
	 * @param string|null $uid The caller, null for an unauthenticated call.
	 *
	 * @return PlannedItemDefaultsController The controller.
	 */
	private function controller(?string $uid): PlannedItemDefaultsController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new PlannedItemDefaultsController(
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $session,
			defaults: new PlannedItemDefaults(workingDays: new WorkingDayCalculator()),
		);
	}//end controller()

	/**
	 * A new planned item opens at the next working day at 10:00.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function testThePlanDefaultsStartAtTheNextWorkingDayAtTen(): void {
		$startsAt = $this->controller(uid: 'alice')->show()->getData()['startsAt'];

		self::assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2}T10:00$/', string: $startsAt);
		self::assertGreaterThan(expected: (new DateTimeImmutable())->format('Y-m-d'), actual: substr($startsAt, 0, 10));
	}//end testThePlanDefaultsStartAtTheNextWorkingDayAtTen()

	/**
	 * Nobody signed in gets a 401, not a date.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function testAnUnauthenticatedCallIsRefused(): void {
		$response = $this->controller(uid: null)->show();

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testAnUnauthenticatedCallIsRefused()
}//end class
