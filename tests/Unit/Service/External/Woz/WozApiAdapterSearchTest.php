<?php

/**
 * Unit tests for WozApiAdapter searches: every match of a search is returned
 * with the first as the result and the tier it came from, the API key header
 * is only sent when one is set, and a failing search degrades to a lookup
 * error with its reason.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\External\Woz;

use OCA\Dossiq\Service\External\IntegrationMode;
use OCA\Dossiq\Service\External\Woz\WozApiAdapter;
use OCA\Dossiq\Service\External\Woz\WozResponseMapper;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\External\Woz\WozApiAdapter
 *
 * @uses \OCA\Dossiq\Service\External\IntegrationMode
 * @uses \OCA\Dossiq\Service\External\Woz\WozResponseMapper
 * @uses \OCA\Dossiq\Service\External\Woz\WozLookupResult
 * @uses \OCA\Dossiq\Service\External\RegisterValues
 */
class WozApiAdapterSearchTest extends TestCase {

	private LoggerInterface&MockObject $logger;

	/** @var array<string, mixed> */
	private array $captured = [];

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function adapter(int $status, string $body, array $config = ['integration.woz.mode' => 'live']): WozApiAdapter {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $config[$key] ?? $default);
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use ($response): IResponse {
				$this->captured = ['url' => $url, 'options' => $options];
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new WozApiAdapter($clients, new IntegrationMode($appConfig), new WozResponseMapper(), $this->logger);
	}//end adapter()

	public function testEveryMatchIsReturnedWithTheFirstAsTheResult(): void {
		$body = (string)json_encode(['_embedded' => ['wozObjecten' => [['wozobjectnummer' => '1'], 'junk', ['wozobjectnummer' => '2']]]]);

		$result = $this->adapter(200, $body)->lookupByNummeraanduiding('0518200000123456');

		$this->assertSame('FOUND', $result->lookupStatus);
		$this->assertSame('1', $result->wozObject['wozobjectnummer']);
		$this->assertSame('live', $result->extras['tier']);
		$this->assertSame(2, $result->extras['count']);
		$this->assertSame(['1', '2'], array_column($result->extras['matches'], 'wozobjectnummer'));
		$this->assertFalse($result->dormant);
	}//end testEveryMatchIsReturnedWithTheFirstAsTheResult()

	public function testTheApiKeyHeaderIsOnlySentWhenSetAndTheBaseUrlIsTrimmed(): void {
		$this->adapter(200, '{}', ['integration.woz.baseUrl' => 'https://woz.example/api/'])->lookupByNummeraanduiding('n-1');

		$this->assertSame('https://woz.example/api/wozobjecten', $this->captured['url']);
		$this->assertSame(['Accept' => 'application/hal+json'], $this->captured['options']['headers']);
		$this->assertSame(['nummeraanduidingIdentificatie' => 'n-1'], $this->captured['options']['query']);
		$this->assertSame(10, $this->captured['options']['timeout']);
	}//end testTheApiKeyHeaderIsOnlySentWhenSetAndTheBaseUrlIsTrimmed()

	public function testAMalformedSearchBodyIsNotFound(): void {
		$this->assertSame('NOT_FOUND', $this->adapter(200, 'not json')->lookupByNummeraanduiding('n-1')->lookupStatus);
		$this->assertSame('NOT_FOUND', $this->adapter(200, '{"_embedded":"none"}')->lookupByNummeraanduiding('n-1')->lookupStatus);
	}//end testAMalformedSearchBodyIsNotFound()

	public function testANonSuccessSearchIsALookupErrorNamingItsStatus(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq WOZ lookup returned a non-success status',
			['status' => 429, 'context' => ['caseId' => 'case-1']]
		);

		$result = $this->adapter(429, '')->lookupAddress('1234 AB', '10', context: ['caseId' => 'case-1']);

		$this->assertSame('LOOKUP_ERROR', $result->lookupStatus);
		$this->assertSame(['reason' => 'http-429'], $result->extras);
	}//end testANonSuccessSearchIsALookupErrorNamingItsStatus()

	public function testAnObjectBodyWithoutAWrapperIsMappedAsItIs(): void {
		$result = $this->adapter(200, '{"wozobjectnummer":"0518"}')->lookupByWozObjectNummer('05 18');

		$this->assertSame('FOUND', $result->lookupStatus);
		$this->assertSame('0518', $result->wozObject['wozobjectnummer']);
		$this->assertStringEndsWith('/wozobjecten/05%2018', $this->captured['url']);
		$this->assertSame('NOT_FOUND', $this->adapter(200, '[]')->lookupByWozObjectNummer('0518')->lookupStatus);
	}//end testAnObjectBodyWithoutAWrapperIsMappedAsItIs()
}//end class
