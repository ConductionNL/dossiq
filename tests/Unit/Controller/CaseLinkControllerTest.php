<?php

/**
 * The case link surface refuses a caller before any service runs.
 *
 * MUTATION-CHECKED 2026-10-10: dropping the mutation check in link() reddens
 * testLinkingSomebodyElsesCaseIsRefused; dropping guardCascadeAccess reddens
 * testACascadeOnACaseYouMayNotChangeIsRefused.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Controller\CaseLinkController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Relation\OutcomeCascade;
use OCA\Dossiq\Service\Relation\RoleLinkService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\CaseLinkController
 */
class CaseLinkControllerTest extends TestCase {

	/**
	 * The link service.
	 *
	 * @var RoleLinkService&MockObject
	 */
	private RoleLinkService $links;

	/**
	 * The cascade service.
	 *
	 * @var OutcomeCascade&MockObject
	 */
	private OutcomeCascade $cascades;

	/**
	 * The access guard.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $guard;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->links    = $this->createMock(RoleLinkService::class);
		$this->cascades = $this->createMock(OutcomeCascade::class);
		$this->guard    = $this->createMock(CaseAccessGuard::class);
	}//end setUp()

	/**
	 * The controller over a request carrying these parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return CaseLinkController The controller.
	 */
	private function controller(array $params = []): CaseLinkController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		return new CaseLinkController(
			appName: 'dossiq',
			request: $request,
			links: $this->links,
			cascades: $this->cascades,
			accessGuard: $this->guard,
			userSession: $session,
		);
	}//end controller()

	/**
	 * A caller who may not change the case is refused before the service runs.
	 *
	 * @return void
	 */
	public function testLinkingSomebodyElsesCaseIsRefused(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->links->expects($this->never())->method('link');

		$response = $this->controller(['target' => 'dec-1'])->link(caseId: 'case-1', role: 'reviews');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testLinkingSomebodyElsesCaseIsRefused()

	/**
	 * A link that names no target is refused.
	 *
	 * @return void
	 */
	public function testALinkWithoutATargetIsRefused(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->links->expects($this->never())->method('link');

		$response = $this->controller()->link(caseId: 'case-1', role: 'reviews');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testALinkWithoutATargetIsRefused()

	/**
	 * The service's refusal of an undeclared role reaches the caller as 400 with its sentence.
	 *
	 * @return void
	 */
	public function testAnUndeclaredRoleIsABadRequest(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->links->method('link')->willThrowException(new InvalidArgumentException("This case type declares no relation role 'x'."));

		$response = $this->controller(['target' => 'dec-1'])->link(caseId: 'case-1', role: 'x');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString("'x'", $response->getData()['error']);
	}//end testAnUndeclaredRoleIsABadRequest()

	/**
	 * Listing roles needs read access only.
	 *
	 * @return void
	 */
	public function testRolesNeedReadAccess(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(false);
		$this->links->expects($this->never())->method('rolesFor');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->roles(caseId: 'case-1')->getStatus());
	}//end testRolesNeedReadAccess()

	/**
	 * A cascade on a target whose case the caller may not change does not run.
	 *
	 * @return void
	 */
	public function testACascadeOnACaseYouMayNotChangeIsRefused(): void {
		$this->cascades->method('caseOf')->willReturn('case-9');
		$this->guard->method('hasCaseMutationAccess')->willReturnCallback(
			static fn (string $caseId): bool => $caseId !== 'case-9'
		);
		$this->cascades->expects($this->never())->method('run');

		$response = $this->controller()->cascade(name: 'hold', targetId: 'ch-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testACascadeOnACaseYouMayNotChangeIsRefused()

	/**
	 * A cascade on a target that names no case does not run either.
	 *
	 * @return void
	 */
	public function testACascadeOnATargetWithoutACaseIsRefused(): void {
		$this->cascades->method('caseOf')->willReturn('');
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->cascades->expects($this->never())->method('run');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->cascade(name: 'hold', targetId: 'ch-1')->getStatus());
	}//end testACascadeOnATargetWithoutACaseIsRefused()

	/**
	 * An allowed cascade passes the caller's inputs.
	 *
	 * @return void
	 */
	public function testAnAllowedCascadeRunsWithItsInputs(): void {
		$this->cascades->method('caseOf')->willReturn('case-1');
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->cascades->expects($this->once())
			->method('run')
			->with(name: 'settle', targetId: 'ch-1', inputs: ['amount' => 5])
			->willReturn(['status' => 'settled']);

		$response = $this->controller(['inputs' => ['amount' => 5]])->cascade(name: 'settle', targetId: 'ch-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAnAllowedCascadeRunsWithItsInputs()
}//end class
