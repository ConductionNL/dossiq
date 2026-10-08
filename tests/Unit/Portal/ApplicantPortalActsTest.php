<?php

/**
 * Unit tests for what dossiq does when a resident acts on their case in the portal.
 *
 * The recorder is what makes portaliq's two citizen facts reach the handler
 * (dossiq#3142): an internal timeline entry of the `reactie-indiener` kind,
 * which opens a follow-up so the case surfaces in the handler's queue, and a
 * bell notification to the case's assignee. A fact about a case that is not
 * dossiq's is left alone.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use JsonSerializable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Portal\ApplicantPortalActs;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A stand-in for OpenRegister's object service that knows a fixed set of cases.
 */
class FakePortalCaseStore {

	/**
	 * The cases by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $cases = [];

	/**
	 * The coordinates the last lookup was asked in.
	 *
	 * @var array<int, mixed>
	 */
	public array $askedIn = [];

	/**
	 * Find one object, the way OpenRegister answers: an entity or null.
	 *
	 * @param string $id       The object id.
	 * @param mixed  $register The register.
	 * @param mixed  $schema   The schema.
	 *
	 * @return object|null The entity, or null.
	 */
	public function find(string $id, mixed $register = null, mixed $schema = null): ?object {
		$this->askedIn = [$register, $schema];

		if (isset($this->cases[$id]) === false) {
			return null;
		}

		$data = $this->cases[$id];

		return new class($data) implements JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $data The object data.
			 */
			public function __construct(private readonly array $data) {
			}

			/**
			 * The object data.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}//end find()
}//end class

/**
 * Tests that a resident's act in the portal reaches the handler.
 *
 * @covers \OCA\Dossiq\Portal\ApplicantPortalActs
 */
class ApplicantPortalActsTest extends TestCase {

	/**
	 * @var FakePortalCaseStore
	 */
	private FakePortalCaseStore $store;

	/**
	 * @var CaseTimeline|MockObject
	 */
	private $timeline;

	/**
	 * @var INotificationManager|MockObject
	 */
	private $notifications;

	/**
	 * The notifications that were sent, as [user, subject, parameters, objectType, objectId].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $sent = [];

	/**
	 * What each notification double was given, by object id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $states = [];

	/**
	 * Set up the stand-ins.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new FakePortalCaseStore();
		$this->store->cases['case-1'] = ['id' => 'case-1', 'identifier' => 'Z-2026-0001', 'assignee' => 'behandelaar'];
		$this->store->cases['case-2'] = ['id' => 'case-2', 'identifier' => 'Z-2026-0002', 'assignee' => ''];

		$this->timeline = $this->getMockBuilder(CaseTimeline::class)
			->disableOriginalConstructor()
			->onlyMethods(['record'])
			->getMock();

		$this->sent = [];
		$this->notifications = $this->createMock(INotificationManager::class);
		$this->states = [];
		$this->notifications->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$this->notifications->method('notify')->willReturnCallback(function (INotification $sent): void {
			$this->sent[] = $this->states[spl_object_id($sent)];
		});
	}//end setUp()

	/**
	 * A notification double that records what the sender set on it.
	 *
	 * @return INotification
	 */
	private function notification(): INotification {
		$state = ['user' => '', 'subject' => '', 'parameters' => [], 'type' => '', 'id' => '', 'app' => ''];
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnCallback(function (string $app) use (&$state, &$notification) {
			$state['app'] = $app;
			return $notification;
		});
		$notification->method('setUser')->willReturnCallback(function (string $user) use (&$state, &$notification) {
			$state['user'] = $user;
			return $notification;
		});
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnCallback(function (string $type, string $id) use (&$state, &$notification) {
			$state['type'] = $type;
			$state['id'] = $id;
			return $notification;
		});
		$notification->method('setSubject')->willReturnCallback(function (string $subject, array $parameters = []) use (&$state, &$notification) {
			$state['subject'] = $subject;
			$state['parameters'] = $parameters;
			return $notification;
		});
		$notification->method('getUser')->willReturnCallback(function () use (&$state) {
			return $state['user'];
		});

		$this->states[spl_object_id($notification)] = &$state;

		return $notification;
	}//end notification()

	/**
	 * Build the recorder.
	 *
	 * @return ApplicantPortalActs
	 */
	private function acts(): ApplicantPortalActs {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => '7',
					'case_schema' => '12',
					default => '',
				};
			}
		);

