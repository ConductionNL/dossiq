<?php

/**
 * PortalDocumentService Unit Tests
 *
 * Verifies the citizen document ACL: only documents whose downloadbaarVoor
 * overlaps the citizen's role are surfaced; internal documents are removed
 * entirely; a direct download of a non-addressable document is denied.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Zaakportaal
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Zaakportaal;

use OCA\Procest\Service\Zaakportaal\PortalDocumentService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PortalDocumentService.
 *
 * @covers \OCA\Procest\Service\Zaakportaal\PortalDocumentService
 */
class PortalDocumentServiceTest extends TestCase
{

    private PortalDocumentService $service;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new PortalDocumentService();
    }//end setUp()

    /**
     * Internal documents (no citizen ACL) are filtered out entirely.
     *
     * @return void
     */
    public function testInternalDocumentsAreHidden(): void
    {
        $documents = [
            ['id' => 'd1', 'naam' => 'Aanvraag.pdf', 'downloadbaarVoor' => ['aanvrager']],
            ['id' => 'd2', 'naam' => 'Interne advies.pdf', 'downloadbaarVoor' => ['intern']],
            ['id' => 'd3', 'naam' => 'Notitie.pdf'],
        ];

        $visible = $this->service->filterVisible($documents, ['aanvrager']);

        $this->assertCount(1, $visible);
        $this->assertSame('d1', $visible[0]['id']);
    }//end testInternalDocumentsAreHidden()

    /**
     * A geadresseerde sees documents addressed to that role.
     *
     * @return void
     */
    public function testGeadresseerdeSeesTheirDocuments(): void
    {
        $documents = [
            ['id' => 'd1', 'naam' => 'Besluit.pdf', 'downloadbaarVoor' => ['geadresseerde']],
            ['id' => 'd2', 'naam' => 'Aanvraag.pdf', 'downloadbaarVoor' => ['aanvrager']],
        ];

        $visible = $this->service->filterVisible($documents, ['geadresseerde']);

        $this->assertCount(1, $visible);
        $this->assertSame('d1', $visible[0]['id']);
    }//end testGeadresseerdeSeesTheirDocuments()

    /**
     * A non-addressable document download is denied.
     *
     * @return void
     */
    public function testDownloadDeniedForInternalDocument(): void
    {
        $document = ['id' => 'd2', 'downloadbaarVoor' => ['intern']];
        $this->assertFalse($this->service->isDownloadable($document, ['aanvrager']));
    }//end testDownloadDeniedForInternalDocument()

    /**
     * A document with no ACL is treated as internal-only.
     *
     * @return void
     */
    public function testMissingAclIsInternalOnly(): void
    {
        $this->assertFalse($this->service->isDownloadable(['id' => 'd3'], ['aanvrager']));
    }//end testMissingAclIsInternalOnly()

    /**
     * An addressable document is downloadable for the matching role.
     *
     * @return void
     */
    public function testDownloadAllowedForAddressableDocument(): void
    {
        $document = ['id' => 'd1', 'downloadbaarVoor' => ['aanvrager']];
        $this->assertTrue($this->service->isDownloadable($document, ['aanvrager']));
    }//end testDownloadAllowedForAddressableDocument()
}//end class
