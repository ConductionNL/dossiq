<?php

/**
 * The scannable-services list and the #[McpTool] methods agree, both ways.
 *
 * OpenRegister only scans the classes this list names, so an attributed method
 * on a class missing here is a tool nobody can call, and a listed class with
 * no attributed method is a dead seam. This test reads lib/ for both.
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-201-first-imcpscannableservices-opt-in-exact-enumeration
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Mcp;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Mcp\DossiqScannableServices;
use OCA\Dossiq\Service\Mcp\IntakeTools;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The curated tool catalogue as OpenRegister's scanner will see it.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-201-first-imcpscannableservices-opt-in-exact-enumeration
 */
class DossiqScannableServicesTest extends TestCase {

	/**
	 * The curated read tools this change ships.
	 *
	 * @var array<string>
	 */
	private const READ_TOOLS = [
		'getDeadlineDashboard',
		'getDoorlooptijdMetrics',
		'getKpiOverview',
		'getWorkload',
		'listAvailableTransitions',
		'listOverdueComplaints',
	];

	/**
	 * The reach every curated tool declares (design D2, REQ-MCP-205). A
	 * two-segment id carries no verb, so an undeclared reach resolves to
	 * `external` in OpenRegister's ToolReachResolver and over-gates the tool.
	 *
	 * @var array<string, string>
	 */
	private const REACH = [
		'getDeadlineDashboard' => 'user',
		'getDoorlooptijdMetrics' => 'user',
		'getKpiOverview' => 'user',
		'getWorkload' => 'user',
		'listAvailableTransitions' => 'user',
		'listOverdueComplaints' => 'user',
		'transitionCase' => 'instance',
		'reassignCase' => 'instance',
		'completeTask' => 'instance',
		'extendDeadline' => 'instance',
		'pauseDeadline' => 'instance',
		'resumeDeadline' => 'instance',
		'scheduleAppointment' => 'external',
		'cancelAppointment' => 'external',
		'draftBeschikking' => 'user',
		'fileCase' => 'instance',
	];

	/**
	 * The listed classes are exactly the lib/ classes that carry #[McpTool].
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-201-first-imcpscannableservices-opt-in-exact-enumeration
	 */
	public function testTheListIsExactlyTheAttributedClasses(): void {
		$listed = (new DossiqScannableServices())->getScannableServiceClasses();
		$attributed = $this->attributedClassesInLib();

		sort($listed);
		sort($attributed);
		$this->assertNotEmpty($attributed, 'The scan found no #[McpTool] at all, so it cannot mean anything.');
		$this->assertSame($attributed, $listed);
	}//end testTheListIsExactlyTheAttributedClasses()

	/**
	 * Every tool declares a description, a valid scope, a subject and an action,
	 * and the reads declare themselves read-only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
	 */
	public function testEveryToolDeclaresWhatTheScannerAndTheGrantMatrixRead(): void {
		$names = [];
		foreach ($this->tools() as [$method, $tool]) {
			$where = $method->class . '::' . $method->getName();
			$this->assertTrue($method->isPublic() && $method->isStatic() === false, $where . ' is not a public instance method.');
			$this->assertNotEmpty($tool->name, $where . ' has no name.');
			$this->assertNotEmpty($tool->description, $where . ' has no description.');
			$this->assertContains($tool->scope, ['read', 'create', 'update', 'delete'], $where . ' scope.');
			$this->assertNotEmpty($tool->subject, $where . ' has no subject.');
			$this->assertNotEmpty($tool->action, $where . ' has no action.');
			$this->assertStringNotContainsString('.', (string)$tool->name, $where . ': the id is dossiq.<name>, two segments.');
			$this->assertSame((self::REACH[$tool->name] ?? 'undeclared in REACH'), $tool->reach, $where . ' reach (REQ-MCP-205).');

			if (in_array($tool->name, self::READ_TOOLS, true) === true) {
				$this->assertSame('read', $tool->scope, $where);
				$this->assertTrue($tool->readOnlyHint, $where);
			} else {
				// A write never claims to be read-only: hermiq gates an
				// ungranted write, and a false readOnlyHint is what says it is one.
				$this->assertNotSame('read', $tool->scope, $where);
				$this->assertFalse($tool->readOnlyHint, $where);
			}

			foreach ($method->getParameters() as $param) {
				$type = $param->getType();
				// `array` is a list the scanner describes as a JSON array; only
				// hermiq's intake transcript (`fileCase` $messages) needs one.
				$this->assertTrue(
					$type instanceof ReflectionNamedType && in_array($type->getName(), ['string', 'int', 'bool', 'float', 'array'], true),
					$where . ' parameter $' . $param->getName() . ' is not a scalar the scanner can describe.'
				);
				$this->assertNotSame('userId', $param->getName(), $where . ' lets the agent name the acting user.');
			}

			$names[] = $tool->name;
		}//end foreach

		sort($names);
		$expected = array_keys(self::REACH);
		sort($expected);
		$this->assertSame($expected, $names);
	}//end testEveryToolDeclaresWhatTheScannerAndTheGrantMatrixRead()

