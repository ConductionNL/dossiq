<?php

/**
 * The follow of a case handed to another organisation.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transfer
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
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/federated-case-collaboration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transfer;

use OCA\Dossiq\Service\Transfer\TransferredCaseFollow;
use OCA\Dossiq\Service\Transfer\TransferRegisterGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * Which transfer an act lands on, what the entry says, and when nothing is written.
 *
 * @covers \OCA\Dossiq\Service\Transfer\TransferredCaseFollow
 * @uses \OCA\Dossiq\Service\CaseTransfer\TransferStore
 */
class TransferredCaseFollowTest extends TestCase {

	/**
	 * An object service that answers these transfers and records what it is asked.
	 *
	 * @param array<int, array<string, mixed>>|RuntimeException $transfers The transfers findAll answers.
	 *
	 * @return object
	 */
	private function objects(array|RuntimeException $transfers): object {
		return new class ($transfers) {
			public array $queries = [];

			public array $saved = [];

			public function __construct(private array|RuntimeException $transfers) {
			}

			public function findAll(array $query): array {
				$this->queries[] = $query;
				if ($this->transfers instanceof RuntimeException) {
					throw $this->transfers;
				}

				return $this->transfers;
			}

			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
				$register = $options->target->register;
				$schema = $options->target->schema;
				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema];
				return $object;
			}
		};
	}

	/**
	 * The follow over these objects, in register 3 and schema 8.
	 *
	 * @param object|null $objects The object service, or null when OpenRegister is not there.
	 * @param array       $scope   The register and schema.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return TransferredCaseFollow
	 */
	private function follow(?object $objects, array $scope = [3, 8], ?LoggerInterface $logger = null): TransferredCaseFollow {
		$gateway = $this->createMock(TransferRegisterGateway::class);
		$gateway->method('objectService')->willReturn($objects);
		$gateway->method('transferScope')->willReturn($scope);

		return new TransferredCaseFollow($gateway, $logger ?? $this->createMock(LoggerInterface::class));
	}

	public function testAnActOnAHandedOverCaseIsAppendedToItsTransfer(): void {
		$objects = $this->objects([
			['id' => 't-1', 'caseId' => 'case-1', 'status' => 'accepted', 'handoverScope' => 'organisation', 'completedAt' => '2026-11-01T09:00:00+00:00',
				'senderActivity' => [['act' => 'note added', 'actor' => 'eve', 'recordedAt' => '2026-11-02T09:00:00+00:00']]],
		]);

		$this->assertTrue($this->follow($objects)->record('case-1', 'document added', 'bob'));

		$this->assertSame(
			[['filters' => ['register' => 3, 'schema' => 8, 'caseId' => 'case-1', 'status' => 'accepted']]],
			$objects->queries
		);
		$this->assertCount(1, $objects->saved);
		$this->assertSame(3, $objects->saved[0]['register']);
		$this->assertSame(8, $objects->saved[0]['schema']);
		$saved = $objects->saved[0]['object'];
		$this->assertSame('t-1', $saved['id']);
		$this->assertCount(2, $saved['senderActivity']);
		$this->assertSame('note added', $saved['senderActivity'][0]['act']);
		$this->assertSame('document added', $saved['senderActivity'][1]['act']);
		$this->assertSame('bob', $saved['senderActivity'][1]['actor']);
		$this->assertNotFalse(strtotime($saved['senderActivity'][1]['recordedAt']));
	}

	public function testATransferWithoutAScopeIsAnOrganisationHandover(): void {
		$objects = $this->objects([['id' => 't-1', 'caseId' => 'case-1', 'status' => 'accepted']]);

		$this->assertTrue($this->follow($objects)->record('case-1', 'status changed'));
		$this->assertSame([['act' => 'status changed', 'actor' => '']], array_map(
			static fn (array $entry): array => ['act' => $entry['act'], 'actor' => $entry['actor']],
			$objects->saved[0]['object']['senderActivity']
		));
	}

	public function testTheMostRecentlyAcceptedTransferTakesTheAct(): void {
		$objects = $this->objects([
			['id' => 't-old', 'caseId' => 'case-1', 'status' => 'accepted', 'completedAt' => '2026-10-01T09:00:00+00:00'],
			['id' => 't-new', 'caseId' => 'case-1', 'status' => 'accepted', 'completedAt' => '2026-11-01T09:00:00+00:00'],
			['id' => 't-mid', 'caseId' => 'case-1', 'status' => 'accepted', 'completedAt' => '2026-10-15T09:00:00+00:00'],
		]);

		$this->follow($objects)->record('case-1', 'task completed', 'bob');

		$this->assertSame('t-new', $objects->saved[0]['object']['id']);
	}

	public function testATeamHandoverAPendingTransferOrAnotherCaseIsNotFollowed(): void {
		$objects = $this->objects([
			['id' => 't-team', 'caseId' => 'case-1', 'status' => 'accepted', 'handoverScope' => 'team'],
			['id' => 't-pending', 'caseId' => 'case-1', 'status' => 'pending'],
			['id' => 't-other', 'caseId' => 'case-2', 'status' => 'accepted'],
		]);

		$this->assertFalse($this->follow($objects)->record('case-1', 'document added', 'bob'));
		$this->assertSame([], $objects->saved);
	}

	public function testAnObjectEntityIsReadThroughItsJsonSerialization(): void {
		$entity = new class () implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 't-9', 'caseId' => 'case-1', 'status' => 'accepted'];
			}
		};
		$objects = $this->objects([$entity]);

		$this->assertTrue($this->follow($objects)->record('case-1', 'note added', 'bob'));
		$this->assertSame('t-9', $objects->saved[0]['object']['id']);
	}

	public function testTheActivityKeepsOnlyTheNewestEntries(): void {
		$old = array_fill(0, TransferredCaseFollow::MAX_ENTRIES, ['act' => 'note added', 'actor' => 'eve', 'recordedAt' => '2026-01-01T00:00:00+00:00']);
		$old[0]['actor'] = 'first';
		$objects = $this->objects([['id' => 't-1', 'caseId' => 'case-1', 'status' => 'accepted', 'senderActivity' => $old]]);

		$this->follow($objects)->record('case-1', 'document added', 'bob');

		$activity = $objects->saved[0]['object']['senderActivity'];
		$this->assertCount(TransferredCaseFollow::MAX_ENTRIES, $activity);
		$this->assertSame('eve', $activity[0]['actor']);
		$this->assertSame('document added', $activity[TransferredCaseFollow::MAX_ENTRIES - 1]['act']);
	}

	public function testAnUnknownActOrNoCaseRecordsNothing(): void {
		$objects = $this->objects([['id' => 't-1', 'caseId' => 'case-1', 'status' => 'accepted']]);

		$this->assertFalse($this->follow($objects)->record('case-1', 'deleted everything', 'bob'));
		$this->assertFalse($this->follow($objects)->record('  ', 'document added', 'bob'));
		$this->assertSame([], $objects->queries);
		$this->assertSame([], $objects->saved);
	}

	public function testWithoutOpenRegisterOrATransferRegisterNothingIsAsked(): void {
		$this->assertFalse($this->follow(null)->record('case-1', 'document added', 'bob'));

		$objects = $this->objects([['id' => 't-1', 'caseId' => 'case-1', 'status' => 'accepted']]);
		$this->assertFalse($this->follow($objects, [3, 0])->record('case-1', 'document added', 'bob'));
		$this->assertSame([], $objects->queries);
	}

	public function testAFailingRegisterIsLoggedAndRecordsNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('could not record an act'),
			$this->callback(static fn (array $context): bool => $context['caseId'] === 'case-1' && $context['exception'] === 'down')
		);

		$this->assertFalse($this->follow($this->objects(new RuntimeException('down')), [3, 8], $logger)->record('case-1', 'document added', 'bob'));
	}
}//end class
