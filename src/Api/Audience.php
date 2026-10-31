<?php
declare(strict_types=1);

namespace Announcements\Api;

/**
 * Who an announcement is for (announcements.audience). Pure so it loads
 * without Phalcon, like AnnouncementInput; controllers turn the caller's
 * role into the two flags visibleFor() takes.
 *
 * - everyone: every logged-in user, customer (`member`) accounts included.
 * - all:      "All Staff and Agents". Every internal role, but not
 *             customers. Keeps the v1 key so existing rows and API callers
 *             that send 'all' mean the same audience they always did
 *             internally; what changed (25 Sep 2026) is that customers no
 *             longer see it.
 * - staff:    admin and operator only.
 *
 * Any role not listed here (member, or a role added later) is treated as
 * a customer and sees `everyone` only, so a new role never sees internal
 * notices by accident.
 */
final class Audience
{
    public const EVERYONE = 'everyone';
    public const ALL      = 'all';
    public const STAFF    = 'staff';

    public const LABELS = [
        self::EVERYONE => 'Everyone (incl. customers)',
        self::ALL      => 'All Staff and Agents',
        self::STAFF    => 'Staff-Only',
    ];

    public const STAFF_ROLES = ['admin', 'operator'];

    /** charter_agent may not be seeded yet; Roles::idsByNames() skips unknown names. */
    public const AGENT_ROLES = ['agent', 'charter_agent'];

    public const ALIASES = [
        'staff-only'           => self::STAFF,
        'staff_only'           => self::STAFF,
        'staff-and-agents'     => self::ALL,
        'all-staff-and-agents' => self::ALL,
        'public'               => self::EVERYONE,
        'customers'            => self::EVERYONE,
    ];

    public static function normalize(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = self::ALIASES[$value] ?? $value;

        return isset(self::LABELS[$value]) ? $value : null;
    }

    /** @return string[] audience keys the caller may read */
    public static function visibleFor(bool $isStaff, bool $isAgent): array
    {
        if ($isStaff) {
            return [self::EVERYONE, self::ALL, self::STAFF];
        }

        return $isAgent ? [self::EVERYONE, self::ALL] : [self::EVERYONE];
    }

    public static function label(?string $audience): string
    {
        return self::LABELS[(string) $audience] ?? (string) $audience;
    }
}
