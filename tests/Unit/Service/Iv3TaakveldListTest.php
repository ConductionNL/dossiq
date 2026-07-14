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
            // iv3-taakveld-2023-refinement: refinement codes carry a
            // trailing lowercase letter (e.g. "6.71a").
            $this->assertMatchesRegularExpression('/^\d+\.\d{1,3}[a-z]?$/', $taakveld['code']);
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

    /**
     * geldigVanaf() returns the shipped list's effective date.
     *
     * @return void
     */
    public function testGeldigVanafIsNonEmpty(): void
    {
        $this->assertNotSame('', $this->list->geldigVanaf());
    }//end testGeldigVanafIsNonEmpty()

    /**
     * A deprecated pre-2023 taakveld-6 code remains resolvable — valid,
     * labelled, and flagged deprecated.
     *
     * @return void
     */
    public function testDeprecatedCodeRemainsResolvable(): void
    {
        $this->assertTrue($this->list->isValidCode('6.72'));
        $this->assertSame('Maatwerkdienstverlening 18-', $this->list->labelFor('6.72'));
        $this->assertTrue($this->list->isDeprecated('6.72'));
    }//end testDeprecatedCodeRemainsResolvable()

    /**
     * A 2023-refinement code is valid, labelled, and NOT flagged deprecated.
     *
     * @return void
     */
    public function testRefinementCodeIsNotDeprecated(): void
    {
        $this->assertTrue($this->list->isValidCode('6.72a'));
        $this->assertSame('Jeugdhulp begeleiding', $this->list->labelFor('6.72a'));
        $this->assertFalse($this->list->isDeprecated('6.72a'));
    }//end testRefinementCodeIsNotDeprecated()

    /**
     * An unaffected code (outside taakveld 6's refinement) is never
     * deprecated.
     *
     * @return void
     */
    public function testUnaffectedCodeIsNeverDeprecated(): void
    {
        $this->assertFalse($this->list->isDeprecated('8.1'));
    }//end testUnaffectedCodeIsNeverDeprecated()

    /**
     * Every 2023-refinement code under 6.71 aggregates under its deprecated
     * pre-2023 parent 6.71.
     *
     * @return void
     */
    public function testRefinementCodesAggregateUnderTheirPre2023Parent(): void
    {
        foreach (['6.71a', '6.71b', '6.71c', '6.71d'] as $code) {
            $this->assertSame('6.71', $this->list->aggregationKeyFor($code), $code.' must aggregate under 6.71');
        }

        // The old 6.72 catch-all was split into TEN refinement codes
        // (6.72a-d, 6.73a-c, 6.74a-c) — all ten aggregate under 6.72.
        foreach (['6.72a', '6.72b', '6.72c', '6.72d', '6.73a', '6.73b', '6.73c', '6.74a', '6.74b', '6.74c'] as $code) {
            $this->assertSame('6.72', $this->list->aggregationKeyFor($code), $code.' must aggregate under 6.72');
        }

        foreach (['6.81a', '6.81b'] as $code) {
            $this->assertSame('6.81', $this->list->aggregationKeyFor($code), $code.' must aggregate under 6.81');
        }

        foreach (['6.82a', '6.82b'] as $code) {
            $this->assertSame('6.82', $this->list->aggregationKeyFor($code), $code.' must aggregate under 6.82');
        }
    }//end testRefinementCodesAggregateUnderTheirPre2023Parent()

    /**
     * A deprecated pre-2023 code aggregates under itself (it is its own
     * bucket — the refinement codes fold into it, not the other way round).
     *
     * @return void
     */
    public function testDeprecatedCodeAggregatesUnderItself(): void
    {
        $this->assertSame('6.72', $this->list->aggregationKeyFor('6.72'));
    }//end testDeprecatedCodeAggregatesUnderItself()

    /**
     * A code with no refinement relationship aggregates under itself.
     *
     * @return void
     */
    public function testUnaffectedCodeAggregatesUnderItself(): void
    {
        $this->assertSame('8.1', $this->list->aggregationKeyFor('8.1'));
    }//end testUnaffectedCodeAggregatesUnderItself()

    /**
     * An unknown code passes through aggregationKeyFor() unchanged rather
     * than being dropped.
     *
     * @return void
     */
    public function testUnknownCodeAggregatesUnderItself(): void
    {
        $this->assertSame('99.9', $this->list->aggregationKeyFor('99.9'));
    }//end testUnknownCodeAggregatesUnderItself()

    /**
     * The taakveld-6 renamed codes (6.2, 6.4) resolve to their 2023 labels.
     *
     * @return void
     */
    public function testRenamedCodesResolveToTheir2023Labels(): void
    {
        $this->assertSame('Toegang en eerstelijnsvoorzieningen', $this->list->labelFor('6.2'));
        $this->assertSame('WSW en beschut werk', $this->list->labelFor('6.4'));
    }//end testRenamedCodesResolveToTheir2023Labels()
}//end class
