<?php
/**
 * Identity hooks — bind WP users to their anonymous visitor sessions and
 * fire the signup conversion on registration. See spec §5.
 *
 * @package Mbuzz\WP
 */

declare(strict_types=1);

namespace Mbuzz\WP\Identity;

use Mbuzz\Mbuzz;
use Mbuzz\WP\Privacy\Consent;

final class Hooks
{
    /** User meta: unix time of the last identify() we sent for this user. */
    public const META_LAST_IDENTIFIED_AT = '_mbuzz_last_identified_at';

    public static function register(): void
    {
        add_action('wp_login', [self::class, 'onLogin'], 10, 2);
        add_action('user_register', [self::class, 'onRegister'], 10, 1);
        add_action('profile_update', [self::class, 'onProfileUpdate'], 10, 2);
    }

    /**
     * wp_login: ($user_login, WP_User $user)
     *
     * @param \WP_User|object $user  Duck-typed — needs ID, user_email, display_name, roles.
     */
    public static function onLogin(string $userLogin, $user): void
    {
        if (! Consent::granted() || ! self::isValidUser($user)) {
            return;
        }
        Mbuzz::identify((int) $user->ID, self::traits($user));
        update_user_meta((int) $user->ID, self::META_LAST_IDENTIFIED_AT, time());
    }

    /**
     * user_register: ($user_id)
     */
    public static function onRegister(int $userId): void
    {
        if (! Consent::granted()) {
            return;
        }
        $user = get_userdata($userId);
        if (! self::isValidUser($user)) {
            return;
        }

        Mbuzz::identify($userId, self::traits($user));
        update_user_meta($userId, self::META_LAST_IDENTIFIED_AT, time());

        Mbuzz::conversion('signup', [
            'user_id'        => (string) $userId,
            'is_acquisition' => true,
        ]);
    }

    /**
     * profile_update: ($user_id, WP_User $old_user_data, array $userdata)
     *
     * @param \WP_User|object|null $oldUserData
     */
    public static function onProfileUpdate(int $userId, $oldUserData = null): void
    {
        if (! Consent::granted()) {
            return;
        }
        $user = get_userdata($userId);
        if (! self::isValidUser($user)) {
            return;
        }

        // Only re-identify when something identity-relevant actually changed.
        // Re-firing on every meta tweak (last_login, avatar, etc.) is wasteful
        // and dirties the audit trail.
        if (is_object($oldUserData)
            && isset($oldUserData->user_email, $oldUserData->display_name)
            && $oldUserData->user_email === $user->user_email
            && $oldUserData->display_name === $user->display_name
        ) {
            return;
        }

        Mbuzz::identify($userId, self::traits($user));
        update_user_meta($userId, self::META_LAST_IDENTIFIED_AT, time());
    }

    /**
     * @return array{email: string, name: string, role: ?string}
     */
    private static function traits(object $user): array
    {
        $roles = is_array($user->roles ?? null) ? $user->roles : [];

        return [
            'email' => (string) ($user->user_email ?? ''),
            'name'  => (string) ($user->display_name ?? ''),
            'role'  => $roles[0] ?? null,
        ];
    }

    private static function isValidUser($user): bool
    {
        return is_object($user) && isset($user->ID) && (int) $user->ID > 0;
    }
}
