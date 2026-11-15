<?php

/**
 * The penalty is a stored OpenRegister calculation, not dossiq code.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Pins where the calculations live, what they compute over, and that the
 * rows dossiq writes fit the schema (ADR-118 C16).
 *
 * The arithmetic itself was evaluated with OpenRegister's CalculationEvaluator
 * at openregister#4571 for 0 to 60 days, Awb and flat bands, against the
 * retired DwangsomCalculationService: identical day, amount and ceiling.
 *
 * @spec openspec/changes/penalty-is-a-stored-calculation/specs/accruing-amount/spec.md
 */
final class PenaltyPaymentCalculationSchemaTest extends TestCase {

	/**
	 * The penalty schema from a register file.
	 *
	 * @param string $file Path under lib/Settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(string $file): array {
		$data = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/' . $file), true);
		return $data['components']['schemas']['penaltyPaymentCalculation'];
	}//end schema()

	/**
	 * OpenRegister reads calculations from `configuration` only; both the
	 * shipped fragment and the mock register carry the same four.
	 *
	 * @return void
	 */
	public function testTheCalculationsLiveUnderConfigurationInBothRegisters(): void {
		$shipped = self::schema(file: 'register.d/60-termijnbewaking.json');
		$mock = self::schema(file: 'dossiq_mock_register.json');

		self::assertArrayNotHasKey('x-openregister-calculations', $shipped);
		$calcs = $shipped['configuration']['x-openregister-calculations'];
		self::assertSame(['currentDag', 'cumulativeAmount', 'dailyRate', 'plafondBereikt'], array_keys($calcs));
		self::assertSame($calcs, $mock['configuration']['x-openregister-calculations']);
		foreach ($calcs as $name => $calc) {
			self::assertTrue($calc['materialise'], $name . ' must be materialised so the sweep keeps it current');
			self::assertArrayHasKey($name, $shipped['properties']);
		}
	}//end testTheCalculationsLiveUnderConfigurationInBothRegisters()

	/**
	 * The amount is `graduated` over rateBands (the Awb bands when empty),
	 * capped at the ceiling, counted to stoppedOn or today.
	 *
	 * @return void
	 */
	public function testTheAmountIsGraduatedOverRateBandsUntilStopped(): void {
		$calcs = self::schema(file: 'register.d/60-termijnbewaking.json')['configuration']['x-openregister-calculations'];
		$graduated = $calcs['cumulativeAmount']['expression']['graduated'];

		self::assertSame($calcs['currentDag']['expression'], $graduated[0]);
		self::assertSame(
			['coalesce' => [['prop' => 'rateBands'], ['lit' => [['upTo' => 14, 'rate' => 2300], ['upTo' => 28, 'rate' => 3500], ['rate' => 4500]]]]],
			$graduated[1]
		);
		self::assertSame(['coalesce' => [['prop' => 'plafondCalculated'], 144200]], $graduated[2]);
		self::assertStringContainsString('"stoppedOn"', (string)json_encode($calcs['currentDag']['expression']));
	}//end testTheAmountIsGraduatedOverRateBandsUntilStopped()

	/**
	 * A deviating row with its flat band and a stopped row fit the merged
	 * schema, validated the way OpenRegister validates on save.
	 *
	 * @return void
	 */
	public function testRowsWithRateBandsAndStoppedOnFitTheSchema(): void {
		$validator = new RealSchemaValidator();
		$row = [
			'noticeOfDefault' => 'n-1',
			'deadlineInstance' => 't-1',
			'startDate' => '2026-03-29',
			'plafondCalculated' => 144200,
			'status' => 'lopend',
			'regime' => 'afwijkend',
			'rateBands' => [['rate' => 1000]],
		];
		self::assertSame([], $validator->errors(slug: 'penaltyPaymentCalculation', payload: $row));

		$stopped = $row + ['stoppedOn' => '2026-04-03', 'definitiveAmount' => 5000];
		$stopped['status'] = 'gestopt-wegens-decision';
		self::assertSame([], $validator->errors(slug: 'penaltyPaymentCalculation', payload: $stopped));
		// A band without a rate passes the save (OpenRegister does not apply
		// `required` inside array items); the `graduated` operator refuses it
		// at evaluation instead of reading it as zero (openregister REQ-GRC-03).
	}//end testRowsWithRateBandsAndStoppedOnFitTheSchema()
}//end class