		return new ApplicantPortalActs(
			$settings,
			$this->timeline,
			$this->notifications,
			$this->createMock(LoggerInterface::class)
		);
	}//end acts()

	/**
	 * A write writes an internal follow-up entry and tells the assignee.
	 *
	 * @return void
	 */
	public function testAWriteReachesTheTimelineAndTheAssignee(): void {
		$this->timeline->expects($this->once())
			->method('record')
			->with(
				'case-1',
				TimelineKinds::APPLICANT_RESPONSE,
				$this->stringContains('changed'),
				[
					'act' => 'amendment',
					'fields' => 'phone, email',
					'occurredAt' => '2026-09-27T10:00:00+02:00',
				],
				CaseTimeline::INTERNAL,
			)
			->willReturn('entry-1');

		$recorded = $this->acts()->recordWrite('case-1', 'amendment', ['phone', 'email'], '2026-09-27T10:00:00+02:00');

		$this->assertTrue($recorded);
		$this->assertSame(['7', '12'], $this->store->askedIn, 'the case is looked up in dossiq\'s own register and case schema');
		$this->assertCount(1, $this->sent);
		$this->assertSame('behandelaar', $this->sent[0]['user']);
		$this->assertSame(Application::APP_ID, $this->sent[0]['app']);
		$this->assertSame(Notifier::SUBJECT_APPLICANT_RESPONDED, $this->sent[0]['subject']);
		$this->assertSame(['case', 'case-1'], [$this->sent[0]['type'], $this->sent[0]['id']]);
		$this->assertSame('amendment', $this->sent[0]['parameters']['act']);
		$this->assertSame('Z-2026-0001', $this->sent[0]['parameters']['identifier']);
	}//end testAWriteReachesTheTimelineAndTheAssignee()

	/**
	 * A withdrawal writes its entry with the status and reason and tells the assignee.
	 *
	 * @return void
	 */
	public function testAWithdrawalReachesTheTimelineAndTheAssignee(): void {
		$this->timeline->expects($this->once())
			->method('record')
			->with(
				'case-1',
				TimelineKinds::APPLICANT_RESPONSE,
				$this->stringContains('withdrew'),
				[
					'act' => ApplicantPortalActs::ACT_WITHDRAWAL,
					'status' => 'status-withdrawn',
					'reason' => 'Niet meer nodig.',
					'occurredAt' => '2026-09-27T11:00:00+02:00',
				],
				CaseTimeline::INTERNAL,
			)
			->willReturn('entry-2');

		$recorded = $this->acts()->recordWithdrawal('case-1', 'status-withdrawn', 'Niet meer nodig.', '2026-09-27T11:00:00+02:00');

		$this->assertTrue($recorded);
		$this->assertCount(1, $this->sent);
		$this->assertSame(Notifier::SUBJECT_APPLICANT_WITHDREW, $this->sent[0]['subject']);
		$this->assertSame('Niet meer nodig.', $this->sent[0]['parameters']['reason']);
	}//end testAWithdrawalReachesTheTimelineAndTheAssignee()

	/**
	 * A fact about a case that is not dossiq's is left alone.
	 *
	 * @return void
	 */
	public function testACaseThatIsNotOursIsLeftAlone(): void {
		$this->timeline->expects($this->never())->method('record');

		$this->assertFalse($this->acts()->recordWrite('elsewhere', 'document', [], '2026-09-27T10:00:00+02:00'));
		$this->assertFalse($this->acts()->recordWithdrawal('elsewhere', 's', '', '2026-09-27T10:00:00+02:00'));
		$this->assertSame([], $this->sent);
	}//end testACaseThatIsNotOursIsLeftAlone()

	/**
	 * A case nobody is assigned to still gets its entry, and nobody is notified.
	 *
	 * The follow-up the entry opens is what surfaces it in the queue then.
	 *
	 * @return void
	 */
	public function testAnUnassignedCaseStillGetsTheEntry(): void {
		$this->timeline->expects($this->once())->method('record')->willReturn('entry-3');

		$this->assertTrue($this->acts()->recordWrite('case-2', 'document', [], '2026-09-27T10:00:00+02:00'));
		$this->assertSame([], $this->sent);
	}//end testAnUnassignedCaseStillGetsTheEntry()

	/**
	 * An act portaliq does not declare is refused rather than written.
	 *
	 * The kind declares the act as an enum, so an unknown one would be a 400
	 * on the write and a notification about nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownActIsRefused(): void {
		$this->timeline->expects($this->never())->method('record');

		$this->assertFalse($this->acts()->recordWrite('case-1', 'something-else', [], '2026-09-27T10:00:00+02:00'));
		$this->assertSame([], $this->sent);
	}//end testAnUnknownActIsRefused()

	/**
	 * The kind the recorder writes is declared, and opens a follow-up.
	 *
	 * @return void
	 */
	public function testTheKindIsDeclaredAndOpensAFollowUp(): void {
		$declared = [];
		foreach (TimelineKinds::DECLARATIONS as $declaration) {
			$declared[$declaration['slug']] = $declaration;
		}

		$this->assertArrayHasKey(TimelineKinds::APPLICANT_RESPONSE, $declared);
		$kind = $declared[TimelineKinds::APPLICANT_RESPONSE];
		$this->assertTrue($kind['followUp']);
		$this->assertSame(
			['amendment', 'document', 'task-answer', ApplicantPortalActs::ACT_WITHDRAWAL],
			$kind['properties']['act']['enum']
		);
		foreach (['fields', 'status', 'reason', 'occurredAt'] as $property) {
			$this->assertArrayHasKey($property, $kind['properties']);
		}
	}//end testTheKindIsDeclaredAndOpensAFollowUp()
}//end class
