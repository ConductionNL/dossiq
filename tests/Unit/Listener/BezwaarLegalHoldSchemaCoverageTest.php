<?php

/**
 * Procest BezwaarLegalHoldListener schema-coverage tests
 *
 * Guards the Awb proceeding schema lists on
 * {@see \OCA\Procest\Listener\BezwaarLegalHoldListener} against the drift that
 * left `beroep` off the opening list: the release side (`appealDecision`) was
 * wired up while the place side was not, so a case under appeal carried no
 * legal hold and stayed destruction-eligible for the whole appeal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Procest\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.procest.app
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Listener;

use OCA\Procest\AppInfo\Registrar\BezwaarSubscriptionRegistrar;
use OCA\Procest\Listener\BezwaarLegalHoldListener;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @coversDefaultClass \OCA\Procest\Listener\BezwaarLegalHoldListener
 */
class BezwaarLegalHoldSchemaCoverageTest extends TestCase
{
    /**
     * Read a private constant off the listener.
     *
     * @param string $name The constant name.
     *
     * @return array<int, string> The constant value.
     */
    private function constant(string $name): array
    {
        $reflection = new ReflectionClass(objectOrClass: BezwaarLegalHoldListener::class);

        return (array) $reflection->getConstant(name: $name);
    }//end constant()

    /**
     * Every Awb proceeding that can be OPENED must place a hold. `beroep`
     * (appeal) suspends destruction exactly as `bezwaar`/`objection` do.
     *
     * @return void
     */
    public function testOpeningSchemasCoverEveryAwbProceeding(): void
    {
        $opens = $this->constant(name: 'PROCEEDING_OPENED_SCHEMAS');

        $this->assertContains(needle: 'bezwaar', haystack: $opens);
        $this->assertContains(needle: 'objection', haystack: $opens);
        $this->assertContains(
            needle: 'beroep',
            haystack: $opens,
            message: 'An open beroep must place a legal hold; without it a case under appeal is destruction-eligible.'
        );
    }//end testOpeningSchemasCoverEveryAwbProceeding()

    /**
     * The place and release sides must stay symmetric: every terminal artefact
     * on the closing list must correspond to a proceeding on the opening list.
     * `appealDecision` terminates `beroep`, `bezwaarDecision` terminates
     * `bezwaar`/`objection`. A closing schema with no opening counterpart is
     * precisely the defect this test exists to catch.
     *
     * @return void
     */
    public function testClosingSchemasHaveAnOpeningCounterpart(): void
    {
        $opens  = $this->constant(name: 'PROCEEDING_OPENED_SCHEMAS');
        $closes = $this->constant(name: 'PROCEEDING_CLOSED_SCHEMAS');

        $counterparts = [
            'appealDecision'  => 'beroep',
            'bezwaarDecision' => 'bezwaar',
        ];

        foreach ($closes as $closingSchema) {
            $this->assertArrayHasKey(
                key: $closingSchema,
                array: $counterparts,
                message: 'Unmapped closing schema "'.$closingSchema.'" — add its opening counterpart.'
            );
            $this->assertContains(
                needle: $counterparts[$closingSchema],
                haystack: $opens,
                message: '"'.$closingSchema.'" releases a hold that "'
                    .$counterparts[$closingSchema].'" never places.'
            );
        }
    }//end testClosingSchemasHaveAnOpeningCounterpart()

    /**
     * The listener is subscribed with a register/schema filter in
     * `BezwaarSubscriptionRegistrar::subscribe()`. That filter is a SECOND,
     * independent list: a schema missing there never reaches `handle()` at all,
     * however correct the constants are. `beroep` was missing from both.
     *
     * @return void
     */
    public function testSubscriptionFilterCoversEveryProceedingSchema(): void
    {
        $declared = (array) (new ReflectionClass(objectOrClass: BezwaarSubscriptionRegistrar::class))
            ->getConstant(name: 'LEGAL_HOLD_SCHEMAS');

        $this->assertNotEmpty(
            actual: $declared,
            message: 'Could not read BezwaarSubscriptionRegistrar::LEGAL_HOLD_SCHEMAS — '
                .'if the constant was renamed this test silently stops guarding anything.'
        );

        $expected = array_merge(
            $this->constant(name: 'PROCEEDING_OPENED_SCHEMAS'),
            $this->constant(name: 'PROCEEDING_CLOSED_SCHEMAS')
        );

        foreach ($expected as $schemaSlug) {
            $this->assertContains(
                needle: $schemaSlug,
                haystack: $declared,
                message: 'Schema "'.$schemaSlug.'" is handled by the listener but absent from its '
                    .'subscription filter, so the listener never runs for it.'
            );
        }
    }//end testSubscriptionFilterCoversEveryProceedingSchema()

    /**
     * The constant guarded above only matters if it is the list actually handed
     * to the dispatcher for THIS listener. Without this pairing check the test
     * above would keep passing against a constant nothing reads.
     *
     * @return void
     */
    public function testLegalHoldSubscriptionUsesTheGuardedConstant(): void
    {
        $source = file_get_contents(
            filename: __DIR__.'/../../../lib/AppInfo/Registrar/BezwaarSubscriptionRegistrar.php'
        );
        $this->assertIsString(actual: $source);

        $matched = preg_match(
            pattern: '/BezwaarLegalHoldListener::class,\s*schemas:\s*self::LEGAL_HOLD_SCHEMAS/',
            subject: $source
        );

        $this->assertSame(
            expected: 1,
            actual: $matched,
            message: 'BezwaarLegalHoldListener is no longer subscribed with self::LEGAL_HOLD_SCHEMAS, '
                .'so the schema-coverage assertion above guards a list nothing uses.'
        );
    }//end testLegalHoldSubscriptionUsesTheGuardedConstant()
}//end class
