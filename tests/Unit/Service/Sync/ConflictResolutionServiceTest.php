<?php

/**
 * Tests for ConflictResolutionService: the resolve contract of a conflict record.
 *
 * Each refusal is checked to write nothing, and each success to write exactly
 * the record, the target and the outbox row the contract names.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Sync;

use DateTimeImmutable;
use OCA\Dossiq\Service\Access\ObjectUpdateRight;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Sync\ConflictResolutionOutcome;
use OCA\Dossiq\Service\Sync\ConflictResolutionService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An object as OpenRegister returns it: data through jsonSerialize, metadata through __call.
 */
class ConflictObjectDouble implements \JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data  The object's data.
	 * @param string|null          $owner The owner's uid.
	 */
	public function __construct(
		public readonly array $data,
		private readonly ?string $owner = null,
	) {
	}//end __construct()

	/**
	 * The data with OpenRegister's metadata block, as ObjectEntity serialises.
	 *
	 * @return array<string, mixed> The data.
	 */
	public function jsonSerialize(): array {
		return array_merge($this->data, ['@self' => ['owner' => $this->owner]]);
	}//end jsonSerialize()

	/**
	 * Magic accessors, as Entity has.
	 *
	 * @param string            $name The accessor.
	 * @param array<int, mixed> $args The arguments.
	 *
	 * @return mixed The value.
	 */
	public function __call(string $name, array $args): mixed {
		if ($name === 'getOwner') {
			return $this->owner;
		}

		return null;
	}//end __call()
}//end class

/**
 * An object service holding objects per schema, refusing unknown ids and recording writes.
 */
class ConflictObjectServiceDouble {

	/**
	 * Every patch, in order, as [schema, id, changes].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public array $patches = [];

	/**
	 * Schemas whose writes throw.
	 *
	 * @var array<int, string>
	 */
	public array $failingSchemas = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, ConflictObjectDouble>> $objects Keyed schema, then id.
	 */
	public function __construct(
		public array $objects,
	) {
	}//end __construct()

	/**
	 * Find one object, or throw when absent (as an unreadable object does).
	 *
	 * @param string          $id       The id.
	 * @param string|int|null $register The register.
	 * @param string|int|null $schema   The schema.
	 *
	 * @return ConflictObjectDouble The object.
	 */
	public function find(string $id, string|int|null $register = null, string|int|null $schema = null): ConflictObjectDouble {
		if (isset($this->objects[(string)$schema][$id]) === false) {
			throw new RuntimeException('not found');
		}

		return $this->objects[(string)$schema][$id];
	}//end find()

	/**
	 * Merge the changes onto the stored object and record the patch.
	 *
	 * @param string               $objectId The id.
	 * @param array<string, mixed> $data     The changes.
	 * @param string|int|null      $register The register.
	 * @param string|int|null      $schema   The schema.
	 *
	 * @return ConflictObjectDouble The stored object.
	 */
	public function patchObject(string $objectId, array $data, string|int|null $register = null, string|int|null $schema = null): ConflictObjectDouble {
		if (in_array((string)$schema, $this->failingSchemas, true) === true) {
			throw new RuntimeException('write refused');
		}

		$this->patches[] = [(string)$schema, $objectId, $data];
		$stored = $this->objects[(string)$schema][$objectId];
		$this->objects[(string)$schema][$objectId] = new ConflictObjectDouble(array_merge($stored->data, $data));

		return $this->objects[(string)$schema][$objectId];
	}//end patchObject()
}//end class

/**
 * The resolve contract, check by check.
 *
 * @covers \OCA\Dossiq\Service\Sync\ConflictResolutionService
 * @uses   \OCA\Dossiq\Service\Sync\ConflictResolutionOutcome
 */
class ConflictResolutionServiceTest extends TestCase {

	/**
	 * The office text the record holds.
	 *
	 * @var string
	 */
	private const OFFICE = 'Bijgebouw circa 24 m², zonder vergunning.';

	/**
	 * The device text the record holds.
	 *
	 * @var string
	 */
	private const DEVICE = 'Bijgebouw 4 bij 6 meter, in de groenstrook.';

	/**
	 * The update right double.
	 *
	 * @var ObjectUpdateRight&MockObject
	 */
	private ObjectUpdateRight&MockObject $updateRight;

