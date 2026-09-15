<?php

/**
 * The inventory of mechanisms that can ask a person for something.
 *
 * Two kinds, both read off the tree rather than off a list somebody keeps:
 *
 *  - `notification:<key>` — every notification declared in the canonical
 *    `x-openregister-notifications` dialect across `lib/Settings`. A
 *    notification is by definition a mechanism telling a person something.
 *  - `flow:<ClassShortName>` — every concrete flow node under `lib/Flow`. A
 *    node is a thing a case can DO, and doing it often means putting work on
 *    somebody. The abstract bases are excluded because they are not nodes.
 *
 * Deliberately NOT a keyword heuristic over the node bodies. That was tried
 * first, and it scored `DossiqNotifyRoleNode` at zero while `DossiqSendEmailNode`
 * scored two: a detector that misses the node with "notify" in its name is a
 * detector that will miss the next one. Every node is in the inventory and the
 * allowlist classifies the ones that reach no person, which means a NEW node
 * has to be classified by somebody rather than passing by being unremarkable.
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

/**
 * Reads the mechanisms a tree ships, and what the queue sources declare.
 *
 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
 */
class QueueSourceScanner {
	/**
	 * Every notification declared in the register files under one directory.
	 *
	 * @param string $settingsRoot The directory holding the register JSON.
	 *
	 * @return array<int, string> Mechanism ids, deduplicated and sorted.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public static function notificationMechanisms(string $settingsRoot): array {
		$found = [];
		foreach (self::jsonFiles(root: $settingsRoot) as $path) {
			$decoded = json_decode((string)file_get_contents($path), true);
			if (is_array($decoded) === false) {
				continue;
			}

			foreach (self::notificationKeys(node: $decoded) as $key) {
				$found['notification:' . $key] = true;
			}
		}

		$ids = array_keys($found);
		sort($ids);

		return $ids;
	}

	/**
	 * Every concrete flow node under one directory.
	 *
	 * @param string $flowRoot The directory holding the flow nodes.
	 *
	 * @return array<int, string> Mechanism ids, sorted.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public static function flowMechanisms(string $flowRoot): array {
		$ids = [];
		foreach (glob(rtrim($flowRoot, '/') . '/*Node.php') as $path) {
			$source = (string)file_get_contents($path);
			if (preg_match('/^\s*abstract\s+class\s/m', $source) === 1) {
				continue;
			}

			$ids[] = 'flow:' . basename($path, '.php');
		}

		sort($ids);

		return $ids;
	}

	/**
	 * The mechanisms one set of source classes declares they cover.
	 *
	 * Read off the class files rather than by constructing the sources: a
	 * source's constructor wants a register and a calendar, and a structural
	 * test that needs a running Nextcloud is a structural test that gets
	 * skipped.
	 *
	 * @param array<int, string> $sourceFiles Paths to the queue source classes.
	 *
	 * @return array<int, string> The declared mechanism ids, sorted.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public static function declaredMechanisms(array $sourceFiles): array {
		$declared = [];
		foreach ($sourceFiles as $path) {
			$source = (string)file_get_contents($path);
			if (preg_match('/function mechanisms\(\): array \{(.*?)\}/s', $source, $matches) !== 1) {
				continue;
			}

			if (preg_match_all("/'((?:notification|flow):[A-Za-z0-9_.-]+)'/", $matches[1], $ids) === false) {
				continue;
			}

			foreach ($ids[1] as $id) {
				$declared[$id] = true;
			}
		}

		$ids = array_keys($declared);
		sort($ids);

		return $ids;
	}

	/**
	 * The mechanisms nobody covers and nobody explained.
	 *
	 * @param array<int, string>    $inventory The mechanisms the tree ships.
	 * @param array<int, string>    $declared  The mechanisms the sources cover.
	 * @param array<string, string> $allowed   Mechanism id to the reason it reaches no person.
	 *
	 * @return array<int, string> One finding per unexplained mechanism.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public static function offenders(array $inventory, array $declared, array $allowed): array {
		$offenders = [];
		foreach ($inventory as $mechanism) {
			if (in_array($mechanism, $declared, true) === true) {
				continue;
			}

			if (array_key_exists($mechanism, $allowed) === true) {
				continue;
			}

			$offenders[] = $mechanism . ' can ask a person for something and declares no queue source.';
		}

		return $offenders;
	}

	/**
	 * Every JSON file under a directory, one level of nesting included.
	 *
	 * @param string $root The directory.
	 *
	 * @return array<int, string> The paths.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	private static function jsonFiles(string $root): array {
		$root = rtrim($root, '/');

		$here = glob($root . '/*.json');
		if ($here === false) {
			$here = [];
		}

		$nested = glob($root . '/*/*.json');
		if ($nested === false) {
			$nested = [];
		}

		return array_merge($here, $nested);
	}

	/**
	 * The notification keys declared anywhere inside one decoded document.
	 *
	 * @param mixed $node The decoded document, or a branch of it.
	 *
	 * @return array<int, string> The keys.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	private static function notificationKeys(mixed $node): array {
		if (is_array($node) === false) {
			return [];
		}

		$keys = [];
		foreach ($node as $key => $value) {
			if ($key === 'x-openregister-notifications' && is_array($value) === true) {
				foreach (array_keys($value) as $notification) {
					$keys[] = (string)$notification;
				}

				continue;
			}

			$keys = array_merge($keys, self::notificationKeys(node: $value));
		}

		return $keys;
	}
}//end class
