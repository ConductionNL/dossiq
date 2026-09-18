<?php

/**
 * IntakeMessageRoutedListener and ChannelIntake unit tests.
 *
 * integriq routes a channel message at a case and reads the result slot back.
 * These tests drive the three things that decide whether that is safe: a
 * refusal a person can read, a second delivery that opens no second case, and
 * a write that does not take its identity or its assignee from the message.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\IntakeMessageRoutedListener;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Intake\ChannelIntake;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Integriq\Event\IntakeMessageRoutedEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The ObjectService surface these tests drive.
 *
 * Declared as an interface and doubled with `createMock`, never
 * `addMethods`: a double that adds a method the real class lacks can only
 * pass. Every signature here is the one `SearchesObjects` calls with named
 * arguments.
 */
interface ChannelIntakeObjectServiceStub {
	/**
	 * Find a single object by id.
	 *
	 * @param string $id       The object id.
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @return mixed The object.
	 */
	public function find(string $id, string $register, string $schema): mixed;

	/**
	 * Save or update an object.
	 *
	 * @param array       $object   The payload.
	 * @param string      $register The register slug.
	 * @param string      $schema   The schema slug.
	 * @param string|null $uuid     The object to update, or null to create.
	 *
	 * @return mixed The stored object.
	 */
	public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): mixed;

	/**
	 * Search objects by register and schema slug.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 * @param array  $filters  The filters.
	 *
	 * @return mixed The rows.
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters): mixed;

	/**
	 * Run a callable as the system principal.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed Whatever the operation returns.
	 */
	public function runAsSystem(callable $operation): mixed;
}//end interface

/**
 * Unit tests for the channel intake seam.
 *
 * @covers \OCA\Dossiq\Listener\IntakeMessageRoutedListener
 * @covers \OCA\Dossiq\Service\Intake\ChannelIntake
 */
class IntakeMessageRoutedListenerTest extends TestCase {

	/**
	 * Objects the fake object service was asked to write, schema to payloads.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $written = [];

	/**
	 * Intake log entries the fake holds, as stored rows.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $logRows = [];

	/**
	 * Whether the caller is currently inside the system elevation.
	 *
	 * @var boolean
	 */
	private bool $elevated = false;

	/**
	 * Whether a case type resolves.
	 *
	 * @var boolean
	 */
	private bool $caseTypeExists = true;

