<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PortalObligationController;
use OCA\Dossiq\Portal\PortalAssertionVerifier;
use OCA\Dossiq\Service\Obligations\IntakeObligations;
use OCA\Dossiq\Tests\Unit\Portal\PortalAssertionVerifierTest;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The portal's settle endpoint: verify, audience, then the obligation.
 *
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal
 */
class PortalObligationControllerTest extends TestCase {
	/** @var IntakeObligations&MockObject The service double. */
	private IntakeObligations&MockObject $obligations;

	/**
	 * A controller for one forwarded request.
	 *
	 * @param string               $assertion The X-Portal-Subject header.
	 * @param array<string, mixed> $body      The body.
	 *
	 * @return PortalObligationController
	 */
	private function controller(string $assertion, array $body): PortalObligationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => ($name === PortalAssertionVerifier::HEADER) ? $assertion : '');
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => ($body[$key] ?? $default));
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$this->obligations = $this->createMock(IntakeObligations::class);

		return new PortalObligationController(
			appName: 'dossiq',
			request: $request,
			verifier: new PortalAssertionVerifier(config: null, secretOverride: 'a-dedicated-portaliq-secret-0123'),
			obligations: $this->obligations,
			throttler: $this->createMock(IThrottler::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * The asserted subject settles; a subject in the body is ignored.
	 *
	 * @return void
	 */
	public function testTheAssertedSubjectSettles(): void {
		$controller = $this->controller(PortalAssertionVerifierTest::mint(), ['case' => 'case-1', 'kind' => 'cosign', 'answer' => 'sign', 'subjectRef' => 'subj-mallory']);
		$this->obligations->expects(self::once())->method('settle')
			->with('case-1', 'cosign', 'sign', 'subject-ref-anna', self::anything())
			->willReturn(['status' => 200, 'body' => ['state' => 'signed']]);

		$response = $controller->settle();

		self::assertSame(200, $response->getStatus());
		self::assertSame('signed', $response->getData()['state']);
	}//end testTheAssertedSubjectSettles()

	/**
	 * Without a valid assertion nothing is settled.
	 *
	 * @return void
	 */
	public function testWithoutAValidAssertionTheAnswerIs401(): void {
		$controller = $this->controller('not-a-jwt', ['case' => 'case-1']);
		$this->obligations->expects(self::never())->method('settle');

		self::assertSame(401, $controller->settle()->getStatus());
	}//end testWithoutAValidAssertionTheAnswerIs401()

	/**
	 * A supplier session cannot settle.
	 *
	 * @return void
	 */
	public function testASupplierIsRefused(): void {
		$controller = $this->controller(PortalAssertionVerifierTest::mint(['audience' => 'supplier']), ['case' => 'case-1']);
		$this->obligations->expects(self::never())->method('settle');

		self::assertSame(403, $controller->settle()->getStatus());
	}//end testASupplierIsRefused()
}//end class
