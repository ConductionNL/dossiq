<?php

/**
 * Example data for DqProcesanalyse: finished cases whose status history
 * fills the phases and case types the days-per-phase matrix draws.
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
 * The finished cases behind the process analysis.
 */
class ZuiddrechtProcessAnalysisHistoryTest extends TestCase {

	/**
	 * The part's cases, by key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$file = __DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.d/62-process-analysis-history.json';
		$this->assertFileExists($file);
		$part = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

		return array_column($part['objects'], null, 'key');
	}//end cases()

	/**
	 * The day offset of a datetime placeholder.
	 *
	 * @param string $placeholder A `{{datetime:<days> <time>}}` placeholder.
	 *
	 * @return int
	 */
	private function day(string $placeholder): int {
		$this->assertMatchesRegularExpression(pattern: '/^\{\{datetime:-\d+ \d\d:\d\d\}\}$/', string: $placeholder);

		return (int)substr($placeholder, 11);
	}//end day()

	/**
	 * Each case is closed, held by someone other than Pieter, and its history runs forward in time to the status it is in.
	 *
	 * @return void
	 */
	public function testEveryCaseIsFinishedAndOrdered(): void {
		$this->assertCount(expectedCount: 5, haystack: $this->cases());
		foreach ($this->cases() as $key => $case) {
			$data    = $case['data'];
			$history = $data['statusHistory'];
			$this->assertNotSame(expected: '{{cast:pieter.uid}}', actual: $data['assignee'], message: $key);
			$this->assertSame(expected: '{{lookup:status}}', actual: end($history)['status'], message: $key);
			$this->assertSame(expected: end($history)['enteredAt'], actual: $data['currentStatusEnteredAt'], message: $key);
			$this->assertLessThanOrEqual(expected: -21, actual: $this->day(placeholder: $data['currentStatusEnteredAt']), message: $key);

			$days = array_map(fn(array $entry): int => $this->day(placeholder: $entry['enteredAt']), $history);
			$sorted = $days;
			sort($sorted);
			$this->assertSame(expected: $sorted, actual: $days, message: $key);
			foreach ($history as $entry) {
				$this->assertArrayHasKey(key: trim($entry['status'], '{}'), array: array_flip(array_map(static fn(string $name): string => 'lookup:'.$name, array_keys($case['lookups']))), message: $key);
			}
		}
	}//end testEveryCaseIsFinishedAndOrdered()

	/**
	 * The Bezwaar case goes back to In behandeling after the committee's advice, which is the rework the report counts.
	 *
	 * @return void
	 */
	public function testTheBezwaarReturnsToAssessmentAfterTheAdvice(): void {
		$case     = $this->cases()['procesanalyse-bezwaar-uitrit-dorpsstraat'];
		$statuses = array_column($case['data']['statusHistory'], 'status');
		$names    = array_map(static fn(string $ref): string => $case['lookups'][trim(substr($ref, 9), '}')]['where']['name'], $statuses);

		$this->assertSame(
			expected: ['Ontvangen', 'In behandeling', 'Advies uitgebracht', 'In behandeling', 'Beslissing op bezwaar', 'Afgehandeld'],
			actual: $names
		);
	}//end testTheBezwaarReturnsToAssessmentAfterTheAdvice()

	/**
	 * The statuses the register ships per case type identifier, by name.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function registerStatuses(): array {
		$file     = __DIR__.'/../../../lib/Settings/dossiq_register.json';
		$register = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
		$statuses = [];
		foreach ($register['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? '') === 'caseType' && isset($object['identifier']) === true) {
				$statuses[$object['identifier']] ??= [];
			}

			if (($object['@self']['schema'] ?? '') === 'statusType') {
				$statuses[substr((string)$object['caseType'], 5)][$object['name']] = $object;
			}
		}

		return $statuses;
	}//end registerStatuses()

	/**
	 * A status lookup on a case type the register ships names a status that case type has.
	 *
	 * @return void
	 */
	public function testStatusLookupsNameStatusesTheRegisterShips(): void {
		$shipped = $this->registerStatuses();
		foreach ($this->cases() as $key => $case) {
			$identifier = $case['lookups']['caseType']['where']['identifier'];
			if (isset($shipped[$identifier]) === false) {
				continue;
			}

			foreach ($case['lookups'] as $name => $lookup) {
				if ($lookup['schema'] !== 'statusType') {
					continue;
				}

				$this->assertArrayHasKey(key: $lookup['where']['name'], array: $shipped[$identifier], message: $key.' '.$name);
			}
		}
	}//end testStatusLookupsNameStatusesTheRegisterShips()

	/**
	 * The complaint spends 13 days in a status of phase Beoordeling, the only phase the board fills for it.
	 *
	 * @return void
	 */
	public function testTheComplaintSpendsThirteenDaysInAssessment(): void {
		$case    = $this->cases()['procesanalyse-klacht-afhandeling-melding'];
		$history = $case['data']['statusHistory'];
		$name    = $case['lookups'][trim(substr($history[0]['status'], 9), '}')]['where']['name'];

		$this->assertCount(expectedCount: 2, haystack: $history);
		$this->assertSame(expected: 'Beoordeling', actual: $this->registerStatuses()['klacht-behandeling'][$name]['phase'] ?? null);
		$this->assertSame(expected: 13, actual: $this->day(placeholder: $history[1]['enteredAt']) - $this->day(placeholder: $history[0]['enteredAt']));
	}//end testTheComplaintSpendsThirteenDaysInAssessment()

	/**
	 * The re-integration spends 21 days In uitvoering, a visit that ended.
	 *
	 * @return void
	 */
	public function testTheReintegrationEndsItsExecutionVisit(): void {
		$history = $this->cases()['procesanalyse-reintegratie-werkervaringsplek']['data']['statusHistory'];

		$this->assertSame(expected: 21, actual: $this->day(placeholder: $history[3]['enteredAt']) - $this->day(placeholder: $history[2]['enteredAt']));
	}//end testTheReintegrationEndsItsExecutionVisit()
}//end class
