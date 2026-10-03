<?php

/**
 * MunicipalRoleSetController wire-contract tests.
 *
 * The shipped role set, offered and adopted from the roles screen. Three
 * answers are pinned because none of them would show up as an error: an
 * unreachable register reading as "dossiq ships no roles", a second adoption
 * being recorded rather than refused, and an undo that a granted role should
 * block coming back as a plain failure with no name on it.
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
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\MunicipalRoleSetController;
use OCA\Dossiq\Service\Starter\MunicipalRoleSetService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Wire-contract tests for MunicipalRoleSetController.
 *
 * @covers \OCA\Dossiq\Controller\MunicipalRoleSetController
 */
class MunicipalRoleSetControllerTest extends TestCase {

	/**
	 * The shipped role set.
	 *
	 * @var MunicipalRoleSetService|MockObject
	 */
	private MunicipalRoleSetService $roleSet;

	/**
	 * The controller under test.
	 *
	 * @var MunicipalRoleSetController
	 */
	private MunicipalRoleSetController $controller;

	/**
	 * Build the controller with a mocked role set.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->roleSet = $this->getMockBuilder(className: MunicipalRoleSetService::class)
			->disableOriginalConstructor()
			->onlyMethods(['offer', 'adopt', 'undoAdoption'])
			->getMock();

		$this->controller = new MunicipalRoleSetController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
			roleSet: $this->roleSet,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * The offer comes back whole, and an unreachable register is a 503.
	 *
	 * ADR-102: absence fails closed with a status. An empty set here tells an
	 * administrator that dossiq ships no roles at all.
	 *
	 * @return void
	 */
	public function testTheRoleSetOfferComesBackOrAnswersUnavailable(): void {
		$this->roleSet->method('offer')->willReturnOnConsecutiveCalls(
			['set' => 'gemeentelijke-rollen', 'adopted' => false, 'roles' => [], 'rolesInUse' => []],
			null,
		);

		self::assertSame(
			expected: 'gemeentelijke-rollen',
			actual: $this->controller->roles()->getData()['set']
		);
		self::assertSame(
			expected: Http::STATUS_SERVICE_UNAVAILABLE,
			actual: $this->controller->roles()->getStatus()
		);
	}//end testTheRoleSetOfferComesBackOrAnswersUnavailable()

	/**
	 * Adopting an already adopted set is a conflict, not a second record.
	 *
	 * @return void
	 */
	public function testAdoptingAnAlreadyAdoptedRoleSetIsAConflict(): void {
		$this->roleSet->method('adopt')->willReturn(
			['ok' => false, 'reason' => 'already_adopted', 'adopted' => 0]
		);

		self::assertSame(
			expected: Http::STATUS_CONFLICT,
			actual: $this->controller->adoptRoles()->getStatus()
		);
	}//end testAdoptingAnAlreadyAdoptedRoleSetIsAConflict()

	/**
	 * A first adoption answers 200 and says how many roles woke up.
	 *
	 * The control for the test above: without it a controller that answered
	 * 409 for everything would pass.
	 *
	 * @return void
	 */
	public function testAFirstAdoptionAnswersOkAndCountsTheRoles(): void {
		$this->roleSet->method('adopt')->willReturn(['ok' => true, 'reason' => '', 'adopted' => 6]);

		$response = $this->controller->adoptRoles();

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: 6, actual: $response->getData()['adopted']);
	}//end testAFirstAdoptionAnswersOkAndCountsTheRoles()

	/**
	 * Undoing an adoption a granted role blocks is a conflict that names the role.
	 *
	 * 🔑 THE NAME IS THE POINT. An undo refused with no name leaves the
	 * administrator to find which of six roles somebody is in, by hand.
	 *
	 * @return void
	 */
	public function testUndoingAnAdoptionInUseNamesTheRole(): void {
		$this->roleSet->method('undoAdoption')->willReturn(
			['ok' => false, 'reason' => 'role_in_use', 'roleInUse' => 'Behandelaar']
		);

		$response = $this->controller->undoRoles();

		self::assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		self::assertSame(expected: 'Behandelaar', actual: $response->getData()['roleInUse']);
	}//end testUndoingAnAdoptionInUseNamesTheRole()

	/**
	 * An undo nothing blocks answers 200.
	 *
	 * @return void
	 */
	public function testAnUndoNothingBlocksAnswersOk(): void {
		$this->roleSet->method('undoAdoption')->willReturn(
			['ok' => true, 'reason' => '', 'roleInUse' => '']
		);

		self::assertSame(
			expected: Http::STATUS_OK,
			actual: $this->controller->undoRoles()->getStatus()
		);
	}//end testAnUndoNothingBlocksAnswersOk()

	/**
	 * A service that throws is a 500 that says nothing about the stack.
	 *
	 * @return void
	 */
	public function testAServiceThatThrowsAnswersFiveHundred(): void {
		$this->roleSet->method('offer')->willThrowException(new \RuntimeException('no register'));

		$response = $this->controller->roles();

		self::assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		self::assertArrayNotHasKey(key: 'exception', array: $response->getData());
	}//end testAServiceThatThrowsAnswersFiveHundred()
}//end class
