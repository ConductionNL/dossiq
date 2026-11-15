<?php

/**
 * A publication is made from its case, its decision and only the documents that may go out.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Publication
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use OCA\Dossiq\Service\Publication\PublicationSources;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Publication\PublicationSources
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class PublicationSourcesTest extends TestCase {

	/**
	 * The register the sources read.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The sources under test.
	 *
	 * @var PublicationSources
	 */
	private PublicationSources $sources;

	/**
	 * A case, its decision, three documents and their assessments.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'case', uuid: 'case-1', row: ['title' => 'Speeltuinen']);
		$this->store->seed(schema: 'decision', uuid: 'dec-1', row: ['case' => 'case-1']);
		foreach (['doc-1', 'doc-2', 'doc-2-red', 'doc-3'] as $doc) {
			$this->store->seed(schema: 'document', uuid: $doc, row: ['title' => $doc, 'fileName' => $doc . '.pdf', 'content' => 'eA==']);
		}

		$this->store->seed(schema: 'assessment', uuid: 'as-1', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-1', 'classification' => 'openbaar']);
		$this->store->seed(schema: 'assessment', uuid: 'as-2', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-2', 'classification' => 'deels_openbaar', 'redactedDocumentRef' => 'doc-2-red']);
		$this->store->seed(schema: 'assessment', uuid: 'as-3', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-3', 'classification' => 'niet_openbaar']);

		/** @var SettingsService&MockObject $settings */
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'case_schema' => 'case',
				'woo_assessment_schema' => 'assessment',
				'document_schema' => 'document',
			][$key] ?? $default
		);
		$this->sources = new PublicationSources(settingsService: $settings);
	}//end setUp()

	/**
	 * The case and the decision are loaded together; a missing one refuses.
	 *
	 * @return void
	 */
	public function testTheCaseAndTheDecisionAreLoaded(): void {
		[$case, $decision] = $this->sources->caseAndDecision(objectService: $this->store, register: 'dossiq', decisionSchema: 'decision', caseId: 'case-1', decisionId: 'dec-1');

		self::assertSame('Speeltuinen', $case['title']);
		self::assertSame('case-1', $decision['case']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Decision not found: dec-missing');
		$this->sources->decision(objectService: $this->store, register: 'dossiq', decisionSchema: 'decision', decisionId: 'dec-missing');
	}//end testTheCaseAndTheDecisionAreLoaded()

	/**
	 * A missing case refuses before the decision is read.
	 *
	 * @return void
	 */
	public function testAMissingCaseRefuses(): void {
		$this->expectExceptionMessage('Case not found: case-missing');
		$this->sources->caseAndDecision(objectService: $this->store, register: 'dossiq', decisionSchema: 'decision', caseId: 'case-missing', decisionId: 'dec-1');
	}//end testAMissingCaseRefuses()

	/**
	 * Only the disclosed document and the finalized redaction go out, each with its record.
	 *
	 * @return void
	 */
	public function testOnlyDisclosableDocumentsGoOut(): void {
		$delivery = $this->sources->delivery(objectService: $this->store, register: 'dossiq', caseId: 'case-1');

		self::assertSame(['doc-1', 'doc-2-red'], array_column($delivery['documents'], 'title'));
		self::assertSame('as-2', $delivery['items'][1]['assessment']);
		self::assertSame('doc-2', $delivery['items'][1]['originalRef']);
		self::assertSame('doc-2-red', $delivery['items'][1]['deliveredRef']);
	}//end testOnlyDisclosableDocumentsGoOut()

	/**
	 * A partly disclosed document without a finalized redaction never goes out.
	 *
	 * @return void
	 */
	public function testAnUnredactedOriginalNeverGoesOut(): void {
		$loaded = [];
		$delivery = $this->sources->collectDelivery(
			assessments: [['documentRef' => 'doc-2', 'classification' => 'deels_openbaar']],
			documentLoader: static function (string $ref) use (&$loaded): array {
				$loaded[] = $ref;
				return ['title' => $ref];
			}
		);

		self::assertSame(['documents' => [], 'items' => []], $delivery);
		self::assertSame([], $loaded);
	}//end testAnUnredactedOriginalNeverGoesOut()
}//end class
