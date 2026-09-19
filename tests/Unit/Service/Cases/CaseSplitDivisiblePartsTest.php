<?php

/**
 * What the picker is told it may divide, before the handler chooses.
 *
 * The rule was always enforced on the write. What it could not do was be READ:
 * a handler on a case type that forbids dividing documents ticked one, typed a
 * title, confirmed, and learnt the rule from the refusal. These assertions are
 * about the answer arriving early enough to act on.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
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
 * @spec openspec/changes/split-picker-asks-the-policy/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Cases\CaseSplitExecutor;
use OCA\Dossiq\Service\Cases\CaseSplitPlan;
use OCA\Dossiq\Service\Cases\CaseSplitPolicy;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The parts a split may divide, answered before anything is chosen.
 *
 * @covers \OCA\Dossiq\Service\Cases\CaseSplitExecutor
 * @uses \OCA\Dossiq\Service\Cases\CaseSplitPlan
 * @uses \OCA\Dossiq\Service\Cases\CaseSplitPolicy
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CaseSplitDivisiblePartsTest extends TestCase {
	/**
	 * The store the executor reads.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * One case on one case type.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'caseType', uuid: 'ct-1', row: ['title' => 'Melding openbare ruimte']);
		$this->store->seed(
			schema: 'case',
			uuid: 'case-1',
			row: ['title' => 'Twee klachten op één formulier', 'caseType' => 'ct-1'],
		);
	}//end setUp()

	/**
	 * Scenario: A case type that forbids dividing documents offers no
	 * documents.
	 *
	 * @return void
	 */
	public function testAForbiddenPartIsNotOffered(): void {
		$this->store->seed(
			schema: 'caseType',
			uuid: 'ct-1',
			row: ['title' => 'Ondeelbaar dossier', 'splittableParts' => ['parties', 'tasks']],
		);

		$offered = $this->executor()->divisibleParts(caseId: 'case-1');

		$this->assertSame(['parties', 'tasks'], $offered);
		$this->assertNotContains('documents', $offered);
	}//end testAForbiddenPartIsNotOffered()

	/**
	 * Scenario: A case type that declares nothing offers all three, because
	 * that is what every case type meant before the key existed.
	 *
	 * @return void
	 */
	public function testACaseTypeThatDeclaresNothingOffersAllThree(): void {
		$this->assertSame(
			CaseSplitPolicy::PARTS,
			$this->executor()->divisibleParts(caseId: 'case-1')
		);
	}//end testACaseTypeThatDeclaresNothingOffersAllThree()

	/**
	 * Scenario: An unreadable case type does not invent a restriction.
	 *
	 * 🔴 THE DIRECTION MATTERS AND ONLY HERE. The declaration is an
	 * administrator's restriction on a default that was always permissive, so
	 * failing to read it must not refuse everything: that would turn a broken
	 * reference into a case type nobody can split, with nothing on screen
	 * saying why.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseTypeOffersAllThree(): void {
		$this->store->seed(
			schema: 'case',
			uuid: 'case-1',
			row: ['title' => 'Zaak met kapotte verwijzing', 'caseType' => 'ct-weg'],
		);

		$this->assertSame(
			CaseSplitPolicy::PARTS,
			$this->executor()->divisibleParts(caseId: 'case-1')
		);
	}//end testAnUnreadableCaseTypeOffersAllThree()

	/**
	 * A case that is not there refuses, rather than answering a list a picker
	 * would then draw over nothing.
	 *
	 * @return void
	 */
	public function testACaseThatIsNotThereRefuses(): void {
		$this->expectException(RefusedException::class);

		$this->executor()->divisibleParts(caseId: 'case-weg');
	}//end testACaseThatIsNotThereRefuses()

	/**
	 * The answer is the one the write path enforces, not a second copy: the
	 * same declaration read through the same policy gives the same list.
	 *
	 * @return void
	 */
	public function testTheOfferIsThePolicysOwnAnswer(): void {
		$declared = ['documents'];
		$this->store->seed(
			schema: 'caseType',
			uuid: 'ct-1',
			row: ['title' => 'Alleen stukken', 'splittableParts' => $declared],
		);

		$this->assertSame(
			(new CaseSplitPolicy())->allowedFor(caseType: ['splittableParts' => $declared]),
			$this->executor()->divisibleParts(caseId: 'case-1')
		);
	}//end testTheOfferIsThePolicysOwnAnswer()

	/**
	 * The executor over the in-memory register.
	 *
	 * @return CaseSplitExecutor The service under test.
	 */
	private function executor(): CaseSplitExecutor {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_type_schema' => 'caseType',
					'case_document_schema' => 'caseDocument',
					'role_schema' => 'role',
				];

				return ($map[$key] ?? $default);
			}
		);

		return new CaseSplitExecutor(
			settingsService: $settings,
			policy: new CaseSplitPolicy(),
			plan: new CaseSplitPlan(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end executor()
}//end class
