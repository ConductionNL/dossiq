<?php

/**
 * The notification rule that tells a handler a term resumed by itself.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/expired-pause-resumes-the-term/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\TermijnService;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 * @spec openspec/changes/expired-pause-resumes-the-term/specs/termijn-pause-extension/spec.md
 */
class TermResumedAfterPauseRuleTest extends TestCase {

	/**
	 * The term event schema as the import ships it.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function eventSchema(): array {
		$root = dirname(__DIR__, 3);
		$base = json_decode((string)file_get_contents($root . '/lib/Settings/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $root . '/lib/Settings/register.d');

		return $merged['components']['schemas']['termijnGebeurtenis'];
	}//end eventSchema()

	/**
	 * Every notice field the service writes is a property the schema declares.
	 *
	 * @return void
	 */
	public function testTheEventDeclaresEveryNoticeField(): void {
		$schema = $this->eventSchema();

		foreach (TermijnService::NOTICE_FIELDS as $field) {
			$this->assertArrayHasKey($field, $schema['properties'], $field . ' is written but not declared');
			$this->assertSame('string', $schema['properties'][$field]['type']);
		}

		$this->assertSame('1.3.0', $schema['version']);
		$this->assertContains('hervat', $schema['properties']['type']['enum']);
	}//end testTheEventDeclaresEveryNoticeField()

	/**
	 * The rule fires on a created event that carries `notify`, and tells that user.
	 *
	 * @return void
	 */
	public function testTheRuleTellsTheUserTheEventNames(): void {
		$rule = $this->eventSchema()['x-openregister-notifications']['termResumedAfterPause'];

		$this->assertTrue($rule['enabled']);
		$this->assertSame('created', $rule['trigger']['type']);
		$this->assertSame(['field' => 'notify', 'operator' => 'notIn', 'values' => ['']], $rule['trigger']['filter']);
		$this->assertSame([['kind' => 'field', 'field' => 'notify']], $rule['recipients']);
		$this->assertSame(['nc-notification'], $rule['channels']);
	}//end testTheRuleTellsTheUserTheEventNames()

	/**
	 * Both languages name the case and the new end date, in subject and message.
	 *
	 * @return void
	 */
	public function testTheMessageNamesTheCaseAndTheNewEndDate(): void {
		$rule = $this->eventSchema()['x-openregister-notifications']['termResumedAfterPause'];

		foreach (['nl', 'en'] as $language) {
			$this->assertStringContainsString('{{caseTitle}}', $rule['subject'][$language]);
			$this->assertStringContainsString('{{endDate}}', $rule['subject'][$language]);
			$this->assertStringContainsString('{{endDate}}', $rule['message'][$language]);
		}
	}//end testTheMessageNamesTheCaseAndTheNewEndDate()
}//end class
