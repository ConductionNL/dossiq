<?php

/**
 * What a case type's role rules do to the live case schema's properties.
 *
 * Every assertion here is about a failure that is invisible from the editor and
 * silent at runtime. A schema every case type writes to; a rule that can be
 * added but never taken off again; a grant the register declared that a
 * withdrawal takes with it. None of the three raises anything: the field simply
 * starts, or stops, being in the answer.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
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
 *
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\CaseFieldRoleProjector;
use OCA\Dossiq\Service\Access\FieldRoleRuleDeclaration;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Access\CaseFieldRoleProjector
 *
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */
class CaseFieldRoleProjectorTest extends TestCase {

	/**
	 * A projector with no live schema behind it.
	 *
	 * The merge and the ledger are pure, so they are exercised directly. The
	 * write half needs a schema mapper and is covered by the integration the
	 * publish service already has.
	 *
	 * @return CaseFieldRoleProjector The projector.
	 */
	private function projector(): CaseFieldRoleProjector {
		return new CaseFieldRoleProjector(
			store: $this->createMock(CaseTypeStore::class),
			declaration: new FieldRoleRuleDeclaration(),
			slugs: $this->createMock(SchemaSlugResolver::class),
			fragments: new RegisterFragmentMerger(),
			container: $this->createMock(ContainerInterface::class),
			logger: new NullLogger(),
		);
	}//end projector()

	/**
	 * Publishing one case type leaves another case type's grants alone.
	 *
	 * Every case type on the instance writes to the same `case` schema. A
	 * writer that replaced a property's authorization would unpublish the other
	 * types' rules, and OpenRegister would simply start returning the field
	 * again.
	 *
	 * @return void
	 */
	public function testAnotherCaseTypesGrantsSurviveAPublish(): void {
		$projector = $this->projector();

		$ledger = $projector->ledgerWith(
			ledger: ['other-ct' => ['qualityScore' => ['read' => [['group' => 'dossiq-quality']]]]],
			caseTypeId: 'mine',
			own: ['confidentiality' => ['update' => [['group' => 'dossiq-coordinators']]]]
		);

		$properties = $projector->propertiesWith(
			properties: ['qualityScore' => ['type' => 'number'], 'confidentiality' => ['type' => 'string']],
			base: [],
			ledger: $ledger
		);

		$this->assertSame(
			['read' => [['group' => 'dossiq-quality']]],
			$properties['qualityScore']['authorization']
		);
		$this->assertSame(
			['update' => [['group' => 'dossiq-coordinators']]],
			$properties['confidentiality']['authorization']
		);
	}//end testAnotherCaseTypesGrantsSurviveAPublish()

	/**
	 * Two case types restricting one field are published as one block.
	 *
	 * @return void
	 */
	public function testTwoCaseTypesOnOneFieldMergeTheirGrants(): void {
		$properties = $this->projector()->propertiesWith(
			properties: ['qualityScore' => ['type' => 'number']],
			base: [],
			ledger: [
				'a' => ['qualityScore' => ['read' => [['group' => 'dossiq-quality']]]],
				'b' => ['qualityScore' => ['read' => [['group' => 'dossiq-coordinators']]]],
			]
		);

		$this->assertSame(
			['read' => [['group' => 'dossiq-quality'], ['group' => 'dossiq-coordinators']]],
			$properties['qualityScore']['authorization']
		);
	}//end testTwoCaseTypesOnOneFieldMergeTheirGrants()

	/**
	 * A case type that withdraws its last rule loses its ledger entry.
	 *
	 * @return void
	 */
	public function testAWithdrawnRuleLosesItsLedgerEntry(): void {
		$ledger = $this->projector()->ledgerWith(
			ledger: [
				'mine' => ['qualityScore' => ['read' => [['group' => 'dossiq-quality']]]],
				'other' => ['statutoryTerm' => ['update' => [['group' => 'dossiq-coordinators']]]],
			],
			caseTypeId: 'mine',
			own: []
		);

		$this->assertSame(['other'], array_keys($ledger));
	}//end testAWithdrawnRuleLosesItsLedgerEntry()

	/**
	 * A withdrawn rule gives the field back to everyone.
	 *
	 * The `authorization` key is removed rather than emptied. `read: []` is a
	 * non-empty authorization block holding nobody, so leaving one behind would
	 * strip the field for every non-administrator while nothing declares a rule.
	 *
	 * @return void
	 */
	public function testAWithdrawnRuleRemovesTheAuthorizationKey(): void {
		// 🔴 THE REGISTER DECLARES NOTHING ABOUT THIS FIELD, WHICH IS THE WHOLE
		// POINT. An earlier version of this test named the field in `base` to
		// get it visited, and passed over a projector that could add a rule and
		// never take it off: a withdrawal removes the field from the ledger, so
		// a loop over the ledger alone never reaches the property again. The
		// PREVIOUS ledger is what says the field was ours to clear.
		$properties = $this->projector()->propertiesWith(
			properties: ['qualityScore' => ['type' => 'number', 'authorization' => ['read' => [['group' => 'x']]]]],
			base: [],
			ledger: [],
			previous: ['mine' => ['qualityScore' => ['read' => [['group' => 'x']]]]]
		);

		$this->assertSame(['type' => 'number'], $properties['qualityScore']);
	}//end testAWithdrawnRuleRemovesTheAuthorizationKey()

