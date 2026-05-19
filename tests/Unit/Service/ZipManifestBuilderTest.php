<?php

/**
 * ZipManifestBuilder Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#task-T04
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\ZipManifestBuilder;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\InformatieobjectAccessGuard;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ZipManifestBuilder.
 *
 * @covers \OCA\Procest\Service\ZipManifestBuilder
 */
class ZipManifestBuilderTest extends TestCase
{

    private SettingsService $settingsService;
    private InformatieobjectAccessGuard $accessGuard;
    private LoggerInterface $logger;
    private ZipManifestBuilder $builder;

    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->accessGuard     = $this->createMock(InformatieobjectAccessGuard::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->builder = new ZipManifestBuilder(
            settingsService: $this->settingsService,
            accessGuard: $this->accessGuard,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that buildZip returns a readable temporary file path.
     *
     * @return void
     */
    public function testBuildZipReturnsZipFile(): void
    {
        $user = $this->createMock(IUser::class);

        $this->accessGuard
            ->method('canRead')
            ->willReturn(true);

        $docs = [
            [
                'id'                          => 'doc-1',
                'bestandsnaam'                => 'test.pdf',
                'titel'                       => 'Test Document',
                'informatieobjecttype'        => 'Aanvraag',
                'status'                      => 'concept',
                'vertrouwelijkheidaanduiding' => 'intern',
                'creatiedatum'                => '2026-01-01',
                'auteur'                      => 'Test User',
            ],
        ];

        $zipPath = $this->builder->buildZip(user: $user, informatieobjecten: $docs);

        $this->assertFileExists($zipPath);
        $this->assertStringEndsWith('.zip', $zipPath);

        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $this->assertNotFalse($zip->locateName('manifest.csv'));
        $zip->close();

        unlink($zipPath);
    }//end testBuildZipReturnsZipFile()

    /**
     * Test that buildZip excludes documents the user cannot read.
     *
     * @return void
     */
    public function testBuildZipExcludesInaccessibleDocuments(): void
    {
        $user = $this->createMock(IUser::class);

        $this->accessGuard
            ->method('canRead')
            ->willReturn(false);

        $docs = [
            ['id' => 'doc-1', 'bestandsnaam' => 'secret.pdf', 'informatieobjecttype' => 'Geheim', 'status' => 'definitief', 'vertrouwelijkheidaanduiding' => 'geheim', 'titel' => 'Secret', 'creatiedatum' => '2026-01-01', 'auteur' => 'Admin'],
        ];

        $zipPath = $this->builder->buildZip(user: $user, informatieobjecten: $docs);

        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $manifestContent = $zip->getFromName('manifest.csv');
        $zip->close();

        $this->assertNotFalse($manifestContent);
        $this->assertStringNotContainsString('secret.pdf', $manifestContent);

        unlink($zipPath);
    }//end testBuildZipExcludesInaccessibleDocuments()

    /**
     * Test that buildZip always includes manifest.csv.
     *
     * @return void
     */
    public function testBuildZipAlwaysIncludesManifest(): void
    {
        $user = $this->createMock(IUser::class);
        $this->accessGuard->method('canRead')->willReturn(true);

        $zipPath = $this->builder->buildZip(user: $user, informatieobjecten: []);

        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $this->assertNotFalse($zip->locateName('manifest.csv'));
        $zip->close();

        unlink($zipPath);
    }//end testBuildZipAlwaysIncludesManifest()

}//end class
