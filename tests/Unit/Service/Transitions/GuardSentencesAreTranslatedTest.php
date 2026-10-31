<?php

/**
 * Every refusal a guard or the approval gate writes has a Dutch translation.
 *
 * A sentence passed through IL10N with no catalogue entry reaches a Dutch
 * reader in English, on the board's card menu and on the case page alike.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use PHPUnit\Framework\TestCase;

/**
 * Reads the translated sentences out of the guard sources and checks both catalogues.
 */
class GuardSentencesAreTranslatedTest extends TestCase {
	/**
	 * The sources whose refusals a handler reads.
	 *
	 * @var array<int, string>
	 */
	private const SOURCES = [
		'lib/Service/Transitions/ApprovalGuard.php',
		'lib/Service/Transitions/CapacityGuard.php',
		'lib/Service/Transitions/ChecklistGuard.php',
		'lib/Service/Transitions/DelegatedAuthorityGuard.php',
		'lib/Service/Transitions/DocumentAssessmentGuard.php',
		'lib/Service/Transitions/RequiredDocumentGuard.php',
		'lib/Service/Transitions/RequiredFieldGuard.php',
		'lib/Service/Transitions/RoleGuard.php',
		'lib/Service/Transitions/StatusChecklistGuard.php',
		'lib/Service/Cases/ApprovalGate.php',
	];

	/**
	 * A single-quoted literal passed as the first argument of `->t(`.
	 *
	 * @var string
	 */
	private const T_LITERAL = <<<'REGEX'
/->t\(\s*'((?:\\.|[^'\\])*)'/
REGEX;

	/**
	 * Each `->t('...')` literal in the sources is a key in en.json and nl.json.
	 *
	 * @return void
	 */
	public function testEveryGuardSentenceIsInBothCatalogues(): void {
		$root = dirname(__DIR__, 4);
		$en   = $this->catalogue(path: $root . '/l10n/en.json');
		$nl   = $this->catalogue(path: $root . '/l10n/nl.json');

		$keys = [];
		foreach (self::SOURCES as $source) {
			$code = (string)file_get_contents($root . '/' . $source);
			preg_match_all(self::T_LITERAL, $code, $matches);
			foreach ($matches[1] as $literal) {
				$keys[] = str_replace(["\\'", '\\\\'], ["'", '\\'], $literal);
			}
		}

		self::assertGreaterThanOrEqual(expected: 30, actual: count($keys), message: 'Expected the guards to translate their refusals');

		$missing = [];
		foreach ($keys as $key) {
			if (isset($en[$key]) === false || isset($nl[$key]) === false || $nl[$key] === '') {
				$missing[] = $key;
			}
		}

		self::assertSame(expected: [], actual: $missing, message: "Guard sentences without a catalogue entry:\n" . implode("\n", $missing));
	}//end testEveryGuardSentenceIsInBothCatalogues()

	/**
	 * The translations of one catalogue.
	 *
	 * @param string $path The catalogue file.
	 *
	 * @return array<string, string>
	 */
	private function catalogue(string $path): array {
		$decoded = json_decode((string)file_get_contents($path), true);

		return (array)($decoded['translations'] ?? []);
	}//end catalogue()
}//end class
