<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

use Announcements\Feeds\EventMapper;
use Announcements\Feeds\FeedConfig;
use Announcements\Feeds\FeedPoller;
use Announcements\Feeds\ModelAnnouncementStore;
use Announcements\Feeds\StatuspageClient;

// Lives in the announcements plugin but declares the base cli module's
// task namespace, contributed via this plugin's composer.json psr-4
// entry ("App_skeleton\\Modules\\Cli\\Tasks\\": "src/tasks/") — the same
// mechanism marketing-module's Campaign*Task classes use, for the same
// reason: Phalcon's CLI dispatcher and CronRunner both resolve a task as
// a fixed App_skeleton\Modules\Cli\Tasks\{Studly}Task name and have no
// notion of a module's own namespace.

/**
 * Usage: ./run announcements-feeds poll
 *
 * Cron: task 'announcements-feeds', action 'poll', every 30 minutes
 * (seeded by migration 002). Polls each provider status feed in
 * src/Feeds/feeds.php and mirrors the relevant events into the
 * announcements table — see FeedPoller for the per-feed algorithm and
 * feeds.php for the matching rules and the auto-publish flag.
 *
 * Output is one summary line (CronRunner stores stdout as the run's
 * last_output), preceded by one "FAILED ..." line per feed/endpoint that
 * couldn't be read. A failing feed never aborts the run: it is logged
 * and the remaining feeds still poll.
 */
class AnnouncementsFeedsTask extends \Phalcon\Cli\Task
{
    public function mainAction(): void
    {
        echo 'Usage: ./run announcements-feeds poll' . PHP_EOL;
    }

    public function pollAction(): void
    {
        // Announcement is a bare/global model discovered by
        // Announcements\Module::registerAutoloaders() only when Phalcon
        // dispatches a web request into this module — never the case
        // for the CLI, nor for the backend's "Run now" button, which
        // dispatches into the backend module. Load it directly, same as
        // tests/bootstrap.php does.
        if (!class_exists(\Announcement::class)) {
            require_once dirname(__DIR__) . '/models/Announcement.php';
        }

        $feeds  = FeedConfig::all();
        $poller = new FeedPoller(new StatuspageClient(), new ModelAnnouncementStore(), new EventMapper());

        $totals   = ['polled' => 0, 'matched' => 0, 'inserted' => 0, 'updated' => 0, 'resolved' => 0];
        $failures = [];

        foreach ($feeds as $key => $feed) {
            try {
                $result = $poller->poll((string) $key, $feed);
            } catch (\Throwable $e) {
                $failures[] = "{$key}: " . $e->getMessage();
                error_log("announcements-feeds: {$key} failed: " . $e->getMessage());

                continue;
            }

            if ($result['fetched']) {
                $totals['polled']++;
            }

            foreach (['matched', 'inserted', 'updated', 'resolved'] as $counter) {
                $totals[$counter] += $result[$counter];
            }

            foreach ($result['failures'] as $failure) {
                $failures[] = $failure;
                error_log('announcements-feeds: ' . $failure);
            }
        }

        foreach ($failures as $failure) {
            echo '  FAILED ' . $failure . PHP_EOL;
        }

        echo sprintf(
            'Polled %d/%d feeds; %d events matched; %d inserted, %d updated, %d resolved; %d failure(s).',
            $totals['polled'],
            count($feeds),
            $totals['matched'],
            $totals['inserted'],
            $totals['updated'],
            $totals['resolved'],
            count($failures)
        ) . PHP_EOL;
    }
}
