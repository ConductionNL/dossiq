<?php

/**
 * Iv3TaakveldList Unit Tests
 *
 * Covers list integrity (well-formed unique codes), known-code label
 * lookups, and unknown-code rejection.
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
 * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/tasks.md#1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\Iv3TaakveldList;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Iv3TaakveldList.
 *
 * @covers \OCA\Procest\Service\Iv3TaakveldList
 */
class Iv3TaakveldListTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var Iv3TaakveldList
     */
    private Iv3TaakveldList $list;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->list = new Iv3TaakveldList();
    }//end setUp()

    /**
     * Every taakveld has a well-formed, unique code, a non-empty label, and
     * a category code in 0..8.
     *
     * @return void
     */
    public function testAllTaakveldenAreWellFormedAndUnique(): void
    {
        $all = $this->list->allTaakvelden();
        $this->assertNotEmpty($all);

        $seenCodes = [];
        foreach ($all as $taakveld) {
            $this->assertMatchesRegularExpression('/^\d+\.\d{1,3}$/', $taakveld['code']);
            $this->assertNotSame('', $taakveld['label']);
            $this->assertContains($taakveld['categoryCode'], ['0', '1', '2', '3', '4', '5', '6', '7', '8']);
            $this->assertArrayNotHasKey($taakveld['code'], $seenCodes, 'Duplicate taakveld code: '.$taakveld['code']);
            $seenCodes[$taakveld['code']] = true;
        }
    }//end testAllTaakveldenAreWellFormedAndUnique()

    /**
     * Nine main categories (0-8) are represented across the flattened list.
     *
     * @return void
     */
    public function testAllNineCategoriesArePresent(): void
    {
        $categories = array_unique(array_column($this->list->allTaakvelden(), 'categoryCode'));
        sort($categories);
        $this->assertSame(['0', '1', '2', '3', '4', '5', '6', '7', '8'], $categories);
    }//end testAllNineCategoriesArePresent()

    /**
     * Known codes resolve to their documented labels.
     *
     * @return void
     */
    public function testKnownCodesResolveToDocumentedLabels(): void
    {
        $this->assertSame('Ruimtelijke ordening', $this->list->labelFor('8.1'));
        $this->assertSame('Milieubeheer', $this->list->labelFor('7.4'));
    }//end testKnownCodesResolveToDocumentedLabels()

    /**
     * A known code is valid.
     *
     * @return void
     */
    public function testKnownCodeIsValid(): void
    {
        $this->assertTrue($this->list->isValidCode('8.1'));
    }//end testKnownCodeIsValid()

    /**
     * An unknown code is invalid and labelFor() returns null.
     *
     * @return void
     */
    public function testUnknownCodeIsInvalid(): void
    {
        $this->assertFalse($this->list->isValidCode('99.9'));
        $this->assertNull($this->list->labelFor('99.9'));
    }//end testUnknownCodeIsInvalid()

    /**
     * version() returns a non-empty string.
     *
     * @return void
     */
    public function testVersionIsNonEmpty(): void
    {
        $this->assertNotSame('', $this->list->version());
        $this->assertNotSame('unknown', $this->list->version());
    }//end testVersionIsNonEmpty()
}//end class
