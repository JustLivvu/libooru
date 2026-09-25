<?php
declare(strict_types=1);

function scraperTaskIsRunning(array $task): bool
{
    $pid = (int)($task['pid'] ?? 0);
    $taskId = (int)($task['id'] ?? 0);
    if ($pid < 1 || $taskId < 1) return false;

    $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
    if (!is_string($cmdline) || $cmdline === '') {
        $cmdline = (string)@shell_exec('ps -p ' . $pid . ' -o command= 2>/dev/null');
    }
    $script = basename((string)($task['source'] ?? 'realbooru')) . '.php';
    if (($task['tag'] ?? '') === 'Tags fetcher (all Realbooru posts)') {
        $script = 'realbooru_tags_fetcher.php';
    }

    return str_contains($cmdline, $script)
        && (bool)preg_match('/--task-id(?:=|\s+)' . $taskId . '(?:\s|\x00|$)/', $cmdline);
}

function renderAdminTabs(?array $user, string $active): void
{
    echo '<nav class="admin-tabs" aria-label="Admin sections">';
    echo '<a' . ($active === 'panel' ? ' class="active" aria-current="page"' : '')
        . ' href="' . View::url('/admin') . '">Panel</a>';
    if (Auth::can('manage_scraper', $user)) {
        echo '<a' . ($active === 'scraper' ? ' class="active" aria-current="page"' : '')
            . ' href="' . View::url('/scraper') . '">Scraper</a>';
    }
    echo '</nav>';
}

