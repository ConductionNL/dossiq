<?php

/**
 * Every advice request in the mock register leads to a contested decision with an author outside its panel.
 *
 * The panel check follows bacAdviceRequest.bezwaar -> objectionProceeding.case ->
 * the objection filed on that case -> objection.contestedDecision, and refuses
 * deliberation when that chain breaks or the decision has no author. The demo
 * data must survive that check, so every link and the author are pinned here.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
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
 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class MockBacAdviceChainsResolveTest extends TestCase {
	/**
	 * The mock register's objects, grouped by schema and keyed by id.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function objectsBySchema(): array {
		$descriptor = json_decode(
			(string) file_get_contents(dirname(__DIR__, 3).'/lib/Settings/dossiq_mock_register.json'),
			true
		);
		$bySchema = [];
		foreach ($descriptor['components']['objects'] ?? [] as $object) {
			$self = $object['@self'] ?? [];
			$bySchema[(string) ($self['schema'] ?? '')][(string) ($self['id'] ?? '')] = $object;
		}

		return $bySchema;
	}//end objectsBySchema()

	/**
	 * Each advice request resolves to a decision whose author sits on neither its panel nor its committee.
	 *
	 * @return void
	 */
	public function testEveryAdviceRequestResolvesToADecisionWithAnIndependentAuthor(): void {
		$objects  = $this->objectsBySchema();
		$requests = $objects['bacAdviceRequest'] ?? [];
		$this->assertNotSame(expected: [], actual: $requests, message: 'the mock register holds advice requests');

		foreach ($requests as $id => $request) {
			$proceeding = $objects['objectionProceeding'][(string) ($request['bezwaar'] ?? '')] ?? null;
			$this->assertIsArray(actual: $proceeding, message: $id.': bezwaar resolves to an objection proceeding');

			$caseId = (string) ($proceeding['case'] ?? '');
			$this->assertArrayHasKey(key: $caseId, array: $objects['case'] ?? [], message: $id.': the proceeding names a case');

			$filed = array_values(
				array_filter(
					$objects['objection'] ?? [],
					static fn (array $objection): bool => ($objection['case'] ?? '') === $caseId
				)
			);
			$this->assertNotSame(expected: [], actual: $filed, message: $id.': an objection is filed on case '.$caseId);
			$this->assertSame(
				expected: (string) $filed[0]['@self']['id'],
				actual: (string) ($proceeding['objection'] ?? ''),
				message: $id.': the proceeding names the objection filed on its case'
			);

			$decision = $objects['decision'][(string) ($filed[0]['contestedDecision'] ?? '')] ?? null;
			$this->assertIsArray(actual: $decision, message: $id.': the objection contests a decision in the register');

			$author = (string) ($decision['decidedBy'] ?? '');
			$this->assertNotSame(expected: '', actual: $author, message: $id.': the contested decision has an author');

			$committee = $objects['bezwaaradviescommissie'][(string) ($request['committee'] ?? '')] ?? [];
			$members   = array_merge(
				array_map('strval', $request['panel'] ?? []),
				array_map(static fn (array $member): string => (string) ($member['uid'] ?? ''), $committee['members'] ?? [])
			);
			$this->assertNotContains(needle: $author, haystack: $members, message: $id.': the author is not on the panel');
		}//end foreach
	}//end testEveryAdviceRequestResolvesToADecisionWithAnIndependentAuthor()

	/**
	 * No advice request is seeded past deliberation: only dossiq's own status
	 * transition moves a request to advice-issued or inadmissible.
	 *
	 * @return void
	 */
	public function testNoAdviceRequestIsSeededPastDeliberation(): void {
		foreach ($this->objectsBySchema()['bacAdviceRequest'] ?? [] as $id => $request) {
			$this->assertNotContains(
				needle: (string) ($request['status'] ?? ''),
				haystack: ['advice-issued', 'inadmissible'],
				message: $id.': seeded in a status only the status transition may set'
			);
		}
	}//end testNoAdviceRequestIsSeededPastDeliberation()
}//end class
