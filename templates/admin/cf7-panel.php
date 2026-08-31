<?php
/**
 * Mbuzz panel in the Contact Form 7 form editor. Escaped output only — the
 * presenter (Cf7PanelPresenter) prepared every value.
 *
 * @package Mbuzz\WP
 *
 * @var bool                              $enabled
 * @var string                            $enabled_name
 * @var string                            $track_as
 * @var string                            $track_as_name
 * @var array<int, string>                $track_as_options
 * @var string                            $type
 * @var string                            $type_name
 * @var string                            $capture_page_as
 * @var string                            $capture_page_as_name
 * @var array<int, string>                $role_options
 * @var array<int, array<string, string>> $rows
 * @var string                            $docs_url
 */

declare(strict_types=1);

use Mbuzz\WP\Support\View;
use Mbuzz\WP\Tracking\Roles;
use Mbuzz\WP\Tracking\TrackAs;

if (! defined('ABSPATH')) {
    exit;
}

// Presentation labels live in the view.
$track_as_labels = [
    TrackAs::CONVERSION => __('Conversion', 'mbuzz-attribution'),
    TrackAs::EVENT      => __('Event', 'mbuzz-attribution'),
    TrackAs::OFF        => __('Off', 'mbuzz-attribution'),
];
$role_labels = [
    Roles::USER_ID  => __('Identity — user ID (join key)', 'mbuzz-attribution'),
    Roles::TRAIT    => __('Identity trait', 'mbuzz-attribution'),
    Roles::PROPERTY => __('Property', 'mbuzz-attribution'),
    Roles::REVENUE  => __('Revenue', 'mbuzz-attribution'),
    Roles::CURRENCY => __('Currency', 'mbuzz-attribution'),
    Roles::IGNORE   => __('Ignore', 'mbuzz-attribution'),
];
?>
<h2><?php esc_html_e('Mbuzz attribution', 'mbuzz-attribution'); ?></h2>
<p class="description">
	<?php esc_html_e('Choose how this form is sent to mbuzz. Tracking stays off until you enable it here — nothing fires on forms you don\'t configure. Set whether the submission is a conversion or an event, name it, and map each field below.', 'mbuzz-attribution'); ?>
</p>
<p class="description">
	<?php
	printf(
		/* translators: 1: Identity user ID role, 2: Identity trait role, 3: Property role, 4: Ignore role */
		esc_html__('Per field, pick a role: %1$s is the join key that ties this lead to the rest of the journey and to conversions your other systems report later — a non-PII unique ID is best; email or phone send that data to mbuzz. %2$s describes the person (you name it). %3$s attaches anything else to the conversion. %4$s never sends it.', 'mbuzz-attribution'),
		'<strong>' . esc_html__('Identity — user ID', 'mbuzz-attribution') . '</strong>',
		'<strong>' . esc_html__('Identity trait', 'mbuzz-attribution') . '</strong>',
		'<strong>' . esc_html__('Property', 'mbuzz-attribution') . '</strong>',
		'<strong>' . esc_html__('Ignore', 'mbuzz-attribution') . '</strong>'
	);
	?>
	<a href="<?php echo esc_url($docs_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Read the documentation →', 'mbuzz-attribution'); ?></a>
</p>

<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e('Enable tracking', 'mbuzz-attribution'); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($enabled_name); ?>" value="1" <?php checked($enabled); ?>>
					<?php esc_html_e('Enabled', 'mbuzz-attribution'); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="mbuzz-track-as"><?php esc_html_e('Track as', 'mbuzz-attribution'); ?></label></th>
			<td>
				<select id="mbuzz-track-as" name="<?php echo esc_attr($track_as_name); ?>">
					<?php foreach ($track_as_options as $option) : ?>
						<option value="<?php echo esc_attr($option); ?>" <?php selected($track_as, $option); ?>>
							<?php echo esc_html($track_as_labels[$option] ?? $option); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="mbuzz-type"><?php esc_html_e('Conversion / event type', 'mbuzz-attribution'); ?></label></th>
			<td>
				<input type="text" id="mbuzz-type" name="<?php echo esc_attr($type_name); ?>" value="<?php echo esc_attr($type); ?>" class="regular-text" placeholder="enquiry">
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="mbuzz-capture"><?php esc_html_e('Capture page as property', 'mbuzz-attribution'); ?></label></th>
			<td>
				<input type="text" id="mbuzz-capture" name="<?php echo esc_attr($capture_page_as_name); ?>" value="<?php echo esc_attr($capture_page_as); ?>" class="regular-text" placeholder="location">
				<p class="description"><?php esc_html_e('Names a property holding the page title (e.g. the location). Leave blank to skip.', 'mbuzz-attribution'); ?></p>
			</td>
		</tr>
	</tbody>
</table>

<h3><?php esc_html_e('Field mapping', 'mbuzz-attribution'); ?></h3>
<?php if ($rows === []) : ?>
	<p><?php esc_html_e('Add fields to this form, then map them here.', 'mbuzz-attribution'); ?></p>
<?php else : ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e('Form field', 'mbuzz-attribution'); ?></th>
				<th scope="col"><?php esc_html_e('Role', 'mbuzz-attribution'); ?></th>
				<th scope="col"><?php esc_html_e('mbuzz name', 'mbuzz-attribution'); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($rows as $row) : ?>
				<?php View::render('admin/partials/field-map-row', $row + ['role_options' => $role_options, 'role_labels' => $role_labels]); ?>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e('Sensitive fields (children, dates of birth) default to "Ignore" — turn them on only if you mean to.', 'mbuzz-attribution'); ?></p>
<?php endif; ?>
