<?php

/**
 * Unit tests for how PublicationSources loads what a Woo publication delivers:
 * through the case's own document upload when one is wired, else from the
 * document schema, nothing without an assessment or document schema, and a
 * missing decision refused.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use OCA\Dossiq\Service\Publication\PublicationSources;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Publication\PublicationSources
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class PublicationSourcesLoadingTest extends TestCase {

	private InMemoryRegister $store;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'document', uuid: 'doc-1', row: ['title' => 'Besluit', 'fileName' => 'besluit.pdf', 'content' => 'eA==']);
		$this->store->seed(schema: 'assessment', uuid: 'as-1', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-1', 'classification' => 'openbaar']);
		$this->store->seed(schema: 'assessment', uuid: 'as-9', row: ['caseRef' => 'case-9', 'documentRef' => 'doc-1', 'classification' => 'openbaar']);
	}//end setUp()

	/**
	 * Sources over the given config.
	 *
	 * @param array<string, string> $config Config values by key.
	 */
	private function sources(array $config, ?WooCaseDocuments $documents = null): PublicationSources {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => $config[$key] ?? $default);
		return new PublicationSources($settings, $documents);
	}//end sources()

	public function testTheCasesOwnUploadIsReadWhenWired(): void {
		$documents = $this->createMock(WooCaseDocuments::class);
		$documents->expects($this->once())->method('load')->with('doc-1')->willReturn(['fileName' => 'upload.pdf', 'content' => 'eQ==']);

		$delivery = $this->sources(['woo_assessment_schema' => 'assessment'], $documents)->delivery($this->store, 'dossiq', 'case-1');

		$this->assertSame([['fileName' => 'upload.pdf', 'content' => 'eQ==']], $delivery['documents']);
		$this->assertSame('upload.pdf', $delivery['items'][0]['fileName']);
		$this->assertCount(1, $delivery['assessments']);
	}//end testTheCasesOwnUploadIsReadWhenWired()

	public function testWithoutADocumentSchemaNothingIsLoaded(): void {
		$delivery = $this->sources(['woo_assessment_schema' => 'assessment'])->delivery($this->store, 'dossiq', 'case-1');

		$this->assertSame([], $delivery['documents']);
		$this->assertSame([], $delivery['items']);
		$this->assertCount(1, $delivery['assessments']);
	}//end testWithoutADocumentSchemaNothingIsLoaded()

	public function testWithoutAnAssessmentSchemaNothingIsSearchedOrDelivered(): void {
		$store = new class {
			public int $searches = 0;

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->searches++;
				return [['caseRef' => 'case-1', 'documentRef' => 'doc-1', 'classification' => 'openbaar']];
			}
		};

		$this->assertSame(
			['documents' => [], 'items' => [], 'assessments' => []],
			$this->sources(['document_schema' => 'document'])->delivery($store, 'dossiq', 'case-1')
		);
		$this->assertSame(0, $store->searches);
	}//end testWithoutAnAssessmentSchemaNothingIsSearchedOrDelivered()

	public function testTheDocumentTitleStandsInForAMissingFileName(): void {
		$this->store->seed(schema: 'document', uuid: 'doc-2', row: ['title' => 'Bijlage']);
		$this->store->seed(schema: 'assessment', uuid: 'as-2', row: ['caseRef' => 'case-2', 'documentRef' => 'doc-2', 'classification' => 'openbaar']);

		$delivery = $this->sources(['woo_assessment_schema' => 'assessment', 'document_schema' => 'document'])->delivery($this->store, 'dossiq', 'case-2');

		$this->assertSame(['assessment' => 'as-2', 'classification' => 'openbaar', 'deliveredRef' => 'doc-2', 'originalRef' => 'doc-2', 'fileName' => 'Bijlage', 'content' => ''], $delivery['items'][0]);
	}//end testTheDocumentTitleStandsInForAMissingFileName()

	public function testAMissingDecisionIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Decision not found: dec-404');

		$this->sources([])->decision($this->store, 'dossiq', 'decision', 'dec-404');
	}//end testAMissingDecisionIsRefused()
}//end class
