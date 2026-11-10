<?php

/**
 * Tests for matching an object payload against a configured schema.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener\Support
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

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Listener\ApplicantTermNoteListener;
use OCA\Dossiq\Listener\BeschikkingImmutabilityListener;
use OCA\Dossiq\Listener\BewijsstukImmutabilityListener;
use OCA\Dossiq\Listener\CaseDeadlineFollowsTermListener;
use OCA\Dossiq\Listener\CaseInheritedDeadlineListener;
use OCA\Dossiq\Listener\CaseMarkerDerivationListener;
use OCA\Dossiq\Listener\CasePriorityDerivationListener;
use OCA\Dossiq\Listener\CaseRequesterProjectionListener;
use OCA\Dossiq\Listener\ChecklistRunImmutabilityListener;
use OCA\Dossiq\Listener\DerivedStatusListener;
use OCA\Dossiq\Listener\IntakeFormBindingListener;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every listener's schema check answers the same table for the schema its setting names.
 *
 * @coversNothing
 *
 * @spec openspec/specs/case-management/spec.md
 */
class SchemaMembershipTest extends TestCase {

	/**
	 * Payloads and whether they belong to the configured schema `target`.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function payloads(): array {
		return [
			'schema under self' => [['@self' => ['schema' => 'target']], true],
			'schema url under self' => [['@self' => ['schema' => 'https://x/api/schemas/target']], true],
			'schema at the top' => [['schema' => 'target'], true],
			'null under self falls back to the top' => [['@self' => ['schema' => null], 'schema' => 'target'], true],
			'self wins over the top' => [['@self' => ['schema' => 'other'], 'schema' => 'target'], false],
			'a longer name is not the schema' => [['@self' => ['schema' => 'xtarget']], false],
			'a url ending in a longer name' => [['@self' => ['schema' => 'https://x/xtarget']], false],
			'no schema at all' => [['id' => 'o-1'], false],
			'an empty schema' => [['@self' => ['schema' => '']], false],
		];
	}//end payloads()

	/**
	 * Each listener, the private check it asks, and the setting that names its schema.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: string}>
	 */
	public static function listeners(): array {
		return [
			'intake form binding' => [IntakeFormBindingListener::class, 'isCaseTypeSchema', 'case_type_schema'],
			'case priority' => [CasePriorityDerivationListener::class, 'isCaseSchema', 'case_schema'],
			'checklist run' => [ChecklistRunImmutabilityListener::class, 'isChecklistRunSchema', 'inspection_checklist_run_schema'],
			'deadline follows term' => [CaseDeadlineFollowsTermListener::class, 'isCaseSchema', 'case_schema'],
			'beschikking' => [BeschikkingImmutabilityListener::class, 'isBeschikkingSchema', 'beschikking_schema'],
			'inherited deadline' => [CaseInheritedDeadlineListener::class, 'isCaseSchema', 'case_schema'],
			'bewijsstuk' => [BewijsstukImmutabilityListener::class, 'isBewijsstukSchema', 'bewijsstuk_schema'],
			'derived status' => [DerivedStatusListener::class, 'isCaseSchema', 'case_schema'],
			'requester projection' => [CaseRequesterProjectionListener::class, 'isCaseSchema', 'case_schema'],
			'applicant term note' => [ApplicantTermNoteListener::class, 'isCaseSchema', 'case_schema'],
			'case markers' => [CaseMarkerDerivationListener::class, 'isCaseSchema', 'case_schema'],
		];
	}//end listeners()

	/**
	 * Every listener answers the whole table for its own setting, and nothing when the setting is empty.
	 *
	 * @param class-string $class  The listener.
	 * @param string       $method Its private schema check.
	 * @param string       $key    The setting that names its schema.
	 *
	 * @dataProvider listeners
	 *
	 * @return void
	 */
	public function testTheListenerAnswersTheTable(string $class, string $method, string $key): void {
		foreach (self::payloads() as $name => [$payload, $expected]) {
			$this->assertSame($expected, $this->ask(class: $class, method: $method, settings: [$key => 'target'], payload: $payload), $name);
			$this->assertFalse($this->ask(class: $class, method: $method, settings: [], payload: $payload), $name . ', nothing configured');
		}
	}//end testTheListenerAnswersTheTable()

	/**
	 * Ask a listener's private check with the given settings.
	 *
	 * @param class-string          $class    The listener.
	 * @param string                $method   Its private schema check.
	 * @param array<string, string> $settings The settings it reads.
	 * @param array<string, mixed>  $payload  The payload.
	 *
	 * @return bool
	 */
	private function ask(string $class, string $method, array $settings, array $payload): bool {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($settings[$key] ?? '')
		);

		$listener = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		(function (SettingsService $settingsService): void {
			$this->settingsService = $settingsService;
		})->call($listener, $settingsService);

		return (new ReflectionMethod($class, $method))->invoke($listener, $payload);
	}//end ask()
}//end class
