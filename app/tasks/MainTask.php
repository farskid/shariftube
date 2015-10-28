<?php
namespace Shariftube\Tasks;

use Phalcon\CLI\Task;
use Shariftube\Models\Files;
use Shariftube\Models\Servers;
use Shariftube\Models\Users;

class MainTask extends Task
{
    public function mainAction()
    {
        $current_time = time();
        foreach ($this->config->crons as $cron => $time) {
            $time = array_values(array_filter(explode(' ', $time)));
            if (count($time) != 5) {
                continue;
            }
            $test = 1;
            foreach ($time as $index => $value) {
                switch ($index) {
                    case 0:
                        $target = intval(date('i', $current_time)); // minute
                        break;
                    case 1:
                        $target = date('G', $current_time); //hour
                        break;
                    case 2:
                        $target = date('j', $current_time); //day of month
                        break;
                    case 3:
                        $target = date('n', $current_time); //month
                        break;
                    case 4:
                        $target = date('w', $current_time); //day of week
                        break;
                }
                $value = array_filter(explode(',', $value));
                if (empty($value)) {
                    $test = 0;
                    break;
                }
                $test = count($value);
                foreach ($value as $val) {
                    $val = preg_replace('/\s+/', '', $val);
                    if ($val != '*') {
                        if (strpos($val, '-') !== false) {
                            $val = array_values(array_filter(explode('-', $val)));
                            if (count($val) != 2 || $target < $val[0] || $target > $val[1]) {
                                $test--;
                                continue;
                            }
                        } elseif (strpos($val, '/') !== false) {
                            $val = array_values(array_filter(explode('/', $val)));
                            if (count($val) != 2 || $val[0] != '*' || $target % $val[1]) {
                                $test--;
                                continue;
                            }
                        } elseif (intval($val) != $target) {
                            $test--;
                            continue;
                        }
                    }
                }
                if ($test <= 0) {
                    break;
                }
            }
            if ($test <= 0) {
                continue;
            }

            echo "Running cron '{$cron}'\n";
            exec(BASE_DIR . "/cli Main {$cron} &> /dev/null &");
        }

        echo "Running Fetch Threads\n";
        for ($i = 1; $i <= $this->config->cli->fetch_threads; $i++) {
            echo "Running fetch thread #{$i}\n";
            exec(BASE_DIR . "/cli Main fetch {$i} &> /dev/null &");
        }
        echo "Running Feed Threads\n";
        for ($i = 1; $i <= $this->config->cli->feed_threads; $i++) {
            echo "Running feed thread #{$i}\n";
            exec(BASE_DIR . "/cli Main feed {$i} &> /dev/null &");
        }
        echo "Running Server transfer Threads\n";
        exec(BASE_DIR . "/cli Main transfer &> /dev/null &");
        echo "Finnish\n";
    }

    public function cleanOldCacheAction()
    {
        if (file_exists(APP_DIR . '/cache/curl')) {
            $dirs = @scandir(APP_DIR . '/cache/curl');
            foreach ($dirs as $file) {
                if (!in_array($file, ['.', '..']) && !is_dir(APP_DIR . '/cache/curl/' . $file)) {
                    if (filemtime(APP_DIR . '/cache/curl/' . $file) < strtotime("-{$this->config->cli->curl_cache_lifetime} Seconds")) {
                        unlink(APP_DIR . '/cache/curl/' . $file);
                    }
                }
            }
        }
    }

    public function userFresherAction()
    {
        $users = Users::find(['deleted_at = 0 AND quota > 0']);
        if ($users) {
            foreach ($users as $user) {
                $user->used = Files::sum([
                    'column' => 'size',
                    'conditions' => 'user_id = :id: AND status IN({status:array})',
                    'bind' => [
                        'id' => $user->getId(),
                        'status' => ['Waiting', 'InProgress', 'Transferring', 'Success'],
                    ],
                ]);
                $user->remain = $user->quota - $user->used;
                $user->save();
            }
        }
    }

