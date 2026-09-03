<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\ProvisionAssignedGroups;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The shipped flows' assigned groups are provisioned, idempotently.
 *
 * On a fresh install the shipped case flow assigns `task-behandelaar` to the
 * group `behandelaars`, and the completion gate fails closed on a group that
 * does not exist — proven to red 7 of 9 journey specs until `occ group:add
 * behandelaars` was run by hand. These tests pin the repair step that now
 * does that at install, and sweep the shipped register data so a NEW group
 * assignment cannot ship without its provisioning.
 *
 * @covers \OCA\Dossiq\Repair\ProvisionAssignedGroups
 */
class ProvisionAssignedGroupsTest extends TestCase {

	/**
	 * A missing group is created and reported.
	 *
	 * @return void
	 */
	public function testCreatesEveryMissingAssignedGroup(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturn(false);
		$groupManager->expects($this->exactly(count(ProvisionAssignedGroups::ASSIGNED_GROUPS)))
			->method('createGroup')
			->willReturn($this->createMock(IGroup::class));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('info');
		$output->expects($this->never())->method('warning');

		$step = new ProvisionAssignedGroups(groupManager: $groupManager, logger: new NullLogger());
		$step->run($output);
	}

	/**
	 * An existing group is left alone — the step is idempotent.
	 *
	 * @return void
	 */
	public function testLeavesAnExistingGroupAlone(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturn(true);
		$groupManager->expects($this->never())->method('createGroup');

		$step = new ProvisionAssignedGroups(groupManager: $groupManager, logger: new NullLogger());
		$step->run($this->createMock(IOutput::class));
	}

	/**
	 * A backend that refuses group creation is reported loudly, not swallowed.
	 *
	 * @return void
	 */
	public function testWarnsWhenTheBackendRefusesCreation(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturn(false);
		$groupManager->method('createGroup')->willReturn(null);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('warning');

		$step = new ProvisionAssignedGroups(groupManager: $groupManager, logger: new NullLogger());
		$step->run($output);
	}

	/**
	 * dossiq names no group id from before the fleet rename.
	 *
	 * A fresh install still ends up with `decidesk-administrators` alongside
	 * `decidiq-administrators`. It is not dossiq's: the gid appears nowhere in
	 * this repository, and the surviving one is named in decidiq's own
	 * `lib/Settings/decidesk_register.json` register authorization block. This
	 * assertion is what makes that a finding rather than an assumption — if a
	 * pre-rename gid is ever introduced here, it fails, and the leftover stops
	 * being somebody else's problem.
	 *
	 * `OCA\Decidesk\Event\*` is deliberately not covered: those are
	 * back-compat listener registrations for an app whose namespace moved, and
	 * removing them would silently unhook the seam.
	 *
	 * @return void
	 */
	public function testDossiqNamesNoPreRenameGroupId(): void {
		$root = dirname(__DIR__, 3);
		$hits = [];

		foreach (['lib', 'src', 'appinfo'] as $directory) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
			);

			foreach ($iterator as $file) {
				if ($file->isFile() === false) {
					continue;
				}

				$source = (string)file_get_contents($file->getPathname());
				foreach (['decidesk-administrators', 'procest-administrators', 'docudesk-administrators'] as $gid) {
					if (str_contains($source, $gid) === true) {
						$hits[] = $file->getPathname() . ' => ' . $gid;
					}
				}
			}
		}

		self::assertSame(
			[],
			$hits,
			sprintf('dossiq names a group id from before the fleet rename: %s', implode(', ', $hits))
		);
	}//end testDossiqNamesNoPreRenameGroupId()

	/**
	 * Every literal assignee in the shipped flows is a provisioned group.
	 *
	 * Sweeps every `dossiq.askPerson` node in the shipped register files: an
	 * assignee that is not a template expression must appear in
	 * ProvisionAssignedGroups::ASSIGNED_GROUPS, so assigning shipped work to
	 * an unprovisioned principal fails here instead of on a fresh install.
	 *
	 * @return void
	 */
	public function testEveryShippedLiteralAssigneeIsProvisioned(): void {
		$files = [
			__DIR__ . '/../../../lib/Settings/dossiq_register.json',
		];
		foreach ((array)glob(__DIR__ . '/../../../lib/Settings/register.d/*.json') as $file) {
			$files[] = (string)$file;
		}

		$assignees = [];
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			if (is_array($data) === false) {
				continue;
			}

			$this->collectAskPersonAssignees(node: $data, file: basename($file), found: $assignees);
		}

		$this->assertNotEmpty($assignees, 'The sweep found no shipped askPerson assignees at all — the query is broken, not the data clean');

		foreach ($assignees as $entry) {
			$this->assertContains(
				$entry['assignee'],
				ProvisionAssignedGroups::ASSIGNED_GROUPS,
				sprintf(
					'%s assigns a shipped askPerson step to "%s", which ProvisionAssignedGroups does not provision — the completion gate will refuse every actor on a fresh install',
					$entry['file'],
					$entry['assignee']
				)
			);
		}
	}

	/**
	 * Collect the literal assignees of every dossiq.askPerson node.
	 *
	 * @param mixed $node The JSON node.
	 * @param string $file The file being swept (for messages).
	 * @param array<int, array{file: string, assignee: string}> $found Accumulator.
	 *
	 * @return void
	 */
	private function collectAskPersonAssignees(mixed $node, string $file, array &$found): void {
		if (is_array($node) === false) {
			return;
		}

		$type = ($node['type'] ?? '');
		if ($type === 'dossiq.askPerson') {
			$config = (array)($node['config'] ?? []);
			$assignee = trim((string)($config['assignee'] ?? ''));
			if ($assignee !== '' && str_contains($assignee, '{{') === false) {
				$found[] = ['file' => $file, 'assignee' => $assignee];
			}
		}

		foreach ($node as $value) {
			$this->collectAskPersonAssignees(node: $value, file: $file, found: $found);
		}
	}
}//end class
