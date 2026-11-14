<?php

/**
 * Unit tests for StufHttpClient.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Stuf;

use OCA\Dossiq\Service\Stuf\StufHttpClient;
use OCA\Dossiq\Service\Stuf\StufVaultService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Stuf\StufHttpClient
 * @uses \OCA\Dossiq\Service\Stuf\StufVaultService
 */
class StufHttpClientTest extends TestCase {
	private IClient $client;

	private function http(string $secret = ''): StufHttpClient {
		$this->client = $this->createMock(IClient::class);
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($this->client);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($secret);

		return new StufHttpClient($service, new StufVaultService($config, $this->createMock(LoggerInterface::class)), $this->createMock(LoggerInterface::class));
	}

	public function testTheEnvelopeIsPostedWithTheSoapHeaders(): void {
		$http = $this->http();
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('<Bv03/>');
		$response->method('getStatusCode')->willReturn(200);
		$this->client->expects($this->once())->method('post')->with(
			'https://zs.example.nl/stuf',
			[
				'body' => '<env/>',
				'headers' => ['Content-Type' => 'text/xml; charset=UTF-8', 'SOAPAction' => '"genereerZaakIdentificatie_Di02"', 'User-Agent' => 'Dossiq-StUF/1.0'],
				'timeout' => 12,
				'verify' => true,
			]
		)->willReturn($response);

		$result = $http->send(['id' => 'ep-1', 'endpointUrl' => 'https://zs.example.nl/stuf'], '<env/>', 'genereerZaakIdentificatie_Di02', 12);

		$this->assertSame(200, $result['httpStatus']);
		$this->assertSame('<Bv03/>', $result['responseXml']);
		$this->assertNull($result['fout']);
		$this->assertIsInt($result['durationMs']);
	}

	public function testAPlainHttpEndpointIsRefusedWithoutARequest(): void {
		$http = $this->http();
		$this->client->expects($this->never())->method('post');

		$this->assertSame(
			['httpStatus' => 0, 'responseXml' => '', 'durationMs' => 0, 'fout' => ['code' => 'TRANSPORT_NON_HTTPS', 'omschrijving' => 'Endpoint URL is not HTTPS', 'details' => '', 'kind' => 'permanent']],
			$http->send(['endpointUrl' => 'http://zs.example.nl/stuf'], '<env/>')
		);
	}

	public function testAMissingClientCertificateIsAPermanentFailure(): void {
		$http = $this->http('');
		$this->client->expects($this->never())->method('post');

		$result = $http->send(['endpointUrl' => 'https://zs.example.nl/stuf', 'tlsClientCertRef' => 'vault://cert'], '<env/>');

		$this->assertSame(['TLS_CERT_LOAD_FAILED', 'permanent'], [$result['fout']['code'], $result['fout']['kind']]);
	}

	public function testAClientCertificateIsWrittenToAPrivateFileAndPassed(): void {
		$http = $this->http("-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n");
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('');
		$response->method('getStatusCode')->willReturn(500);
		$seen = null;
		$this->client->method('post')->willReturnCallback(function (string $uri, array $options) use (&$seen, $response): IResponse {
			$seen = $options['cert'] ?? null;
			$this->assertSame("-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n", file_get_contents($seen));
			$this->assertSame('0600', substr(sprintf('%o', fileperms($seen)), -4));
			return $response;
		});

		$result = $http->send(['endpointUrl' => 'https://zs.example.nl/stuf', 'tlsClientCertRef' => 'vault://cert'], '<env/>');

		$this->assertIsString($seen);
		$this->assertSame(500, $result['httpStatus']);
		@unlink($seen);
	}

	/** @return array<string, array{string, string}> */
	public static function transportErrors(): array {
		return [
			'timed out' => ['cURL error 28: Operation timed out after 30001 milliseconds', 'TIMEOUT'],
			'timeout' => ['Connection timeout', 'TIMEOUT'],
			'refused' => ['Connection refused', 'NETWORK'],
		];
	}

	/** @dataProvider transportErrors */
	public function testATransportErrorIsTransientAndClassified(string $message, string $code): void {
		$http = $this->http();
		$this->client->method('post')->willThrowException(new \RuntimeException($message));

		$result = $http->send(['endpointUrl' => 'https://zs.example.nl/stuf'], '<env/>');

		$this->assertSame(0, $result['httpStatus']);
		$this->assertSame(['code' => $code, 'omschrijving' => 'Transport error', 'details' => $message, 'kind' => 'transient'], $result['fout']);
	}
}