    public function transferFilesAction()
    {
        $files = Files::find([
            "deleted_at = 0 AND status = 'Transferring' AND size = fetched"
        ]);
        if ($files) {
            foreach ($files as $file) {
                $dir = date('Ymd', strtotime($file->created_at));
                if (file_exists(APP_DIR . '/cache/files/' . $file->getId() . '/' . $dir)
                    && !file_exists(APP_DIR . '/cache/files/' . $file->getId() . '/' . $dir . '/' . $file->name)
                ) {
                    $file->status = 'Success';
                    $file->save();
                }
            }
        }
    }

    public function removeAction()
    {
        $servers = Servers::find(['deleted_at = 0']);
        if ($servers) {
            foreach ($servers as $server) {
                foreach ($server->ls() as $dir) {
                    if (preg_match('/^[\d]{8}$/', $dir)) {
                        $time = strtotime(substr($dir, 0, 4) . '-' . substr($dir, 4, 2) . '-' . substr($dir, 6, 2));
                        if ($time < strtotime("-{$this->config->cli->delete_after} Days")) {
                            $server->rmdir($dir);
                        }
                    }
                }
                $files = $server->getFiles([
                    'deleted_at = 0 AND created_at < :date:',
                    'bind' => [
                        'date' => date('Y-m-d', strtotime("-{$this->config->cli->delete_after} Days")),
                    ],
                ]);
                if ($files) {
                    $files->delete();
                }
                $server->used = Files::sum([
                    'column' => 'size',
                    'conditions' => 'server_id = :id: AND deleted_at = 0',
                    'bind' => [
                        'id' => $server->getId(),
                    ],
                ]);
                $server->remain = $server->quota - $server->used;
                if ($server->remain < ($this->config->cli->pause_server_remain * 1024 * 1024)) {
                    $server->enable = 'No';
                } else {
                    $server->enable = 'Yes';
                }
                $server->save();
            }
        }
    }

