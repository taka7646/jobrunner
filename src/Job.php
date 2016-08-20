<?php
namespace Taka7646\JobRunner;

class Job
{

    protected $processId;

    protected $jobId;

    protected $cmd;

    protected $config;

    protected $lockFp;

    /**
     * ジョブ情報
     * [
     * 'config' => [
     * ]
     * 'jobs' = [
     * <JobId> => [
     * 'cmd' => 実行コマンド,
     * 'log' => ログファイルパス,
     * 'startAt' => 'Y-m-d H:i:s',
     * 'pid' => プロセスID
     * 'retCode' => プロセスの終了コード　jobが終了する前はプロパティが存在しない
     * ],
     * ]
     * ]
     * 
     * @var array
     */
    protected $jobInfo;

    /**
     * 設定の初期値
     * work_dir 作業ディレクトリパス　このディレクトリ以下にジョブ情報ファイル、ログファイルが出力されます
     * group ジョブグループ グループ単位で並列制御されます。
     * parallel 最大並列数 0の場合は制限なし
     * clean_up_second 古いジョブ情報を削除するまでの時間(秒) 0の場合は削除しない
     */
    const DEFAULT_CONFIG = [
        'work_dir' => '/tmp/jobrunner',
        'group' => "default",
        'parallel' => 1,
        'clean_up_second' => 30 * 60
    ];

    const LOCK_RETRY_COUNT = 5;

    const STATE_NOT_FOUND = 'not found';

    const STATE_COMPLETE = 'complete';

    const STATE_RUNNING = 'running';

