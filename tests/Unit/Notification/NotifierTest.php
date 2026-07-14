<?php

/**
 * Procest Notifier Unit Tests
 *
 * Tests for the Procest INotifier implementation that renders the
 * `note_mention` notification (nc-vue #207 @mention → real NC
 * notification) for the bell menu.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/ncvue-w2-leaves-adoption/specs/ncvue-w2-leaves-adoption/spec.md#mentions
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Notification;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Procest Notifier class.
 *
 * @covers \OCA\Procest\Notification\Notifier
 */
class NotifierTest extends TestCase
{

    /**
     * @var IFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private IFactory $l10nFactory;

    /**
     * @var IURLGenerator|\PHPUnit\Framework\MockObject\MockObject
     */
    private IURLGenerator $urlGenerator;

    /**
     * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
     */
    private IL10N $l10n;

    /**
     * The notifier under test.
     *
     * @var Notifier
     */
    private Notifier $notifier;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->l10nFactory  = $this->createMock(IFactory::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->l10n         = $this->createMock(IL10N::class);

        $this->l10nFactory->method('get')->willReturn($this->l10n);
        // Echo the source text back (with sprintf-style substitution) so
        // assertions can check on plain literal English text.
        $this->l10n->method('t')->willReturnCallback(
            static function (string $text, array $params = []): string {
                return $params === [] ? $text : vsprintf(str_replace('%s', '%s', $text), $params);
            }
        );

        $this->urlGenerator->method('imagePath')->willReturn('/img/app-dark.svg');
        $this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
            static fn (string $path): string => 'https://cloud.example.com'.$path
        );

        $this->notifier = new Notifier($this->l10nFactory, $this->urlGenerator);
    }//end setUp()


    /**
     * getID returns the app id.
     *
     * @return void
     */
    public function testGetIdReturnsAppId(): void
    {
        $this->assertSame(Application::APP_ID, $this->notifier->getID());
    }//end testGetIdReturnsAppId()


    /**
     * A notification from a different app is rejected.
     *
     * @return void
     */
    public function testPrepareRejectsForeignApp(): void
    {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('some_other_app');

        $this->expectException(UnknownNotificationException::class);
        $this->notifier->prepare($notification, 'en');
    }//end testPrepareRejectsForeignApp()


    /**
     * An unknown subject key is rejected.
     *
     * @return void
     */
    public function testPrepareRejectsUnknownSubject(): void
    {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn(Application::APP_ID);
        $notification->method('getSubject')->willReturn('some_unknown_subject');

        $this->expectException(UnknownNotificationException::class);
        $this->notifier->prepare($notification, 'en');
    }//end testPrepareRejectsUnknownSubject()


    /**
     * A note_mention notification is parsed with the actor's display name
     * in the subject, an absolute icon URL, and a non-empty message.
     *
     * @return void
     */
    public function testPrepareRendersNoteMentionWithActorName(): void
    {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn(Application::APP_ID);
        $notification->method('getSubject')->willReturn('note_mention');
        $notification->method('getSubjectParameters')->willReturn(
            [
                'actorUserId'      => 'alice',
                'actorDisplayName' => 'Alice',
                'register'         => 'procest',
                'schema'           => 'case',
                'objectId'         => 'case-1',
                'noteId'           => 'note-9',
            ]
        );

        $notification->expects($this->once())
            ->method('setParsedSubject')
            ->with('Alice mentioned you in a note')
            ->willReturn($notification);

        $notification->expects($this->once())
            ->method('setParsedMessage')
            ->with($this->isType('string'))
            ->willReturn($notification);

        // setIcon MUST receive an absolute URL (project convention — a
        // relative imagePath() silently renders no icon in the bell menu).
        $notification->expects($this->once())
            ->method('setIcon')
            ->with($this->stringStartsWith('https://'))
            ->willReturn($notification);

        $result = $this->notifier->prepare($notification, 'en');
        $this->assertSame($notification, $result);
    }//end testPrepareRendersNoteMentionWithActorName()


    /**
     * When the actor display name is missing, the subject falls back to
     * the generic wording (never renders an empty "%s mentioned…").
     *
     * @return void
     */
    public function testPrepareFallsBackWhenActorNameMissing(): void
    {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn(Application::APP_ID);
        $notification->method('getSubject')->willReturn('note_mention');
        $notification->method('getSubjectParameters')->willReturn(
            [
                'actorUserId' => 'alice',
                'objectId'    => 'case-1',
            ]
        );

        $notification->expects($this->once())
            ->method('setParsedSubject')
            ->with('You were mentioned in a note')
            ->willReturn($notification);
        $notification->method('setParsedMessage')->willReturn($notification);
        $notification->method('setIcon')->willReturn($notification);

        $this->notifier->prepare($notification, 'en');
    }//end testPrepareFallsBackWhenActorNameMissing()
}//end class
