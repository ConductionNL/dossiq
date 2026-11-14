<?php

/**
 * The cases a document belongs to and the documents of a case, read unscoped.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 * @spec openspec/specs/document-writes-need-every-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DocumentCaseIds;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\DocumentCaseIds
 * @uses   \OCA\Dossiq\Service\Custody\DossierRegister
 */
class DocumentCaseIdsTest extends TestCase {

	/**
	 * An object service answering $rows and recording each search.
	 *
	 * @param array<int, mixed> $rows The rows.
	 *
	 * @return object The service; `calls` holds [query, scope] per search.
	 */
	private static function store(array $rows): object {
		return new class ($rows) {
			/**
			 * The searches received.
			 *
			 * @var array<int, array{0: array<string, mixed>, 1: array<string, mixed>}>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, mixed> $rows The rows.
			 */
			public function __construct(private array $rows) {
			}//end __construct()

			/**
			 * Record the search and answer the rows.
			 *
			 * @param array<string, mixed> $query The query.
			 * @param bool                 $_rbac Whether RBAC applies.
			 * @param bool                 $_multitenancy Whether multitenancy applies.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = [$query, ['_rbac' => $_rbac, '_multitenancy' => $_multitenancy]];

				return $this->rows;
			}//end searchObjects()
		};
	}//end store()

	/**
	 * The reader over $store with register 5 and link schema 7, or as configured.
	 *
	 * @param object|null $store  The object service.
	 * @param string      $schema The link schema setting.
	 *
	 * @return DocumentCaseIds
	 */
	private function reader(?object $store, string $schema = '7'): DocumentCaseIds {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', '5'],
				['dossier_zaakinformatieobject_schema', '', $schema],
			]
		);

		return new DocumentCaseIds($settings);
	}//end reader()

	/**
	 * A document's cases are read unscoped from its links, deduplicated, ids only.
	 *
	 * @return void
	 */
	public function testTheCasesOfADocumentAreReadUnscopedAndDeduplicated(): void {
		$store = self::store(
			[
				['case' => 'case-a'],
				['case' => ['id' => 'case-b']],
				['case' => 'case-a'],
				['case' => ''],
				['case' => ['title' => 'no id']],
				['informatieobject' => 'doc-1'],
			]
		);

		self::assertSame(['case-a', 'case-b'], $this->reader($store)->caseIdsOf('doc-1'));
		self::assertSame(
			[
				[
					['informatieobject' => 'doc-1', '_limit' => 500, '@self' => ['register' => 5, 'schema' => 7]],
					['_rbac' => false, '_multitenancy' => false],
				],
			],
			$store->calls
		);
	}//end testTheCasesOfADocumentAreReadUnscopedAndDeduplicated()

	/**
	 * A case's documents are read unscoped from its links, in order, deduplicated.
	 *
	 * @return void
	 */
	public function testTheDocumentsOfACaseAreReadUnscopedInOrder(): void {
		$store = self::store(
			[
				['informatieobject' => ['id' => 'doc-2']],
				['informatieobject' => 'doc-1'],
				['informatieobject' => 'doc-2'],
				['informatieobject' => ''],
				['case' => 'case-1'],
			]
		);

		self::assertSame(['doc-2', 'doc-1'], $this->reader($store)->documentIdsOf('case-1'));
		self::assertSame(
			[
				[
					['case' => 'case-1', '_limit' => 500, '@self' => ['register' => 5, 'schema' => 7]],
					['_rbac' => false, '_multitenancy' => false],
				],
			],
			$store->calls
		);
	}//end testTheDocumentsOfACaseAreReadUnscopedInOrder()

	/**
	 * Without OpenRegister nothing is read and the caller is told.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterBothReadsAreRefused(): void {
		foreach (['caseIdsOf', 'documentIdsOf'] as $method) {
			try {
				$this->reader(null)->$method('x');
				self::fail($method . ' should refuse');
			} catch (RuntimeException $e) {
				self::assertSame('OpenRegister is not available', $e->getMessage());
			}
		}
	}//end testWithoutOpenRegisterBothReadsAreRefused()

	/**
	 * Without the link schema nothing is searched and the missing key is named.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredLinkSchemaIsRefused(): void {
		$store = self::store([['case' => 'case-a']]);
		foreach (['caseIdsOf', 'documentIdsOf'] as $method) {
			try {
				$this->reader($store, '')->$method('x');
				self::fail($method . ' should refuse');
			} catch (RuntimeException $e) {
				self::assertSame('Dossier schema dossier_zaakinformatieobject_schema not configured', $e->getMessage());
			}
		}

		self::assertSame([], $store->calls);
	}//end testAnUnconfiguredLinkSchemaIsRefused()
}//end class