function page_scraper(?array $user, string $method): void
{
    Auth::requirePermission('manage_scraper');

    if ($method === 'POST') {
        View::verifyCsrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'start') {
            $tag = trim($_POST['tag'] ?? '');
            $source = in_array($_POST['source'] ?? '', ['realbooru', 'e621', 'rule34'], true) ? $_POST['source'] : 'realbooru';
            $blacklist = $source === 'e621' ? trim($_POST['blacklist'] ?? '') : '';
            $canStart = $tag !== '';
            if (!$canStart) View::setFlash('Tag cannot be empty.', 'error');

            if ($source === 'rule34') {
                $submittedUserId = trim($_POST['rule34_user_id'] ?? '');
                $submittedApiKey = trim($_POST['rule34_api_key'] ?? '');
                if ($submittedUserId !== '') {
                    if (ctype_digit($submittedUserId)) {
                        View::setSiteSetting('rule34_user_id', $submittedUserId);
                    } else {
                        View::setFlash('Rule34.xxx User ID must be a number.', 'error');
                        $canStart = false;
                    }
                }
                if ($submittedApiKey !== '') View::setSiteSetting('rule34_api_key', $submittedApiKey);
                if (View::siteSetting('rule34_user_id') === '' || View::siteSetting('rule34_api_key') === '') {
                    View::setFlash('Rule34.xxx User ID and API key are required.', 'error');
                    $canStart = false;
                }
            }

            if ($canStart) {
                $script = LIBOORU_ROOT . '/scrapers/' . $source . '.php';

                DB::exec('INSERT INTO scraper_tasks (tag, source, blacklist, status) VALUES (?, ?, ?, ?)', [$tag, $source, $blacklist, 'starting']);
                $taskId = (int)DB::lastId();
                $logFile = LIBOORU_ROOT . '/data/scraper_' . $taskId . '.log';

                if ($source === 'e621') {
                    $cmd = sprintf(
                        'php %s --tag %s --blacklist %s --task-id %d > %s 2>&1 & echo $!',
                        escapeshellarg($script),
                        escapeshellarg($tag),
                        escapeshellarg($blacklist),
                        $taskId,
                        escapeshellarg($logFile)
                    );
                } else {
                    $cmd = sprintf(
                        'php %s --tag %s --task-id %d > %s 2>&1 & echo $!',
                        escapeshellarg($script),
                        escapeshellarg($tag),
                        $taskId,
                        escapeshellarg($logFile)
                    );
                }
                $pid = (int)shell_exec($cmd);
                if ($pid > 0) {
                    DB::exec('UPDATE scraper_tasks SET pid = ?, status = ? WHERE id = ?', [$pid, 'running', $taskId]);
                    $sourceLabel = match ($source) {
                        'e621' => 'e621',
                        'rule34' => 'Rule34.xxx',
                        default => 'Realbooru',
                    };
                    View::setFlash("Started $sourceLabel scraper for tag: $tag (PID: $pid)", 'ok');
                } else {
                    DB::exec('UPDATE scraper_tasks SET status = ? WHERE id = ?', ['error', $taskId]);
                    View::setFlash("Failed to start scraper process.", 'error');
                }
            }
        } elseif ($action === 'restart') {
            $taskId = (int)($_POST['task_id'] ?? 0);
            $task = DB::row('SELECT * FROM scraper_tasks WHERE id = ?', [$taskId]);
            if (!$task || !in_array($task['source'], ['realbooru', 'e621', 'rule34'], true)) {
                View::setFlash('Scraper task not found.', 'error');
            } elseif (!scraperTaskIsRunning($task)) {
                View::setFlash('The scraper process is no longer running.', 'error');
                DB::exec("UPDATE scraper_tasks SET status = 'completed' WHERE id = ?", [$taskId]);
            } else {
                $oldPid = (int)$task['pid'];
                posix_kill($oldPid, SIGTERM);
                for ($attempt = 0; $attempt < 50 && scraperTaskIsRunning($task); $attempt++) {
                    usleep(100_000);
                }

                if (scraperTaskIsRunning($task)) {
                    View::setFlash('Could not stop the previous scraper process.', 'error');
                } else {
                    $isRealbooruTagFetcher = ($task['tag'] ?? '') === 'Tags fetcher (all Realbooru posts)';
                    $script = $isRealbooruTagFetcher
                        ? LIBOORU_ROOT . '/scrapers/realbooru_tags_fetcher.php'
                        : LIBOORU_ROOT . '/scrapers/' . $task['source'] . '.php';
                    $logFile = LIBOORU_ROOT . '/data/scraper_' . $taskId . '.log';
                    $blacklistArg = $task['source'] === 'e621' && !$isRealbooruTagFetcher
                        ? ' --blacklist ' . escapeshellarg((string)$task['blacklist'])
                        : '';
                    $tagArg = $isRealbooruTagFetcher ? '' : ' --tag ' . escapeshellarg((string)$task['tag']);
                    $cmd = 'php ' . escapeshellarg($script)
                        . $tagArg . $blacklistArg
                        . ' --task-id ' . $taskId
                        . ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
                    $newPid = (int)shell_exec($cmd);
                    if ($newPid > 0) {
                        DB::exec("UPDATE scraper_tasks SET pid = ?, status = 'running' WHERE id = ?", [$newPid, $taskId]);
                        View::setFlash("Restarted scraper task #{$taskId} (PID: {$newPid}).", 'ok');
                    } else {
                        DB::exec("UPDATE scraper_tasks SET status = 'error' WHERE id = ?", [$taskId]);
                        View::setFlash('Could not restart the scraper process.', 'error');
                    }
                }
            }
        } elseif ($action === 'fetch_tags') {
            $script = LIBOORU_ROOT . '/scrapers/realbooru_tags_fetcher.php';
            DB::exec('INSERT INTO scraper_tasks (tag, status) VALUES (?, ?)', ['Tags fetcher (all Realbooru posts)', 'starting']);
            $taskId = (int)DB::lastId();
            $logFile = LIBOORU_ROOT . '/data/scraper_' . $taskId . '.log';
            $cmd = sprintf(
                'php %s --task-id %d > %s 2>&1 & echo $!',
                escapeshellarg($script),
                $taskId,
                escapeshellarg($logFile)
            );
            $pid = (int)shell_exec($cmd);
            if ($pid > 0) {
                DB::exec('UPDATE scraper_tasks SET pid = ?, status = ? WHERE id = ?', [$pid, 'running', $taskId]);
                View::setFlash("Started tags fetcher for all Realbooru posts (PID: $pid)", 'ok');
            } else {
                DB::exec('UPDATE scraper_tasks SET status = ? WHERE id = ?', ['error', $taskId]);
                View::setFlash('Failed to start tags fetcher.', 'error');
            }
        } elseif ($action === 'clean') {
            $completed = DB::rows("SELECT id FROM scraper_tasks WHERE status != 'running'");
            foreach ($completed as $c) {
                @unlink(LIBOORU_ROOT . '/data/scraper_' . $c['id'] . '.log');
            }
            DB::exec("DELETE FROM scraper_tasks WHERE status != 'running'");
            View::setFlash("Cleaned up completed tasks.", 'ok');
        }
        Router::redirect('/scraper');
    }

    $tasks = DB::rows('SELECT * FROM scraper_tasks ORDER BY created_at DESC LIMIT 50');


    foreach ($tasks as &$task) {
        if ($task['status'] === 'running' && $task['pid'] > 0) {



            if (!scraperTaskIsRunning($task)) {
                DB::exec("UPDATE scraper_tasks SET status = 'completed' WHERE id = ?", [$task['id']]);
                $task['status'] = 'completed';
            }
        }
    }
    unset($task);

    View::header('Scraper', $user);
    View::flash();

    $rule34UserId = View::siteSetting('rule34_user_id');
    $rule34ApiConfigured = View::siteSetting('rule34_api_key') !== '';

    renderAdminTabs($user, 'scraper');

    echo '<div class="form-container">';
    echo '<h2>Start New Scraper</h2>';
    echo '<form method="post" action="' . View::url('/scraper') . '" class="scraper-start-form">';
    echo '  <input type="hidden" name="csrf_token" value="' . View::e(View::csrfToken()) . '">';
    echo '  <input type="hidden" name="action" value="start">';
    echo '  <div class="form-group">';
    echo '    <label for="scraper-source">Source</label>';
    echo '    <select id="scraper-source" name="source"><option value="realbooru">Realbooru</option><option value="e621">e621</option><option value="rule34">Rule34.xxx</option></select>';
    echo '  </div>';
    echo '  <div class="form-group">';
    echo '    <label>Tag to scrape</label>';
    echo '    <input type="text" name="tag" required placeholder="e.g. femboy">';
    echo '  </div>';
    echo '  <div class="form-group" id="e621-blacklist" hidden>';
    echo '    <label>Blacklist tags <small>(space, comma or line separated)</small></label>';
    echo '    <textarea name="blacklist" rows="4" placeholder="gore scat feral"></textarea>';
    echo '    <small>e621 posts containing any of these tags will be skipped.</small>';
    echo '  </div>';
    echo '  <div class="form-group" id="rule34-credentials" hidden>';
    echo '    <label>Rule34.xxx User ID</label>';
    echo '    <input name="rule34_user_id" inputmode="numeric" value="' . View::e($rule34UserId) . '" placeholder="Numeric user ID">';
    echo '    <label>Rule34.xxx API key</label>';
    echo '    <input type="password" name="rule34_api_key" value="" autocomplete="new-password" placeholder="' . ($rule34ApiConfigured ? 'Configured — leave blank to keep it' : 'Enter API key') . '">';
    echo '    <small>Generate credentials in Rule34.xxx account options under API Access Credentials.</small>';
    echo '  </div>';
    echo '  <button type="submit" class="button">Start Scraper</button>';
    echo '</form>';
    echo '<script>(() => { const source = document.getElementById("scraper-source"); const blacklist = document.getElementById("e621-blacklist"); const credentials = document.getElementById("rule34-credentials"); const update = () => { blacklist.hidden = source.value !== "e621"; credentials.hidden = source.value !== "rule34"; }; source.addEventListener("change", update); update(); })();</script>';
    echo '</div>';

    echo '<div class="form-container">';
    echo '<h2>Tags fetcher</h2>';
    echo '<p>Rechecks every imported Realbooru post and adds any missing source tags, including yellow model tags. Existing tags are kept.</p>';
    echo '<form method="post" action="' . View::url('/scraper') . '">';
    echo '  <input type="hidden" name="csrf_token" value="' . View::e(View::csrfToken()) . '">';
    echo '  <input type="hidden" name="action" value="fetch_tags">';
    echo '  <button type="submit" class="button" onclick="return confirm(\'Recheck tags for every imported Realbooru post?\')">Start Tags Fetcher</button>';
    echo '</form>';
    echo '</div>';

    echo '<h2>Recent Tasks</h2>';
    if ($tasks) {
        echo '<form method="post" action="' . View::url('/scraper') . '" style="margin-bottom: 10px;">';
        echo '  <input type="hidden" name="csrf_token" value="' . View::e(View::csrfToken()) . '">';
        echo '  <input type="hidden" name="action" value="clean">';
        echo '  <button type="submit" class="button">Clean Completed</button>';
        echo '</form>';

        echo '<table class="data-table">';
        echo '<tr><th>ID</th><th>Source</th><th>Tag</th><th>Blacklist</th><th>PID</th><th>Status</th><th>Started</th><th>Action</th></tr>';
        foreach ($tasks as $t) {
            $statusColor = $t['status'] === 'running' ? 'color: orange;' : 'color: green;';
            echo '<tr>';
            echo '<td>' . $t['id'] . '</td>';
            $taskSource = match ($t['source'] ?? 'realbooru') {
                'e621' => 'e621',
                'rule34' => 'Rule34.xxx',
                default => 'Realbooru',
            };
            echo '<td>' . View::e($taskSource) . '</td>';
            echo '<td>' . View::e($t['tag']) . '</td>';
            echo '<td>' . View::e($t['blacklist'] ?? '') . '</td>';
            echo '<td>' . $t['pid'] . '</td>';
            echo '<td style="font-weight:bold; ' . $statusColor . '">' . View::e($t['status']) . '</td>';
            echo '<td>' . date('Y-m-d H:i:s', $t['created_at']) . '</td>';
            echo '<td><a href="' . View::url('/scraper', ['log_id' => $t['id']]) . '">View Log</a>';
            if ($t['status'] === 'running') {
                echo ' <form method="post" action="' . View::url('/scraper') . '" style="display:inline">';
                View::csrfField();
                echo '<input type="hidden" name="action" value="restart">';
                echo '<input type="hidden" name="task_id" value="' . (int)$t['id'] . '">';
                echo '<button type="submit" onclick="return confirm(\'Restart this scraper from its saved progress?\')">Restart</button>';
                echo '</form>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p>No scraper tasks found.</p>';
    }

    if (isset($_GET['log_id'])) {
        $logId = (int)$_GET['log_id'];
        $logFile = LIBOORU_ROOT . '/data/scraper_' . $logId . '.log';
        echo '<h2 id="log">Log for Task #' . $logId . ' <a href="' . View::url('/scraper') . '">(Close)</a></h2>';
        echo '<div style="background: #111; color: #ccc; padding: 10px; border-radius: 5px; height: 400px; overflow-y: auto; font-family: monospace; white-space: pre-wrap;">';
        if (file_exists($logFile)) {
            echo View::e(file_get_contents($logFile));
        } else {
            echo 'Log file not found or empty.';
        }
        echo '</div>';


        echo '<script>
            var logDiv = document.querySelector("#log").nextElementSibling;
            logDiv.scrollTop = logDiv.scrollHeight;
            location.hash = "#log";
        </script>';
    }

    View::footer();
}
