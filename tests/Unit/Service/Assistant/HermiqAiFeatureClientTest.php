<?php

/**
 * Unit tests for HermiqAiFeatureClient: an absent hermiq is answered without a
 * request and never as local, a refusal keeps hermiq's gate, and a group is
 * read rather than placed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Assistant
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-without-hermiq-the-features-read-unavailable-rather-than-local
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Assistant;

use OCA\Dossiq\Service\Assistant\HermiqAiFeatureClient;
use OCA\Dossiq\Service\Assistant\HermiqAssistantException;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Assistant\HermiqAiFeatureClient
 *
 * @uses \OCA\Dossiq\Service\Assistant\HermiqAssistantException
 */
class HermiqAiFeatureClientTest extends TestCase {

	/**
	 * Build the client.
	 *
	 * @param bool           $enabled       Whether hermiq is enabled.
	 * @param IClientService $clientService The HTTP client factory.
	 *
	 * @return HermiqAiFeatureClient The client.
	 */
	private function client(bool $enabled, IClientService $clientService): HermiqAiFeatureClient {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'hermiq_service_uid' => 'svc',
				'hermiq_service_app_password' => 'pw',
				default => $default,
			}
		);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://cloud.example.nl');

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn($enabled);

		return new HermiqAiFeatureClient(
			clientService: $clientService,
			urlGenerator: $urlGenerator,
			appConfig: $appConfig,
			appManager: $appManager,
			logger: new NullLogger(),
		);
	}//end client()

	/**
	 * An HTTP client service whose client answers one response.
	 *
	 * @param int    $status The status.
	 * @param string $body   The body.
	 * @param string $verb   The verb expected: get or post.
	 * @param string $url    The URL expected.
	 *
	 * @return IClientService The service.
	 */
	private function answering(int $status, string $body, string $verb, string $url): IClientService {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method($verb)->with($url, $this->anything())->willReturn($response);

		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		return $service;
	}//end answering()

	/**
	 * An absent hermiq answers an empty map, and no request is made.
	 *
	 * The caller reports every declared feature as unavailable from that empty
	 * map; what must never happen is a row that reads as running here.
	 *
	 * @return void
	 */
	public function testAnAbsentHermiqIsAnsweredWithoutARequestAndNeverAsLocal(): void {
		$service = $this->createMock(IClientService::class);
		$service->expects($this->never())->method('newClient');

		$client = $this->client(enabled: false, clientService: $service);

		self::assertFalse(condition: $client->isAvailable());
		self::assertSame(expected: [], actual: $client->featureResidency());
	}//end testAnAbsentHermiqIsAnsweredWithoutARequestAndNeverAsLocal()

	/**
	 * An unreachable hermiq answers the same empty map rather than throwing.
	 *
	 * @return void
	 */
	public function testAnUnreachableHermiqAnswersAnEmptyMap(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('connection refused'));
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		self::assertSame(expected: [], actual: $this->client(enabled: true, clientService: $service)->featureResidency());
	}//end testAnUnreachableHermiqAnswersAnEmptyMap()

	/**
	 * The residency rows are keyed by hermiq's slug.
	 *
	 * @return void
	 */
	public function testTheResidencyIsKeyedByHermiqsSlug(): void {
		$service = $this->answering(
			status: 200,
			body: (string)json_encode(['results' => [['slug' => 'record-summary', 'provider' => 'ollama', 'residency' => 'local'], ['provider' => 'x']]]),
			verb: 'get',
			url: 'https://cloud.example.nl/index.php/apps/hermiq/api/ai-features/residency'
		);

		$rows = $this->client(enabled: true, clientService: $service)->featureResidency();

		self::assertSame(expected: ['record-summary'], actual: array_keys($rows));
	}//end testTheResidencyIsKeyedByHermiqsSlug()

	/**
	 * A refusal keeps hermiq's gate name and its sentence.
	 *
	 * @return void
	 */
	public function testARefusalKeepsHermiqsGate(): void {
		$service = $this->answering(
			status: 403,
			body: (string)json_encode(['error' => 'This provider runs outside the EU', 'gate' => 'residency']),
			verb: 'post',
			url: 'https://cloud.example.nl/index.php/apps/hermiq/api/report-similarity/evaluate'
		);

		try {
			$this->client(enabled: true, clientService: $service)->groupFor(reportId: 'case-1', reportType: 'ct-1', text: 'Stroomstoring');
			self::fail(message: 'A refusal read as an answer.');
		} catch (HermiqAssistantException $e) {
			self::assertSame(expected: 'residency', actual: $e->getErrorCode());
			self::assertSame(expected: 'This provider runs outside the EU', actual: $e->getMessage());
		}
	}//end testARefusalKeepsHermiqsGate()

	/**
	 * A group is read with a GET on its own path, never placed again.
	 *
	 * @return void
	 */
	public function testAGroupIsReadNotPlaced(): void {
		$service = $this->answering(
			status: 200,
			body: (string)json_encode(['groupId' => 'g 7', 'count' => 3]),
			verb: 'get',
			url: 'https://cloud.example.nl/index.php/apps/hermiq/api/report-similarity/groups/g%207'
		);

		$group = $this->client(enabled: true, clientService: $service)->group(groupId: 'g 7');

		self::assertSame(expected: 3, actual: $group['count']);
	}//end testAGroupIsReadNotPlaced()
}//end class
