<?php

/**
 * Dossiq integration-status service.
 *
 * Tells integriq's connection registry what a probe or a save learned about one
 * of dossiq's outside connections. Integriq owns the rows the Integrations page
 * lists and works out each status itself (hydra change connection-registry,
 * design D4). Dossiq only reports what it alone can observe, and asks for a
 * fresh resolve after a save.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\AppInfo\Registrar\SubstitutableAdapterRegistrar;
use OCA\Dossiq\Service\Beschikking\FilinqTemplateEngineAdapter;
use OCA\Dossiq\Service\Beschikking\MockTemplateEngineAdapter;
use OCA\Dossiq\Service\Beschikking\TemplateAdapterChoice;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends connection reports and refresh requests to integriq.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md
 */
class IntegrationStatusService {

	/**
	 * Integriq's report event (ADR-041). Named by string so dossiq stays
	 * installable without integriq: the class is only there when integriq is.
	 *
	 * @var string
	 */
	public const STATUS_EVENT = 'OCA\Integriq\Event\ConnectionStatusReportedEvent';

	/**
	 * Integriq's refresh event. Same reason for the string as above.
	 *
	 * @var string
	 */
	public const REFRESH_EVENT = 'OCA\Integriq\Event\ConnectionRefreshRequestedEvent';

	/**
	 * The connection keys `lib/Settings/connections.json` declares. A key
	 * outside this set is a caller's typo, not a new connection: adding one
	 * means declaring it in that file too. A unit test keeps the two equal.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = [
		'zgw',
		'stuf',
		'kcc',
		'dmn',
		'mailbox',
		'store',
		'financial',
		'brp',
		'kvk',
		'pdok',
		'berichtenbox',
		'templates',
	];

	/**
	 * The six states the registry accepts. Anything else is refused here, so
	 * a typo never travels to integriq only to be dropped with a warning there.
	 *
	 * `simulated` says the quiet part. A seam bound to a mock adapter WORKS:
	 * the send succeeds and a message id comes back, and nothing leaves the
	 * instance. `unavailable` would be wrong for it, because the seam is
	 * available; it just is not real.
	 *
	 * `limited` means the connection works in part (contract D4, hydra#673).
	 * No dossiq caller reports it yet; the service accepts it because the
	 * contract does.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = [
		'configured',
		'limited',
		'unconfigured',
		'unavailable',
		'simulated',
		'error',
	];

	/**
	 * App-config keys per connection whose save asks integriq to resolve that
	 * connection again. Each list is the connection's `requiredConfig` plus its
	 * `adapter.configKey` in `lib/Settings/connections.json`; a unit test keeps
	 * the two equal.
	 *
	 * StUF, the mailbox and the store are absent on purpose: dossiq probes them
	 * and reports the outcome, and a saved form says less than a probe does.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const SAVE_REQUIRED_KEYS = [
		'zgw' => ['register', 'case_schema'],
		'kcc' => ['identification_method'],
		'dmn' => ['decision_table_schema'],
		'financial' => ['dwangsom_callback_secret'],
		'berichtenbox' => ['digital_post_source', 'berichtenbox_adapter'],
		'templates' => ['beschikking_template_adapter'],
	];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Sends the integriq events (ADR-041).
	 * @param LoggerInterface $logger Records what could not be sent.
	 * @param TemplateAdapterChoice|null $templateChoice The template seam's own rule, for the templates card.
	 * @param IAppConfig|null $appConfig Reads the template adapter key, for the templates card.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
		private readonly ?TemplateAdapterChoice $templateChoice = null,
		private readonly ?IAppConfig $appConfig = null,
	) {
	}//end __construct()

	/**
	 * What the Document templates card should read: Live or Simulated.
	 *
	 * THE CARD ASKS THE SEAM'S QUESTION. An empty `beschikking_template_adapter`
	 * binds filinq's adapter when filinq is enabled and the mock otherwise
	 * ({@see TemplateAdapterChoice}), so an empty
	 * key no longer means a mock. integriq cannot see whether filinq is
	 * enabled, so dossiq reports the answer rather than leaving it to the
	 * declaration's `simulatedValues`.
	 *
	 * @return array{status: string, message: string}|null The status and its words, or null without the readers.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function templatesStatus(): ?array {
		if ($this->templateChoice === null || $this->appConfig === null) {
			return null;
		}

		$named = trim($this->appConfig->getValueString(Application::APP_ID, SubstitutableAdapterRegistrar::TEMPLATE_CONFIG_KEY, ''));
		$adapter = $this->templateChoice->adapterFor(named: $named);

		if ($adapter === MockTemplateEngineAdapter::class) {
			$message = 'Filinq is not installed. A mock adapter answers here and no template reaches Filinq.';
			if ($named !== '') {
				$message = 'beschikking_template_adapter names the mock. No template reaches Filinq. Clear it to render through Filinq.';
			}

			return ['status' => 'simulated', 'message' => $message];
		}

		if ($adapter === FilinqTemplateEngineAdapter::class) {
			return ['status' => 'configured', 'message' => 'Filinq renders the templates.'];
		}

		return ['status' => 'configured', 'message' => $adapter . ' renders the templates.'];
	}//end templatesStatus()

	/**
	 * Report the Document templates card to integriq.
	 *
	 * @return bool True when the report was sent.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function recordTemplates(): bool {
		$status = $this->templatesStatus();
		if ($status === null) {
			return false;
		}

		return $this->record(key: 'templates', status: $status['status'], message: $status['message']);
	}//end recordTemplates()

	/**
	 * Report a connection's status to integriq.
	 *
	 * Never throws: this runs beside a probe whose own answer is the thing the
	 * caller returns, and a report that cannot be delivered must not turn a
	 * successful connection test into a 500. Without integriq nothing is sent
	 * and nothing is logged, because a missing optional app is not a fault.
	 *
	 * @param string $key One of {@see self::KEYS}.
	 * @param string $status One of {@see self::STATUSES}.
	 * @param string $message What the probe reported.
	 *
	 * @return bool True when the report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md
	 */
	public function record(string $key, string $status, string $message = ''): bool {
		if (in_array($key, self::KEYS, true) === false) {
			$this->logger->warning(
				'Dossiq: refusing to report an unknown connection key',
				['key' => $key]
			);
			return false;
		}

		if (in_array($status, self::STATUSES, true) === false) {
			$this->logger->warning(
				'Dossiq: refusing to report an unknown connection status',
				['key' => $key, 'status' => $status]
			);
			return false;
		}

		$eventClass = $this->resolveEventClass(eventClass: self::STATUS_EVENT);
		if ($eventClass === null) {
			return false;
		}

		return $this->send(
			key: $key,
			build: static fn (): object => new $eventClass(
				app: Application::APP_ID,
				key: $key,
				status: $status,
				message: $message,
			)
		);
	}//end record()

