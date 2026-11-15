<?php

/**
 * Unit tests for RemedyController.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\RemedyController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Remedy\RemedyLodging;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The remedy endpoint guards the case and passes the dialog's fields on.
 */
class RemedyControllerTest extends TestCase {

	/**
	 * A controller with a logged-in user and the given access verdict.
	 *
	 * @param RemedyLodging        $lodging The lodging service double.
	 * @param bool                 $mayEdit Whether the user may change the case.
	 * @param array<string, mixed> $params  The request parameters.
	 *
	 * @return RemedyController
	 */
	private function controller(RemedyLodging $lodging, bool $mayEdit = true, array $params = []): RemedyController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturn($mayEdit);

		return new RemedyController('dossiq', $request, $lodging, $guard, $session);
	}//end controller()

	/**
	 * The remedy opens its own case and the answer names it.
	 *
	 * @return void
	 */
	public function testAnObjectionOpensItsOwnCase(): void {
		$lodging = $this->createMock(RemedyLodging::class);
		$lodging->expects($this->once())->method('lodge')
			->with('woo-1', ['receivedDate' => '2026-12-01', 'grounds' => 'Onevenredig'])
			->willReturn(['caseId' => 'bezwaar-1', 'objectionId' => 'objection-1', 'deadline' => '2027-01-26', 'isTimely' => true]);

		$response = $this->controller($lodging, true, ['receivedDate' => '2026-12-01', 'grounds' => 'Onevenredig', 'case' => 'smuggled'])->lodge('woo-1');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('bezwaar-1', $response->getData()['caseId']);
	}//end testAnObjectionOpensItsOwnCase()

	/**
	 * No decision answers 409 with the rule.
	 *
	 * @return void
	 */
	public function testNoDecisionAnswers409AndCreatesNothing(): void {
		$lodging = $this->createMock(RemedyLodging::class);
		$lodging->method('lodge')->willThrowException(new RefusedException(rule: 'remedy-no-decision', sentence: 'no decision'));

		$response = $this->controller($lodging)->lodge('woo-1');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('remedy-no-decision', $response->getData()['error']);
	}//end testNoDecisionAnswers409AndCreatesNothing()

	/**
	 * A failed objection answers its error.
	 *
	 * @return void
	 */
	public function testAFailedObjectionRecordLeavesNoCase(): void {
		$lodging = $this->createMock(RemedyLodging::class);
		$lodging->method('lodge')->willThrowException(new RuntimeException('objection schema refused the payload'));

		$response = $this->controller($lodging)->lodge('woo-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('objection schema refused the payload', $response->getData()['error']);
	}//end testAFailedObjectionRecordLeavesNoCase()

	/**
	 * A user who may not change the case is refused before anything happens.
	 *
	 * @return void
	 */
	public function testAUserWithoutMutationAccessIsRefused(): void {
		$lodging = $this->createMock(RemedyLodging::class);
		$lodging->expects($this->never())->method('lodge');

		$response = $this->controller($lodging, false)->lodge('woo-1');

		$this->assertSame(403, $response->getStatus());
	}//end testAUserWithoutMutationAccessIsRefused()
}//end class
