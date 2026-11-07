<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 *
 * The page shell only. All data is loaded by js/timeline.js through the API.
 */
script('photos_timeline', 'timeline');
style('photos_timeline', 'timeline');
?>
<div id="app" class="ptl-app" data-settings="<?php p($_['settings']); ?>"<?php if ($_['cronWarning']) { ?> data-cron-warning="1"<?php } ?>>
	<div id="app-content" class="ptl-content">
		<header class="ptl-toolbar" role="toolbar" aria-label="<?php p($l->t('Timeline controls')); ?>">
			<nav class="ptl-crumbs" aria-label="<?php p($l->t('Location')); ?>"></nav>
			<button type="button" class="ptl-filter-toggle" aria-expanded="false" aria-controls="ptl-filters">
				<?php p($l->t('Filters')); ?>
			</button>
			<div class="ptl-filters" id="ptl-filters">
				<div class="ptl-seg" role="group" aria-label="<?php p($l->t('Show')); ?>">
					<button type="button" data-types="all" aria-pressed="true"><?php p($l->t('All')); ?></button>
					<button type="button" data-types="photos" aria-pressed="false"><?php p($l->t('Photos')); ?></button>
					<button type="button" data-types="videos" aria-pressed="false"><?php p($l->t('Videos')); ?></button>
				</div>
				<label class="ptl-date"><span><?php p($l->t('From')); ?></span>
					<input type="date" class="ptl-from" min="1900-01-01" max="2100-12-31">
				</label>
				<label class="ptl-date"><span><?php p($l->t('To')); ?></span>
					<input type="date" class="ptl-to" min="1900-01-01" max="2100-12-31">
				</label>
				<button type="button" class="ptl-clear" hidden><?php p($l->t('Clear dates')); ?></button>
			</div>
			<button type="button" class="ptl-settings-btn" title="<?php p($l->t('Choose folders')); ?>" aria-label="<?php p($l->t('Choose folders for My timeline')); ?>"></button>
		</header>
		<div class="ptl-main">
			<div class="ptl-scroll" tabindex="-1">
				<div class="ptl-canvas" role="list" aria-label="<?php p($l->t('Photos and videos')); ?>"></div>
				<div class="ptl-message" hidden></div>
			</div>
			<div class="ptl-scrubber" aria-hidden="true">
				<div class="ptl-scrub-labels"></div>
				<div class="ptl-scrub-pos"></div>
				<div class="ptl-scrub-bubble" hidden></div>
			</div>
		</div>
		<div class="ptl-status" role="status" aria-live="polite"></div>
	</div>
</div>
