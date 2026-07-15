<?php

/**
 * CaseModelLoader active-model-resolution tests.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Cmmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-001
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Cmmn;

use OCA\Procest\Service\Cmmn\CaseModelLoader;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Tests\Unit\Service\FakeTermijnStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\Cmmn\CaseModelLoader
 */
final class CaseModelLoaderTest extends TestCase
{

    /**
     * Build a loader over a fake store with the standard CMMN config keys.
     *
     * @param FakeTermijnStore $store The fake object store.
     *
     * @return CaseModelLoader
     */
    private function loader(FakeTermijnStore $store): CaseModelLoader
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($store);
        $settings->method('getConfigValue')->willReturnCallback(
            static fn (string $key): string => match ($key) {
                'register'          => '1',
                'case_model_schema' => '42',
                default              => '',
            },
        );

        return new CaseModelLoader($settings, $this->createMock(LoggerInterface::class));
    }//end loader()

    /**
     * Only the `published` model is returned when a `deprecated` sibling exists.
     *
     * @return void
     */
    public function testResolvesOnlyPublishedModel(): void
    {
        $store = new FakeTermijnStore();
        $store->store['42']['old'] = [
            'id' => 'old', 'caseType' => 'ct-1', 'lifecycleStatus' => 'deprecated', 'title' => 'Old',
            'planItems' => [], 'caseFileItems' => [],
        ];
        $store->store['42']['new'] = [
            'id' => 'new', 'caseType' => 'ct-1', 'lifecycleStatus' => 'published', 'title' => 'New',
            'planItems' => [], 'caseFileItems' => [],
        ];

        $model = $this->loader($store)->getActiveModel(caseTypeId: 'ct-1');
        self::assertNotNull($model);
        self::assertSame('new', $model['id']);
    }//end testResolvesOnlyPublishedModel()

    /**
     * No published model for a caseType returns null, not an error.
     *
     * @return void
     */
    public function testNullWhenNoPublishedModel(): void
    {
        $store = new FakeTermijnStore();
        $store->store['42']['draft-only'] = [
            'id' => 'draft-only', 'caseType' => 'ct-2', 'lifecycleStatus' => 'draft', 'planItems' => [], 'caseFileItems' => [],
        ];

        self::assertNull($this->loader($store)->getActiveModel(caseTypeId: 'ct-2'));
        self::assertNull($this->loader($store)->getActiveModel(caseTypeId: 'ct-unknown'));
    }//end testNullWhenNoPublishedModel()

    /**
     * `planItems`/`caseFileItems` JSON-string fields are decoded to arrays.
     *
     * @return void
     */
    public function testDecodesJsonStringFields(): void
    {
        $store = new FakeTermijnStore();
        $store->store['42']['m1'] = [
            'id'             => 'm1',
            'caseType'       => 'ct-3',
            'lifecycleStatus' => 'published',
            'planItems'      => json_encode([['id' => 'stage-1', 'type' => 'stage', 'name' => 'S1']]),
            'caseFileItems'  => json_encode([['id' => 'x', 'name' => 'X', 'type' => 'string']]),
        ];

        $model = $this->loader($store)->getActiveModel(caseTypeId: 'ct-3');
        self::assertIsArray($model['planItems']);
        self::assertSame('stage-1', $model['planItems'][0]['id']);
        self::assertIsArray($model['caseFileItems']);
    }//end testDecodesJsonStringFields()

    /**
     * `getPlanItemById()` finds a specific item within the active model.
     *
     * @return void
     */
    public function testGetPlanItemById(): void
    {
        $store = new FakeTermijnStore();
        $store->store['42']['m1'] = [
            'id' => 'm1', 'caseType' => 'ct-4', 'lifecycleStatus' => 'published',
            'planItems' => [['id' => 'task-1', 'type' => 'humanTask', 'name' => 'T1']],
            'caseFileItems' => [],
        ];

        $loader = $this->loader($store);
        $item   = $loader->getPlanItemById(caseTypeId: 'ct-4', itemId: 'task-1');
        self::assertNotNull($item);
        self::assertSame('humanTask', $item['type']);
        self::assertNull($loader->getPlanItemById(caseTypeId: 'ct-4', itemId: 'nope'));
    }//end testGetPlanItemById()
}//end class
