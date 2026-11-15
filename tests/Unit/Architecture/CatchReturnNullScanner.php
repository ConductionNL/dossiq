<?php

/**
 * Scanner for catch-and-return-nothing sites under lib/Service.
 *
 * Finds every `catch (\Throwable ...)` whose block answers `null` or `[]`
 * within its first three statements, which is the shape that makes a refusal
 * indistinguishable from a failure: the caller sees the same empty value
 * either way and reports "not authorised" for "could not be read".
 *
 * Three properties matter, and each one is here because a line-based or
 * indentation-based scanner got them wrong somewhere in the fleet:
 *
 *  1. It works on a COMMENT- AND STRING-MASKED copy, so a docblock that
 *     describes the anti-pattern, or a string literal quoting it, is not
 *     itself a finding.
 *  2. It walks BRACES, not indentation. Hydra's gate-8 shipped an awk
 *     implementation whose terminators were hard-coded four- and eight-space
 *     indents; on a tab-indented file the catch body ran to end of file.
 *  3. It counts STATEMENTS, not lines. A three-line window misses the same
 *     shape the moment a multi-line logger call sits in front of the return,
 *     which is how the same tree measures 90 sites by lines and 207 by
 *     statements.
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
 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

/**
 * Lists the catch blocks that answer nothing, and compares them to an allowlist.
 *
 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
 */
final class CatchReturnNullScanner {
	/**
	 * How many statements after the catch are inspected (design D-1).
	 *
	 * @var int
	 */
	public const WINDOW = 3;

	/**
	 * The classes an allowlist entry may declare (design D-2).
	 *
	 * @var array<int, string>
	 */
	public const CLASSES = ['refusal', 'degradation', 'read-miss'];

	/**
	 * Scan every PHP file under a directory, recursively.
	 *
	 * @param string $dir  Directory to walk.
	 * @param string $root Path the reported file names are relative to.
	 *
	 * @return array<int, array{file: string, method: string, line: int, occurrence: int, returns: string}>
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public static function scanDirectory(string $dir, string $root): array {
		$files = [];
		$walker = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
		);
		foreach ($walker as $entry) {
			if ($entry->isFile() === true && $entry->getExtension() === 'php') {
				$files[] = $entry->getPathname();
			}
		}

		sort($files);

		$sites = [];
		foreach ($files as $file) {
			foreach (self::scanFile(path: $file, root: $root) as $site) {
				$sites[] = $site;
			}
		}

		return $sites;
	}//end scanDirectory()

	/**
	 * Scan one PHP file.
	 *
	 * @param string $path The file to read.
	 * @param string $root Path the reported file name is relative to.
	 *
	 * @return array<int, array{file: string, method: string, line: int, occurrence: int, returns: string}>
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public static function scanFile(string $path, string $root): array {
		$source = (string)file_get_contents($path);
		$masked = self::mask(source: $source);
		$name = self::relative(path: $path, root: $root);

		$sites = [];
		$seen = [];
		$offset = 0;
		while (preg_match('/\bcatch\s*\(\s*\\\\?Throwable\b/', $masked, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
			$at = (int)$m[0][1];
			$offset = ($at + strlen((string)$m[0][0]));

			$block = self::blockAfter(masked: $masked, from: $offset);
			if ($block === null) {
				continue;
			}

			$returns = self::answersNothing(
				body: substr($masked, ($block[0] + 1), (($block[1] - $block[0]) - 2))
			);
			if ($returns === null) {
				continue;
			}

			$method = self::enclosingMethod(masked: $masked, before: $at);
			$key = $method;
			$seen[$key] = (($seen[$key] ?? 0) + 1);

			$sites[] = [
				'file' => $name,
				'method' => $method,
				'line' => (substr_count($masked, "\n", 0, $at) + 1),
				'occurrence' => $seen[$key],
				'returns' => $returns,
			];
		}//end while

		return $sites;
	}//end scanFile()

	/**
	 * Compare scanned sites to allowlist entries.
	 *
	 * Returns the three ways the pair can disagree, each as a list of
	 * human-readable identifiers: a site nobody wrote down, an entry whose
	 * site is gone, and the ceiling being exceeded.
	 *
	 * @param array<int, array{file: string, method: string, line: int, occurrence: int, returns: string}> $sites   Scanned sites.
	 * @param array<string, mixed>                                                                         $allowed Decoded allowlist document.
	 *
	 * @return array{unlisted: array<int, string>, stale: array<int, string>, ceiling: array<int, string>}
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public static function compare(array $sites, array $allowed): array {
		$entries = [];
		foreach ((array)($allowed['sites'] ?? []) as $entry) {
			$entries[self::key(entry: (array)$entry)] = (array)$entry;
		}

		$found = [];
		foreach ($sites as $site) {
			$found[self::key(entry: $site)] = $site;
		}

		$unlisted = [];
		foreach ($found as $key => $site) {
			if (array_key_exists($key, $entries) === false) {
				$unlisted[] = $key . ' (line ' . $site['line'] . ', ' . $site['returns'] . ')';
			}
		}

		$stale = [];
		foreach ($entries as $key => $entry) {
			if (array_key_exists($key, $found) === false) {
				$stale[] = $key;
			}
		}

		$ceiling = [];
		$max = (int)($allowed['ceiling'] ?? 0);
		if (count($sites) > $max) {
			$ceiling[] = sprintf('%d sites against a ceiling of %d', count($sites), $max);
		}

		sort($unlisted);
		sort($stale);

		return ['unlisted' => $unlisted, 'stale' => $stale, 'ceiling' => $ceiling];
	}//end compare()

	/**
	 * The identity of a site: file, method and which catch inside that method.
	 *
	 * Line numbers are deliberately not part of it. An entry keyed on a line
	 * goes stale on the next unrelated edit above it, and a list that goes
	 * stale by itself gets bulk-regenerated instead of read.
	 *
	 * @param array<string, mixed> $entry A scanned site or an allowlist entry.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public static function key(array $entry): string {
		return sprintf(
			'%s::%s#%d',
			(string)($entry['file'] ?? ''),
			(string)($entry['method'] ?? ''),
			(int)($entry['occurrence'] ?? 1)
		);
	}//end key()

	/**
	 * Blank comments and string contents, keeping every byte offset and line.
	 *
	 * @param string $source The PHP source.
	 *
	 * @return string The masked source, the same length as the original.
	 */
	private static function mask(string $source): string {
		$out = '';
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				$out .= $token;
				continue;
			}

