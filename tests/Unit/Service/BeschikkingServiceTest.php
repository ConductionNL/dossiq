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
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\Beschikking\AuditPacketBuilder;
use OCA\Procest\Service\Beschikking\BeschikkingRepository;
use OCA\Procest\Service\Beschikking\BezwaarTermijnScheduler;
use OCA\Procest\Service\Beschikking\MandaatVerifier;
use OCA\Procest\Service\Beschikking\OpenRegisterArchivalAdapter;
use OCA\Procest\Service\Beschikking\MockSigningAdapter;
use OCA\Procest\Service\Beschikking\MockTemplateEngineAdapter;
use OCA\Procest\Service\BerichtenboxRoutingService;
use OCA\Procest\Service\BeschikkingService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\StateMachineService;
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
class FakeObjectService
{
    /**
     * Stored objects keyed by schema then id.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    public array $store = [];

    /**
     * Auto-increment id counter.
     *
     * @var int
     */
    private int $seq = 0;

    /**
     * Find a single object by id.
     *
     * @param string $id       The object id.
     * @param string $register The register id (named).
     * @param string $schema   The schema id (named).
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $register = '', string $schema = ''): ?array
    {
        return ($this->store[$schema][$id] ?? null);
    }//end find()

    /**
     * Search objects by simple equality filters (real searchObjectsBySlug()).
     *
     * @param string               $register The register slug.
     * @param string               $schema   The schema slug.
     * @param array<string, mixed> $filters  Equality filters.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array
    {
        $rows = array_values($this->store[$schema] ?? []);

        return array_values(array_filter(
            $rows,
            static function (array $row) use ($filters): bool {
                foreach ($filters as $key => $value) {
                    if (($row[$key] ?? null) !== $value) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }//end searchObjectsBySlug()

    /**
     * Persist an object, assigning an id when absent.
     *
     * @param string               $register The register id.
     * @param string               $schema   The schema id.
     * @param array<string, mixed> $object   The object payload.
     *
     * @return array<string, mixed>
     */
    public function saveObject(string $register, string $schema, array $object): array
    {
        if (empty($object['id']) === true) {
            $this->seq++;
            $object['id'] = $schema.'-'.$this->seq;
        }

        $this->store[$schema][$object['id']] = $object;

        return $object;
    }//end saveObject()
}//end class

/**
 * Unit tests for BeschikkingService.
 *
 * @covers \OCA\Procest\Service\BeschikkingService
 */
