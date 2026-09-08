<?php

/**
 * CaseFlowActions Unit Tests.
 *
 * The flow document is the whole of what "plan a follow-up" writes, so it is
 * what this asserts. Two properties of it fail SILENTLY if they regress: a
 * schedule trigger without `runAs` is refused at save (OpenRegister does not
 * fall back to the flow's owner, on purpose), and a cron that leaves a
 * calendar field open fires on days nobody planned.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use DateTimeImmutable;
use OCA\Dossiq\Service\Flow\CaseFlowActions;
use OCA\Dossiq\Service\Flow\PlannedFollowUpDocument;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The flow a planned follow-up is written as.
 *
 * @covers \OCA\Dossiq\Service\Flow\CaseFlowActions
 * @covers \OCA\Dossiq\Service\Flow\PlannedFollowUpDocument
 */
class CaseFlowActionsTest extends TestCase {

	/**
	 * Build the service over a container that resolves nothing.
	 *
	 * Nothing here needs OpenRegister: `planDocument()` is pure, and the two
	 * refusals below happen before any collaborator is asked for. That is
	 * itself the contract — dossiq declares no `<app>` dependency on
	 * openregister, so this class has to stay constructible without it.
	 *
	 * @return CaseFlowActions The service under test.
	 */
	private function service(): CaseFlowActions {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(
			new class extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
			}
		);