	/**
	 * Set up the update right double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->updateRight = $this->createMock(ObjectUpdateRight::class);
	}//end setUp()

	/**
	 * Build the service over an object service double.
	 *
	 * @param ConflictObjectServiceDouble|null $objectService The double, or null for no OpenRegister.
	 *
	 * @return ConflictResolutionService The service.
	 */
	private function service(?ConflictObjectServiceDouble $objectService): ConflictResolutionService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? 'dossiq' : '')
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-11-13T10:00:00+00:00'));

		return new ConflictResolutionService(
			settings: $settings,
			updateRight: $this->updateRight,
			time: $time,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * The board's conflict, with a target run and its outbox row.
	 *
	 * @param array<string, mixed> $recordChanges Overrides on the record.
	 * @param string               $runText       The finding text on the run now.
	 *
	 * @return ConflictObjectServiceDouble The double.
	 */
	private function store(array $recordChanges = [], string $runText = self::OFFICE): ConflictObjectServiceDouble {
		$record = array_merge(
			[
				'syncQueueRef' => 'q-1',
				'conflictType' => 'concurrent_edit',
				'targetRegister' => 'dossiq',
				'targetSchema' => 'inspectionChecklistRun',
				'targetId' => 'run-1',
				'conflictFields' => ['responses.0.comment'],
				'clientVersion' => ['responses' => [['itemId' => 'bijgebouwen', 'comment' => self::DEVICE]]],
				'serverVersion' => ['responses' => [['itemId' => 'bijgebouwen', 'comment' => self::OFFICE]]],
			],
			$recordChanges
		);

		return new ConflictObjectServiceDouble(
			[
				'conflictRecord' => ['c-1' => new ConflictObjectDouble($record)],
				'inspectionChecklistRun' => [
					'run-1' => new ConflictObjectDouble(
						[
							'status' => 'open',
							'responses' => [
								['itemId' => 'bijgebouwen', 'comment' => $runText, 'answer' => 'nee'],
								['itemId' => 'dak', 'comment' => 'In orde.'],
							],
						],
						'anja'
					),
				],
				'syncQueue' => ['q-1' => new ConflictObjectDouble(['status' => 'conflict', 'lastError' => '409'])],
			]
		);
	}//end store()

	/**
	 * Keeping the device text writes it onto the run and marks record and outbox row.
	 *
	 * @return void
	 */
	public function testClientWinsWritesTheDeviceTextAndMarksTheRecord(): void {
		$store = $this->store();
		$this->updateRight->expects($this->once())->method('allows')
			->with($store->objects['inspectionChecklistRun']['run-1'], 'inspectionChecklistRun')
			->willReturn(true);

		$outcome = $this->service($store)->resolve(id: 'c-1', resolution: 'client_wins', userId: 'pieter');

		$this->assertSame(200, $outcome->status);
		$this->assertSame(
			['id' => 'c-1', 'resolution' => 'client_wins', 'resolvedBy' => 'pieter', 'resolvedAt' => '2026-11-13T10:00:00+00:00'],
			$outcome->body
		);
		$this->assertSame(
			[
				[
					'inspectionChecklistRun',
					'run-1',
					[
						'responses' => [
							['itemId' => 'bijgebouwen', 'comment' => self::DEVICE, 'answer' => 'nee'],
							['itemId' => 'dak', 'comment' => 'In orde.'],
						],
					],
				],
				['conflictRecord', 'c-1', ['resolution' => 'client_wins', 'resolvedBy' => 'pieter', 'resolvedAt' => '2026-11-13T10:00:00+00:00']],
				['syncQueue', 'q-1', ['status' => 'synced', 'lastError' => '']],
			],
			$store->patches
		);
	}//end testClientWinsWritesTheDeviceTextAndMarksTheRecord()

	/**
	 * Keeping the office text writes nothing to the target and asks no update right.
	 *
	 * @return void
	 */
	public function testServerWinsLeavesTheTargetAlone(): void {
		$store = $this->store();
		$this->updateRight->expects($this->never())->method('allows');

		$outcome = $this->service($store)->resolve(id: 'c-1', resolution: 'server_wins', userId: 'pieter');

		$this->assertSame(200, $outcome->status);
		$this->assertSame('server_wins', $outcome->body['resolution']);
		$this->assertSame(['conflictRecord', 'syncQueue'], array_column($store->patches, 0));
	}//end testServerWinsLeavesTheTargetAlone()

	/**
	 * A record the user cannot read is 404 and nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnreadableRecordIsNotFound(): void {
		$store = $this->store();

		$outcome = $this->service($store)->resolve(id: 'c-unknown', resolution: 'server_wins', userId: 'pieter');

		$this->assertSame(404, $outcome->status);
		$this->assertSame([], $store->patches);
	}//end testAnUnreadableRecordIsNotFound()

	/**
	 * A record already resolved is 409 and nothing is written.
	 *
	 * @return void
	 */
	public function testAnAlreadyResolvedRecordIsRefused(): void {
		$store = $this->store(['resolution' => 'server_wins']);
		$this->updateRight->expects($this->never())->method('allows');

		$outcome = $this->service($store)->resolve(id: 'c-1', resolution: 'client_wins', userId: 'pieter');

		$this->assertSame(409, $outcome->status);
		$this->assertSame(['message' => 'Dit conflict is al opgelost.'], $outcome->body);
		$this->assertSame([], $store->patches);
	}//end testAnAlreadyResolvedRecordIsRefused()

	/**
	 * Keeping the device text on a record without a target id is 422.
	 *
	 * @return void
	 */
	public function testClientWinsWithoutATargetIsUnprocessable(): void {
		$store = $this->store(['targetId' => '']);

		$outcome = $this->service($store)->resolve(id: 'c-1', resolution: 'client_wins', userId: 'pieter');

		$this->assertSame(422, $outcome->status);
		$this->assertSame(['message' => 'Dit conflict noemt geen object om de tekst op te schrijven.'], $outcome->body);
		$this->assertSame([], $store->patches);
	}//end testClientWinsWithoutATargetIsUnprocessable()

	/**
	 * A target the user cannot read is 404.
	 *
	 * @return void
	 */
	public function testAnUnreadableTargetIsNotFound(): void {
		$store = $this->store(['targetId' => 'run-elsewhere']);
		$this->updateRight->expects($this->never())->method('allows');

		$outcome = $this->service($store)->resolve(id: 'c-1', resolution: 'client_wins', userId: 'pieter');

		$this->assertSame(404, $outcome->status);
		$this->assertSame([], $store->patches);
	}//end testAnUnreadableTargetIsNotFound()

	/**
	 * A user without the update right on the target is 403 and nothing is written.
	 *
	 * @return void
	 */
	public function testNoUpdateRightIsForbidden(): void {
		$store = $this->store();
		$this->updateRight->method('allows')->willReturn(false);

		$outcome = $this->service($store)->resolve(id: 'c-1', resolution: 'client_wins', userId: 'pieter');

		$this->assertSame(403, $outcome->status);
		$this->assertSame([], $store->patches);
	}//end testNoUpdateRightIsForbidden()

	/**
	 * When the office changed the text again, the record gets the new text and stays open.
	 *
	 * @return void
	 */
	public function testTheOfficeChangedTheTextAgain(): void {
		$newer = 'Bijgebouw 24 m², vergunning aangevraagd.';
		$store = $this->store([], $newer);
		$this->updateRight->method('allows')->willReturn(true);

		$outcome = $this->service($store)->resolve(id: 'c-1', resolution: 'client_wins', userId: 'pieter');

		$this->assertSame(409, $outcome->status);
		$this->assertSame(['message' => 'Op kantoor is de tekst intussen opnieuw gewijzigd. Kies opnieuw.'], $outcome->body);
		$this->assertSame(
			[['conflictRecord', 'c-1', ['serverVersion' => ['responses' => [['itemId' => 'bijgebouwen', 'comment' => $newer]]]]]],
			$store->patches
		);
		$this->assertArrayNotHasKey('resolution', $store->objects['conflictRecord']['c-1']->data);
	}//end testTheOfficeChangedTheTextAgain()

	/**
	 * A target write OpenRegister refuses leaves the record open.
	 *
	 * @return void
	 */
	public function testAFailedTargetWriteLeavesTheRecordOpen(): void {
		$store = $this->store();
		$store->failingSchemas = ['inspectionChecklistRun'];
		$this->updateRight->method('allows')->willReturn(true);

		$outcome = $this->service($store)->resolve(id: 'c-1', resolution: 'client_wins', userId: 'pieter');

		$this->assertSame(500, $outcome->status);
		$this->assertSame([], $store->patches);
	}//end testAFailedTargetWriteLeavesTheRecordOpen()

	/**
	 * An outbox row that no longer exists does not stop the resolution.
	 *
	 * @return void
	 */
	public function testAMissingOutboxRowIsSkipped(): void {
		$store = $this->store(['syncQueueRef' => 'q-gone']);

		$outcome = $this->service($store)->resolve(id: 'c-1', resolution: 'server_wins', userId: 'pieter');

		$this->assertSame(200, $outcome->status);
		$this->assertSame(['conflictRecord'], array_column($store->patches, 0));
	}//end testAMissingOutboxRowIsSkipped()

	/**
	 * Without OpenRegister nothing is read or written.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheServiceIsUnavailable(): void {
		$outcome = $this->service(null)->resolve(id: 'c-1', resolution: 'server_wins', userId: 'pieter');

		$this->assertSame(503, $outcome->status);
		$this->assertInstanceOf(ConflictResolutionOutcome::class, $outcome);
	}//end testWithoutOpenRegisterTheServiceIsUnavailable()
}//end class
