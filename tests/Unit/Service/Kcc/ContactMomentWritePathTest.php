<?php

/**
 * ContactMomentService write and read path characterisation
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Kcc
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

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use OCA\Dossiq\Service\Kcc\ContactMomentService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\AppFramework\OCS\OCSBadRequestException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins what the service stores, refuses and returns, through its public methods.
 *
 * Written against the service before its payload shaping moved to
 * ContactMomentPayload, and unchanged since: same stored object, same refusal
 * text, same scoping query.
 *
 * @covers \OCA\Dossiq\Service\Kcc\ContactMomentService
 * @uses \OCA\Dossiq\Service\Kcc\ContactMomentPayload
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ContactMomentWritePathTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The fake ObjectService; public arrays record what it was asked.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Build the service over a fake ObjectService.
	 *
	 * @param array<string,string> $config The app config values.
	 * @param bool $withObjects Whether OpenRegister answers.
	 *
	 * @return ContactMomentService
	 */
	private function service(array $config = ['register' => '7', 'customer_contact_schema' => '31'], bool $withObjects = true): ContactMomentService {
		$this->objects = new class() {
			/** @var array<int,array<string,mixed>> */
			public array $saved = [];
			/** @var array<int,array<string,mixed>> */
			public array $queries = [];
			/** @var array<string,mixed> */
			public array $store = [];
			public bool $findThrows = false;

			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array {
				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return $object + ['id' => $uuid ?? 'new-1'];
			}

			public function find(string $id, string $register, string $schema): mixed {
				if ($this->findThrows === true) {
					throw new \RuntimeException('gone');
				}

				return $this->store[$id] ?? null;
			}

			public function findAll(array $config): array {
				$this->queries[] = $config;
				return array_values($this->store);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($withObjects === true ? $this->objects : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => $config[$key] ?? $default
		);

		return new ContactMomentService(
			settingsService: $settings,
			logger: $this->createMock(LoggerInterface::class),
			dates: $this->caseDatesFrozenAt('2026-10-12T10:00:00+02:00'),
		);
	}//end service()

	/**
	 * The stored object of a full create, field by field.
	 *
	 * @return void
	 */
	public function testCreateStoresTheWholePayload(): void {
		$saved = $this->service()->create(
			[
				'channel' => 'phone',
				'direction' => 'outbound',
				'outcome' => 'resolved',
				'subject' => '  Paspoort ',
				'summary' => ' Vraag over ophalen ',
				'customerRef' => 'c-1',
				'customerName' => 'J. Jansen',
				'customerPhone' => '',
				'case' => 'case-9',
				'kccAgentRef' => 'attacker',
				'unknown' => 'dropped',
				'tags' => ['a', 5],
				'startedAt' => '2026-10-12T09:00:00+02:00',
				'endedAt' => '2026-10-12T09:10:00+02:00',
			],
			'agent1'
		);

		$expected = [
			'channel' => 'phone',
			'direction' => 'outbound',
			'outcome' => 'resolved',
			'kccAgentRef' => 'agent1',
			'subject' => 'Paspoort',
			'summary' => 'Vraag over ophalen',
			'customerRef' => 'c-1',
			'customerName' => 'J. Jansen',
			'case' => 'case-9',
			'tags' => ['a', '5'],
			'startedAt' => '2026-10-12T09:00:00+02:00',
			'endedAt' => '2026-10-12T09:10:00+02:00',
			'durationSeconds' => 600,
		];
		$this->assertSame($expected, $this->objects->saved[0]['object']);
		$this->assertSame('7', $this->objects->saved[0]['register']);
		$this->assertSame('31', $this->objects->saved[0]['schema']);
		$this->assertNull($this->objects->saved[0]['uuid']);
		$this->assertSame('new-1', $saved['id']);
	}//end testCreateStoresTheWholePayload()

	/**
	 * Without timestamps the moment starts now and has no end or duration.
	 *
	 * @return void
	 */
	public function testCreateDefaultsStartToNow(): void {
		$this->service()->create(['channel' => 'chat', 'tags' => 'not-a-list'], 'agent1');

		$object = $this->objects->saved[0]['object'];
		$this->assertSame('2026-10-12T10:00:00+02:00', $object['startedAt']);
		$this->assertSame('inbound', $object['direction']);
		$this->assertSame('open', $object['outcome']);
		$this->assertSame('', $object['subject']);
		$this->assertArrayNotHasKey('endedAt', $object);
		$this->assertArrayNotHasKey('durationSeconds', $object);
		$this->assertArrayNotHasKey('tags', $object);
	}//end testCreateDefaultsStartToNow()

	/**
	 * An end before the start gives a zero duration, not a negative one.
	 *
	 * @return void
	 */
	public function testCreateNeverStoresANegativeDuration(): void {
		$this->service()->create(
			['channel' => 'phone', 'startedAt' => '2026-10-12T09:10:00+02:00', 'endedAt' => '2026-10-12T09:00:00+02:00'],
			'agent1'
		);

		$this->assertSame(0, $this->objects->saved[0]['object']['durationSeconds']);
	}//end testCreateNeverStoresANegativeDuration()

	/**
	 * The refusals of the storage lookup, each with its own message.
	 *
	 * @return void
	 */
	public function testCreateRefusesWithoutStorage(): void {
		try {
			$this->service(withObjects: false)->create(['channel' => 'phone'], 'agent1');
			$this->fail('expected a refusal');
		} catch (OCSBadRequestException $e) {
			$this->assertSame('OpenRegister is not available', $e->getMessage());
		}

		foreach ([['register' => '7'], ['customer_contact_schema' => '31']] as $config) {
			try {
				$this->service(config: $config)->create(['channel' => 'phone'], 'agent1');
				$this->fail('expected a refusal');
			} catch (OCSBadRequestException $e) {
				$this->assertSame('KCC contact schema is not configured', $e->getMessage());
			}
		}
	}//end testCreateRefusesWithoutStorage()

	/**
	 * An update merges the mutable fields only and keeps the rest.
	 *
	 * @return void
	 */
	public function testUpdateMergesMutableFields(): void {
		$service = $this->service();
		$this->objects->store['m-1'] = [
			'id' => 'm-1',
			'channel' => 'phone',
			'kccAgentRef' => 'agent1',
			'outcome' => 'open',
			'subject' => 'Oud',
			'startedAt' => '2026-10-12T09:00:00+02:00',
		];

		$service->update(
			'm-1',
			[
				'subject' => 'Nieuw',
				'summary' => 5,
				'outcome' => 'resolved',
				'channel' => 'email',
				'kccAgentRef' => 'attacker',
				'endedAt' => '2026-10-12T09:05:00+02:00',
				'tags' => [1, 'b'],
			],
			'agent1'
		);

		$expected = [
			'id' => 'm-1',
			'channel' => 'phone',
			'kccAgentRef' => 'agent1',
			'outcome' => 'resolved',
			'subject' => 'Nieuw',
			'startedAt' => '2026-10-12T09:00:00+02:00',
			'summary' => '5',
			'endedAt' => '2026-10-12T09:05:00+02:00',
			'durationSeconds' => 300,
			'tags' => ['1', 'b'],
		];
		$this->assertSame($expected, $this->objects->saved[0]['object']);
		$this->assertSame('m-1', $this->objects->saved[0]['uuid']);
	}//end testUpdateMergesMutableFields()

	/**
	 * An end is ignored when the stored moment has no start; an unreadable end counts zero seconds.
	 *
	 * @return void
	 */
	public function testUpdateEndNeedsAStart(): void {
		$service = $this->service();
		$this->objects->store['m-1'] = ['id' => 'm-1', 'kccAgentRef' => 'agent1'];
		$this->objects->store['m-2'] = ['id' => 'm-2', 'kccAgentRef' => 'agent1', 'startedAt' => '2026-10-12T09:00:00+02:00'];

		$service->update('m-1', ['endedAt' => '2026-10-12T09:05:00+02:00'], 'agent1');
		$this->assertSame(['id' => 'm-1', 'kccAgentRef' => 'agent1'], $this->objects->saved[0]['object']);

		$service->update('m-2', ['endedAt' => 'yesterday-ish'], 'agent1');
		$this->assertSame('yesterday-ish', $this->objects->saved[1]['object']['endedAt']);
		$this->assertSame(0, $this->objects->saved[1]['object']['durationSeconds']);

		$service->update('m-2', ['endedAt' => ''], 'agent1');
		$this->assertArrayNotHasKey('endedAt', $this->objects->saved[2]['object']);
	}//end testUpdateEndNeedsAStart()

	/**
	 * An outcome outside the list is refused, whether sent or already stored.
	 *
	 * @return void
	 */
	public function testUpdateRefusesAnUnknownOutcome(): void {
		$service = $this->service();
		$this->objects->store['m-1'] = ['id' => 'm-1', 'kccAgentRef' => 'agent1', 'outcome' => 'open'];
		$this->objects->store['m-2'] = ['id' => 'm-2', 'kccAgentRef' => 'agent1', 'outcome' => 'legacy'];

		foreach ([['m-1', ['outcome' => 'banana']], ['m-2', ['subject' => 'x']]] as [$id, $data]) {
			try {
				$service->update($id, $data, 'agent1');
				$this->fail('expected a refusal');
			} catch (OCSBadRequestException $e) {
				$this->assertSame('Invalid outcome', $e->getMessage());
			}
		}

		$this->assertSame([], $this->objects->saved);
	}//end testUpdateRefusesAnUnknownOutcome()

	/**
	 * A moment of another agent, a missing one and a failing lookup all read as not found.
	 *
	 * @return void
	 */
	public function testOwnershipIsEnforcedWithoutDisclosure(): void {
		$service = $this->service();
		$this->objects->store['m-1'] = ['id' => 'm-1', 'kccAgentRef' => 'agent2'];

		foreach (['m-1', 'missing'] as $id) {
			try {
				$service->get($id, 'agent1');
				$this->fail('expected a refusal');
			} catch (OCSBadRequestException $e) {
				$this->assertSame('Contact moment not found', $e->getMessage());
			}
		}

		$this->assertSame('agent2', $service->get('m-1', 'agent1', true)['kccAgentRef']);

		$this->objects->findThrows = true;
		$this->expectExceptionMessage('Contact moment not found');
		$service->update('m-1', [], 'agent2');
	}//end testOwnershipIsEnforcedWithoutDisclosure()

	/**
	 * The list query carries the set filters, and the agent unless privileged.
	 *
	 * @return void
	 */
	public function testListScopesToTheAgent(): void {
		$service = $this->service();
		$this->objects->store['m-1'] = ['id' => 'm-1'];

		$rows = $service->list(['channel' => 'phone', 'outcome' => '', 'assignedTeam' => 'kcc', 'subject' => 'x'], 'agent1');
		$service->list([], 'agent1', true);

		$this->assertSame([['id' => 'm-1']], $rows);
		$this->assertSame(
			['filters' => ['register' => 7, 'schema' => 31, 'channel' => 'phone', 'assignedTeam' => 'kcc', 'kccAgentRef' => 'agent1']],
			$this->objects->queries[0]
		);
		$this->assertSame(['filters' => ['register' => 7, 'schema' => 31]], $this->objects->queries[1]);
	}//end testListScopesToTheAgent()

	/**
	 * Related moments share the customer and leave the asked one out.
	 *
	 * @return void
	 */
	public function testRelatedLeavesTheMomentItselfOut(): void {
		$service = $this->service();
		$this->objects->store['m-1'] = ['id' => 'm-1', 'kccAgentRef' => 'agent1', 'customerRef' => 'c-1'];
		$this->objects->store['m-2'] = ['id' => 'm-2', 'kccAgentRef' => 'agent2', 'customerRef' => 'c-1'];
		$this->objects->store['m-3'] = ['id' => 'm-3', 'kccAgentRef' => 'agent1'];

		$related = $service->related('m-1', 'agent1');

		$this->assertSame(['m-2', 'm-3'], array_column($related, 'id'));
		$this->assertSame(['filters' => ['register' => 7, 'schema' => 31, 'customerRef' => 'c-1']], $this->objects->queries[0]);
		$this->assertSame([], $service->related('m-3', 'agent1'));
	}//end testRelatedLeavesTheMomentItselfOut()
}//end class
