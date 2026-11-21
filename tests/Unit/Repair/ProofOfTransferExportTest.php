<?php

/**
 * Unit tests for ProofOfTransferExport.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\ProofOfTransferExport;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every proof record with a case becomes a case document; nothing is written without a store or both schemas.
 *
 * @covers \OCA\Dossiq\Repair\ProofOfTransferExport
 * @uses   \OCA\Dossiq\Repair\ProofDocumentTarget
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class ProofOfTransferExportTest extends TestCase {

	/**
	 * What was saved, in order.
	 *
	 * @var list<array{register: string, schema: string, object: array<string, mixed>}>
	 */
	public array $saved = [];

	/**
	 * What was logged, in order.
	 *
	 * @var list<string>
	 */
	public array $logged = [];

	/**
	 * The export over a store and a config.
	 *
	 * @param object|null           $store  The OpenRegister store.
	 * @param array<string, string> $config The dossiq config values.
	 *
	 * @return ProofOfTransferExport The export.
	 */
	private function export(?object $store, array $config): ProofOfTransferExport {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = $level . ' ' . $message . ' ' . json_encode($context);
				}
			);
		}

		return new ProofOfTransferExport(settings: $settings, logger: $logger);
	}//end export()

	/**
	 * A store answering the proof schema with rows, and recording saves.
	 *
	 * @param list<array<string, mixed>> $rows      The proof rows.
	 * @param list<string>               $failSaves Case ids whose save fails.
	 *
	 * @return object The store.
	 */
	private function store(array $rows, array $failSaves = []): object {
		return new class($this, $rows, $failSaves) {
			/**
			 * The store.
			 *
			 * @param ProofOfTransferExportTest  $test      The test, recording saves.
			 * @param list<array<string, mixed>> $rows      The proof rows.
			 * @param list<string>               $failSaves Case ids whose save fails.
			 */
			public function __construct(
				private readonly ProofOfTransferExportTest $test,
				private readonly array $rows,
				private readonly array $failSaves,
			) {
			}//end __construct()

			/**
			 * The proof rows.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjects(array $query): array {
				return $this->rows;
			}//end searchObjects()

			/**
			 * Record a save.
			 *
			 * @param array<string, mixed> $object  The object.
			 * @param ObjectSaveOptions    $options Where it goes.
			 *
			 * @return array<string, mixed> The object.
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions()): array {
				if (in_array($object['case'], $this->failSaves, true) === true) {
					throw new RuntimeException('disk full');
				}

				$this->test->saved[] = [
					'register' => (string)$options->target->register,
					'schema' => (string)$options->target->schema,
					'object' => $object,
				];
				return $object;
			}//end saveObject()
		};
	}//end store()

	/**
	 * Each proof with a case is written once, as the case document the migration names; one without a case is skipped.
	 *
	 * @return void
	 */
	public function testEveryProofWithACaseBecomesACaseDocument(): void {
		$proof = ['caseId' => 'case-a', 'archivId' => 'arch-1'];
		$count = $this->export(
			store: $this->store(rows: [$proof, ['caseId' => '', 'archivId' => 'orphan'], ['caseId' => 'case-b']], failSaves: ['case-b']),
			config: ['archief_bewijs_schema' => '31', 'case_document_schema' => '32'],
		)->export(register: '5');

		$this->assertSame(expected: 1, actual: $count);
		$this->assertSame(
			expected: [
				[
					'register' => '5',
					'schema' => '32',
					'object' => [
						'case' => 'case-a',
						'title' => 'Bewijs van overbrenging (e-Depot)',
						'description' => 'Gemigreerd proof-of-transfer; OpenRegister beheert voortaan overbrenging en bewijs.',
						'document' => 'arch-1',
						'source' => 'archief-migration',
						'proofOfTransfer' => $proof,
					],
				],
			],
			actual: $this->saved
		);
		$this->assertSame(
			expected: ['error Archival migration: proof export failed — source record preserved in place {"caseId":"case-b","error":"disk full"}'],
			actual: $this->logged
		);
	}//end testEveryProofWithACaseBecomesACaseDocument()

	/**
	 * Without a store, or without either schema, nothing is read or written.
	 *
	 * @return void
	 */
	public function testNothingIsWrittenWithoutAStoreOrBothSchemas(): void {
		$rows = [['caseId' => 'case-a']];

		$this->assertSame(expected: 0, actual: $this->export(store: null, config: ['archief_bewijs_schema' => '31', 'case_document_schema' => '32'])->export(register: '5'));
		$this->assertSame(expected: 0, actual: $this->export(store: $this->store(rows: $rows), config: ['case_document_schema' => '32'])->export(register: '5'));
		$this->assertSame(expected: 0, actual: $this->export(store: $this->store(rows: $rows), config: ['archief_bewijs_schema' => '31'])->export(register: '5'));
		$this->assertSame(expected: [], actual: $this->saved);
	}//end testNothingIsWrittenWithoutAStoreOrBothSchemas()

}//end class
