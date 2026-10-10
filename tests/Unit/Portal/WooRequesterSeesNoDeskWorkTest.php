<?php

/**
 * The requester never reads the officer's work on a Woo case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-never-reads-the-officers-work-on-a-woo-case-req-wds-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * REQ-WDS-005: a negative projection over everything the resident audiences are offered.
 *
 * @coversNothing
 */
class WooRequesterSeesNoDeskWorkTest extends TestCase {

	/**
	 * Words that name desk work on a Woo case, matched case-insensitively inside a key.
	 */
	private const DESK_WORDS = [
		'assessment',
		'weigeringsgrond',
		'refusalground',
		'classification',
		'corpus',
		'searchplan',
		'redaction',
		'reviewer',
		'withheld',
	];

	/**
	 * Every key a collection or action declares to the resident: fields, columns, detail fields and labels.
	 *
	 * @param array<string, mixed> $entry A collection or an action.
	 *
	 * @return array<int, string>
	 */
	private function keysOf(array $entry): array {
		$keys = array_merge(
			array_map('strval', (array)($entry['fields'] ?? [])),
			array_map('strval', array_column((array)($entry['columns'] ?? []), 'field')),
			array_map('strval', (array)(($entry['detail'] ?? [])['fields'] ?? [])),
			array_map('strval', array_keys((array)($entry['fieldConfigs'] ?? []))),
			[(string)($entry['schema'] ?? '')]
		);

		return array_values(array_filter($keys, static fn (string $key): bool => $key !== ''));
	}//end keysOf()

	/**
	 * No collection or action offered to a resident carries a Woo assessment, corpus, redaction or reviewer.
	 *
	 * @return void
	 */
	public function testNoResidentSurfaceCarriesWooDeskWork(): void {
		$provider = new PortalContributionProvider();
		$checked = 0;
		foreach (['citizen', 'client'] as $audience) {
			$contribution = $provider->getContribution(['audience' => $audience]);
			$this->assertNotNull($contribution);
			foreach (array_merge($contribution['collections'], $contribution['actions']) as $entry) {
				foreach ($this->keysOf(entry: $entry) as $key) {
					$checked++;
					foreach (self::DESK_WORDS as $word) {
						$this->assertStringNotContainsStringIgnoringCase(
							$word,
							$key,
							$audience . ' ' . (string)($entry['id'] ?? '?') . ' offers ' . $key . ', which is desk work on a Woo case'
						);
					}
				}
			}
		}

		// A walk that read nothing would pass; the resident surfaces declare dozens of keys.
		$this->assertGreaterThan(40, $checked);
	}//end testNoResidentSurfaceCarriesWooDeskWork()
}//end class
