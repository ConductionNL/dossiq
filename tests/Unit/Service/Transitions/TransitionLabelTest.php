<?php

/**
 * Tests for TransitionLabel.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Service\Support\TranslatedText;
use OCA\Dossiq\Service\Transitions\TransitionLabel;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * A transition's label, a string or a language map, as text.
 */
class TransitionLabelTest extends TestCase {

	/**
	 * A label reader for a reader in the given language.
	 *
	 * @param string $language The reader's language.
	 *
	 * @return TransitionLabel The reader.
	 */
	private function labels(string $language): TransitionLabel {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('getLanguageCode')->willReturn($language);

		return new TransitionLabel(reader: new TranslatedText(l10n: $l10n), text: new LanguageMapText());
	}//end labels()

	/**
	 * A language map reads in the reader's language, Dutch as the source.
	 *
	 * @return void
	 */
	public function testALanguageMapReadsInTheReadersLanguage(): void {
		$transition = ['label' => ['nl' => 'In behandeling nemen', 'en' => 'Take on']];

		self::assertSame(expected: 'Take on', actual: $this->labels(language: 'en')->forReader(transition: $transition));
		self::assertSame(expected: 'In behandeling nemen', actual: $this->labels(language: 'nl')->forReader(transition: $transition));
		self::assertSame(expected: 'In behandeling nemen', actual: $this->labels(language: 'en')->source(transition: $transition));
		self::assertSame(expected: ['In behandeling nemen', 'Take on'], actual: $this->labels(language: 'en')->texts(transition: $transition));
	}//end testALanguageMapReadsInTheReadersLanguage()

	/**
	 * A plain string label stays as it is.
	 *
	 * @return void
	 */
	public function testAStringLabelStays(): void {
		$labels = $this->labels(language: 'en');

		self::assertSame(expected: 'Goedkeuren', actual: $labels->forReader(transition: ['label' => ' Goedkeuren ']));
		self::assertSame(expected: ['Goedkeuren'], actual: $labels->texts(transition: ['label' => 'Goedkeuren']));
		self::assertSame(expected: '', actual: $labels->forReader(transition: []));
		self::assertSame(expected: [], actual: $labels->texts(transition: ['label' => '']));
	}//end testAStringLabelStays()
}//end class
