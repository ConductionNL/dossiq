<?php

/**
 * The case, its case type and the rows that belong to it, read regardless of the caller.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\CaseRecordLookup;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Access\CaseRecordLookup
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CaseRecordLookupTest extends TestCase {

	private InMemoryRegister $register;

	private CaseRecordLookup $lookup;

	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? $default
		);
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(static fn (string $id): array => ['id' => $id, 'resolved' => true]);

		$this->lookup = new CaseRecordLookup(settingsService: $settings, caseTypes: new CaseTypeStore($settings), resolver: $resolver);

		$this->register->seed('case', 'c-1', ['caseType' => 'ct-1', 'assignee' => 'handler']);
		$this->register->seed('assessment', 'a-1', ['caseRef' => 'c-1']);
		$this->register->seed('assessment', 'a-2', ['caseRef' => 'c-2']);
	}//end setUp()

	public function testTheRowsOfACaseAreOnlyThatCasesRows(): void {
		self::assertSame(['a-1'], array_column($this->lookup->rowsOf(schema: 'assessment', field: 'caseRef', caseId: 'c-1'), 'id'));
		self::assertSame([], $this->lookup->rowsOf(schema: '', field: 'caseRef', caseId: 'c-1'), 'no schema, no rows');
		self::assertSame([], $this->lookup->rowsOf(schema: 'assessment', field: 'caseRef', caseId: ''), 'no case, no rows');
	}//end testTheRowsOfACaseAreOnlyThatCasesRows()

	public function testTheCaseAndItsCaseType(): void {
		$case = $this->lookup->caseRow(caseId: 'c-1');

		self::assertSame('handler', $case['assignee']);
		self::assertSame(['id' => 'ct-1', 'resolved' => true], $this->lookup->caseTypeOf(case: $case));
		self::assertSame([], $this->lookup->caseRow(caseId: ''));
		self::assertSame([], $this->lookup->caseTypeOf(case: []), 'a case without a case type has none');
	}//end testTheCaseAndItsCaseType()
}//end class
