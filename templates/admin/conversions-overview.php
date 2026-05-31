<?php
/**
 * Mbuzz → Conversions overview. A hub: which forms are tracked, what they
 * send, and where to configure them. Escaped output only — the presenter
 * (ConversionsOverviewPresenter) and controller prepared every value.
 *
 * @package Mbuzz\WP
 *
 * @var bool                              $has_api_key
 * @var string                            $docs_url
 * @var string                            $settings_url
 * @var bool                              $has_forms
 * @var int                               $tracked_count
 * @var array<int, array<string, mixed>>  $rows
 */

declare(strict_types=1);

use Mbuzz\WP\Tracking\TrackAs;

if (! defined('ABSPATH')) {
    exit;
}

$track_as_labels = [
    TrackAs::CONVERSION => __('Conversion', 'mbuzz-attribution'),
    TrackAs::EVENT      => __('Event', 'mbuzz-attribution'),
    TrackAs::OFF        => __('Off', 'mbuzz-attribution'),
];
?>
<div class="wrap">
	<h1><?php esc_html_e('Conversions', 'mbuzz-attribution'); ?></h1>

	<p class="description">
		<?php esc_html_e('Every form below can be tracked in mbuzz. Tracking is opt-in — open a form and configure its Mbuzz tab to start sending conversions or events. Nothing fires until you do.', 'mbuzz-attribution'); ?>
		<a href="<?php echo esc_url($docs_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Read the documentation →', 'mbuzz-attribution'); ?></a>
	</p>

	<?php if (! $has_api_key) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php esc_html_e('No API key is set, so nothing is being sent yet.', 'mbuzz-attribution'); ?>
				<a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Add your key in Settings →', 'mbuzz-attribution'); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<?php if (! $has_forms) : ?>
		<p><?php esc_html_e('No forms found yet. Create a Contact Form 7 form, then return here to configure tracking.', 'mbuzz-attribution'); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e('Form', 'mbuzz-attribution'); ?></th>
					<th scope="col"><?php esc_html_e('Tracking', 'mbuzz-attribution'); ?></th>
					<th scope="col"><?php esc_html_e('Mapping', 'mbuzz-attribution'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($rows as $row) : ?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url($row['edit_url']); ?>"><?php echo esc_html($row['title']); ?></a></strong>
						</td>
						<td>
							<?php if ($row['tracked']) : ?>
								<span class="dashicons dashicons-yes" style="color:#46b450"></span>
								<?php
								printf(
									/* translators: 1: Conversion or Event, 2: the type name, e.g. enquiry */
									esc_html__('%1$s · %2$s', 'mbuzz-attribution'),
									esc_html($track_as_labels[$row['track_as']] ?? $row['track_as']),
									esc_html($row['type'])
								);
								?>
							<?php else : ?>
								<span class="description"><?php esc_html_e('Not tracked', 'mbuzz-attribution'); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ($row['mapped_count'] > 0 || $row['ignored_count'] > 0) : ?>
								<?php
								printf(
									/* translators: %d: number of fields sent to mbuzz */
									esc_html(_n('%d field sent', '%d fields sent', (int) $row['mapped_count'], 'mbuzz-attribution')),
									(int) $row['mapped_count']
								);
								?>
								<?php if ($row['ignored_count'] > 0) : ?>
									<span class="description">
										<?php
										printf(
											/* translators: %d: number of fields not sent */
											esc_html(_n('· %d ignored', '· %d ignored', (int) $row['ignored_count'], 'mbuzz-attribution')),
											(int) $row['ignored_count']
										);
										?>
									</span>
								<?php endif; ?>
								<?php if ($row['tracked'] && ! $row['has_user_id']) : ?>
									<br><span class="description" style="color:#996800"><?php esc_html_e('No user-ID field — identity isn\'t sent. Map a field as the user-ID join key to tie this to a person.', 'mbuzz-attribution'); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span class="description"><?php esc_html_e('Not configured', 'mbuzz-attribution'); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<hr>
	<h2><?php esc_html_e('How tracking works', 'mbuzz-attribution'); ?></h2>
	<p class="description" style="max-width:46em">
		<?php esc_html_e('mbuzz records the whole journey server-side: every page view becomes a touchpoint, and a configured form submission becomes an attributed conversion or event. The transaction can even complete in another system (a CRM or billing platform) — as long as it reports the same user ID later, mbuzz links it back to the journey captured here.', 'mbuzz-attribution'); ?>
	</p>
	<p>
		<a href="<?php echo esc_url($settings_url); ?>" class="button"><?php esc_html_e('Settings', 'mbuzz-attribution'); ?></a>
		<a href="<?php echo esc_url($docs_url); ?>" class="button button-primary" target="_blank" rel="noopener"><?php esc_html_e('Documentation', 'mbuzz-attribution'); ?></a>
	</p>
</div>