	/**
	 * A withdrawal does not take the register's own grant with it.
	 *
	 * @return void
	 */
	public function testAWithdrawalLeavesTheRegistersGrantStanding(): void {
		$properties = $this->projector()->propertiesWith(
			properties: ['riskAssessment' => ['type' => 'object']],
			base: ['riskAssessment' => ['read' => [['group' => 'dossiq-risk-assessment']]]],
			ledger: [],
			previous: ['mine' => ['riskAssessment' => ['read' => [['group' => 'dossiq-quality']]]]]
		);

		$this->assertSame(
			['read' => [['group' => 'dossiq-risk-assessment']]],
			$properties['riskAssessment']['authorization']
		);
	}//end testAWithdrawalLeavesTheRegistersGrantStanding()

	/**
	 * A field another case type still owns is not cleared by one withdrawal.
	 *
	 * @return void
	 */
	public function testAFieldAnotherCaseTypeStillOwnsIsNotCleared(): void {
		$properties = $this->projector()->propertiesWith(
			properties: ['qualityScore' => ['type' => 'number']],
			base: [],
			ledger: ['other' => ['qualityScore' => ['read' => [['group' => 'dossiq-quality']]]]],
			previous: [
				'mine' => ['qualityScore' => ['read' => [['group' => 'dossiq-coordinators']]]],
				'other' => ['qualityScore' => ['read' => [['group' => 'dossiq-quality']]]],
			]
		);

		$this->assertSame(
			['read' => [['group' => 'dossiq-quality']]],
			$properties['qualityScore']['authorization']
		);
	}//end testAFieldAnotherCaseTypeStillOwnsIsNotCleared()

	/**
	 * The register's own grants survive a case type withdrawing its rule.
	 *
	 * `riskAssessment` is declared restricted in the register JSON, not by any
	 * case type. A projector that treated the live block as its own would take
	 * that grant off the moment a case type happened to name the same group,
	 * and the assessment would become readable by everybody.
	 *
	 * @return void
	 */
	public function testTheRegistersOwnGrantSurvivesAWithdrawal(): void {
		$base = ['riskAssessment' => ['read' => [['group' => 'dossiq-risk-assessment']]]];

		$properties = $this->projector()->propertiesWith(
			properties: ['riskAssessment' => ['type' => 'object']],
			base: $base,
			ledger: []
		);

		$this->assertSame(
			['read' => [['group' => 'dossiq-risk-assessment']]],
			$properties['riskAssessment']['authorization']
		);
	}//end testTheRegistersOwnGrantSurvivesAWithdrawal()

	/**
	 * A case type naming the register's group adds to it rather than replacing it.
	 *
	 * @return void
	 */
	public function testACaseTypeAddsToTheRegistersGrant(): void {
		$properties = $this->projector()->propertiesWith(
			properties: ['riskAssessment' => ['type' => 'object']],
			base: ['riskAssessment' => ['read' => [['group' => 'dossiq-risk-assessment']]]],
			ledger: ['mine' => ['riskAssessment' => ['read' => [['group' => 'dossiq-quality']]]]]
		);

		$this->assertSame(
			['read' => [['group' => 'dossiq-risk-assessment'], ['group' => 'dossiq-quality']]],
			$properties['riskAssessment']['authorization']
		);
	}//end testACaseTypeAddsToTheRegistersGrant()

	/**
	 * A rule naming a property the schema does not declare is left unpublished.
	 *
	 * OpenRegister refuses a whole schema save over an authorization block on a
	 * property it cannot find, so one stale rule would make every other rule on
	 * the schema unpublishable.
	 *
	 * @return void
	 */
	public function testARuleOnAnUndeclaredPropertyIsLeftUnpublished(): void {
		$properties = $this->projector()->propertiesWith(
			properties: ['qualityScore' => ['type' => 'number']],
			base: [],
			ledger: ['mine' => ['fieldThatWentAway' => ['read' => [['group' => 'dossiq-quality']]]]]
		);

		$this->assertSame(['qualityScore' => ['type' => 'number']], $properties);
	}//end testARuleOnAnUndeclaredPropertyIsLeftUnpublished()

	/**
	 * A property nothing declares a rule about is not touched.
	 *
	 * @return void
	 */
	public function testAnUnrelatedPropertyIsNotTouched(): void {
		$properties = $this->projector()->propertiesWith(
			properties: [
				'title' => ['type' => 'string'],
				'apiToken' => ['type' => 'string', 'authorization' => ['read' => [['group' => 'somebody']]]],
			],
			base: [],
			ledger: []
		);

		$this->assertSame(
			['read' => [['group' => 'somebody']]],
			$properties['apiToken']['authorization']
		);
		$this->assertSame(['type' => 'string'], $properties['title']);
	}//end testAnUnrelatedPropertyIsNotTouched()

	/**
	 * The register base is read from the shipped JSON, fragments and all.
	 *
	 * This is the one assertion that reads the real register rather than a
	 * fixture, because the base is what keeps a case type's withdrawal from
	 * taking the register's own grant with it, and a base that silently read
	 * nothing would pass every other test in this file.
	 *
	 * @return void
	 */
	public function testTheRegisterBaseCarriesTheShippedGrants(): void {
		$base = $this->projector()->registerBase();

		$this->assertArrayHasKey('riskAssessment', $base);
		$this->assertSame(
			[['group' => 'dossiq-risk-assessment']],
			$base['riskAssessment']['read']
		);
	}//end testTheRegisterBaseCarriesTheShippedGrants()

	/**
	 * A reapply leaves the ledger exactly as it stands.
	 *
	 * @return void
	 */
	public function testAReapplyDoesNotChangeTheLedger(): void {
		$ledger = ['mine' => ['qualityScore' => ['read' => [['group' => 'dossiq-quality']]]]];

		$this->assertSame(
			$ledger,
			$this->projector()->ledgerWith(ledger: $ledger, caseTypeId: '', own: [])
		);
	}//end testAReapplyDoesNotChangeTheLedger()
}//end class
