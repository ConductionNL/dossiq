<?php

/**
 * BrkApiAdapter kadastrale-aanduiding search golden test
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\External\Brk
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
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\External\Brk;

use OCA\Dossiq\Service\External\Brk\BrkApiAdapter;
use OCA\Dossiq\Service\External\Brk\BrkResponseMapper;
use OCA\Dossiq\Service\External\IntegrationMode;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden test for BrkApiAdapter::lookupByKadastraleAanduiding(): every answer, request and log line.
 *
 * @covers \OCA\Dossiq\Service\External\Brk\BrkApiAdapter
 * @uses \OCA\Dossiq\Service\External\Brk\BrkLookupResult
 * @uses \OCA\Dossiq\Service\External\Brk\BrkResponseMapper
 * @uses \OCA\Dossiq\Service\External\IntegrationMode
 */
class BrkApiAdapterSearchGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The requests made in one world.
	 *
	 * @var array<int, mixed>
	 */
	private array $requests = [];

	/**
	 * Run one search in a world and observe it.
	 *
	 * @param array<string, mixed> $world The input, settings and response of the world.
	 *
	 * @return array<string, mixed> The answer, the requests and the log lines.
	 */
	private function observe(array $world): array {
		$this->requests = [];
		$logger = new class extends AbstractLogger {
			/**
			 * The log lines.
			 *
			 * @var array<int, mixed>
			 */
			public array $lines = [];

			/**
			 * Keep one log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed>       $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [$level, (string)$message, $context];
			}
		};

		$config = ($world['config'] ?? ['integration.brk.mode' => 'test', 'integration.brk.apiKey' => 'key-1']);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (string)($config[$key] ?? $default)
		);

		$response = $this->createMock(originalClassName: IResponse::class);
		if (($world['response'] ?? null) === 'throw') {
			$response->method('getStatusCode')->willThrowException(new RuntimeException('status unreadable'));
		} else {
			$response->method('getStatusCode')->willReturn(($world['status'] ?? 200));
		}

		$response->method('getBody')->willReturn(($world['body'] ?? '{"_embedded":{"kadastraalOnroerendeZaken":[]}}'));

		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $uri, array $options = []) use ($world, $response): IResponse {
				$this->requests[] = ['uri' => $uri, 'options' => $options];
				if (($world['get'] ?? null) === 'throw') {
					throw new RuntimeException('connection refused');
				}

				return $response;
			}
		);

		$clients = $this->createMock(originalClassName: IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$adapter = new BrkApiAdapter(
			clientService: $clients,
			mode: new IntegrationMode(appConfig: $appConfig),
			mapper: new BrkResponseMapper(),
			logger: $logger
		);

		$ask = ($world['ask'] ?? []) + ['municipalityCode' => 'VBSTD', 'section' => 'a', 'perceelnummer' => '1234'];
		$result = $adapter->lookupByKadastraleAanduiding(...$ask);

		return [
			'answer' => ['lookupStatus' => $result->lookupStatus, 'parcel' => $result->parcel, 'dormant' => $result->dormant, 'extras' => $result->extras],
			'requests' => $this->requests,
			'logs' => $logger->lines,
		];
	}//end observe()

	/**
	 * Every path through lookupByKadastraleAanduiding().
	 *
	 * @return void
	 */
	public function testGoldenSearch(): void {
		$found = '{"_embedded":{"kadastraalOnroerendeZaken":['
			. '{"identificatie":"76870487970000","kadastraleAanduiding":{"kadastraleGemeente":{"code":"VBSTD"},"sectie":"A","perceelnummer":1234},'
			. '"grootte":{"waarde":250},"type":"perceel"},'
			. '{"identificatie":"76870487970001","type":"appartementsrecht"}]}}';
		$worlds = [
			'no match' => [],
			'one or more matches' => ['body' => $found],
			'with a volgnummer' => ['ask' => ['sequenceNumber' => 'a12'], 'body' => $found],
			'empty volgnummer is left out' => ['ask' => ['sequenceNumber' => '']],
			'context is passed to the logs' => ['ask' => ['context' => ['caseId' => 'c-1']], 'status' => 503],
			'context on a transport failure' => ['ask' => ['context' => ['caseId' => 'c-2'], 'sequenceNumber' => 'A1'], 'get' => 'throw'],
			'empty gemeentecode' => ['ask' => ['municipalityCode' => '']],
			'sectie of digits' => ['ask' => ['section' => '1']],
			'sectie of three letters' => ['ask' => ['section' => 'abc']],
			'empty sectie' => ['ask' => ['section' => '']],
			'perceelnummer with letters' => ['ask' => ['perceelnummer' => '12a']],
			'perceelnummer of six digits' => ['ask' => ['perceelnummer' => '123456']],
			'volgnummer without A' => ['ask' => ['sequenceNumber' => '12']],
			'volgnummer too long' => ['ask' => ['sequenceNumber' => 'A12345']],
			'gemeentecode checked before sectie' => ['ask' => ['municipalityCode' => '', 'section' => '1', 'perceelnummer' => 'x']],
			'sectie checked before perceelnummer' => ['ask' => ['section' => '1', 'perceelnummer' => 'x', 'sequenceNumber' => 'x']],
			'perceelnummer checked before volgnummer' => ['ask' => ['perceelnummer' => 'x', 'sequenceNumber' => 'x']],
			'status 199' => ['status' => 199],
			'status 299' => ['status' => 299, 'body' => $found],
			'status 300' => ['status' => 300],
			'status 404' => ['status' => 404],
			'status 500' => ['status' => 500],
			'body not json' => ['body' => 'not json'],
			'embedded not a list' => ['body' => '{"_embedded":"x"}'],
			'no embedded' => ['body' => '{"items":[1]}'],
			'unreadable status' => ['response' => 'throw'],
			'transport failure' => ['get' => 'throw'],
			'base url with trailing slash, no api key' => [
				'config' => ['integration.brk.mode' => 'live', 'integration.brk.baseUrl' => 'https://brk.example/v2/'],
				'body' => $found,
			],
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'land-registry-search', value: $observed);
	}//end testGoldenSearch()
}//end class
