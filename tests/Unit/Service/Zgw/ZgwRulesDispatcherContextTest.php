<?php

/**
 * Zgw Rules Dispatcher Context Test
 *
 * The dispatcher hands the request context (ObjectService and mapping) to
 * every rule group it routes to, so no group evaluates a rule against a
 * stale or missing register.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZgwRulesDispatcher;
use OCA\Dossiq\Service\Zgw\ZgwZrcZaakinformatieobjectRules;
use OCA\Dossiq\Service\Zgw\ZgwZtcResultaattypeRules;
use OCA\Dossiq\Service\ZgwBrcRulesService;
use OCA\Dossiq\Service\ZgwDrcRulesService;
use OCA\Dossiq\Service\ZgwZrcRulesService;
use OCA\Dossiq\Service\ZgwZtcRulesService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Zgw\ZgwRulesDispatcher
 */
class ZgwRulesDispatcherContextTest extends TestCase {

	/**
	 * The six rule groups, in constructor order.
	 *
	 * @var array<int, class-string>
	 */
	private const GROUPS = [
		ZgwZrcRulesService::class,
		ZgwZtcRulesService::class,
		ZgwDrcRulesService::class,
		ZgwBrcRulesService::class,
		ZgwZrcZaakinformatieobjectRules::class,
		ZgwZtcResultaattypeRules::class,
	];

	public function testTheContextReachesEveryRuleGroup(): void {
		$objectService = new \stdClass();
		$mapping = ['zaak' => ['sourceRegister' => 'dossiq', 'sourceSchema' => 'case']];

		$groups = [];
		foreach (self::GROUPS as $class) {
			$group = $this->createMock($class);
			$group->expects($this->once())->method('setContext')->with($this->identicalTo($objectService), $mapping);
			$groups[] = $group;
		}

		(new ZgwRulesDispatcher(...$groups))->setContext($objectService, $mapping);
	}

	public function testAnEmptyContextIsPassedOnAsEmpty(): void {
		$groups = [];
		foreach (self::GROUPS as $class) {
			$group = $this->createMock($class);
			$group->expects($this->once())->method('setContext')->with(null, null);
			$groups[] = $group;
		}

		(new ZgwRulesDispatcher(...$groups))->setContext(null, null);
	}
}//end class
