<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 *
 * Every route requires a logged-in user. None is a public page, and only the
 * HTML page itself skips the CSRF check (it contains no user data).
 */

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'api#items', 'url' => '/api/v1/items', 'verb' => 'GET'],
		['name' => 'api#thumbs', 'url' => '/api/v1/thumbs', 'verb' => 'GET'],
		['name' => 'api#large', 'url' => '/api/v1/large/{fileId}', 'verb' => 'GET', 'requirements' => ['fileId' => '\d+']],
		['name' => 'api#folders', 'url' => '/api/v1/folders', 'verb' => 'GET'],
		['name' => 'api#getSettings', 'url' => '/api/v1/settings', 'verb' => 'GET'],
		['name' => 'api#setSettings', 'url' => '/api/v1/settings', 'verb' => 'PUT'],
	],
];
