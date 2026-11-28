<?php

/**
 * FederatedTokenCalls Unit Tests
 *
 * A transfer token resolves through the transfer service; the transfer is
 * accepted or rejected as the remote party, and any other action is null; a
 * rejected token is counted under the action the endpoints declare, and a
 * throttler that fails is logged, never thrown.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\FederatedTokenCalls;
use OCA\Dossiq\Kernel\Contracts\CaseTransferServiceInterface;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for FederatedTokenCalls.
 *
 * @covers \OCA\Dossiq\Controller\Support\FederatedTokenCalls
 */
final class FederatedTokenCallsTest extends TestCase {

	/**
	 * The token resolves through the transfer service; accept and reject go out as the remote party.
	 *
	 * @return void
	 */
	public function testTheTransferIsAnsweredAsTheRemoteParty(): void {
		$transfers = $this->createMock(originalClassName: CaseTransferServiceInterface::class);
		$transfers->method('resolveFederatedTransferShare')->with('tok', 't-1')->willReturn(['sharedWith' => 'b@remote']);
		$transfers->expects($this->once())->method('acceptTransfer')->with('t-1', 'b@remote')->willReturn(['status' => 'accepted']);
		$transfers->expects($this->once())->method('rejectTransfer')->with('t-1', 'not ours', 'b@remote')->willReturn(['status' => 'rejected']);
		$calls = new FederatedTokenCalls(
			caseTransferService: $transfers,
			throttler: $this->createMock(originalClassName: IThrottler::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->assertSame(expected: ['sharedWith' => 'b@remote'], actual: $calls->verifiedTransfer(shareToken: 'tok', transferId: 't-1'));
		$this->assertSame(
			expected: ['status' => 'accepted'],
			actual: $calls->answerTransfer(request: $this->request(params: ['action' => 'accept']), transferId: 't-1', sharedWith: 'b@remote')
		);
		$this->assertSame(
			expected: ['status' => 'rejected'],
			actual: $calls->answerTransfer(
				request: $this->request(params: ['action' => 'reject', 'reason' => 'not ours']),
				transferId: 't-1',
				sharedWith: 'b@remote'
			)
		);
		$this->assertNull(actual: $calls->answerTransfer(request: $this->request(params: ['action' => 'keep']), transferId: 't-1', sharedWith: 'b@remote'));
	}//end testTheTransferIsAnsweredAsTheRemoteParty()

	/**
	 * A rejected token is counted under the declared action from the caller's address; a failing throttler is logged.
	 *
	 * @return void
	 */
	public function testARejectedTokenIsCountedAndAFailingThrottlerIsLogged(): void {
		$throttler = $this->createMock(originalClassName: IThrottler::class);
		$attempts = 0;
		$throttler->expects($this->exactly(count: 2))->method('registerAttempt')
			->with('the_action', '10.0.0.7')
			->willReturnCallback(
				callback: static function () use (&$attempts): void {
					$attempts++;
					if ($attempts === 2) {
						throw new \RuntimeException(message: 'cache down');
					}
				}
			);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains(string: 'cache down'));
		$calls = new FederatedTokenCalls(
			caseTransferService: $this->createMock(originalClassName: CaseTransferServiceInterface::class),
			throttler: $throttler,
			logger: $logger,
		);
		$request = $this->request(params: []);

		$calls->registerFailedToken(request: $request, action: 'the_action');
		$calls->registerFailedToken(request: $request, action: 'the_action');
	}//end testARejectedTokenIsCountedAndAFailingThrottlerIsLogged()

	/**
	 * A request carrying these parameters, from 10.0.0.7.
	 *
	 * @param array<string, string> $params The parameters.
	 *
	 * @return IRequest
	 */
	private function request(array $params): IRequest {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$request->method('getRemoteAddress')->willReturn('10.0.0.7');

		return $request;
	}//end request()
}//end class
