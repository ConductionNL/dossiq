<?php

/**
 * BeroepDossierExport Unit Tests.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
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

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\BeroepDossierExport;
use OCA\Procest\Service\DossierCompiler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for BeroepDossierExport.
 *
 * @covers \OCA\Procest\Service\BeroepDossierExport
 */
class BeroepDossierExportTest extends TestCase
{

    /**
     * @var DossierCompiler|\PHPUnit\Framework\MockObject\MockObject
     */
    private DossierCompiler $compiler;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var BeroepDossierExport
     */
    private BeroepDossierExport $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->compiler = $this->createMock(DossierCompiler::class);
        $this->logger   = $this->createMock(LoggerInterface::class);
        $this->service  = new BeroepDossierExport($this->compiler, $this->logger);
    }//end setUp()

    /**
     * The plan numbers documents sequentially, slugifies titles, and
     * preserves source extensions (defaulting to pdf).
     *
     * @return void
     */
    public function testBuildPlanNumbersAndNamesDocuments(): void
    {
        $this->compiler->method('compile')->willReturn(
            [
                ['title' => 'Primair besluit', 'document' => 'nc://p/besluit.pdf', '_sourceCase' => 'p1'],
                ['title' => 'Bezwaarschrift van indiener', 'document' => 'nc://b/bezwaar.docx', '_sourceCase' => 'b1'],
                ['title' => '', 'document' => 'nc://b/onbekend', '_sourceCase' => 'b1'],
            ]
        );

        $plan = $this->service->buildPlan('beroep-1');

        $this->assertSame('beroep-1', $plan['case']);
        $this->assertSame(3, $plan['documentCount']);

        $this->assertSame('01-primair-besluit.pdf', $plan['entries'][0]['filename']);
        $this->assertSame('02-bezwaarschrift-van-indiener.docx', $plan['entries'][1]['filename']);
        // Empty title falls back to "document"; no extension defaults to pdf.
        $this->assertSame('03-document.pdf', $plan['entries'][2]['filename']);

        $this->assertSame(1, $plan['entries'][0]['sequence']);
        $this->assertSame('p1', $plan['entries'][0]['sourceCase']);
    }//end testBuildPlanNumbersAndNamesDocuments()

    /**
     * An empty dossier produces a zero-count plan.
     *
     * @return void
     */
    public function testBuildPlanHandlesEmptyDossier(): void
    {
        $this->compiler->method('compile')->willReturn([]);

        $plan = $this->service->buildPlan('beroep-2');

        $this->assertSame(0, $plan['documentCount']);
        $this->assertSame([], $plan['entries']);
    }//end testBuildPlanHandlesEmptyDossier()
}//end class
