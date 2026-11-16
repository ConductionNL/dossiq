<?php

/**
 * A missing or invalid assertion is a counted 401, a foreign audience a 403,
 * and a valid one hands back its claims.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Controller\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\VerifiesPortalAssertions;
use OCA\Dossiq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Controller\Support\VerifiesPortalAssertions
 */
class VerifiesPortalAssertionsTest extends TestCase {

	/**
	 * An endpoint over the given verified claims and throttler.
	 *
	 * @param array<string, mixed>|null $claims    What the verifier answers.
	 * @param IThrottler                $throttler The throttler.
	 * @param LoggerInterface           $logger    The logger.
	 *
	 * @return object
	 */
	private function endpoint(?array $claims, IThrottler $throttler, LoggerInterface $logger): object {
		$verifier = $this->createMock(PortalAssertionVerifierInterface::class);
		$verifier->method('verify')->willReturn($claims);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('token');
		$request->method('getRemoteAddress')->willReturn('10.0.0.1');

		return new class ($verifier, $request, $throttler, $logger) {
			use VerifiesPortalAssertions;

			private const AUDIENCES = ['citizen'];
			private const THROTTLE_ACTION = 'test_action';

			public function __construct(
				private readonly PortalAssertionVerifierInterface $verifier,
				private readonly IRequest $request,
				private readonly IThrottler $throttler,
				private readonly LoggerInterface $logger,
			) {
			}

			public function claims(): array|JSONResponse {
				return $this->portalClaims();
			}
		};
	}

	public function testInvalidAssertionIsCountedAnd401(): void {
		$throttler = $this->createMock(IThrottler::class);
		$throttler->expects($this->once())->method('registerAttempt')->with('test_action', '10.0.0.1');

		$answer = $this->endpoint(claims: null, throttler: $throttler, logger: $this->createMock(LoggerInterface::class))->claims();

		$this->assertInstanceOf(JSONResponse::class, $answer);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $answer->getStatus());
	}

	public function testThrottlerFailureIsLoggedNotThrown(): void {
		$throttler = $this->createMock(IThrottler::class);
		$throttler->method('registerAttempt')->willThrowException(new RuntimeException('cache down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringEndsWith(': registerAttempt failed'), ['error' => 'cache down']);

		$answer = $this->endpoint(claims: null, throttler: $throttler, logger: $logger)->claims();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $answer->getStatus());
	}

	public function testForeignAudienceIs403(): void {
		$answer = $this->endpoint(claims: ['audience' => 'business'], throttler: $this->createMock(IThrottler::class), logger: $this->createMock(LoggerInterface::class))->claims();

		$this->assertInstanceOf(JSONResponse::class, $answer);
		$this->assertSame(Http::STATUS_FORBIDDEN, $answer->getStatus());
	}

	public function testValidAssertionHandsBackItsClaims(): void {
		$claims = ['audience' => 'citizen', 'sub' => 'bsn-1'];

		$this->assertSame($claims, $this->endpoint(claims: $claims, throttler: $this->createMock(IThrottler::class), logger: $this->createMock(LoggerInterface::class))->claims());
	}
}
