<?php

/**
 * Golden outcomes of HermiqAiFeatureClient's calls with the service account.
 *
 * Pins every allowed and refused outcome of one GET and one POST: refused
 * when Hermiq is absent, refused WITHOUT a log line when the service account
 * is not configured, refused and logged when Hermiq cannot be reached, and
 * the exact options of a GET (no body) and a POST (a JSON body).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Assistant
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
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
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Assistant\HermiqAiFeatureClient
 * @uses \OCA\Dossiq\Service\Assistant\HermiqAssistantException
 * @uses \OCA\Dossiq\Service\Assistant\HermiqServiceCall
 */
class HermiqAiFeatureCallGoldenTest extends TestCase {

	/**
	 * The warnings logged, in order.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * The requests sent, in order.
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $sent = [];

	/**
	 * Allowed: a GET carries no body, a POST carries the JSON body.
	 *
	 * @return void
	 */
	public function testAGetAndAPostCarryTheServiceAccount(): void {
		$this->client(setup: [])->group(groupId: 'g 1');
		$this->client(setup: [])->groupFor(reportId: 'r1', reportType: 'woo', text: 't');

		$base = ['timeout' => 30, 'auth' => ['svc', 'pw'], 'http_errors' => false, 'headers' => ['Accept' => 'application/json']];
		self::assertSame('get', $this->sent[0][0]);
		self::assertSame($base, $this->sent[0][2]);
		self::assertStringEndsWith('g%201', $this->sent[0][1]);
		self::assertSame('post', $this->sent[1][0]);
		self::assertSame($base + ['json' => ['reportId' => 'r1', 'reportType' => 'woo', 'text' => 't', 'deterministicKey' => '']], $this->sent[1][2]);
		self::assertStringStartsWith('https://cloud.example.nl/index.php/apps/hermiq/', $this->sent[1][1]);
		self::assertSame([], $this->warnings);
	}//end testAGetAndAPostCarryTheServiceAccount()

	/**
	 * Refused: absent, unconfigured (no log line) and unreachable (logged).
	 *
	 * @return void
	 */
	public function testEveryRefusalKeepsItsShape(): void {
		$cases = [
			'absent' => [['enabled' => false], ['Hermiq is not installed or enabled on this instance', 503], 0],
			'no uid' => [['uid' => ''], ['The Hermiq service-account credentials are not configured', 503], 0],
			'no password' => [['password' => ''], ['The Hermiq service-account credentials are not configured', 503], 0],
			'unreachable' => [['throws' => true], ['hermiq_unreachable', 503], 1],
		];

		foreach ($cases as $label => [$setup, $expected, $logLines]) {
			$this->warnings = [];
			$this->sent = [];
			try {
				$this->client(setup: $setup)->group(groupId: 'g1');
				self::fail($label . ' must be refused');
			} catch (HermiqAssistantException $e) {
				self::assertSame($expected, [$e->getMessage(), $e->getStatusCode()], $label);
				self::assertCount($logLines, $this->warnings, $label);
			}
		}

		self::assertSame('HermiqAiFeatureClient: request failed', $this->warnings[0][0]);
		self::assertSame(['app', 'url', 'error'], array_keys($this->warnings[0][1]));
		self::assertSame('down', $this->warnings[0][1]['error']);
		self::assertStringEndsWith('g1', $this->warnings[0][1]['url']);
	}//end testEveryRefusalKeepsItsShape()

	/**
	 * The client over a setup.
	 *
	 * @param array<string, mixed> $setup `enabled`, `uid`, `password`, `throws`.
	 *
	 * @return HermiqAiFeatureClient
	 */
	private function client(array $setup): HermiqAiFeatureClient {
		$config = ['hermiq_service_uid' => ($setup['uid'] ?? 'svc'), 'hermiq_service_app_password' => ($setup['password'] ?? 'pw')];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn($setup['enabled'] ?? true);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('https://cloud.example.nl');

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('{}');
		$client = $this->createMock(IClient::class);
		foreach (['get', 'post'] as $verb) {
			$client->method($verb)->willReturnCallback(
				function (string $url, array $options) use ($verb, $setup, $response): IResponse {
					$this->sent[] = [$verb, $url, $options];
					if (($setup['throws'] ?? false) === true) {
						throw new RuntimeException('down');
					}

					return $response;
				}
			);
		}

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new HermiqAiFeatureClient(clientService: $clients, urlGenerator: $urls, appConfig: $appConfig, appManager: $appManager, logger: $logger);
	}//end client()
}//end class