		return new CaseFlowActions(
			$container,
			$this->createMock(SettingsService::class),
			new PlannedFollowUpDocument(),
			$this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * The document a planned follow-up is written from.
	 *
	 * @return array<string, mixed> The flow document.
	 */
	private function document(): array {
		return (new PlannedFollowUpDocument())->build(
			caseId: 'case-1',
			caseTypeId: 'type-controle',
			due: new DateTimeImmutable('2026-10-15'),
			title: 'Controle Kerkstraat 12',
			uid: 'behandelaar'
		);
	}//end document()

	/**
	 * The named node types, for the assertions below.
	 *
	 * @param array<string, mixed> $document The flow document.
	 * @param string $type The node type.
	 *
	 * @return array<string, mixed> The node's config.
	 */
	private function configOf(array $document, string $type): array {
		foreach ($document['nodes'] as $node) {
			if ($node['type'] === $type) {
				return $node['config'];
			}
		}

		$this->fail(sprintf('the flow document has no "%s" node', $type));
	}//end configOf()

	/**
	 * The schedule trigger names the user its runs act as.
	 *
	 * Without it TriggerScheduleNode refuses the save outright: nobody is
	 * present when a schedule fires, and the flow's owner is deliberately NOT
	 * a fallback, because authoring a flow is not consent to unattended
	 * execution as its author.
	 *
	 * @return void
	 */
	public function testTheScheduleTriggerCarriesRunAs(): void {
		$config = $this->configOf($this->document(), 'openregister.trigger-schedule');

		$this->assertSame('behandelaar', $config['runAs']);
	}//end testTheScheduleTriggerCarriesRunAs()

	/**
	 * `runAs` is the person who planned it, and no other identity appears in
	 * the document.
	 *
	 * @return void
	 */
	public function testRunAsIsThePersonWhoPlannedIt(): void {
		$document = (new PlannedFollowUpDocument())->build(
			caseId: 'case-1',
			caseTypeId: 'type-controle',
			due: new DateTimeImmutable('2026-10-15'),
			title: 'Controle',
			uid: 'coordinator'
		);

		$this->assertSame(
			'coordinator',
			$this->configOf($document, 'openregister.trigger-schedule')['runAs']
		);
	}//end testRunAsIsThePersonWhoPlannedIt()

	/**
	 * The trigger is single-shot: minute, hour, day and month are all pinned
	 * to the planned date, so it names one minute rather than a recurrence.
	 *
	 * The weekday field stays open on purpose. Pinning it too would AND two
	 * calendar constraints that disagree in most years, and the flow would
	 * fire on neither.
	 *
	 * @return void
	 */
	public function testTheTriggerIsSingleShot(): void {
		$cron = $this->configOf($this->document(), 'openregister.trigger-schedule')['cron'];

		$fields = preg_split('/\s+/', $cron);
		$this->assertCount(5, $fields, 'a cron expression has five fields');
		$this->assertSame('15', $fields[2], 'the day is pinned to the planned date');
		$this->assertSame('10', $fields[3], 'the month is pinned to the planned date');
		$this->assertNotSame('*', $fields[0], 'the minute is pinned');
		$this->assertNotSame('*', $fields[1], 'the hour is pinned');
		$this->assertSame('*', $fields[4], 'the weekday stays open');
	}//end testTheTriggerIsSingleShot()

	/**
	 * The flow's own cron matches the trigger node's.
	 *
	 * FlowScheduleService reads the flow ROW's `cron` to decide what is due,
	 * and TriggerScheduleNode validates the NODE's. Two different expressions
	 * would pass both and fire on a date nobody planned.
	 *
	 * @return void
	 */
	public function testTheFlowRowAndTheTriggerNodeAgreeOnTheCron(): void {
		$document = $this->document();

		$this->assertSame(
			$document['cron'],
			$this->configOf($document, 'openregister.trigger-schedule')['cron']
		);
		$this->assertSame('schedule', $document['trigger']);
	}//end testTheFlowRowAndTheTriggerNodeAgreeOnTheCron()

	/**
	 * The create node carries the planned case's type, title and the case it
	 * is related to.
	 *
	 * @return void
	 */
	public function testTheCreateNodeCarriesTheCaseTypeTitleAndRelation(): void {
		$config = $this->configOf($this->document(), 'dossiq.createSubCase');

		$this->assertSame('type-controle', $config['caseType']);
		$this->assertSame('Controle Kerkstraat 12', $config['title']);
		$this->assertSame(['case-1'], $config['relatedCases']);
	}//end testTheCreateNodeCarriesTheCaseTypeTitleAndRelation()

	/**
	 * The trigger reaches the create node.
	 *
	 * A node with no edge into it never runs, and the flow would save, publish
	 * and fire without creating anything.
	 *
	 * @return void
	 */
	public function testTheTriggerIsWiredToTheCreateNode(): void {
		$document = $this->document();

		$froms = array_column($document['edges'], 'from');
		$tos = array_column($document['edges'], 'to');

		$this->assertContains('when', $froms);
		$this->assertContains('create', $tos);
	}//end testTheTriggerIsWiredToTheCreateNode()

	/**
	 * Every planned flow carries the marker that separates it from the flows
	 * dossiq SHIPS, which carry none.
	 *
	 * @return void
	 */
	public function testThePlannedFlowIsMarkedAsOne(): void {
		$document = $this->document();

		$this->assertSame(PlannedFollowUpDocument::PLANNED_SLUG, $document['applicationSlug']);
		$this->assertSame('dossiq', $document['app']);
	}//end testThePlannedFlowIsMarkedAsOne()

	/**
	 * It is written disabled, then published, then enabled.
	 *
	 * A run is refused unless a published, sound version exists, so a flow
	 * enabled before it is published would be armed and unrunnable.
	 *
	 * @return void
	 */
	public function testThePlannedFlowIsWrittenDisabled(): void {
		$this->assertFalse($this->document()['enabled']);
	}//end testThePlannedFlowIsWrittenDisabled()

	/**
	 * An unparseable date is refused before anything is written.
	 *
	 * @return void
	 */
	public function testAnUnparseableDateIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('invalid_date');

		$this->service()->plan('case-1', 'type-1', 'volgende maand', 'Controle', 'behandelaar');
	}//end testAnUnparseableDateIsRefused()

	/**
	 * Without a flow store there is nothing to plan into, and that is said
	 * rather than swallowed.
	 *
	 * @return void
	 */
	public function testPlanningRefusesWithoutAFlowStore(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('flows_unavailable');

		$this->service()->plan('case-1', 'type-1', '2026-10-15', 'Controle', 'behandelaar');
	}//end testPlanningRefusesWithoutAFlowStore()

	/**
	 * With no flow store the planned list is empty rather than an error: the
	 * Related cases tab has related cases to show either way.
	 *
	 * @return void
	 */
	public function testThePlannedListIsEmptyWithoutAFlowStore(): void {
		$this->assertSame(['results' => [], 'total' => 0], $this->service()->planned('case-1'));
	}//end testThePlannedListIsEmptyWithoutAFlowStore()

	/**
	 * The retirement sweep is a no-op without a flow store.
	 *
	 * @return void
	 */
	public function testTheSweepIsANoOpWithoutAFlowStore(): void {
		$this->assertSame(0, $this->service()->retireFired());
	}//end testTheSweepIsANoOpWithoutAFlowStore()
}//end class