	/**
	 * No curated tool class writes through ObjectService: each one calls the
	 * service its controller calls (REQ-MCP-204, task 3.2). The derived
	 * surface staying read-only is DossiqMcpDialectTest's job.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
	 */
	public function testNoToolClassReachesPastItsOwningService(): void {
		$checked = 0;
		foreach ((new DossiqScannableServices())->getScannableServiceClasses() as $class) {
			if (str_starts_with($class, 'OCA\\Dossiq\\Service\\Mcp\\') === false) {
				continue;
			}

			$source = (string)file_get_contents((string)(new ReflectionClass($class))->getFileName());
			foreach (['ObjectService', 'getObjectService', 'saveObject', 'updateObject', 'patchObject', 'deleteObject'] as $needle) {
				$this->assertStringNotContainsString($needle, $source, $class . ' reaches past its owning service: ' . $needle);
			}

			$checked++;
		}

		$this->assertSame(6, $checked, 'Expected the six tool classes under Service\\Mcp.');
	}//end testNoToolClassReachesPastItsOwningService()

	/**
	 * The list is registered under the alias OpenRegister resolves.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-201-first-imcpscannableservices-opt-in-exact-enumeration
	 */
	public function testTheListIsRegisteredUnderOpenRegistersAlias(): void {
		$this->assertSame(
			'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::' . Application::APP_ID,
			DossiqScannableServices::ALIAS
		);

		// Application::register() cannot be built in a unit run (its parent needs
		// a live DI container), so read the binding where the gate reads it.
		$source = file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');
		$this->assertIsString($source);
		$bound = preg_match("/registerServiceAlias\\(\\s*'([^']+)',\\s*DossiqScannableServices::class/", $source, $found);
		$this->assertSame(1, $bound, 'Application.php binds DossiqScannableServices under a spelled-out alias.');
		$this->assertSame(DossiqScannableServices::ALIAS, stripcslashes($found[1]));
	}//end testTheListIsRegisteredUnderOpenRegistersAlias()

	/**
	 * Every attributed method with its attribute instance.
	 *
	 * @return list<array{0: ReflectionMethod, 1: McpTool}>
	 */
	private function tools(): array {
		$tools = [];
		foreach ((new DossiqScannableServices())->getScannableServiceClasses() as $class) {
			foreach ((new ReflectionClass($class))->getMethods() as $method) {
				foreach ($method->getAttributes(McpTool::class) as $attribute) {
					$tools[] = [$method, $attribute->newInstance()];
				}
			}
		}

		return $tools;
	}//end tools()

	/**
	 * The classes under lib/ whose source carries a #[McpTool] attribute.
	 *
	 * @return list<string>
	 */
	private function attributedClassesInLib(): array {
		$lib = dirname(__DIR__, 3) . '/lib';
		$classes = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			if (preg_match('/^\s*#\[McpTool\(/m', $source) !== 1) {
				continue;
			}

			$relative = substr($file->getPathname(), strlen($lib) + 1, -4);
			$classes[] = 'OCA\\Dossiq\\' . str_replace('/', '\\', $relative);
		}

		return $classes;
	}//end attributedClassesInLib()

	/**
	 * Exactly the create-only intake tool carries hermiq's intake mark, and it
	 * declares the create scope and action hermiq requires beside the mark
	 * (decision 177). No tool that reads or changes an existing case is marked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-only-the-create-tool-is-annotated-for-intake
	 */
	public function testOnlyTheCreateToolCarriesTheIntakeMark(): void {
		$marked = [];
		foreach ($this->tools() as [$method, $tool]) {
			if (($tool->annotations[IntakeTools::INTAKE_MARK] ?? false) === true) {
				$marked[] = $tool->name;
				$this->assertSame('create', $tool->scope);
				$this->assertSame('create', $tool->action);
				$this->assertFalse($tool->readOnlyHint);
			}
		}

		$this->assertSame(['fileCase'], $marked);
	}//end testOnlyTheCreateToolCarriesTheIntakeMark()

	/**
	 * The six curated reads, and nothing else, are offered to hermiq's outbound
	 * surface (`outsideAgent`, design D-5). The caller's own rights still decide
	 * per call, because each read runs its controller's gate; a write is never
	 * offered there.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-the-conversational-intake-files-through-dossiqs-own-create-only-path-req-aic-05
	 */
	public function testOnlyTheReadsAreOfferedToTheOutsideSurface(): void {
		$offered = [];
		foreach ($this->tools() as [$method, $tool]) {
			if (($tool->annotations['outsideAgent'] ?? false) === true) {
				$offered[] = $tool->name;
				$this->assertTrue($tool->readOnlyHint, $tool->name);
			}
		}

		sort($offered);
		$reads = self::READ_TOOLS;
		sort($reads);
		$this->assertSame($reads, $offered);
	}//end testOnlyTheReadsAreOfferedToTheOutsideSurface()
}//end class
