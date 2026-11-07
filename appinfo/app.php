<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 */

$app = new \OCA\PhotosTimeline\AppInfo\Application();
$app->ensureBackgroundJob();
$app->registerFilesIntegration();
$app->registerUserCleanup();
