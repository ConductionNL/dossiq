<?php

/**
 * The person endpoints answer a failed register read with 503.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseNextStatusController;
use OCA\Dossiq\Controller\PersonLookupController;
use OCA\Dossiq\Controller\PersonReachabilityController;
use OCA\Dossiq\Service\Cases\CaseNextStatus;
use OCA\Dossiq\Service\People\PersonNumberLookup;
use OCA\Dossiq\Service\People\PersonReachability;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A register that cannot be read is answered as such, not as "nobody" or "not found".
 *
 * @covers \OCA\Dossiq\Controller\PersonLookupController
 * @covers \OCA\Dossiq\Controller\PersonReachabilityController
 * @covers \OCA\Dossiq\Controller\CaseNextStatusController
 *
 * @spec openspec/changes/bsn-exact-lookup/specs/initiator-selection/spec.md#requirement-a-person-is-found-by-bsn-through-a-guarded-recorded-lookup
 */
final class PersonReadFailureTest extends TestCase {

	/**
	 * The BSN lookup answers 503 when the register read fails.
	 *
	 * @return void
	 */
	public function testTheLookupAnswersAFailedReadWith503(): void {
		$lookup = $this->createMock(PersonNumberLookup::class);
		$lookup->method('find')->willThrowException(new RuntimeException('register unreachable'));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		$response = (new PersonLookupController(appName: 'dossiq', request: $this->createMock(IRequest::class), lookup: $lookup, userSession: $session))->lookup();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
	}//end testTheLookupAnswersAFailedReadWith503()

	/**
	 * The reachability answers 503 when the register read fails.
	 *
	 * @return void
	 */
	public function testTheReachabilityAnswersAFailedReadWith503(): void {
		$reachability = $this->createMock(PersonReachability::class);
		$reachability->method('forPerson')->willThrowException(new RuntimeException('register unreachable'));

		$response = (new PersonReachabilityController(appName: 'dossiq', request: $this->createMock(IRequest::class), reachability: $reachability))->show(id: 'p-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
	}//end testTheReachabilityAnswersAFailedReadWith503()

	/**
	 * The next status answers 503 when the register read fails.
	 *
	 * @return void
	 */
	public function testTheNextStatusAnswersAFailedReadWith503(): void {
		$next = $this->createMock(CaseNextStatus::class);
		$next->method('forCase')->willThrowException(new RuntimeException('register unreachable'));

		$response = (new CaseNextStatusController(appName: 'dossiq', request: $this->createMock(IRequest::class), nextStatus: $next))->show(caseId: 'case-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
	}//end testTheNextStatusAnswersAFailedReadWith503()
}//end class
