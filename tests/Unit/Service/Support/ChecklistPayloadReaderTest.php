<?php

/**
 * ChecklistPayloadReader Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inspection-checklists/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Support;

use OCA\Procest\Service\Support\ChecklistPayloadReader;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the checklist payload shape rules.
 *
 * @covers \OCA\Procest\Service\Support\ChecklistPayloadReader
 */
class ChecklistPayloadReaderTest extends TestCase
{

    /**
     * The subject under test.
     *
     * @var ChecklistPayloadReader
     */
    private ChecklistPayloadReader $reader;

    /**
     * Set up the subject.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->reader = new ChecklistPayloadReader();
    }//end setUp()

    /**
     * A run's frozen template wins over any top-level items.
     *
     * A run carries `templateSnapshot`; reading the top level instead would
     * judge the run against a template it was not started under.
     *
     * @return void
     */
    public function testTemplateSnapshotTakesPrecedence(): void
    {
        $items = $this->reader->items(
            [
                'items'            => [['id' => 'stale']],
                'templateSnapshot' => ['items' => [['id' => 'frozen']]],
            ]
        );

        $this->assertSame(['frozen'], array_keys($items));
    }//end testTemplateSnapshotTakesPrecedence()

    /**
     * Flat and sectioned items are both collected, and merged when both exist.
     *
     * @return void
     */
    public function testFlatAndSectionedItemsAreBothCollected(): void
    {
        $items = $this->reader->items(
            [
                'items'    => [['id' => 'flat']],
                'sections' => [
                    ['items' => [['id' => 's1']]],
                    ['items' => [['id' => 's2']]],
                ],
            ]
        );

        $this->assertSame(['flat', 's1', 's2'], array_keys($items));
    }//end testFlatAndSectionedItemsAreBothCollected()

    /**
     * Item ids fall back id → order → position.
     *
     * ⚠️ PHP coerces a numeric-string array key to an int, so an item keyed by
     * `order: 4` lands under int `4`, not `'4'`. Lookups coerce the same way,
     * so `isset($items['4'])` still matches — but an `assertSame` on
     * `array_keys()` sees the int. Asserted loosely here on purpose, with the
     * membership check that actually matters spelled out.
     *
     * @return void
     */
    public function testItemIdFallbackOrder(): void
    {
        $items = $this->reader->items(
            ['items' => [['id' => 'explicit'], ['order' => 4], ['label' => 'positional']]]
        );

        $this->assertEquals(['explicit', '4', '2'], array_keys($items));
        $this->assertArrayHasKey('4', $items, 'a string lookup still resolves the coerced int key');
        $this->assertArrayHasKey('2', $items);
    }//end testItemIdFallbackOrder()

    /**
     * Malformed entries are discarded rather than crashing the reader.
     *
     * @return void
     */
    public function testMalformedEntriesAreDiscarded(): void
    {
        $items = $this->reader->items(['items' => ['not-an-array', 42, ['id' => 'good']]]);
        $this->assertSame(['good'], array_keys($items));

        $this->assertSame([], $this->reader->items(['items' => 'nonsense']));
        $this->assertSame([], $this->reader->responses(['responses' => 'nonsense']));
        $this->assertSame([['itemId' => 'a']], $this->reader->responses(['responses' => ['x', ['itemId' => 'a']]]));
    }//end testMalformedEntriesAreDiscarded()

    /**
     * A later response for the same item wins.
     *
     * @return void
     */
    public function testTheLatestResponseForAnItemWins(): void
    {
        $byId = $this->reader->responsesByItemId(
            ['responses' => [['itemId' => 'a', 'value' => 'first'], ['itemId' => 'a', 'value' => 'second']]]
        );

        $this->assertSame('second', $byId['a']['value']);
    }//end testTheLatestResponseForAnItemWins()

    /**
     * A response with no item id is dropped from the index.
     *
     * @return void
     */
    public function testAResponseWithoutAnItemIdIsDropped(): void
    {
        $this->assertSame([], $this->reader->responsesByItemId(['responses' => [['value' => 'orphan']]]));
    }//end testAResponseWithoutAnItemIdIsDropped()

    /**
     * Any of value / numericValue / choice / photos counts as an answer.
     *
     * @return void
     */
    public function testWhatCountsAsAnswered(): void
    {
        $this->assertTrue($this->reader->isAnswered(['value' => 'ja']));
        $this->assertTrue($this->reader->isAnswered(['numericValue' => 0]));
        $this->assertTrue($this->reader->isAnswered(['choice' => 'b']));
        $this->assertTrue($this->reader->isAnswered(['photos' => ['f1']]));
    }//end testWhatCountsAsAnswered()

    /**
     * Blank, absent and empty-collection responses are NOT answers.
     *
     * Negative control for the test above — a reader returning true
     * unconditionally would pass that one on its own.
     *
     * @return void
     */
    public function testWhatDoesNotCountAsAnswered(): void
    {
        $this->assertFalse($this->reader->isAnswered([]));
        $this->assertFalse($this->reader->isAnswered(['value' => '']));
        $this->assertFalse($this->reader->isAnswered(['value' => null]));
        $this->assertFalse($this->reader->isAnswered(['photos' => []]));
    }//end testWhatDoesNotCountAsAnswered()

    /**
     * The label falls back to the item id when there is no label.
     *
     * @return void
     */
    public function testLabelFallsBackToTheItemId(): void
    {
        $this->assertSame('Fundering', $this->reader->label(['label' => 'Fundering'], 'x'));
        $this->assertSame('x', $this->reader->label([], 'x'));
        $this->assertSame('x', $this->reader->label(['label' => ''], 'x'));
    }//end testLabelFallsBackToTheItemId()
}//end class
