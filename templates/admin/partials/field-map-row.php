<?php
/**
 * One field-mapping row. Escaped output only.
 *
 * @package Mbuzz\WP
 *
 * @var string                $field
 * @var string                $role
 * @var string                $role_name
 * @var string                $role_id
 * @var string                $key
 * @var string                $key_name
 * @var string                $key_id
 * @var array<int, string>    $role_options
 * @var array<string, string> $role_labels
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>
<tr>
	<td><code><?php echo esc_html($field); ?></code></td>
	<td>
		<label class="screen-reader-text" for="<?php echo esc_attr($role_id); ?>">
			<?php
			/* translators: %s: form field name */
			printf(esc_html__('Role for %s', 'mbuzz-attribution'), esc_html($field));
			?>
		</label>
		<select id="<?php echo esc_attr($role_id); ?>" name="<?php echo esc_attr($role_name); ?>">
			<?php foreach ($role_options as $option) : ?>
				<option value="<?php echo esc_attr($option); ?>" <?php selected($role, $option); ?>>
					<?php echo esc_html($role_labels[$option] ?? $option); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</td>
	<td>
		<label class="screen-reader-text" for="<?php echo esc_attr($key_id); ?>">
			<?php
			/* translators: %s: form field name */
			printf(esc_html__('mbuzz name for %s', 'mbuzz-attribution'), esc_html($field));
			?>
		</label>
		<input type="text" id="<?php echo esc_attr($key_id); ?>" name="<?php echo esc_attr($key_name); ?>" value="<?php echo esc_attr($key); ?>" class="regular-text" placeholder="<?php echo esc_attr__('e.g. team_size', 'mbuzz-attribution'); ?>">
	</td>
</tr>
