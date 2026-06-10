<?php

/**
 * Unit tests for the archief-edepot services.
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

use OCA\Procest\Service\ArchiefEdepotSeedDataService;
use OCA\Procest\Service\ArchivalTriggerService;
use OCA\Procest\Service\BagItBundlerService;
use OCA\Procest\Service\MetadataBundlerService;
use OCA\Procest\Service\ProofOfTransferService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\ArchiefEdepotSeedDataService
 * @covers \OCA\Procest\Service\ArchivalTriggerService
 * @covers \OCA\Procest\Service\MetadataBundlerService
 * @covers \OCA\Procest\Service\BagItBundlerService
 * @covers \OCA\Procest\Service\ProofOfTransferService
 */
class ArchivalServicesTest extends TestCase
{
    private FakeTermijnStore $objects;
    private SettingsService $settings;

    protected function setUp(): void
    {
        $this->objects = new FakeTermijnStore();
        $settings      = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($this->objects);
        $settings->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'                          => 'procest',
                    'bewaar_termijn_regel_schema'       => 'bewaarTermijnRegel',
                    'overdracht_trigger_schema'         => 'overdrachtTrigger',
                    'sip_bundel_schema'                 => 'sipBundel',
                    'overdracht_transactie_schema'      => 'overdrachtTransactie',
                    'archief_bewijs_schema'             => 'archiefBewijs',
                    'overdracht_audit_log_schema'       => 'overdrachtAuditLog',
                    default                              => '',
                };
            },
        );
        $this->settings = $settings;
    }

    /**
     * @return void
     */
    public function testTriggerDetectionBranchesOnRulePresence(): void
    {
        $this->objects->saveObject('procest', 'bewaarTermijnRegel', [
            'id' => 'btr-1',
            'zaaktypeKey' => 'omgevingsvergunning-regulier',
            'bewaartermijnJaren' => 5,
            'isActive' => true,
        ]);

        $svc = new ArchivalTriggerService($this->settings, $this->createMock(LoggerInterface::class));
        $counts = $svc->detectReadyCases([
            ['id' => 'C/1', 'caseType' => 'omgevingsvergunning-regulier', 'closedAt' => '2026-01-15'],
            ['id' => 'C/2', 'caseType' => 'unknown-type', 'closedAt' => '2026-01-15'],
            ['id' => 'C/3', 'caseType' => 'omgevingsvergunning-regulier', 'closedAt' => '2026-01-15', 'hasActiveBezwaar' => true],
        ]);
        self::assertSame(['ready' => 1, 'blocked' => 1, 'suspended' => 1, 'errors' => 0], $counts);

        $triggers = array_values($this->objects->store['overdrachtTrigger']);
        $statuses = array_column($triggers, 'status');
        self::assertContains('gereed-voor-overdracht', $statuses);
        self::assertContains('geblokkeerd-geen-regel', $statuses);
        self::assertContains('opgeschort-juridische-procedure', $statuses);

        // overdrachtDatum on the ready one = closedAt + 5y.
        $ready = array_values(array_filter($triggers, static fn (array $t): bool => $t['status'] === 'gereed-voor-overdracht'));
        self::assertSame('2031-01-15', $ready[0]['overdrachtDatum']);
    }

    /**
     * @return void
     */
    public function testMetadataBundlerProducesValidMdto(): void
    {
        $svc = new MetadataBundlerService($this->settings, $this->createMock(LoggerInterface::class));
        $bundle = $svc->buildBundle(
            ['id' => 'C/4', 'title' => 'Permit X', 'caseType' => 'omgevingsvergunning-regulier', 'closedAt' => '2026-01-15', 'handler' => 'jane'],
            ['mdtoVersion' => '1.1', 'bewaartermijnJaren' => 5, 'selectielijstCategorie' => 'VNG 4.3.1'],
            [['name' => 'permit.pdf', 'documentType' => 'beschikking', 'mimeType' => 'application/pdf']]
        );
        self::assertStringContainsString('<mdto:MDTO', $bundle['metadataXml']);
        $valid = $svc->validateXsd($bundle['metadataXml']);
        self::assertTrue($valid['valid'], 'Errors: '.implode(';', $valid['errors']));

        $sip = $svc->createSipBundel('C/4', $bundle['metadataXml'], $bundle['documents']);
        self::assertTrue($sip['metadataXsdValid']);
        self::assertSame('prepared', $sip['status']);
    }

    /**
     * @return void
     */
    public function testMetadataBundlerRejectsMalformed(): void
    {
        $svc = new MetadataBundlerService($this->settings, $this->createMock(LoggerInterface::class));
        $r = $svc->validateXsd('<not-mdto/>');
        self::assertFalse($r['valid']);
    }

    /**
     * @return void
     */
    public function testBagItBuilderProducesManifestAndOxum(): void
    {
        $svc = new BagItBundlerService($this->settings, $this->createMock(LoggerInterface::class));
        $bag = $svc->buildBagIt([
            'metadataXml' => '<mdto:MDTO/>',
            'documents'   => [
                ['name' => 'a.pdf', 'content' => "AAAA"],
                ['name' => 'b.pdf', 'content' => "BBBBB"],
            ],
        ]);

        self::assertArrayHasKey('data/metadata.xml', $bag['files']);
        self::assertArrayHasKey('data/a.pdf', $bag['files']);
        self::assertArrayHasKey('manifest-sha256.txt', $bag['files']);
        self::assertArrayHasKey('bagit.txt', $bag['files']);
        self::assertArrayHasKey('bag-info.txt', $bag['files']);
        self::assertStringContainsString('BagIt-Version: 1.0', $bag['files']['bagit.txt']);

        // Oxum = total payload bytes . payload count (3 payload files).
        // metadata.xml (12) + a.pdf (4) + b.pdf (5) = 21 bytes, 3 files.
        self::assertSame('21.3', $bag['payloadOxum']);
        self::assertSame(64, strlen($bag['manifestChecksum'])); // sha256 hex
    }

    /**
     * @return void
     */
    public function testProofOfTransferVerifiesChecksumMatch(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $trigger = new ArchivalTriggerService($this->settings, $logger);
        $svc = new ProofOfTransferService($this->settings, $trigger, $logger);

        // Seed SIP with a known manifest checksum.
        $this->objects->saveObject('procest', 'sipBundel', [
            'id' => 'sip-1',
            'zaakId' => 'C/5',
            'manifestChecksum' => 'aaaaaaaaaaaa',
        ]);

        $bewijs = $svc->createArchiefBewijs(
            'C/5', 'ARCH-1', 'gemeente-edepot', 'sip-1', 'OK', ['sha256' => 'aaaaaaaaaaaa']
        );
        self::assertSame('verified', $bewijs['status']);

        // Now a mismatch case.
        $this->objects->saveObject('procest', 'sipBundel', [
            'id' => 'sip-2',
            'zaakId' => 'C/6',
            'manifestChecksum' => 'aaaaaaaaaaaa',
        ]);
        $bewijs2 = $svc->createArchiefBewijs(
            'C/6', 'ARCH-2', 'gemeente-edepot', 'sip-2', 'OK', ['sha256' => 'zzzzzzzzzzzz']
        );
        self::assertSame('alert-mismatch', $bewijs2['status']);
    }

    /**
     * @return void
     */
    public function testRecommendCorrectiveActionForKnownErrors(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $svc = new ProofOfTransferService(
            $this->settings,
            new ArchivalTriggerService($this->settings, $logger),
            $logger
        );
        self::assertStringContainsString('MDTO', $svc->recommendCorrectiveAction('MDTO_VALIDATION_FAILED'));
        self::assertStringContainsString('PDF/A', $svc->recommendCorrectiveAction('DOCUMENT_CONVERSION_FAILED'));
        self::assertStringContainsString('capacit', $svc->recommendCorrectiveAction('E_DEPOT_CAPACITY_EXCEEDED'));
        self::assertStringContainsString('Onbekende', $svc->recommendCorrectiveAction('FOOBAR'));
    }

    /**
     * @return void
     */
    public function testSeedRegelenIsIdempotent(): void
    {
        $svc = new ArchiefEdepotSeedDataService($this->settings, $this->createMock(LoggerInterface::class));
        $r1 = $svc->seed();
        self::assertSame(3, $r1['regels']);
        $r2 = $svc->seed();
        self::assertSame(0, $r2['regels']);
        self::assertSame(3, $r2['skipped']);
    }
}
