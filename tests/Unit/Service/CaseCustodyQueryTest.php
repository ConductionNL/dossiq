<?php

/**
 * Reading the chain back: who held a case, and what a unit held.
 *
 * The scenario these tests stand in for is "who held this case in March", which
 * the spec excludes from e2e because it is a reader rather than a surface. The
 * property worth protecting is that the answer is ONE unit and not two: a move
 * closes one holding and opens the next at the SAME moment, so the boundary has
 * to be half-open or the transfer date returns both.
 *
 * MUTATION-CHECKED 2026-09-18: dropping the `$from > $moment` guard from
 * CaseCustodyQuery::holderOn() reddens three of these on their unit
 * assertions, because a holding that has not begun yet then answers for a date
 * before it. Restored after.
 *
 * ⚠️ The half-open boundary (`$until <= $moment`) is NOT covered by a mutation
 * here, and the note says so rather than implying it is: `holderOn()` keeps the
 * LAST matching holding, so the newer one wins even when the older also
 * matches, and flipping `<=` to `<` leaves every assertion green. The boundary
 * is documented on the method and belt-and-braces in the code; a test claiming
 * to watch it would be a test that cannot fail.
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
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Custody\CaseCustodyChain;
use OCA\Dossiq\Service\Custody\CaseCustodyQuery;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who held the case on a date, and which cases a unit held in a period.
 *
 * @covers \OCA\Dossiq\Service\Custody\CaseCustodyQuery
 * @uses \OCA\Dossiq\Service\Custody\CaseCustodyChain
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */
class CaseCustodyQueryTest extends TestCase {

	/**
	 * The store every service under test reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * A case that changed hands three times in a year.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();

		$chain = $this->chain();
		$chain->begin(
			caseId: 'case-1',
			organisationUnit: 'vergunningen',
			handler: 'jan',
			from: '2026-01-06T09:00:00+01:00',
			reason: 'Registered',
			movedBy: 'jan',
		);
		$chain->move(
			caseId: 'case-1',
			organisationUnit: 'toezicht',
			handler: 'sofie',
			reason: 'Handhaving',
			movedBy: 'jan',
			at: '2026-03-10T10:00:00+01:00',
		);
		$chain->move(
			caseId: 'case-1',
			organisationUnit: 'juridisch',
			handler: '',
			reason: 'Bezwaar',
			movedBy: 'sofie',
			at: '2026-06-01T10:00:00+02:00',
		);
	}//end setUp()

	/**
	 * The question the record exists for returns exactly one unit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function testWhoHeldTheCaseInMarchIsOneAnswer(): void {
		$holding = $this->query()->holderOn(caseId: 'case-1', on: '2026-03-15T12:00:00+01:00');

		self::assertNotNull($holding, 'A date inside the case\'s life always has a holder.');
		self::assertSame('toezicht', $holding['organisationUnit']);
		self::assertSame('Handhaving', $holding['reason'], 'The answer comes from the holding, not from an audit diff.');
	}//end testWhoHeldTheCaseInMarchIsOneAnswer()

	/**
	 * On the day of a transfer the case belongs to the unit that took it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function testTheTransferDateBelongsToTheUnitThatTookTheCase(): void {
		$holding = $this->query()->holderOn(caseId: 'case-1', on: '2026-03-10T10:00:00+01:00');

		self::assertNotNull($holding);
		self::assertSame(
			'toezicht',
			$holding['organisationUnit'],
			'The holding that BEGAN at that instant owns it; the one that ended does not, or the day reads as two units.',
		);
	}//end testTheTransferDateBelongsToTheUnitThatTookTheCase()

	/**
	 * A date before the case existed has no holder at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function testADateBeforeTheCaseHasNoHolder(): void {
		self::assertNull($this->query()->holderOn(caseId: 'case-1', on: '2025-12-31T23:59:59+01:00'));
	}//end testADateBeforeTheCaseHasNoHolder()

	/**
	 * The open holding answers every date after the last move.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function testTheOpenHoldingAnswersTheFuture(): void {
		$holding = $this->query()->holderOn(caseId: 'case-1', on: '2027-01-01T00:00:00+01:00');

		self::assertNotNull($holding);
		self::assertSame('juridisch', $holding['organisationUnit']);
		self::assertTrue($holding['open']);
	}//end testTheOpenHoldingAnswersTheFuture()

	/**
	 * A unit's holdings in a window are the ones that overlap it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function testWhatAUnitHeldLastQuarterIsAQuery(): void {
		$query = $this->query();

		$q1 = $query->heldBy(organisationUnit: 'toezicht', from: '2026-01-01', to: '2026-03-31');
		self::assertCount(1, $q1, 'Toezicht held the case from 10 March, which is inside Q1.');
		self::assertSame('case-1', $q1[0]['caseId']);

		$q4 = $query->heldBy(organisationUnit: 'toezicht', from: '2026-10-01', to: '2026-12-31');
		self::assertSame([], $q4, 'Toezicht let go of it in June, so it held nothing in Q4.');

		$open = $query->heldBy(organisationUnit: 'juridisch', from: '2026-10-01', to: '2026-12-31');
		self::assertCount(1, $open, 'An open holding has no end, so it counts in every later window.');
	}//end testWhatAUnitHeldLastQuarterIsAQuery()

	/**
	 * The query under test.
	 *
	 * @return CaseCustodyQuery The query.
	 */
	private function query(): CaseCustodyQuery {
		return new CaseCustodyQuery(
			chain: $this->chain(),
			settingsService: $this->settings(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end query()

	/**
	 * The chain the query reads from.
	 *
	 * @return CaseCustodyChain The chain.
	 */
	private function chain(): CaseCustodyChain {
		return new CaseCustodyChain(
			settingsService: $this->settings(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end chain()

	/**
	 * A settings service that answers the in-memory store and the slugs it holds.
	 *
	 * @return SettingsService The settings service.
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_custody_schema' => 'caseCustody',
				];

				return ($map[$key] ?? $default);
			}
		);

		return $settings;
	}//end settings()
}//end class
