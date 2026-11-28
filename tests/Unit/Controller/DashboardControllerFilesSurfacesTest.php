<?php
/**
 * Every page carries Nextcloud's Viewer; the files browser's actions come from their own bare page.
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
use OCA\Dossiq\Controller\Support\SurfaceLoadEvents;
use OCP\AppFramework\Http\TemplateResponse;
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
     * Build the controller around a dispatcher that expects exactly these events.
     *
     * @param list<string> $expected The event classes that must be dispatched, once each.
     *
     * @return DashboardController The controller.
     */
    private function controllerExpecting(array $expected): DashboardController
    {
        $present = array_values(array: array_filter(array: $expected, callback: 'class_exists'));

        $dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
        $dispatcher->expects($this->exactly(count: count(value: $present)))
            ->method('dispatchTyped')
            ->with($this->callback(callback: static fn (Event $event): bool => in_array(needle: $event::class, haystack: $present, strict: true)));

        return new DashboardController(
            request: $this->createMock(originalClassName: IRequest::class),
            initialState: $this->createMock(originalClassName: IInitialState::class),
            appConfig: $this->createMock(originalClassName: IAppConfig::class),
            surfaces: new SurfaceLoadEvents(eventDispatcher: $dispatcher),
        );
    }//end controllerExpecting()


    /**
     * Every page dispatches the Viewer's load event, and not the Files app's.
     *
     * @return void
     */
    public function testThePageDispatchesOnlyTheViewersLoadEvent(): void
    {
        $this->controllerExpecting(expected: DashboardController::PAGE_SURFACE_EVENTS)->page();
    }//end testThePageDispatchesOnlyTheViewersLoadEvent()


    /**
     * The files surfaces page dispatches the Files app's additional-scripts event as a bare page.
     *
     * @return void
     */
    public function testTheFilesSurfacesPageDispatchesTheAdditionalScriptsAsABarePage(): void
    {
        $response = $this->controllerExpecting(expected: DashboardController::FILES_SURFACE_EVENTS)->filesSurfaces();

        $this->assertSame(expected: 'surfaces', actual: $response->getTemplateName());
        $this->assertSame(expected: TemplateResponse::RENDER_AS_BASE, actual: $response->getRenderAs());
    }//end testTheFilesSurfacesPageDispatchesTheAdditionalScriptsAsABarePage()


    /**
     * The events are named, so a typo cannot silently dispatch nothing.
     *
     * Named rather than imported, because neither the Viewer nor the Files app
     * is a dependency. The Files sidebar's event stays out on purpose: on
     * Nextcloud 34 the sidebar cannot be opened from another app's page.
     *
     * @return void
     */
    public function testTheEventsAreTheViewersOnThePageAndTheAdditionalScriptsOnTheFilesPage(): void
    {
        $this->assertSame(expected: ['OCA\\Viewer\\Event\\LoadViewer'], actual: DashboardController::PAGE_SURFACE_EVENTS);
        $this->assertSame(expected: ['OCA\\Files\\Event\\LoadAdditionalScriptsEvent'], actual: DashboardController::FILES_SURFACE_EVENTS);
    }//end testTheEventsAreTheViewersOnThePageAndTheAdditionalScriptsOnTheFilesPage()
}//end class
