<?php

/**
 * PortalInspectionController tests: the assertion decides who completes, and only whitelisted fields travel.
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
 * @spec openspec/changes/inspection-checklists-onto-task/design.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PortalInspectionController;
use OCA\Dossiq\Kernel\Contracts\ExternalInspectionRunInterface;
use OCA\Dossiq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see PortalInspectionController}.
 *
 * @covers \OCA\Dossiq\Controller\PortalInspectionController
 */
final class PortalInspectionControllerTest extends TestCase {

	/**
	 * The assertion verifier.
	 *
	 * @var PortalAssertionVerifierInterface&MockObject
	 */
	private PortalAssertionVerifierInterface $verifier;

	/**
	 * The external runs.
	 *
	 * @var ExternalInspectionRunInterface&MockObject
	 */
	private ExternalInspectionRunInterface $runs;

	/**
	 * The throttler.
	 *
	 * @var IThrottler&MockObject
	 */
	private IThrottler $throttler;

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Build the collaborators; the request carries a task, answers, remarks and fields that must not travel.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->verifier = $this->createMock(PortalAssertionVerifierInterface::class);
		$this->runs = $this->createMock(ExternalInspectionRunInterface::class);
		$this->throttler = $this->createMock(IThrottler::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturn('jwt-token');
		$this->request->method('getRemoteAddress')->willReturn('192.0.2.7');
		$params = [
			'taskId' => 'task-9',
			'responses' => [['itemId' => 'q1', 'value' => 'ja']],
			'remarks' => 'goed',
			'assignee' => 'party:someone-else',
			'location' => ['lat' => 1],
		];
		$this->request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
	}//end setUp()

	/**
	 * An assertion that does not verify is throttled and refused before anything is completed.
	 *
	 * @return void
	 */
	public function testARejectedAssertionIsUnauthorized(): void {
		$this->verifier->method('verify')->with('jwt-token')->willReturn(null);
		$this->throttler->expects($this->once())->method('registerAttempt')->with('dossiq_portal_assertion', '192.0.2.7');
		$this->runs->expects($this->never())->method('complete');

		$response = $this->controller()->complete();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testARejectedAssertionIsUnauthorized()

	/**
	 * Only the inspector audience may complete an inspection task.
	 *
	 * @return void
	 */
	public function testAnotherAudienceIsForbidden(): void {
		$this->verifier->method('verify')->willReturn(['sub' => 'bsn-1', 'audience' => 'citizen']);
		$this->runs->expects($this->never())->method('complete');

		$response = $this->controller()->complete();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'forbidden'], $response->getData());
	}//end testAnotherAudienceIsForbidden()

	/**
	 * The verified claims, the task id and only the whitelisted fields reach the service; its answer is returned.
	 *
	 * @return void
	 */
	public function testTheCompletionCarriesTheClaimsAndTheWhitelistedFields(): void {
		$claims = ['sub' => 'sub-42', 'audience' => 'inspector'];
		$this->verifier->method('verify')->willReturn($claims);
		$this->runs->expects($this->once())->method('complete')
			->with($claims, 'task-9', ['responses' => [['itemId' => 'q1', 'value' => 'ja']], 'remarks' => 'goed'])
			->willReturn(['status' => 404, 'body' => ['error' => 'not_found']]);

		$response = $this->controller()->complete();

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'not_found'], $response->getData());
	}//end testTheCompletionCarriesTheClaimsAndTheWhitelistedFields()

	/**
	 * The controller under test.
	 *
	 * @return PortalInspectionController The controller.
	 */
	private function controller(): PortalInspectionController {
		return new PortalInspectionController('dossiq', $this->request, $this->verifier, $this->runs, $this->throttler, $this->createMock(LoggerInterface::class));
	}//end controller()
}//end class
