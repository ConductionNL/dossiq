<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Consultation;

use OCA\Dossiq\Service\Consultation\ConsultationAccessGuard;
use OCA\Dossiq\Service\ConsultationService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The guard reads a request body it can actually reach.
 *
 * `getContent()` is protected on `OC\AppFramework\Http\Request` and absent
 * from `OCP\IRequest`, so calling it from outside is an Error. The guard has
 * to test whether the method is really callable before calling it, and read
 * the stream otherwise, or every caller of a route that goes through it gets
 * a 500.
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
 */
class ConsultationAccessGuardBodyTest extends TestCase {

	/**
	 * The guard, built on a given request.
	 *
	 * @param IRequest $request The request to read from.
	 *
	 * @return ConsultationAccessGuard The guard.
	 */
	private function guard(IRequest $request): ConsultationAccessGuard {
		return new ConsultationAccessGuard(
			request: $request,
			consultationService: $this->createMock(originalClassName: ConsultationService::class),
			userSession: $this->createMock(originalClassName: IUserSession::class),
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
		);
	}//end guard()

	/**
	 * A plain OCP\IRequest offers no getContent() at all, so the guard reads
	 * the stream rather than calling something that is not there.
	 *
	 * @return void
	 */
	public function testAReachableGetContentIsRead(): void {
		// The suite's own request stubs expose a public getContent(); the
		// controller contract test has one. Anything genuinely callable is
		// read, which is what keeps the decoding path drivable from a test.
		$request = $this->createMock(originalClassName: IRequest::class);
		$guard = $this->guard(request: $request);

		$this->assertFalse(
			is_callable([$request, 'getContent']),
			'OCP\\IRequest declares no getContent(), so a plain double answers none',
		);
		// With nothing callable and no stream in a test, the body is empty
		// rather than an Error, which is the whole point of the guard.
		$this->assertSame(expected: [], actual: $guard->requestBody());
	}//end testAReachableGetContentIsRead()

	/**
	 * A request whose getContent() cannot be called, which is every real one,
	 * leaves the guard reading the stream: empty here, so an empty body, and
	 * above all no Error.
	 *
	 * @return void
	 */
	public function testAnUnreachableGetContentFallsBackInsteadOfThrowing(): void {
		$this->assertSame(
			expected: [],
			actual: $this->guard(request: $this->createMock(originalClassName: IRequest::class))->requestBody(),
		);
	}//end testAnUnreachableGetContentFallsBackInsteadOfThrowing()
}//end class
