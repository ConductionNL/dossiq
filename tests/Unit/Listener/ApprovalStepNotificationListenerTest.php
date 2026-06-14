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

        $nextStep = new class {
            /**
             * @return string Next step role.
             */
            public function getRole(): string
            {
                return 'afdelingshoofd';
            }
        };

        $fqn = 'OCA\OpenRegister\Event\ApprovalStepApprovedEvent';
        if (class_exists($fqn) === false) {
            eval(
                'namespace OCA\OpenRegister\Event; class ApprovalStepApprovedEvent extends \\'.Event::class.' {'
                .' public ?object $next=null; public function setNext($n){$this->next=$n;}'
                .' public function getNextStep(){return $this->next;}'
                .' public function getObjectUuid(){return "voorstel-abc";} }'
            );
        }

        $event = new $fqn();
        $event->setNext($nextStep);

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

        $step = new class {
            /**
             * @return string JSON metadata-in-comment payload.
             */
            public function getComment(): string
            {
                return json_encode(
                    ['text' => 'Financiele paragraaf ontbreekt', '_meta' => ['action' => 'returned']]
                );
            }
        };

        // Define a class that IS named like the OR rejected event so the
        // listener's FQN guard routes it to handleRejected.
        $fqn = 'OCA\OpenRegister\Event\ApprovalStepRejectedEvent';
        if (class_exists($fqn) === false) {
            eval(
                'namespace OCA\OpenRegister\Event; class ApprovalStepRejectedEvent extends \\'.Event::class.' {'
                .' public object $step; public function setStep($s){$this->step=$s;}'
                .' public function getStep(){return $this->step;}'
                .' public function getUserId(){return "beoordelaar";}'
                .' public function getObjectUuid(){return "voorstel-xyz";} }'
            );
        }

        $event = new $fqn();
        $event->setStep($step);

        $listener = new ApprovalStepNotificationListener(
            $this->notifications,
            $this->settings,
            $this->groupManager,
            $this->logger
        );

        $listener->handle($event);
    }//end testRejectedNotifiesStellerWithDecodedComment()
}//end class
