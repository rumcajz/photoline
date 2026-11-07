<?php
/**
 * Photos Timeline: self-contained test runner (no PHPUnit needed).
 *
 * Runs inside an ownCloud installation, as the web server user:
 *   php apps/photos_timeline/tests/run.php [userA userB [userC]]
 *
 * With two or more user ids it also runs the cross-user security tests:
 * each user must never get another user's photos, thumbnails or folders.
 * Read-only: it never changes files; it only reads the index and settings.
 *
 * @license AGPL-3.0
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}

$ocRoot = getenv('OC_ROOT') ?: '/var/www/owncloud';
require $ocRoot . '/lib/base.php';

use OCA\PhotosTimeline\AppInfo\Application;
use OCA\PhotosTimeline\Controller\ApiController;
use OCA\PhotosTimeline\Db\MediaMapper;
use OCA\PhotosTimeline\Service\MetadataExtractor;
use OCA\PhotosTimeline\Service\SettingsService;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
	global $pass, $fail;
	if ($ok) {
		$pass++;
		echo "  ok   $name\n";
	} else {
		$fail++;
		echo "  FAIL $name" . ($detail !== '' ? " ($detail)" : '') . "\n";
	}
}

function body($response): string {
	if ($response instanceof \OCP\AppFramework\Http\DataDisplayResponse) {
		$d = $response->render();
		$enc = $response->getHeaders()['Content-Encoding'] ?? '';
		return $enc === 'gzip' ? gzdecode($d) : $d;
	}
	if ($response instanceof \OCP\AppFramework\Http\JSONResponse) {
		return $response->render();
	}
	return '';
}

function packIds(string $pack): array {
	if (substr($pack, 0, 4) !== 'PTL1') {
		return ['bad'];
	}
	$len = unpack('N', substr($pack, 4, 4))[1];
	$header = json_decode(substr($pack, 8, $len), true);
	$out = [];
	foreach ($header as $h) {
		$out[$h[0]] = $h[1];
	}
	return $out;
}

/** Logs in a user for this process (no password: CLI only) and returns the API controller. */
function asUser(string $uid): ApiController {
	\OC_Util::tearDownFS();
	$user = \OC::$server->getUserManager()->get($uid);
	\OC::$server->getUserSession()->setUser($user);
	\OC_Util::setupFS($uid);
	return (new Application())->getContainer()->query(ApiController::class);
}

\OC_App::loadApp('photos_timeline');

// ------------------------------------------------------------------ units
echo "Unit tests\n";
$cases = [
	'20261031_152312.jpg' => '2026-10-31 15:23:12',
	'IMG_20200101_120000.jpg' => '2020-01-01 12:00:00',
	'VID-20190502-WA0003.mp4' => '2019-05-02 12:00:00',
	'2019-05-02 12.30.00.jpg' => '2019-05-02 12:30:00',
	'Screenshot_2019-05-02-12-30-05.png' => '2019-05-02 12:30:05',
	'PXL_20230401_101010123.jpg' => '2023-04-01 10:10:10',
	'holiday.jpg' => null,
	'20231345_999999.jpg' => null,
];
foreach ($cases as $name => $want) {
	$ts = MetadataExtractor::dateFromName($name);
	$got = $ts === null ? null : gmdate('Y-m-d H:i:s', $ts);
	check("date from name '$name'", $got === $want, "got " . var_export($got, true));
}
check('EXIF date parsed', MetadataExtractor::parseExifDate('2026:08:06 17:56:14') === gmmktime(17, 56, 14, 8, 6, 2026));
check('EXIF zero date rejected', MetadataExtractor::parseExifDate('0000:00:00 00:00:00') === null);
foreach (['..', '../x', 'a/../b', 'a/./b', "a\0b"] as $bad) {
	check('path rejected: ' . json_encode($bad), SettingsService::cleanPath($bad) === null);
}
check('path normalised', SettingsService::cleanPath('/CameraUpload/') === 'CameraUpload');
check('date validated', SettingsService::cleanDate('2026-02-30') === null && SettingsService::cleanDate('2026-02-28') === '2026-02-28');

// ---------------------------------------------- routes and annotations
echo "Route security\n";
$routes = require __DIR__ . '/../appinfo/routes.php';
$ref = new ReflectionClass(ApiController::class);
foreach ($routes['routes'] as $r) {
	[$ctrl, $method] = explode('#', $r['name']);
	$class = $ctrl === 'api' ? ApiController::class : \OCA\PhotosTimeline\Controller\PageController::class;
	$doc = (string)(new ReflectionMethod($class, lcfirst(str_replace('_', '', ucwords($method, '_')))))->getDocComment();
	check("{$r['name']} is not a public page", strpos($doc, '@PublicPage') === false);
	if ($r['name'] !== 'page#index') {
		check("{$r['name']} requires the CSRF token", strpos($doc, '@NoCSRFRequired') === false);
	}
	check("{$r['name']} has no CORS opening", strpos($doc, '@CORS') === false);
}

// ---------------------------------------------------------- cross-user
$users = array_slice($argv, 1);
if (count($users) >= 2) {
	echo "Cross-user security (" . implode(', ', $users) . ")\n";
	$mapper = \OC::$server->query(MediaMapper::class);
	$own = [];
	foreach ($users as $u) {
		$rows = $mapper->findForTimeline($u, [''], [1, 2], null, null);
		$own[$u] = array_map(function ($r) {
			return (int)$r['file_id'];
		}, $rows);
		echo "  ($u has " . count($own[$u]) . " indexed items)\n";
	}
	foreach ($users as $u) {
		$api = asUser($u);
		foreach ($users as $other) {
			if ($other === $u || !$own[$other]) {
				continue;
			}
			$sample = array_slice($own[$other], 0, 40);
			// the other user's ids that are not also reachable by this user (e.g. via a share)
			$foreign = array_values(array_diff($sample, $own[$u]));
			if (!$foreign) {
				continue;
			}
			$pack = packIds(body($api->thumbs(implode(',', $foreign), 's')));
			check("$u gets none of $other's small thumbnails", count($pack) === 0, count($pack) . ' returned');
			$pack = packIds(body($api->thumbs(implode(',', $foreign), 't')));
			check("$u gets none of $other's tiny thumbnails", count($pack) === 0, count($pack) . ' returned');
			$resp = $api->large($foreign[0]);
			check("$u cannot open $other's photo full size", $resp->getStatus() === 404, 'status ' . $resp->getStatus());
		}
		foreach (['mine', 'folder'] as $scope) {
			$data = json_decode(body($api->items($scope, '')), true);
			$ids = array_map(function ($i) {
				return $i[0];
			}, $data['items'] ?? []);
			$leak = [];
			foreach ($users as $other) {
				if ($other !== $u) {
					$leak = array_merge($leak, array_diff(array_intersect($ids, $own[$other]), $own[$u]));
				}
			}
			check("$u's timeline ($scope) has only $u's items", !$leak, count($leak) . ' foreign');
		}
		foreach (['../' . $users[0], '../../' . $users[0] . '/files', '/../..', '..%2F..'] as $evil) {
			$st = $api->items('folder', $evil)->getStatus();
			check("$u: folder " . json_encode($evil) . " refused", $st === 404 || $st === 400, "status $st");
			$st = $api->folders($evil)->getStatus();
			check("$u: folder list " . json_encode($evil) . " refused", $st === 404 || $st === 400, "status $st");
		}
	}
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
