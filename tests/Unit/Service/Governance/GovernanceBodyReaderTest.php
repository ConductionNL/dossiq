<?php

/**
 * Unit tests for GovernanceBodyReader: decidiq's answer overrides the local
 * row only when it found the body; an absent or failing decidiq and a body
 * not yet migrated both fall back to the local row; and an archived body in
 * decidiq refuses a new referral in AdvisoryCommitteeService.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Governance
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md#requirement-req-mcd-003-reads-resolve-from-decidiq-falling-back-locally
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Governance;

use OCA\Decidiq\Event\GovernanceBodyStateRequestedEvent;
use OCA\Dossiq\Service\Governance\GovernanceBodyReader;
use OCA\Dossiq\Tests\Support\MakesBezwaarAuditTrail;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Governance\GovernanceBodyReader
 *
 * @uses \OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService
 */
class GovernanceBodyReaderTest extends TestCase {
	use MakesBezwaarAuditTrail;

	/**
	 * Every read the dispatcher carried.
	 *
	 * @var list<GovernanceBodyStateRequestedEvent>
	 */
	private array $asked = [];

	/**
	 * A reader over a dispatcher that answers like decidiq would.
	 *
	 * @param string                    $mode   `found`, `missing`, `absent` or `throws`.
	 * @param array<string, mixed>|null $body   The body decidiq holds, for `found`.
	 *
	 * @return GovernanceBodyReader The reader.
	 */
	private function reader(string $mode, ?array $body = null): GovernanceBodyReader {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event) use ($mode, $body): void {
				if ($event instanceof GovernanceBodyStateRequestedEvent === false) {
					return;
				}

				$this->asked[] = $event;
				if ($mode === 'throws') {
					throw new RuntimeException('listener exploded');
				}

				if ($mode === 'absent') {
					return;
				}

				$event->setHandled(true);
				if ($mode === 'found' && $body !== null) {
					$event->setGovernanceBody($body);
				}
			}
		);

		return new GovernanceBodyReader(eventDispatcher: $dispatcher, logger: new NullLogger());
	}//end reader()

	/**
	 * A found body overrides active, the name and the roster, and asks by the
	 * key the write raised it under.
	 *
	 * @return void
	 */
	public function testAFoundBodyOverridesTheLocalRow(): void {
		$row = ['id' => 'committee-1', 'name' => 'BAC oud', 'active' => true, 'chair' => 'old-chair', 'governanceBodyId' => 'gb-9'];
		$body = [
			'id' => 'gb-9',
			'name' => 'Bezwaarschriftencommissie',
			'active' => false,
			'members' => [
				['uid' => 'jan', 'name' => 'Jan', 'role' => 'chair', 'external' => true],
				['uid' => 'piet', 'name' => 'Piet', 'role' => 'secretary', 'external' => false],
				['uid' => 'kees', 'name' => 'Kees', 'role' => 'member', 'external' => true],
				['uid' => 'anna', 'name' => 'Anna', 'role' => 'deputy', 'external' => false],
			],
		];

		$resolved = $this->reader(mode: 'found', body: $body)->resolve(row: $row);

		self::assertFalse(condition: $resolved['active']);
		self::assertSame(expected: 'Bezwaarschriftencommissie', actual: $resolved['name']);
		self::assertSame(expected: 'jan', actual: $resolved['chair']);
		self::assertSame(expected: 'piet', actual: $resolved['secretary']);
		self::assertSame(
			expected: [
				['uid' => 'kees', 'displayName' => 'Kees', 'role' => 'member', 'external' => true],
				['uid' => 'anna', 'displayName' => 'Anna', 'role' => 'deputy', 'external' => false],
			],
			actual: $resolved['members']
		);
		self::assertSame(expected: 'decidiq', actual: $resolved[GovernanceBodyReader::SOURCE_KEY]);
		self::assertSame(expected: 'dossiq', actual: $this->asked[0]->getSourceApp());
		self::assertSame(expected: 'committee-1', actual: $this->asked[0]->getExternalReference());
		self::assertSame(expected: 'gb-9', actual: $this->asked[0]->getGovernanceBodyId());
	}//end testAFoundBodyOverridesTheLocalRow()

	/**
	 * An absent decidiq, a failing read and a body not yet migrated all keep the
	 * local row as it is, with no error.
	 *
	 * @return void
	 */
	public function testEveryOtherAnswerFallsBackToTheLocalRow(): void {
		$row = ['id' => 'committee-1', 'name' => 'BAC', 'active' => true, 'chair' => 'jan'];

		foreach (['absent', 'throws', 'missing'] as $mode) {
			$resolved = $this->reader(mode: $mode)->resolve(row: $row);

			self::assertTrue(condition: $resolved['active'], message: $mode);
			self::assertSame(expected: 'jan', actual: $resolved['chair'], message: $mode);
			self::assertSame(expected: 'local', actual: $resolved[GovernanceBodyReader::SOURCE_KEY], message: $mode);
		}
	}//end testEveryOtherAnswerFallsBackToTheLocalRow()

	/**
	 * A committee archived in decidiq refuses a new referral, though its local
	 * copy still says active.
	 *
	 * @return void
	 */
	public function testACommitteeArchivedInDecidiqRefusesANewReferral(): void {
		$this->startBezwaarStore();
		$this->store->seed('bezwaaradviescommissie', 'committee-1', ['name' => 'BAC', 'active' => true, 'governanceBodyId' => 'gb-9']);

		$service = $this->realAdvisoryService(
			trail: $this->bezwaarAuditTrail(),
			governanceBodies: $this->reader(mode: 'found', body: ['id' => 'gb-9', 'name' => 'BAC', 'active' => false, 'members' => []])
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Committee is archived and cannot accept new bezwaaren');
		$service->assignToCommittee(objectionId: 'bezwaar-1', commissieId: 'committee-1');
	}//end testACommitteeArchivedInDecidiqRefusesANewReferral()

	/**
	 * Without decidiq the local row decides, and the referral goes through.
	 *
	 * @return void
	 */
	public function testWithoutDecidiqTheLocalRowDecides(): void {
		$this->startBezwaarStore();
		$this->store->seed('bezwaaradviescommissie', 'committee-1', ['name' => 'BAC', 'active' => true]);

		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail(), governanceBodies: $this->reader(mode: 'absent'));

		$saved = $service->assignToCommittee(objectionId: 'bezwaar-1', commissieId: 'committee-1');

		self::assertSame(expected: 'assigned', actual: $saved['status']);
	}//end testWithoutDecidiqTheLocalRowDecides()
}//end class
