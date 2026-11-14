<?php

/**
 * A case type's version chain: every version sharing its identifier, newest
 * first and each once; the version new cases get; and the versions a running
 * case could move to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\CaseTypeVersionChain;
use OCA\Dossiq\Service\CaseType\DerivedCaseTypePayload;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Support\TranslatedText;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeVersionChain
 * @uses   \OCA\Dossiq\Service\CaseType\DerivedCaseTypePayload
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Support\TranslatedText
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 */
class CaseTypeVersionChainTest extends TestCase {

	/**
	 * The case type store.
	 *
	 * @var CaseTypeStore&MockObject
	 */
	private CaseTypeStore&MockObject $store;

	/**
	 * The versions of identifier ZT-1, as the store holds them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * A chain of three: v1 superseded by v2, v2 published, v3 a draft.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rows = [
			'v1' => ['id' => 'v1', 'identifier' => 'ZT-1', 'title' => 'Vergunning', 'version' => 1, 'validFrom' => '2025-01-01', 'validUntil' => '2025-12-31', 'supersededBy' => ['id' => 'v2']],
			'v2' => ['id' => 'v2', 'identifier' => 'ZT-1', 'title' => 'Vergunning', 'version' => 2, 'validFrom' => '2026-01-01', 'previousVersion' => 'v1'],
			'v3' => ['id' => 'v3', 'identifier' => 'ZT-1', 'title' => ['nl' => 'Vergunning (concept)'], 'version' => 3, 'isDraft' => true, 'previousVersion' => 'v2'],
		];
		$this->store = $this->getMockBuilder(CaseTypeStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCaseType', 'versionsWithIdentifier'])
			->getMock();
		$this->store->method('readCaseType')->willReturnCallback(fn (string $caseTypeId): array => ($this->rows[$caseTypeId] ?? []));
		$this->store->method('versionsWithIdentifier')->willReturnCallback(
			fn (string $identifier): array => array_values(array_filter($this->rows, static fn (array $row): bool => ($row['identifier'] ?? '') === $identifier && $identifier !== ''))
		);
	}//end setUp()

	/**
	 * The chain under test.
	 *
	 * @return CaseTypeVersionChain
	 */
	private function chain(): CaseTypeVersionChain {
		return new CaseTypeVersionChain(store: $this->store, payloads: new DerivedCaseTypePayload(), text: new TranslatedText());
	}//end chain()

	/**
	 * Every version is listed once, newest first, with its fields read.
	 *
	 * @return void
	 */
	public function testEveryVersionIsListedOnceNewestFirst(): void {
		$versions = $this->chain()->versionsOf(caseTypeId: 'v2');

		self::assertSame(['v3', 'v2', 'v1'], array_column($versions, 'id'));
		self::assertSame(
			[
				'id' => 'v1',
				'title' => 'Vergunning',
				'identifier' => 'ZT-1',
				'version' => 1,
				'isDraft' => false,
				'validFrom' => '2025-01-01',
				'validUntil' => '2025-12-31',
				'previousVersion' => '',
				'supersededBy' => 'v2',
				'isSelf' => false,
			],
			$versions[2]
		);
		self::assertSame([true, 'v1', 'Vergunning (concept)'], [$versions[1]['isSelf'], $versions[1]['previousVersion'], $versions[0]['title']]);
		self::assertTrue($versions[0]['isDraft']);
	}//end testEveryVersionIsListedOnceNewestFirst()

	/**
	 * A case type without an identifier is its own chain of one, version 1 at least.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutIdentifierIsItsOwnChain(): void {
		$this->rows = ['solo' => ['id' => 'solo', 'title' => 'Los', 'version' => 0]];

		$versions = $this->chain()->versionsOf(caseTypeId: 'solo');

		self::assertCount(1, $versions);
		self::assertSame(['solo', 1, true], [$versions[0]['id'], $versions[0]['version'], $versions[0]['isSelf']]);
	}//end testACaseTypeWithoutIdentifierIsItsOwnChain()

	/**
	 * An unknown case type has no chain, and rows without an id are skipped.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseTypeHasNoChainAndIdlessRowsAreSkipped(): void {
		self::assertSame([], $this->chain()->versionsOf(caseTypeId: 'missing'));
		self::assertSame([], $this->chain()->currentOf(caseTypeId: 'missing'));
		self::assertSame([], $this->chain()->targetsFor(caseTypeId: 'missing'));

		$this->rows['ghost'] = ['identifier' => 'ZT-1', 'version' => 9];
		self::assertSame(['v3', 'v2', 'v1'], array_column($this->chain()->versionsOf(caseTypeId: 'v1'), 'id'));
	}//end testAnUnknownCaseTypeHasNoChainAndIdlessRowsAreSkipped()

	/**
	 * The current version is the published one nothing superseded.
	 *
	 * @return void
	 */
	public function testTheCurrentVersionIsPublishedAndNotSuperseded(): void {
		self::assertSame('v2', $this->chain()->currentOf(caseTypeId: 'v1')['id']);

		$this->rows['v2']['supersededBy'] = 'v3';
		self::assertSame([], $this->chain()->currentOf(caseTypeId: 'v1'));
	}//end testTheCurrentVersionIsPublishedAndNotSuperseded()

	/**
	 * A case can move to every published version but its own, older ones included.
	 *
	 * @return void
	 */
	public function testTargetsAreThePublishedVersionsButItsOwn(): void {
		self::assertSame(['v1'], array_column($this->chain()->targetsFor(caseTypeId: 'v2'), 'id'));
		self::assertSame(['v2'], array_column($this->chain()->targetsFor(caseTypeId: 'v1'), 'id'));
		self::assertSame(['v2', 'v1'], array_column($this->chain()->targetsFor(caseTypeId: 'v3'), 'id'));
	}//end testTargetsAreThePublishedVersionsButItsOwn()
}//end class
