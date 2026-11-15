<?php

/**
 * Fitting a stored answer onto a target field: the edges of each kind.
 *
 * Pins how the converter reads a malformed choice list, writes every kind of
 * answer as text, carries a structured answer only onto the same kind, and
 * accepts or refuses moments, e-mail addresses, web addresses and yes/no words.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use PHPUnit\Framework\TestCase;

/**
 * The converter's answers at the edges of each kind.
 *
 * @covers \OCA\Dossiq\Service\Cases\RebindValueConverter
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
class RebindValueConverterTest extends TestCase {

	use BuildsRebindConverter;

	/**
	 * A choice list that is not a list declares no choices, so the field is plain text.
	 *
	 * @return void
	 */
	public function testAChoiceListThatIsNotAListDeclaresNoChoices(): void {
		$converter = $this->rebindConverter();
		$definition = ['propertyType' => 'string', 'enumValues' => 'a,b'];

		self::assertSame([], $converter->choicesOf(definition: $definition));
		self::assertSame('text', $converter->kindOf(definition: $definition));
	}//end testAChoiceListThatIsNotAListDeclaresNoChoices()

	/**
	 * Every kind of answer is written as the text the case stores.
	 *
	 * @return void
	 */
	public function testEveryAnswerIsWrittenAsText(): void {
		$converter = $this->rebindConverter();

		self::assertSame('', $converter->asText(value: null));
		self::assertSame('true', $converter->asText(value: true));
		self::assertSame('false', $converter->asText(value: false));
		self::assertSame('42', $converter->asText(value: ' 42 '));
		self::assertSame('', $converter->asText(value: []));
		self::assertSame('{"lat":52.1,"lon":5.1}', $converter->asText(value: ['lat' => 52.1, 'lon' => 5.1]));
		self::assertSame('["a","b"]', $converter->asText(value: ['a', 'b']));
	}//end testEveryAnswerIsWrittenAsText()

	/**
	 * A structured answer carries over onto the same kind, and not onto another.
	 *
	 * @return void
	 */
	public function testAStructuredAnswerCarriesOverOnlyOntoTheSameKind(): void {
		$converter = $this->rebindConverter();
		$file = ['propertyType' => 'file'];
		$value = '{"name":"plan.pdf"}';

		self::assertSame($value, $converter->fit(value: $value, source: $file, target: ['propertyType' => 'file']));
		self::assertNull($converter->fit(value: $value, source: $file, target: ['propertyType' => 'object']));
		self::assertNull($converter->fit(value: $value, source: $file, target: ['propertyType' => 'string']));
	}//end testAStructuredAnswerCarriesOverOnlyOntoTheSameKind()

	/**
	 * A moment lands in ATOM form, the same instant in the app's fallback zone; what is not a moment does not fit.
	 *
	 * @return void
	 */
	public function testAMomentLandsInAtomForm(): void {
		$converter = $this->rebindConverter();
		$target = ['propertyType' => 'string', 'format' => 'date-time'];

		self::assertSame(
			'2026-03-01T09:15:00+01:00',
			$converter->fit(value: '2026-03-01T10:15:00+02:00', source: [], target: $target)
		);
		self::assertNull($converter->fit(value: 'next tuesday-ish', source: [], target: $target));
	}//end testAMomentLandsInAtomForm()

	/**
	 * An e-mail address fits an e-mail field only when it is one.
	 *
	 * @return void
	 */
	public function testAnEmailFieldTakesOnlyAnAddress(): void {
		$converter = $this->rebindConverter();
		$target = ['propertyType' => 'string', 'format' => 'email'];

		self::assertSame('loket@gemeente.nl', $converter->fit(value: ' loket@gemeente.nl ', source: [], target: $target));
		self::assertNull($converter->fit(value: 'loket at gemeente', source: [], target: $target));
	}//end testAnEmailFieldTakesOnlyAnAddress()

	/**
	 * A web address fits a url field, by either format spelling, only when it is one.
	 *
	 * @return void
	 */
	public function testAUrlFieldTakesOnlyAnAddress(): void {
		$converter = $this->rebindConverter();

		self::assertSame(
			'https://gemeente.nl/loket',
			$converter->fit(value: 'https://gemeente.nl/loket', source: [], target: ['propertyType' => 'string', 'format' => 'uri'])
		);
		self::assertSame(
			'https://gemeente.nl',
			$converter->fit(value: 'https://gemeente.nl', source: [], target: ['propertyType' => 'url'])
		);
		self::assertNull($converter->fit(value: 'gemeente', source: [], target: ['propertyType' => 'string', 'format' => 'url']));
	}//end testAUrlFieldTakesOnlyAnAddress()

	/**
	 * No-words land as 'false', and a word that is neither does not fit.
	 *
	 * @return void
	 */
	public function testNoWordsLandAsFalse(): void {
		$converter = $this->rebindConverter();
		$target = ['propertyType' => 'boolean'];

		foreach (['false', '0', 'No', 'NEE'] as $word) {
			self::assertSame('false', $converter->fit(value: $word, source: [], target: $target), $word);
		}

		self::assertSame('true', $converter->fit(value: 'Ja', source: [], target: $target));
		self::assertNull($converter->fit(value: 'misschien', source: [], target: $target));
	}//end testNoWordsLandAsFalse()
}//end class
