<?php

/**
 * Handing an incident over leaves the case exactly where it was.
 *
 * 🔴 THIS IS THE ONE RULE A CARELESS IMPLEMENTATION BREAKS, by reusing the
 * case hand-off for the incident. The case sits with the area handler while
 * one report inside it is worked by an inspector; moving the incident must not
 * move the case, and the failure is invisible until a teamleider asks why
 * their area handler's list emptied.
 *
 * So the change this produces is asserted key for key: it names the
 * INCIDENT's assignee and nothing that belongs to the case.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\IncidentRecord;
use PHPUnit\Framework\TestCase;

/**
 * The incident's own hand-off.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class IncidentHandoverTest extends TestCase {
	private IncidentRecord $incidents;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->incidents = new IncidentRecord();
	}//end setUp()

	/**
	 * The change touches the incident's assignee and nothing of the case.
	 *
	 * @return void
	 */
	public function testTheHandoverTouchesTheIncidentAlone(): void {
		$change = $this->incidents->handoverChange(
			incident: ['id' => 'june', 'case' => 'case-1', 'assignee' => 'aad', 'state' => 'open'],
			assignee: 'inspector-mo',
		);

		$this->assertSame('inspector-mo', $change['assignee']);
		// Nothing about the case travels with it: not its id, not its owner,
		// not its status.
		$this->assertArrayNotHasKey('case', $change);
		$this->assertArrayNotHasKey('caseAssignee', $change);
		$this->assertArrayNotHasKey('status', $change);
	}//end testTheHandoverTouchesTheIncidentAlone()

	/**
	 * Taking an untouched incident on starts work on it.
	 *
	 * @return void
	 */
	public function testTakingAnUntouchedIncidentOnStartsIt(): void {
		$this->assertSame('in-behandeling', $this->incidents->handoverChange(['state' => 'open'], 'mo')['state']);
		$this->assertSame('in-behandeling', $this->incidents->handoverChange([], 'mo')['state']);
	}//end testTakingAnUntouchedIncidentOnStartsIt()

	/**
	 * A finished incident is not reopened by being handed to a colleague.
	 *
	 * @return void
	 */
	public function testAFinishedIncidentIsNotReopenedByAHandover(): void {
		$change = $this->incidents->handoverChange(['state' => 'afgehandeld'], 'mo');

		$this->assertArrayNotHasKey('state', $change);
		$this->assertSame('mo', $change['assignee']);
	}//end testAFinishedIncidentIsNotReopenedByAHandover()

	/**
	 * One already in progress stays in progress.
	 *
	 * @return void
	 */
	public function testOneAlreadyInProgressStaysThere(): void {
		$this->assertArrayNotHasKey('state', $this->incidents->handoverChange(['state' => 'in-behandeling'], 'mo'));
	}//end testOneAlreadyInProgressStaysThere()

	/**
	 * A handover to nobody empties the assignee rather than writing a space.
	 *
	 * @return void
	 */
	public function testAHandoverToNobodyEmptiesTheAssignee(): void {
		$this->assertSame('', $this->incidents->handoverChange(['state' => 'open'], '  ')['assignee']);
	}//end testAHandoverToNobodyEmptiesTheAssignee()
}//end class
