<?php

/**
 * BeschikkingService Unit / Lifecycle Tests.
 *
 * Drives the full beschikking lifecycle (compose -> akkoord -> onderteken ->
 * verzend -> archive) against an in-memory ObjectService fake and the real
 * mock cross-app adapters, asserting state transitions, mandaat rejection,
 * immutability, BezwaarTrigger creation, and a verifiable audit-pakket.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\BerichtenboxRoutingService;
use OCA\Dossiq\Service\Beschikking\AuditPacketBuilder;
use OCA\Dossiq\Service\Beschikking\BeschikkingRepository;
use OCA\Dossiq\Service\Beschikking\BezwaarTermijnScheduler;
use OCA\Dossiq\Service\Beschikking\MandaatVerifier;
use OCA\Dossiq\Service\Beschikking\MockSigningAdapter;
use OCA\Dossiq\Service\Beschikking\MockTemplateEngineAdapter;
use OCA\Dossiq\Service\Beschikking\OpenRegisterArchivalAdapter;
use OCA\Dossiq\Service\BeschikkingService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StateMachineService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * In-memory ObjectService fake.
 *
 * Mirrors the subset of the OpenRegister ObjectService API the beschikking
 * pipeline relies on: find (named id/register/schema), searchObjectsBySlug
 * (positional), and saveObject (positional, assigns ids and persists).
 */
class FakeObjectService {

	/**
	 * Stored objects keyed by schema then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * Auto-increment id counter.
	 *
	 * @var integer
	 */
	private int $seq = 0;

	/**
	 * Find a single object by id.
	 *
	 * @param string $id The object id.
	 * @param string $register The register id (named).
	 * @param string $schema The schema id (named).
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $id, string $register = '', string $schema = ''): ?array {
		return ($this->store[$schema][$id] ?? null);
	}//end find()

	/**
	 * Search objects by simple equality filters (real searchObjectsBySlug()).
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $filters Equality filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
		$rows = array_values($this->store[$schema] ?? []);

		return array_values(
			array_filter(
				$rows,
				static function (array $row) use ($filters): bool {
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				},
			)
		);
	}//end searchObjectsBySlug()

	/**
	 * Persist an object, assigning an id when absent.
	 *
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param array<string, mixed> $object The object payload.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(string $register, string $schema, array $object): array {
		if (empty($object['id']) === true) {
			$this->seq++;
			$object['id'] = $schema . '-' . $this->seq;
		}

		$this->store[$schema][$object['id']] = $object;

		return $object;
	}//end saveObject()
}//end class

/**
 * Unit tests for BeschikkingService.
 *
 * @covers \OCA\Dossiq\Service\BeschikkingService
 *
 * @uses \OCA\Dossiq\Service\BerichtenboxRoutingService
 * @uses \OCA\Dossiq\Service\Beschikking\AuditPacketBuilder
 * @uses \OCA\Dossiq\Service\Beschikking\BeschikkingRepository
 * @uses \OCA\Dossiq\Service\Beschikking\BezwaarTermijnScheduler
 * @uses \OCA\Dossiq\Service\Beschikking\MandaatVerifier
 * @uses \OCA\Dossiq\Service\Beschikking\MockSigningAdapter
 * @uses \OCA\Dossiq\Service\Beschikking\MockTemplateEngineAdapter
 * @uses \OCA\Dossiq\Service\Beschikking\OpenRegisterArchivalAdapter
 * @uses \OCA\Dossiq\Service\StateMachineService
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class BeschikkingServiceTest extends TestCase {

	/**
	 * The in-memory object store.
	 *
	 * @var FakeObjectService
	 */
	private FakeObjectService $objects;

	/**
	 * The service under test.
	 *
	 * @var BeschikkingService
	 */
	private BeschikkingService $service;

	/**
	 * Set up fixtures with a wired-up service graph.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeObjectService();

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'procest',
					'beschikking_schema' => 'beschikking',
					'state_machine_log_schema' => 'stateMachineLog',
					'bezwaar_trigger_schema' => 'bezwaarTrigger',
					'mandaat_regeling_schema' => 'mandaatRegeling',
					default => '',
				};
			},
		);

		$logger = $this->createMock(LoggerInterface::class);
		$stateMachine = new StateMachineService($settings, $logger);
		$routing = new BerichtenboxRoutingService($logger);

		$signingAdapter = new MockSigningAdapter();

		$this->service = new BeschikkingService(
			$stateMachine,
			$routing,
			new MockTemplateEngineAdapter(),
			$signingAdapter,
			new OpenRegisterArchivalAdapter($this->createMock(ContainerInterface::class), $logger),
			new BeschikkingRepository($settings, $logger),
			new MandaatVerifier($settings, $logger),
			new AuditPacketBuilder($settings, $signingAdapter, $logger),
			new BezwaarTermijnScheduler($settings, $logger),
		);

		// Seed a WMO mandaatregeling covering the afdelingsmanager level.
		$this->objects->saveObject(
			'procest',
			'mandaatRegeling',
			[
				'id' => 'mr-2024-007-wmo',
				'name' => 'Mandaatregeling WMO',
				'mandateGroups' => [
					['level' => 'consulent', 'to_amount' => 5000, 'caseTypes' => ['wmo-melding'], 'decisionTypes' => ['toekenning']],
					['level' => 'afdelingsmanager', 'to_amount' => 25000, 'caseTypes' => ['wmo-melding'], 'decisionTypes' => ['toekenning', 'rejection']],
				],
			]
		);
	}//end setUp()

	/**
	 * Compose a beschikking in the ontwerp status with a rendered PDF.
	 *
	 * @return array<string, mixed> The composed beschikking (for chaining).
	 */
	private function composeWmo(): array {
		$decision = $this->service->compose(
			'zaak-2026-wmo-1',
			'tpl-wmo-v1',
			[
				'decisionType' => 'toekenning',
				'addressee' => ['type' => 'burger', 'bsn' => '123456789', 'name' => 'M. Jansen', 'messageBoxConfirmed' => true],
				'rationale' => 'Toegekend op basis van onderzoek.',
			],
		);

		// The compose path does not set zaaktype/legesbedrag; patch them in
		// (ontwerp status permits edits) so the mandaat lookup can resolve.
		return $this->service->updateFields($decision['id'], ['caseType' => 'wmo-melding', 'feeAmount' => 4000]);
	}//end composeWmo()

