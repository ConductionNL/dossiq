<?php

/**
 * Example data for DqFlow and DqMijnWerk: the regular permit's case-type flow
 * as an editable draft, and the Woo request that waits on its requester.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The regular permit flow and the waiting Woo request in the Zuiddrecht set.
 */
class ZuiddrechtRegulierFlowAndWaitTest extends TestCase {

	/**
	 * One part of the set, as written.
	 *
	 * @param string $name The part's file name.
	 *
	 * @return array<string, mixed> The part.
	 */
	private function part(string $name): array {
		$file = __DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.d/'.$name;
		$this->assertFileExists($file);

		return json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	}//end part()

	/**
	 * The flow is an unpublished draft whose steps, read top to bottom, are the ones the board draws.
	 *
	 * @return void
	 */
	public function testTheRegularPermitFlowIsAnEditableDraft(): void {
		$part = $this->part(name: '60-regulier-case-type-flow.json');
		$flow = $part['flows'][0]['data'];

		$this->assertSame(expected: 'Omgevingsvergunning regulier', actual: $flow['name']);
		$this->assertFalse($flow['publish']);
		$this->assertSame(
			expected: ['Zaak aangemaakt', 'Status: ontvangen', 'Volledigheid toetsen', 'Ontvangstbericht', 'Welstand nodig?', 'Deelzaak welstandsadvies', 'Besluit publiceren', 'Einde'],
			actual: array_column($flow['nodes'], 'name')
		);

		$ids = array_column($flow['nodes'], 'id');
		foreach ($flow['edges'] as $edge) {
			$this->assertContains($edge['from'], $ids);
			$this->assertContains($edge['to'], $ids);
		}
	}//end testTheRegularPermitFlowIsAnEditableDraft()

	/**
	 * The design-review step reads the stored table the fragment writes, and both of its answers lead somewhere.
	 *
	 * @return void
	 */
	public function testTheDesignReviewStepReadsTheStoredTable(): void {
		$part  = $this->part(name: '60-regulier-case-type-flow.json');
		$table = $part['objects'][0];
		$this->assertSame(expected: ['decision-tables', 'decision-table'], actual: [$table['register'], $table['schema']]);
		$this->assertSame(expected: 'Welstandsgebieden Zuiddrecht', actual: $table['data']['name']);

		$step = array_values(array_filter($part['flows'][0]['data']['nodes'], static fn(array $node): bool => $node['id'] === 'welstand'))[0];
		$this->assertSame(expected: $table['data']['key'], actual: $step['config']['tableRef']);

		$exits = [];
		foreach ($part['flows'][0]['data']['edges'] as $edge) {
			if ($edge['from'] === 'welstand') {
				$exits[$edge['fromExit']] = $edge['to'];
			}
		}

		$this->assertSame(expected: ['ja' => 'welstandsadvies', 'nee' => 'besluit-publiceren'], actual: $exits);
		foreach ($table['data']['rules'] as $rule) {
			$this->assertCount(expectedCount: count($table['data']['inputs']), haystack: $rule['inputEntries']);
			$this->assertContains($rule['outputEntries'][0], ['ja', 'nee']);
		}
	}//end testTheDesignReviewStepReadsTheStoredTable()

	/**
	 * Parkeervergunningen binnenstad waits on an open request sent nine days ago, and the case says so from that moment.
	 *
	 * @return void
	 */
	public function testParkeervergunningenWaitsOnTheRequester(): void {
		$part    = $this->part(name: '61-parkeervergunningen-waits.json');
		$request = $part['objects'][0]['data'];
		$change  = $part['changes'][0];

		$this->assertSame(expected: '{{object:woo-parkeervergunningen-binnenstad.id}}', actual: $request['case']);
		$this->assertSame(expected: 'open', actual: $request['state']);
		$this->assertSame(expected: 'woo-parkeervergunningen-binnenstad', actual: $change['object']);
		$this->assertTrue($change['set']['waitingOnApplicant']);
		$this->assertSame(expected: $request['requestedAt'], actual: $change['set']['waitingOnApplicantSince']);
		$this->assertSame(expected: '{{datetime:-9 14:00}}', actual: $request['requestedAt']);
	}//end testParkeervergunningenWaitsOnTheRequester()
}//end class
