<?php

/**
 * Unit tests for ZgwRuleResults.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZgwRuleResults;
use PHPUnit\Framework\TestCase;

/**
 * The valid and the refused result of a rule.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class ZgwRuleResultsTest extends TestCase {
	/**
	 * A pass carries the body and a 200.
	 *
	 * @return void
	 */
	public function testAPassCarriesTheBody(): void {
		$this->assertSame(
			['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => ['a' => 1]],
			(new ZgwRuleResults())->isValid(body: ['a' => 1])
		);
	}//end testAPassCarriesTheBody()

	/**
	 * A refusal carries its status, detail and fields, and a code only when one is given.
	 *
	 * @return void
	 */
	public function testARefusalCarriesItsFields(): void {
		$results = new ZgwRuleResults();
		$field   = $results->fieldError(fieldName: 'zaaktype', code: 'bad-url', reason: 'Ongeldig.');

		$this->assertSame(['name' => 'zaaktype', 'code' => 'bad-url', 'reason' => 'Ongeldig.'], $field);
		$this->assertSame(
			['valid' => false, 'status' => 400, 'detail' => 'Nee.', 'invalidParams' => [$field], 'enrichedBody' => []],
			$results->error(status: 400, detail: 'Nee.', invalidParams: [$field])
		);
		$this->assertSame(
			['valid' => false, 'status' => 403, 'detail' => 'Nee.', 'invalidParams' => [], 'enrichedBody' => [], 'code' => 'permission_denied'],
			$results->error(status: 403, detail: 'Nee.', code: 'permission_denied')
		);
	}//end testARefusalCarriesItsFields()

	/**
	 * A field that may not change is refused by name.
	 *
	 * @return void
	 */
	public function testAnImmutableFieldIsRefusedByName(): void {
		$detail = 'Het veld identificatie mag niet gewijzigd worden.';

		$this->assertSame(
			[
				'valid' => false,
				'status' => 400,
				'detail' => $detail,
				'invalidParams' => [['name' => 'identificatie', 'code' => 'wijzigen-niet-toegelaten', 'reason' => $detail]],
				'enrichedBody' => [],
			],
			(new ZgwRuleResults())->fieldImmutableError(fieldName: 'identificatie')
		);
	}//end testAnImmutableFieldIsRefusedByName()
}//end class
