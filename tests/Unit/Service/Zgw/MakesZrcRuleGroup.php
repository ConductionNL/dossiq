<?php

/**
 * A uuid-keyed ObjectService for the ZRC rule group tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
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

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Builds a ZRC rule group over objects answered by uuid.
 *
 * Each rule group takes the same three collaborators and the same context, so
 * the four suites share this instead of each writing its own stub.
 */
trait MakesZrcRuleGroup {
	/**
	 * How often the fake ObjectService was asked for an object.
	 *
	 * @var int
	 */
	private int $finds = 0;

	/**
	 * A rule group of the given class, with a context when objects are given.
	 *
	 * @template T of \OCA\Dossiq\Service\ZgwRulesBase
	 *
	 * @param class-string<T> $class The rule group to build.
	 * @param array<string,mixed>|null $objects Objects by uuid (an array, an object with jsonSerialize(), or a Throwable to throw); null for no context.
	 * @param array $mapping The mapping config.
	 *
	 * @return T
	 */
	private function ruleGroup(string $class, ?array $objects, array $mapping = ['sourceRegister' => '1']): object {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => 'schema:' . $key);

		$rules = new $class(
			logger: $this->createMock(LoggerInterface::class),
			settingsService: $settings,
			fieldValidator: new FieldValidator(),
		);
		if ($objects === null) {
			return $rules;
		}

		$counter = &$this->finds;
		$objectService = new class($objects, $counter) {
			/**
			 * @param array<string,mixed> $objects Objects by uuid.
			 * @param int $finds The call counter.
			 */
			public function __construct(private array $objects, private int &$finds) {
			}

			public function find(string $id, string $register, string $schema): mixed {
				$this->finds++;
				$found = $this->objects[$id . '@' . $schema] ?? ($this->objects[$id] ?? null);
				if ($found instanceof \Throwable) {
					throw $found;
				}

				return $found;
			}
		};
		$rules->setContext(objectService: $objectService, mappingConfig: $mapping);

		return $rules;
	}//end ruleGroup()
}//end trait
