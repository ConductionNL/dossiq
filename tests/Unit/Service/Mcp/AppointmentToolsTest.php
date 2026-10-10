<?php

/**
 * Unit tests for the curated appointment tools.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Mcp;

use OCA\Dossiq\Service\AppointmentService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Mcp\AppointmentTools;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Booking and cancelling run the case check AppointmentController runs.
 */
class AppointmentToolsTest extends TestCase {

	private IUserSession&MockObject $session;

	private CaseAccessGuard&MockObject $guard;

	private AppointmentService&MockObject $appointments;

	private AppointmentTools $tools;

	protected function setUp(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->appointments = $this->createMock(AppointmentService::class);
		$this->tools = new AppointmentTools(
			userSession: $this->session,
			caseAccess: $this->guard,
			appointments: $this->appointments,
		);
	}//end setUp()

	/**
	 * A signed-in caller.
	 *
	 * @return IUser
	 */
	private function signIn(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('henk');
		$this->session->method('getUser')->willReturn($user);

		return $user;
	}//end signIn()

	public function testBookingOnACaseTheCallerMayNotChangeIsRefused(): void {
		$user = $this->signIn();
		$this->guard->expects($this->once())->method('hasCaseMutationAccess')->with('c-1', $user)->willReturn(false);
		$this->appointments->expects($this->never())->method('bookAppointment');

		$this->assertSame('forbidden', $this->tools->scheduleAppointment(caseId: 'c-1', productId: 'p-1', locationId: 'l-1', dateTime: '2026-11-03T10:00:00+01:00')['error']);
	}//end testBookingOnACaseTheCallerMayNotChangeIsRefused()

	public function testBookingPassesTheControllersFields(): void {
		$this->signIn();
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->appointments->expects($this->once())->method('bookAppointment')
			->with('c-1', [
				'productId' => 'p-1',
				'locationId' => 'l-1',
				'dateTime' => '2026-11-03T10:00:00+01:00',
				'duration' => 45,
				'citizenName' => 'Fatima El-Amrani',
				'citizenEmail' => 'fatima@example.nl',
				'citizenPhone' => null,
				'notes' => null,
			])
			->willReturn(['id' => 'a-1', 'status' => 'scheduled']);

		$result = $this->tools->scheduleAppointment(
			caseId: 'c-1',
			productId: 'p-1',
			locationId: 'l-1',
			dateTime: '2026-11-03T10:00:00+01:00',
			duration: 45,
			citizenName: 'Fatima El-Amrani',
			citizenEmail: 'fatima@example.nl',
		);

		$this->assertSame(['success' => true, 'appointment' => ['id' => 'a-1', 'status' => 'scheduled']], $result);
	}//end testBookingPassesTheControllersFields()

	public function testAMissingSlotFieldIsRefusedBeforeTheService(): void {
		$this->signIn();
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->appointments->expects($this->never())->method('bookAppointment');

		$this->assertSame('slot_required', $this->tools->scheduleAppointment(caseId: 'c-1', productId: 'p-1', locationId: '', dateTime: '2026-11-03T10:00')['error']);
	}//end testAMissingSlotFieldIsRefusedBeforeTheService()

	public function testAServiceErrorPayloadIsAnEnvelope(): void {
		$this->signIn();
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->appointments->method('bookAppointment')->willReturn(['error' => 'OpenRegister is not available']);

		$this->assertSame(['error' => 'refused', 'message' => 'OpenRegister is not available'], $this->tools->scheduleAppointment(caseId: 'c-1', productId: 'p-1', locationId: 'l-1', dateTime: '2026-11-03T10:00'));
	}//end testAServiceErrorPayloadIsAnEnvelope()

	public function testCancellingAnAppointmentWithNoCaseIsRefused(): void {
		$this->signIn();
		$this->appointments->method('getCaseIdForAppointment')->willReturn(null);
		$this->appointments->expects($this->never())->method('cancelAppointment');

		$this->assertSame('forbidden', $this->tools->cancelAppointment(appointmentId: 'a-1')['error']);
	}//end testCancellingAnAppointmentWithNoCaseIsRefused()

	public function testCancellingChecksTheOwningCase(): void {
		$user = $this->signIn();
		$this->appointments->method('getCaseIdForAppointment')->with('a-1')->willReturn('c-1');
		$this->guard->expects($this->once())->method('hasCaseMutationAccess')->with('c-1', $user)->willReturn(true);
		$this->appointments->expects($this->once())->method('cancelAppointment')->with('a-1')->willReturn(['id' => 'a-1', 'status' => 'cancelled']);

		$this->assertSame('cancelled', $this->tools->cancelAppointment(appointmentId: 'a-1')['appointment']['status']);
	}//end testCancellingChecksTheOwningCase()

	public function testNoSessionBooksNothing(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->appointments->expects($this->never())->method('bookAppointment');

		$this->assertSame('not_authenticated', $this->tools->scheduleAppointment(caseId: 'c-1', productId: 'p-1', locationId: 'l-1', dateTime: 'x')['error']);
		$this->assertSame('not_authenticated', $this->tools->cancelAppointment(appointmentId: 'a-1')['error']);
	}//end testNoSessionBooksNothing()
}//end class