	/**
	 * Reset the fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->written = [];
		$this->logRows = [];
		$this->elevated = false;
		$this->caseTypeExists = true;
	}//end setUp()

	/**
	 * A fake object service that refuses an unelevated write.
	 *
	 * 🔴 THIS IS THE LEAST PRIVILEGED PRINCIPAL PROBE. A webhook arrives with
	 * no Nextcloud session, and an anonymous create is fail-closed
	 * (OpenRegister #1955). So `saveObject` here throws exactly the way the
	 * platform does when the caller is nobody, and only the elevation makes it
	 * succeed. A test that doubled an administrator would pass whether or not
	 * the elevation was ever reached.
	 *
	 * @return ChannelIntakeObjectServiceStub The double.
	 */
	private function objectService(): ChannelIntakeObjectServiceStub {
		$service = $this->createMock(originalClassName: ChannelIntakeObjectServiceStub::class);

		$service->method('runAsSystem')->willReturnCallback(
			function (callable $operation) {
				$this->elevated = true;
				try {
					return $operation();
				} finally {
					$this->elevated = false;
				}
			}
		);

		$service->method('saveObject')->willReturnCallback(
			function (array $object, string $register, string $schema, ?string $uuid = null): array {
				if ($schema === 'case' && $this->elevated === false) {
					throw new \RuntimeException('Anonymous callers may not create objects.');
				}

				$this->written[$schema][] = $object;
				$stored = ($object + ['@self' => ['id' => $schema . '-' . count($this->written[$schema])]]);
				if ($schema === 'mailIntakeEntry') {
					$this->logRows[] = $stored;
				}

				return $stored;
			}
		);

		$service->method('find')->willReturnCallback(
			function (string $id, string $register, string $schema): array {
				if ($schema === 'caseType' && $this->caseTypeExists === true) {
					return ['id' => $id, 'initialStatus' => 'status-ontvangen'];
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('no such object');
			}
		);

		$service->method('searchObjectsBySlug')->willReturnCallback(
			function (string $register, string $schema, array $filters): array {
				$matches = [];
				foreach ($this->logRows as $row) {
					$isMatch = true;
					foreach ($filters as $key => $value) {
						if (str_starts_with($key, '_') === true || $key === '@self') {
							continue;
						}

						if (($row[$key] ?? null) !== $value) {
							$isMatch = false;
							break;
						}
					}

					if ($isMatch === true) {
						$matches[] = $row;
					}
				}

				return $matches;
			}
		);

		return $service;
	}//end objectService()

	/**
	 * The intake under test, wired to the fakes.
	 *
	 * @param ChannelIntakeObjectServiceStub|null $service The object service, or null for the default.
	 *
	 * @return ChannelIntake The intake.
	 */
	private function intake(?ChannelIntakeObjectServiceStub $service = null): ChannelIntake {
		$service = ($service ?? $this->objectService());
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($service);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_type_schema' => 'caseType',
					IntakeLog::SCHEMA_KEY => 'mailIntakeEntry',
					default => $default,
				};
			}
		);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-09-18T09:00:00+00:00'));

		$timeline = $this->createMock(originalClassName: CaseTimeline::class);
		// The channel path writes no timeline entry, deliberately: the only
		// declared inbound kind is `mail-inkomend` and a Teams post is not an
		// e-mail. A call here would be that lie.
		$timeline->expects($this->never())->method('record');

		$log = new IntakeLog(
			settingsService: $settings,
			time: $time,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			timeline: $timeline,
		);

		$dates = $this->createMock(originalClassName: CaseDateNormaliser::class);
		$dates->method('toCalendarDateOrNull')->willReturnCallback(
			static function (mixed $value): ?string {
				$text = trim((string)(is_scalar($value) === true ? $value : ''));
				if ($text === '') {
					return null;
				}

				$stamp = strtotime($text);

				return ($stamp === false) ? null : date('Y-m-d', $stamp);
			}
		);
		$dates->method('today')->willReturn(new \DateTimeImmutable('2026-09-18 00:00:00'));

		return new ChannelIntake(
			settingsService: $settings,
			log: $log,
			dates: $dates,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end intake()

	/**
	 * One routed message.
	 *
	 * @param array<string, mixed> $overrides Message fields to override.
	 * @param array<string, mixed> $payload   The rule's mapped payload.
	 * @param string               $uuid      The integriq message uuid.
	 *
	 * @return IntakeMessageRoutedEvent The event.
	 */
	private function event(array $overrides = [], array $payload = [], string $uuid = 'im-1'): IntakeMessageRoutedEvent {
		$message = ($overrides + [
			'channelId' => 'teams',
			'externalId' => 'teams-msg-9',
			'correspondent' => ['handle' => 'jan@example.org'],
			'text' => "Lantaarnpaal kapot\nOp de hoek bij de school.",
			'receivedAt' => '2026-09-18T08:30:00+00:00',
		]);

		return new IntakeMessageRoutedEvent(
			message: $message,
			targetSchema: 'case',
			targetPayload: ($payload + ['caseType' => 'ct-mor']),
			files: [],
			messageUuid: $uuid,
			ruleName: 'Meldingen via Teams',
		);
	}//end event()

	/**
	 * The listener, wired to an intake.
	 *
	 * @param ChannelIntake $intake The intake.
	 *
	 * @return IntakeMessageRoutedListener The listener.
	 */
	private function listener(ChannelIntake $intake): IntakeMessageRoutedListener {
		return new IntakeMessageRoutedListener(
			intake: $intake,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end listener()

	/**
	 * A routed message opens a case and answers the slot.
	 *
	 * @return void
	 */
	public function testARoutedMessageOpensACaseAndAnswersTheSlot(): void {
		$event = $this->event();
		$this->listener($this->intake())->handle($event);

		$this->assertCount(1, ($this->written['case'] ?? []));
		$case = $this->written['case'][0];
		$this->assertSame('ct-mor', $case['caseType']);
		$this->assertSame('teams', $case['intakeChannel']);
		$this->assertSame('2026-09-18', $case['startDate']);
		$this->assertSame('Lantaarnpaal kapot', $case['title']);
		$this->assertSame('case-1', $event->getCreatedRef());
	}//end testARoutedMessageOpensACaseAndAnswersTheSlot()

	/**
	 * The status comes from the case type, and the case is not left without one.
	 *
	 * @return void
	 */
	public function testTheStatusComesFromTheCaseType(): void {
		$this->listener($this->intake())->handle($this->event());

		$this->assertSame('status-ontvangen', ($this->written['case'][0]['status'] ?? ''));
	}//end testTheStatusComesFromTheCaseType()

	/**
	 * The message may not name who holds the case, nor where it is.
	 *
	 * The least privileged principal probe on the data side: everything that
	 * decides access or lifecycle is dossiq's, because the write runs
	 * elevated and these values arrived on a webhook.
	 *
	 * @return void
	 */
	public function testAMessageCannotNameTheAssigneeTheStatusOrTheGrants(): void {
		$this->listener($this->intake())->handle(
			$this->event(
				payload: [
					'assignee' => 'admin',
					'status' => 'status-afgehandeld',
					'grants' => ['admin' => 'manage'],
					'register' => 'other',
				]
			)
		);

		$case = ($this->written['case'][0] ?? []);
		$this->assertArrayNotHasKey('assignee', $case);
		$this->assertArrayNotHasKey('grants', $case);
		$this->assertArrayNotHasKey('register', $case);
		$this->assertSame('status-ontvangen', ($case['status'] ?? ''));
	}//end testAMessageCannotNameTheAssigneeTheStatusOrTheGrants()

	/**
	 * The correspondent is written where code can read it, and nothing else.
	 *
	 * @return void
	 */
	public function testTheCorrespondentIsWrittenOntoTheCase(): void {
		$this->listener($this->intake())->handle($this->event());

		$case = ($this->written['case'][0] ?? []);
		$this->assertSame('jan@example.org', ($case['initiatorSourceId'] ?? ''));
		$this->assertSame('contact', ($case['initiatorType'] ?? ''));
	}//end testTheCorrespondentIsWrittenOntoTheCase()

	/**
	 * A rule that mapped the initiator itself is not overwritten.
	 *
	 * @return void
	 */
	public function testAMappedInitiatorWins(): void {
		$this->listener($this->intake())->handle(
			$this->event(payload: ['initiatorSourceId' => 'bsn-123'])
		);

		$this->assertSame('bsn-123', ($this->written['case'][0]['initiatorSourceId'] ?? ''));
	}//end testAMappedInitiatorWins()

	/**
	 * A second delivery of the same message opens no second case.
	 *
	 * What makes it the same message is the channel plus the channel's own id
	 * for it. integriq mints a fresh `intake_message` uuid per delivery, so
	 * the second event carries a different one, and a check on the uuid would
	 * open a second case here.
	 *
	 * @return void
	 */
	public function testASecondDeliveryOpensNoSecondCase(): void {
		$intake = $this->intake();
		$first = $this->event(uuid: 'im-1');
		$second = $this->event(uuid: 'im-2-fresh-row');

		$listener = $this->listener($intake);
		$listener->handle($first);
		$listener->handle($second);

		$this->assertCount(1, ($this->written['case'] ?? []));
		$this->assertSame($first->getCreatedRef(), $second->getCreatedRef());
	}//end testASecondDeliveryOpensNoSecondCase()

	/**
	 * A refusal is written where the intake worker reads it.
	 *
	 * @return void
	 */
	public function testARefusalIsRecordedOnTheIntakeLogWithItsReason(): void {
		$this->caseTypeExists = false;

		$event = $this->event();
		$this->listener($this->intake())->handle($event);

		$this->assertSame([], ($this->written['case'] ?? []));
		$this->assertNull($event->getCreatedRef());

		$entry = ($this->logRows[0] ?? []);
		$this->assertSame(IntakeLog::OUTCOME_REFUSED, ($entry['outcome'] ?? ''));
		$this->assertSame('teams', ($entry['channel'] ?? ''));
		$this->assertSame('teams-msg-9', ($entry['channelMessageId'] ?? ''));
		$this->assertStringContainsString('Meldingen via Teams', ($entry['reason'] ?? ''));
	}//end testARefusalIsRecordedOnTheIntakeLogWithItsReason()

	/**
	 * An opened case is recorded too, so the log holds every message.
	 *
	 * @return void
	 */
	public function testAnOpenedCaseIsRecordedOnTheIntakeLog(): void {
		$this->listener($this->intake())->handle($this->event());

		$entry = ($this->logRows[0] ?? []);
		$this->assertSame(IntakeLog::OUTCOME_CASE, ($entry['outcome'] ?? ''));
		$this->assertSame('case-1', ($entry['case'] ?? ''));
		$this->assertSame('jan@example.org', ($entry['sender'] ?? ''));
	}//end testAnOpenedCaseIsRecordedOnTheIntakeLog()

	/**
	 * A message with no id of its own is refused rather than opened.
	 *
	 * @return void
	 */
	public function testAMessageWithNoIdOfItsOwnIsRefused(): void {
		$event = $this->event(overrides: ['externalId' => '']);
		$this->listener($this->intake())->handle($event);

		$this->assertSame([], ($this->written['case'] ?? []));
		$this->assertNull($event->getCreatedRef());
	}//end testAMessageWithNoIdOfItsOwnIsRefused()

	/**
	 * A rule naming another app's target is left alone.
	 *
	 * @return void
	 */
	public function testAMessageForAnotherAppIsNotAnswered(): void {
		$event = new IntakeMessageRoutedEvent(
			message: ['channelId' => 'teams', 'externalId' => 'x-1'],
			targetSchema: 'invoice',
			targetPayload: ['caseType' => 'ct-mor'],
			files: [],
			messageUuid: 'im-9',
			ruleName: 'Facturen',
		);

		$this->listener($this->intake())->handle($event);

		$this->assertSame([], ($this->written['case'] ?? []));
		$this->assertSame([], $this->logRows);
		$this->assertNull($event->getCreatedRef());
	}//end testAMessageForAnotherAppIsNotAnswered()

	/**
	 * A listener that cannot do its work holds the message instead of throwing.
	 *
	 * An exception escaping here stops integriq's dispatch mid-batch, and the
	 * rest of the batch is other people's messages.
	 *
	 * @return void
	 */
	public function testTheListenerNeverThrows(): void {
		$service = $this->createMock(originalClassName: ChannelIntakeObjectServiceStub::class);
		$service->method('runAsSystem')->willThrowException(new \RuntimeException('the register is on fire'));
		$service->method('saveObject')->willThrowException(new \RuntimeException('the register is on fire'));
		$service->method('searchObjectsBySlug')->willReturn([]);

		$event = $this->event();
		$this->listener($this->intake(service: $service))->handle($event);

		$this->assertNull($event->getCreatedRef());
	}//end testTheListenerNeverThrows()
}//end class
