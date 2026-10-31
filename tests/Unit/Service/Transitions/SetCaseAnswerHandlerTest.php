<?php

/**
 * A rule writes a property definition's answer into the case's properties list.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\SetCaseAnswerHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */
class SetCaseAnswerHandlerTest extends TestCase {

	/**
	 * The stored case is read, the one answer replaced or appended, the rest kept, and saved.
	 *
	 * @return void
	 */
	public function testTheAnswerIsMergedIntoTheStoredCase(): void {
		$objects = new class {
			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			/**
			 * @return array<string, mixed>
			 */
			public function find(string $id): array {
				return ['id' => $id, 'properties' => [['name' => 'oppervlakte', 'value' => 80], ['name' => 'welstandsgebied', 'value' => false]]];
			}

			/**
			 * @param array<string, mixed> $object The case.
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				$this->saved[] = [$object, $register, $schema];
				return $object;
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? '');

		$handler = new SetCaseAnswerHandler(assignees: new AssigneeResolver(new NullLogger()), settings: $settings, logger: new NullLogger());

		$this->assertTrue($handler->handle(['name' => 'welstandsgebied', 'value' => true], ['id' => 'case-1'], [])->succeeded);
		$this->assertTrue($handler->handle(['name' => 'bouwlagen', 'value' => 3], ['id' => 'case-1'], [])->succeeded);

		$this->assertSame(
			[['name' => 'oppervlakte', 'value' => 80], ['name' => 'welstandsgebied', 'value' => true]],
			$objects->saved[0][0]['properties']
		);
		$this->assertSame(['dossiq', 'case'], array_slice($objects->saved[0], 1));
		$this->assertSame(['name' => 'bouwlagen', 'value' => 3], $objects->saved[1][0]['properties'][2]);
	}//end testTheAnswerIsMergedIntoTheStoredCase()
}//end class
