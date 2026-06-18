<?php
/**
 * Migrator admin screen.
 *
 * @package Migrator
 */

defined('ABSPATH') || exit;
?>
<div class="wrap migrator">
	<h1 class="migrator__title">
		<span class="dashicons dashicons-migrate" aria-hidden="true"></span>
		<?php esc_html_e('Migrator', 'migrator'); ?>
	</h1>
	<p class="migrator__lead">
		<?php esc_html_e('Back up your site or move it to a new host — one file, no technical setup.', 'migrator'); ?>
	</p>

	<div class="migrator__cards">
		<section class="migrator-card" aria-labelledby="migrator-export-heading">
			<h2 id="migrator-export-heading" class="migrator-card__heading">
				<?php esc_html_e('Create a backup', 'migrator'); ?>
			</h2>
			<p class="migrator-card__desc">
				<?php esc_html_e('Package your database and files into a single archive you can download and restore anywhere.', 'migrator'); ?>
			</p>

			<button type="button" class="button button-primary button-hero" id="migrator-export-start">
				<?php esc_html_e('Create backup', 'migrator'); ?>
			</button>

			<div class="migrator-progress" id="migrator-export-progress" hidden>
				<div
					class="migrator-progress__bar"
					role="progressbar"
					aria-valuemin="0"
					aria-valuemax="100"
					aria-valuenow="0"
					aria-label="<?php esc_attr_e('Backup progress', 'migrator'); ?>"
				>
					<span class="migrator-progress__fill" id="migrator-export-fill"></span>
				</div>
				<p class="migrator-progress__status" id="migrator-export-status" aria-live="polite"></p>
			</div>

			<div class="migrator-result" id="migrator-export-result" hidden>
				<p class="migrator-result__msg" id="migrator-export-result-msg"></p>
				<a class="button button-primary" id="migrator-export-download" href="#" download>
					<?php esc_html_e('Download backup', 'migrator'); ?>
				</a>
			</div>
		</section>

		<section class="migrator-card" aria-labelledby="migrator-import-heading">
			<h2 id="migrator-import-heading" class="migrator-card__heading">
				<?php esc_html_e('Restore a backup', 'migrator'); ?>
			</h2>
			<p class="migrator-card__desc">
				<?php esc_html_e('Restoring overwrites this site with the contents of an archive. The drag-and-drop importer is coming next; for now use WP-CLI:', 'migrator'); ?>
			</p>
			<p><code>wp migrator import &lt;file&gt;.migrator</code></p>
		</section>
	</div>
</div>
