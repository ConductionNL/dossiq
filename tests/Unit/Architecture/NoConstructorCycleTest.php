<?php

/**
 * No class under lib/ can be reached from its own constructor.
 *
 * Nextcloud builds a service by reading its constructor and resolving every
 * typed parameter, recursively. A cycle in that graph is therefore not a
 * design smell, it is a crash: the container recurses until PHP runs out of
 * stack and answers "Maximum call stack size reached. Infinite recursion?".
 *
 * 🔴 IT CRASHES AT INSTALL TIME, NOT AT USE TIME. dossiq shipped a four-class
 * cycle (TaskDeclarationReader -> WorkflowDefinitionService ->
 * WorkflowLifecycleGuard -> TaskDeclarationValidator -> back) that three
 * repair steps resolve. `occ app:enable dossiq` died in the container, so the
 * app could not be installed at all, and all six PHPUnit cells failed in
 * their SETUP step rather than on an assertion. Nothing in the suite could
 * see it, because the suite never started.
 *
 * So the graph is asserted here, from the source, with no container needed.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Asserts the constructor-injection graph under lib/ is acyclic.
 */
class NoConstructorCycleTest extends TestCase {

	/**
	 * The constructor graph, keyed by fully qualified class name.
	 *
	 * @var array<string, array<int, string>>|null
	 */
	private ?array $graph = null;

	/**
	 * A cycle in the graph is an app that cannot be enabled.
	 *
	 * @return void
	 */
	public function testConstructorGraphHasNoCycle(): void {
		$graph = $this->graph();

		$this->assertNotEmpty(
			actual: $graph,
			message: 'No constructors were read from lib/. The scanner found nothing, which is not the same as finding nothing wrong.'
		);

		$cycles = $this->cycles(graph: $graph);

		$this->assertSame(
			expected: [],
			actual: $cycles,
			message: "A class is reachable from its own constructor. Nextcloud's container will recurse until the stack runs out and `occ app:enable dossiq` will fail.\n- " . implode("\n- ", $cycles)
		);
	}//end testConstructorGraphHasNoCycle()

	/**
	 * The scanner reads a dependency it can point at, so a silent empty graph
	 * cannot pass for a clean one.
	 *
	 * @return void
	 */
	public function testScannerReadsAKnownDependency(): void {
		$graph = $this->graph();

		$this->assertArrayHasKey(
			key: 'OCA\Dossiq\Service\Task\TaskDeclarationValidator',
			array: $graph,
			message: 'The scanner did not see TaskDeclarationValidator, so it is not reading lib/ the way this test assumes.'
		);

		$this->assertContains(
			needle: 'OCA\Dossiq\Service\Workflow\WorkflowJsonProperty',
			haystack: $graph['OCA\Dossiq\Service\Task\TaskDeclarationValidator'],
			message: 'The scanner saw the class but did not resolve an imported constructor parameter to its fully qualified name.'
		);
	}//end testScannerReadsAKnownDependency()

	/**
	 * Build class -> constructor dependencies for every class under lib/.
	 *
	 * @return array<string, array<int, string>> The graph.
	 */
	private function graph(): array {
		if ($this->graph !== null) {
			return $this->graph;
		}

		$graph = [];
		$root = dirname(__DIR__, 3) . '/lib';
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

		foreach ($files as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$source = file_get_contents($file->getPathname());
			if ($source === false) {
				continue;
			}

			$namespace = '';
			if (preg_match('/^namespace\s+([^;]+);/m', $source, $match) === 1) {
				$namespace = trim($match[1]);
			}

			$imports = [];
			preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $source, $uses, PREG_SET_ORDER);
			foreach ($uses as $use) {
				$alias = ($use[2] ?? '');
				if ($alias === '') {
					$parts = explode('\\', $use[1]);
					$alias = end($parts);
				}

				$imports[$alias] = $use[1];
			}

			if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $class) !== 1) {
				continue;
			}

			$fqn = ($namespace === '' ? $class[1] : $namespace . '\\' . $class[1]);
			$graph[$fqn] = $this->dependencies(source: $source, namespace: $namespace, imports: $imports);
		}//end foreach

		$this->graph = $graph;

		return $graph;
	}//end graph()

	/**
	 * The class names a constructor's typed parameters resolve to.
	 *
	 * @param string                $source    The file source.
	 * @param string                $namespace The file's namespace.
	 * @param array<string, string> $imports   Alias to fully qualified name.
	 *
	 * @return array<int, string> The dependencies.
	 */
	private function dependencies(string $source, string $namespace, array $imports): array {
		if (preg_match('/function\s+__construct\s*\((.*?)\)\s*[{:]/s', $source, $match) !== 1) {
			return [];
		}

		$found = [];
		preg_match_all('/(?:private|protected|public|readonly|\s)*?([\\\\\w|]+)\s+\$\w+/', $match[1], $types, PREG_SET_ORDER);
		foreach ($types as $type) {
			foreach (explode('|', $type[1]) as $part) {
				$part = ltrim(trim($part), '?');
				if ($part === '' || ctype_lower($part[0]) === true) {
					continue;
				}

				if (str_starts_with($part, '\\') === true) {
					$found[] = substr($part, 1);
					continue;
				}

				$segments = explode('\\', $part);
				$head = array_shift($segments);
				if (isset($imports[$head]) === true) {
					$tail = (count($segments) > 0 ? '\\' . implode('\\', $segments) : '');
					$found[] = $imports[$head] . $tail;
					continue;
				}

				$found[] = ($namespace === '' ? $part : $namespace . '\\' . $part);
			}
		}//end foreach

		return array_values(array_unique($found));
	}//end dependencies()

	/**
	 * Every cycle in the graph, each rendered as an arrow chain.
	 *
	 * @param array<string, array<int, string>> $graph The graph.
	 *
	 * @return array<int, string> The cycles.
	 */
	private function cycles(array $graph): array {
		$state = [];
		$stack = [];
		$found = [];

		$visit = function (string $node) use (&$visit, &$state, &$stack, &$found, $graph): void {
			$state[$node] = 'open';
			$stack[] = $node;

			foreach (($graph[$node] ?? []) as $next) {
				if (array_key_exists($next, $graph) === false) {
					continue;
				}

				if (($state[$next] ?? '') === 'open') {
					$at = array_search($next, $stack, true);
					$found[] = implode(' -> ', array_slice($stack, (int) $at)) . ' -> ' . $next;
					continue;
				}

				if (isset($state[$next]) === false) {
					$visit($next);
				}
			}

			array_pop($stack);
			$state[$node] = 'closed';
		};

		$nodes = array_keys($graph);
		sort($nodes);
		foreach ($nodes as $node) {
			if (isset($state[$node]) === false) {
				$visit($node);
			}
		}

		return $found;
	}//end cycles()
}//end class
