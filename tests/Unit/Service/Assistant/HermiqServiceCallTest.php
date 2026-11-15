<?php

/**
 * How one outbound Hermiq call is built, refused, sent and decoded.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Assistant
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Assistant;

use OCA\Dossiq\Service\Assistant\HermiqAssistantException;
use OCA\Dossiq\Service\Assistant\HermiqPost;
use OCA\Dossiq\Service\Assistant\HermiqServiceCall;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Assistant\HermiqServiceCall
 * @covers \OCA\Dossiq\Service\Assistant\HermiqAssistantException
 * @uses   \OCA\Dossiq\Service\Assistant\HermiqPost
 */
class HermiqServiceCallTest extends TestCase {
	/**
	 * A response with a status and a raw body.
	 *
	 * @param int    $status The HTTP status.
	 * @param string $body   The raw body.
	 *
	 * @return IResponse
	 */
	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		return $response;
	}//end response()

	public function testCredentialsAreReadFromTheTwoServiceAccountKeys(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->exactly(2))->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => match ([$app, $key, $default]) {
				['dossiq', 'hermiq_service_uid', ''] => 'svc-user',
				['dossiq', 'hermiq_service_app_password', ''] => 'secret-pw',
			}
		);

		$call = new HermiqServiceCall($this->createMock(LoggerInterface::class), 'Client');

		$this->assertSame(['svc-user', 'secret-pw'], $call->credentialsFrom($appConfig));
	}//end testCredentialsAreReadFromTheTwoServiceAccountKeys()

	public function testPostOptionsCarryAuthBodyTimeoutAndKeepErrorStatuses(): void {
		$call = new HermiqServiceCall($this->createMock(LoggerInterface::class), 'Client');

		$this->assertSame(
			[
				'timeout' => 30,
				'auth' => ['uid', 'pw'],
				'json' => ['prompt' => 'hi'],
				'http_errors' => false,
				'headers' => ['Accept' => 'application/json'],
			],
			$call->postOptions(['uid', 'pw'], ['prompt' => 'hi'])
		);
	}//end testPostOptionsCarryAuthBodyTimeoutAndKeepErrorStatuses()

	public function testAnAvailableHermiqPassesTheCheck(): void {
		$call = new HermiqServiceCall($this->createMock(LoggerInterface::class), 'Client');
		$call->requireAvailable(true);
		$this->addToAssertionCount(1);
	}//end testAnAvailableHermiqPassesTheCheck()

	public function testAMissingHermiqIsRefusedWith503(): void {
		$call = new HermiqServiceCall($this->createMock(LoggerInterface::class), 'Client');

		try {
			$call->requireAvailable(false);
			$this->fail('Expected a refusal');
		} catch (HermiqAssistantException $e) {
			$this->assertSame('Hermiq is not installed or enabled on this instance', $e->getMessage());
			$this->assertSame(503, $e->getStatusCode());
			$this->assertSame(503, $e->getCode());
			$this->assertNull($e->getErrorCode());
		}
	}//end testAMissingHermiqIsRefusedWith503()

	public function testCompleteCredentialsPassWithoutLogging(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		(new HermiqServiceCall($logger, 'Client'))->requireCredentials(['uid', 'pw']);
	}//end testCompleteCredentialsPassWithoutLogging()

	/**
	 * @return array<string, array{0: array{0: string, 1: string}}>
	 */
	public static function incompleteCredentials(): array {
		return [
			'no uid' => [['', 'pw']],
			'no password' => [['uid', '']],
			'neither' => [['', '']],
		];
	}//end incompleteCredentials()

	/**
	 * @dataProvider incompleteCredentials
	 *
	 * @param array{0: string, 1: string} $credentials The credentials.
	 */
	public function testIncompleteCredentialsAreLoggedAndRefused(array $credentials): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'AssistantClient: service-account credentials are not configured',
			['app' => 'dossiq']
		);

		try {
			(new HermiqServiceCall($logger, 'AssistantClient'))->requireCredentials($credentials);
			$this->fail('Expected a refusal');
		} catch (HermiqAssistantException $e) {
			$this->assertSame('The Hermiq service-account credentials are not configured', $e->getMessage());
			$this->assertSame(503, $e->getStatusCode());
		}
	}//end testIncompleteCredentialsAreLoggedAndRefused()

	public function testSendPostsTheUrlAndOptionsAndReturnsTheResponse(): void {
		$response = $this->response(200, '{}');
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')
			->with('https://hermiq.test/api', ['timeout' => 30])
			->willReturn($response);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$call = new HermiqServiceCall($this->createMock(LoggerInterface::class), 'Client');

		$this->assertSame($response, $call->send($clients, new HermiqPost('https://hermiq.test/api', ['timeout' => 30])));
	}//end testSendPostsTheUrlAndOptionsAndReturnsTheResponse()

	public function testATransportFailureIsLoggedAndRefusedAsUnreachable(): void {
		$failure = new RuntimeException('connection refused');
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException($failure);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Anonymiser: request failed',
			['app' => 'dossiq', 'url' => 'https://hermiq.test/x', 'error' => 'connection refused']
		);

		try {
			(new HermiqServiceCall($logger, 'Anonymiser'))->send($clients, new HermiqPost('https://hermiq.test/x', []));
			$this->fail('Expected a refusal');
		} catch (HermiqAssistantException $e) {
			$this->assertSame('hermiq_unreachable', $e->getMessage());
			$this->assertSame(503, $e->getStatusCode());
			$this->assertSame($failure, $e->getPrevious());
		}
	}//end testATransportFailureIsLoggedAndRefusedAsUnreachable()

	public function testA2xxReplyIsDecoded(): void {
		$call = new HermiqServiceCall($this->createMock(LoggerInterface::class), 'Client');

		$this->assertSame(['answer' => 'yes'], $call->decoded($this->response(201, '{"answer":"yes"}')));
	}//end testA2xxReplyIsDecoded()

	public function testAnUndecodableBodyIsAnInvalidResponseEvenOn200(): void {
		$call = new HermiqServiceCall($this->createMock(LoggerInterface::class), 'Client');

		try {
			$call->decoded($this->response(200, 'not json'));
			$this->fail('Expected a refusal');
		} catch (HermiqAssistantException $e) {
			$this->assertSame('hermiq_invalid_response', $e->getMessage());
			$this->assertSame(502, $e->getStatusCode());
		}
	}//end testAnUndecodableBodyIsAnInvalidResponseEvenOn200()

	public function testANon2xxReplyRelaysItsMessageStatusAndErrorCode(): void {
		$call = new HermiqServiceCall($this->createMock(LoggerInterface::class), 'Client');

		try {
			$call->decoded($this->response(422, '{"message":"too long","error":"ignored","errorCode":"input_too_long"}'));
			$this->fail('Expected a refusal');
		} catch (HermiqAssistantException $e) {
			$this->assertSame('too long', $e->getMessage());
			$this->assertSame(422, $e->getStatusCode());
			$this->assertSame('input_too_long', $e->getErrorCode());
		}
	}//end testANon2xxReplyRelaysItsMessageStatusAndErrorCode()

	public function testANon2xxReplyFallsBackToErrorThenToAGenericCode(): void {
		$call = new HermiqServiceCall($this->createMock(LoggerInterface::class), 'Client');

		try {
			$call->decoded($this->response(403, '{"error":"forbidden"}'));
			$this->fail('Expected a refusal');
		} catch (HermiqAssistantException $e) {
			$this->assertSame('forbidden', $e->getMessage());
			$this->assertNull($e->getErrorCode());
		}

		try {
			$call->decoded($this->response(199, '{}'));
			$this->fail('Expected a refusal');
		} catch (HermiqAssistantException $e) {
			$this->assertSame('hermiq_api_error', $e->getMessage());
			$this->assertSame(199, $e->getStatusCode());
		}

		try {
			$call->decoded($this->response(300, '[]'));
			$this->fail('Expected a refusal');
		} catch (HermiqAssistantException $e) {
			$this->assertSame('hermiq_api_error', $e->getMessage());
			$this->assertSame(300, $e->getStatusCode());
		}
	}//end testANon2xxReplyFallsBackToErrorThenToAGenericCode()

	public function testListOfReturnsAnArrayUnderTheKeyOrAnEmptyList(): void {
		$call = new HermiqServiceCall($this->createMock(LoggerInterface::class), 'Client');

		$this->assertSame([['tokens' => 3]], $call->listOf(['usage' => [['tokens' => 3]]], 'usage'));
		$this->assertSame([], $call->listOf(['usage' => 'many'], 'usage'));
		$this->assertSame([], $call->listOf([], 'spans'));
	}//end testListOfReturnsAnArrayUnderTheKeyOrAnEmptyList()
}//end class
