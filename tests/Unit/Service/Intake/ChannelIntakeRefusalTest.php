<?php

/**
 * Every way a routed channel message opens no case, and what each leaves behind.
 *
 * A message routed at a case either opens one or is refused in writing; it
 * is never dropped silently and never opened twice. The listener tests drive
 * the path that opens a case; these drive the refusals: no duplicate check
 * possible, an already-handled message, an unknown case type, an
 * unconfigured register, a save that throws and a save that answers no id.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
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
 * @spec openspec/specs/intake-from-a-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Intake\ChannelIntake;
use OCA\Dossiq\Service\Intake\MessageFacts;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The refusal paths of ChannelIntake::caseFor().
 *
 * @covers \OCA\Dossiq\Service\Intake\ChannelIntake
 * @uses   \OCA\Dossiq\Service\CommunicationChannel
 *
 * @spec openspec/specs/intake-from-a-channel/spec.md
 */
class ChannelIntakeRefusalTest extends TestCase {

	private const MESSAGE = ['channelId' => 'mail-1', 'externalId' => 'msg-9', 'text' => 'Hallo'];

	/** @var IntakeLog&MockObject */
	private IntakeLog $log;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/**
	 * Set up the log and logger mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->log = $this->createMock(originalClassName: IntakeLog::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * An intake over settings with the given config and object service.
	 *
	 * @param array<string, string> $config        Config key → value.
	 * @param object|null           $objectService The object service.
	 *
	 * @return ChannelIntake The intake.
	 */
	private function intake(array $config = [], ?object $objectService = null): ChannelIntake {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$settings->method('getObjectService')->willReturn($objectService);

		$facts = $this->createMock(originalClassName: MessageFacts::class);
		$facts->method('titleFor')->willReturn('Onderwerp');
		$facts->method('correspondent')->willReturn('jan@example.org');
		$facts->method('receivedDate')->willReturn('2026-10-10');
		$facts->method('receivedAtField')->willReturn([]);

		return new ChannelIntake(settingsService: $settings, log: $this->log, logger: $this->logger, facts: $facts);
	}//end intake()

