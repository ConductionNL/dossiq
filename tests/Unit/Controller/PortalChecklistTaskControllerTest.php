<?php

/**
 * Tests for the portal's completion of a checklist task.
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
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-an-external-inspector-completes-a-checklist-task-from-the-portal
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PortalChecklistTaskController;
use OCA\Dossiq\Portal\PortalAssertionVerifier;
use OCA\Dossiq\Service\Inspection\InspectionRunService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for {@see PortalChecklistTaskController}.
 *
 * @covers \OCA\Dossiq\Controller\PortalChecklistTaskController
 */
final class PortalChecklistTaskControllerTest extends TestCase {

	/**
	 * The run service.
	 *
	 * @var InspectionRunService&MockObject
	 */
	private InspectionRunService $runs;

	/**
	 * The throttler.
	 *
	 * @var IThrottler&MockObject
	 */
	private IThrottler $throttler;

	/**
	 * The asserted party completes with the body's answers, never a body subject.
	 *
	 * @return void
	 */
	public function testTheAssertedPartyCompletes(): void {
		$controller = $this->controller(claims: ['sub' => 'insp-7', 'audience' => 'inspector'], body: ['taskId' => 'task-1', 'items' => [['itemId' => 'q1', 'value' => 'ja']], 'subjectRef' => 'someone-else']);
		$this->runs->expects($this->once())->method('completeForParty')
			->with('insp-7', 'task-1', ['items' => [['itemId' => 'q1', 'value' => 'ja']], 'remarks' => null])
			->willReturn(['id' => 'task-1', 'outcome' => 'conform']);

		$response = $controller->complete();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('conform', $response->getData()['outcome']);
	}//end testTheAssertedPartyCompletes()

	/**
	 * No valid assertion: 401, counted, nothing completed.
	 *
	 * @return void
	 */
	public function testARejectedAssertionIsUnauthorised(): void {
		$controller = $this->controller(claims: null, body: []);
		$this->runs->expects($this->never())->method('completeForParty');
		$this->throttler->expects($this->once())->method('registerAttempt');

		$this->assertSame(401, $controller->complete()->getStatus());
	}//end testARejectedAssertionIsUnauthorised()

	/**
	 * A resident's assertion does not complete an inspector's task.
	 *
	 * @return void
	 */
	public function testAnotherAudienceIsForbidden(): void {
		$controller = $this->controller(claims: ['sub' => 'r-1', 'audience' => 'citizen'], body: []);
		$this->runs->expects($this->never())->method('completeForParty');

		$this->assertSame(403, $controller->complete()->getStatus());
	}//end testAnotherAudienceIsForbidden()

	/**
	 * Another party's task reads as absent; a broken run is the caller's fault.
	 *
	 * @return void
	 */
	public function testRefusalsMapToTheirStatus(): void {
		$controller = $this->controller(claims: ['sub' => 'insp-8', 'audience' => 'inspector'], body: ['taskId' => 'task-1']);
		$this->runs->method('completeForParty')->willThrowException(new DoesNotExistException('No such portal task.'));
		$this->assertSame(404, $controller->complete()->getStatus());

		$controller = $this->controller(claims: ['sub' => 'insp-7', 'audience' => 'inspector'], body: ['taskId' => 'task-1']);
		$this->runs->method('completeForParty')->willThrowException(new RuntimeException('Item q1 is required.'));
		$response = $controller->complete();
		$this->assertSame(422, $response->getStatus());
		$this->assertSame('Item q1 is required.', $response->getData()['error']);

		$controller = $this->controller(claims: ['sub' => 'insp-7', 'audience' => 'inspector'], body: ['taskId' => 'task-1']);
		$this->runs->method('completeForParty')->willThrowException(new \LogicException('boom'));
		$this->assertSame(500, $controller->complete()->getStatus());
	}//end testRefusalsMapToTheirStatus()

	/**
	 * A body without a task is refused before anything runs.
	 *
	 * @return void
	 */
	public function testAMissingTaskIsABadRequest(): void {
		$controller = $this->controller(claims: ['sub' => 'insp-7', 'audience' => 'inspector'], body: []);
		$this->runs->expects($this->never())->method('completeForParty');

		$this->assertSame(400, $controller->complete()->getStatus());
	}//end testAMissingTaskIsABadRequest()

	/**
	 * Build the controller for one request.
	 *
	 * @param array<string, mixed>|null $claims The verified claims, or null.
	 * @param array<string, mixed>      $body   The forwarded body.
	 *
	 * @return PortalChecklistTaskController The controller.
	 */
	private function controller(?array $claims, array $body): PortalChecklistTaskController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('jwt');
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => ($body[$key] ?? $default));
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');

		$verifier = $this->createMock(PortalAssertionVerifier::class);
		$verifier->method('verify')->willReturn($claims);
		$this->runs = $this->createMock(InspectionRunService::class);
		$this->throttler = $this->createMock(IThrottler::class);

		return new PortalChecklistTaskController(
			appName: 'dossiq',
			request: $request,
			verifier: $verifier,
			runs: $this->runs,
			throttler: $this->throttler,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()
}//end class
