<?php

/**
 * SentimentAnalysisJob writes as the background service account.
 *
 * Cron has no user, so OpenRegister refused the klantSentiment create as
 * Anonymous and no case ever got its escalation activity. These tests drive
 * the REAL job, SentimentService and ContactMomentService into a register that
 * refuses a write from nobody. The case write is a patch: a full save of
 * `['activity' => ...]` alone would replace the case and wipe every other
 * field, so the test seeds one and checks it survives.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\SentimentAnalysisJob;
use OCA\Dossiq\Service\ContactMomentService;
use OCA\Dossiq\Service\SentimentService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The sentiment and case writes run as the configured account, or not at all.
 *
 * @covers \OCA\Dossiq\BackgroundJob\SentimentAnalysisJob
 * @covers \OCA\Dossiq\Service\ContactMomentService
 * @uses \OCA\Dossiq\Service\SentimentService
 */
class SentimentAnalysisJobServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;
	use MakesCaseDateNormaliser;

	/**
	 * The contactmoment schema id.
	 */
	private const CONTACT = '11';

	/**
	 * The klantSentiment schema id.
	 */
	private const SENTIMENT = '12';

	/**
	 * The case schema id.
	 */
	private const CASE = '9';

	/**
	 * The register the job writes to.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * Seed one angry contactmoment on one case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = $this->refusingRegister();
		$this->register->seed(
			schema: self::CONTACT,
			id: 'cm-1',
			row: [
				'transcript' => 'Ik ben woedend, dit is schandalig en ik bel mijn advocaat.',
				'relatedCases' => ['case-1'],
			]
		);
		$this->register->seed(
			schema: self::CASE,
			id: 'case-1',
			row: [
				'title' => 'Bouwvergunning Dorpsstraat 1',
				'activity' => [],
			]
		);
	}//end setUp()

	/**
	 * One run stores the sentiment and the case activity, as the service account.
	 *
	 * @return void
	 */
	public function testTheRunWritesAsTheServiceAccount(): void {
		$this->runJobOnce(job: $this->job());

		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: [self::SENTIMENT, self::CASE]);

		$sentiments = array_values($this->register->rows[self::SENTIMENT] ?? []);
		$this->assertCount(1, $sentiments);
		$this->assertSame('cm-1', $sentiments[0]['interactionId']);
		$this->assertTrue($sentiments[0]['escalationRecommended']);

		$case = $this->register->row(schema: self::CASE, id: 'case-1');
		$this->assertSame('Bouwvergunning Dorpsstraat 1', $case['title'], 'The activity write replaced the case.');
		$this->assertCount(1, $case['activity']);
		$this->assertSame('sentiment_detected', $case['activity'][0]['type']);
	}//end testTheRunWritesAsTheServiceAccount()

	/**
	 * Without an account nothing is attempted and the admins are told.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheRunWritesNothing(): void {
		$this->configuredAccount = '';

		$this->runJobOnce(job: $this->job());

		$this->assertSame([], $this->register->writes);
		$this->assertSame([], $this->register->refusals);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertSame([], $this->register->rows[self::SENTIMENT] ?? []);
		$this->assertSame([], $this->register->row(schema: self::CASE, id: 'case-1')['activity']);
		$this->assertNull($this->acting);
	}//end testWithoutAnAccountTheRunWritesNothing()

	/**
	 * The real job over the real services.
	 *
	 * @return SentimentAnalysisJob The job.
	 */
	private function job(): SentimentAnalysisJob {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => '5',
				'contactmoment_schema' => self::CONTACT,
				'klant_sentiment_schema' => self::SENTIMENT,
				'case_schema' => self::CASE,
				default => $default,
			}
		);
		$settings->method('getKccConfigValue')->willReturn('["advocaat"]');

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister', 'dossiq']);

		$contactMoments = new ContactMomentService(
			settingsService: $settings,
			userSession: $this->createMock(IUserSession::class),
			logger: new NullLogger(),
			dates: $this->caseDates(),
		);

		return $this->buildWith(
			SentimentAnalysisJob::class,
			[
				'time' => $this->createMock(ITimeFactory::class),
				'settingsService' => $settings,
				'sentimentService' => new SentimentService(),
				'contactMomentService' => $contactMoments,
				'appManager' => $apps,
				'logger' => new NullLogger(),
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end job()
}//end class
