<?php
/**
 * The case page carries Nextcloud's own Viewer.
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Controller
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @version   1.0.0
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DashboardController;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The files tab on a case opens a file in the Viewer. The Viewer is a script
 * its app adds only when the page dispatches the load event, so the page has
 * to dispatch it, and has to survive an instance where the app is absent.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
class DashboardControllerFilesSurfacesTest extends TestCase
{

    /**
     * Rendering the page dispatches the load event of every surface whose app is installed.
     *
     * @return void
     */
    public function testRenderingThePageDispatchesEveryLoadEventThatExists(): void
    {
        $present = array_values(array: array_filter(array: DashboardController::FILES_SURFACE_EVENTS, callback: 'class_exists'));

        $dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
        $dispatcher->expects($this->exactly(count: count(value: $present)))
            ->method('dispatchTyped')
            ->with($this->callback(callback: static fn (Event $event): bool => in_array(needle: $event::class, haystack: $present, strict: true)));

        $controller = new DashboardController(
            request: $this->createMock(originalClassName: IRequest::class),
            initialState: $this->createMock(originalClassName: IInitialState::class),
            appConfig: $this->createMock(originalClassName: IAppConfig::class),
            eventDispatcher: $dispatcher,
        );

        $controller->page();
    }//end testRenderingThePageDispatchesEveryLoadEventThatExists()


    /**
     * The events are the Viewer's and the Files app's additional scripts, by
     * name, and the Files sidebar's is not.
     *
     * Named rather than imported, because the Viewer app is not a dependency;
     * a typo in the name would silently dispatch nothing, so the name is
     * pinned. The sidebar's event stays out on purpose: on Nextcloud 34 the
     * sidebar cannot be opened from another app's page.
     *
     * @return void
     */
    public function testTheEventsAreTheViewersAndTheAdditionalScriptsAndNotTheSidebars(): void
    {
        $this->assertSame(
            expected: ['OCA\\Viewer\\Event\\LoadViewer', 'OCA\\Files\\Event\\LoadAdditionalScriptsEvent'],
            actual: DashboardController::FILES_SURFACE_EVENTS
        );
    }//end testTheEventsAreTheViewersAndTheAdditionalScriptsAndNotTheSidebars()
}//end class
