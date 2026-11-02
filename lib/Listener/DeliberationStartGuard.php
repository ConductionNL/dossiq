<?php

/**
 * Dossiq deliberation start guard.
 *
 * Runs the panel check (Awb Art. 7:13 lid 3) on every OpenRegister write that
 * moves an advice request into in-deliberation, whichever path made the write:
 * a lifecycle action, an API write or AdvisoryCommitteeService.
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
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Stops a write into in-deliberation that the panel check refuses.
 *
 * A check that fails unexpectedly refuses too: a panel whose independence was
 * not established does not start deliberating.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/every-move-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-write-that-starts-deliberation-runs-the-panel-check
 */
class DeliberationStartGuard implements IEventListener {

	use SavedObjectPayload;

	/**
	 * The error code a refused write carries.
	 */
	public const ERROR_CODE = 'deliberation-start-refused';

	/**
	 * The refusal when the check itself fails.
	 */
	public const UNCHECKED_MESSAGE = 'Panel independence could not be checked (Awb Art. 7:13 lid 3)';

	/**
	 * The guarded status.
	 */
	private const DELIBERATION = 'in-deliberation';

	/**
	 * Constructor.
	 *
	 * @param SettingsService          $settingsService   The configured advice request schema.
	 * @param AdvisoryCommitteeService $advisoryCommittee The panel check.
	 * @param LoggerInterface          $logger            Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly AdvisoryCommitteeService $advisoryCommittee,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse a write into in-deliberation that the panel check does not clear.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/every-move-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-write-that-starts-deliberation-runs-the-panel-check
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$request = $this->savedObject(event: $event);
		if ($request === null || $this->entersDeliberation(event: $event, request: $request) === false) {
			return;
		}

		$refusal = $this->refusal(request: $request);
		if ($refusal === null) {
			return;
		}

		$event->setErrors(['code' => self::ERROR_CODE, 'message' => $refusal]);
		$event->stopPropagation();
	}//end handle()

	/**
	 * Whether the write moves an advice request into in-deliberation.
	 *
	 * @param Event                $event   The pre-save event.
	 * @param array<string, mixed> $request The request as it will be saved.
	 *
	 * @return bool
	 */
	private function entersDeliberation(Event $event, array $request): bool {
		if (($request['status'] ?? null) !== self::DELIBERATION
			|| $this->inSchema(object: $request, configured: $this->settingsService->getConfigValue('bac_advice_request_schema')) === false
		) {
			return false;
		}

		return ($this->previousObject(event: $event)['status'] ?? null) !== self::DELIBERATION;
	}//end entersDeliberation()

	/**
	 * The panel check's refusal, or null when it clears.
	 *
	 * @param array<string, mixed> $request The request as it will be saved.
	 *
	 * @return string|null The refusal message.
	 */
	private function refusal(array $request): ?string {
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
	}//end refusal()
}//end class
