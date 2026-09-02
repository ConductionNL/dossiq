<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Flow
 * @package   OCA\Dossiq\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Flow;

use DateTime;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use OCP\WorkflowEngine\IManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Ask a person something, and wait for their answer.
 *
 * WHY THIS IS ONE NODE AND NOT TWO. The obvious composition — `createTask`
 * followed by `await-signal` — cannot work, and the reason is worth stating
 * because it looks like it should. A run holds one awaiting slot PER NODE, so
 * whatever resumes it must name the node, not just the run. `createTask` runs
 * BEFORE the await node and therefore cannot know which node the task will end
 * up blocking. The task would be created with no way back to the question it
 * answers, and the flow would look correct while nothing could ever wake it.
 *
 * So the node that suspends is the node that creates the task, and it stamps
 * the task with its own run and its own id. That is the whole design.
 *
 * 🔴 THE HEARTBEAT MUST NOT CREATE A SECOND TASK. This node suspends with a
 * resume time as a safety net against a lost signal, which means it is
 * re-entered on a timer with no answer present. Creating the task
 * unconditionally would leave one task per heartbeat sitting in somebody's
 * list — every one of them able to resume the run, and all but one of them
 * noise. Creation is therefore guarded on the resume slot, exactly as
 * AwaitSignalNode guards its own request record.
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One over the threshold, and
 *     every dependency is load-bearing: the node speaks OpenRegister's whole
 *     suspend/resume vocabulary (suspension, resume slot, run context, signal
 *     key, value template) AND dossiq's own storage seam. Splitting a class to
 *     shed an import would separate the ask from the wait it exists to pair.
 */
class DossiqAskPersonNode implements IFlowNode {

    /**
     * Minutes between heartbeats when the config names none.
     *
     * @var integer
     */
    private const DEFAULT_HEARTBEAT_MINUTES = 30;

    /**
     * The shortest heartbeat this node will honour.
     *
     * A lower one is not faster: a completed task wakes the run immediately
     * through the signal, and the heartbeat only exists for the case where
     * that signal was lost.
     *
     * @var integer
     */
    private const MIN_HEARTBEAT_MINUTES = 5;


    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Resolves the object service and configured schemas.
     * @param IL10N           $l10n            The localisation service.
     * @param LoggerInterface $logger          The logger.
     *
     * @return void
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * This node's catalogue id.
     *
     * @return string The namespaced node id.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function getId(): string {
        return 'dossiq.askPerson';

    }//end getId()


    /**
     * The node's display name.
     *
     * @return string The translated name.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function getDisplayName(): string {
        return $this->l10n->t('Ask a person');

    }//end getDisplayName()


    /**
     * What the node does.
     *
     * @return string The translated description.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function getDescription(): string {
        return $this->l10n->t('Create a task for somebody and pause the case until they complete it.');

    }//end getDescription()


    /**
     * The node's icon.
     *
     * @return string The icon name.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function getIcon(): string {
        return 'account-question';

    }//end getIcon()


    /**
     * Where this node may be offered.
     *
     * @param integer $scope The Nextcloud workflow scope.
     *
     * @return boolean True when available in this scope.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function isAvailableForScope(int $scope): bool {
        return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);

    }//end isAvailableForScope()


    /**
     * Refuse a step that asks nothing of nobody.
     *
     * @param array $config The step configuration.
     *
     * @return void
     *
     * @throws UnexpectedValueException When the question or the assignee is missing.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function validateConfig(array $config): void {
        if (trim((string) ($config['question'] ?? '')) === '') {
            throw new UnexpectedValueException(
                $this->l10n->t('Say what is being asked, or nobody can answer it.')
            );
        }

        // An unassigned question is not a question. OpenRegister's resume guard
        // deliberately lets ANYONE answer a step that names no assignee, so an
        // empty assignee here would not merely be untidy — it would open the
        // case's progress to any authenticated user.
        if (trim((string) ($config['assignee'] ?? '')) === '') {
            throw new UnexpectedValueException(
                $this->l10n->t('Say who is being asked. A task nobody is assigned can be completed by anyone.')
            );
        }

    }//end validateConfig()


    /**
     * Create the task on the first pass; pass the answer on when it arrives.
     *
     * @param array $items   The input items.
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata.
     *
     * @return array The items, each carrying the answer.
     *
     * @throws FlowSuspension While the task is outstanding.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function execute(array $items, array $config, array $context): array {
        $this->validateConfig(config: $config);

        $signal = $this->answerFrom(context: $context);

        if ($signal === null) {
            $this->ensureTask(items: $items, config: $config, context: $context);

            throw new FlowSuspension(
                resumeAt: $this->heartbeatAt(config: $config),
                reason: sprintf(
                    'waiting for a person: %s',
                    trim((string) ($config['question'] ?? 'a task'))
                )
            );
        }

        $key = trim((string) ($config['signalKey'] ?? ''));
        if ($key === '') {
            $key = 'answer';
        }

        // Onto every item, not into the run: the steps after this route per
        // item, and a switch cannot branch on something only the run holds.
        $out = [];
        foreach ($items as $item) {
            if (is_array($item) === true) {
                $json       = (array) ($item['json'] ?? []);
                $json[$key] = $signal;
                $item['json'] = $json;
            }

            $out[] = $item;
        }

        return $out;

    }//end execute()


    /**
     * Create the task, once, and remember that it was created.
     *
     * @param array $items   The input items; the first carries the case.
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata.
     *
     * @return void
     */
    private function ensureTask(array $items, array $config, array $context): void {
        $resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
        if (($resume instanceof FlowNodeResumeState) === false) {
            // Without a slot there is nowhere to record that the task exists,
            // so a heartbeat would create another one every time it woke.
            // Refusing is the safe direction: a step that cannot be made
            // idempotent must not run at all.
            throw new RuntimeException('dossiq.askPerson requires a node resume slot');
        }

        if ($resume->has(key: 'taskId') === true) {
            return;
        }

        $caseId   = $this->caseIdFrom(items: $items);
        $assignee = $this->renderedAssignee(config: $config, items: $items);
        $taskId   = $this->persistTask(
            task: $this->buildTask(caseId: $caseId, assignee: $assignee, config: $config, context: $context, resume: $resume)
        );

        $resume->merge(
            values: [
                'taskId'   => $taskId,
                'askedAt'  => (new DateTime())->format('c'),
                'question' => trim((string) ($config['question'] ?? '')),
                // Read back by OpenRegister's resume guard, which refuses a
                // signal from anyone but this person or their group. The
                // RENDERED name, never the raw template: the guard compares
                // this against real uids and group ids, so a stored literal
                // "{{ case.assignee }}" would refuse every real user.
                'assignee' => $assignee,
            ]
        );

        $this->logger->info(
            'Dossiq askPerson: created task ' . $taskId . ' and suspended the run',
            ['case' => $caseId, 'node' => $resume->nodeId()]
        );

    }//end ensureTask()


