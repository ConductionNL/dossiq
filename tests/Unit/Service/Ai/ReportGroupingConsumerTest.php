<?php

/**
 * Unit tests for ReportGroupingConsumer: a new case is placed in hermiq's
 * grouping once, its group is read without placing it again, and the
 * confirmations of receipt owed are counted from the reports.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Ai
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-identical-reports-collapse-on-the-case-and-dossiq-decides-what-a-group-means-req-aic-04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Ai;

use OCA\Dossiq\Service\Ai\CaseTypeAiFeatures;
use OCA\Dossiq\Service\Ai\ReportGroupingConsumer;
use OCA\Dossiq\Service\Assistant\HermiqAiFeatureClient;
use OCA\Dossiq\Service\Assistant\HermiqAssistantException;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Ai\ReportGroupingConsumer
 *
 * @uses \OCA\Dossiq\Service\Ai\CaseTypeAiFeatures
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 * @uses \OCA\Dossiq\Service\Assistant\HermiqAssistantException
 */
class ReportGroupingConsumerTest extends TestCase {

	/**
	 * The register double, reading as a signed-in handler.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * The hermiq client double.
	 *
	 * @var HermiqAiFeatureClient&MockObject
	 */
	private HermiqAiFeatureClient&MockObject $client;

	/**
	 * Seed a case type that groups reports, one that does not, and a case of each.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new AnonymousRefusingRegister(actor: static fn (): string => 'handler');
		$this->client = $this->createMock(HermiqAiFeatureClient::class);

		$this->register->seed(
			schema: 'caseType',
			id: 'ct-grouping',
			row: ['title' => 'Melding openbare ruimte', 'aiFeatures' => [ReportGroupingConsumer::FEATURE_SLUG => 'case']]
		);
		$this->register->seed(
			schema: 'caseType',
			id: 'ct-off',
			row: ['title' => 'Melding openbare ruimte', 'aiFeatures' => [ReportGroupingConsumer::FEATURE_SLUG => 'none']]
		);
		$this->register->seed(
			schema: 'case',
			id: 'case-1',
			row: ['caseType' => 'ct-grouping', 'title' => 'Stroomstoring', 'description' => 'Geen stroom in de Kerkstraat']
		);
		$this->register->seed(
			schema: 'case',
			id: 'case-off',
			row: ['caseType' => 'ct-off', 'title' => 'Stroomstoring', 'description' => 'Geen stroom']
		);
	}//end setUp()

	/**
	 * Build the consumer over the register double.
	 *
	 * @return ReportGroupingConsumer The consumer.
	 */
	private function consumer(): ReportGroupingConsumer {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				default => $default,
			}
		);

		return new ReportGroupingConsumer(
			aiFeatures: new CaseTypeAiFeatures(),
			client: $this->client,
			caseTypes: new CaseTypeStore(settingsService: $settings),
			settingsService: $settings,
			logger: new NullLogger(),
		);
	}//end consumer()

	/**
	 * A new case is placed once, with its text, and the group id is kept on it.
	 *
	 * @return void
	 */
	public function testANewCaseIsPlacedAndKeepsItsGroup(): void {
		$this->client->expects($this->once())
			->method('groupFor')
			->with('case-1', 'ct-grouping', "Stroomstoring\nGeen stroom in de Kerkstraat")
			->willReturn(['groupId' => 'g-7', 'count' => 200, 'nearDuplicates' => [], 'newGroup' => false]);

		$answer = $this->consumer()->placeCase(caseId: 'case-1');

		self::assertSame(expected: 200, actual: $answer['group']['count']);
		self::assertSame(expected: 'g-7', actual: $this->register->row(schema: 'case', id: 'case-1')['reportGroupId']);
	}//end testANewCaseIsPlacedAndKeepsItsGroup()

	/**
	 * A case already placed is never sent again: a second evaluation would count
	 * the same resident twice.
	 *
	 * @return void
	 */
	public function testAPlacedCaseIsNotPlacedTwice(): void {
		$this->register->seed(
			schema: 'case',
			id: 'case-1',
			row: ['caseType' => 'ct-grouping', 'title' => 'Stroomstoring', 'reportGroupId' => 'g-7']
		);
		$this->client->expects($this->never())->method('groupFor');

		$answer = $this->consumer()->placeCase(caseId: 'case-1');

		self::assertTrue(condition: $answer['alreadyPlaced']);
		self::assertSame(expected: [], actual: $this->register->writes);
	}//end testAPlacedCaseIsNotPlacedTwice()

	/**
	 * A case type that switched the feature off sends nothing and writes nothing.
	 *
	 * @return void
	 */
	public function testACaseTypeThatDoesNotGroupSendsNothing(): void {
		$this->client->expects($this->never())->method('groupFor');
		$this->client->expects($this->never())->method('group');

		$consumer = $this->consumer();

		self::assertFalse(condition: $consumer->placeCase(caseId: 'case-off')['declared']);
		self::assertFalse(condition: $consumer->currentGroup(caseId: 'case-off')['declared']);
		self::assertSame(expected: [], actual: $this->register->writes);
	}//end testACaseTypeThatDoesNotGroupSendsNothing()

	/**
	 * A refusal from hermiq leaves the case alone and carries hermiq's gate.
	 *
	 * @return void
	 */
	public function testARefusalIsCarriedWithItsGateAndWritesNothing(): void {
		$this->client->method('groupFor')->willThrowException(
			new HermiqAssistantException(message: 'Residency policy refuses this provider', statusCode: 403, errorCode: 'residency')
		);

		$answer = $this->consumer()->placeCase(caseId: 'case-1');

		self::assertFalse(condition: $answer['available']);
		self::assertSame(expected: 'residency', actual: $answer['gate']);
		self::assertSame(expected: [], actual: $this->register->writes);
	}//end testARefusalIsCarriedWithItsGateAndWritesNothing()

	/**
	 * Opening the case reads the group with its near-duplicates beside it, and
	 * never places the case again.
	 *
	 * @return void
	 */
	public function testTheGroupIsReadWithItsNearDuplicatesWithoutPlacingAgain(): void {
		$this->register->seed(
			schema: 'case',
			id: 'case-1',
			row: ['caseType' => 'ct-grouping', 'title' => 'Stroomstoring', 'reportGroupId' => 'g-7']
		);
		$this->client->expects($this->never())->method('groupFor');
		$this->client->expects($this->once())->method('group')->with('g-7')->willReturn(
			[
				'groupId' => 'g-7',
				'count' => 200,
				'members' => [],
				'nearDuplicates' => [['reportId' => 'case-9', 'score' => 0.61, 'decidedBy' => 'model', 'uncertain' => true]],
				'terms' => ['stroom', 'kerkstraat'],
				'windowMinutes' => 120,
			]
		);

		$answer = $this->consumer()->currentGroup(caseId: 'case-1');

		self::assertTrue(condition: $answer['available']);
		self::assertSame(expected: 200, actual: $answer['group']['count']);
		self::assertSame(expected: 'case-9', actual: $answer['group']['nearDuplicates'][0]['reportId']);
		self::assertSame(expected: ['stroom', 'kerkstraat'], actual: $answer['group']['terms']);
	}//end testTheGroupIsReadWithItsNearDuplicatesWithoutPlacingAgain()

	/**
	 * A case nobody grouped yet is said to be ungrouped, not shown as a group of one.
	 *
	 * @return void
	 */
	public function testAnUngroupedCaseIsSaidToBeUngrouped(): void {
		$this->client->expects($this->never())->method('group');

		$answer = $this->consumer()->currentGroup(caseId: 'case-1');

		self::assertTrue(condition: $answer['declared']);
		self::assertFalse(condition: $answer['available']);
		self::assertNull(actual: $answer['group']);
	}//end testAnUngroupedCaseIsSaidToBeUngrouped()

	/**
	 * A case the caller cannot read answers null, like one that does not exist.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseAnswersNull(): void {
		$this->client->expects($this->never())->method('groupFor');

		self::assertNull(actual: $this->consumer()->placeCase(caseId: 'case-missing'));
		self::assertNull(actual: $this->consumer()->currentGroup(caseId: 'case-missing'));
	}//end testAnUnreadableCaseAnswersNull()

	/**
	 * Two hundred reports in one group still owe two hundred confirmations.
	 *
	 * @return void
	 */
	public function testGroupingDoesNotReduceTheConfirmationsOwed(): void {
		$reports = [];
		for ($i = 1; $i <= 200; $i++) {
			$reports[] = ['id' => 'case-'.$i, 'reportGroupId' => 'g-7'];
		}

		// The same report listed twice is still one confirmation.
		$reports[] = 'case-1';

		self::assertSame(expected: 200, actual: $this->consumer()->confirmationsOwed(reports: $reports));
	}//end testGroupingDoesNotReduceTheConfirmationsOwed()

	/**
	 * dossiq holds no similarity scoring of its own: the consumer asks, and
	 * nothing in the source tree scores text beside it.
	 *
	 * @return void
	 */
	public function testDossiqHoldsNoSimilarityScoringOfItsOwn(): void {
		$root = dirname(__DIR__, 4).'/lib';
		$hits = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			if (preg_match('/\b(similar_text|levenshtein|jaccard|cosineSimilarity)\s*\(/i', $source) === 1) {
				$hits[] = substr($file->getPathname(), strlen($root) + 1);
			}
		}

		self::assertSame(expected: [], actual: $hits);
	}//end testDossiqHoldsNoSimilarityScoringOfItsOwn()
}//end class
