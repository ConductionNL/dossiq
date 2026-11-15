<?php

/**
 * Unit tests for CaseTypeReader: the case type settings a transition reads,
 * the payment rule, whether a status is final, and whether a status type
 * belongs to a case type.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\CaseTypeReader
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses   \OCA\Dossiq\Service\Support\SerialisedResult
 */
class CaseTypeReaderTest extends TestCase {

	/** @var array<string, string> */
	private array $config = [];

	/** The fake object service: rows by schema and id, and the reads it saw. */
	private object $objects;

	private bool $storage = true;

	protected function setUp(): void {
		parent::setUp();
		$this->config = ['register' => '5', 'case_type_schema' => '11', 'status_type_schema' => '12'];
		$this->storage = true;
		$this->objects = new class {
			/** @var array<string, array<string, mixed>> */
			public array $rows = [];
			/** @var array<int, array<int, string>> */
			public array $reads = [];

			public function find(string $id, string $register, string $schema): mixed {
				$this->reads[] = [$id, $register, $schema];
				$row = ($this->rows[$schema . ':' . $id] ?? null);
				if ($row === 'throw') {
					throw new RuntimeException('read failed');
				}
				return $row;
			}
		};
	}

	private function reader(): CaseTypeReader {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturnCallback(fn (): ?object => ($this->storage === true ? $this->objects : null));
		$settings->method('getConfigValue')->willReturnCallback(fn (string $key): string => ($this->config[$key] ?? ''));
		return new CaseTypeReader($settings);
	}

	public function testReadProjectsTheCaseTypeSettings(): void {
		$this->objects->rows['11:ct-1'] = [
			'suspensionAllowed' => 'true',
			'extensionAllowed' => 1,
			'extensionPeriod' => 'P14D',
			'maxExtensions' => '2',
			'initialStatus' => 'st-1',
			'title' => ['en' => 'Permit', 'nl' => 'Vergunning'],
			'entryRequirements' => ['st-2' => ['documents' => ['id']]],
		];

		$this->assertSame(
			[
				'suspensionAllowed' => true,
				'extensionAllowed' => true,
				'extensionPeriod' => 'P14D',
				'maxExtensions' => 2,
				'initialStatus' => 'st-1',
				'title' => 'Vergunning',
				'entryRequirements' => ['st-2' => ['documents' => ['id']]],
			],
			$this->reader()->read('ct-1')
		);
		$this->assertSame([['ct-1', '5', '11']], $this->objects->reads);
	}

	public function testReadFallsBackToTheNameAndDefaultsForAMissingCaseType(): void {
		$this->objects->rows['11:ct-1'] = ['name' => '  Melding  ', 'suspensionAllowed' => 'yes'];

		$read = $this->reader()->read('ct-1');
		$this->assertSame('Melding', $read['title']);
		$this->assertFalse($read['suspensionAllowed'], 'Only true, 1, "1" and "true" are true.');

		$this->assertSame(
			[
				'suspensionAllowed' => false,
				'extensionAllowed' => false,
				'extensionPeriod' => '',
				'maxExtensions' => null,
				'initialStatus' => '',
				'title' => '',
				'entryRequirements' => [],
			],
			$this->reader()->read('ct-unknown')
		);
	}

	public function testNothingIsReadWithoutStorageConfigurationOrId(): void {
		$this->objects->rows['11:ct-1'] = ['suspensionAllowed' => true];

		$this->assertFalse($this->reader()->read('')['suspensionAllowed']);
		$this->config['case_type_schema'] = '';
		$this->assertFalse($this->reader()->read('ct-1')['suspensionAllowed']);
		$this->assertSame([], $this->objects->reads);

		$this->config['case_type_schema'] = '11';
		$this->storage = false;
		$this->assertFalse($this->reader()->read('ct-1')['suspensionAllowed']);
	}

	public function testAFailingReadIsAnEmptyRow(): void {
		$this->objects->rows['11:ct-1'] = 'throw';

		$this->assertSame('', $this->reader()->read('ct-1')['initialStatus']);
		$this->assertNull($this->reader()->paymentRule('ct-1'));
	}

	public function testPaymentRule(): void {
		$this->objects->rows['11:ct-1'] = ['paymentRequiredBeforeHandling' => '1'];
		$this->objects->rows['11:ct-2'] = ['title' => 'No payment'];
		$reader = $this->reader();

		$this->assertSame(['paymentRequiredBeforeHandling' => true], $reader->paymentRule('ct-1'));
		$this->assertSame(['paymentRequiredBeforeHandling' => false], $reader->paymentRule('ct-2'));
		$this->assertNull($reader->paymentRule('ct-unknown'));
		$this->assertNull($reader->paymentRule(''));
	}

	public function testIsFinalStatusReadsTheStatusTypeSchema(): void {
		$this->objects->rows['12:st-end'] = ['isFinal' => true];
		$this->objects->rows['12:st-mid'] = ['isFinal' => 'false'];
		$reader = $this->reader();

		$this->assertTrue($reader->isFinalStatus('st-end'));
		$this->assertFalse($reader->isFinalStatus('st-mid'));
		$this->assertFalse($reader->isFinalStatus('st-unknown'));
		$this->assertSame(['st-end', '5', '12'], $this->objects->reads[0]);
	}

	public function testStatusBelongsToTheCaseTypeItsRowNames(): void {
		$this->objects->rows['12:st-1'] = ['caseType' => 'ct-1'];
		$this->objects->rows['12:st-2'] = ['caseType' => ['id' => 'ct-1']];
		$this->objects->rows['12:st-3'] = ['caseType' => ['@self' => ['id' => 'ct-1']]];
		$this->objects->rows['12:st-4'] = ['caseType' => 'ct-2'];
		$reader = $this->reader();

		$this->assertTrue($reader->statusBelongsTo('st-1', 'ct-1'));
		$this->assertTrue($reader->statusBelongsTo('st-2', 'ct-1'));
		$this->assertTrue($reader->statusBelongsTo('st-3', 'ct-1'));
		$this->assertFalse($reader->statusBelongsTo('st-4', 'ct-1'));
		$this->assertFalse($reader->statusBelongsTo('st-unknown', 'ct-1'));
	}

	public function testStatusBelongsToIsFalseForAnEmptyIdWithoutReading(): void {
		$reader = $this->reader();

		$this->assertFalse($reader->statusBelongsTo('', 'ct-1'));
		$this->assertFalse($reader->statusBelongsTo('st-1', ''));
		$this->assertSame([], $this->objects->reads);
	}
}
