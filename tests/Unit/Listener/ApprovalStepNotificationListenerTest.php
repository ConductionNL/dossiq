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
class ApprovalStepNotificationListenerTest extends TestCase
{
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
    protected function setUp(): void
    {
        $this->notifications = $this->createMock(ParaferingNotificationService::class);
        $this->settings      = $this->createMock(SettingsService::class);
        $this->groupManager  = $this->createMock(IGroupManager::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * Stub the settings bridge to return a voorstel for any UUID lookup.
     *
     * @param array<string, mixed> $voorstel The voorstel to return.
     *
     * @return void
     */
    private function stubVoorstel(array $voorstel): void
    {
        $objectService = new class($voorstel) {
            /**
             * @param array<string, mixed> $voorstel Voorstel data.
             */
            public function __construct(private array $voorstel)
            {
            }

            /**
             * @param string $id   Object id.
             * @param mixed  ...$kw Named args (register/schema).
             *
             * @return array<string, mixed> The voorstel.
             */
            public function find(string $id, ...$kw): array
            {
                return $this->voorstel;
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
    public function testApprovedWithNextStepNotifiesNextRoleGroup(): void
    {
        $this->stubVoorstel(['onderwerp' => 'Omgevingsvergunning', 'steller' => 'steller1']);

        $member = $this->createMock(IUser::class);
        $member->method('getUID')->willReturn('hoofd1');

        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn([$member]);
        $this->groupManager->method('get')->with('afdelingshoofd')->willReturn($group);

        $this->notifications->expects($this->once())
            ->method('notifyStepActivated')
            ->with('hoofd1', 'Omgevingsvergunning', 'voorstel-abc', 'afdelingshoofd');

        $nextStep = new ApprovalStep();
        $nextStep->setRole('afdelingshoofd');

        $currentStep = new ApprovalStep();
        $currentStep->setObjectUuid('voorstel-abc');

        $event = new ApprovalStepApprovedEvent(
            new ApprovalChain(),
            $currentStep,
            'approver1',
            'goedgekeurd',
            $nextStep,
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
    public function testUnrelatedEventIsIgnored(): void
    {
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
    public function testRejectedNotifiesStellerWithDecodedComment(): void
    {
        $this->stubVoorstel(['onderwerp' => 'Subsidiebesluit', 'steller' => 'steller2']);

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
            (string) json_encode(
                ['text' => 'Financiele paragraaf ontbreekt', '_meta' => ['action' => 'returned']]
            )
        );

        $event = new ApprovalStepRejectedEvent(
            new ApprovalChain(),
            $step,
            'beoordelaar',
            'teruggestuurd',
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
