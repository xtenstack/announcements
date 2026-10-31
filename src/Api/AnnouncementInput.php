<?php
declare(strict_types=1);

namespace Announcements\Api;

/**
 * Validation for POST /api/announcements, factored out of the controller
 * so the rules are unit-testable without the Docker stack (the
 * controller itself can't be exercised in-process — see
 * tests/bootstrap.php). Pure: takes the decoded JSON body, returns the
 * cleaned values plus a field => message map of every problem found, so
 * a caller sees all its mistakes in one 422 rather than one per round
 * trip.
 *
 * Severity uses Announcement::SEVERITIES plus its aliases ('warning' →
 * notice, 'critical' → important) — duplicated here as constants rather
 * than calling the model so this class stays loadable without Phalcon.
 */
final class AnnouncementInput
{
    public const TITLE_MAX       = 200;   // announcements.title VARCHAR(200)
    public const SOURCE_MAX      = 40;    // announcements.source VARCHAR(40)
    public const EXTERNAL_ID_MAX = 160;   // announcements.external_id VARCHAR(160)
    public const SOURCE_URL_MAX  = 2000;

    public const SEVERITIES       = ['info', 'notice', 'important'];
    public const SEVERITY_ALIASES = ['warning' => 'notice', 'critical' => 'important'];

    /** Keys of Audience::LABELS; see Audience for what each one means. */
    public const AUDIENCES = [Audience::EVERYONE, Audience::ALL, Audience::STAFF];

    /**
     * @param array<string, mixed> $body decoded JSON body
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public static function validate(array $body): array
    {
        $errors = [];

        $title = self::stringField($body, 'title', $errors);

        if ($title === '') {
            $errors['title'] ??= 'title is required';
        } elseif (mb_strlen($title) > self::TITLE_MAX) {
            $errors['title'] = 'title must be at most ' . self::TITLE_MAX . ' characters';
        }

        $text = self::stringField($body, 'body', $errors);

        if ($text === '') {
            $errors['body'] ??= 'body is required';
        }

        $severity = 'info';

        if (array_key_exists('severity', $body) && $body['severity'] !== null && $body['severity'] !== '') {
            $normalized = is_string($body['severity']) ? self::normalizeSeverity($body['severity']) : null;

            if ($normalized === null) {
                $errors['severity'] = 'severity must be one of ' . implode(', ', self::SEVERITIES) . ' (aliases: ' . implode(', ', array_keys(self::SEVERITY_ALIASES)) . ')';
            } else {
                $severity = $normalized;
            }
        }

        $audience = Audience::ALL;

        if (array_key_exists('audience', $body) && $body['audience'] !== null && $body['audience'] !== '') {
            $candidate = is_string($body['audience']) ? Audience::normalize($body['audience']) : null;

            if ($candidate === null) {
                $errors['audience'] = 'audience must be one of ' . implode(', ', self::AUDIENCES)
                    . ' (aliases: ' . implode(', ', array_keys(Audience::ALIASES)) . ')';
            } else {
                $audience = $candidate;
            }
        }

        $publish = false;

        if (array_key_exists('publish', $body) && $body['publish'] !== null) {
            $flag = self::toBool($body['publish']);

            if ($flag === null) {
                $errors['publish'] = 'publish must be a boolean';
            } else {
                $publish = $flag;
            }
        }

        $expiresAt = null;

        if (array_key_exists('expires_at', $body) && $body['expires_at'] !== null && $body['expires_at'] !== '') {
            $expiresAt = is_string($body['expires_at']) ? self::normalizeExpiresAt($body['expires_at']) : null;

            if ($expiresAt === null) {
                $errors['expires_at'] = 'expires_at must be Y-m-d or Y-m-d H:i:s';
            }
        }

        $source     = self::optionalString($body, 'source', self::SOURCE_MAX, $errors);
        $externalId = self::optionalString($body, 'external_id', self::EXTERNAL_ID_MAX, $errors);
        $sourceUrl  = self::optionalString($body, 'source_url', self::SOURCE_URL_MAX, $errors);

        if ($externalId !== null && $source === null && !isset($errors['source'])) {
            $errors['source'] = 'source is required when external_id is given';
        }

        if ($source !== null && !preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]*$/', $source)) {
            $errors['source'] = 'source may only contain letters, digits, ":", ".", "_" and "-"';
        }

        if ($sourceUrl !== null && !preg_match('#^https?://\S+$#', $sourceUrl)) {
            $errors['source_url'] = 'source_url must be an http(s) URL';
        }

        return [
            'values' => [
                'title'       => $title,
                'body'        => $text,
                'severity'    => $severity,
                'audience'    => $audience,
                'publish'     => $publish,
                'expires_at'  => $expiresAt,
                'source'      => $source,
                'external_id' => $externalId,
                'source_url'  => $sourceUrl,
            ],
            'errors' => $errors,
        ];
    }

    public static function normalizeSeverity(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = self::SEVERITY_ALIASES[$value] ?? $value;

        return in_array($value, self::SEVERITIES, true) ? $value : null;
    }

    /**
     * A bare date means "current for the whole of that day", so it is
     * stored as end-of-day — the same rule the backend form applies
     * (AnnouncementsController::fillFromRequest()). A full timestamp is
     * taken as given. Both are strict: 2026-02-30 is rejected.
     */
    public static function normalizeExpiresAt(string $value): ?string
    {
        $value = trim($value);

        $date = \DateTime::createFromFormat('!Y-m-d', $value);

        if ($date !== false && $date->format('Y-m-d') === $value) {
            return $value . ' 23:59:59';
        }

        $stamp = \DateTime::createFromFormat('Y-m-d H:i:s', $value);

        if ($stamp !== false && $stamp->format('Y-m-d H:i:s') === $value) {
            return $value;
        }

        return null;
    }

    private static function stringField(array $body, string $field, array &$errors): string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return '';
        }

        if (!is_string($body[$field])) {
            $errors[$field] = $field . ' must be a string';

            return '';
        }

        return trim($body[$field]);
    }

    private static function optionalString(array $body, string $field, int $max, array &$errors): ?string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return null;
        }

        if (!is_string($body[$field])) {
            $errors[$field] = $field . ' must be a string';

            return null;
        }

        $value = trim($body[$field]);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            $errors[$field] = $field . ' must be at most ' . $max . ' characters';

            return null;
        }

        return $value;
    }

    private static function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $result = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $result;
        }

        return null;
    }
}
