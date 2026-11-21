<?php

/**
 * Test stub for OCA\OpenRegister\Exception\TaskConflictException.
 *
 * OpenRegister throws it when a lifecycle verb meets a terminal task; dossiq
 * answers it with a 409. Mirrors the real parent class. Self-skips when the
 * real class is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

if (class_exists('\\OCA\\OpenRegister\\Exception\\TaskConflictException', false) === false) {
    /**
     * Thrown when a task verb conflicts with the task's state.
     */
    class TaskConflictException extends RuntimeException {
    }
}
