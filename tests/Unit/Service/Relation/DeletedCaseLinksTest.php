<?php

/**
 * Unit tests for DeletedCaseLinks.
 *
 * The links to a deleted case stripped from every case that holds one: the
 * cases the deleted case named and the cases that named it, each once, and
 * a counterpart written only when a link to the deleted case went.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 *
 * @covers \OCA\Dossiq\Service\Relation\DeletedCaseLinks
 * @uses \OCA\Dossiq\Service\Relation\CaseRelationCodec
 * @uses \OCA\Dossiq\Service\Support\ReferenceId
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use OCA\Dossiq\Service\Relation\CaseRelationCodec;
use OCA\Dossiq\Service\Relation\CaseRelationStore;
use OCA\Dossiq\Service\Relation\DeletedCaseLinks;
use PHPUnit\Framework\TestCase;

class DeletedCaseLinksTest extends TestCase {

	/**
	 * The counterparts written during a test, by id, with what was written.
	 *
	 * @var array<string, array{relations: array<int, mixed>, typedLinks: array<string, mixed>|null}>
	 */
	private array $written = [];

	/**
	 * The cleanup over a store holding `$cases`, where `$incoming` declared a link towards the deleted case.
	 *
	 * @param array<string, array<string, mixed>> $cases    The cases, by id.
	 * @param array<int, array<string, mixed>>    $incoming The `/used` rows of the deleted case.
	 *
	 * @return DeletedCaseLinks
	 */
	private function links(array $cases, array $incoming = []): DeletedCaseLinks {
		$this->written = [];
		$store = $this->createMock(originalClassName: CaseRelationStore::class);
		$store->method('fetchCase')->willReturnCallback(
			static fn (string $caseUuid): ?array => ($cases[$caseUuid] ?? null)
		);
		$store->method('relationRows')->willReturn($incoming);
		$store->method('persistRelations')->willReturnCallback(
			function (array $case, array $relations, ?array $typedLinks = null): void {
				$this->written[(string)$case['id']] = ['relations' => $relations, 'typedLinks' => $typedLinks];
			}
		);

		return new DeletedCaseLinks(store: $store, codec: new CaseRelationCodec());
	}//end links()

	/**
	 * No case id strips nothing and reads nothing.
	 *
	 * @return void
	 */
	public function testNoCaseIdStripsNothing(): void {
		$this->assertSame(expected: 0, actual: $this->links(cases: [])->strip(caseId: ''));
		$this->assertSame(expected: [], actual: $this->written);
	}//end testNoCaseIdStripsNothing()

	/**
	 * Both halves are read, each counterpart once, and the deleted case's own entry skipped.
	 *
	 * @return void
	 */
	public function testTheCasesNamedAndTheCasesNamingItAreStrippedOnce(): void {
		$cases = [
			'x' => [
				'id' => 'x',
				'relatedCases' => [
					['caseId' => 'p', 'aardRelatie' => 'vervolg'],
					['caseId' => 'x', 'aardRelatie' => 'samenhang'],
					['caseId' => 'gone', 'aardRelatie' => 'samenhang'],
				],
			],
			'p' => [
				'id' => 'p',
				'relatedCases' => [
					['caseId' => 'x', 'aardRelatie' => 'vervolg'],
					['caseId' => 'q', 'aardRelatie' => 'samenhang'],
				],
			],
			'q' => ['id' => 'q', 'contributingCases' => ['x', 'p']],
		];

		$stripped = $this->links(cases: $cases, incoming: [['id' => 'q'], ['uuid' => 'p']])->strip(caseId: 'x');

		$this->assertSame(expected: 2, actual: $stripped);
		$this->assertSame(expected: ['p', 'q'], actual: array_keys($this->written));
		$this->assertSame(
			expected: [['caseId' => 'q', 'aardRelatie' => 'samenhang']],
			actual: $this->written['p']['relations']
		);
		$this->assertSame(expected: ['p'], actual: $this->written['q']['typedLinks']['contributingCases']);
	}//end testTheCasesNamedAndTheCasesNamingItAreStrippedOnce()

	/**
	 * A counterpart that holds no link to the deleted case is not written.
	 *
	 * @return void
	 */
	public function testACounterpartHoldingNoLinkIsNotWritten(): void {
		$cases = [
			'p' => ['id' => 'p', 'relatedCases' => [['caseId' => 'q', 'aardRelatie' => 'samenhang']]],
		];

		$this->assertSame(expected: 0, actual: $this->links(cases: $cases, incoming: [['id' => 'p']])->strip(caseId: 'x'));
		$this->assertSame(expected: [], actual: $this->written);
	}//end testACounterpartHoldingNoLinkIsNotWritten()
}//end class
