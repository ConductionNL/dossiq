<?php

/**
 * Unit tests for CaseResultWriter's lookups and its refusals when storage is
 * missing: whether a status is final, the result choices a case type offers,
 * the archival future it hands to the deriver, and the result name written.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Archival\ArchivalNominationDeriver;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\CaseResultWriter
 * @uses   \OCA\Dossiq\Exception\RefusedException
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses   \OCA\Dossiq\Service\Support\SerialisedResult
 */
class CaseResultWriterLookupsTest extends TestCase {

	/** @var array<string, string> */
	private array $config = [];

	private bool $storage = true;

	/** The fake object service: rows by schema and id, and what was saved. */
	private object $objects;

	private CaseTypeResolver&MockObject $resolver;

	private ArchivalNominationDeriver&MockObject $deriver;

	protected function setUp(): void {
		parent::setUp();
		$this->config = [
			'register' => '5',
			'status_type_schema' => '12',
			'result_type_schema' => '13',
			'result_schema' => '14',
		];
		$this->storage = true;
		$this->objects = new class {
			/** @var array<string, mixed> */
			public array $rows = [];
			/** @var array<int, array<int, mixed>> */
			public array $saved = [];
			public mixed $saveResult = null;

			public function find(string $id, string $register, string $schema): mixed {
				$row = ($this->rows[$schema . ':' . $id] ?? null);
				if ($row === 'throw') {
					throw new RuntimeException('read failed');
				}
				return $row;
			}

			public function saveObject(array $object, string $register, string $schema): mixed {
				$this->saved[] = [$object, $register, $schema];
				return $this->saveResult;
			}
		};
		$this->resolver = $this->createMock(CaseTypeResolver::class);
		$this->deriver = $this->createMock(ArchivalNominationDeriver::class);
	}

	private function writer(): CaseResultWriter {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturnCallback(fn (): ?object => ($this->storage === true ? $this->objects : null));
		$settings->method('getConfigValue')->willReturnCallback(fn (string $key): string => ($this->config[$key] ?? ''));
		return new CaseResultWriter(settingsService: $settings, caseTypeResolver: $this->resolver, archivalDeriver: $this->deriver);
	}

	public function testIsFinalStatusReadsTheStatusTypeFlag(): void {
		$this->objects->rows['12:st-end'] = ['isFinal' => '1'];
		$this->objects->rows['12:st-mid'] = ['isFinal' => 'no'];
		$this->objects->rows['12:st-broken'] = 'throw';
		$writer = $this->writer();

		$this->assertTrue($writer->isFinalStatus('st-end'));
		$this->assertFalse($writer->isFinalStatus('st-mid'));
		$this->assertFalse($writer->isFinalStatus('st-broken'), 'A failing read is not final.');
		$this->assertFalse($writer->isFinalStatus(''));
	}

	public function testIsFinalStatusIsFalseWithoutStorageOrSchema(): void {
		$this->objects->rows['12:st-end'] = ['isFinal' => true];

		$this->config['status_type_schema'] = '';
		$this->assertFalse($this->writer()->isFinalStatus('st-end'));

		$this->config['status_type_schema'] = '12';
		$this->storage = false;
		$this->assertFalse($this->writer()->isFinalStatus('st-end'));
	}

	public function testResultTypeChoicesNameEachResultAndSkipThoseWithoutAnId(): void {
		$this->resolver->method('resultTypesFor')->with(caseTypeId: 'ct-1')->willReturn([
			['id' => 'rt-1', 'name' => 'Granted'],
			['@self' => ['id' => 'rt-2'], 'title' => ['nl' => 'Afgewezen']],
			['id' => 'rt-3'],
			['name' => 'No id'],
		]);

		$this->assertSame(
			[
				['id' => 'rt-1', 'name' => 'Granted'],
				['id' => 'rt-2', 'name' => 'Afgewezen'],
				['id' => 'rt-3', 'name' => 'rt-3'],
			],
			$this->writer()->resultTypeChoices('ct-1')
		);
	}

	public function testArchivalFutureIsTheDeriversAnswer(): void {
		$this->deriver->expects($this->once())->method('derive')
			->with(case: ['id' => 'case-1'], resultTypeId: 'rt-1', endDate: '2026-11-13')
			->willReturn(['archiveNomination' => 'destroy', 'archiveActionDate' => '2036-11-13']);

		$this->assertSame(
			['archiveNomination' => 'destroy', 'archiveActionDate' => '2036-11-13'],
			$this->writer()->archivalFuture(['id' => 'case-1'], 'rt-1', '2026-11-13')
		);
	}

	public function testTheWrittenResultCarriesTheResultTypeName(): void {
		$this->resolver->method('resultTypesFor')->willReturn([['id' => 'rt-1']]);
		$this->objects->rows['13:rt-1'] = ['name' => 'Granted'];
		$this->objects->saveResult = ['@self' => ['id' => 'res-9']];

		$this->assertSame('res-9', $this->writer()->resolveClosingResult('case-1', 'ct-1', 'rt-1'));
		$this->assertSame([[['case' => 'case-1', 'resultType' => 'rt-1', 'name' => 'Granted'], '5', '14']], $this->objects->saved);
	}

	public function testAWriteWithoutAnIdIsAnError(): void {
		$this->resolver->method('resultTypesFor')->willReturn([['id' => 'rt-1']]);
		$this->objects->saveResult = ['name' => 'no id'];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('result_not_written');

		$this->writer()->resolveClosingResult('case-1', 'ct-1', 'rt-1');
	}

	public function testAWriteWithoutTheResultSchemaIsAnError(): void {
		$this->resolver->method('resultTypesFor')->willReturn([['id' => 'rt-1']]);
		$this->config['result_schema'] = '';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('result_schema_not_configured');

		$this->writer()->resolveClosingResult('case-1', 'ct-1', 'rt-1');
	}

	public function testAMissingResultIsRefusedWhenTheCaseTypeHasResults(): void {
		$this->resolver->method('resultTypesFor')->willReturn([['id' => 'rt-1']]);

		try {
			$this->writer()->resolveClosingResult('case-1', 'ct-1', null);
			$this->fail('Expected a refusal.');
		} catch (RefusedException $e) {
			$this->assertSame('result-type-required', $e->getRule());
			$this->assertSame('Pick a result before closing this case.', $e->getSentence());
			$this->assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
		}
		$this->assertSame([], $this->objects->saved);
	}
}
