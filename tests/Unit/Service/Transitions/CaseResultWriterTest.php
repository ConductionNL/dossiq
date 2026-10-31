<?php

/**
 * The result a closing case is written with must be one of its case type's.
 *
 * A free text "Deels toegewezen" posted as the result reached OpenRegister,
 * failed the result schema's uuid format, and the handler read a 500. The
 * writer now refuses a result that is not on the case type's list before it
 * writes anything.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md#requirement-finishing-or-aborting-a-case-picks-its-result-from-the-case-type-req-cm-80
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

/**
 * Resolving the closing result against the case type's own results.
 *
 * @covers \OCA\Dossiq\Service\Transitions\CaseResultWriter
 *
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 */
class CaseResultWriterTest extends TestCase {

	/**
	 * The object service double; records what was saved.
	 *
	 * @var ResultWriterObjectServiceDouble
	 */
	private ResultWriterObjectServiceDouble $objects;

	/**
	 * Build the writer over a case type offering the given result rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows the resolver answers.
	 *
	 * @return CaseResultWriter The writer.
	 */
	private function writer(array $rows): CaseResultWriter {
		/** @var SettingsService&MockObject $settings */
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => $key
		);

		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('resultTypesFor')->willReturn($rows);

		return new CaseResultWriter(
			settingsService: $settings,
			caseTypeResolver: $resolver,
			archivalDeriver: $this->createMock(originalClassName: ArchivalNominationDeriver::class),
		);
	}//end writer()

	/**
	 * A fresh object service per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new ResultWriterObjectServiceDouble();
	}//end setUp()

	/**
	 * A typed name is not one of the case type's results: refused with 422,
	 * and nothing is written.
	 *
	 * @return void
	 */
	public function testAResultNotOnTheCaseTypeIsRefusedBeforeAnythingIsWritten(): void {
		$writer = $this->writer(rows: [['id' => 'rt-1', 'name' => 'Deels toegewezen']]);

		try {
			$writer->resolveClosingResult(caseId: 'case-1', caseTypeId: 'ct-1', resultTypeId: 'Deels toegewezen');
			self::fail('An unknown result was accepted.');
		} catch (RefusedException $e) {
			self::assertSame('result-type-unknown', $e->getRule());
			self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
		}

		self::assertSame([], $this->objects->saved);
	}//end testAResultNotOnTheCaseTypeIsRefusedBeforeAnythingIsWritten()

	/**
	 * Control: a result on the case type's list, a parent's included, is written.
	 *
	 * @return void
	 */
	public function testAResultOnTheCaseTypeIsWritten(): void {
		$writer = $this->writer(
			rows: [
				['id' => 'rt-own', 'name' => 'Toegewezen'],
				['@self' => ['id' => 'rt-parent'], 'name' => 'Deels toegewezen', 'origin' => 'inherited'],
			]
		);

		$resultId = $writer->resolveClosingResult(caseId: 'case-1', caseTypeId: 'ct-1', resultTypeId: 'rt-parent');

		self::assertSame('result-1', $resultId);
		self::assertSame('rt-parent', $this->objects->saved[0]['resultType']);
	}//end testAResultOnTheCaseTypeIsWritten()

	/**
	 * A case type without results asks for none, and names none: a result
	 * given anyway is refused rather than written against nothing.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutResultsTakesNone(): void {
		$writer = $this->writer(rows: []);

		self::assertNull($writer->resolveClosingResult(caseId: 'case-1', caseTypeId: 'ct-1', resultTypeId: ''));

		$this->expectException(RefusedException::class);
		$writer->resolveClosingResult(caseId: 'case-1', caseTypeId: 'ct-1', resultTypeId: 'rt-x');
	}//end testACaseTypeWithoutResultsTakesNone()
}//end class

/**
 * The two ObjectService calls the writer makes, recorded.
 */
class ResultWriterObjectServiceDouble {

	/**
	 * Every object saved, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * Record a save and answer it with an id.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param string $register The register.
	 * @param string $schema The schema.
	 *
	 * @return array<string, mixed> The saved object.
	 */
	public function saveObject(array $object, string $register, string $schema): array {
		$this->saved[] = $object;

		return array_merge($object, ['id' => 'result-' . count($this->saved)]);
	}//end saveObject()

	/**
	 * Answer a read with nothing.
	 *
	 * @param string $id The id.
	 * @param string $register The register.
	 * @param string $schema The schema.
	 *
	 * @return array<string, mixed> An empty row.
	 */
	public function find(string $id, string $register, string $schema): array {
		return [];
	}//end find()
}//end class
