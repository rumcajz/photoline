<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Controller;

use OCA\PhotosTimeline\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

class PageController extends Controller {
	/** @var IUserSession */
	private $userSession;
	/** @var IRootFolder */
	private $rootFolder;
	/** @var SettingsService */
	private $settings;

	public function __construct(string $AppName, IRequest $request, IUserSession $userSession,
								IRootFolder $rootFolder, SettingsService $settings) {
		parent::__construct($AppName, $request);
		$this->userSession = $userSession;
		$this->rootFolder = $rootFolder;
		$this->settings = $settings;
	}

	/**
	 * The app page. Besides the logged-in user's own timeline settings (so the
	 * timeline can load without an extra request) it holds no data.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index(): TemplateResponse {
		$settings = null;
		$user = $this->userSession->getUser();
		if ($user !== null) {
			try {
				$settings = $this->settings->get($user->getUID(), $this->rootFolder->getUserFolder($user->getUID()));
			} catch (\Throwable $e) {
				$settings = null;
			}
		}
		return new TemplateResponse($this->appName, 'main', [
			'settings' => \json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		]);
	}
}
