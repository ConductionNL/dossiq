<?php

/**
 * ChecklistGuard Unit Tests
 *
 * Verifies the guard rejects when the referenced task has unchecked items,
 * honours the requiredItems whitelist, and degrades gracefully when the
 * task store is unreachable.
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

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Transitions\ChecklistGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Procest\Service\Transitions\ChecklistGuard
 *
 * @uses \OCA\Procest\Service\Transitions\GuardResult
 */
class ChecklistGuardTest extends TestCase
{
    /**
     * @return void
     */
    public function testFailsWhenTaskIdMissing(): void
    {
        $guard  = new ChecklistGuard($this->createMock(SettingsService::class), new NullLogger());
        $result = $guard->evaluate(guardConfig: [], case: ['id' => 'c'], userId: 'u');

        self::assertFalse($result->passed);
        self::assertSame('Checklist guard missing taskId', $result->failureMessage);
    }//end testFailsWhenTaskIdMissing()

    /**
     * @return void
     */
    public function testFailsWhenStorageUnavailable(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn(null);

        $guard  = new ChecklistGuard($settings, new NullLogger());
        $result = $guard->evaluate(
            guardConfig: ['taskId' => 't-1'],
            case: ['id' => 'c'],
            userId: 'u',
        );

        self::assertFalse($result->passed);
        self::assertSame('Opslag niet beschikbaar', $result->failureMessage);
    }//end testFailsWhenStorageUnavailable()

    /**
     * @return void
     */
    public function testFailsWhenRegisterOrSchemaMissing(): void
    {
        $objectService = new class {
            public function find(string $id, string $register, string $schema): array
            {
                return [];
            }
        };

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($objectService);
        $settings->method('getConfigValue')->willReturn('');

        $guard  = new ChecklistGuard($settings, new NullLogger());
        $result = $guard->evaluate(
            guardConfig: ['taskId' => 't-1'],
            case: ['id' => 'c'],
            userId: 'u',
        );

        self::assertFalse($result->passed);
        self::assertSame('Taak-register niet geconfigureerd', $result->failureMessage);
    }//end testFailsWhenRegisterOrSchemaMissing()

    /**
     * @return void
     */
    public function testFailsWhenTaskLoadThrows(): void
    {
        $objectService = new class {
            public function find(string $id, string $register, string $schema): array
            {
                throw new RuntimeException('not found');
            }
        };

        $settings = $this->buildSettings($objectService);

        $guard  = new ChecklistGuard($settings, new NullLogger());
        $result = $guard->evaluate(
            guardConfig: ['taskId' => 't-1'],
            case: ['id' => 'c'],
            userId: 'u',
        );

        self::assertFalse($result->passed);
        self::assertSame('Gekoppelde taak niet gevonden', $result->failureMessage);
    }//end testFailsWhenTaskLoadThrows()

    /**
     * @return void
     */
    public function testPassesWhenAllItemsChecked(): void
    {
        $objectService = new class {
            public function find(string $id, string $register, string $schema): array
            {
                return [
                    'checklist' => [
                        ['label' => 'Stuk 1', 'checked' => true],
                        ['label' => 'Stuk 2', 'checked' => true],
                    ],
                ];
            }
        };

        $settings = $this->buildSettings($objectService);

        $guard  = new ChecklistGuard($settings, new NullLogger());
        $result = $guard->evaluate(
            guardConfig: ['taskId' => 't-1'],
            case: ['id' => 'c'],
            userId: 'u',
        );

        self::assertTrue($result->passed);
    }//end testPassesWhenAllItemsChecked()

    /**
     * @return void
     */
    public function testFailsAndListsMissingItems(): void
    {
        $objectService = new class {
            public function find(string $id, string $register, string $schema): array
            {
                return [
                    'checklist' => [
                        ['label' => 'Stuk 1', 'checked' => true],
                        ['label' => 'Stuk 2', 'checked' => false],
                        ['label' => 'Stuk 3', 'checked' => false],
                    ],
                ];
            }
        };

        $settings = $this->buildSettings($objectService);

        $guard  = new ChecklistGuard($settings, new NullLogger());
        $result = $guard->evaluate(
            guardConfig: ['taskId' => 't-1'],
            case: ['id' => 'c'],
            userId: 'u',
        );

        self::assertFalse($result->passed);
        self::assertSame(['Stuk 2', 'Stuk 3'], $result->details['missing']);
    }//end testFailsAndListsMissingItems()

    /**
     * @return void
     */
    public function testHonoursRequiredItemsWhitelist(): void
    {
        // Only require Stuk 2; Stuk 1 unchecked must NOT trip the guard.
        $objectService = new class {
            public function find(string $id, string $register, string $schema): array
            {
                return [
                    'checklist' => [
                        ['label' => 'Stuk 1', 'checked' => false],
                        ['label' => 'Stuk 2', 'checked' => true],
                    ],
                ];
            }
        };

        $settings = $this->buildSettings($objectService);

        $guard  = new ChecklistGuard($settings, new NullLogger());
        $result = $guard->evaluate(
            guardConfig: ['taskId' => 't-1', 'requiredItems' => ['Stuk 2']],
            case: ['id' => 'c'],
            userId: 'u',
        );

        self::assertTrue($result->passed);
    }//end testHonoursRequiredItemsWhitelist()

    /**
     * Build a SettingsService mock returning a configured register+task_schema.
     *
     * @param object $objectService Object-service double
     *
     * @return SettingsService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function buildSettings(object $objectService): SettingsService
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($objectService);
        $settings->method('getConfigValue')->willReturnCallback(
            function (string $key): string {
                return [
                    'register'    => 'reg-1',
                    'task_schema' => 'task-schema',
                ][$key] ?? '';
            }
        );
        return $settings;
    }//end buildSettings()
}//end class