class BeschikkingServiceTest extends TestCase
{
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
    protected function setUp(): void
    {
        $this->objects = new FakeObjectService();

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($this->objects);
        $settings->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'                  => 'procest',
                    'beschikking_schema'        => 'beschikking',
                    'state_machine_log_schema'  => 'stateMachineLog',
                    'bezwaar_trigger_schema'    => 'bezwaarTrigger',
                    'mandaat_regeling_schema'   => 'mandaatRegeling',
                    default                     => '',
                };
            },
        );

        $logger       = $this->createMock(LoggerInterface::class);
        $stateMachine = new StateMachineService($settings, $logger);
        $routing      = new BerichtenboxRoutingService($logger);

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
        $this->objects->saveObject('procest', 'mandaatRegeling', [
            'id'             => 'mr-2024-007-wmo',
            'naam'           => 'Mandaatregeling WMO',
            'mandaatGroepen' => [
                ['niveau' => 'consulent', 'tot_bedrag' => 5000, 'zaaktypes' => ['wmo-melding'], 'beschikkingTypes' => ['toekenning']],
                ['niveau' => 'afdelingsmanager', 'tot_bedrag' => 25000, 'zaaktypes' => ['wmo-melding'], 'beschikkingTypes' => ['toekenning', 'afwijzing']],
            ],
        ]);
    }//end setUp()

    /**
     * Compose a beschikking in the ontwerp status with a rendered PDF.
     *
     * @return array<string, mixed> The composed beschikking (for chaining).
     */
    private function composeWmo(): array
    {
        $beschikking = $this->service->compose(
            'zaak-2026-wmo-1',
            'tpl-wmo-v1',
            [
                'beschikkingType' => 'toekenning',
                'geadresseerde'   => ['type' => 'burger', 'bsn' => '123456789', 'naam' => 'M. Jansen', 'berichtenboxBevestigd' => true],
                'motivering'      => 'Toegekend op basis van onderzoek.',
            ],
        );

        // The compose path does not set zaaktype/legesbedrag; patch them in
        // (ontwerp status permits edits) so the mandaat lookup can resolve.
        return $this->service->updateFields($beschikking['id'], ['zaaktype' => 'wmo-melding', 'legesbedrag' => 4000]);
    }//end composeWmo()

    /**
     * Composition produces a draft with PDF/A-3 composition metadata. [T05]
     *
     * @return void
     */
    public function testComposeCreatesDraft(): void
    {
        $beschikking = $this->composeWmo();

        $this->assertSame('ontwerp', $beschikking['huidigeStatus']);
        $this->assertSame('pdf-a3', $beschikking['samengesteldeInhoud']['format']);
        $this->assertNotEmpty($beschikking['samengesteldeInhoud']['bestandId']);
    }//end testComposeCreatesDraft()

    /**
     * The full lifecycle reaches gearchiveerd with all evidence recorded. [V01]
     *
     * @return void
     */
    public function testFullLifecycle(): void
    {
        $beschikking = $this->composeWmo();
        $id          = $beschikking['id'];

        $afterAkkoord = $this->service->akkoord($id, 'afdelingsmanager-wmo-15');
        $this->assertSame('akkoord-mandaat', $afterAkkoord['huidigeStatus']);
        $this->assertSame('afdelingsmanager', $afterAkkoord['mandaatGegeven']['mandaatNiveau']);

        $afterSign = $this->service->onderteken($id, 'kpn-gekwalificeerde-handtekening', 'afdelingsmanager-wmo-15');
        $this->assertSame('ondertekend', $afterSign['huidigeStatus']);
        $this->assertNotEmpty($afterSign['handtekening']['validatieRapportId']);

        $afterSend = $this->service->verzend($id, 'afdelingsmanager-wmo-15');
        $this->assertSame('verzonden', $afterSend['huidigeStatus']);
        $this->assertNotEmpty($afterSend['bezwaarTermijnEindDatum']);

        // A BezwaarTrigger was created with a 6-week termijn. [V08]
        $triggers = $this->objects->searchObjectsBySlug('procest', 'bezwaarTrigger', ['beschikkingId' => $id]);
        $this->assertCount(1, $triggers);
        $this->assertTrue($triggers[0]['archiefTriggerActief']);

        $afterArchive = $this->service->archive($id);
        $this->assertSame('gearchiveerd', $afterArchive['huidigeStatus']);
        $this->assertNotEmpty($afterArchive['archief']['archiefId']);
        $this->assertNotEmpty($afterArchive['archief']['vernietigingsdatum']);

        // Every transition was logged. [V05 logging]
        $logs = $this->objects->searchObjectsBySlug('procest', 'stateMachineLog', ['beschikkingId' => $id]);
        $this->assertGreaterThanOrEqual(4, count($logs));
    }//end testFullLifecycle()

    /**
     * Mandaat is rejected when the approver level cannot cover the bedrag. [V03]
     *
     * @return void
     */
    public function testMandaatRejectedWhenOverLimit(): void
    {
        $beschikking = $this->composeWmo();
        // Raise the bedrag above the consulent limit while still ontwerp.
        $beschikking = $this->service->updateFields($beschikking['id'], ['legesbedrag' => 9000]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mandaat_insufficient');

        // A consulent may only sign up to 5000.
        $this->service->akkoord($beschikking['id'], 'consulent-wmo-3');
    }//end testMandaatRejectedWhenOverLimit()

    /**
     * Editing a content field once ondertekend is rejected. [V02]
     *
     * @return void
     */
    public function testImmutabilityAfterSigning(): void
    {
        $beschikking = $this->composeWmo();
        $id          = $beschikking['id'];

        $this->service->akkoord($id, 'afdelingsmanager-wmo-15');
        $this->service->onderteken($id, 'kpn-gekwalificeerde-handtekening', 'afdelingsmanager-wmo-15');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $this->service->updateFields($id, ['motivering' => 'gewijzigd']);
    }//end testImmutabilityAfterSigning()

    /**
     * An invalid transition (verzend before onderteken) is rejected. [V05]
     *
     * @return void
     */
    public function testInvalidTransitionRejected(): void
    {
        $beschikking = $this->composeWmo();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_transition');

        // Cannot verzend straight from ontwerp.
        $this->service->verzend($beschikking['id'], 'afdelingsmanager-wmo-15');
    }//end testInvalidTransitionRejected()

    /**
     * The audit-pakket is a non-empty, verifiable ZIP. [V04]
     *
     * @return void
     */
    public function testAuditPacketIsZip(): void
    {
        $beschikking = $this->composeWmo();
        $id          = $beschikking['id'];

        $this->service->akkoord($id, 'afdelingsmanager-wmo-15');
        $this->service->onderteken($id, 'kpn-gekwalificeerde-handtekening', 'afdelingsmanager-wmo-15');

        $zip = $this->service->exportAuditPacket($id);

        // ZIP local-file-header magic bytes.
        $this->assertSame("PK\x03\x04", substr($zip, 0, 4));
        $this->assertGreaterThan(100, strlen($zip));
    }//end testAuditPacketIsZip()
}//end class
