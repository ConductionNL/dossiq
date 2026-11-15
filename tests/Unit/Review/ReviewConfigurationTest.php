<?php

/**
 * Unit tests for the review configuration.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Review;

use OCA\Dossiq\Review\ReviewConfiguration;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;

/**
 * The case type's `documentReview` block, read for a case.
 *
 * @covers \OCA\Dossiq\Review\ReviewConfiguration
 * @uses \OCA\Dossiq\Review\ReviewDepth
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-review-depth-is-set-per-document-type-on-the-case-type-and-recorded-req-wrt-004
 */
class ReviewConfigurationTest extends TestCase {

	/**
	 * A case whose type configures a depth, one whose type says nothing, one expanded reference, and an unknown case.
	 *
	 * @return void
	 */
	public function testTheCaseTypeConfiguresTheReview(): void {
		$store = new InMemoryRegister();
		$store->seed(schema: 'caseType', uuid: 'ct-1', row: ['documentReview' => ['reviewDepth' => ['export' => ['mode' => 'sample', 'sampleSize' => 5]]]]);
		$store->seed(schema: 'caseType', uuid: 'ct-2', row: ['title' => 'Bezwaar']);
		$store->seed(schema: 'case', uuid: 'case-a', row: ['caseType' => 'ct-1']);
		$store->seed(schema: 'case', uuid: 'case-b', row: ['caseType' => 'ct-2']);
		$store->seed(schema: 'case', uuid: 'case-c', row: ['caseType' => ['id' => 'ct-1']]);
		$config = ['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$configuration = new ReviewConfiguration(settingsService: $settings);

		$this->assertSame(['export' => ['mode' => 'sample', 'sampleSize' => 5]], $configuration->reviewDepth(caseId: 'case-a'));
		$this->assertSame([], $configuration->reviewDepth(caseId: 'case-b'));
		$this->assertSame([], $configuration->forCase(caseId: 'case-b'));
		$this->assertSame(['export' => ['mode' => 'sample', 'sampleSize' => 5]], $configuration->reviewDepth(caseId: 'case-c'));
		$this->assertSame([], $configuration->forCase(caseId: 'case-unknown'));
	}//end testTheCaseTypeConfiguresTheReview()

	/**
	 * A review block or a depth that is not a map configures nothing.
	 *
	 * @return void
	 */
	public function testAMalformedBlockConfiguresNothing(): void {
		$store = new InMemoryRegister();
		$store->seed(schema: 'caseType', uuid: 'ct-text', row: ['documentReview' => 'yes']);
		$store->seed(schema: 'caseType', uuid: 'ct-depth', row: ['documentReview' => ['reviewDepth' => 'everything', 'batchSize' => 25]]);
		$store->seed(schema: 'case', uuid: 'case-text', row: ['caseType' => 'ct-text']);
		$store->seed(schema: 'case', uuid: 'case-depth', row: ['caseType' => ['uuid' => 'ct-depth']]);
		$config = ['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$configuration = new ReviewConfiguration(settingsService: $settings);

		$this->assertSame([], $configuration->forCase(caseId: 'case-text'));
		$this->assertSame([], $configuration->reviewDepth(caseId: 'case-text'));
		$this->assertSame(['reviewDepth' => 'everything', 'batchSize' => 25], $configuration->forCase(caseId: 'case-depth'));
		$this->assertSame([], $configuration->reviewDepth(caseId: 'case-depth'));
	}//end testAMalformedBlockConfiguresNothing()

	/**
	 * No object service, an unconfigured register or schema, or an empty id reads nothing.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredStoreReadsNothing(): void {
		$store = new InMemoryRegister();
		$store->seed(schema: 'caseType', uuid: 'ct-1', row: ['documentReview' => ['batchSize' => 10]]);
		$store->seed(schema: 'case', uuid: 'case-a', row: ['caseType' => 'ct-1']);
		$full = ['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType'];

		$this->assertSame(expected: ['batchSize' => 10], actual: $this->configured(store: $store, config: $full)->forCase(caseId: 'case-a'));
		$this->assertSame(expected: [], actual: $this->configured(store: null, config: $full)->forCase(caseId: 'case-a'));
		$this->assertSame(expected: [], actual: $this->configured(store: $store, config: ['register' => ''] + $full)->forCase(caseId: 'case-a'));
		$this->assertSame(expected: [], actual: $this->configured(store: $store, config: ['case_schema' => ''] + $full)->forCase(caseId: 'case-a'));
		$this->assertSame(expected: [], actual: $this->configured(store: $store, config: ['case_type_schema' => ''] + $full)->forCase(caseId: 'case-a'));
		$this->assertSame(expected: [], actual: $this->configured(store: $store, config: $full)->forCase(caseId: ''));
	}//end testAnUnconfiguredStoreReadsNothing()

	/**
	 * A document's depth follows its type, and an every-page depth requires every page.
	 *
	 * @return void
	 */
	public function testTheDepthOfADocumentFollowsItsType(): void {
		$configuration = $this->configured(store: null, config: []);
		$depth = ['pdf' => ['mode' => 'sample', 'sampleSize' => 2]];

		$this->assertSame(
			expected: ['depth' => ['mode' => 'every-page'], 'pagesRequired' => [1, 2, 3]],
			actual: $configuration->depthOfDocument(reviewDepth: $depth, document: ['mimeType' => 'text/plain'], pageCount: 3)
		);
		$this->assertSame(
			expected: ['depth' => ['mode' => 'every-page'], 'pagesRequired' => [1]],
			actual: $configuration->depthOfDocument(reviewDepth: $depth, document: ['mimeType' => 'text/plain'], pageCount: null)
		);

		$sampled = $configuration->depthOfDocument(reviewDepth: $depth, document: ['mimeType' => 'application/pdf'], pageCount: 10);
		$this->assertSame(expected: 'sample', actual: $sampled['depth']['mode']);
		$this->assertSame(expected: 2, actual: $sampled['depth']['sampleSize']);
		$this->assertCount(expectedCount: 2, haystack: $sampled['pagesRequired']);
		$this->assertSame(expected: [], actual: array_diff($sampled['pagesRequired'], range(start: 1, end: 10)));
	}//end testTheDepthOfADocumentFollowsItsType()

	/**
	 * A configuration reading the given store with the given app config.
	 *
	 * @param InMemoryRegister|null $store  The object service, or none.
	 * @param array<string, string> $config The app config values.
	 *
	 * @return ReviewConfiguration The subject.
	 */
	private function configured(?InMemoryRegister $store, array $config): ReviewConfiguration {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(value: $store);
		$settings->method('getConfigValue')->willReturnCallback(
			callback: static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new ReviewConfiguration(settingsService: $settings);
	}//end configured()
}//end class
