<?php

/**
 * SetFieldHandler Unit Tests
 *
 * Verifies the setField action handler updates a named case field via OR,
 * resolves the `__now__` macro to an ISO-8601 timestamp, and surfaces
 * missing-field/storage/exception envelopes.
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
 * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-20
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Transitions;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Transitions\SetFieldHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Procest\Service\Transitions\SetFieldHandler
 */
class SetFieldHandlerTest extends TestCase
{
    /**
     * @return void
     */
    public function testFailsWhenFieldMissing(): void
    {
        $handler = new SetFieldHandler(
            settingsService: $this->createMock(SettingsService::class),
            logger: new NullLogger(),
        );

        $result = $handler->handle(
            actionConfig: ['type' => 'setField', 'value' => 'x'],
            case: ['id' => 'c'],
            transitionContext: [],
        );

        self::assertFalse($result->ok);
        self::assertSame('set_field_missing_field', $result->error);
    }//end testFailsWhenFieldMissing()

    /**
     * @return void
     */
    public function testFailsWhenObjectServiceUnavailable(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn(null);

        $handler = new SetFieldHandler($settings, new NullLogger());

        $result = $handler->handle(
            actionConfig: ['type' => 'setField', 'field' => 'einddatum'],
            case: ['id' => 'c'],
            transitionContext: [],
        );

        self::assertFalse($result->ok);
        self::assertSame('storage_unavailable', $result->error);
    }//end testFailsWhenObjectServiceUnavailable()

    /**
     * @return void
     */
    public function testWritesFieldOnCase(): void
    {
        $recorded = null;
        $objectService = new class($recorded) {
            /** @var mixed */
            public $recorded;

            public function __construct(&$recorded)
            {
                $this->recorded = &$recorded;
            }

            public function saveObject(array $object, string $register, string $schema): array
            {
                $this->recorded = $object;
                return $object;
            }
        };

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($objectService);
        $settings->method('getConfigValue')->willReturnCallback(
            function (string $key): string {
                return [
                    'register'    => 'reg-1',
                    'case_schema' => 'case-schema',
                ][$key] ?? '';
            }
        );

        $handler = new SetFieldHandler($settings, new NullLogger());

        $result = $handler->handle(
            actionConfig: ['type' => 'setField', 'field' => 'resultaat', 'value' => 'toegewezen'],
            case: ['id' => 'case-1', 'resultaat' => null],
            transitionContext: [],
        );

        self::assertTrue($result->ok);
        self::assertSame('resultaat', $result->data['field']);
        self::assertSame('toegewezen', $recorded['resultaat']);
    }//end testWritesFieldOnCase()

    /**
     * @return void
     */
    public function testResolvesNowMacro(): void
    {
        $recorded = null;
        $objectService = new class($recorded) {
            /** @var mixed */
            public $recorded;

            public function __construct(&$recorded)
            {
                $this->recorded = &$recorded;
            }

            public function saveObject(array $object, string $register, string $schema): array
            {
                $this->recorded = $object;
                return $object;
            }
        };

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($objectService);
        $settings->method('getConfigValue')->willReturnCallback(
            function (string $key): string {
                return [
                    'register'    => 'reg-1',
                    'case_schema' => 'case-schema',
                ][$key] ?? '';
            }
        );

        $handler = new SetFieldHandler($settings, new NullLogger());

        $result = $handler->handle(
            actionConfig: ['type' => 'setField', 'field' => 'einddatum', 'value' => '__now__'],
            case: ['id' => 'case-1'],
            transitionContext: [],
        );

        self::assertTrue($result->ok);
        // ISO-8601 ATOM format: YYYY-MM-DDTHH:MM:SS+ZZ:ZZ.
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/',
            (string) $recorded['einddatum'],
        );
    }//end testResolvesNowMacro()

    /**
     * @return void
     */
    public function testCatchesExceptionFromObjectService(): void
    {
        $objectService = new class {
            public function saveObject(array $object, string $register, string $schema): array
            {
                throw new RuntimeException('boom');
            }
        };

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($objectService);
        $settings->method('getConfigValue')->willReturnCallback(
            function (string $key): string {
                return [
                    'register'    => 'reg-1',
                    'case_schema' => 'case-schema',
                ][$key] ?? '';
            }
        );

        $handler = new SetFieldHandler($settings, new NullLogger());

        $result = $handler->handle(
            actionConfig: ['type' => 'setField', 'field' => 'x', 'value' => 'y'],
            case: ['id' => 'c'],
            transitionContext: [],
        );

        self::assertFalse($result->ok);
        self::assertSame('set_field_failed', $result->error);
    }//end testCatchesExceptionFromObjectService()
}//end class