    private function getPath($path)
    {
        return rtrim($this->config['work_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }

    private function lock()
    {
        if ($this->lockFp) {
            return false;
        }
        $lockFile = $this->getPath($this->config['group'] . ".lock");
        $this->lockFp = fopen($lockFile, "a+");
        for ($i = 0;; $i ++) {
            if (flock($this->lockFp, LOCK_EX | LOCK_NB)) {
                break;
            } else {
                if ($i >= self::LOCK_RETRY_COUNT) {
                    fclose($this->lockFp);
                    $this->lockFp = null;
                    throw new \RuntimeException("Coundn't have a lock");
                } else {
                    usleep(2000);
                }
            }
        }
        return true;
    }

    private function unlock()
    {
        if (! $this->lockFp) {
            return false;
        }
        flock($this->lockFp, LOCK_UN);
        fclose($this->lockFp);
        $this->lockFp = null;
    }

    private function loadJobInfo()
    {
        $infoFile = $this->getPath($this->config['group'] . ".json");
        if (! is_file($infoFile)) {
            return [
                'jobs' => []
            ];
        }
        $fp = fopen($infoFile, "rb");
        if (flock($fp, LOCK_EX)) {
            $len = filesize($infoFile);
            if ($len) {
                $data = fread($fp, $len);
                $info = json_decode($data, true);
            } else {
                $info = [];
            }
            flock($fp, LOCK_UN);
            fclose($fp);
            $this->jobInfo = $info;
            return $info;
        } else {
            fclose($fp);
            throw new \RuntimeException("Coundn't have a lock");
        }
    }

    /**
     * 実行中のジョブの数を求める.
     * 
     * @param array $jobInfo            
     */
    private function getRunningCount()
    {
        $count = 0;
        foreach ($this->jobInfo['jobs'] as $jobId => $job) {
            if (! isset($job['retCode'])) {
                $count ++;
            }
        }
        return $count;
    }

    /**
     * 古いジョブ情報を削除する
     * 
     * @param array $jobs
     *            ジョブリスト
     * @param integer $cleanUpSecond
     *            削除するまでの時間
     * @return array クリーンアップ後のジョブリスト
     */
    private function cleanUp(array $jobs, $cleanUpSecond)
    {
        $oldTime = new \DateTime();
        $oldTime->modify("-{$cleanUpSecond} second");
        $afterJobs = [];
        foreach ($jobs as $jobId => $job) {
            $jobTime = new \DateTime($job['startAt']);
            if ($jobTime > $oldTime) {
                $afterJobs[$jobId] = $job;
                continue;
            }
            unlink($job['log']);
        }
        return $afterJobs;
    }

    /**
     * ジョブ情報を更新する
     * 
     * @param string $jobId
     *            ジョブID
     * @param array $jobData
     *            更新するジョブ情報
     * @throws \RuntimeException
     */
    private function updateJobInfo($jobId, array $jobData)
    {
        $infoFile = $this->getPath($this->config['group'] . ".json");
        $fp = fopen($infoFile, "a+");
        if (flock($fp, LOCK_EX)) {
            $len = filesize($infoFile);
            if ($len) {
                $data = fread($fp, $len);
                $info = json_decode($data, true);
            } else {
                $info = [];
            }
            $info['config'] = $this->config;
            $info['jobs'][$jobId] = $jobData;
            
            if ($this->config['clean_up_second'] > 0) {
                $info['jobs'] = $this->cleanUp($info['jobs'], $this->config['clean_up_second']);
            }
            
            $data = json_encode($info);
            ftruncate($fp, 0);
            fwrite($fp, $data);
            flock($fp, LOCK_UN);
            fclose($fp);
            clearstatcache();
            return $info;
        } else {
            fclose($fp);
            throw new \RuntimeException("Coundn't have a lock");
        }
    }

    /**
     * ジョブ実行用のスクリプトをセットアップする.
     * 作業ディレクトリ下にrunner.phpを生成します。
     */
    private function setupRunner()
    {
        $runner = $this->getPath("runner.php");
        if (is_file($runner)) {
            return $runner;
        }
        $data = <<<EOL
<?php
    \$group = \$argv[1];
    \$jobId = \$argv[2];
    \$infoName = __DIR__ . "/{\$group}.json";
    \$info = json_decode(file_get_contents(\$infoName), true);
    \$jobInfo = \$info['jobs'][\$jobId];
    \$cmdLine = \$jobInfo['cmd'] . ' 2>&1 >' . \$jobInfo['log'];
    exec(\$cmdLine, \$out, \$retCode);
    \$fp = fopen(\$infoName, "a+");
    if (flock(\$fp, LOCK_EX)) {
        \$len = filesize(\$infoName);
        \$data = fread(\$fp, \$len);
        \$info = json_decode(\$data, true);
        \$info['jobs'][\$jobId] += [
            'retCode' => \$retCode,
        ];
        \$data = json_encode(\$info);
        ftruncate(\$fp, 0);
        fwrite(\$fp, \$data);
    }
    fclose(\$fp);
EOL;
        file_put_contents($runner, $data);
        return $runner;
    }

    public function __construct(array $config = [])
    {
        $this->config = $config + self::DEFAULT_CONFIG;
        $this->jobId = uniqid();
        $this->jobInfo = [];
    }

    public function __destruct()
    {
        $this->unlock();
    }

    /**
     * ジョブを実行します.
     * 実際のジョブ実行は、runner.phpが行います。
     * このメソッドではジョブのパラメータを保存して、runner.phpをバックグラウンド実行します。
     *
     * @param string $cmd
     *            コマンド名
     * @param array $params
     *            コマンドに与えるパラメータ
     */
    public function run($cmd, array $params = [])
    {
        if (! is_dir($this->config['work_dir'])) {
            mkdir($this->config['work_dir']);
        }
        $runner = $this->setupRunner();
        $this->lock();
        $cmdLine = $cmd . ' ' . implode(' ', $params);
        $info = $this->loadJobInfo();
        if ($this->config['parallel'] > 0) {
            $count = $this->getRunningCount();
            if ($count >= $this->config['parallel']) {
                throw new \RuntimeException('Parallel Job is over limit!! running:' . $count);
            }
        }
        $logFile = $this->getPath($this->jobId . ".log");
        $cmdLine = escapeshellcmd($cmdLine);
        $out = [];
        $retCode = 0;
        $shellCmd = "nohup php $runner " . implode(' ', [
            $this->config['group'],
            $this->jobId
        ]) . " > /dev/null 2>&1 & echo $!";
        $pid = exec($shellCmd, $out, $retCode);
        $this->updateJobInfo($this->jobId, [
            'cmd' => $cmdLine,
            'log' => $logFile,
            'startAt' => date('Y-m-d H:i:s'),
            'pid' => $pid
        ]);
        $this->unlock();
        return $this->jobId;
    }

    /**
     * 指定Jobの状態を取得します
     * 
     * @param string $jobId            
     * @return array ジョブ状態情報
     *         [
     *         'state' => 状態文字列 STATE_NOT_FOUND, STATE_NOT_COMPLETE, STATE_RUNNING
     *         'code' => プロセスの終了コード
     *         ]
     */
    public function getJobStatus($jobId)
    {
        $info = $this->loadJobInfo();
        $jobs = $info['jobs'];
        if (! isset($jobs[$jobId])) {
            // 指定のJOBが存在しない。
            // jobIdが間違っているか、すでにcleanUpされています。
            return [
                'state' => self::STATE_NOT_FOUND,
                'code' => 0
            ];
        }
        $job = $jobs[$jobId];
        if (isset($job['retCode'])) {
            return [
                'state' => self::STATE_COMPLETE,
                'code' => $job['retCode']
            ];
        }
        return [
            'state' => self::STATE_RUNNING,
            'code' => 0
        ];
    }

    /**
     * 指定JOBのログを取得する.
     *
     * @param string $jobId
     *            ジョブID
     * @param number $offset
     *            ログファイルのオフセット
     * @return string ログ
     */
    public function getJobLog($jobId, $offset = 0)
    {
        $info = $this->loadJobInfo();
        $jobs = $info['jobs'];
        if (! isset($jobs[$jobId])) {
            return "";
        }
        $job = $jobs[$jobId];
        if (! file_exists($job['log'])) {
            return "";
        }
        $fp = fopen($job['log'], "rb");
        $len = filesize($job['log']);
        fseek($fp, $offset);
        $data = fread($fp, $len);
        fclose($fp);
        return $data;
    }
}
