<?php

use App_skeleton\Models\SoftDeletes;

/**
 * One company-wide notice. Bare/global model class, matching the sibling
 * ai-ssa-chat/phone/email plugins' own Config models and
 * requirements-module's Requirement/Changelog convention — Phalcon's
 * per-module autoloading (Module::registerAutoloaders()) discovers it via
 * setDirectories(), not a namespace prefix.
 *
 * @method static Announcement|null findFirstById(int $id)
 */
class Announcement extends \Phalcon\Mvc\Model
{
    use SoftDeletes;

    public $id;
    public $title;
    public $body;
    public $author_user_id;
    public $audience;
    public $severity;
    public $published_at;
    public $expires_at;
    public $expired_notified_at;
    public $source;
    public $external_id;
    public $source_url;
    public $deleted_at;
    public $created_at;
    public $updated_at;

    public const SEVERITIES = ['info' => 'Info', 'notice' => 'Notice', 'important' => 'Important'];

    /**
     * Accepted on input (API create, feed poller, history filter) and
     * stored as the level they map to — the status-feed feature was
     * specified in terms of "warning"/"critical", which fold into the
     * existing three-level scale rather than widening it. Mirrored in
     * Announcements\Api\AnnouncementInput::SEVERITY_ALIASES, which must
     * stay loadable without Phalcon.
     */
    public const SEVERITY_ALIASES = ['warning' => 'notice', 'critical' => 'important'];

    /** "All Staff and Agents". The full set of audiences is Announcements\Api\Audience. */
    public const AUDIENCE_ALL = 'all';

    public function audienceLabel(): string
    {
        return \Announcements\Api\Audience::label($this->audience);
    }

    private static ?\League\CommonMark\MarkdownConverter $markdownConverter = null;

    public function initialize(): void
    {
        $this->setSource('announcements');
        $this->keepSnapshots(true);
        $this->belongsTo('author_user_id', 'Users', 'id', ['alias' => 'Author']);
    }

    public function beforeSave(): void
    {
        $this->updated_at = date('Y-m-d H:i:s');
    }

    public static function normalizeSeverity(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = self::SEVERITY_ALIASES[$value] ?? $value;

        return isset(self::SEVERITIES[$value]) ? $value : null;
    }

    /**
     * The live row mirroring an external event, by its provenance key
     * (see migration 002's partial unique index) — how the feed poller
     * and POST /api/announcements dedupe. findFirst() here is the
     * SoftDeletes override, so a soft-deleted mirror is not found and
     * the same event can be re-created after an admin deleted it.
     */
    public static function findBySourceKey(string $source, string $externalId): ?self
    {
        return self::findFirst([
            'conditions' => 'source = :source: AND external_id = :external_id:',
            'bind'       => ['source' => $source, 'external_id' => $externalId],
        ]);
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at <= date('Y-m-d H:i:s');
    }

    /**
     * Server-side markdown render — league/commonmark in safe mode
     * (html_input escaped, unsafe links disallowed), per the module
     * plan's open question 1 (markdown, not rich text — no existing
     * Stack-wide rich-text convention was found to reuse instead, see
     * the PR description). The converter is stateless once built, so one
     * static instance is reused across every call in a request rather
     * than rebuilding the extension/environment per announcement.
     */
    public function renderedBodyHtml(): string
    {
        return (string) self::converter()->convert((string) $this->body);
    }

    private static function converter(): \League\CommonMark\MarkdownConverter
    {
        if (self::$markdownConverter === null) {
            $environment = new \League\CommonMark\Environment\Environment([
                'html_input'         => 'escape',
                'allow_unsafe_links' => false,
            ]);
            $environment->addExtension(new \League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension());

            self::$markdownConverter = new \League\CommonMark\MarkdownConverter($environment);
        }

        return self::$markdownConverter;
    }

    /**
     * Fires 'announcements:expired' on the shared events bus for every
     * announcement whose expires_at has just passed, exactly once per
     * announcement (expired_notified_at makes this idempotent — see
     * migration 001's own comment on why that column exists). Call this
     * at the top of every read path (backend index/history/view, the API
     * index action) — there is no cron in v1, so this is the only
     * mechanism that ever detects an expiry (module plan, "Phasing" —
     * P1 has no scheduler). A draft (published_at IS NULL) never fires
     * this: it was never announced, so it can't have "expired" in any
     * meaningful sense.
     */
    public static function detectAndFireExpirations(): void
    {
        $now = date('Y-m-d H:i:s');

        $newlyExpired = self::find([
            'conditions' => 'published_at IS NOT NULL AND expires_at IS NOT NULL '
                . 'AND expires_at <= :now: AND expired_notified_at IS NULL',
            'bind'       => ['now' => $now],
        ]);

        if (count($newlyExpired) === 0) {
            return;
        }

        $di            = \Phalcon\Di\Di::getDefault();
        $eventsManager = $di !== null && $di->has('eventsBus') ? $di->getShared('eventsBus') : null;

        foreach ($newlyExpired as $announcement) {
            $announcement->expired_notified_at = $now;

            if (!$announcement->save()) {
                continue;
            }

            if ($eventsManager !== null) {
                $eventsManager->fire('announcements:expired', $announcement, $announcement->toReadArray());
            }
        }
    }

    /** Shared shape for the backend views and the JSON API's serializer. */
    public function toReadArray(): array
    {
        return [
            'id'            => (int) $this->id,
            'title'         => $this->title,
            'body'          => $this->body,
            'author_user_id' => $this->author_user_id !== null ? (int) $this->author_user_id : null,
            'audience'      => $this->audience,
            'severity'      => $this->severity,
            'published_at'  => $this->published_at,
            'expires_at'    => $this->expires_at,
            'source'        => $this->source,
            'external_id'   => $this->external_id,
            'source_url'    => $this->source_url,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}
