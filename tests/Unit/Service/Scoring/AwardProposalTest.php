<?php

/**
 * The proposed award of a tender case is held on its bids, one per lot.
 *
 * @category  Tests
 * @package   OCA\Dossiq\Tests\Unit\Service\Scoring
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Scoring;

use OCA\Dossiq\Service\Scoring\AwardProposal;
use OCA\Dossiq\Service\Scoring\ScoringRecords;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * REQ-TND-CASE-01: the buyer proposes the award.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Scoring
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */
class AwardProposalTest extends TestCase {

	/**
	 * The fake register.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * A tender with two lots: lot 1 has three bids, one still received; lot 2 one bid.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new AnonymousRefusingRegister(actor: fn (): string => 'eva');
		$this->bid(id: 'bid-a', state: 'evaluated', lot: 'lot-1');
		$this->bid(id: 'bid-b', state: 'admissible', lot: 'lot-1');
		$this->bid(id: 'bid-c', state: 'received', lot: 'lot-1');
		$this->bid(id: 'bid-d', state: 'evaluated', lot: 'lot-2');
	}//end setUp()

	/**
	 * Proposing a bid marks it, and nothing else changes.
	 *
	 * @return void
	 */
	public function testTheProposedBidHoldsTheProposal(): void {
		$result = $this->proposal()->propose(caseId: 'tender-1', submissionId: ' bid-a ');

		$this->assertSame(expected: ['proposed' => 'bid-a', 'released' => []], actual: $result);
		$this->assertSame(expected: 'proposed-award', actual: $this->state(id: 'bid-a'));
		$this->assertSame(expected: 'admissible', actual: $this->state(id: 'bid-b'));
		$this->assertSame(expected: 80000, actual: $this->register->row(schema: 'bid', id: 'bid-a')['priceAmount'], message: 'only the state is written');
	}//end testTheProposedBidHoldsTheProposal()

	/**
	 * Proposing another bid of the same lot releases the earlier one; another lot keeps its own.
	 *
	 * @return void
	 */
	public function testOneProposalPerLot(): void {
		$this->proposal()->propose(caseId: 'tender-1', submissionId: 'bid-a');
		$this->proposal()->propose(caseId: 'tender-1', submissionId: 'bid-d');
		$result = $this->proposal()->propose(caseId: 'tender-1', submissionId: 'bid-b');

		$this->assertSame(expected: ['proposed' => 'bid-b', 'released' => ['bid-a']], actual: $result);
		$this->assertSame(expected: 'evaluated', actual: $this->state(id: 'bid-a'));
		$this->assertSame(expected: 'proposed-award', actual: $this->state(id: 'bid-b'));
		$this->assertSame(expected: 'proposed-award', actual: $this->state(id: 'bid-d'), message: 'the other lot keeps its proposal');
	}//end testOneProposalPerLot()

	/**
	 * Proposing the proposed bid again changes nothing.
	 *
	 * @return void
	 */
	public function testProposingTheSameBidAgainIsANoOp(): void {
		$this->proposal()->propose(caseId: 'tender-1', submissionId: 'bid-a');
		$result = $this->proposal()->propose(caseId: 'tender-1', submissionId: 'bid-a');

		$this->assertSame(expected: ['proposed' => 'bid-a', 'released' => []], actual: $result);
		$this->assertSame(expected: 'proposed-award', actual: $this->state(id: 'bid-a'));
	}//end testProposingTheSameBidAgainIsANoOp()

	/**
	 * A bid still received, or one of another case, is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testOnlyAnAdmissibleBidOfTheCaseCanBeProposed(): void {
		$this->register->seed(
			schema: 'bid',
			id: 'bid-x',
			row: ['tender' => 'tender-2', 'case' => 'tender-2', 'priceAmount' => 1, 'state' => 'evaluated', 'lot' => 'lot-1']
		);

		foreach (['bid-c', 'bid-x', ''] as $id) {
			$result = $this->proposal()->propose(caseId: 'tender-1', submissionId: $id);
			$this->assertSame(expected: 'Only an admissible bid on this case can be proposed for the award.', actual: $result['refusal'], message: $id);
		}

		$this->assertSame(expected: 'received', actual: $this->state(id: 'bid-c'));
		$this->assertSame(expected: 'evaluated', actual: $this->state(id: 'bid-x'));
	}//end testOnlyAnAdmissibleBidOfTheCaseCanBeProposed()

	/**
	 * A proposed bid stays on the scoring sheet.
	 *
	 * @return void
	 */
	public function testAProposedBidStaysScorable(): void {
		$this->proposal()->propose(caseId: 'tender-1', submissionId: 'bid-a');

		$ids = array_map(
			static fn (array $row): string => (string)$row['id'],
			(new ScoringRecords(settings: $this->settings()))->scorableSubmissions(caseId: 'tender-1')
		);
		sort($ids);
		$this->assertSame(expected: ['bid-a', 'bid-b', 'bid-d'], actual: $ids);
	}//end testAProposedBidStaysScorable()

	/**
	 * Seed one bid of tender-1.
	 *
	 * @param string $id    The bid uuid.
	 * @param string $state Its state.
	 * @param string $lot   Its lot.
	 *
	 * @return void
	 */
	private function bid(string $id, string $state, string $lot): void {
		$this->register->seed(
			schema: 'bid',
			id: $id,
			row: ['tender' => 'tender-1', 'case' => 'tender-1', 'priceAmount' => 80000, 'state' => $state, 'lot' => $lot]
		);
	}//end bid()

	/**
	 * The stored state of one bid.
	 *
	 * @param string $id The bid uuid.
	 *
	 * @return string The state.
	 */
	private function state(string $id): string {
		return (string)$this->register->row(schema: 'bid', id: $id)['state'];
	}//end state()

	/**
	 * Settings answering the fake register.
	 *
	 * @return SettingsService
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);

		return $settings;
	}//end settings()

	/**
	 * The service over the fake register.
	 *
	 * @return AwardProposal
	 */
	private function proposal(): AwardProposal {
		$settings = $this->settings();
		$l10n     = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new AwardProposal(settings: $settings, records: new ScoringRecords(settings: $settings), l10n: $l10n);
	}//end proposal()
}//end class
