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
use OCA\Dossiq\AppInfo\Registrar\AppHostRegistrar;
use OCA\Dossiq\Mcp\DossiqScannableServices;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
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
				$this->assertTrue(
					$type instanceof ReflectionNamedType && in_array($type->getName(), ['string', 'int', 'bool', 'float'], true),
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

		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		(new AppHostRegistrar())->register(context: $context);

		$this->assertSame(DossiqScannableServices::class, $aliases[DossiqScannableServices::ALIAS] ?? null);
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
}//end class
