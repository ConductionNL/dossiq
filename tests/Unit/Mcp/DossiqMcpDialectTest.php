<?php

/**
 * Dossiq's MCP surface is declared, curated and read-only.
 *
 * ADR-063 makes OpenRegister the single MCP registry: a schema opts in with an
 * `x-openregister-mcp` block and OpenRegister derives `dossiq.{slug}.{verb}`
 * tools from it. This test reads the register exactly as the import does (the
 * monolith plus every `register.d` fragment, merged by the real
 * RegisterFragmentMerger) and pins the curated set, the read-only posture, the
 * filter allowlist and the absence of any hand-written tool provider.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Mcp
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
 * @spec openspec/specs/mcp-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Mcp;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The declared MCP dialect on the merged dossiq register.
 *
 * @spec openspec/specs/mcp-integration/spec.md
 */
class DossiqMcpDialectTest extends TestCase {

	/**
	 * The curated schemas and the verbs each one declares (design D1).
	 *
	 * @var array<string, array<string>>
	 */
	private const CURATED = [
		'case' => ['search', 'get'],
		'caseType' => ['search', 'get'],
		'statusType' => ['search', 'get'],
		'statusRecord' => ['search'],
		'decision' => ['search', 'get'],
		'result' => ['get'],
		'resultType' => ['search', 'get'],
		'document' => ['search', 'get'],
		'caseDocument' => ['search'],
		'objectionProceeding' => ['search', 'get'],
		'deadlineInstance' => ['search', 'get'],
		'complaint' => ['get'],
	];

	/**
	 * Properties that identify a citizen and may never be a search filter (design D5).
	 *
	 * @var array<string>
	 */
	private const IDENTIFYING = [
		'initiatorSourceId',
		'initiatorDisplayName',
		'initiatorType',
		'requester',
		'complainant',
	];

