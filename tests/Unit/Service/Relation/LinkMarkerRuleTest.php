<?php

/**
 * A marker rule holds exactly when every condition does, and a rule this class
 * cannot evaluate is refused when it is read.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Relation
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
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use InvalidArgumentException;
use OCA\Dossiq\Service\Relation\LinkMarkerRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Relation\LinkMarkerRule
 */
class LinkMarkerRuleTest extends TestCase {

	/**
	 * The fixture rule: the follow-up is open while the target is closed, the
	 * source names what it is about, has no final outcome, and was filed in time.
	 *
	 * @return LinkMarkerRule The rule.
	 */
	private function rule(): LinkMarkerRule {
		return new LinkMarkerRule(
			config: [
				'source' => 'appeal_schema',
				'field'  => 'sourceRecord',
				'target' => 'record_schema',
				'marker' => 'followUpOpen',
				'when'   => [
					['on' => 'target', 'field' => 'status', 'in' => ['closed', 'refused']],
					['field' => 'subject', 'present' => true],
					['field' => 'outcome', 'notIn' => ['granted', 'dismissed']],
					['field' => 'filedOn', 'notAfter' => 'filingDeadline'],
				],
			]
		);
	}//end rule()

	/**
	 * Source and target pairs, and whether the marker holds.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool}>
	 */
	public static function cases(): array {
		$source = ['subject' => 'dec-1', 'filedOn' => '2026-03-01', 'filingDeadline' => '2026-03-10'];
		$closed = ['status' => 'closed'];

		return [
			'every condition holds'      => [$source, $closed, true],
			'target still open'          => [$source, ['status' => 'open'], false],
			'no subject'                 => [array_merge($source, ['subject' => '']), $closed, false],
			'final outcome'              => [array_merge($source, ['outcome' => 'granted']), $closed, false],
			'outcome not final'          => [array_merge($source, ['outcome' => 'pending']), $closed, true],
			'filed late'                 => [array_merge($source, ['filedOn' => '2026-03-11']), $closed, false],
			'filed on the deadline'      => [array_merge($source, ['filedOn' => '2026-03-10']), $closed, true],
			'not filed'                  => [array_merge($source, ['filedOn' => null]), $closed, false],
			'no deadline yet'            => [array_merge($source, ['filingDeadline' => '']), $closed, true],
		];
	}//end cases()

	/**
	 * The marker holds exactly when every condition does.
	 *
	 * @param array<string, mixed> $source The source.
	 * @param array<string, mixed> $target The target.
	 * @param bool                 $holds  Whether it holds.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheMarkerFollowsTheConditions(array $source, array $target, bool $holds): void {
		self::assertSame($holds, $this->rule()->holds(source: $source, target: $target));
	}//end testTheMarkerFollowsTheConditions()

	/**
	 * Malformed rules, and what each refusal must name.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function malformed(): array {
		$base = ['source' => 's', 'field' => 'f', 'target' => 't', 'marker' => 'm'];

		return [
			'no marker'           => [['source' => 's', 'field' => 'f', 'target' => 't'], '`marker`'],
			'when not a list'     => [array_merge($base, ['when' => 'always']), '`when`'],
			'condition no field'  => [array_merge($base, ['when' => [['in' => ['a']]]]), '`field`'],
			'unknown question'    => [array_merge($base, ['when' => [['field' => 'x', 'matches' => 'a']]]), 'exactly one'],
			'two questions'       => [array_merge($base, ['when' => [['field' => 'x', 'in' => ['a'], 'present' => true]]]), 'exactly one'],
			'wrong type'          => [array_merge($base, ['when' => [['field' => 'x', 'in' => 'a']]]), 'wrong type'],
			'unknown side'        => [array_merge($base, ['when' => [['on' => 'case', 'field' => 'x', 'present' => true]]]), "'case'"],
		];
	}//end malformed()

	/**
	 * A rule this class cannot evaluate is refused when read.
	 *
	 * @param mixed  $config The rule.
	 * @param string $names  What the refusal names.
	 *
	 * @return void
	 */
	#[DataProvider('malformed')]
	public function testAMalformedRuleIsRefused(mixed $config, string $names): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($names);
		new LinkMarkerRule(config: $config);
	}//end testAMalformedRuleIsRefused()
}//end class
