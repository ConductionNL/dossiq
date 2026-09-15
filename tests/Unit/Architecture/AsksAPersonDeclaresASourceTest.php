<?php

/**
 * A mechanism that asks a person for something declares a queue source.
 *
 * This is the test the whole change stands on. A personal queue built by hand
 * out of the queries somebody remembered is a queue that goes quietly stale:
 * dossiq's My Work tile showed engine tasks and nothing else for months, and
 * its own note said so. Nothing failed, because nothing was checking.
 *
 * So the check is here. Every notification the register declares and every
 * flow node the app ships is either covered by a declared queue source or
 * carries an entry saying which person it does NOT reach and why. A new one is
 * neither, and fails.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Tests\Unit\Architecture\QueueSourceScanner
 */
class AsksAPersonDeclaresASourceTest extends TestCase {
	/**
	 * The app root, so the scanner walks the real tree.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Resolve the app root.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->root = dirname(__DIR__, 3);
	}

	/**
	 * The allowlist, decoded.
	 *
	 * @return array<string, mixed> The allowlist.
	 */
	private function allowlist(): array {
		$raw = file_get_contents(__DIR__ . '/asks-a-person.allowlist.json');
		self::assertIsString($raw, 'The allowlist is missing.');

		return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * Mechanism id to the reason it reaches no queue.
	 *
	 * @return array<string, string> The map.
	 */
	private function allowed(): array {
		$map = [];
		foreach ($this->allowlist()['entries'] as $entry) {
			$map[(string)$entry['mechanism']] = (string)($entry['reason'] ?? '');
		}

		return $map;
	}

	/**
	 * Every mechanism the tree ships.
	 *
	 * @return array<int, string> The inventory.
	 */
	private function inventory(): array {
		return array_merge(
			QueueSourceScanner::notificationMechanisms(settingsRoot: $this->root . '/lib/Settings'),
			QueueSourceScanner::flowMechanisms(flowRoot: $this->root . '/lib/Flow')
		);
	}

	/**
	 * What the declared sources cover.
	 *
	 * @return array<int, string> The declared mechanisms.
	 */
	private function declared(): array {
		return QueueSourceScanner::declaredMechanisms(
			sourceFiles: (glob($this->root . '/lib/Service/Queue/Source/*.php') ?: [])
		);
	}

	/**
	 * The inventory is not empty, so a green run means the scanner ran.
	 *
	 * A scanner that finds nothing passes every other test in this file, which
	 * is the shape of a check that cannot fail.
	 *
	 * @return void
	 */
	public function testTheInventoryIsNotEmpty(): void {
		$inventory = $this->inventory();

		self::assertGreaterThan(10, count($inventory), 'The scanner found almost nothing, so it did not run.');
		self::assertContains('flow:DossiqAskPersonNode', $inventory);
		self::assertContains('notification:caseAssigned', $inventory);
	}

	/**
	 * Every mechanism reaches a declared source, or is classified.
	 *
	 * @return void
	 */
	public function testEveryMechanismDeclaresASourceOrIsAllowlisted(): void {
		$offenders = QueueSourceScanner::offenders(
			inventory: $this->inventory(),
			declared: $this->declared(),
			allowed: $this->allowed()
		);

		self::assertSame(
			[],
			$offenders,
			"These mechanisms can ask a person for something and reach nobody's queue:\n"
			. implode("\n", $offenders)
			. "\n\nName the mechanism in a queue source's mechanisms(), or add an allowlist "
			. 'entry saying which person it does not reach and why.'
		);
	}

	/**
	 * Every allowlist entry says something a reader can check.
	 *
	 * @return void
	 */
	public function testEveryAllowlistEntryCarriesAReason(): void {
		foreach ($this->allowlist()['entries'] as $entry) {
			self::assertNotEmpty($entry['mechanism'] ?? '', 'An allowlist entry names no mechanism.');
			self::assertGreaterThan(
				40,
				strlen(trim((string)($entry['reason'] ?? ''))),
				$entry['mechanism'] . ' is allowlisted with no reason a reader could check.'
			);
		}
	}

	/**
	 * No allowlist entry is stale.
	 *
	 * An entry for a mechanism that now has a source, or that no longer
	 * exists, stops the allowlist meaning anything.
	 *
	 * @return void
	 */
	public function testNoAllowlistEntryIsStale(): void {
		$inventory = $this->inventory();
		$declared = $this->declared();

		foreach (array_keys($this->allowed()) as $mechanism) {
			self::assertContains(
				$mechanism,
				$inventory,
				$mechanism . ' is allowlisted but the tree no longer ships it. Remove the entry.'
			);
			self::assertNotContains(
				$mechanism,
				$declared,
				$mechanism . ' is allowlisted AND covered by a source. Remove the entry.'
			);
		}
	}

	/**
	 * A new mechanism that declares nothing fails, and names itself.
	 *
	 * @return void
	 */
	public function testANewMechanismWithNoSourceFails(): void {
		$offenders = QueueSourceScanner::offenders(
			inventory: ['flow:DossiqAskForCoffeeNode'],
			declared: ['flow:DossiqAskPersonNode'],
			allowed: []
		);

		self::assertSame(
			['flow:DossiqAskForCoffeeNode can ask a person for something and declares no queue source.'],
			$offenders
		);
	}

	/**
	 * The same mechanism, once a source declares it, passes.
	 *
	 * The mirror of the test above: without it, the failure could be coming
	 * from the mechanism's name rather than from the missing declaration.
	 *
	 * @return void
	 */
	public function testTheSameMechanismDeclaredPasses(): void {
		self::assertSame(
			[],
			QueueSourceScanner::offenders(
				inventory: ['flow:DossiqAskForCoffeeNode'],
				declared: ['flow:DossiqAskForCoffeeNode'],
				allowed: []
			)
		);
	}

	/**
	 * A node added to the tree enters the inventory without anybody listing it.
	 *
	 * This is what makes the check hold next month: the inventory is read off
	 * the directory, so a node nobody remembered is still counted.
	 *
	 * @return void
	 */
	public function testANodeAddedToTheTreeIsFound(): void {
		$dir = sys_get_temp_dir() . '/dossiq-queue-scan-' . bin2hex(random_bytes(6));
		mkdir($dir, 0o777, true);
		file_put_contents($dir . '/DossiqBrandNewNode.php', "<?php\nclass DossiqBrandNewNode {}\n");
		file_put_contents($dir . '/DossiqBaseNode.php', "<?php\nabstract class DossiqBaseNode {}\n");

		try {
			self::assertSame(
				['flow:DossiqBrandNewNode'],
				QueueSourceScanner::flowMechanisms(flowRoot: $dir),
				'An abstract base is not a mechanism, and a new node is.'
			);
		} finally {
			unlink($dir . '/DossiqBrandNewNode.php');
			unlink($dir . '/DossiqBaseNode.php');
			rmdir($dir);
		}
	}

	/**
	 * A notification added to a register enters the inventory the same way.
	 *
	 * @return void
	 */
	public function testANotificationAddedToARegisterIsFound(): void {
		$dir = sys_get_temp_dir() . '/dossiq-queue-notify-' . bin2hex(random_bytes(6));
		mkdir($dir, 0o777, true);
		file_put_contents(
			$dir . '/register.json',
			json_encode([
				'components' => [
					'schemas' => [
						'thing' => [
							'x-openregister-notifications' => ['somebodyWasAsked' => ['enabled' => true]],
						],
					],
				],
			], JSON_THROW_ON_ERROR)
		);

		try {
			self::assertSame(
				['notification:somebodyWasAsked'],
				QueueSourceScanner::notificationMechanisms(settingsRoot: $dir)
			);
		} finally {
			unlink($dir . '/register.json');
			rmdir($dir);
		}
	}
}//end class
