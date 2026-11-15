<?php

/**
 * Copying a domain when part of it cannot be read or written.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Starter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Starter;

use OCA\Dossiq\Service\CaseTypeCopyService;
use OCA\Dossiq\Service\Starter\DomainCopy;
use OCA\Dossiq\Service\Starter\DomainCopyService;
use OCA\Dossiq\Service\Starter\StarterStore;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Tests\Support\StarterStoreHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for DomainCopyService's partial and refused copies.
 */
#[CoversClass(DomainCopyService::class)]
#[UsesClass(DomainCopy::class)]
#[UsesClass(StarterStore::class)]
#[UsesClass(LanguageMapText::class)]
final class DomainCopyEdgesTest extends TestCase {

	/**
	 * The store and its rows.
	 *
	 * @var StarterStoreHarness
	 */
	private StarterStoreHarness $harness;

	/**
	 * The case type copier.
	 *
	 * @var CaseTypeCopyService&MockObject
	 */
	private CaseTypeCopyService $copier;

	/**
	 * One domain with one case type.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->harness = new StarterStoreHarness(test: $this);
		$this->copier = $this->getMockBuilder(CaseTypeCopyService::class)
			->disableOriginalConstructor()
			->onlyMethods(['copy'])
			->getMock();

		$this->harness->seed(schema: 'caseTypeGroup', uuid: 'dom-1', row: ['id' => 'dom-1', 'groupName' => 'VTH']);
		$this->harness->seed(schema: 'caseType', uuid: 'ct-1', row: ['id' => 'ct-1', 'title' => 'Omgevingsvergunning', 'caseTypeGroup' => 'dom-1']);
	}//end setUp()

	/**
	 * The service over the harness.
	 *
	 * @return DomainCopyService
	 */
	private function service(): DomainCopyService {
		return new DomainCopyService($this->harness->store, $this->copier, new NullLogger());
	}//end service()

	/**
	 * The copier answers a copy of the case type under a new id.
	 *
	 * @return void
	 */
	private function copierCopies(): void {
		$this->copier->method('copy')->willReturnCallback(
			static fn (string $caseTypeId): array => ['id' => $caseTypeId . '-copy', 'title' => 'Copy']
		);
	}//end copierCopies()

	/**
	 * A domain that cannot be written is refused as write_failed, and nothing is copied.
	 *
	 * @return void
	 */
	public function testADomainThatCannotBeWrittenIsRefused(): void {
		$this->harness->register->refusedSchemas = ['caseTypeGroup'];
		$this->copier->expects($this->never())->method('copy');

		self::assertSame(
			expected: ['complete' => false, 'domain' => '', 'carried' => [], 'notCarried' => [], 'reason' => 'write_failed'],
			actual: $this->service()->copy(domainId: 'dom-1', name: 'VTH 2')
		);
	}//end testADomainThatCannotBeWrittenIsRefused()

	/**
	 * Case types that cannot be listed leave the copy incomplete, naming them, and carry no templates.
	 *
	 * @return void
	 */
	public function testUnlistableCaseTypesLeaveTheCopyIncomplete(): void {
		$this->harness->register->missingSchemas = ['caseType'];
		$this->copier->expects($this->never())->method('copy');

		$result = $this->service()->copy(domainId: 'dom-1', name: 'VTH 2');

		self::assertFalse(condition: $result['complete']);
		self::assertNotSame(expected: '', actual: $result['domain']);
		self::assertSame(expected: ['caseType' => 0, 'contentTemplate' => 0], actual: $result['carried']);
		self::assertSame(expected: ['caseType'], actual: $result['notCarried']);
	}//end testUnlistableCaseTypesLeaveTheCopyIncomplete()

	/**
	 * Templates that cannot be listed are named as not carried, while the case types are.
	 *
	 * @return void
	 */
	public function testUnlistableTemplatesAreNamedAsNotCarried(): void {
		$this->copierCopies();
		$this->harness->register->missingSchemas = ['contentTemplate'];

		$result = $this->service()->copy(domainId: 'dom-1', name: 'VTH 2');

		self::assertFalse(condition: $result['complete']);
		self::assertSame(expected: ['caseType' => 1, 'contentTemplate' => 0], actual: $result['carried']);
		self::assertSame(expected: ['contentTemplate'], actual: $result['notCarried']);
		self::assertSame(
			expected: $result['domain'],
			actual: $this->harness->register->row(schema: 'caseType', uuid: 'ct-1-copy')['caseTypeGroup']
		);
	}//end testUnlistableTemplatesAreNamedAsNotCarried()

	/**
	 * Only templates scoped to a copied case type are copied, repointed at the copy.
	 *
	 * @return void
	 */
	public function testOnlyTemplatesOnACopiedCaseTypeAreCopied(): void {
		$this->copierCopies();
		$this->harness->seed(schema: 'contentTemplate', uuid: 'tpl-other', row: ['id' => 'tpl-other', 'name' => 'Other', 'caseTypes' => ['ct-9']]);
		$this->harness->seed(schema: 'contentTemplate', uuid: 'tpl-odd', row: ['id' => 'tpl-odd', 'name' => 'Odd', 'caseTypes' => 'ct-1']);
		$this->harness->seed(schema: 'contentTemplate', uuid: 'tpl-on', row: ['id' => 'tpl-on', 'name' => 'On', 'caseTypes' => ['ct-1', 'ct-9']]);

		$result = $this->service()->copy(domainId: 'dom-1', name: 'VTH 2');

		self::assertTrue(condition: $result['complete']);
		self::assertSame(expected: ['caseType' => 1, 'contentTemplate' => 1], actual: $result['carried']);

		$copies = array_values(
			array_filter(
				$this->harness->register->all(schema: 'contentTemplate'),
				static fn (array $row): bool => ($row['name'] === 'On' && $row['id'] !== 'tpl-on')
			)
		);
		self::assertCount(expectedCount: 1, haystack: $copies);
		self::assertSame(expected: ['ct-1-copy'], actual: $copies[0]['caseTypes']);
	}//end testOnlyTemplatesOnACopiedCaseTypeAreCopied()

	/**
	 * A domain with no case types copies no templates at all.
	 *
	 * @return void
	 */
	public function testADomainWithNoCaseTypesCopiesNoTemplates(): void {
		$this->harness->seed(schema: 'caseTypeGroup', uuid: 'dom-empty', row: ['id' => 'dom-empty', 'groupName' => 'Leeg']);
		$this->harness->seed(schema: 'contentTemplate', uuid: 'tpl-all', row: ['id' => 'tpl-all', 'name' => 'All', 'caseTypes' => []]);
		$writesBefore = count($this->harness->register->all(schema: 'contentTemplate'));

		$result = $this->service()->copy(domainId: 'dom-empty', name: 'Leeg 2');

		self::assertTrue(condition: $result['complete']);
		self::assertSame(expected: ['caseType' => 0, 'contentTemplate' => 0], actual: $result['carried']);
		self::assertCount(expectedCount: $writesBefore, haystack: $this->harness->register->all(schema: 'contentTemplate'));
	}//end testADomainWithNoCaseTypesCopiesNoTemplates()
}//end class
