<?php

/**
 * SentimentAnalysisJob work characterisation.
 *
 * Pins what one run of the sentiment job reads, skips, writes and logs:
 * nothing without OpenRegister or its configuration, one sentiment record per
 * unscored contactmoment with a transcript, an activity per related case only
 * when escalation is recommended, and a per-contact failure that is logged
 * without stopping the run.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\SentimentAnalysisJob;
use OCA\Dossiq\Service\ContactMomentService;
use OCA\Dossiq\Service\SentimentService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One run of the sentiment job.
 *
 * @covers \OCA\Dossiq\BackgroundJob\SentimentAnalysisJob
 * @covers \OCA\Dossiq\BackgroundJob\SentimentRun
 * @covers \OCA\Dossiq\BackgroundJob\OpenRegisterObjects
 * @uses \OCA\Dossiq\Service\SentimentService
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 * @uses \OCA\Dossiq\Service\Support\CastArray
 */
class SentimentAnalysisJobWorkTest extends TestCase {
	use MakesBackgroundServiceAccount;

	private const CONTACT = 'contact';

	private const SENTIMENT = 'sentiment';

	/**
	 * The register.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * Configuration values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Installed apps.
	 *
	 * @var array<int, string>
	 */
	private array $apps = ['openregister', 'dossiq'];

	/**
	 * Whether OpenRegister's object service is there.
	 *
	 * @var bool
	 */
	private bool $hasObjectService = true;

