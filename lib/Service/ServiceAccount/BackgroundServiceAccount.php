<?php

/**
 * Dossiq BackgroundServiceAccount.
 *
 * The Nextcloud account dossiq's background jobs write as. A cron job has no
 * Nextcloud session, so OpenRegister sees Anonymous and refuses the write:
 * the termijn reminder sweep sent its letter and then failed to store that it
 * did, so the count of reminders sent never moved. The job now acts as one
 * account an admin picks, with OpenRegister's checks on; the schemas it writes
 * grant that account's group (register.d/90-background-service-account.json).
 * Reads may stay without RBAC; nothing is written that way.
 *
 * A missing, unknown, disabled or ungrouped account refuses before anything
 * runs: the job logs an error, every admin gets a notification, and the job
 * writes nothing and sends nothing. Tomorrow's run tries again.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ServiceAccount
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ServiceAccount;

use DateTime;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Notification\Notifier;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the background service account and runs job writes as it.
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */
class BackgroundServiceAccount extends ServiceAccount {

	/**
	 * App-config key holding the uid of the background service account.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'background_service_account';

	/**
	 * The group the schemas a background job writes grant create and update to.
	 *
	 * @var string
	 */
	public const GROUP = 'dossiq-background-service';

	/**
	 * The notification object type, so a new refusal replaces the last one.
	 *
	 * @var string
	 */
	public const NOTIFICATION_OBJECT = 'background-service-account';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig           $appConfig     Holds the chosen uid.
	 * @param IUserManager         $userManager   Resolves the uid to an account.
	 * @param IGroupManager        $groupManager  Creates the group, checks membership, finds the admins.
	 * @param IUserSession         $userSession   Carries the acting user for one operation.
	 * @param LoggerInterface      $logger        Logs every refusal.
	 * @param INotificationManager $notifications Tells the admins.
	 */
	public function __construct(
		IAppConfig $appConfig,
		IUserManager $userManager,
		IGroupManager $groupManager,
		IUserSession $userSession,
		LoggerInterface $logger,
		private readonly INotificationManager $notifications,
	) {
		parent::__construct(
			appConfig: $appConfig,
			userManager: $userManager,
			groupManager: $groupManager,
			userSession: $userSession,
			logger: $logger,
		);
	}//end __construct()

	/**
	 * The app-config key holding the uid.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	protected function configKey(): string {
		return self::CONFIG_KEY;
	}//end configKey()

	/**
	 * The group the background schemas grant.
	 *
	 * @return string The group id.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function group(): string {
		return self::GROUP;
	}//end group()

	/**
	 * Refuse: log, notify every admin, and throw so the job skips.
	 *
	 * @param string $userId The configured uid.
	 * @param string $reason Why the account cannot be used.
	 *
	 * @return never
	 *
	 * @throws ServiceAccountUnavailableException Always.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	protected function refuse(string $userId, string $reason): never {
		$this->logger->error(
			'Dossiq: no usable background service account, the background jobs write nothing and send nothing',
			['app' => Application::APP_ID, 'userId' => $userId, 'reason' => $reason, 'group' => self::GROUP]
		);
		$this->notifyAdmins(reason: $reason);

		throw new ServiceAccountUnavailableException('No usable background service account ('.$reason.').');
	}//end refuse()

	/**
	 * Tell every admin, replacing the notification an earlier run left.
	 *
	 * A failure to notify is logged and never hides the refusal itself.
	 *
	 * @param string $reason Why the account cannot be used.
	 *
	 * @return void
	 */
	private function notifyAdmins(string $reason): void {
		try {
			$admins = $this->groupManager->get('admin');
			if ($admins === null) {
				return;
			}

			foreach ($admins->getUsers() as $admin) {
				$notification = $this->notifications->createNotification();
				$notification->setApp(Application::APP_ID)
					->setUser($admin->getUID())
					->setObject(self::NOTIFICATION_OBJECT, self::CONFIG_KEY);
				$this->notifications->markProcessed($notification);

				$notification->setDateTime(new DateTime())
					->setSubject(Notifier::SUBJECT_BACKGROUND_ACCOUNT_MISSING, ['reason' => $reason, 'group' => self::GROUP]);
				$this->notifications->notify($notification);
			}
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the admins could not be told that the background service account is missing',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
		}//end try
	}//end notifyAdmins()
}//end class
