<?php

/**
 * Refusal as the sixth outcome of routing.
 *
 * Awb 2:3 is the requirement being driven here, not a niceness: a refused case
 * goes to the department and role the case type names, and it stays in the
 * register. So the test asserts on what the store holds AFTER the refusal, not
 * only on the record the method answered with. A method that returns a tidy
 * record while writing nothing is exactly the lost case this outcome exists to
 * prevent.
 *
 * The case type with no declared destination is driven too, because "refused to
 * nowhere" and "not refused" must not be the same outcome.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Routing
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
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use DateTime;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Routing\RefusalOutcome;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An in-memory case store with the PATCH seam the writer prefers.
 *
 * `patchObject` is implemented on purpose: the fallback path in
 * {@see \OCA\Dossiq\Service\Support\SearchesObjects} re-reads and full-saves,
 * and testing against the fallback would hide a clobber the real seam prevents.
 */
class RefusalCaseStore {

	/**
	 * Stored cases, keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $cases = [];

	/**
	 * Find one case.
	 *
	 * @param string $id       The case id.
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 *
	 * @return array<string, mixed>|null The case, or null.
	 */
	public function find(string $id, string $register = '', string $schema = ''): ?array {
		return ($this->cases[$id] ?? null);
	}//end find()

	/**
	 * Apply a partial change to one case.
	 *
	 * @param string               $objectId The case id.
	 * @param array<string, mixed> $data     The fields to apply.
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 *
	 * @return array<string, mixed> The case as it now stands.
	 */
	public function patchObject(
		string $objectId,
		array $data,
		string $register = '',
		string $schema = '',
	): array {
		$this->cases[$objectId] = array_merge(($this->cases[$objectId] ?? []), $data);

		return $this->cases[$objectId];
	}//end patchObject()
}//end class

