<?php

/**
 * Golden test for ZrcCaseFieldRules::validateCaseFields: the whole refusal of the
 * communicatiekanaal and gegevensgroep rules, and the result a passing body gets.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZrcCaseFieldRules;
use PHPUnit\Framework\TestCase;

class ZrcCaseFieldRulesGoldenTest extends TestCase {
	use MakesZrcRuleGroup;

	private function passing(array $body): array {
		return ['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => $body, 'extra' => 'kept'];
	}//end passing()

	private function validate(array $body, ?array $existing = null): array {
		return $this->ruleGroup(ZrcCaseFieldRules::class, null)->validateCaseFields($this->passing($body), $existing);
	}//end validate()

	public function testEveryRefusalIsWhole(): void {
		$outcomes = [
			'bad-url' => $this->validate(['communicatiekanaal' => 'geen url']),
			'no-resource' => $this->validate(['communicatiekanaal' => 'http://example.com/kanalen']),
			'opschorting' => $this->validate(['suspension' => ['indicatie' => true]]),
			'opschorting-leeg' => $this->validate(['suspension' => []]),
			'verlenging' => $this->validate(['verlenging' => ['reden' => 'x']]),
			'betaling' => $this->validate(['betalingsindicatie' => 'nvt', 'laatsteBetaaldatum' => '2026-01-01']),
		];

		$fixture = __DIR__ . '/fixtures/zrc-case-field-refusals.json';
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents($fixture, json_encode($outcomes, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$this->assertSame(
			json_decode((string) file_get_contents($fixture), true),
			json_decode((string) json_encode($outcomes), true)
		);
	}//end testEveryRefusalIsWhole()

	public function testPassingBodiesKeepTheRestOfTheResult(): void {
		$this->assertSame(
			['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => ['communicatiekanaal' => ''], 'extra' => 'kept'],
			$this->validate(['communicatiekanaal' => ''])
		);
		$this->assertSame(
			['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => ['betalingsindicatie' => 'nvt', 'laatsteBetaaldatum' => null], 'extra' => 'kept'],
			$this->validate(['betalingsindicatie' => 'nvt', 'laatsteBetaaldatum' => '2026-01-01'], ['id' => 'z'])
		);
	}//end testPassingBodiesKeepTheRestOfTheResult()
}//end class
