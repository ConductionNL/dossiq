<?php

/**
 * Dossiq deliberation start guard.
 *
 * Runs the panel check (Awb Art. 7:13 lid 3) on every single-object save that
 * moves an advice request into in-deliberation, creates it there, or changes
 * its panel or objection while it deliberates. Refuses a save that reaches
 * advice-issued or inadmissible without deliberation. OpenRegister's bulk save,
 * import and flow writes fire no ObjectCreatingEvent or ObjectUpdatingEvent
 * and are not covered.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Listener\Support\SavedObjectPayload;
use OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService;
use OCA\Dossiq\Service\InternalWriteMarker;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Stops a write or create of an advice request that the panel check refuses, or that skips deliberation.
 *
 * A check that fails unexpectedly refuses too: a panel whose independence was
 * not established does not start deliberating.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/every-move-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-single-object-save-that-starts-deliberation-runs-the-panel-check
 */
class DeliberationStartGuard implements IEventListener {

	use SavedObjectPayload;

	/**
	 * The error code a write refused by the panel check carries.
	 */
	public const ERROR_CODE = 'deliberation-start-refused';

	/**
	 * The error code a write that skips deliberation carries.
	 */
	public const SKIP_ERROR_CODE = 'deliberation-skip-refused';

	/**
	 * The error code a write into an outcome status outside the advice transition carries.
	 */
	public const OUTCOME_ERROR_CODE = 'deliberation-outcome-refused';

	/**
	 * The refusal of a write into an outcome status outside the advice transition.
	 */
	public const OUTCOME_MESSAGE = 'Only the advice transition can issue the advice or declare the request inadmissible';

	/**
	 * The refusal when the check itself fails.
	 */
	public const UNCHECKED_MESSAGE = 'Panel independence could not be checked (Awb Art. 7:13 lid 3)';

	/**
	 * The refusal of a write that skips deliberation.
	 */
	public const SKIP_MESSAGE = 'Advice can only be issued after deliberation';

	/**
	 * The guarded status.
	 */
	private const DELIBERATION = 'in-deliberation';

	/**
	 * The statuses only deliberation leads to.
	 */
	private const AFTER_DELIBERATION = ['advice-issued', 'inadmissible'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService          $settingsService   The configured advice request schema.
	 * @param AdvisoryCommitteeService $advisoryCommittee The panel check.
	 * @param LoggerInterface          $logger            Logger.
	 * @param InternalWriteMarker      $internalWrites    Whether the app's own advice transition is writing.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly AdvisoryCommitteeService $advisoryCommittee,
		private readonly LoggerInterface $logger,
		private readonly InternalWriteMarker $internalWrites,
	) {
	}//end __construct()

	/**
	 * Refuse a write or create of an advice request that the panel check does not clear, or that skips deliberation.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/every-move-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-single-object-save-that-starts-deliberation-runs-the-panel-check
	 * @spec openspec/changes/creating-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-single-object-save-that-starts-deliberation-runs-the-panel-check
	 * @spec openspec/changes/creating-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-an-advice-request-reaches-advice-issued-or-inadmissible-only-from-in-deliberation
	 * @spec openspec/changes/only-the-advice-transition-sets-the-outcome/specs/bezwaar-advisory-committee/spec.md#requirement-only-the-advice-transition-writes-an-advice-request-into-advice-issued-or-inadmissible
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false && $event instanceof ObjectCreatingEvent === false) {
			return;
		}

		$request = $this->savedObject(event: $event);
		if ($request === null
			|| $this->inSchema(object: $request, configured: $this->settingsService->getConfigValue('bac_advice_request_schema')) === false
		) {
			return;
		}

		$refusal = $this->refusalFor(request: $request, before: $this->previousObject(event: $event));
		if ($refusal === null) {
			return;
		}

		$event->setErrors($refusal);
		$event->stopPropagation();
	}//end handle()

	/**
	 * The errors a write must be refused with, or null when it may be saved.
	 *
	 * @param array<string, mixed>      $request The request as it will be saved.
	 * @param array<string, mixed>|null $before  The request as stored, null on a create.
	 *
	 * @return array{code: string, message: string}|null The refusal.
	 */
	private function refusalFor(array $request, ?array $before): ?array {
		if (in_array($request['status'] ?? null, self::AFTER_DELIBERATION, true) === true) {
			return $this->outcomeRefusal(request: $request, from: ($before['status'] ?? null));
		}

		if ($this->needsPanelCheck(request: $request, before: $before) === false) {
			return null;
		}

		$message = $this->panelRefusal(request: $request);
		if ($message === null) {
			return null;
		}

		return ['code' => self::ERROR_CODE, 'message' => $message];
	}//end refusalFor()

