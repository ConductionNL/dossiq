<?php

/**
 * Unit tests for SeriesDefinition: a document series reads its prefix, order,
 * width, gap policy and number field from the case type, with defaults, and
 * refuses a definition it cannot number by.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\DocumentSeries;

use InvalidArgumentException;
use OCA\Dossiq\Service\DocumentSeries\SeriesDefinition;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\DocumentSeries\SeriesDefinition
 */
class SeriesDefinitionTest extends TestCase {

	public function testACaseTypesSeriesIsReadWithTrimmingAndLowercasedDirections(): void {
		$caseType = [
			'documentSeries' => [
				'bijlagen' => [
					'prefix' => ' B-',
					'order' => ['received', ['field' => ' title ', 'direction' => 'DESC']],
					'width' => '3',
					'gapPolicy' => 'keep',
					'numberField' => ' volgnummer ',
				],
			],
		];

		$series = SeriesDefinition::fromCaseType($caseType, 'bijlagen', 'X-');

		$this->assertSame('bijlagen', $series->series);
		$this->assertSame('B-', $series->prefix);
		$this->assertSame([['field' => 'received', 'direction' => 'asc'], ['field' => 'title', 'direction' => 'desc']], $series->order);
		$this->assertSame(3, $series->width);
		$this->assertSame('keep', $series->gapPolicy);
		$this->assertSame('volgnummer', $series->numberField);
	}//end testACaseTypesSeriesIsReadWithTrimmingAndLowercasedDirections()

	public function testAnUndeclaredSeriesTakesTheDefaults(): void {
		foreach ([[], ['documentSeries' => ['bijlagen' => 'none']], ['documentSeries' => ['bijlagen' => ['prefix' => '  ', 'numberField' => '']]]] as $caseType) {
			$series = SeriesDefinition::fromCaseType($caseType, 'bijlagen', 'D-');

			$this->assertSame('D-', $series->prefix);
			$this->assertSame([], $series->order);
			$this->assertSame(SeriesDefinition::DEFAULT_WIDTH, $series->width);
			$this->assertSame(SeriesDefinition::GAP_RENUMBER, $series->gapPolicy);
			$this->assertSame('sequence', $series->numberField);
		}
	}//end testAnUndeclaredSeriesTakesTheDefaults()

	public function testAnUnknownGapPolicyIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Series bijlagen declares an unknown gap policy "skip".');

		SeriesDefinition::fromArray('bijlagen', ['gapPolicy' => 'skip']);
	}//end testAnUnknownGapPolicyIsRefused()

	public function testAWidthOutsideOneToNineIsRefused(): void {
		$this->assertSame(1, (new SeriesDefinition(series: 's', width: 1))->width);
		$this->assertSame(9, (new SeriesDefinition(series: 's', width: 9))->width);

		foreach ([0, 10] as $width) {
			try {
				new SeriesDefinition(series: 'bijlagen', width: $width);
				$this->fail('Expected width ' . $width . ' to be refused.');
			} catch (InvalidArgumentException $e) {
				$this->assertSame("Series bijlagen declares width {$width}; it must be 1 to 9.", $e->getMessage());
			}
		}
	}//end testAWidthOutsideOneToNineIsRefused()

	public function testAnOrderKeyWithoutAFieldOrWithAnUnknownDirectionIsRefused(): void {
		$sentence = 'Series bijlagen declares an order key without a field, or a direction other than asc or desc.';

		foreach ([['order' => [['field' => ' ']]], ['order' => [['field' => 'title', 'direction' => 'up']]]] as $config) {
			try {
				SeriesDefinition::fromArray('bijlagen', $config);
				$this->fail('Expected the order to be refused.');
			} catch (InvalidArgumentException $e) {
				$this->assertSame($sentence, $e->getMessage());
			}
		}
	}//end testAnOrderKeyWithoutAFieldOrWithAnUnknownDirectionIsRefused()
}//end class