    public function transferAction()
    {
        set_time_limit(0);
        $lock = fopen(APP_DIR . '/cache/locks/transfer.lock', 'w+');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            echo "Server Transfer thread is already running\n";
            return;
        }
        while (!file_exists(APP_DIR . '/cache/locks/transfer.shutdown')) {
            $dirs = @scandir(APP_DIR . '/cache/files');
            $list = array();
            foreach ($dirs as $dir) {
                if (is_numeric($dir) && is_dir(APP_DIR . '/cache/files/' . $dir)) {
                    $subdirs = @scandir(APP_DIR . '/cache/files/' . $dir);
                    foreach ($subdirs as $subdir) {
                        if (is_numeric($subdir) && is_dir(APP_DIR . '/cache/files/' . $dir . '/' . $subdir)) {
                            $tmp = @scandir(APP_DIR . '/cache/files/' . $dir . '/' . $subdir);
                            foreach ($tmp as $temp) {
                                if (!is_dir(APP_DIR . '/cache/files/' . $dir . '/' . $temp)) {
                                    $list[] = $dir;
                                    break;
                                }
                            }
                        }
                    }

                }
            }

            if (!empty($list)) {
                $list = array_values($list);
                $servers = Servers::find([
                    'id IN ({ids:array})',
                    'bind' => [
                        'ids' => $list,
                    ],
                ]);

                foreach ($servers as $server) {
                    $transfered = $server->transfer(APP_DIR . '/cache/files/' . $server->getId() . '/', '');
                    if (!empty($transfered)) {
                        $files = $server->getFiles([
                            "name IN ({name:array}) AND status = 'Transferring'",
                            'bind' => [
                                'name' => $transfered,
                            ],
                        ]);

                        if ($files) {
                            foreach ($files as $file) {
                                $file->status = 'Success';
                                $file->save();
                            }
                        }
                    }
                }
            }

            for ($i = 0; $i < $this->config->cli->transfer_delays; $i++) {
                sleep(1);
                if (file_exists(APP_DIR . '/cache/locks/transfer.now')) {
                    unlink(APP_DIR . '/cache/locks/transfer.now');
                    break;
                }
                if (file_exists(APP_DIR . '/cache/locks/transfer.shutdown')) {
                    break;
                }
            }
        }
        unlink(APP_DIR . '/cache/locks/transfer.shutdown');
        fclose($lock);
        echo "Thread stops by shutdown signal\n";
    }

    public function feedAction($params = array())
    {
        set_time_limit(0);
        if (!isset($params[0]) || !is_numeric($params[0])) {
            echo "Invalid fetch id\n";
            return;
        }
        $id = intval($params[0]);
        $lock = fopen(APP_DIR . '/cache/locks/feed' . $id . '.lock', 'w+');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            echo "Feed thread #{$id} is already running\n";
            return;
        }

        while (!file_exists(APP_DIR . '/cache/locks/feed' . $id . '.shutdown')) {
            do {
                $files = Files::find([
                    "status = 'Waiting' OR (status = 'InProgress' AND locked_at < :time:)",
                    'bind' => [
                        'time' => date('Y-m-d H:i:s', time() - 60),
                    ],
                ]);
                if (!empty($files)) {
                    foreach ($files as $file) {
                        if (!$this->redis->sismember('sharifFiles', $file->getId()) && !$this->redis->sismember('sharifSelected', $file->getId())) {
                            $file->locked_at = date('Y-m-d H:i:s');
                            $file->status = 'InProgress';
                            if ($file->save()) {
                                $this->redis->sadd('sharifFiles', [$file->getId()]);
                                $this->redis->set('sharifFile:' . $file->getId(), serialize($file));
                            }
                        }
                    }
                }
                sleep($this->config->cli->feed_delays);
            } while (!file_exists(APP_DIR . '/cache/locks/feed' . $id . '.shutdown') && count($files));
            sleep($this->config->cli->feed_delays);
        }
        unlink(APP_DIR . '/cache/locks/feed' . $id . '.shutdown');
        fclose($lock);
        echo "Thread stops by shutdown signal\n";
    }

    public function fetchAction($params = array())
    {
        set_time_limit(0);
        if (!isset($params[0]) || !is_numeric($params[0])) {
            echo "Invalid fetch id\n";
            return;
        }
        $id = intval($params[0]);
        $lock = fopen(APP_DIR . '/cache/locks/fetch' . $id . '.lock', 'w+');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            echo "Fetch thread #{$id} is already running\n";
            return;
        }

        while (!file_exists(APP_DIR . '/cache/locks/fetch' . $id . '.shutdown')) {
            while (!file_exists(APP_DIR . '/cache/locks/fetch' . $id . '.shutdown') && ($fileId = $this->redis->srandmember('sharifFiles'))) {
                $this->redis->smove('sharifFiles', 'sharifSelected', $fileId);
                $file = unserialize($this->redis->get('sharifFile:' . $fileId));
                set_time_limit(0);
                $website = $file->getWebsite();
                if (!$website || !class_exists('\\Shariftube\\Websites\\' . $website->name)) {
                    if ($file->setFailed() && $file->save()) {
                        $this->redis->del('sharifFile:' . $fileId);
                        $this->redis->srem('sharifSelected', $fileId);
                    } else {
                        $this->redis->smove('sharifSelected', 'sharifFiles', $fileId);
                    }
                    continue;
                }

                $leecher = '\\Shariftube\\Websites\\' . $website->name;
                $leecher = new $leecher;
                $result = $leecher->getVideo($file);
                if ($result === null) {
                    continue;
                } elseif (!$result) {
                    if ($file->setFailed() && $file->save()) {
                        $this->redis->del('sharifFile:' . $fileId);
                        $this->redis->srem('sharifSelected', $fileId);
                    } else {
                        $this->redis->smove('sharifSelected', 'sharifFiles', $fileId);
                    }
                    continue;
                }
                $file->status = 'Transferring';
                if ($file->save()) {
                    file_put_contents(APP_DIR . '/cache/locks/transfer.now', '');
                    $this->redis->del('sharifFile:' . $fileId);
                    $this->redis->srem('sharifSelected', $fileId);
                } else {
                    $this->redis->smove('sharifSelected', 'sharifFiles', $fileId);
                }
                die('OK');
            }

            sleep($this->config->cli->fetch_delays);
        }
        unlink(APP_DIR . '/cache/locks/fetch' . $id . '.shutdown');
        fclose($lock);
        echo "Thread stops by shutdown signal\n";
    }
}