<?php

/**
 * Audit packet builder test
 *
 * The audit packet of a beschikking is a ZIP with the decision (its BSN
 * masked to the last three digits), the state machine log of that decision,
 * the signing validation report and a manifest, plus a SHA-256 digest of
 * that content. A log that cannot be read leaves an empty log, not a failed
 * export.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\AuditPacketBuilder;
use OCA\Dossiq\Service\Beschikking\SigningAdapterInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ZipArchive;

/**
 * @covers \OCA\Dossiq\Service\Beschikking\AuditPacketBuilder
 */
class AuditPacketBuilderTest extends TestCase {

	/**
	 * The register.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * A register with two log rows for the decision and one for another.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->register->seed(schema: 'stateMachineLog', uuid: 'log-1', row: ['decisionId' => 'dec-1', 'to' => 'concept']);
		$this->register->seed(schema: 'stateMachineLog', uuid: 'log-2', row: ['decisionId' => 'dec-1', 'to' => 'ondertekend']);
		$this->register->seed(schema: 'stateMachineLog', uuid: 'log-3', row: ['decisionId' => 'dec-2', 'to' => 'concept']);
	}//end setUp()

	/**
	 * The builder over the register, with the given log schema.
	 *
	 * @param SigningAdapterInterface $signing   The signing adapter.
	 * @param string                  $logSchema The state machine log schema.
	 * @param LoggerInterface|null    $logger    The logger.
	 *
	 * @return AuditPacketBuilder
	 */
	private function builder(SigningAdapterInterface $signing, string $logSchema = 'stateMachineLog', ?LoggerInterface $logger = null): AuditPacketBuilder {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'dossiq', 'state_machine_log_schema' => $logSchema][$key] ?? ''
		);

		return new AuditPacketBuilder($settings, $signing, ($logger ?? new NullLogger()));
	}//end builder()

	/**
	 * The entries of a ZIP, by name.
	 *
	 * @param string $bytes The ZIP.
	 *
	 * @return array<string, string>
	 */
	private function entries(string $bytes): array {
		$path = (string)tempnam(sys_get_temp_dir(), 'audit-test');
		file_put_contents($path, $bytes);
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($path));
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			$entries[$name] = (string)$zip->getFromName($name);
		}

		$zip->close();
		unlink($path);
		return $entries;
	}//end entries()

	/**
	 * The packet holds the masked decision, its own log, the report and the manifest, and a digest.
	 *
	 * @return void
	 */
	public function testThePacketHoldsTheMaskedDecisionItsLogAndTheReport(): void {
		$signing = $this->createMock(SigningAdapterInterface::class);
		$signing->expects($this->once())->method('fetchValidationReport')->with('rap-7')->willReturn(['valid' => true]);

		$decision = ['reference' => 'B-2026-1', 'addressee' => ['bsn' => '999993653', 'name' => 'J. Jansen'], 'signature' => ['validationRapportId' => 'rap-7']];
		$entries = $this->entries($this->builder($signing)->build('dec-1', $decision));

		$this->assertSame(
			['beschikking.json', 'state-machine-log.json', 'validatierapport.json', 'manifest.json', 'signature.p7s.txt'],
			array_keys($entries)
		);
		$this->assertSame(
			['reference' => 'B-2026-1', 'addressee' => ['bsn' => '******653', 'name' => 'J. Jansen'], 'signature' => ['validationRapportId' => 'rap-7']],
			json_decode($entries['beschikking.json'], true)
		);
		$this->assertStringNotContainsString('999993653', implode('', $entries));
		$this->assertSame(['concept', 'ondertekend'], array_column(json_decode($entries['state-machine-log.json'], true), 'to'));
		$this->assertSame(['valid' => true], json_decode($entries['validatierapport.json'], true));

		$manifest = json_decode($entries['manifest.json'], true);
		$this->assertSame('dec-1', $manifest['decisionId']);
		$this->assertSame('B-2026-1', $manifest['reference']);
		$this->assertSame(['beschikking.json', 'state-machine-log.json', 'validatierapport.json', 'manifest.json'], $manifest['inhoud']);
		$this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $manifest['gegenereerdOp']));
		$this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $entries['signature.p7s.txt']);
	}//end testThePacketHoldsTheMaskedDecisionItsLogAndTheReport()

	/**
	 * Without a report id no report is fetched; a short BSN is fully masked.
	 *
	 * @return void
	 */
	public function testNoReportIdFetchesNoReportAndAShortBsnIsHidden(): void {
		$signing = $this->createMock(SigningAdapterInterface::class);
		$signing->expects($this->never())->method('fetchValidationReport');

		$entries = $this->entries($this->builder($signing)->build('dec-1', ['addressee' => ['bsn' => '123']]));

		$this->assertSame([], json_decode($entries['validatierapport.json'], true));
		$this->assertSame(['addressee' => ['bsn' => '***']], json_decode($entries['beschikking.json'], true));
	}//end testNoReportIdFetchesNoReportAndAShortBsnIsHidden()

	/**
	 * A log that cannot be read, or is not configured, is an empty log.
	 *
	 * @return void
	 */
	public function testAnUnreadableLogIsAnEmptyLog(): void {
		$signing = $this->createMock(SigningAdapterInterface::class);
		$this->register->missingSchemas = ['stateMachineLog'];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('BeschikkingService: findStateMachineLogs failed');

		$entries = $this->entries($this->builder($signing, 'stateMachineLog', $logger)->build('dec-1', []));
		$this->assertSame([], json_decode($entries['state-machine-log.json'], true));

		$entries = $this->entries($this->builder($signing, '')->build('dec-1', []));
		$this->assertSame([], json_decode($entries['state-machine-log.json'], true));
	}//end testAnUnreadableLogIsAnEmptyLog()
}//end class
