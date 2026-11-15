<?php

/**
 * Unit tests for CaseRebindGate.
 *
 * 🔴 The gate is the server-side refusal for a rebind: who may make one, onto
 * which case type and status, and with which answers confirmed as dropped.
 * Every refusal is asserted by its rule and status, because a client branches
 * on those and a refusal that changes code silently changes the UI.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Cases\CaseRebindGate;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaseRebindGate.
 *
 * @covers \OCA\Dossiq\Service\Cases\CaseRebindGate
 *
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 * @uses \OCA\Dossiq\Service\Support\SerialisedResult
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class CaseRebindGateTest extends TestCase {

	/**
	 * Group membership.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * The resolver's statuses and results.
	 *
	 * @var CaseTypeResolver&MockObject
	 */
	private CaseTypeResolver&MockObject $resolver;

	/**
	 * Case type rows the object service answers with, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $caseTypes = [];

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->groups = $this->createMock(IGroupManager::class);
		$this->resolver = $this->createMock(CaseTypeResolver::class);
		$this->caseTypes = [];
	}//end setUp()

	/**
	 * The gate over a real store whose object service answers from $caseTypes.
	 *
	 * @return CaseRebindGate The gate.
	 */
	private function gate(): CaseRebindGate {
		$types = &$this->caseTypes;
		$objectService = new class($types) {
			/**
			 * Constructor.
			 *
			 * @param array<string, array<string, mixed>> $rows The rows by id.
			 */
			public function __construct(private array &$rows) {
			}

			/**
			 * Find one row.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string, mixed>|null The row.
			 */
			public function find(string $id, string $register = '', string $schema = ''): ?array {
				return ($this->rows[$id] ?? null);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'reg-1',
				'case_type_schema' => 'schema-ct',
				default => $default,
			}
		);

		return new CaseRebindGate(
			store: new CaseTypeStore($settings),
			resolver: $this->resolver,
			groupManager: $this->groups,
		);
	}//end gate()

	/**
	 * Run a refusal and return the exception, failing when none is thrown.
	 *
	 * @param callable $act The act.
	 *
	 * @return RefusedException The refusal.
	 */
	private function refusal(callable $act): RefusedException {
		try {
			$act();
		} catch (RefusedException $e) {
			return $e;
		}

		self::fail('Expected a RefusedException.');
	}//end refusal()

	/**
	 * Only a coordinator may rebind; nobody ('') is never asked about.
	 *
	 * @return void
	 */
	public function testMayRebindAsksTheCoordinatorGroup(): void {
		$this->groups->expects(self::exactly(2))->method('isInGroup')->willReturnMap([
			['alice', 'dossiq-coordinators', true],
			['bob', 'dossiq-coordinators', false],
		]);
		$gate = $this->gate();

		self::assertTrue($gate->mayRebind(uid: 'alice'));
		self::assertFalse($gate->mayRebind(uid: 'bob'));
		self::assertFalse($gate->mayRebind(uid: ''));
	}//end testMayRebindAsksTheCoordinatorGroup()

	/**
	 * A non-coordinator is refused as forbidden before the reason is read.
	 *
	 * @return void
	 */
	public function testANonCoordinatorIsForbidden(): void {
		$this->groups->method('isInGroup')->willReturn(false);
		$gate = $this->gate();

		$e = $this->refusal(fn () => $gate->assertMayRebind(actorUid: 'bob', reason: ''));

		self::assertSame('rebind-is-for-coordinators', $e->getRule());
		self::assertSame(RefusedException::STATUS_FORBIDDEN, $e->getStatus());
		self::assertSame('Only a case coordinator may change the type of a running case.', $e->getSentence());
	}//end testANonCoordinatorIsForbidden()

	/**
	 * A coordinator must name a reason, and gets it back trimmed.
	 *
	 * @return void
	 */
	public function testACoordinatorNeedsAReasonAndGetsItTrimmed(): void {
		$this->groups->method('isInGroup')->willReturn(true);
		$gate = $this->gate();

		self::assertSame('wrong type', $gate->assertMayRebind(actorUid: 'alice', reason: "  wrong type \n"));

		$e = $this->refusal(fn () => $gate->assertMayRebind(actorUid: 'alice', reason: '   '));
		self::assertSame('rebind-needs-a-reason', $e->getRule());
		self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
	}//end testACoordinatorNeedsAReasonAndGetsItTrimmed()

	/**
	 * A blank target is refused before anything is read.
	 *
	 * @return void
	 */
	public function testABlankTargetIsRefused(): void {
		$gate = $this->gate();

		$e = $this->refusal(fn () => $gate->assertTarget(caseId: 'c-1', case: [], targetCaseTypeId: '  '));

		self::assertSame('rebind-target-missing', $e->getRule());
		self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
	}//end testABlankTargetIsRefused()

	/**
	 * The case's own type is refused, whether stored as an id or a row.
	 *
	 * @return void
	 */
	public function testTheCasesOwnTypeIsRefused(): void {
		$this->caseTypes['ct-1'] = ['id' => 'ct-1'];
		$gate = $this->gate();

		$e = $this->refusal(fn () => $gate->assertTarget(caseId: 'c-1', case: ['caseType' => 'ct-1'], targetCaseTypeId: 'ct-1'));
		self::assertSame('rebind-to-itself', $e->getRule());

		$e = $this->refusal(fn () => $gate->assertTarget(caseId: 'c-1', case: ['caseType' => ['id' => 'ct-1']], targetCaseTypeId: 'ct-1'));
		self::assertSame('rebind-to-itself', $e->getRule());
	}//end testTheCasesOwnTypeIsRefused()

	/**
	 * An unreadable target is refused, naming the case.
	 *
	 * @return void
	 */
	public function testAnUnreadableTargetIsRefusedNamingTheCase(): void {
		$gate = $this->gate();

		$e = $this->refusal(fn () => $gate->assertTarget(caseId: 'c-9', case: ['caseType' => 'ct-1'], targetCaseTypeId: 'ct-gone'));

		self::assertSame('rebind-target-not-found', $e->getRule());
		self::assertSame('That case type could not be read, so case c-9 was not rebound.', $e->getSentence());
		self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
	}//end testAnUnreadableTargetIsRefusedNamingTheCase()

	/**
	 * A draft target is refused; a published one passes.
	 *
	 * @return void
	 */
	public function testADraftTargetIsRefusedAndAPublishedOnePasses(): void {
		$this->caseTypes['ct-draft'] = ['id' => 'ct-draft', 'isDraft' => true];
		$this->caseTypes['ct-live'] = ['id' => 'ct-live', 'isDraft' => false];
		$this->caseTypes['ct-loose'] = ['id' => 'ct-loose', 'isDraft' => 'true'];
		$gate = $this->gate();

		$e = $this->refusal(fn () => $gate->assertTarget(caseId: 'c-1', case: ['caseType' => 'ct-1'], targetCaseTypeId: 'ct-draft'));
		self::assertSame('rebind-target-is-a-draft', $e->getRule());

		// Only a boolean true marks a draft.
		$gate->assertTarget(caseId: 'c-1', case: ['caseType' => 'ct-1'], targetCaseTypeId: 'ct-live');
		$gate->assertTarget(caseId: 'c-1', case: [], targetCaseTypeId: 'ct-loose');
		self::assertSame(['id' => 'ct-live', 'isDraft' => false], $this->caseTypes['ct-live']);
	}//end testADraftTargetIsRefusedAndAPublishedOnePasses()

	/**
	 * A landing status must be named and be one of the target's own.
	 *
	 * @return void
	 */
	public function testTheLandingStatusMustBeTheTargetsOwn(): void {
		$this->resolver->method('statusTypesFor')->willReturnCallback(
			static fn (string $caseTypeId): array => $caseTypeId === 'ct-2'
				? [['id' => 'st-a'], ['@self' => ['id' => 'st-b']]]
				: [['id' => 'st-other']]
		);
		$gate = $this->gate();

		$gate->assertStatus(targetCaseTypeId: 'ct-2', targetStatusId: 'st-a');
		$gate->assertStatus(targetCaseTypeId: 'ct-2', targetStatusId: 'st-b');

		$e = $this->refusal(fn () => $gate->assertStatus(targetCaseTypeId: 'ct-2', targetStatusId: ' '));
		self::assertSame('rebind-needs-a-mapped-status', $e->getRule());
		self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());

		$e = $this->refusal(fn () => $gate->assertStatus(targetCaseTypeId: 'ct-2', targetStatusId: 'st-other'));
		self::assertSame('rebind-status-is-not-the-targets', $e->getRule());
		self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
	}//end testTheLandingStatusMustBeTheTargetsOwn()

	/**
	 * 🔴 Only the same list confirms a drop; a different one is refused, naming what is removed.
	 *
	 * @return void
	 */
	public function testADifferentConfirmedListIsRefused(): void {
		$gate = $this->gate();

		// The same names in any case or order, blanks and non-scalars ignored, pass.
		$gate->assertDropConfirmed(dropped: ['Boomsoort', 'kleur'], confirmed: [' KLEUR ', 'boomsoort', 'kleur', '', null, ['x']]);
		$gate->assertDropConfirmed(dropped: [], confirmed: []);

		$e = $this->refusal(fn () => $gate->assertDropConfirmed(dropped: ['boomsoort', 'kleur'], confirmed: ['boomsoort']));
		self::assertSame('rebind-drop-not-confirmed', $e->getRule());
		self::assertSame(
			'This rebind removes boomsoort, kleur from the case. Check the preview again and confirm what is removed.',
			$e->getSentence()
		);
		self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());

		$e = $this->refusal(fn () => $gate->assertDropConfirmed(dropped: [], confirmed: ['boomsoort']));
		self::assertSame(
			'This rebind removes nothing from the case. Check the preview again and confirm what is removed.',
			$e->getSentence()
		);
	}//end testADifferentConfirmedListIsRefused()

	/**
	 * Missing required fields are refused with the right pronoun; none passes.
	 *
	 * @return void
	 */
	public function testMissingRequiredFieldsAreRefused(): void {
		$gate = $this->gate();

		$gate->assertNothingMissing(missing: []);

		$e = $this->refusal(fn () => $gate->assertNothingMissing(missing: ['bouwjaar']));
		self::assertSame('rebind-missing-required-properties', $e->getRule());
		self::assertSame('The target case type requires bouwjaar in that status, and this case does not carry it.', $e->getSentence());
		self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());

		$e = $this->refusal(fn () => $gate->assertNothingMissing(missing: ['bouwjaar', 'kleur']));
		self::assertSame('The target case type requires bouwjaar, kleur in that status, and this case does not carry them.', $e->getSentence());
	}//end testMissingRequiredFieldsAreRefused()

	/**
	 * A case without a result, or with one the target has, carries it over silently.
	 *
	 * @return void
	 */
	public function testAResultTheTargetHasIsCarried(): void {
		$this->resolver->method('resultTypesFor')->with('ct-2')->willReturn([['id' => 'rs-x'], ['id' => 'rs-ok']]);
		$gate = $this->gate();

		self::assertSame(['carried' => true, 'note' => ''], $gate->resultCompatibility(case: [], targetCaseTypeId: 'ct-2'));
		self::assertSame(
			['carried' => true, 'note' => ''],
			$gate->resultCompatibility(case: ['result' => ['id' => 'rs-ok']], targetCaseTypeId: 'ct-2')
		);
	}//end testAResultTheTargetHasIsCarried()

	/**
	 * A result the target does not have is kept, with a note saying so.
	 *
	 * @return void
	 */
	public function testAResultTheTargetLacksIsNotCarriedAndNoted(): void {
		$this->resolver->method('resultTypesFor')->willReturn([['id' => 'rs-x']]);
		$gate = $this->gate();

		$verdict = $gate->resultCompatibility(case: ['result' => 'rs-old'], targetCaseTypeId: 'ct-2');

		self::assertFalse($verdict['carried']);
		self::assertSame(
			'The result this case was closed with is not a result of the target case type. '
				. 'It stays on the case as a record of what was decided, and it no longer matches the blueprint.',
			$verdict['note']
		);
	}//end testAResultTheTargetLacksIsNotCarriedAndNoted()
}//end class
