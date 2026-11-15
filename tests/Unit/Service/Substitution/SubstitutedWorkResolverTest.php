<?php

/**
 * Which cases and tasks a substitution routes to the waarnemer
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Substitution
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

namespace OCA\Dossiq\Tests\Unit\Service\Substitution;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Substitution\SubstitutedWorkResolver;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Scope matching, final-status exclusion, de-duplication and the routing stamp.
 *
 * @covers \OCA\Dossiq\Service\Substitution\SubstitutedWorkResolver
 * @uses   \OCA\Dossiq\Service\Substitution\SubstitutionWorkRun
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
final class SubstitutedWorkResolverTest extends TestCase {
	/**
	 * A resolver over cases per assignee, status types and tasks per assignee.
	 *
	 * @param array<string, array<int, array<string, mixed>>>  $cases        Cases per assignee.
	 * @param array<int, array<string, mixed>>|RuntimeException $statusTypes The status types, or the error reading them.
	 * @param array<string, array<int, array<string, mixed>>>  $tasks        Tasks per assignee.
	 * @param array<string, string>                            $config       Config overrides.
	 *
	 * @return SubstitutedWorkResolver
	 */
	private function resolver(array $cases = [], array|RuntimeException $statusTypes = [], array $tasks = [], array $config = []): SubstitutedWorkResolver {
		$config += ['register' => 'dossiq', 'case_schema' => 'case', 'status_type_schema' => 'statusType'];
		$store = new class ($cases, $statusTypes) {
			/**
			 * Constructor.
			 *
			 * @param array<string, array<int, array<string, mixed>>>  $cases       Cases per assignee.
			 * @param array<int, array<string, mixed>>|RuntimeException $statusTypes The status types.
			 */
			public function __construct(private array $cases, private array|RuntimeException $statusTypes) {
			}

			/**
			 * The rows of the schema asked for.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				if ($schema === 'statusType') {
					if ($this->statusTypes instanceof RuntimeException) {
						throw $this->statusTypes;
					}

					return $this->statusTypes;
				}

				return $this->cases[(string)($filters['assignee'] ?? '')] ?? [];
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => $config[$key] ?? '');
		$inbox = $this->createMock(EngineTaskInbox::class);
		$inbox->method('openForAssignee')->willReturnCallback(static fn (string $actor, int $limit = 200): array => $tasks[$actor] ?? []);

		return new SubstitutedWorkResolver(settingsService: $settings, engineTasks: $inbox);
	}//end resolver()

	/**
	 * The ids of a list of rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<int, string>
	 */
	private static function ids(array $rows): array {
		return array_map(static fn (array $row): string => (string)($row['id'] ?? $row['uuid']), $rows);
	}//end ids()

	/**
	 * Scope matching per scope kind, and an unknown scope matches nothing.
	 *
	 * @return void
	 */
	public function testCaseInScope(): void {
		$resolver = $this->resolver();
		$case = ['id' => 'c1', 'caseType' => 'ct-permit'];

		$this->assertTrue($resolver->caseInScope($case, 'all', []));
		$this->assertTrue($resolver->caseInScope($case, 'caseTypes', ['ct-permit']));
		$this->assertFalse($resolver->caseInScope($case, 'caseTypes', ['ct-other']));
		$this->assertTrue($resolver->caseInScope($case, 'cases', ['c1']));
		$this->assertTrue($resolver->caseInScope(['uuid' => 'u1'], 'cases', ['u1']));
		$this->assertFalse($resolver->caseInScope($case, 'cases', ['c2']));
		$this->assertFalse($resolver->caseInScope($case, 'other', ['c1']));
	}//end testCaseInScope()

	/**
	 * Every truthy isFinal spelling excludes its status; others do not.
	 *
	 * @return void
	 */
	public function testFinalStatusesAreExcludedInEverySpelling(): void {
		$resolver = $this->resolver(
			['anna' => [
				['id' => 'c1', 'status' => 's-bool'],
				['id' => 'c2', 'status' => 's-string'],
				['id' => 'c3', 'status' => 's-int'],
				['id' => 'c4', 'status' => 's-open'],
				['id' => 'c5', 'status' => 's-false-string'],
			]],
			[
				['id' => 's-bool', 'isFinal' => true],
				['id' => 's-string', 'isFinal' => 'true'],
				['uuid' => 's-int', 'isFinal' => 1],
				['id' => 's-open', 'isFinal' => false],
				['id' => 's-false-string', 'isFinal' => 'false'],
			]
		);

		$result = $resolver->resolve([['id' => 'sub', 'absentee' => 'anna', 'endDate' => '2026-03-14']]);

		$this->assertSame(['c4', 'c5'], self::ids($result['cases']));
	}//end testFinalStatusesAreExcludedInEverySpelling()

	/**
	 * An unreadable status-type schema excludes nothing rather than failing.
	 *
	 * @return void
	 */
	public function testAnUnreadableStatusSchemaExcludesNothing(): void {
		$resolver = $this->resolver(['anna' => [['id' => 'c1', 'status' => 's-closed']]], new RuntimeException('down'));

		$this->assertSame(['c1'], self::ids($resolver->resolve([['absentee' => 'anna']])['cases']));
	}//end testAnUnreadableStatusSchemaExcludesNothing()

	/**
	 * The stamp carries the absentee, the substitution id and the effective end, preferring _activeUntil.
	 *
	 * @return void
	 */
	public function testTheStampPrefersTheEffectiveEnd(): void {
		$resolver = $this->resolver(
			['anna' => [['id' => 'c1']]],
			[],
			['anna' => [['id' => 't1']]]
		);

		$result = $resolver->resolve([['uuid' => 'sub-1', 'absentee' => 'anna', 'endDate' => '2026-03-14', '_activeUntil' => '2026-03-10']]);

		$stamp = ['absentee' => 'anna', 'substitutionId' => 'sub-1', 'until' => '2026-03-10'];
		$this->assertSame($stamp, $result['cases'][0]['_substituted']);
		$this->assertSame($stamp, $result['tasks'][0]['_substituted']);
	}//end testTheStampPrefersTheEffectiveEnd()

	/**
	 * Overlapping substitutions route each case and task once, to the first; id-less rows are dropped.
	 *
	 * @return void
	 */
	public function testWorkIsRoutedOnceAcrossSubstitutions(): void {
		$resolver = $this->resolver(
			['anna' => [['id' => 'c1'], ['title' => 'no id']]],
			[],
			['anna' => [['id' => 't1'], ['title' => 'no id']]]
		);

		$result = $resolver->resolve([
			['id' => 'first', 'absentee' => 'anna', 'endDate' => '2026-03-14'],
			['id' => 'second', 'absentee' => 'anna', 'endDate' => '2026-03-31'],
		]);

		$this->assertSame(['c1'], self::ids($result['cases']));
		$this->assertSame(['t1'], self::ids($result['tasks']));
		$this->assertSame('first', $result['cases'][0]['_substituted']['substitutionId']);
		$this->assertSame('first', $result['tasks'][0]['_substituted']['substitutionId']);
	}//end testWorkIsRoutedOnceAcrossSubstitutions()

	/**
	 * A case-scoped substitution only routes tasks of the listed cases; a caseTypes scope routes all tasks.
	 *
	 * @return void
	 */
	public function testTaskScoping(): void {
		$tasks = ['anna' => [['id' => 't1', 'case' => 'c1'], ['id' => 't2', 'case' => 'c2'], ['id' => 't3']]];

		$cases = $this->resolver([], [], $tasks)->resolve([['absentee' => 'anna', 'scope' => 'cases', 'scopeRefs' => ['c1']]]);
		$types = $this->resolver([], [], $tasks)->resolve([['absentee' => 'anna', 'scope' => 'caseTypes', 'scopeRefs' => ['ct']]]);

		$this->assertSame(['t1'], self::ids($cases['tasks']));
		$this->assertSame(['t1', 't2', 't3'], self::ids($types['tasks']));
	}//end testTaskScoping()

	/**
	 * Without a case schema only tasks are routed; a substitution without absentee routes nothing.
	 *
	 * @return void
	 */
	public function testMissingCaseSchemaAndAbsentee(): void {
		$resolver = $this->resolver(['anna' => [['id' => 'c1']]], [], ['anna' => [['id' => 't1']]], ['case_schema' => '']);

		$this->assertSame(['cases' => [], 'tasks' => [['id' => 't1', '_substituted' => ['absentee' => 'anna', 'substitutionId' => '', 'until' => '']]]], $resolver->resolve([['absentee' => 'anna']]));
		$this->assertSame(['cases' => [], 'tasks' => []], $resolver->resolve([['absentee' => '']]));
		$this->assertSame(['cases' => [], 'tasks' => []], $resolver->resolve([]));
	}//end testMissingCaseSchemaAndAbsentee()
}//end class
