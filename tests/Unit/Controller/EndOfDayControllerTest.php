<?php

/**
 * The end-of-day endpoint answers the signed-in reader's day in their time
 * zone, and nobody else's (end-of-day-manifest).
 *
 * @category Test
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
 * @spec openspec/specs/end-of-day-manifest/spec.md#requirement-the-day-lists-are-worked-out-on-the-server
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Dossiq\Controller\EndOfDayController;
use OCA\Dossiq\Service\Queue\EndOfDayService;
use OCP\AppFramework\Http;
use OCP\IDateTimeZone;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see EndOfDayController}.
 */
class EndOfDayControllerTest extends TestCase {

	/**
	 * The controller over a day service double.
	 *
	 * @param EndOfDayService $service  The service.
	 * @param string|null     $uid      The signed-in reader, or null.
	 *
	 * @return EndOfDayController
	 */
	private function controller(EndOfDayService $service, ?string $uid): EndOfDayController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));

		return new EndOfDayController(request: $this->createMock(IRequest::class), userSession: $session, endOfDay: $service, timeZone: $zone);
	}//end controller()

	/**
	 * The reader's own day, with today in their zone.
	 *
	 * @return void
	 */
	public function testAnswersTheReadersDayInTheirZone(): void {
		$service = $this->createMock(EndOfDayService::class);
		$service->expects($this->once())->method('dayFor')
			->with(
				$this->identicalTo('pieter'),
				$this->callback(static fn (DateTimeImmutable $now): bool => $now->getTimezone()->getName() === 'Europe/Amsterdam')
			)
			->willReturn(['open' => [['id' => 'assigned-cases:case:a']], 'recorded' => [], 'tomorrow' => []]);

		$data = $this->controller(service: $service, uid: 'pieter')->show()->getData();

		self::assertSame(['assigned-cases:case:a'], array_column($data['open'], 'id'));
	}//end testAnswersTheReadersDayInTheirZone()

	/**
	 * Nobody signed in, nothing read.
	 *
	 * @return void
	 */
	public function testRefusesAnUnauthenticatedCaller(): void {
		$service = $this->createMock(EndOfDayService::class);
		$service->expects($this->never())->method('dayFor');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(service: $service, uid: null)->show()->getStatus());
	}//end testRefusesAnUnauthenticatedCaller()
}//end class
