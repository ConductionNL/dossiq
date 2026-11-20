<?php

/**
 * ZtcChange Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZgwRuleResults;
use OCA\Dossiq\Service\Zgw\ZtcConceptRules;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The change the concept rules judge carries the resource, the action and the body each to the rule that reads it.
 *
 * Every pair below differs in exactly one of the three, and the answer differs with it:
 * a ZtcChange that dropped or swapped a field would answer both alike.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZtcChange
 * @covers \OCA\Dossiq\Service\Zgw\ZtcConceptRules
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
final class ZtcChangeTest extends TestCase {
	/**
	 * The detail of the refusal for this change to a published type or a published zaaktype's sub-resource, or null.
	 *
	 * @param string $resource The resource.
	 * @param string $action   The action.
	 * @param array  $body     The body.
	 *
	 * @return string|null
	 */
	private function refusal(string $resource, string $action, array $body = []): ?string {
		$rules = new ZtcConceptRules(results: new ZgwRuleResults(logger: $this->createMock(LoggerInterface::class)));

		return $rules->checkConceptProtection(
			resource: $resource,
			action: $action,
			body: $body,
			existingObject: ['isDraft' => false],
			parentCaseTypeDraft: false
		)['detail'] ?? null;
	}//end refusal()

	/**
	 * The resource picks the rule and names the type in the refusal.
	 *
	 * @return void
	 */
	public function testTheResourceReachesTheRule(): void {
		$this->assertNull($this->refusal(resource: 'roltypen', action: 'create'));
		$this->assertSame(
			'Het is niet toegestaan om typen van een gepubliceerd zaaktype aan te passen.',
			$this->refusal(resource: 'resultaattypen', action: 'create')
		);
		$this->assertStringContainsString('een besluittype met concept=false', (string)$this->refusal(resource: 'besluittypen', action: 'destroy'));
	}//end testTheResourceReachesTheRule()

	/**
	 * The action decides whether a sub-resource change passes, and is named in the refusal of a published type.
	 *
	 * @return void
	 */
	public function testTheActionReachesTheRule(): void {
		$this->assertNull($this->refusal(resource: 'statustypen', action: 'create'));
		$this->assertNotNull($this->refusal(resource: 'statustypen', action: 'patch'));
		$this->assertNotSame(
			$this->refusal(resource: 'zaaktypen', action: 'update'),
			$this->refusal(resource: 'zaaktypen', action: 'destroy')
		);
	}//end testTheActionReachesTheRule()

	/**
	 * The body decides whether a patch of a published type only ends its validity.
	 *
	 * @return void
	 */
	public function testTheBodyReachesTheRule(): void {
		$this->assertNull($this->refusal(resource: 'zaaktypen', action: 'patch', body: ['endValidity' => '2026-12-31']));
		$this->assertNotNull($this->refusal(resource: 'zaaktypen', action: 'patch', body: ['endValidity' => '2026-12-31', 'title' => 'x']));
	}//end testTheBodyReachesTheRule()
}//end class
