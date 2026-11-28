<?php

/**
 * The merge rule as the shipped `case` schema declares it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\CaseMergeRule;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * Reading the declared rule and its relink pairs.
 *
 * @covers \OCA\Dossiq\Service\Cases\CaseMergeRule
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseMergeRuleTest extends TestCase {

	/**
	 * The shipped rule declares the relink pairs, each a schema and a field.
	 *
	 * @return void
	 */
	public function testTheShippedRuleDeclaresRelinkPairs(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$rule = new CaseMergeRule(logger: $logger);

		self::assertSame('merged', $rule->rule()['mergedStatus']);
		$pairs = $rule->relinkDeclarations();
		self::assertSame(['schema' => 'role', 'field' => 'case'], $pairs[0]);
		self::assertContains(['schema' => 'caseDocument', 'field' => 'case'], $pairs);
	}//end testTheShippedRuleDeclaresRelinkPairs()

	/**
	 * A relink declaration that is not a list relinks nothing; stray entries are dropped.
	 *
	 * @return void
	 */
	public function testAMalformedRelinkDeclarationRelinksNothing(): void {
		$rule = new CaseMergeRule(logger: $this->createMock(originalClassName: LoggerInterface::class));
		$cached = new ReflectionProperty(CaseMergeRule::class, 'rule');

		$cached->setValue($rule, ['x-dossiq-relink' => 'role:case']);
		self::assertSame([], $rule->relinkDeclarations());

		$cached->setValue($rule, ['x-dossiq-relink' => ['nonsense', ['schema' => 'role', 'field' => 'case'], 7]]);
		self::assertSame([['schema' => 'role', 'field' => 'case']], $rule->relinkDeclarations());
	}//end testAMalformedRelinkDeclarationRelinksNothing()
}//end class