/**
 * Refusing a case to a declared department and role, and keeping it findable.
 *
 * @covers \OCA\Dossiq\Service\Routing\RefusalOutcome
 *
 * @uses \OCA\Dossiq\Service\CaseFieldWriter
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class RefusalOutcomeTest extends TestCase {

	/**
	 * The in-memory case store.
	 *
	 * @var RefusalCaseStore
	 */
	private RefusalCaseStore $store;

	/**
	 * What the case type declares.
	 *
	 * @var array<string, mixed>
	 */
	private array $caseType = [];

	/**
	 * Set up the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new RefusalCaseStore();
		$this->caseType = [
			'title' => 'Handhavingsverzoek',
			'refusalDestination' => ['department' => 'Juridische Zaken', 'role' => 'intake'],
		];
	}//end setUp()

	/**
	 * The outcome, wired against the in-memory store.
	 *
	 * @param boolean $configured Whether the register resolves.
	 *
	 * @return RefusalOutcome The outcome under test.
	 */
	private function outcome(bool $configured = true): RefusalOutcome {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(($configured === true) ? $this->store : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key) use ($configured): string {
				if ($configured === false) {
					return '';
				}

				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => 'case',
					default => '',
				};
			}
		);

		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(fn (): array => $this->caseType);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-14 11:00:00'));

		return new RefusalOutcome(
			settingsService: $settings,
			caseTypeResolver: $resolver,
			writer: new CaseFieldWriter(),
			time: $time,
			logger: new NullLogger(),
		);
	}//end outcome()

	/**
	 * Seed one case in the store.
	 *
	 * @param array<string, mixed> $overrides Fields to set on the case.
	 *
	 * @return string The case id.
	 */
	private function seedCase(array $overrides = []): string {
		$case = array_merge(
			[
				'id' => 'case-1',
				'caseType' => 'ct-1',
				'identifier' => '2026-0042',
				'title' => 'Klacht over een boom',
			],
			$overrides
		);

		$this->store->cases[(string)$case['id']] = $case;

		return (string)$case['id'];
	}//end seedCase()

	/**
	 * A refused case lands somewhere, per Awb 2:3.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-refused-intake-goes-to-a-named-department-and-role-req-triage-04
	 */
	public function testARefusedCaseLandsAtItsDeclaredDestination(): void {
		$caseId = $this->seedCase();

		$record = $this->outcome()->refuse(
			caseId: $caseId,
			reason: 'Dit is een melding voor de provincie.',
			refusedBy: 'jdevries'
		);

		$this->assertSame('Juridische Zaken', $record['department']);
		$this->assertSame('intake', $record['role']);
		$this->assertSame('Juridische Zaken', $this->store->cases[$caseId]['assignedGroup']);
	}//end testARefusedCaseLandsAtItsDeclaredDestination()

	/**
	 * The reason and the refuser are recorded on the case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-refused-intake-goes-to-a-named-department-and-role-req-triage-04
	 */
	public function testTheReasonAndTheRefuserAreRecorded(): void {
		$caseId = $this->seedCase();

		$this->outcome()->refuse(
			caseId: $caseId,
			reason: 'Dit is een melding voor de provincie.',
			refusedBy: 'jdevries'
		);

		$stored = $this->store->cases[$caseId]['intakeRefusal'];
		$this->assertTrue($stored['refused']);
		$this->assertSame('Dit is een melding voor de provincie.', $stored['reason']);
		$this->assertSame('jdevries', $stored['refusedBy']);
		$this->assertSame('2026-09-14T11:00:00+00:00', $stored['refusedAt']);
	}//end testTheReasonAndTheRefuserAreRecorded()

	/**
	 * A refused case is not a lost case.
	 *
	 * The case stays in the store, keeps its number, and is not hidden from
	 * the lists search reads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-refused-intake-goes-to-a-named-department-and-role-req-triage-04
	 */
	public function testARefusedCaseStaysFindable(): void {
		$caseId = $this->seedCase();

		$this->outcome()->refuse(caseId: $caseId, reason: 'Niet voor ons.', refusedBy: 'jdevries');

		$this->assertArrayHasKey($caseId, $this->store->cases);
		$this->assertSame('2026-0042', $this->store->cases[$caseId]['identifier']);
		$this->assertArrayNotHasKey('statusHiddenInLists', $this->store->cases[$caseId]);
	}//end testARefusedCaseStaysFindable()

	/**
	 * Refusal with no declared destination is refused, saying so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-refused-intake-goes-to-a-named-department-and-role-req-triage-04
	 */
	public function testRefusalWithNoDestinationIsItselfRefused(): void {
		$this->caseType = ['title' => 'Melding'];
		$caseId = $this->seedCase();

		try {
			$this->outcome()->refuse(caseId: $caseId, reason: 'Niet voor ons.', refusedBy: 'jdevries');
			$this->fail('The refusal should have been refused.');
		} catch (RefusedException $e) {
			$this->assertSame(RefusalOutcome::RULE_NO_DESTINATION, $e->getRule());
			$this->assertStringContainsString('where a refused case goes', $e->getSentence());
		}

		$this->assertArrayNotHasKey('intakeRefusal', $this->store->cases[$caseId]);
	}//end testRefusalWithNoDestinationIsItselfRefused()

	/**
	 * A half-declared destination cannot refuse either.
	 *
	 * A department with no role is a case sitting in a team's tray with
	 * nobody's name on it, which is the lost case with extra steps.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-refused-intake-goes-to-a-named-department-and-role-req-triage-04
	 */
	public function testAHalfDeclaredDestinationCannotRefuse(): void {
		$this->caseType = ['refusalDestination' => ['department' => 'Juridische Zaken', 'role' => '']];

		$this->assertFalse($this->outcome()->canRefuse(caseType: $this->caseType));

		$this->expectException(RefusedException::class);
		$this->outcome()->refuse(
			caseId: $this->seedCase(),
			reason: 'Niet voor ons.',
			refusedBy: 'jdevries'
		);
	}//end testAHalfDeclaredDestinationCannotRefuse()

	/**
	 * A refusal without a reason is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-refused-intake-goes-to-a-named-department-and-role-req-triage-04
	 */
	public function testARefusalWithoutAReasonIsRefused(): void {
		$caseId = $this->seedCase();

		try {
			$this->outcome()->refuse(caseId: $caseId, reason: '   ', refusedBy: 'jdevries');
			$this->fail('The refusal should have been refused.');
		} catch (RefusedException $e) {
			$this->assertSame(RefusalOutcome::RULE_NO_REASON, $e->getRule());
		}

		$this->assertArrayNotHasKey('intakeRefusal', $this->store->cases[$caseId]);
	}//end testARefusalWithoutAReasonIsRefused()

	/**
	 * With no register there is no refusal, and the answer says neither yes nor no.
	 *
	 * 🔴 THE POINT OF THIS TEST. An earlier shape logged the failed write and
	 * answered the caller with the record anyway, so the surface reported a
	 * refusal that had not happened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-refused-intake-goes-to-a-named-department-and-role-req-triage-04
	 */
	public function testAnUnconfiguredRegisterRefusesRatherThanReportingSuccess(): void {
		$this->seedCase();

		try {
			$this->outcome(configured: false)->refuse(
				caseId: 'case-1',
				reason: 'Niet voor ons.',
				refusedBy: 'jdevries'
			);
			$this->fail('The refusal should have been refused.');
		} catch (RefusedException $e) {
			$this->assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
		}
	}//end testAnUnconfiguredRegisterRefusesRatherThanReportingSuccess()

	/**
	 * A case nothing can read is not refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function testACaseThatCannotBeReadIsNotRefused(): void {
		try {
			$this->outcome()->refuse(caseId: 'no-such-case', reason: 'Niet voor ons.', refusedBy: 'jdevries');
			$this->fail('The refusal should have been refused.');
		} catch (RefusedException $e) {
			$this->assertSame(RefusalOutcome::RULE_CASE_UNREADABLE, $e->getRule());
		}

		$this->assertArrayNotHasKey('no-such-case', $this->store->cases);
	}//end testACaseThatCannotBeReadIsNotRefused()

	/**
	 * A case that was never refused reads as not refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function testACaseThatWasNeverRefusedReadsEmpty(): void {
		$this->assertSame([], $this->outcome()->refusalOn(case: ['title' => 'Melding']));
		$this->assertSame(
			[],
			$this->outcome()->refusalOn(case: ['intakeRefusal' => ['refused' => false]])
		);
	}//end testACaseThatWasNeverRefusedReadsEmpty()
}//end class
