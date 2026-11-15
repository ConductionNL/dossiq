<?php

/**
 * Tests for the plate change request and the checked plate change.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Permit
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

namespace OCA\Dossiq\Tests\Unit\Service\Permit;


use OCA\Dossiq\Service\Permit\PlateChange;
use OCA\Dossiq\Service\Permit\PlateChangeRequest;
use PHPUnit\Framework\TestCase;

/**
 * A request is usable only when complete, a permit is held only by its subject,
 * and a checked change drafts the case it opens.
 *
 * @covers \OCA\Dossiq\Service\Permit\PlateChangeRequest
 * @covers \OCA\Dossiq\Service\Permit\PlateChange
 */
class PlateChangeTest extends TestCase {

	/**
	 * A complete request with a six-character plate is usable.
	 *
	 * @return void
	 */
	public function testACompleteRequestIsUsable(): void {
		$this->assertTrue((new PlateChangeRequest(subjectRef: 'bsn-1', permitId: 'permit-1', plate: 'AB12CD'))->isUsable());
		$this->assertTrue((new PlateChangeRequest(subjectRef: 'bsn-1', permitId: 'permit-1', plate: '123456'))->isUsable());
	}//end testACompleteRequestIsUsable()

	/**
	 * Requests with a blank part or a plate that is not six capitals and digits are not usable.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function unusableRequests(): array {
		return [
			'blank subject' => [' ', 'permit-1', 'AB12CD'],
			'blank permit' => ['bsn-1', '', 'AB12CD'],
			'dashed plate' => ['bsn-1', 'permit-1', 'AB-12-CD'],
			'lower case plate' => ['bsn-1', 'permit-1', 'ab12cd'],
			'short plate' => ['bsn-1', 'permit-1', 'AB12C'],
			'long plate' => ['bsn-1', 'permit-1', 'AB12CDE'],
		];
	}//end unusableRequests()

	/**
	 * An unusable request answers false.
	 *
	 * @param string $subject The subject reference.
	 * @param string $permit  The permit id.
	 * @param string $plate   The plate.
	 *
	 * @return void
	 *
	 * @dataProvider unusableRequests
	 */
	public function testAnIncompleteRequestIsNotUsable(string $subject, string $permit, string $plate): void {
		$this->assertFalse((new PlateChangeRequest(subjectRef: $subject, permitId: $permit, plate: $plate))->isUsable());
	}//end testAnIncompleteRequestIsNotUsable()

	/**
	 * A permit is held only by the subject it names.
	 *
	 * @return void
	 */
	public function testAPermitIsHeldOnlyByItsSubject(): void {
		$request = new PlateChangeRequest(subjectRef: 'bsn-1', permitId: 'permit-1', plate: 'AB12CD');

		$this->assertTrue($request->isHeldBySubject(permit: ['portalSubject' => 'bsn-1']));
		$this->assertFalse($request->isHeldBySubject(permit: ['portalSubject' => 'bsn-2']));
		$this->assertFalse($request->isHeldBySubject(permit: []));
		$this->assertFalse($request->isHeldBySubject(permit: null));
	}//end testAPermitIsHeldOnlyByItsSubject()

	/**
	 * The checked change drafts its case from the request, the permit and the change type.
	 *
	 * @return void
	 */
	public function testTheChangeDraftsItsCase(): void {
		$change = new PlateChange(
			request: new PlateChangeRequest(subjectRef: 'bsn-1', permitId: 'permit-1', plate: 'AB12CD'),
			permit: ['title' => 'Parkeervergunning Centrum'],
			changeType: ['id' => 'type-9', 'initialStatus' => ' ingediend '],
		);

		$draft = $change->draft();
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $draft['startDate']);
		unset($draft['startDate']);

		$this->assertSame(
			[
				'title' => 'Kenteken wijzigen: Parkeervergunning Centrum',
				'description' => 'Nieuw kenteken AB12CD voor vergunning permit-1.',
				'caseType' => 'type-9',
				'portalSubject' => 'bsn-1',
				'portalParty' => 'subject:bsn-1',
				'intakeChannel' => 'website',
			],
			$draft
		);
		$this->assertSame('type-9', $change->changeTypeId());
		$this->assertSame(['permit' => 'permit-1', 'nieuwKenteken' => 'AB12CD'], $change->answers());
		$this->assertSame('ingediend', $change->initialStatus());
	}//end testTheChangeDraftsItsCase()

	/**
	 * A change type without an id or initial status answers empty strings.
	 *
	 * @return void
	 */
	public function testAChangeTypeWithoutIdOrStatusAnswersEmpty(): void {
		$change = new PlateChange(
			request: new PlateChangeRequest(subjectRef: 'bsn-1', permitId: 'permit-1', plate: 'AB12CD'),
			permit: [],
			changeType: [],
		);

		$this->assertSame('', $change->changeTypeId());
		$this->assertSame('', $change->initialStatus());
		$this->assertSame('Kenteken wijzigen: ', $change->draft()['title']);
		$this->assertSame('', $change->draft()['caseType']);
	}//end testAChangeTypeWithoutIdOrStatusAnswersEmpty()

}//end class
