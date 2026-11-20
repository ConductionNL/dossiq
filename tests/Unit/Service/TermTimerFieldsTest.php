<?php

/**
 * TermTimerFields Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\TermTimerFields;
use PHPUnit\Framework\TestCase;

/**
 * The subject, metadata and pause rules of a term timer's engine configuration.
 *
 * @covers \OCA\Dossiq\Service\TermTimerFields
 */
class TermTimerFieldsTest extends TestCase {

	/**
	 * The subject names the instance and titles it by the case.
	 *
	 * @return void
	 */
	public function testSubjectNamesTheInstanceAndTitlesItByTheCase(): void {
		$subject = (new TermTimerFields())->subject(instance: ['id' => 'inst-1', 'case' => 'case-9'], label: 'Beslistermijn');

		$this->assertSame(
			[
				'subjectType' => 'object',
				'subjectUuid' => 'inst-1',
				'appId' => 'dossiq',
				'title' => 'Beslistermijn case-9',
				'purpose' => 'due',
			],
			$subject
		);
	}//end testSubjectNamesTheInstanceAndTitlesItByTheCase()

	/**
	 * Without a case the title falls back to the instance id; without an id the uuid is empty.
	 *
	 * @return void
	 */
	public function testSubjectFallsBackToTheInstanceIdForTheTitle(): void {
		$fields = new TermTimerFields();

		$this->assertSame('Hersteltermijn inst-2', $fields->subject(instance: ['id' => 'inst-2'], label: 'Hersteltermijn')['title']);

		$empty = $fields->subject(instance: [], label: 'X');
		$this->assertSame('', $empty['subjectUuid']);
		$this->assertSame('X ', $empty['title']);
	}//end testSubjectFallsBackToTheInstanceIdForTheTitle()

	/**
	 * The metadata carries the source, the kind and both ids as strings.
	 *
	 * @return void
	 */
	public function testMetadataCarriesSourceKindAndIds(): void {
		$fields = new TermTimerFields();

		$this->assertSame(
			[
				'source' => TermijnTimerService::METADATA_SOURCE,
				'kind' => 'pause',
				'termijnInstanceId' => '42',
				'caseId' => 'case-1',
			],
			$fields->metadata(instance: ['id' => 42, 'case' => 'case-1'], kind: 'pause')
		);

		$this->assertSame(
			['source' => TermijnTimerService::METADATA_SOURCE, 'kind' => 'due', 'termijnInstanceId' => '', 'caseId' => ''],
			$fields->metadata(instance: [], kind: 'due')
		);
	}//end testMetadataCarriesSourceKindAndIds()

	/**
	 * Without reminders a pause has exactly the expiry rule.
	 *
	 * @return void
	 */
	public function testPauseRulesWithoutRemindersHoldOnlyTheExpiry(): void {
		$rules = (new TermTimerFields())->pauseRules(offsets: []);

		$this->assertCount(1, $rules);
		$this->assertSame('slaBreached', $rules[0]['trigger']);
		$this->assertSame(0, $rules[0]['offset']);
		$this->assertSame(['handler'], $rules[0]['notifyRole']);
		$this->assertSame('high', $rules[0]['priority']);
		$this->assertSame('pauze-verlopen', $rules[0]['message']);
		$this->assertFalse($rules[0]['openIncident']);
	}//end testPauseRulesWithoutRemindersHoldOnlyTheExpiry()

	/**
	 * Each positive offset adds a preBreach reminder in order; zero and negative offsets are dropped.
	 *
	 * @return void
	 */
	public function testPauseRulesAddOneReminderPerPositiveOffset(): void {
		$rules = (new TermTimerFields())->pauseRules(offsets: [7, 0, -3, 2]);

		$this->assertCount(3, $rules);
		$this->assertSame('pauze-verlopen', $rules[0]['message']);

		$this->assertSame(
			[
				'trigger' => 'preBreach',
				'offset' => 7,
				'offsetUnit' => 'calendarDays',
				'notifyRole' => [],
				'escalateToRole' => [],
				'priority' => 'normal',
				'message' => 'pauze-chase',
				'openIncident' => false,
			],
			$rules[1]
		);
		$this->assertSame(2, $rules[2]['offset']);
		$this->assertSame('pauze-chase', $rules[2]['message']);
	}//end testPauseRulesAddOneReminderPerPositiveOffset()
}//end class
