<?php

/**
 * WebhookHandler Unit Tests
 *
 * Verifies the webhook action handler enforces an http(s) URL, posts the
 * `{case, transition}` payload, maps non-2xx responses to failure envelopes,
 * and swallows transport exceptions.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-20
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Transitions;

use OCA\Procest\Service\Transitions\WebhookHandler;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Procest\Service\Transitions\WebhookHandler
 *
 * @uses \OCA\Procest\Service\Transitions\ActionResult
 */
class WebhookHandlerTest extends TestCase {
	/**
	 * @return void
	 */
	public function testFailsWhenUrlMissing(): void {
		$handler = new WebhookHandler(
			clientService: $this->createMock(IClientService::class),
			logger: new NullLogger(),
		);

		$result = $handler->handle(
			actionConfig: ['type' => 'webhook'],
			case: ['id' => 'c'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('webhook_invalid_url', $result->error);
	}//end testFailsWhenUrlMissing()

	/**
	 * @return void
	 */
	public function testFailsWhenUrlSchemeNotHttp(): void {
		$handler = new WebhookHandler(
			clientService: $this->createMock(IClientService::class),
			logger: new NullLogger(),
		);

		$result = $handler->handle(
			actionConfig: ['type' => 'webhook', 'url' => 'ftp://example.com/x'],
			case: ['id' => 'c'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('webhook_invalid_url', $result->error);
	}//end testFailsWhenUrlSchemeNotHttp()

	/**
	 * @return void
	 */
	public function testSucceedsOn2xxAndForwardsPayload(): void {
		$captured = null;

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(202);

		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use (&$captured, $response): IResponse {
				$captured = ['url' => $url, 'options' => $options];
				return $response;
			}
		);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$handler = new WebhookHandler($clientService, new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'webhook', 'url' => 'https://example.com/hook', 'headers' => ['X-Auth' => 'k']],
			case: ['id' => 'case-7'],
			transitionContext: ['transitionLabel' => 'Decided'],
		);

		self::assertTrue($result->succeeded);
		self::assertSame(202, $result->data['status']);
		self::assertSame('https://example.com/hook', $captured['url']);
		self::assertSame(['X-Auth' => 'k'], $captured['options']['headers']);
		self::assertSame(5, $captured['options']['timeout']);
		self::assertSame(['id' => 'case-7'], $captured['options']['json']['case']);
		self::assertSame(['transitionLabel' => 'Decided'], $captured['options']['json']['transition']);
	}//end testSucceedsOn2xxAndForwardsPayload()

	/**
	 * @return void
	 */
	public function testFailsOnNon2xxResponse(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(503);

		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($response);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$handler = new WebhookHandler($clientService, new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'webhook', 'url' => 'https://example.com/h'],
			case: ['id' => 'c'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('webhook_non_2xx', $result->error);
		self::assertSame(503, $result->data['status']);
	}//end testFailsOnNon2xxResponse()

	/**
	 * @return void
	 */
	public function testSwallowsTransportException(): void {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException(new RuntimeException('timeout'));

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$handler = new WebhookHandler($clientService, new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'webhook', 'url' => 'https://example.com/h'],
			case: ['id' => 'c'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('webhook_failed', $result->error);
	}//end testSwallowsTransportException()
}//end class
