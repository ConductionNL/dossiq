<?php

/**
 * Golden test for the access-purpose rules read from the app configuration.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/specs/case-access-purpose/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\AccessPurposeRules;
use OCA\Dossiq\Service\CaseAccessPurposeService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;

/**
 * Pins the rules each configured JSON yields: who skips the dialog, as which purpose, and who becomes what.
 *
 * @covers \OCA\Dossiq\Service\AccessPurposeRules
 */
class AccessPurposeRulesGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The configured JSON texts, from absent to hostile.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function configurations(): array {
		return [
			'none' => [''],
			'not json' => ['{skipFor'],
			'a string' => ['"handler"'],
			'empty object' => ['{}'],
			'empty list' => ['[]'],
			'own skip roles' => ['{"skipFor":["coordinator","handler"]}'],
			'skip roles with non strings' => ['{"skipFor":["coordinator",7,null,{"a":1},"advisor"]}'],
			'skip roles not a list' => ['{"skipFor":"coordinator"}'],
			'skip roles empty' => ['{"skipFor":[]}'],
			'known purpose' => ['{"skipPurpose":"advice"}'],
			'unknown purpose' => ['{"skipPurpose":"curiosity"}'],
			'purpose not a string' => ['{"skipPurpose":["handling"]}'],
			'own assignments' => ['{"assign":{"handling":"handler","supervision":"supervisor"}}'],
			'assignments with blanks' => ['{"assign":{"handling":"","supervision":7,"advice":"advisor"}}'],
			'assignments not a map' => ['{"assign":"handler"}'],
			'assignments empty' => ['{"assign":{}}'],
			'all parts' => ['{"skipFor":["supervisor"],"skipPurpose":"quality-check","assign":{"quality-check":"supervisor"}}'],
		];
	}//end configurations()

	/**
	 * The configured JSON yields the recorded rules.
	 *
	 * @param string $raw The configured JSON.
	 *
	 * @return void
	 *
	 * @dataProvider configurations
	 */
	public function testTheConfigurationYieldsTheRecordedRules(string $raw): void {
		$rules = (new AccessPurposeRules())->fromConfig(raw: $raw, purposes: CaseAccessPurposeService::PURPOSES);

		$this->assertMatchesGolden(
			'access-purpose-rules/' . preg_replace('/[^a-z]+/', '-', strtolower((string)$this->dataName())),
			$rules
		);
	}//end testTheConfigurationYieldsTheRecordedRules()
}//end class
