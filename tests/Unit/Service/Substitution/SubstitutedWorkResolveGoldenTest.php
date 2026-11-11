<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Substitution;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Substitution\SubstitutedWorkResolver;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use PHPUnit\Framework\TestCase;

/**
 * Whose work a substitute sees: which cases and tasks come back, stamped, and which never do.
 */
class SubstitutedWorkResolveGoldenTest extends TestCase {

	/**
	 * The reads made, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $reads = [];

	/**
	 * A resolver over stored cases and engine tasks.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $cases     Cases per assignee.
	 * @param array<string, array<int, array<string, mixed>>> $tasks     Tasks per assignee.
	 * @param array<string, string>                           $config    The config values.
	 * @param bool                                            $noObjects Whether OpenRegister is absent.
	 *
	 * @return SubstitutedWorkResolver The resolver.
	 */
	private function resolver(array $cases, array $tasks = [], array $config = [], bool $noObjects = false): SubstitutedWorkResolver {
		$config += ['register' => 'dossiq', 'case_schema' => 'case', 'status_type_schema' => 'statusType'];
		$test = $this;
		$objects = new class ($test, $cases) {
			/**
			 * @param SubstitutedWorkResolveGoldenTest                $test  The test.
			 * @param array<string, array<int, array<string, mixed>>> $cases Cases per assignee.
			 */
			public function __construct(private SubstitutedWorkResolveGoldenTest $test, private array $cases) {
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->test->reads[] = ['search', $register, $schema, $filters];
				if ($schema === 'statusType') {
					return [['id' => 'st-final', 'isFinal' => true], ['id' => 'st-open', 'isFinal' => false]];
				}

				return $this->cases[(string)($filters['assignee'] ?? '')] ?? [];
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($noObjects ? null : $objects);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? ''));
		$inbox = $this->createMock(EngineTaskInbox::class);
		$inbox->method('openForAssignee')->willReturnCallback(
			function (string $actor, int $limit = 200) use ($tasks): array {
				$this->reads[] = ['tasks', $actor];

				return $tasks[$actor] ?? [];
			}
		);

		return new SubstitutedWorkResolver(settingsService: $settings, engineTasks: $inbox);
	}//end resolver()

	/**
	 * Allowed: the absentees' open cases and tasks in scope, each once, stamped with the substitution.
	 *
	 * @return void
	 */
	public function testTheAbsenteesWorkComesBackStamped(): void {
		$subs = [
			['id' => 's-1', 'absentee' => 'jan', 'endDate' => '2026-07-21'],
			['uuid' => 's-2', 'absentee' => 'piet', 'scope' => 'cases', 'scopeRefs' => ['c-3', 'c-1'], '_activeUntil' => '2026-08-01', 'endDate' => '2026-07-01'],
			['id' => 's-3', 'absentee' => ''],
			['id' => 's-4'],
		];
		$cases = [
			'jan' => [['id' => 'c-1', 'status' => 'st-open'], ['id' => 'c-2', 'status' => 'st-final'], ['uuid' => 'c-4'], ['title' => 'no id']],
			'piet' => [['id' => 'c-1'], ['id' => 'c-3', 'status' => 'st-open'], ['id' => 'c-5']],
		];
		$tasks = [
			'jan' => [['id' => 't-1', 'case' => 'c-9'], ['uuid' => 't-2'], ['title' => 'no id']],
			'piet' => [['id' => 't-1', 'case' => 'c-3'], ['id' => 't-3', 'case' => 'c-3'], ['id' => 't-4', 'case' => 'c-8']],
		];

		$work = $this->resolver(cases: $cases, tasks: $tasks)->resolve(subs: $subs);

		$jan = ['absentee' => 'jan', 'substitutionId' => 's-1', 'until' => '2026-07-21'];
		$piet = ['absentee' => 'piet', 'substitutionId' => 's-2', 'until' => '2026-08-01'];
		self::assertSame(
			[
				'cases' => [
					['id' => 'c-1', 'status' => 'st-open', '_substituted' => $jan],
					['uuid' => 'c-4', '_substituted' => $jan],
					['id' => 'c-3', 'status' => 'st-open', '_substituted' => $piet],
				],
				'tasks' => [
					['id' => 't-1', 'case' => 'c-9', '_substituted' => $jan],
					['uuid' => 't-2', '_substituted' => $jan],
					['id' => 't-3', 'case' => 'c-3', '_substituted' => $piet],
				],
			],
			$work
		);
		self::assertSame(
			[
				['search', 'dossiq', 'statusType', []],
				['search', 'dossiq', 'case', ['assignee' => 'jan']],
				['tasks', 'jan'],
				['search', 'dossiq', 'case', ['assignee' => 'piet']],
				['tasks', 'piet'],
			],
			$this->reads
		);
	}//end testTheAbsenteesWorkComesBackStamped()

	/**
	 * A case type scope keeps the cases of those types; without a case schema only tasks come back.
	 *
	 * @return void
	 */
	public function testCaseTypeScopeAndNoCaseSchema(): void {
		$subs = [['id' => 's-1', 'absentee' => 'jan', 'scope' => 'caseTypes', 'scopeRefs' => ['bezwaar']]];
		$cases = ['jan' => [['id' => 'c-1', 'caseType' => 'bezwaar'], ['id' => 'c-2', 'caseType' => 'vergunning']]];

		self::assertSame(['c-1'], array_column($this->resolver(cases: $cases)->resolve(subs: $subs)['cases'], 'id'));

		$this->reads = [];
		$work = $this->resolver(cases: $cases, tasks: ['jan' => [['id' => 't-1']]], config: ['case_schema' => ''])->resolve(subs: $subs);
		self::assertSame([], $work['cases']);
		self::assertSame(['t-1'], array_column($work['tasks'], 'id'));
		self::assertSame([['search', 'dossiq', 'statusType', []], ['tasks', 'jan']], $this->reads);
	}//end testCaseTypeScopeAndNoCaseSchema()

	/**
	 * Refused: no substitutions, or no OpenRegister, reads nothing and routes nothing.
	 *
	 * @return void
	 */
	public function testNothingIsRoutedWithoutSubstitutionsOrStore(): void {
		self::assertSame(['cases' => [], 'tasks' => []], $this->resolver(cases: ['jan' => [['id' => 'c-1']]])->resolve(subs: []));
		self::assertSame(['cases' => [], 'tasks' => []], $this->resolver(cases: ['jan' => [['id' => 'c-1']]], noObjects: true)->resolve(subs: [['absentee' => 'jan']]));
		self::assertSame([], $this->reads);
	}//end testNothingIsRoutedWithoutSubstitutionsOrStore()
}//end class