    /**
     * The case the items carry.
     *
     * @param array $items The input items; the first carries the case.
     *
     * @return string The case id.
     *
     * @throws RuntimeException When there is no case to attach a task to.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    private function caseIdFrom(array $items): string {
        $case  = [];
        $first = ($items[0] ?? null);
        if (is_array($first) === true) {
            $case = (array) ($first['json'] ?? []);
        }

        $caseId = (string) ($case['id'] ?? ($case['uuid'] ?? ''));
        if ($caseId === '') {
            throw new RuntimeException('dossiq.askPerson had no case to attach a task to');
        }

        return $caseId;

    }//end caseIdFrom()


    /**
     * The assignee this ask resolves to, rendered against the case.
     *
     * A declared flow cannot name a real person: the uid differs per case, so
     * the shipped declaration says `{{ case.assignee }}` and somebody must
     * render it. The engine does not — it templates only inside its own
     * set-fields and object-read nodes — so a node that stamps authored values
     * onto storage renders them itself, exactly as OpenRegister's own
     * value-bearing nodes do through FlowValueTemplate. Storing the literal is
     * what orphaned every applicant task live: FlowRunAssignee compared real
     * uids against the un-rendered placeholder and refused all of them.
     *
     * 🔴 AN EMPTY RENDERING REFUSES LOUDLY. A template that resolves to
     * nothing would create an UNASSIGNED task, and OpenRegister's resume guard
     * deliberately lets anyone answer a step that names no assignee — so a
     * quiet fallback here would open the case's progress to any authenticated
     * user. Failing the step is the safe direction.
     *
     * The case is offered under both its own keys and a `case.` prefix,
     * because the declarations write `{{ case.assignee }}` — the same spelling
     * dossiq's template nodes already use — while the item's json IS the case.
     *
     * @param array $config The step configuration.
     * @param array $items  The input items; the first carries the case.
     *
     * @return string The rendered assignee.
     *
     * @throws RuntimeException When the assignee renders empty or unresolved.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) FlowValueTemplate is the engine's
     *     canonical rendering API and is published as a static, final class —
     *     there is no instance to inject.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    private function renderedAssignee(array $config, array $items): string {
        $raw = trim((string) ($config['assignee'] ?? ''));

        $case  = [];
        $first = ($items[0] ?? null);
        if (is_array($first) === true) {
            $case = (array) ($first['json'] ?? []);
        }

        $rendered = FlowValueTemplate::renderTracked(value: $raw, json: array_merge($case, ['case' => $case]));

        $value = $rendered['value'];
        if (is_array($value) === true || trim((string) $value) === '' || $rendered['unresolved'] !== []) {
            $detail = '';
            if ($rendered['unresolved'] !== []) {
                $detail = ' (unresolved: ' . implode(', ', $rendered['unresolved']) . ')';
            }

            throw new RuntimeException(
                sprintf('dossiq.askPerson could not resolve the assignee "%s" against the case%s', $raw, $detail)
            );
        }

        return trim((string) $value);

    }//end renderedAssignee()


    /**
     * The task record this step asks somebody to complete.
     *
     * @param string              $caseId   The case the task belongs to.
     * @param string              $assignee The RENDERED assignee, never the raw template.
     * @param array               $config   The step configuration.
     * @param array               $context  Run-level metadata.
     * @param FlowNodeResumeState $resume   This node's resume slot, which knows its id.
     *
     * @return array The task to persist.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    private function buildTask(string $caseId, string $assignee, array $config, array $context, FlowNodeResumeState $resume): array {
        $task = [
            'title'       => trim((string) ($config['question'] ?? '')),
            'description' => trim((string) ($config['details'] ?? '')),
            'case'        => $caseId,
            'status'      => 'available',
            'assignee'    => $assignee,
            // The two fields that make this task an answer to a specific
            // question rather than a loose to-do. Both are required to resume:
            // the run alone cannot say which of its awaiting nodes this is for.
            'flowRun'     => (string) ($context[FlowRunContext::CONTEXT_RUN] ?? ''),
            'flowNode'    => $resume->nodeId(),
        ];

        $due = trim((string) ($config['dueInDays'] ?? ''));
        if ($due !== '' && ctype_digit($due) === true) {
            $task['dueDate'] = (new DateTime())->modify('+' . $due . ' days')->format('c');
        }

        return $task;

    }//end buildTask()


    /**
     * Write the task and return the id the run must remember.
     *
     * The write runs under the flow run's `runAs` identity because the
     * engine's RegistryStepDispatcher executes every contributed node inside
     * `ObjectService::runAs()` (openregister#3332) — the seam that fixed the
     * 'Anonymous' refusal which stopped the seeded case flow live.
     *
     * @param array $task The task to persist.
     *
     * @return string The created task's id.
     *
     * @throws RuntimeException When storage is unavailable, unconfigured, or the
     *                          written task cannot be identified.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    private function persistTask(array $task): string {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('storage_unavailable');
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $taskSchema = $this->settingsService->getConfigValue(key: 'task_schema');
        if ($register === '' || $taskSchema === '') {
            throw new RuntimeException('task_schema_not_configured');
        }

        $created = $objectService->saveObject(object: $task, register: $register, schema: $taskSchema);

        $taskId = $this->createdTaskId(created: $created);
        if ($taskId === '') {
            // A task that was written but cannot be identified is worse than
            // none: the slot would stay empty, so the next heartbeat writes
            // another, and the run accumulates duplicates nobody asked for.
            throw new RuntimeException('dossiq.askPerson could not identify the task it created');
        }

        return $taskId;

    }//end persistTask()


    /**
     * The id of the task the object service reports having written.
     *
     * `ObjectService::saveObject()` returns an ObjectEntity — it always has.
     * This method used to accept only an array, so every successful save was
     * followed by "could not identify the task it created": the task existed,
     * the run STOPPED instead of suspending, no resume slot was written, and
     * the task sat orphaned in somebody's list with no way to wake anything.
     * The entity's uuid is the id every read surface serves back, so it is
     * the one the resume slot must remember.
     *
     * The array shape is still accepted because the service is duck-typed
     * (SettingsService resolves it as `?object`), and refusing a shape that
     * carries a perfectly good id would recreate this bug for the other
     * shape.
     *
     * @param mixed $created Whatever the object service returned.
     *
     * @return string The task id, or '' when the result names none.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    private function createdTaskId(mixed $created): string {
        if (is_object($created) === true && method_exists($created, 'getUuid') === true) {
            $uuid = (string) ($created->getUuid() ?? '');
            if ($uuid !== '') {
                return $uuid;
            }
        }

        if (is_object($created) === true && ($created instanceof \JsonSerializable) === true) {
            $created = (array) $created->jsonSerialize();
        }

        if (is_array($created) === true) {
            return (string) ($created['id'] ?? ($created['uuid'] ?? ''));
        }

        return '';

    }//end createdTaskId()


    /**
     * The answer carried by a resume, or null when none has arrived.
     *
     * A resume with no decision is a NUDGE, not an answer — which is what makes
     * a duplicate or accidental signal harmless.
     *
     * @param array $context Run-level metadata.
     *
     * @return array|null The signal payload, or null.
     */
    private function answerFrom(array $context): ?array {
        $signal = ($context[FlowRunService::SIGNAL_CONTEXT_KEY] ?? null);
        if (is_array($signal) === false) {
            return null;
        }

        if (trim((string) ($signal['decision'] ?? '')) === '') {
            return null;
        }

        return $signal;

    }//end answerFrom()


    /**
     * When to wake and re-check, absent an answer.
     *
     * @param array $config The step configuration.
     *
     * @return DateTime The next heartbeat.
     */
    private function heartbeatAt(array $config): DateTime {
        $minutes = (int) ($config['heartbeatMinutes'] ?? self::DEFAULT_HEARTBEAT_MINUTES);
        if ($minutes < self::MIN_HEARTBEAT_MINUTES) {
            $minutes = self::MIN_HEARTBEAT_MINUTES;
        }

        return (new DateTime())->modify('+' . $minutes . ' minutes');

    }//end heartbeatAt()


}//end class
