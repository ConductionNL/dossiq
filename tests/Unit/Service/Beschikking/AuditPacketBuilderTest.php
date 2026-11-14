<?php

/**
 * Unit tests for AuditPacketBuilder: the audit-pakket ZIP carries the masked
 * beschikking, its stateMachineLog trail, the TSP validatierapport and a
 * manifest, plus a SHA-256 integrity stamp; missing storage or configuration
 * yields an empty log trail rather than a failure.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use JsonSerializable;
use OCA\Dossiq\Service\Beschikking\AuditPacketBuilder;
use OCA\Dossiq\Service\Beschikking\SigningAdapterInterface;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * @covers \OCA\Dossiq\Service\Beschikking\AuditPacketBuilder
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class AuditPacketBuilderTest extends TestCase {

	private SettingsService&MockObject $settings;

	private SigningAdapterInterface&MockObject $signing;

	private LoggerInterface&MockObject $logger;

	/**
	 * Config values returned by the settings mock.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'dossiq', 'state_machine_log_schema' => 'state-machine-log'];

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$this->signing = $this->createMock(SigningAdapterInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function builder(): AuditPacketBuilder {
		return new AuditPacketBuilder($this->settings, $this->signing, $this->logger);
	}//end builder()

	/**
	 * A fake ObjectService recording the searches made against it.
	 */
	private function objectService(array $rows = [], ?\Throwable $failure = null): object {
		return new class($rows, $failure) {
			/** @var array<int, array<int, mixed>> */
			public array $slugCalls = [];

			/** @var array<int, array<string, mixed>> */
			public array $queryCalls = [];

			public function __construct(private array $rows, private ?\Throwable $failure) {
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->slugCalls[] = [$register, $schema, $filters];
				if ($this->failure !== null) {
					throw $this->failure;
				}
				return $this->rows;
			}

			public function searchObjects(array $query): array {
				$this->queryCalls[] = $query;
				return $this->rows;
			}
		};
	}//end objectService()

	/**
	 * Read every entry of a ZIP given as bytes.
	 *
	 * @return array<string, string>
	 */
	private function entries(string $zipBytes): array {
		$tmp = tempnam(sys_get_temp_dir(), 'apbt');
		file_put_contents($tmp, $zipBytes);
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($tmp));
		$out = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			$out[$name] = $zip->getFromName($name);
		}
		$zip->close();
		unlink($tmp);
		return $out;
	}//end entries()

	public function testThePacketCarriesFiveEntriesWithTheReportLogsAndManifest(): void {
		$objects = $this->objectService([['decisionId' => 'b-1', 'to' => 'verzonden']]);
		$this->settings->method('getObjectService')->willReturn($objects);
		$this->signing->expects($this->once())->method('fetchValidationReport')->with('val-9')
			->willReturn(['validationRapportId' => 'val-9', 'valid' => true]);

		$entries = $this->entries($this->builder()->build('b-1', [
			'reference' => 'BES-2026-1',
			'signature' => ['validationRapportId' => 'val-9'],
		]));

		$this->assertSame(
			['beschikking.json', 'state-machine-log.json', 'validatierapport.json', 'manifest.json', 'signature.p7s.txt'],
			array_keys($entries)
		);
		$this->assertSame([['dossiq', 'state-machine-log', ['decisionId' => 'b-1']]], $objects->slugCalls);
		$this->assertSame([['decisionId' => 'b-1', 'to' => 'verzonden']], json_decode($entries['state-machine-log.json'], true));
		$this->assertSame(['validationRapportId' => 'val-9', 'valid' => true], json_decode($entries['validatierapport.json'], true));

		$manifest = json_decode($entries['manifest.json'], true);
		$this->assertSame('b-1', $manifest['decisionId']);
		$this->assertSame('BES-2026-1', $manifest['reference']);
		$this->assertSame(['beschikking.json', 'state-machine-log.json', 'validatierapport.json', 'manifest.json'], $manifest['inhoud']);
		$this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $manifest['gegenereerdOp']));
		$this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $entries['signature.p7s.txt']);
	}//end testThePacketCarriesFiveEntriesWithTheReportLogsAndManifest()

	public function testWithoutARapportIdNoReportIsFetchedAndTheReportIsEmpty(): void {
		$this->settings->method('getObjectService')->willReturn($this->objectService());
		$this->signing->expects($this->never())->method('fetchValidationReport');

		$entries = $this->entries($this->builder()->build('b-2', ['signature' => ['validationRapportId' => '']]));

		$this->assertSame([], json_decode($entries['validatierapport.json'], true));
		$this->assertSame('', json_decode($entries['manifest.json'], true)['reference']);
	}//end testWithoutARapportIdNoReportIsFetchedAndTheReportIsEmpty()

	public function testTheBsnIsMaskedToItsLastThreeDigits(): void {
		$this->settings->method('getObjectService')->willReturn(null);

		$entries = $this->entries($this->builder()->build('b-3', [
			'addressee' => ['bsn' => '123456782', 'name' => 'J. Jansen'],
		]));

		$this->assertSame(
			['addressee' => ['bsn' => '******782', 'name' => 'J. Jansen']],
			json_decode($entries['beschikking.json'], true)
		);
	}//end testTheBsnIsMaskedToItsLastThreeDigits()

	public function testABsnOfThreeCharactersOrFewerIsMaskedEntirely(): void {
		$this->settings->method('getObjectService')->willReturn(null);

		$entries = $this->entries($this->builder()->build('b-4', ['addressee' => ['bsn' => '123']]));

		$this->assertSame(['addressee' => ['bsn' => '***']], json_decode($entries['beschikking.json'], true));
	}//end testABsnOfThreeCharactersOrFewerIsMaskedEntirely()

	public function testADecisionWithoutABsnIsExportedUnchanged(): void {
		$this->settings->method('getObjectService')->willReturn(null);
		$decision = ['reference' => 'R', 'addressee' => ['kvk' => '12345678']];

		$entries = $this->entries($this->builder()->build('b-5', $decision));

		$this->assertSame($decision, json_decode($entries['beschikking.json'], true));
	}//end testADecisionWithoutABsnIsExportedUnchanged()

	public function testNoObjectServiceGivesAnEmptyLogTrail(): void {
		$this->settings->method('getObjectService')->willReturn(null);

		$entries = $this->entries($this->builder()->build('b-6', []));

		$this->assertSame([], json_decode($entries['state-machine-log.json'], true));
	}//end testNoObjectServiceGivesAnEmptyLogTrail()

	public function testAnUnconfiguredLogSchemaGivesAnEmptyLogTrailWithoutSearching(): void {
		$this->config['state_machine_log_schema'] = '';
		$objects = $this->objectService([['decisionId' => 'b-7']]);
		$this->settings->method('getObjectService')->willReturn($objects);

		$entries = $this->entries($this->builder()->build('b-7', []));

		$this->assertSame([], json_decode($entries['state-machine-log.json'], true));
		$this->assertSame([], $objects->slugCalls);
		$this->assertSame([], $objects->queryCalls);
	}//end testAnUnconfiguredLogSchemaGivesAnEmptyLogTrailWithoutSearching()

	public function testAFailingSearchIsLoggedAndGivesAnEmptyLogTrail(): void {
		$this->settings->method('getObjectService')->willReturn($this->objectService([], new RuntimeException('db down')));
		$this->logger->expects($this->once())->method('error')
			->with('BeschikkingService: findStateMachineLogs failed', ['exception' => 'db down']);

		$entries = $this->entries($this->builder()->build('b-8', []));

		$this->assertSame([], json_decode($entries['state-machine-log.json'], true));
	}//end testAFailingSearchIsLoggedAndGivesAnEmptyLogTrail()

	public function testNumericIdsSearchThroughSelfAndSerialiseEntities(): void {
		$this->config = ['register' => '5', 'state_machine_log_schema' => '7'];
		$entity = new class implements JsonSerializable {
			public function jsonSerialize(): array {
				return ['decisionId' => 'b-9', 'to' => 'getekend'];
			}
		};
		$objects = $this->objectService([$entity]);
		$this->settings->method('getObjectService')->willReturn($objects);

		$entries = $this->entries($this->builder()->build('b-9', []));

		$this->assertSame([['decisionId' => 'b-9', '@self' => ['register' => 5, 'schema' => 7]]], $objects->queryCalls);
		$this->assertSame([['decisionId' => 'b-9', 'to' => 'getekend']], json_decode($entries['state-machine-log.json'], true));
	}//end testNumericIdsSearchThroughSelfAndSerialiseEntities()

	public function testTheExportIsLoggedWithItsReference(): void {
		$this->settings->method('getObjectService')->willReturn(null);
		$this->logger->expects($this->once())->method('info')
			->with('BeschikkingService: audit-pakket geexporteerd', ['decisionId' => 'b-10', 'reference' => 'BES-10']);

		$this->builder()->build('b-10', ['reference' => 'BES-10']);
	}//end testTheExportIsLoggedWithItsReference()
}//end class
