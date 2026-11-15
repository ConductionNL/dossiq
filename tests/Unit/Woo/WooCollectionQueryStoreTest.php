<?php

/**
 * Woo Collection Query Store Test
 *
 * What a stored Woo search keeps of its filters, and the refusal when the
 * query schema is not configured.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-selecting-query-is-saved-and-can-be-re-run-req-wrc-004
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\MakesCaseReviewerAccess;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCA\Dossiq\Woo\WooCollectionQueries;
use OCA\Dossiq\Woo\WooSources;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Woo\WooCollectionQueries
 * @uses   \OCA\Dossiq\Woo\WooCaseDocuments
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 * @uses   \OCA\Dossiq\Service\Access\CaseRecordLookup
 * @uses   \OCA\Dossiq\Service\Access\CaseReviewerAccess
 * @uses   \OCA\Dossiq\Service\Access\CaseRecordReaders
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 */
class WooCollectionQueryStoreTest extends TestCase {

	use MakesCaseReviewerAccess;

	private const CASE_ID = '11111111-1111-4111-8111-111111111111';

	private InMemoryRegister $register;

	/**
	 * The service over the register.
	 *
	 * @param string $querySchema The configured query schema.
	 *
	 * @return WooCollectionQueries The service.
	 */
	private function queries(string $querySchema = 'wooCollectionQuery'): WooCollectionQueries {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'woo_collection_query_schema' => $querySchema,
				'dossier_informatieobject_schema' => 'informatieobject',
				'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
			][$key] ?? $default
		);
		$root = $this->createMock(IRootFolder::class);

		return new WooCollectionQueries(
			settingsService: $settings,
			rootFolder: $root,
			sources: $this->createMock(WooSources::class),
			caseDocuments: new WooCaseDocuments(settingsService: $settings, rootFolder: $root, logger: $this->createMock(LoggerInterface::class)),
			access: $this->caseReviewerAccessOver($settings),
		);
	}//end queries()

	protected function setUp(): void {
		$this->register = new InMemoryRegister();
	}//end setUp()

	public function testFiltersAreKeptAndAMalformedPeriodIsDropped(): void {
		$stored = $this->queries()->store(
			caseId: self::CASE_ID,
			search: ['source' => 'cases', 'terms' => '  bestemmingsplan ', 'filters' => ['zaaktype' => 'omgeving'], 'periodFrom' => '01-01-2025', 'periodTo' => '2025-06-30'],
			userId: 'pjansen',
		);

		self::assertSame(['zaaktype' => 'omgeving'], $stored['filters']);
		self::assertSame('bestemmingsplan', $stored['terms']);
		self::assertSame('2025-06-30', $stored['periodTo']);
		self::assertArrayNotHasKey('periodFrom', $stored);
		self::assertSame(self::CASE_ID, $stored['case']);
	}//end testFiltersAreKeptAndAMalformedPeriodIsDropped()

	public function testEmptyFiltersAreNotWritten(): void {
		$stored = $this->queries()->store(caseId: self::CASE_ID, search: ['source' => 'files', 'filters' => []], userId: 'pjansen');

		self::assertArrayNotHasKey('filters', $stored);
		self::assertSame([], $stored['resultKeys']);
	}//end testEmptyFiltersAreNotWritten()

	public function testAQueryIsRefusedWithoutTheQuerySchema(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The Woo collection query schema is not configured');

		$this->queries(querySchema: '')->store(caseId: self::CASE_ID, search: ['source' => 'files'], userId: 'pjansen');
	}//end testAQueryIsRefusedWithoutTheQuerySchema()
}//end class
