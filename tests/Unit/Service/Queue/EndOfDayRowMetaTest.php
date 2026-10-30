<?php

/**
 * The meta line of an end-of-day row: the case number and status of a case,
 * the number and title of the case a task is on, and the time of a note.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
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
 *
 * @spec openspec/changes/end-of-day-manifest/specs/end-of-day-manifest/spec.md#requirement-each-row-names-its-case
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Queue\EndOfDayRowMeta;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for {@see EndOfDayRowMeta}.
 */
class EndOfDayRowMetaTest extends TestCase {

	/**
	 * The meta reader over register objects by uuid and engine tasks by id.
	 *
	 * @param array<string, array<string, mixed>> $objects Register objects by uuid (cases and status types).
	 * @param array<string, array<string, mixed>> $tasks   Engine tasks by id.
	 *
	 * @return EndOfDayRowMeta The reader.
	 */
	private function meta(array $objects, array $tasks=[]): EndOfDayRowMeta {
		$objectService = new class($objects) {
			/**
			 * @param array<string, array<string, mixed>> $objects Objects by uuid.
			 */
			public function __construct(private array $objects) {
			}

			/**
			 * @param string $id       The uuid.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return object The object.
			 */
			public function find(string $id, string $register, string $schema): object {
				if (isset($this->objects[$id]) === false) {
					throw new RuntimeException('not found');
				}

				$data = $this->objects[$id];
				return new class($data) {
					/**
					 * @param array<string, mixed> $data The object's data.
					 */
					public function __construct(private array $data) {
					}

					/**
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return $this->data;
					}
				};
			}
		};

		$gateway = $this->createMock(originalClassName: EngineTaskGateway::class);
		$gateway->method('find')->willReturnCallback(
			static fn (string $taskId): ?array => ($tasks[$taskId] ?? null)
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default=''): string => $default
		);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters=[]): string => vsprintf($text, array_values($parameters))
		);

		return new EndOfDayRowMeta(settings: $settings, tasks: $gateway, l10n: $l10n, logger: new NullLogger());
	}//end meta()

	/**
	 * A case reads its number and the name of its status; a recorded case
	 * its number and the time of the note.
	 *
	 * @return void
	 */
	public function testACaseNamesItsNumberAndStatus(): void {
		$meta = $this->meta(
			objects: [
				'c1' => ['identifier' => '2026-4836', 'title' => 'Bezwaar parkeerboete Havenstraat', 'status' => 's1'],
				's1' => ['name' => 'In behandeling'],
			]
		);

		$facts = $meta->factsOf(item: ['subjectType' => 'case', 'subjectId' => 'c1']);

		$this->assertSame(expected: '2026-4836 · In behandeling', actual: $meta->openLine(facts: $facts));
		$this->assertSame(expected: '2026-4836 · 15:40', actual: $meta->recordedLine(facts: $facts, time: '15:40'));
	}//end testACaseNamesItsNumberAndStatus()

	/**
	 * A task names the number and title of the case it is on.
	 *
	 * @return void
	 */
	public function testATaskNamesTheCaseItIsOn(): void {
		$meta = $this->meta(
			objects: ['c9' => ['identifier' => '2026-4839', 'title' => 'Kapvergunning essen Parkweg', 'status' => 's1']],
			tasks: ['t1' => ['id' => 'engine-t1', 'objectUuid' => 'c9']]
		);

		$facts = $meta->factsOf(item: ['subjectType' => 'task', 'subjectId' => 't1']);

		$this->assertSame(expected: 'at 2026-4839 Kapvergunning essen Parkweg', actual: $meta->openLine(facts: $facts));
		$this->assertSame(expected: 'at 2026-4839 · 11:15', actual: $meta->recordedLine(facts: $facts, time: '11:15'));
	}//end testATaskNamesTheCaseItIsOn()

	/**
	 * What cannot be read is left out: a case without a readable status reads
	 * its number, a task on no case reads nothing but the time.
	 *
	 * @return void
	 */
	public function testWhatCannotBeReadIsLeftOut(): void {
		$meta = $this->meta(
			objects: ['c1' => ['identifier' => '2026-4836', 'status' => 'gone']],
			tasks: ['t1' => ['id' => 'engine-t1', 'objectUuid' => '']]
		);

		$case = $meta->factsOf(item: ['subjectType' => 'case', 'subjectId' => 'c1']);
		$task = $meta->factsOf(item: ['subjectType' => 'task', 'subjectId' => 't1']);
		$missing = $meta->factsOf(item: ['subjectType' => 'case', 'subjectId' => 'nope']);

		$this->assertSame(expected: '2026-4836', actual: $meta->openLine(facts: $case));
		$this->assertSame(expected: '', actual: $meta->openLine(facts: $task));
		$this->assertSame(expected: '11:15', actual: $meta->recordedLine(facts: $task, time: '11:15'));
		$this->assertSame(expected: '', actual: $meta->openLine(facts: $missing));
	}//end testWhatCannotBeReadIsLeftOut()
}//end class
