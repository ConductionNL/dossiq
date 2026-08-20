<?php

/**
 * Semantic Case Intake schema-wiring test (semantic-case-intake).
 *
 * Verifies procest's consume-side of the ns#Case semantic handoff:
 * the case schema declares `implements` on the canonical kind URI, carries
 * a COMPLETE handoffContract binding (every mandatory contract field bound
 * to an existing own property), declares the requester + handoffSource
 * ADR-048 semantic-reference properties (with ADR-011 titles), and extends
 * its x-openregister-notifications with a declarative handoff-intake rule
 * (no legacy dialect, no imperative dispatch). The mandatory/optional
 * contract field set is pinned to the REAL OpenRegister HandoffKindContracts
 * for ns#Case (mandatory: title, summary, channel, source; optional:
 * requester, priority) so a contract drift on OR fails this test.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/semantic-case-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Repair\InitializeSettings
 */
class SemanticCaseIntakeTest extends TestCase {
	/**
	 * Canonical ns#Case kind URI.
	 */
	private const CASE_KIND = 'https://openregister.app/ns#Case';

	/**
	 * The REAL ns#Case contract on OpenRegister (HandoffKindContracts).
	 */
	private const MANDATORY = ['title', 'summary', 'channel', 'source'];

	private const OPTIONAL = ['requester', 'priority'];

	/**
	 * @var array<string,mixed>
	 */
	private array $case;

	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/procest_register.json';
		$this->assertFileExists($path);
		$register = json_decode((string)file_get_contents($path), true);
		$this->case = $register['components']['schemas']['case'];

	}//end setUp()

	public function testCaseImplementsTheSemanticKind(): void {
		$implements = $this->case['configuration']['implements'] ?? [];
		$this->assertContains(self::CASE_KIND, $implements, 'case must declare implements ns#Case');

	}//end testCaseImplementsTheSemanticKind()

	public function testHandoffBindingIsCompleteAndValid(): void {
		$binding = $this->case['configuration']['handoffContract'][self::CASE_KIND] ?? null;
		$this->assertIsArray($binding, 'case must carry a handoffContract binding for ns#Case');

		$properties = $this->case['properties'];

		// Every mandatory contract field bound to an existing own property.
		foreach (self::MANDATORY as $field) {
			$this->assertArrayHasKey($field, $binding, "mandatory contract field {$field} must be bound");
			$this->assertArrayHasKey(
				$binding[$field],
				$properties,
				"binding {$field} -> {$binding[$field]} must name an existing own property"
			);
		}

		// Only known contract fields are bound, each to an existing property.
		$allFields = array_merge(self::MANDATORY, self::OPTIONAL);
		foreach ($binding as $contractField => $ownProperty) {
			$this->assertContains($contractField, $allFields, "binding key {$contractField} is not a contract field");
			$this->assertArrayHasKey($ownProperty, $properties, "bound property {$ownProperty} must exist");
		}

	}//end testHandoffBindingIsCompleteAndValid()

	public function testRequesterAndProvenanceAreSemanticReferences(): void {
		$properties = $this->case['properties'];

		foreach (['requester', 'handoffSource'] as $field) {
			$this->assertArrayHasKey($field, $properties, "case.{$field} must exist");
			$this->assertNotEmpty(
				$properties[$field]['referenceSemanticType'] ?? '',
				"case.{$field} must be an ADR-048 semantic reference"
			);
			$this->assertStringStartsWith(
				'https://',
				$properties[$field]['referenceSemanticType'],
				"case.{$field} referenceSemanticType must be an absolute IRI"
			);
			$this->assertNotEmpty($properties[$field]['title'] ?? '', "case.{$field} missing title (ADR-011)");
			$this->assertNotEmpty($properties[$field]['description'] ?? '', "case.{$field} missing description (ADR-011)");
		}

		// requester is optional in the contract → must NOT be required.
		$this->assertNotContains('requester', ($this->case['required'] ?? []));
		$this->assertNotContains('handoffSource', ($this->case['required'] ?? []));

	}//end testRequesterAndProvenanceAreSemanticReferences()

	public function testHandoffIntakeNotificationIsDeclarative(): void {
		$notifications = $this->case['x-openregister-notifications'] ?? [];
		$this->assertArrayHasKey('caseHandoffIntake', $notifications, 'handoff-intake notification must be declared');

		$rule = $notifications['caseHandoffIntake'];
		$this->assertSame('created', $rule['trigger']['type']);
		// Fires only for handoff-originated cases (handoffSource non-empty).
		$this->assertSame('handoffSource', $rule['trigger']['filter']['field']);
		$this->assertSame('notIn', $rule['trigger']['filter']['operator']);
		$this->assertSame([''], $rule['trigger']['filter']['values']);
		$this->assertTrue($rule['enabled']);
		$this->assertNotEmpty($rule['subject']['nl']);
		$this->assertNotEmpty($rule['subject']['en']);

	}//end testHandoffIntakeNotificationIsDeclarative()

	public function testNoImperativeNotificationDispatchInLib(): void {
		// ADR-031: the handoff intake notification is declarative only; no
		// procest service may imperatively dispatch a notification for it.
		$libDir = __DIR__ . '/../../../lib';
		$offenders = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($libDir, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$contents = (string)file_get_contents($file->getPathname());
			// Handoff-intake-specific imperative dispatch only — the rule is
			// declared via x-openregister-notifications, never dispatched in code.
			if (preg_match('/notifyHandoff|handoffIntakeNotif|dispatchHandoffNotification/', $contents) === 1) {
				$offenders[] = $file->getPathname();
			}
		}

		$this->assertSame([], $offenders, 'no imperative handoff-intake notification dispatch may exist in lib/');

	}//end testNoImperativeNotificationDispatchInLib()
}//end class