	/**
	 * The merged register's schemas, keyed by component name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $schemas = [];

	/**
	 * Merge the register the way ConfigurationImport does.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$settings = dirname(__DIR__, 3) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($settings . '/dossiq_register.json'), true);
		$this->assertIsArray($base);

		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $settings . '/register.d');
		$this->schemas = $merged['components']['schemas'];
	}//end setUp()

	/**
	 * Exactly the curated schemas opt in, with exactly their verbs.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	public function testExactlyTheCuratedSchemasOptIn(): void {
		$declared = [];
		foreach ($this->schemas as $name => $schema) {
			$block = ($schema['configuration']['x-openregister-mcp'] ?? null);
			if (is_array($block) === false) {
				continue;
			}

			$this->assertTrue($block['enabled'] ?? null, $name . ' declares the dialect without enabled:true.');
			$declared[$name] = array_keys($block['tools'] ?? []);
		}

		ksort($declared);
		$expected = self::CURATED;
		ksort($expected);
		$this->assertSame($expected, $declared);

		$toolCount = array_sum(array_map('count', $declared));
		$this->assertSame(20, $toolCount, 'The curated surface is 20 derived tools.');
	}//end testExactlyTheCuratedSchemasOptIn()

	/**
	 * Every declared block passes the dialect's own shape rules and the read-only posture.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	public function testEveryDeclaredBlockIsValidAndReadOnly(): void {
		foreach (array_keys(self::CURATED) as $name) {
			$schema = $this->schemas[$name];
			$problems = $this->problemsOf(
				block: $schema['configuration']['x-openregister-mcp'],
				properties: ($schema['properties'] ?? [])
			);
			$this->assertSame([], $problems, $name . ': ' . implode(' ', $problems));
		}
	}//end testEveryDeclaredBlockIsValidAndReadOnly()

	/**
	 * OpenRegister's own validator accepts every block, whenever it is loadable.
	 *
	 * CI's PHPUnit job clones OpenRegister beside this app and the bootstrap
	 * registers its classes; on a machine without it the mirror rules above
	 * are the check, and this test says which one ran.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	public function testOpenRegistersValidatorAcceptsEveryBlock(): void {
		$validatorClass = 'OCA\\OpenRegister\\Service\\Mcp\\McpAnnotationValidator';
		if (class_exists($validatorClass) === false) {
			$this->addToAssertionCount(1);
			$this->assertFalse(class_exists($validatorClass), 'OpenRegister absent: the mirror rules are the check.');
			return;
		}

		$validator = new $validatorClass();
		foreach (array_keys(self::CURATED) as $name) {
			$schema = $this->schemas[$name];
			$errors = $validator->validate(
				[
					'properties' => ($schema['properties'] ?? []),
					'x-openregister-mcp' => $schema['configuration']['x-openregister-mcp'],
				]
			);
			$this->assertSame([], $errors, $name . ' is refused by OpenRegister.');
		}
	}//end testOpenRegistersValidatorAcceptsEveryBlock()

	/**
	 * The checker sees a write verb, an unknown filter and an identifying filter.
	 *
	 * A checker that returns no problems for anything would let every test
	 * above pass, so it is run against a block that breaks each rule.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	public function testTheCheckerCatchesWhatItIsFor(): void {
		$bad = [
			'enabled' => true,
			'tools' => [
				'search' => ['scope' => 'read', 'readOnlyHint' => true, 'filters' => ['nope', 'initiatorSourceId']],
				'update' => ['scope' => 'update'],
			],
		];

		$problems = implode("\n", $this->problemsOf(block: $bad, properties: ['initiatorSourceId' => ['type' => 'string']]));
		$this->assertStringContainsString('write verb update', $problems);
		$this->assertStringContainsString('unknown filter nope', $problems);
		$this->assertStringContainsString('identifying filter initiatorSourceId', $problems);
	}//end testTheCheckerCatchesWhatItIsFor()

	/**
	 * No hand-written tool provider exists, so nothing shadows a derived tool.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	public function testNoHandWrittenToolProviderIsLeft(): void {
		$lib = dirname(__DIR__, 3) . '/lib';
		$scanned = 0;
		$hits = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$scanned++;
			$source = (string)file_get_contents($file->getPathname());
			if (preg_match('/implements\s+[^{]*IMcpToolProvider|\'mcpProvider\'\s*=>/', $source) === 1) {
				$hits[] = substr($file->getPathname(), strlen($lib) + 1);
			}
		}

		$this->assertGreaterThan(100, $scanned, 'The scan read too few files to mean anything.');
		$this->assertSame([], $hits, 'A hand-written MCP tool provider shadows the derived tools.');
	}//end testNoHandWrittenToolProviderIsLeft()

	/**
	 * Return the rule breaches of one dialect block.
	 *
	 * Mirrors OpenRegister's McpAnnotationValidator (closed verb set, filters
	 * only on search and only on real properties) and adds dossiq's own rules:
	 * read verbs only, read scope, readOnlyHint, no identifying filter.
	 *
	 * @param array<string, mixed> $block      The x-openregister-mcp block.
	 * @param array<string, mixed> $properties The schema's merged properties.
	 *
	 * @return array<string> One sentence per breach.
	 */
	private function problemsOf(array $block, array $properties): array {
		$problems = [];
		if (($block['enabled'] ?? null) !== true) {
			$problems[] = 'enabled is not true.';
		}

		foreach (($block['tools'] ?? []) as $verb => $config) {
			if (in_array($verb, ['search', 'get'], true) === false) {
				$problems[] = 'write verb ' . $verb . ' declared.';
				continue;
			}

			if (($config['scope'] ?? null) !== 'read' || ($config['readOnlyHint'] ?? null) !== true) {
				$problems[] = $verb . ' is not scope read with readOnlyHint.';
			}

			if (is_string($config['description'] ?? null) === false || trim($config['description']) === '') {
				$problems[] = $verb . ' has no agent-facing description.';
			}

			if (isset($config['filters']) === true && $verb !== 'search') {
				$problems[] = 'filters on ' . $verb . '.';
			}

			foreach (($config['filters'] ?? []) as $filter) {
				if (array_key_exists($filter, $properties) === false) {
					$problems[] = 'unknown filter ' . $filter . '.';
				}

				if (in_array($filter, self::IDENTIFYING, true) === true) {
					$problems[] = 'identifying filter ' . $filter . '.';
				}
			}
		}//end foreach

		return $problems;
	}//end problemsOf()
}//end class
