<?php

/**
 * How a case's relations are read from and written back to its stored form.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Relation
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

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use OCA\Dossiq\Service\Relation\CaseRelationCodec;
use PHPUnit\Framework\TestCase;

/**
 * The typed link properties, the relatedCases list, and the pair edits.
 *
 * @covers \OCA\Dossiq\Service\Relation\CaseRelationCodec
 */
class CaseRelationCodecTest extends TestCase {

	private CaseRelationCodec $codec;

	protected function setUp(): void {
		$this->codec = new CaseRelationCodec();
	}

	public function testEachNatureMapsToItsTypedProperty(): void {
		$this->assertSame('followUpCases', $this->codec->typedProperty('vervolg'));
		$this->assertSame('subjectCases', $this->codec->typedProperty('subject'));
		$this->assertSame('contributingCases', $this->codec->typedProperty('bijdrage'));
		$this->assertSame('associatedCases', $this->codec->typedProperty('samenhang'));
		$this->assertSame('blockingCases', $this->codec->typedProperty('waitsOn'));
		$this->assertNull($this->codec->typedProperty('onderliggend'));
	}

	public function testTypedLinksReadEveryStoredShapeOnceEach(): void {
		$links = $this->codec->typedLinks(
			[
				'followUpCases' => ['a', ['id' => 'b'], ['uuid' => 'c'], ' a ', '', 7, ['other' => 'x']],
				'subjectCases' => '["d","d",{"id":"e"}]',
				'contributingCases' => 'f',
				'associatedCases' => 42,
			]
		);

		$this->assertSame(
			[
				'followUpCases' => ['a', 'b', 'c'],
				'subjectCases' => ['d', 'e'],
				'contributingCases' => ['f'],
				'associatedCases' => [],
				'blockingCases' => [],
			],
			$links
		);
	}

	public function testALinkIsAddedOnceToItsProperty(): void {
		$links = ['followUpCases' => ['a']];

		$this->assertSame(['followUpCases' => ['a', 'b']], $this->codec->withTypedLink($links, 'vervolg', 'b'));
		$this->assertSame(['followUpCases' => ['a']], $this->codec->withTypedLink($links, 'vervolg', 'a'));
		$this->assertSame(['followUpCases' => ['a'], 'subjectCases' => ['s']], $this->codec->withTypedLink($links, 'subject', 's'));
		$this->assertSame($links, $this->codec->withTypedLink($links, 'unknown', 'b'));
		$this->assertSame($links, $this->codec->withTypedLink($links, 'vervolg', ''));
	}

	public function testALinkIsRemovedFromOneOrEveryProperty(): void {
		$links = ['followUpCases' => ['a', 'b'], 'subjectCases' => ['b', 'c']];

		$this->assertSame(['followUpCases' => ['a'], 'subjectCases' => ['b', 'c']], $this->codec->withoutTypedLink($links, 'vervolg', 'b'));
		$this->assertSame(['followUpCases' => ['a'], 'subjectCases' => ['c']], $this->codec->withoutTypedLink($links, null, 'b'));
		$this->assertSame($links, $this->codec->withoutTypedLink($links, 'unknown', 'b'));
		$this->assertSame($links, $this->codec->withoutTypedLink($links, null, ''));
	}

	public function testAnEntryCarriesNotesOnlyWhenThereAreAny(): void {
		$this->assertSame(['caseId' => 'c-1', 'aardRelatie' => 'vervolg', 'notes' => 'zie brief'], $this->codec->buildEntry('c-1', 'vervolg', 'zie brief'));
		$this->assertSame(['caseId' => 'c-1', 'aardRelatie' => 'vervolg'], $this->codec->buildEntry('c-1', 'vervolg', ''));
		$this->assertSame(['caseId' => 'c-1', 'aardRelatie' => 'vervolg'], $this->codec->buildEntry('c-1', 'vervolg', null));
	}

	public function testDecodeKeepsWellFormedEntriesFromAnArrayOrJson(): void {
		$stored = [
			['caseId' => 'c-1', 'aardRelatie' => 'vervolg', 'notes' => 'n', 'extra' => true],
			['caseId' => '', 'aardRelatie' => 'subject'],
			'not an entry',
			['caseId' => 'c-2', 'notes' => ''],
		];
		$expected = [
			['caseId' => 'c-1', 'aardRelatie' => 'vervolg', 'notes' => 'n'],
			['caseId' => 'c-2', 'aardRelatie' => ''],
		];

		$this->assertSame($expected, $this->codec->decode(['relatedCases' => $stored]));
		$this->assertSame($expected, $this->codec->decode(['relatedCases' => json_encode($stored)]));
		$this->assertSame([], $this->codec->decode(['relatedCases' => 'not json']));
		$this->assertSame([], $this->codec->decode([]));
	}

	public function testPairsAreFoundAndRemovedByCaseAndNature(): void {
		$relations = [
			['caseId' => 'c-1', 'aardRelatie' => 'vervolg'],
			['caseId' => 'c-1', 'aardRelatie' => 'subject'],
			['caseId' => 'c-2', 'aardRelatie' => 'vervolg'],
		];

		$this->assertTrue($this->codec->hasPair($relations, 'c-1', 'subject'));
		$this->assertFalse($this->codec->hasPair($relations, 'c-2', 'subject'));
		$this->assertSame(
			[['caseId' => 'c-1', 'aardRelatie' => 'subject'], ['caseId' => 'c-2', 'aardRelatie' => 'vervolg']],
			$this->codec->removePair($relations, 'c-1', 'vervolg')
		);
		$this->assertSame([['caseId' => 'c-2', 'aardRelatie' => 'vervolg']], $this->codec->removeAllForCase($relations, 'c-1'));
	}
}
