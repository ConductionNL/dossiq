<?php

/**
 * Golden outcomes of the two Hermiq service-account clients.
 *
 * HermiqAssistantClient::converse and HermiqAnonymisationClient::detectPii
 * call Hermiq the same way: refuse when Hermiq is absent, refuse (and log)
 * when the service account is not configured, post with the service
 * account, refuse (and log) an unreachable Hermiq, refuse an undecodable or
 * non-2xx reply. This test pins every allowed and refused outcome of both,
 * with the exact messages, statuses, codes, options and log lines.
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

use OCA\Dossiq\Service\Assistant\HermiqAnonymisationClient;
use OCA\Dossiq\Service\Assistant\HermiqAssistantClient;
use OCA\Dossiq\Service\Assistant\HermiqAssistantException;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * @covers \OCA\Dossiq\Service\Assistant\HermiqAssistantClient
 * @covers \OCA\Dossiq\Service\Assistant\HermiqAnonymisationClient
 * @uses \OCA\Dossiq\Service\Assistant\HermiqAssistantException
 */
class HermiqServiceCallGoldenTest extends TestCase {

	/**
	 * The log lines written, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logged = [];

	/**
	 * The posts sent, in order.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $posted = [];

	/**
	 * The two clients, each with the call that reaches Hermiq.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function clients(): array {
		return ['assistant' => ['assistant'], 'anonymisation' => ['anonymisation']];
	}//end clients()

	/**
	 * Allowed: the post carries exactly these options, and the reply is decoded.
	 *
	 * @return void
	 */
	public function testAConfiguredCallPostsWithTheServiceAccount(): void {
		$reply = $this->reply(status: 200, body: '{"sessionId":"s1","reply":"ok","spans":[{"start":1}],"usage":{"tokens":3}}');

		$assistant = $this->call(which: 'assistant', setup: ['reply' => $reply]);
		$anonymisation = $this->call(which: 'anonymisation', setup: ['reply' => $reply]);

		self::assertSame(['sessionId' => 's1', 'reply' => 'ok', 'usage' => ['tokens' => 3]], $assistant);
		self::assertSame(['spans' => [['start' => 1]], 'usage' => ['tokens' => 3]], $anonymisation);
		self::assertSame(
			[
				[
					'https://cloud.example.nl/index.php/apps/hermiq/api/assistant/converse',
					[
						'timeout' => 30,
						'auth' => ['svc', 'pw'],
						'json' => ['message' => 'Hoi', 'context' => ['app' => 'dossiq'], 'sessionId' => 's1'],
						'http_errors' => false,
						'headers' => ['Accept' => 'application/json'],
					],
				],
				[
					'https://cloud.example.nl/index.php/apps/hermiq/api/assistant/detect-pii',
					[
						'timeout' => 30,
						'auth' => ['svc', 'pw'],
						'json' => ['text' => 'Jan', 'context' => ['app' => 'dossiq']],
						'http_errors' => false,
						'headers' => ['Accept' => 'application/json'],
					],
				],
			],
			$this->posted
		);
		self::assertSame([], $this->logged);
	}//end testAConfiguredCallPostsWithTheServiceAccount()

	/**
	 * A successful reply without usage or spans yields empty ones.
	 *
	 * @return void
	 */
	public function testAReplyWithoutUsageYieldsEmptyOnes(): void {
		$reply = $this->reply(status: 204, body: '{"usage":"x","spans":"y"}');

		self::assertSame(['sessionId' => '', 'reply' => '', 'usage' => []], $this->call(which: 'assistant', setup: ['reply' => $reply]));
		self::assertSame(['spans' => [], 'usage' => []], $this->call(which: 'anonymisation', setup: ['reply' => $reply]));
	}//end testAReplyWithoutUsageYieldsEmptyOnes()

