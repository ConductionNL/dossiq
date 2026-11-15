<?php

/**
 * GuardedCitizenLookup: the guard decides, the recorder records why.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Kcc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use OCA\Dossiq\Service\CitizenLookupGuard;
use OCA\Dossiq\Service\Kcc\CitizenLookupRecorder;
use OCA\Dossiq\Service\Kcc\GuardedCitizenLookup;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The allow decision and the redaction are the guard's; a refusal is recorded
 * with no fields and the "geen kcc-rol" ground, an answer with the fields the
 * guard reveals to that user and the "kcc-rol" ground.
 *
 * @covers \OCA\Dossiq\Service\Kcc\GuardedCitizenLookup
 */
class GuardedCitizenLookupTest extends TestCase {

	/**
	 * The guard.
	 *
	 * @var CitizenLookupGuard&MockObject
	 */
	private CitizenLookupGuard $guard;

	/**
	 * The recorder.
	 *
	 * @var CitizenLookupRecorder&MockObject
	 */
	private CitizenLookupRecorder $recorder;

	/**
	 * The service under test.
	 *
	 * @var GuardedCitizenLookup
	 */
	private GuardedCitizenLookup $lookup;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->guard = $this->createMock(CitizenLookupGuard::class);
		$this->recorder = $this->createMock(CitizenLookupRecorder::class);
		$this->lookup = new GuardedCitizenLookup($this->guard, $this->recorder);
	}//end setUp()

	/**
	 * A user with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser The user.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * Whether the lookup is allowed is the guard's answer for that user.
	 *
	 * @return void
	 */
	public function testIsAllowedIsTheGuardsAnswer(): void {
		$alice = $this->user(uid: 'alice');
		$bob = $this->user(uid: 'bob');
		$this->guard->method('isCitizenLookupAllowed')->willReturnCallback(static fn (IUser $user): bool => $user === $alice);

		$this->assertTrue($this->lookup->isAllowed(user: $alice));
		$this->assertFalse($this->lookup->isAllowed(user: $bob));
	}//end testIsAllowedIsTheGuardsAnswer()

	/**
	 * A refusal is recorded as not allowed, with no fields and its ground.
	 *
	 * @return void
	 */
	public function testARefusalIsRecordedWithoutFields(): void {
		$this->guard->expects($this->never())->method('revealedFieldsFor');
		$this->recorder->expects($this->once())->method('record')->with('alice', '999993653', false, [], 'geen kcc-rol')->willReturn(true);

		$this->lookup->recordRefusal(uid: 'alice', burgerId: '999993653');
	}//end testARefusalIsRecordedWithoutFields()

	/**
	 * An answer is recorded as allowed, with the fields revealed to that user.
	 *
	 * @return void
	 */
	public function testAnAnswerIsRecordedWithTheRevealedFields(): void {
		$alice = $this->user(uid: 'alice');
		$this->guard->expects($this->once())->method('revealedFieldsFor')->with($alice)->willReturn(['naam', 'adres']);
		$this->recorder->expects($this->once())->method('record')->with('alice', '999993653', true, ['naam', 'adres'], 'kcc-rol')->willReturn(true);

		$this->lookup->recordAnswer(user: $alice, burgerId: '999993653');
	}//end testAnAnswerIsRecordedWithTheRevealedFields()

	/**
	 * The payload the caller gets is the guard's redaction of it.
	 *
	 * @return void
	 */
	public function testThePayloadIsRedactedByTheGuard(): void {
		$alice = $this->user(uid: 'alice');
		$payload = ['naam' => 'Jansen', 'bsn' => '999993653'];
		$this->guard->expects($this->once())->method('redactForCaller')->with($alice, $payload)->willReturn(['naam' => 'Jansen']);

		$this->assertSame(['naam' => 'Jansen'], $this->lookup->redactForCaller(user: $alice, payload: $payload));
	}//end testThePayloadIsRedactedByTheGuard()
}//end class
