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
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-review-depth-is-set-per-document-type-and-recorded-req-wrt-004
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
}//end class
