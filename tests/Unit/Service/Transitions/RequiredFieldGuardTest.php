<?php

/**
 * RequiredFieldGuard Unit Tests
 *
 * Verifies that the requiredField guard treats null, '', and empty-array
 * values as missing, and passes for any non-empty scalar/array.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-19
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Transitions;

use OCA\Procest\Service\Transitions\RequiredFieldGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Service\Transitions\RequiredFieldGuard
 *
 * @uses \OCA\Procest\Service\Transitions\GuardResult
 */
class RequiredFieldGuardTest extends TestCase
{
    /**
     * @return void
     */
    public function testFailsWhenFieldConfigMissing(): void
    {
        $guard  = new RequiredFieldGuard();
        $result = $guard->evaluate(guardConfig: [], case: ['id' => 'c'], userId: 'u');

        self::assertFalse($result->passed);
        self::assertSame('Required-field guard missing field', $result->failureMessage);
    }//end testFailsWhenFieldConfigMissing()

    /**
     * @return void
     */
    public function testFailsWhenFieldIsNull(): void
    {
        $guard  = new RequiredFieldGuard();
        $result = $guard->evaluate(
            guardConfig: ['field' => 'resultaat'],
            case: ['id' => 'c'],
            userId: 'u',
        );

        self::assertFalse($result->passed);
        self::assertSame('resultaat', $result->details['field']);
    }//end testFailsWhenFieldIsNull()

    /**
     * @return void
     */
    public function testFailsWhenFieldIsEmptyString(): void
    {
        $guard  = new RequiredFieldGuard();
        $result = $guard->evaluate(
            guardConfig: ['field' => 'resultaat'],
            case: ['resultaat' => ''],
            userId: 'u',
        );

        self::assertFalse($result->passed);
    }//end testFailsWhenFieldIsEmptyString()

    /**
     * @return void
     */
    public function testFailsWhenFieldIsEmptyArray(): void
    {
        $guard  = new RequiredFieldGuard();
        $result = $guard->evaluate(
            guardConfig: ['field' => 'tags'],
            case: ['tags' => []],
            userId: 'u',
        );

        self::assertFalse($result->passed);
    }//end testFailsWhenFieldIsEmptyArray()

    /**
     * @return void
     */
    public function testPassesWhenFieldHasValue(): void
    {
        $guard  = new RequiredFieldGuard();
        $result = $guard->evaluate(
            guardConfig: ['field' => 'resultaat'],
            case: ['resultaat' => 'toegewezen'],
            userId: 'u',
        );

        self::assertTrue($result->passed);
        self::assertSame('resultaat', $result->details['field']);
    }//end testPassesWhenFieldHasValue()

    /**
     * @return void
     */
    public function testPassesForZeroNumericValue(): void
    {
        // 0 is a legitimate value, not "empty".
        $guard  = new RequiredFieldGuard();
        $result = $guard->evaluate(
            guardConfig: ['field' => 'bedrag'],
            case: ['bedrag' => 0],
            userId: 'u',
        );

        self::assertTrue($result->passed);
    }//end testPassesForZeroNumericValue()
}//end class