	/**
	 * Composition produces a draft with PDF/A-3 composition metadata. [T05]
	 *
	 * @return void
	 */
	public function testComposeCreatesDraft(): void {
		$decision = $this->composeWmo();

		$this->assertSame('draft', $decision['currentStatus']);
		$this->assertSame('pdf-a3', $decision['compositeContent']['format']);
		$this->assertNotEmpty($decision['compositeContent']['fileId']);
	}//end testComposeCreatesDraft()

	/**
	 * The full lifecycle reaches gearchiveerd with all evidence recorded. [V01]
	 *
	 * @return void
	 */
	public function testFullLifecycle(): void {
		$decision = $this->composeWmo();
		$id = $decision['id'];

		$afterApproved = $this->service->akkoord($id, 'afdelingsmanager-wmo-15');
		$this->assertSame('approved-mandate', $afterApproved['currentStatus']);
		// Outer key renamed; the inner `mandaatNiveau` is nested JSON and is
		// deliberately left Dutch until the JSON-rewrite migration.
		$this->assertSame('afdelingsmanager', $afterApproved['mandateGranted']['mandateLevel']);

		$afterSign = $this->service->onderteken($id, 'kpn-gekwalificeerde-handtekening', 'afdelingsmanager-wmo-15');
		$this->assertSame('signed', $afterSign['currentStatus']);
		$this->assertNotEmpty($afterSign['signature']['validationRapportId']);

		$afterSend = $this->service->verzend($id, 'afdelingsmanager-wmo-15');
		$this->assertSame('sent', $afterSend['currentStatus']);
		$this->assertNotEmpty($afterSend['objectionTermEndDate']);

		// A BezwaarTrigger was created with a 6-week termijn. [V08]
		$triggers = $this->objects->searchObjectsBySlug('procest', 'bezwaarTrigger', ['decisionId' => $id]);
		$this->assertCount(1, $triggers);
		$this->assertTrue($triggers[0]['archiveTriggerActive']);

		$afterArchive = $this->service->archive($id);
		$this->assertSame('archived', $afterArchive['currentStatus']);
		$this->assertNotEmpty($afterArchive['archive']['archiveId']);
		$this->assertNotEmpty($afterArchive['archive']['destructionDate']);

		// Every transition was logged. [V05 logging]
		$logs = $this->objects->searchObjectsBySlug('procest', 'stateMachineLog', ['decisionId' => $id]);
		$this->assertGreaterThanOrEqual(4, count($logs));
	}//end testFullLifecycle()

	/**
	 * Mandaat is rejected when the approver level cannot cover the bedrag. [V03]
	 *
	 * @return void
	 */
	public function testMandaatRejectedWhenOverLimit(): void {
		$decision = $this->composeWmo();
		// Raise the bedrag above the consulent limit while still ontwerp.
		$decision = $this->service->updateFields($decision['id'], ['feeAmount' => 9000]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('mandaat_insufficient');

		// A consulent may only sign up to 5000.
		$this->service->akkoord($decision['id'], 'consulent-wmo-3');
	}//end testMandaatRejectedWhenOverLimit()

	/**
	 * Editing a content field once ondertekend is rejected. [V02]
	 *
	 * @return void
	 */
	public function testImmutabilityAfterSigning(): void {
		$decision = $this->composeWmo();
		$id = $decision['id'];

		$this->service->akkoord($id, 'afdelingsmanager-wmo-15');
		$this->service->onderteken($id, 'kpn-gekwalificeerde-handtekening', 'afdelingsmanager-wmo-15');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('immutable');

		$this->service->updateFields($id, ['rationale' => 'gewijzigd']);
	}//end testImmutabilityAfterSigning()

	/**
	 * An invalid transition (verzend before onderteken) is rejected. [V05]
	 *
	 * @return void
	 */
	public function testInvalidTransitionRejected(): void {
		$decision = $this->composeWmo();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('invalid_transition');

		// Cannot verzend straight from ontwerp.
		$this->service->verzend($decision['id'], 'afdelingsmanager-wmo-15');
	}//end testInvalidTransitionRejected()

	/**
	 * The audit-pakket is a non-empty, verifiable ZIP. [V04]
	 *
	 * @return void
	 */
	public function testAuditPacketIsZip(): void {
		$decision = $this->composeWmo();
		$id = $decision['id'];

		$this->service->akkoord($id, 'afdelingsmanager-wmo-15');
		$this->service->onderteken($id, 'kpn-gekwalificeerde-handtekening', 'afdelingsmanager-wmo-15');

		$zip = $this->service->exportAuditPacket($id);

		// ZIP local-file-header magic bytes.
		$this->assertSame("PK\x03\x04", substr($zip, 0, 4));
		$this->assertGreaterThan(100, strlen($zip));
	}//end testAuditPacketIsZip()
}//end class
