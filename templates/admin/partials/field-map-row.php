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
 * @var bool                  $key_used      whether this role takes a mbuzz name
 * @var bool                  $key_is_map    event_type reuses the column for its value map
 * @var array<int, string>    $role_options
 * @var array<int, string>    $keyed_roles   roles that take a mbuzz name
 * @var array<string, string> $role_labels
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$keyed_roles = isset($keyed_roles) && is_array($keyed_roles) ? $keyed_roles : [];
$key_used    = ! empty($key_used);
$key_is_map  = ! empty($key_is_map);
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
		<select
			id="<?php echo esc_attr($role_id); ?>"
			name="<?php echo esc_attr($role_name); ?>"
			class="mbuzz-role-select"
			data-keyed-roles="<?php echo esc_attr((string) wp_json_encode(array_values($keyed_roles))); ?>"
			data-key-target="<?php echo esc_attr($key_id); ?>">
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
		<?php if ($key_is_map) : ?>
			<textarea id="<?php echo esc_attr($key_id); ?>" name="<?php echo esc_attr($key_name); ?>" class="regular-text mbuzz-key-input" rows="3" placeholder="<?php echo esc_attr__("book_a_tour = ll_submit_tour\nenquiry = ll_submit_enquiry", 'mbuzz-attribution'); ?>"><?php echo esc_textarea($key); ?></textarea>
			<p class="description">
				<?php esc_html_e('One per line: the value this field posts, then the event name to send. A value that isn\'t listed falls back to the event name above.', 'mbuzz-attribution'); ?>
			</p>
		<?php else : ?>
			<input type="text" id="<?php echo esc_attr($key_id); ?>" name="<?php echo esc_attr($key_name); ?>" value="<?php echo esc_attr($key); ?>" class="regular-text mbuzz-key-input" placeholder="<?php echo esc_attr__('e.g. team_size', 'mbuzz-attribution'); ?>"<?php echo $key_used ? '' : ' style="display:none"'; ?>>
			<span class="mbuzz-key-na description" aria-hidden="true"<?php echo $key_used ? ' style="display:none"' : ''; ?>>&mdash;</span>
		<?php endif; ?>
	</td>
</tr>