	/**
	 * The refusal of a write that reaches advice-issued or inadmissible, or null when it may be saved.
	 *
	 * Only deliberation leads there, and only the app's own advice transition
	 * moves a request there. A save that keeps the status is no move.
	 *
	 * @param array<string, mixed> $request The request as it will be saved.
	 * @param mixed                $from    The status as stored, null on a create.
	 *
	 * @return array{code: string, message: string}|null The refusal.
	 */
	private function outcomeRefusal(array $request, mixed $from): ?array {
		if (in_array($from, [self::DELIBERATION, ...self::AFTER_DELIBERATION], true) === false) {
			return ['code' => self::SKIP_ERROR_CODE, 'message' => self::SKIP_MESSAGE];
		}

		if ($from === $request['status'] || $this->internalWrites->isInternal(objectId: (string) $request['id']) === true) {
			return null;
		}

		return ['code' => self::OUTCOME_ERROR_CODE, 'message' => self::OUTCOME_MESSAGE];
	}//end outcomeRefusal()

	/**
	 * Whether the write starts deliberation, or changes who deliberates on which objection.
	 *
	 * A create has no previous status, so a create in in-deliberation starts it.
	 *
	 * @param array<string, mixed>      $request The request as it will be saved.
	 * @param array<string, mixed>|null $before  The request as stored, null on a create.
	 *
	 * @return bool
	 */
	private function needsPanelCheck(array $request, ?array $before): bool {
		if (($request['status'] ?? null) !== self::DELIBERATION) {
			return false;
		}

		if (($before['status'] ?? null) !== self::DELIBERATION) {
			return true;
		}

		return json_encode($before['panel'] ?? null) !== json_encode($request['panel'] ?? null)
			|| $this->objectionOf(request: $before) !== $this->objectionOf(request: $request);
	}//end needsPanelCheck()

	/**
	 * The objection a request names: `bezwaar`, or the legacy key the backfill copies into it.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return string The objection id, '' when there is none.
	 */
	private function objectionOf(array $request): string {
		$objection = (string) ($request['bezwaar'] ?? '');
		if ($objection !== '') {
			return $objection;
		}

		return (string) ($request['objectionProceeding'] ?? '');
	}//end objectionOf()

	/**
	 * The panel check's refusal, or null when it clears.
	 *
	 * On a create the check's trail entry cannot be written, because the
	 * record does not exist yet; the audit writer then logs the full entry.
	 * Keep it that way: an entry written for the unsaved entity would leave an
	 * orphan row in OpenRegister's hash-chained trail.
	 *
	 * @param array<string, mixed> $request The request as it will be saved.
	 *
	 * @return string|null The refusal message.
	 */
	private function panelRefusal(array $request): ?string {
		try {
			$this->advisoryCommittee->guardDeliberationStart(request: $request, requestId: (string) $request['id']);
		} catch (RuntimeException $refused) {
			$this->logger->info('Dossiq: refused starting deliberation', ['request' => $request['id'], 'reason' => $refused->getMessage()]);
			return $refused->getMessage();
		} catch (Throwable $failed) {
			$this->logger->error('Dossiq: the panel check failed, deliberation not started', ['request' => $request['id'], 'exception' => $failed]);
			return self::UNCHECKED_MESSAGE;
		}

		return null;
	}//end panelRefusal()
}//end class