	/**
	 * An object service that finds case type `type-1` and answers saves with $saved.
	 *
	 * @param mixed $saved What saveObject() answers, or a Throwable to throw.
	 *
	 * @return object The fake.
	 */
	private static function objectService(mixed $saved): object {
		return new class ($saved) {
			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			/**
			 * @param mixed $answer The save answer.
			 */
			public function __construct(private mixed $answer) {
			}

			/**
			 * @param string     $id       The id.
			 * @param string|int $register The register.
			 * @param string|int $schema   The schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, string|int $register, string|int $schema): ?array {
				return $id === 'type-1' ? ['id' => 'type-1', 'initialStatus' => 'ontvangen'] : null;
			}

			/**
			 * @param array<string, mixed> $object   The case.
			 * @param string|int           $register The register.
			 * @param string|int           $schema   The schema.
			 *
			 * @return mixed
			 */
			public function saveObject(array $object, string|int $register, string|int $schema): mixed {
				$this->saved[] = $object;
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end objectService()

	/**
	 * The full configuration.
	 *
	 * @return array<string, string>
	 */
	private static function config(): array {
		return ['register' => 'r1', 'case_schema' => 'case', 'case_type_schema' => 'caseType'];
	}//end config()

	/**
	 * Expect exactly one refused log entry whose reason contains $reason.
	 *
	 * @param string $reason Part of the reason.
	 *
	 * @return void
	 */
	private function expectRefusal(string $reason): void {
		$this->log->expects($this->once())->method('recordChannelMessage')->with(
			'mail-1',
			'msg-9',
			'jan@example.org',
			'Onderwerp',
			IntakeLog::OUTCOME_REFUSED,
			$this->stringContains($reason)
		);
	}//end expectRefusal()

	/**
	 * A configured log with nothing seen yet.
	 *
	 * @return void
	 */
	private function freshLog(): void {
		$this->log->method('isConfigured')->willReturn(true);
		$this->log->method('findChannelEntry')->willReturn(null);
	}//end freshLog()

	/**
	 * An unprovisioned log refuses the message before anything is read.
	 *
	 * @return void
	 */
	public function testAnUnprovisionedLogRefusesWithoutRecording(): void {
		$this->log->method('isConfigured')->willReturn(false);
		$this->log->expects($this->never())->method('findChannelEntry');
		$this->log->expects($this->never())->method('recordChannelMessage');
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('intake log is not provisioned'),
			['channel' => 'mail-1', 'message' => 'msg-9']
		);

		$objects = self::objectService(saved: ['id' => 'case-1']);
		$this->assertSame(expected: '', actual: $this->intake(config: self::config(), objectService: $objects)->caseFor(self::MESSAGE, ['caseType' => 'type-1']));
		$this->assertSame(expected: [], actual: $objects->saved);
	}//end testAnUnprovisionedLogRefusesWithoutRecording()

	/**
	 * An already-handled message answers the case it opened, and opens none.
	 *
	 * @return void
	 */
	public function testASecondDeliveryAnswersTheFirstCase(): void {
		$this->log->method('isConfigured')->willReturn(true);
		$this->log->method('findChannelEntry')->with('mail-1', 'msg-9')->willReturn(['case' => ' case-7 ']);
		$this->log->expects($this->never())->method('recordChannelMessage');
		$objects = self::objectService(saved: ['id' => 'case-1']);

		$this->assertSame(expected: 'case-7', actual: $this->intake(config: self::config(), objectService: $objects)->caseFor(self::MESSAGE, ['caseType' => 'type-1']));
		$this->assertSame(expected: [], actual: $objects->saved);
	}//end testASecondDeliveryAnswersTheFirstCase()

	/**
	 * A case type this instance does not hold is refused, naming the rule.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseTypeIsRefusedNamingTheRule(): void {
		$this->freshLog();
		$this->expectRefusal(reason: 'The routing rule "Meldingen" names no case type this instance has.');

		$intake = $this->intake(config: self::config(), objectService: self::objectService(saved: ['id' => 'x']));

		$this->assertSame(expected: '', actual: $intake->caseFor(self::MESSAGE, ['caseType' => 'type-404'], 'Meldingen'));
	}//end testAnUnknownCaseTypeIsRefusedNamingTheRule()

	/**
	 * A rule mapping no case type at all is refused the same way.
	 *
	 * @return void
	 */
	public function testAMissingCaseTypeIsRefused(): void {
		$this->freshLog();
		$this->expectRefusal(reason: 'names no case type');

		$this->assertSame(expected: '', actual: $this->intake(config: self::config())->caseFor(self::MESSAGE, ['caseType' => '  ']));
	}//end testAMissingCaseTypeIsRefused()

	/**
	 * Without a case schema configured the case cannot be written.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredCaseSchemaRefuses(): void {
		$this->freshLog();
		$this->expectRefusal(reason: 'The case could not be written.');
		$this->logger->expects($this->atLeastOnce())->method('warning');
		$objects = self::objectService(saved: ['id' => 'case-1']);
		$config = self::config();
		unset($config['case_schema']);

		$this->assertSame(expected: '', actual: $this->intake(config: $config, objectService: $objects)->caseFor(self::MESSAGE, ['caseType' => 'type-1']));
		$this->assertSame(expected: [], actual: $objects->saved);
	}//end testAnUnconfiguredCaseSchemaRefuses()

	/**
	 * A save that throws is logged with its reason and refused.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsLoggedAndRefused(): void {
		$this->freshLog();
		$this->expectRefusal(reason: 'The case could not be written.');
		$this->logger->expects($this->once())->method('error')->with(
			'ChannelIntake: could not open a case for a routed message',
			['caseType' => 'type-1', 'error' => 'schema refused']
		);

		$intake = $this->intake(config: self::config(), objectService: self::objectService(saved: new RuntimeException('schema refused')));

		$this->assertSame(expected: '', actual: $intake->caseFor(self::MESSAGE, ['caseType' => 'type-1']));
	}//end testAFailingSaveIsLoggedAndRefused()

	/**
	 * A save that answers no id cannot be linked, so it is refused.
	 *
	 * @return void
	 */
	public function testASaveWithoutAnIdIsRefused(): void {
		$this->freshLog();
		$this->expectRefusal(reason: 'The case could not be written.');
		$this->logger->expects($this->once())->method('error')->with(
			'ChannelIntake: the case was written but answered no id, so the message cannot be linked to it',
			['caseType' => 'type-1']
		);

		$intake = $this->intake(config: self::config(), objectService: self::objectService(saved: 'not an object'));

		$this->assertSame(expected: '', actual: $intake->caseFor(self::MESSAGE, ['caseType' => 'type-1']));
	}//end testASaveWithoutAnIdIsRefused()

	/**
	 * A written case is recorded under its @self id with the type's initial status;
	 * blank payload fields are not written.
	 *
	 * @return void
	 */
	public function testAWrittenCaseIsRecordedUnderItsSelfId(): void {
		$this->freshLog();
		$this->log->expects($this->once())->method('recordChannelMessage')->with(
			'mail-1',
			'msg-9',
			'jan@example.org',
			'Onderwerp',
			IntakeLog::OUTCOME_CASE,
			'Opened by routing rule "Meldingen".',
			'case-self'
		);
		$objects = self::objectService(saved: ['@self' => ['id' => 'case-self'], 'id' => 'other']);

		$caseId = $this->intake(config: self::config(), objectService: $objects)->caseFor(
			self::MESSAGE,
			['caseType' => 'type-1', 'description' => '   ', 'initiatorName' => ['not', 'scalar']],
			'Meldingen'
		);

		$this->assertSame(expected: 'case-self', actual: $caseId);
		$this->assertSame(expected: 'ontvangen', actual: $objects->saved[0]['status']);
		$this->assertSame(expected: 'Hallo', actual: $objects->saved[0]['description']);
		$this->assertArrayNotHasKey(key: 'initiatorName', array: $objects->saved[0]);
		$this->assertSame(expected: 'jan@example.org', actual: $objects->saved[0]['initiatorSourceId']);
	}//end testAWrittenCaseIsRecordedUnderItsSelfId()

	/**
	 * A target schema is a case when it is `case` or the configured case schema.
	 *
	 * @return void
	 */
	public function testIsForACaseMatchesCaseOrTheConfiguredSchema(): void {
		$intake = $this->intake(config: ['case_schema' => 'Zaak']);

		$this->assertTrue(condition: $intake->isForACase(' CASE '));
		$this->assertTrue(condition: $intake->isForACase('zaak'));
		$this->assertFalse(condition: $intake->isForACase('contact'));
		$this->assertFalse(condition: $intake->isForACase('  '));
		$this->assertFalse(condition: $this->intake()->isForACase('zaak'), message: 'No configured schema matches only `case`.');
	}//end testIsForACaseMatchesCaseOrTheConfiguredSchema()
}//end class
