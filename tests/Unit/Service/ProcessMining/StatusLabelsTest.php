<?php

/**
 * Naming a status for the reader, falling back to its id.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\ProcessMining
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/process-mining-bottlenecks/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\ProcessMining;

use OCA\Dossiq\Service\ProcessMining\StatusLabels;
use OCA\Dossiq\Service\Support\TranslatedText;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\ProcessMining\StatusLabels
 * @uses   \OCA\Dossiq\Service\Support\TranslatedText
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 */
class StatusLabelsTest extends TestCase {

	/**
	 * Labels over a fixed index, for a reader in the given language.
	 *
	 * @param string $language The reader's language code.
	 *
	 * @return StatusLabels
	 */
	private function labels(string $language): StatusLabels {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn($language);

		return new StatusLabels(
			text: new TranslatedText(l10n: $l10n),
			statusTypeIndex: [
				'st-open' => ['name' => ['nl' => 'Ontvangen', 'en' => 'Received']],
				'st-title' => ['title' => ' In behandeling '],
				'st-both' => ['name' => 'Besluit', 'title' => 'Ignored'],
				'st-blank' => ['name' => ['nl' => '  ']],
				'st-empty' => [],
			],
		);
	}//end labels()

	public function testTheNameIsReadInTheReadersLanguage(): void {
		self::assertSame('Received', $this->labels(language: 'en_GB')->labelOf(statusId: 'st-open'));
		self::assertSame('Ontvangen', $this->labels(language: 'nl')->labelOf(statusId: 'st-open'));
		self::assertSame('Ontvangen', $this->labels(language: 'de')->labelOf(statusId: 'st-open'));
	}//end testTheNameIsReadInTheReadersLanguage()

	public function testTheNameWinsOverTheTitleWhichIsTheFallback(): void {
		$labels = $this->labels(language: 'nl');

		self::assertSame('Besluit', $labels->labelOf(statusId: 'st-both'));
		self::assertSame('In behandeling', $labels->labelOf(statusId: 'st-title'));
	}//end testTheNameWinsOverTheTitleWhichIsTheFallback()

	public function testAnUnknownOrNamelessStatusIsLabelledByItsId(): void {
		$labels = $this->labels(language: 'nl');

		self::assertSame('st-gone', $labels->labelOf(statusId: 'st-gone'));
		self::assertSame('st-blank', $labels->labelOf(statusId: 'st-blank'));
		self::assertSame('st-empty', $labels->labelOf(statusId: 'st-empty'));
	}//end testAnUnknownOrNamelessStatusIsLabelledByItsId()
}//end class