			$text = (string)$token[1];
			$blankWhole = [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_ENCAPSED_AND_WHITESPACE];
			if (in_array($token[0], $blankWhole, true) === true) {
				$out .= self::blank(text: $text);
				continue;
			}

			if ($token[0] === T_CONSTANT_ENCAPSED_STRING && strlen($text) > 2) {
				$out .= ($text[0] . self::blank(text: substr($text, 1, -1)) . $text[(strlen($text) - 1)]);
				continue;
			}

			$out .= $text;
		}//end foreach

		return $out;
	}//end mask()

	/**
	 * Replace every character with a space, except newlines.
	 *
	 * @param string $text The text to blank.
	 *
	 * @return string Same length, same line breaks, no content.
	 */
	private static function blank(string $text): string {
		return (string)preg_replace('/[^\n]/', ' ', $text);
	}//end blank()

	/**
	 * The `{...}` span starting at or after an offset.
	 *
	 * @param string $masked The masked source.
	 * @param int    $from   Offset to start looking from.
	 *
	 * @return array{0: int, 1: int}|null Open and one-past-close offsets, or null.
	 */
	private static function blockAfter(string $masked, int $from): ?array {
		$length = strlen($masked);
		$i = $from;
		while ($i < $length && $masked[$i] !== '{') {
			if ($masked[$i] === ';') {
				return null;
			}

			$i++;
		}

		if ($i >= $length) {
			return null;
		}

		$depth = 0;
		for ($j = $i; $j < $length; $j++) {
			if ($masked[$j] === '{') {
				$depth++;
			} elseif ($masked[$j] === '}') {
				$depth--;
				if ($depth === 0) {
					return [$i, ($j + 1)];
				}
			}
		}

		return null;
	}//end blockAfter()

	/**
	 * Whether one of the block's first three statements answers nothing.
	 *
	 * A statement guarded by a condition does not count: `if (...) { return
	 * null; }` is a branch, not the block's answer, and that is a different
	 * shape with a different fix.
	 *
	 * @param string $body The catch block's body, braces excluded.
	 *
	 * @return string|null `return null;`, `return [];`, or null for neither.
	 */
	private static function answersNothing(string $body): ?string {
		foreach (array_slice(self::statements(body: $body), 0, self::WINDOW) as $statement) {
			$flat = trim((string)preg_replace('/\s+/', ' ', $statement));
			if (preg_match('/^return\s+null\s*;$/', $flat) === 1) {
				return 'return null;';
			}

			if (preg_match('/^return\s*\[\s*\]\s*;$/', $flat) === 1) {
				return 'return [];';
			}
		}

		return null;
	}//end answersNothing()

	/**
	 * Split a block body into its top-level statements.
	 *
	 * @param string $body The block body.
	 *
	 * @return array<int, string> The statements, in order, braces and all.
	 */
	private static function statements(string $body): array {
		$statements = [];
		$current = '';
		$depth = 0;
		$length = strlen($body);

		for ($i = 0; $i < $length; $i++) {
			$char = $body[$i];
			$current .= $char;

			if ($char === '{' || $char === '(' || $char === '[') {
				$depth++;
				continue;
			}

			if ($char === '}' || $char === ')' || $char === ']') {
				$depth--;
				if ($depth === 0 && $char === '}') {
					$statements[] = $current;
					$current = '';
				}

				continue;
			}

			if ($char === ';' && $depth === 0) {
				$statements[] = $current;
				$current = '';
			}
		}//end for

		if (trim($current) !== '') {
			$statements[] = $current;
		}

		return array_values(
			array_filter(
				array_map('trim', $statements),
				static fn (string $statement): bool => $statement !== ''
			)
		);
	}//end statements()

	/**
	 * The name of the function whose body contains an offset.
	 *
	 * @param string $masked The masked source.
	 * @param int    $before Offset of the catch.
	 *
	 * @return string The nearest preceding function name, or `{file}` when none.
	 */
	private static function enclosingMethod(string $masked, int $before): string {
		$head = substr($masked, 0, $before);
		$count = preg_match_all('/\bfunction\s+([A-Za-z_]\w*)\s*\(/', $head, $matches);
		if ($count === false || $count === 0) {
			return '{file}';
		}

		return (string)$matches[1][($count - 1)];
	}//end enclosingMethod()

	/**
	 * Express a path relative to a root, with forward slashes.
	 *
	 * @param string $path The absolute path.
	 * @param string $root The root to strip.
	 *
	 * @return string The relative path.
	 */
	private static function relative(string $path, string $root): string {
		$real = (string)realpath($path);
		$base = ((string)realpath($root) . DIRECTORY_SEPARATOR);
		if (str_starts_with($real, $base) === true) {
			$real = substr($real, strlen($base));
		}

		return str_replace('\\', '/', $real);
	}//end relative()
}//end class
