<?php

/**
 * ApprovalStepNotificationListener Unit Tests
 *
 * Verifies that procest parafering notifications are driven by OpenRegister's
 * approval-workflow step events: an approval that advances a next step notifies
 * the next role group's members; a rejection notifies the voorstel steller. The
 * listener resolves the voorstel via ObjectService and extracts the
 * human-readable text from the metadata-in-comment payload.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Listener
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ApprovalChain;
use OCA\OpenRegister\Db\ApprovalStep;
use OCA\OpenRegister\Event\ApprovalStepApprovedEvent;
use OCA\OpenRegister\Event\ApprovalStepRejectedEvent;
use OCA\Procest\Listener\ApprovalStepNotificationListener;
use OCA\Procest\Service\ParaferingNotificationService;
use OCA\Procest\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ApprovalStepNotificationListener.
 *
 * @covers \OCA\Procest\Listener\ApprovalStepNotificationListener
 */
class ApprovalStepNotificationListenerTest extends TestCase {
	/**
	 * Mocked notification service.
	 *
	 * @var ParaferingNotificationService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private ParaferingNotificationService $notifications;

	/**
	 * Mocked settings/OpenRegister bridge.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settings;

	/**
	 * Mocked group manager.
	 *
	 * @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->notifications = $this->createMock(ParaferingNotificationService::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Ensure OR event stubs exist for the CI env (no OpenRegister present).
		// The listener matches events by class name string, so we define stubs
		// with the exact FQN that the listener's EVENT_APPROVED / EVENT_REJECTED
		// constants reference. In the deployed env these classes are provided by
		// OpenRegister itself and the isinstance/class_exists guards are no-ops.
		$approvedFqn = 'OCA\OpenRegister\Event\ApprovalStepApprovedEvent';
		if (class_exists($approvedFqn) === false) {
			// phpcs:disable
			eval(
				'namespace OCA\OpenRegister\Event;'
				. ' class ApprovalStepApprovedEvent extends \OCP\EventDispatcher\Event {'
				. '   public ?object $next = null;'
				. '   public string $objectUuid = "";'
				. '   public function setNext(?object $n): void { $this->next = $n; }'
				. '   public function getNextStep(): ?object { return $this->next; }'
				. '   public function setObjectUuid(string $u): void { $this->objectUuid = $u; }'
				. '   public function getObjectUuid(): string { return $this->objectUuid; }'
				. ' }'
			);
			// phpcs:enable
		}

		$rejectedFqn = 'OCA\OpenRegister\Event\ApprovalStepRejectedEvent';
		if (class_exists($rejectedFqn) === false) {
			// phpcs:disable
			eval(
				'namespace OCA\OpenRegister\Event;'
				. ' class ApprovalStepRejectedEvent extends \OCP\EventDispatcher\Event {'
				. '   public string $objectUuid = "";'
				. '   public string $userId = "";'
				. '   public ?object $step = null;'
				. '   public function setObjectUuid(string $u): void { $this->objectUuid = $u; }'
				. '   public function getObjectUuid(): string { return $this->objectUuid; }'
				. '   public function setUserId(string $u): void { $this->userId = $u; }'
				. '   public function getUserId(): string { return $this->userId; }'
				. '   public function setStep(?object $s): void { $this->step = $s; }'
				. '   public function getStep(): ?object { return $this->step; }'
				. ' }'
			);
			// phpcs:enable
		}
	}//end setUp()

	/**
	 * Stub the settings bridge to return a voorstel for any UUID lookup.
	 *
	 * @param array<string, mixed> $proposal The voorstel to return.
	 *
	 * @return void
	 */
	private function stubProposal(array $proposal): void {
		$objectService = new class($proposal) {
			/**
			 * @param array<string, mixed> $proposal Voorstel data.
			 */
			public function __construct(
				private array $proposal,
			) {
			}

			/**
			 * @param string $id Object id.
			 * @param mixed ...$kw Named args (register/schema).
			 *
			 * @return array<string, mixed> The voorstel.
			 */
			public function find(string $id, ...$kw): array {
				return $this->proposal;
			}
		};

		$this->settings->method('getObjectService')->willReturn($objectService);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return $key === 'register' ? 'reg' : 'voorstel';
			}
		);
	}//end stubVoorstel()

	/**
	 * An OpenRegister-named approved event with a next step notifies the next
	 * role group's members via notifyStepActivated.
	 *
	 * @return void
	 */
	public function testApprovedWithNextStepNotifiesNextRoleGroup(): void {
		// Exercises the real OR approval Db/Event classes (ApprovalStep,
		// ApprovalChain and the named-parameter event constructors), which only
		// OpenRegister provides. Skip when they are absent (unit CI without OR);
		// the deployed env with OpenRegister present runs this for real.
		if (class_exists(ApprovalStep::class) === false) {
			$this->markTestSkipped('OpenRegister approval classes not available in this environment.');
		}

		$this->stubProposal(['onderwerp' => 'Omgevingsvergunning', 'steller' => 'steller1']);

		$member = $this->createMock(IUser::class);
		$member->method('getUID')->willReturn('hoofd1');

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$member]);
		$this->groupManager->method('get')->with('afdelingshoofd')->willReturn($group);

		$this->notifications->expects($this->once())
			->method('notifyStepActivated')
			->with('hoofd1', 'Omgevingsvergunning', 'voorstel-abc', 'afdelingshoofd');

		$step = new ApprovalStep();
		$step->setObjectUuid('voorstel-abc');

		$nextStep = new ApprovalStep();
		$nextStep->setRole('afdelingshoofd');

		$event = new ApprovalStepApprovedEvent(
			chain: new ApprovalChain(),
			step: $step,
			userId: 'beoordelaar',
			statusOnApprove: 'approved',
			nextStep: $nextStep
		);

		$listener = new ApprovalStepNotificationListener(
			$this->notifications,
			$this->settings,
			$this->groupManager,
			$this->logger
		);

		$listener->handle($event);
	}//end testApprovedWithNextStepNotifiesNextRoleGroup()

	/**
	 * An unrelated event class is ignored (no notifications dispatched).
	 *
	 * @return void
	 */
	public function testUnrelatedEventIsIgnored(): void {
		$this->notifications->expects($this->never())->method('notifyStepActivated');
		$this->notifications->expects($this->never())->method('notifyVoorstelReturned');

		$listener = new ApprovalStepNotificationListener(
			$this->notifications,
			$this->settings,
			$this->groupManager,
			$this->logger
		);

		$listener->handle(new class extends Event {
		});
	}//end testUnrelatedEventIsIgnored()

	/**
	 * extractCommentText / role resolution are exercised through the public
	 * notify helpers by routing a real OR-named event via class_alias.
	 *
	 * @return void
	 */
	public function testRejectedNotifiesStellerWithDecodedComment(): void {
		// See testApprovedWithNextStepNotifiesNextRoleGroup — needs the real OR
		// approval Db/Event classes; skip when OpenRegister is absent.
		if (class_exists(ApprovalStep::class) === false) {
			$this->markTestSkipped('OpenRegister approval classes not available in this environment.');
		}

		$this->stubProposal(['onderwerp' => 'Subsidiebesluit', 'steller' => 'steller2']);

		$this->notifications->expects($this->once())
			->method('notifyVoorstelReturned')
			->with(
				'steller2',
				'Subsidiebesluit',
				'voorstel-xyz',
				'beoordelaar',
				'Financiele paragraaf ontbreekt'
			);

		$step = new ApprovalStep();
		$step->setObjectUuid('voorstel-xyz');
		$step->setComment(
			json_encode(['text' => 'Financiele paragraaf ontbreekt', '_meta' => ['action' => 'returned']])
		);

		$event = new ApprovalStepRejectedEvent(
			chain: new ApprovalChain(),
			step: $step,
			userId: 'beoordelaar',
			statusOnReject: 'rejected'
		);

		$listener = new ApprovalStepNotificationListener(
			$this->notifications,
			$this->settings,
			$this->groupManager,
			$this->logger
		);

		$listener->handle($event);
	}//end testRejectedNotifiesStellerWithDecodedComment()
}//end class