	/**
	 * Refused: every refusal of each client, with its message, status, code and log.
	 *
	 * @param string $which `assistant` or `anonymisation`.
	 *
	 * @return void
	 *
	 * @dataProvider clients
	 */
	public function testEveryRefusalKeepsItsShape(string $which): void {
		$name = ($which === 'assistant' ? 'HermiqAssistantClient' : 'HermiqAnonymisationClient');
		$cases = [
			'absent' => [['enabled' => false], ['Hermiq is not installed or enabled on this instance', 503, null], []],
			'no uid' => [['uid' => ''], ['The Hermiq service-account credentials are not configured', 503, null], [['warning', $name . ': service-account credentials are not configured', ['app' => 'dossiq']]]],
			'no password' => [['password' => ''], ['The Hermiq service-account credentials are not configured', 503, null], [['warning', $name . ': service-account credentials are not configured', ['app' => 'dossiq']]]],
			'post throws' => [['throws' => 'post'], ['hermiq_unreachable', 503, null], [['warning', $name . ': request failed', ['app' => 'dossiq', 'url' => $this->urlOf(which: $which), 'error' => 'down']]]],
			'client throws' => [['throws' => 'client'], ['hermiq_unreachable', 503, null], [['warning', $name . ': request failed', ['app' => 'dossiq', 'url' => $this->urlOf(which: $which), 'error' => 'down']]]],
			'not json' => [['reply' => $this->reply(status: 200, body: 'nope')], ['hermiq_invalid_response', 502, null], []],
			'coded refusal' => [['reply' => $this->reply(status: 422, body: '{"message":"m","error":"e","errorCode":"guardrail_blocked"}')], ['m', 422, 'guardrail_blocked'], []],
			'error only' => [['reply' => $this->reply(status: 400, body: '{"error":"e"}')], ['e', 400, null], []],
			'bare refusal' => [['reply' => $this->reply(status: 500, body: '{}')], ['hermiq_api_error', 500, null], []],
		];

		foreach ($cases as $label => [$setup, $expected, $logs]) {
			$this->logged = [];
			try {
				$this->call(which: $which, setup: $setup);
				self::fail($label . ' must be refused');
			} catch (HermiqAssistantException $e) {
				self::assertSame($expected, [$e->getMessage(), $e->getStatusCode(), $e->getErrorCode()], $label);
				self::assertSame($logs, $this->logged, $label);
			}
		}
	}//end testEveryRefusalKeepsItsShape()

	/**
	 * The endpoint each client posts to.
	 *
	 * @param string $which `assistant` or `anonymisation`.
	 *
	 * @return string
	 */
	private function urlOf(string $which): string {
		if ($which === 'assistant') {
			return 'https://cloud.example.nl/index.php/apps/hermiq/api/assistant/converse';
		}

		return 'https://cloud.example.nl/index.php/apps/hermiq/api/assistant/detect-pii';
	}//end urlOf()

	/**
	 * A reply with a status and a body.
	 *
	 * @param int    $status The status.
	 * @param string $body   The body.
	 *
	 * @return IResponse
	 */
	private function reply(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}//end reply()

	/**
	 * Run one client's call over a setup.
	 *
	 * @param string               $which `assistant` or `anonymisation`.
	 * @param array<string, mixed> $setup `enabled`, `uid`, `password`, `throws`, `reply`.
	 *
	 * @return array<string, mixed>
	 */
	private function call(string $which, array $setup): array {
		$config = ['hermiq_service_uid' => ($setup['uid'] ?? 'svc'), 'hermiq_service_app_password' => ($setup['password'] ?? 'pw')];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn($setup['enabled'] ?? true);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('https://cloud.example.nl/');

		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use ($setup): IResponse {
				$this->posted[] = [$url, $options];
				if (($setup['throws'] ?? '') === 'post') {
					throw new RuntimeException('down');
				}

				return $setup['reply'];
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturnCallback(
			static function () use ($client, $setup): IClient {
				if (($setup['throws'] ?? '') === 'client') {
					throw new RuntimeException('down');
				}

				return $client;
			}
		);

		$logger = $this->recordingLogger();
		if ($which === 'assistant') {
			return (new HermiqAssistantClient(clientService: $clients, urlGenerator: $urls, appConfig: $appConfig, appManager: $appManager, logger: $logger))
				->converse(sessionId: 's1', message: 'Hoi', context: ['app' => 'dossiq']);
		}

		return (new HermiqAnonymisationClient(clientService: $clients, urlGenerator: $urls, appConfig: $appConfig, appManager: $appManager, logger: $logger))
			->detectPii(text: 'Jan', context: ['app' => 'dossiq']);
	}//end call()

	/**
	 * A logger that writes each line into the log list.
	 *
	 * @return AbstractLogger
	 */
	private function recordingLogger(): AbstractLogger {
		return new class($this) extends AbstractLogger {
			/**
			 * @param HermiqServiceCallGoldenTest $test The test that keeps the lines.
			 */
			public function __construct(private readonly HermiqServiceCallGoldenTest $test) {
			}

			/**
			 * @param mixed                $level   The level.
			 * @param string|Stringable    $message The line.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->test->record(['log' => [$level, (string)$message, $context]]);
			}
		};
	}//end recordingLogger()

	/**
	 * Write one log line down.
	 *
	 * @param array{log: array<int, mixed>} $line The line.
	 *
	 * @return void
	 */
	public function record(array $line): void {
		$this->logged[] = $line['log'];
	}//end record()
}//end class
