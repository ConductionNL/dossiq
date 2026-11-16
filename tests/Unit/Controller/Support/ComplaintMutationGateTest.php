<?php

/**
 * The complaint gate refuses without a session, refuses a missing complaint,
 * and asserts the read or mutation right on a stored one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Controller\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\ComplaintMutationGate;
use OCA\Dossiq\Kernel\Contracts\ComplaintAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\ComplaintServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\ComplaintMutationGate
 */
class ComplaintMutationGateTest extends TestCase {

	private ComplaintAccessGuardInterface&MockObject $guard;
	private ComplaintServiceInterface&MockObject $complaints;
	private ComplaintMutationGate $gate;

	protected function setUp(): void {
		$this->guard = $this->createMock(ComplaintAccessGuardInterface::class);
		$this->complaints = $this->createMock(ComplaintServiceInterface::class);
		$this->gate = new ComplaintMutationGate(accessGuard: $this->guard, complaintService: $this->complaints);
	}

	public function testNoSessionIsRefusedBeforeAnyRead(): void {
		$denied = new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		$this->guard->method('currentUid')->willReturn('');
		$this->guard->method('notAuthenticated')->willReturn($denied);
		$this->complaints->expects($this->never())->method('getComplaint');

		$this->assertSame($denied, $this->gate->refusal(id: 'c1'));
		$this->assertSame($denied, $this->gate->readable(id: 'c1'));
	}

	public function testMissingComplaintIsA404(): void {
		$this->guard->method('currentUid')->willReturn('alice');
		$this->complaints->method('getComplaint')->willReturn(null);

		$refusal = $this->gate->refusal(id: 'c1');
		$this->assertInstanceOf(JSONResponse::class, $refusal);
		$this->assertSame(Http::STATUS_NOT_FOUND, $refusal->getStatus());
		$this->assertSame(['error' => 'Complaint not found'], $refusal->getData());

		$read = $this->gate->readable(id: 'c1');
		$this->assertInstanceOf(JSONResponse::class, $read);
		$this->assertSame(Http::STATUS_NOT_FOUND, $read->getStatus());
	}

	public function testMutationRightIsAsserted(): void {
		$complaint = ['id' => 'c1'];
		$this->guard->method('currentUid')->willReturn('alice');
		$this->complaints->method('getComplaint')->willReturn($complaint);
		$this->guard->expects($this->once())->method('authorizeMutation')->with($complaint, 'alice');

		$this->assertNull($this->gate->refusal(id: 'c1'));
	}

	public function testReadRightIsAssertedAndTheComplaintReturned(): void {
		$complaint = ['id' => 'c1'];
		$this->guard->method('currentUid')->willReturn('alice');
		$this->complaints->method('getComplaint')->willReturn($complaint);
		$this->guard->expects($this->once())->method('authorizeAccess')->with($complaint, 'alice');

		$this->assertSame($complaint, $this->gate->readable(id: 'c1'));
	}
}
