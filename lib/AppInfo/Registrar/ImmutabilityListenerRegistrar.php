<?php

/**
 * Procest immutability listener registrar.
 *
 * The pre-persist immutability guards: REQ-SUB-007 (a bewijsstuk linked to a
 * vaststelling is frozen) and REQ-IC-8 (a submitted inspectionChecklistRun is
 * append-only). They live in their own registrar because
 * `ObjectListenerRegistrar` is explicitly the home of the listeners that are
 * NOT scoped to a single subsystem, and because keeping them here holds that
 * class's object coupling inside the phpmd threshold.
 *
 * Both listeners subscribe to OpenRegister's PRE-persist, stoppable events.
 * The post-persist pair cannot be used: OpenRegister dispatches
 * `ObjectUpdatedEvent`/`ObjectDeletedEvent` after the row has already been
 * written, with no surrounding transaction, so a listener there cannot stop
 * the mutation it objects to.
 *
 * @category AppInfo
 * @package  OCA\Procest\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/subsidieverlening-keten/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\AppInfo\Registrar;

use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Procest\Listener\BewijsstukImmutabilityListener;
use OCA\Procest\Listener\ChecklistRunImmutabilityListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the pre-persist immutability guards.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/subsidieverlening-keten/spec.md
 */
class ImmutabilityListenerRegistrar
{
    /**
     * Register the immutability listeners.
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     *
     * @spec openspec/specs/subsidieverlening-keten/spec.md
     */
    public function register(IRegistrationContext $context): void
    {
        // REQ-SUB-007: a bewijsstuk linked to a vaststelling is immutable.
        // This is the production call site for
        // BewijsstukService::assertMutable(), which previously had none.
        $context->registerEventListener(
            event: ObjectUpdatingEvent::class,
            listener: BewijsstukImmutabilityListener::class
        );
        $context->registerEventListener(
            event: ObjectDeletingEvent::class,
            listener: BewijsstukImmutabilityListener::class
        );

        // REQ-IC-8: a submitted inspectionChecklistRun is append-only. The
        // listener existed but was never referenced by any registrar, so the
        // rule was not enforced by anything.
        $context->registerEventListener(
            event: ObjectUpdatingEvent::class,
            listener: ChecklistRunImmutabilityListener::class
        );
    }//end register()
}//end class
