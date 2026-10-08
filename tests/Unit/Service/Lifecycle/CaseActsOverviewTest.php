<?php

/**
 * The one read behind the act menu: what a case is, and what may be done.
 *
 * The read was a method on the act facade until the query and the commands
 * were separated. What these tests pin is the answer's SHAPE, because the
 * case page renders every key of it: a missing key is a blank badge rather
 * than an error, and a wrong archive marker offers Archive on a case that is
 * already archived, which the platform answers with a shrug.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Lifecycle
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Lifecycle\CaseActsMenu;
use OCA\Dossiq\Service\Lifecycle\CaseActsOverview;
use OCA\Dossiq\Service\Lifecycle\CaseArchiveState;
use OCA\Dossiq\Service\Lifecycle\CaseEndingActs;
use OCA\Dossiq\Service\Lifecycle\CaseHoldActs;
use OCA\Dossiq\Service\Lifecycle\CaseIncompleteness;
use OCA\Dossiq\Service\Lifecycle\DraftCaseActs;
use OCA\Dossiq\Service\Lifecycle\ProcessOwnedStatusRule;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use PHPUnit\Framework\TestCase;

/**
 * The state half of the act read.
 *
 * @covers \OCA\Dossiq\Service\Lifecycle\CaseActsOverview
 *
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class CaseActsOverviewTest extends TestCase {

	/**
	 * A held, incomplete case that ended by being aborted.
	 *
	 * @var array<string, mixed>
	 */
	private const CASE = [
		'id' => 'case-1',
		'caseType' => 'ct-1',
		'heldUntil' => '2099-03-01',
		'endingAct' => 'abort',
	];

	/**
	 * The store, seeded with the case above unless a test says otherwise.
	 *
	 * @var CaseStatusStore
	 */
	private CaseStatusStore $store;

	/**
	 * The menu, which this class composes rather than computes.
	 *
	 * @var CaseActsMenu
	 */
	private CaseActsMenu $menu;

	/**
	 * Build the read model over doubled collaborators.
	 *
	 * @param CaseArchiveState|null $archiveState The archive marker reader, when wired.
	 *
	 * @return CaseActsOverview The read model.
	 */
	private function overview(?CaseArchiveState $archiveState = null): CaseActsOverview {
		$incompleteness = $this->createMock(originalClassName: CaseIncompleteness::class);
		$incompleteness->method('missingOn')->willReturn(['applicantAddress']);

		$holds = $this->createMock(originalClassName: CaseHoldActs::class);
		$holds->method('isHeld')->willReturn(true);

		$drafts = $this->createMock(originalClassName: DraftCaseActs::class);
		$drafts->method('isDraft')->willReturn(false);

		$endings = $this->createMock(originalClassName: CaseEndingActs::class);
		$endings->method('endingOf')->willReturn(['act' => 'abort', 'reason' => 'Ingetrokken']);

		$processStatus = $this->createMock(originalClassName: ProcessOwnedStatusRule::class);
		$processStatus->method('allowsHandSet')->willReturn(false);

		return new CaseActsOverview(
			store: $this->store,
			menu: $this->menu,
			endings: $endings,
			holds: $holds,
			drafts: $drafts,
			incompleteness: $incompleteness,
			processStatus: $processStatus,
			archiveState: $archiveState,
		);
	}//end overview()

	/**
	 * A store holding the case, and a menu answering three fixed keys.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = $this->createMock(originalClassName: CaseStatusStore::class);
		$this->store->method('loadCase')->willReturn(self::CASE);

		$this->menu = $this->createMock(originalClassName: CaseActsMenu::class);
		$this->menu->method('forCase')->willReturn(
			[
				'acts' => [['act' => 'finish', 'allowed' => true, 'role' => '', 'reason' => '']],
				'alwaysAvailable' => [['act' => 'note']],
				'awaitingApproval' => [['on' => 'fatima']],
			]
		);
	}//end setUp()

	/**
	 * The read answers the state the case page draws from, and it carries
	 * the menu's three keys beside it rather than in a second call.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testTheReadCarriesTheStateAndTheMenuInOneAnswer(): void {
		$answer = $this->overview()->overview(caseId: 'case-1');

		self::assertSame('case-1', $answer['caseId']);
		self::assertTrue($answer['held']);
		self::assertSame('2099-03-01', $answer['heldUntil']);
		self::assertFalse($answer['draft']);
		self::assertTrue($answer['incomplete']);
		self::assertSame(['applicantAddress'], $answer['missingFields']);
		self::assertSame('abort', $answer['endingAct']);
		self::assertSame(['act' => 'abort', 'reason' => 'Ingetrokken'], $answer['ending']);
		self::assertFalse($answer['statusIsHandSettable']);

		self::assertSame([['act' => 'note']], $answer['alwaysAvailable']);
		self::assertSame([['on' => 'fatima']], $answer['awaitingApproval']);
		self::assertSame('finish', $answer['acts'][0]['act']);
	}//end testTheReadCarriesTheStateAndTheMenuInOneAnswer()

	/**
	 * 🔑 THE ARCHIVE MARKER DECIDES WHETHER THE MENU OFFERS RESTORE. Two
	 * entries both enabled would let a handler archive a case that is already
	 * archived, and the platform answers that with a shrug rather than a
	 * refusal, so nothing on screen would say what happened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function testAnArchivedCaseSaysSoWithWhoAndWhen(): void {
		$archiveState = $this->createMock(originalClassName: CaseArchiveState::class);
		$archiveState->method('markerOn')->willReturn(
			['at' => '2026-09-01', 'by' => 'ahmed', 'reason' => 'Bewaartermijn gestart']
		);

		$answer = $this->overview(archiveState: $archiveState)->overview(caseId: 'case-1');

		self::assertTrue($answer['archived']);
		self::assertSame('2026-09-01', $answer['archivedAt']);
		self::assertSame('ahmed', $answer['archivedBy']);
		self::assertSame('Bewaartermijn gestart', $answer['archivedReason']);
	}//end testAnArchivedCaseSaysSoWithWhoAndWhen()

	/**
	 * With no marker, and with no archive reader wired at all, the case reads
	 * as not archived and the three marker fields are empty strings rather
	 * than absent. An absent key renders as a blank badge, not as an error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function testWithoutAMarkerTheCaseReadsAsNotArchived(): void {
		$archiveState = $this->createMock(originalClassName: CaseArchiveState::class);
		$archiveState->method('markerOn')->willReturn([]);

		foreach ([$this->overview(archiveState: $archiveState), $this->overview()] as $overview) {
			$answer = $overview->overview(caseId: 'case-1');

			self::assertFalse($answer['archived']);
			self::assertSame('', $answer['archivedAt']);
			self::assertSame('', $answer['archivedBy']);
			self::assertSame('', $answer['archivedReason']);
		}
	}//end testWithoutAMarkerTheCaseReadsAsNotArchived()

	/**
	 * A case the store cannot find is refused, and the refusal names the rule
	 * rather than answering an empty menu that reads like a case with nothing
	 * on it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAnUnknownCaseIsRefusedRatherThanAnsweredEmpty(): void {
		$this->store = $this->createMock(originalClassName: CaseStatusStore::class);
		$this->store->method('loadCase')->willReturn(null);

		try {
			$this->overview()->overview(caseId: 'nope');
			self::fail('A case that cannot be read has to refuse.');
		} catch (RefusedException $refusal) {
			self::assertSame('case-not-found', $refusal->getRule());
			self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $refusal->getStatus());
		}
	}//end testAnUnknownCaseIsRefusedRatherThanAnsweredEmpty()
}//end class
