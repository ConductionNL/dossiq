<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/portal-permits-as-held-products/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PortalPermitController;
use OCA\Dossiq\Portal\PortalAssertionVerifier;
use OCA\Dossiq\Service\Permit\PermitChangeRefused;
use OCA\Dossiq\Service\Permit\PermitPlateChange;
use OCA\Dossiq\Tests\Unit\Portal\PortalAssertionVerifierTest;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The portal's plate change endpoint: verify, audience, then the request.
 */
class PortalPermitControllerTest extends TestCase {
	/** @var PermitPlateChange&MockObject The service double. */
	private PermitPlateChange&MockObject $change;

	/**
	 * A controller for one forwarded request.
	 *
	 * @param string $assertion The X-Portal-Subject header.
	 * @param array<string, mixed> $body The body.
	 *
	 * @return PortalPermitController The controller.
	 */
	private function controller(string $assertion, array $body): PortalPermitController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => ($name === PortalAssertionVerifier::HEADER) ? $assertion : '');
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => ($body[$key] ?? $default));
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$this->change = $this->createMock(PermitPlateChange::class);

		return new PortalPermitController(
			appName: 'dossiq',
			request: $request,
			verifier: new PortalAssertionVerifier(config: null, secretOverride: 'a-dedicated-portaliq-secret-0123'),
			change: $this->change,
			throttler: $this->createMock(IThrottler::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * @return void
	 */
	public function testTheAssertedResidentChangesTheirPlate(): void {
		$controller = $this->controller(PortalAssertionVerifierTest::mint(), ['permitId' => 'permit-1', 'nieuwKenteken' => 'HX-901-B', 'subjectRef' => 'subj-mallory']);
		$this->change->expects(self::once())->method('request')->with('subject-ref-anna', 'permit-1', 'HX-901-B')
			->willReturn(['caseId' => 'case-new', 'identifier' => '2026-0042']);

		$response = $controller->changePlate();

		self::assertSame(201, $response->getStatus());
		self::assertSame('2026-0042', $response->getData()['identifier']);
	}//end testTheAssertedResidentChangesTheirPlate()

	/**
	 * @return void
	 */
	public function testWithoutAValidAssertionTheAnswerIs401AndNothingIsAsked(): void {
		$controller = $this->controller('not-a-jwt', ['permitId' => 'permit-1']);
		$this->change->expects(self::never())->method('request');

		self::assertSame(401, $controller->changePlate()->getStatus());
	}//end testWithoutAValidAssertionTheAnswerIs401AndNothingIsAsked()

	/**
	 * @return void
	 */
	public function testASupplierIsRefused(): void {
		$controller = $this->controller(PortalAssertionVerifierTest::mint(['audience' => 'supplier']), ['permitId' => 'permit-1']);
		$this->change->expects(self::never())->method('request');

		self::assertSame(403, $controller->changePlate()->getStatus());
	}//end testASupplierIsRefused()

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function statuses(): array {
		return [
			'invalid' => [PermitChangeRefused::INVALID, 400],
			'not found' => [PermitChangeRefused::NOT_FOUND, 404],
			'not active' => [PermitChangeRefused::NOT_ACTIVE, 409],
			'unavailable' => [PermitChangeRefused::UNAVAILABLE, 503],
		];
	}//end statuses()

	/**
	 * @param string $reason The refusal.
	 * @param int $status The expected status.
	 *
	 * @return void
	 *
	 * @dataProvider statuses
	 */
	public function testARefusalAnswersItsStatus(string $reason, int $status): void {
		$controller = $this->controller(PortalAssertionVerifierTest::mint(), ['permitId' => 'permit-1', 'nieuwKenteken' => 'HX901B']);
		$this->change->method('request')->willThrowException(new PermitChangeRefused($reason, 'why'));

		$response = $controller->changePlate();

		self::assertSame($status, $response->getStatus());
		self::assertSame($reason, $response->getData()['error']);
	}//end testARefusalAnswersItsStatus()
}//end class
