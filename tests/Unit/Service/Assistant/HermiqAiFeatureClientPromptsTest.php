<?php

/**
 * The prompt library the case assistant reads from hermiq.
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

use OCA\Dossiq\Service\Assistant\HermiqAiFeatureClient;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Assistant\HermiqAiFeatureClient
 *
 * @uses \OCA\Dossiq\Service\Assistant\HermiqAssistantException
 * @uses \OCA\Dossiq\Service\Assistant\HermiqServiceCall
 */
class HermiqAiFeatureClientPromptsTest extends TestCase {
	/**
	 * Build the client.
	 *
	 * @param bool                 $enabled       Whether hermiq is enabled.
	 * @param IClientService       $clientService The HTTP client factory.
	 * @param LoggerInterface|null $logger        The logger.
	 *
	 * @return HermiqAiFeatureClient The client.
	 */
	private function client(bool $enabled, IClientService $clientService, ?LoggerInterface $logger = null): HermiqAiFeatureClient {
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
			logger: ($logger ?? new NullLogger()),
		);
	}//end client()

	/**
	 * An HTTP client service whose client answers one GET.
	 *
	 * @param int    $status The status.
	 * @param string $body   The body.
	 * @param string $url    The URL expected.
	 *
	 * @return IClientService The service.
	 */
	private function answering(int $status, string $body, string $url): IClientService {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('get')->with($url, $this->anything())->willReturn($response);

		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		return $service;
	}//end answering()

	public function testAnAbsentHermiqOffersNoPromptsAndMakesNoRequest(): void {
		$service = $this->createMock(IClientService::class);
		$service->expects($this->never())->method('newClient');

		$this->assertSame([], $this->client(enabled: false, clientService: $service)->prompts('case'));
	}//end testAnAbsentHermiqOffersNoPromptsAndMakesNoRequest()

	public function testThePromptsForAScopeAreReadInOrderAndNonRowsDropped(): void {
		$service = $this->answering(
			status: 200,
			body: (string)json_encode(['results' => [['id' => 'p1'], 'junk', ['id' => 'p2'], 7]]),
			url: 'https://cloud.example.nl/index.php/apps/hermiq/api/assistant-prompts?scope=case%20file'
		);

		$this->assertSame(
			[['id' => 'p1'], ['id' => 'p2']],
			$this->client(enabled: true, clientService: $service)->prompts('case file')
		);
	}//end testThePromptsForAScopeAreReadInOrderAndNonRowsDropped()

	public function testAnswersWithoutAListOfResultsOfferNoPrompts(): void {
		$url = 'https://cloud.example.nl/index.php/apps/hermiq/api/assistant-prompts?scope=case';

		$this->assertSame([], $this->client(true, $this->answering(200, '{"results":"none"}', $url))->prompts('case'));
		$this->assertSame([], $this->client(true, $this->answering(200, '{}', $url))->prompts('case'));
	}//end testAnswersWithoutAListOfResultsOfferNoPrompts()

	public function testARefusedReadIsLoggedAndOffersNoPrompts(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'HermiqAiFeatureClient: could not read the prompt library',
			['app' => 'dossiq', 'scope' => 'case', 'error' => 'not allowed']
		);
		$service = $this->answering(
			status: 403,
			body: '{"message":"not allowed"}',
			url: 'https://cloud.example.nl/index.php/apps/hermiq/api/assistant-prompts?scope=case'
		);

		$this->assertSame([], $this->client(enabled: true, clientService: $service, logger: $logger)->prompts('case'));
	}//end testARefusedReadIsLoggedAndOffersNoPrompts()

	public function testResidencyRowsThatAreNotRowsAreSkipped(): void {
		$service = $this->answering(
			status: 200,
			body: (string)json_encode(['results' => ['junk', ['slug' => 'summary', 'residency' => 'local']]]),
			url: 'https://cloud.example.nl/index.php/apps/hermiq/api/ai-features/residency'
		);

		$this->assertSame(
			['summary' => ['slug' => 'summary', 'residency' => 'local']],
			$this->client(enabled: true, clientService: $service)->featureResidency()
		);
	}//end testResidencyRowsThatAreNotRowsAreSkipped()
}//end class