	/**
	 * Ask integriq to resolve every connection whose settings the save touched.
	 *
	 * A save that names none of a connection's keys leaves that connection
	 * alone: saving the KCC form must not ask integriq about ZGW. Integriq
	 * reads the saved values itself and decides the status (design D6).
	 *
	 * @param array<string, mixed> $saved The payload the settings save carried.
	 *
	 * @return array<int, string> The connection keys a refresh was sent for.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md
	 */
	public function recordFromSave(array $saved): array {
		$eventClass = $this->resolveEventClass(eventClass: self::REFRESH_EVENT);
		if ($eventClass === null) {
			return [];
		}

		$refreshed = [];
		foreach (self::SAVE_REQUIRED_KEYS as $key => $configKeys) {
			if (array_intersect($configKeys, array_keys($saved)) === []) {
				continue;
			}

			$sent = $this->send(
				key: $key,
				build: static fn (): object => new $eventClass(
					app: Application::APP_ID,
					key: $key,
				)
			);
			if ($sent === true) {
				$refreshed[] = $key;
			}
		}

		// The templates card is the one row integriq cannot resolve from the
		// saved value alone: an empty key reads Live or Simulated depending on
		// whether filinq is enabled. So the save also reports what the seam binds.
		if (in_array('templates', $refreshed, true) === true) {
			$this->recordTemplates();
		}

		return $refreshed;
	}//end recordFromSave()

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @param string $eventClass The fully qualified class name, without a leading backslash.
	 *
	 * @return string|null The class name to instantiate, or null when absent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md
	 */
	protected function resolveEventClass(string $eventClass): ?string {
		$qualified = '\\' . $eventClass;
		if (class_exists($qualified) === false) {
			return null;
		}

		return $qualified;
	}//end resolveEventClass()

	/**
	 * Build and dispatch one event, swallowing anything a listener throws.
	 *
	 * @param string $key The connection the event is about, for the log.
	 * @param callable(): object $build Builds the event.
	 *
	 * @return bool True when the event was dispatched without an exception.
	 */
	private function send(string $key, callable $build): bool {
		try {
			$event = $build();
			if ($event instanceof Event === false) {
				return false;
			}

			$this->eventDispatcher->dispatchTyped($event);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not send a connection event to integriq',
				['key' => $key, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end send()
}//end class