	/**
	 * The contact moment service.
	 *
	 * @var ContactMomentService&MockObject
	 */
	private ContactMomentService&MockObject $contactMoments;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Seed contact moments on both sides of every skip.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->register = $this->refusingRegister();
		$this->register->seed(schema: self::CONTACT, id: 'cm-angry', row: ['transcript' => ' Ik ben woedend en bel mijn advocaat. ', 'relatedCases' => ['case-1', 2]]);
		$this->register->seed(schema: self::CONTACT, id: 'cm-calm', row: ['transcript' => 'Dank u wel, alles is duidelijk.', 'relatedCases' => ['case-3']]);
		$this->register->seed(schema: self::CONTACT, id: 'cm-silent', row: ['transcript' => '   ', 'relatedCases' => ['case-4']]);
		$this->register->seed(schema: self::CONTACT, id: 'cm-scored', row: ['transcript' => 'Schandalig, mijn advocaat belt u.']);
		$this->register->seed(schema: self::SENTIMENT, id: 's-old', row: ['interactionId' => 'cm-scored']);
		$this->config = ['register' => 'reg', 'contactmoment_schema' => self::CONTACT, 'klant_sentiment_schema' => self::SENTIMENT];
		$this->contactMoments = $this->createMock(ContactMomentService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Run the job once as the service account.
	 *
	 * @return void
	 */
	private function runJob(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturnCallback(fn () => ($this->hasObjectService === true ? $this->register : null));
		$settings->method('getConfigValue')->willReturnCallback(fn (string $key, string $default = ''): string => ($this->config[$key] ?? $default));
		$settings->method('getKccConfigValue')->willReturn('["advocaat", 7]');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturnCallback(fn (): array => $this->apps);

		$job = $this->buildWith(
			SentimentAnalysisJob::class,
			[
				'time' => $this->createMock(ITimeFactory::class),
				'settingsService' => $settings,
				'sentimentService' => new SentimentService(),
				'contactMomentService' => $this->contactMoments,
				'appManager' => $apps,
				'logger' => $this->logger,
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
		$this->runJobOnce(job: $job);
	}//end runJob()

	/**
	 * The new sentiment records, by interaction id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function newSentiments(): array {
		$found = [];
		foreach (($this->register->rows[self::SENTIMENT] ?? []) as $id => $row) {
			if ($id !== 's-old') {
				$found[$row['interactionId']] = $row;
			}
		}

		return $found;
	}//end newSentiments()

	/**
	 * Unscored contacts with a transcript are scored; only an escalation reaches the cases.
	 *
	 * @return void
	 */
	public function testUnscoredContactsAreScoredAndEscalationsReachTheCases(): void {
		$activities = [];
		$this->contactMoments->expects($this->exactly(2))->method('recordActivity')->willReturnCallback(
			function (...$arguments) use (&$activities): bool {
				$activities[] = $arguments;
				return true;
			}
		);
		$this->logger->expects($this->never())->method('error');

		$this->runJob();

		$sentiments = $this->newSentiments();
		$this->assertSame(['cm-angry', 'cm-calm'], array_keys($sentiments));
		$angry = (new SentimentService())->analyzeSentiment('Ik ben woedend en bel mijn advocaat.', ['advocaat', '7']);
		$this->assertSame(
			[
				'interactionId' => 'cm-angry',
				'sentimentScore' => $angry['score'],
				'sentimentLabel' => $angry['label'],
				'triggerWoorden' => $angry['triggers'],
				'transcriptSnippet' => $angry['snippet'],
				'escalationRecommended' => true,
				'escalationLevel' => $angry['escalationLevel'],
			],
			array_diff_key($sentiments['cm-angry'], ['createdAt' => true, 'id' => true, 'uuid' => true])
		);
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $sentiments['cm-angry']['createdAt']);
		$this->assertFalse($sentiments['cm-calm']['escalationRecommended']);

		$reason = 'Sentiment ' . $angry['label'] . ' (escalatie: ' . $angry['escalationLevel'] . ')';
		$this->assertSame(
			[
				['case-1', 'cm-angry', 'sentiment_detected', 'systeem', $reason],
				['2', 'cm-angry', 'sentiment_detected', 'systeem', $reason],
			],
			$activities
		);
	}//end testUnscoredContactsAreScoredAndEscalationsReachTheCases()

	/**
	 * A failing contact is logged and the run goes on with the next one.
	 *
	 * @return void
	 */
	public function testAFailingContactIsLoggedAndTheRunGoesOn(): void {
		$this->contactMoments->method('recordActivity')->willThrowException(new RuntimeException('case gone'));
		$this->logger->expects($this->once())->method('error')->with(
			'Dossiq: sentiment scoring failed for a contactmoment: case gone',
			['app' => 'dossiq']
		);

		$this->runJob();

		$this->assertSame(['cm-angry', 'cm-calm'], array_keys($this->newSentiments()));
	}//end testAFailingContactIsLoggedAndTheRunGoesOn()

	/**
	 * Without OpenRegister, its object service or the configuration nothing is read or written.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRunDoesNothing(): void {
		$this->contactMoments->expects($this->never())->method('recordActivity');
		$this->logger->expects($this->never())->method('error');

		$this->apps = ['dossiq'];
		$this->runJob();
		$this->apps = ['openregister'];
		$this->hasObjectService = false;
		$this->runJob();
		$this->hasObjectService = true;
		foreach (['register', 'contactmoment_schema', 'klant_sentiment_schema'] as $key) {
			$config = $this->config;
			$this->config[$key] = '';
			$this->runJob();
			$this->config = $config;
		}

		$this->assertSame([], $this->register->writes);
	}//end testAnUnconfiguredRunDoesNothing()

	/**
	 * A contactmoment read that fails is logged and nothing is written.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLogged(): void {
		$this->register = new class (fn (): ?string => $this->actingUid()) extends AnonymousRefusingRegister {
			/**
			 * Fail every search.
			 *
			 * @param string               $registerSlug  The register.
			 * @param string               $schemaSlug    The schema.
			 * @param array<string, mixed> $filters       The filters.
			 * @param bool                 $_rbac         RBAC.
			 * @param bool                 $_multitenancy Multitenancy.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				throw new RuntimeException('index down');
			}
		};
		$this->logger->expects($this->once())->method('error')->with(
			'Dossiq: sentiment job could not load contactmomenten: index down',
			['app' => 'dossiq']
		);

		$this->runJob();

		$this->assertSame([], $this->register->writes);
	}//end testAFailingReadIsLogged()
}//end class
