<?php
namespace Taka7646\JobRunner;

class Job
{

    protected $processId;

    protected $jobId;

    protected $cmd;

    protected $config;

    protected $lockFp;

    const DEFAULT_CONFIG = [
        'work_dir' => '/tmp/jobrunner',
        'group' => "default"
    ];
    const LOCK_RETRY_COUNT = 5;

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
        for($i = 0; ;$i++ ){
            if (flock($this->lockFp, LOCK_EX | LOCK_NB)) {
                break;
            } else {
                if($i >= self::LOCK_RETRY_COUNT){
                    fclose($this->lockFp);
                    $this->lockFp = null;
                    throw new \RuntimeException("Coundn't have a lock");
                }else{
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
        fclose($this->lockFp);
        $this->lockFp = null;
    }

    private function loadJobInfo(){
        $infoFile = $this->getPath($this->config['group'].".json");
        if(!is_file($infoFile)){
            return [];
        }
        $fp = fopen($infoFile, "r+");
        if (flock($fp, LOCK_EX)) {
            $contents = fread($fp, filesize($infoFile));
            $info = json_decode($contents, true);
            fclose($fp);
            return $info;
        }else{
            fclose($fp);
            throw new \RuntimeException("Coundn't have a lock");
        }
    }

    public function __construct(array $config = [])
    {
        $this->config = $config + self::DEFAULT_CONFIG;
        $this->jobId = uniqid();
    }

    public function run($cmd, array $params = [])
    {
        if(!is_dir($this->config['work_dir'])){
            mkdir($this->config['work_dir']);
        }
        $this->lock();
        $cmdLine = $cmd . ' ' . implode(' ', $params);
        $info = $this->loadJobInfo();
        $logFile = $this->getPath($this->jobId.".log");
        $cmdLine = escapeshellarg($cmdLine) . " > $logFile" . ' 2>&1 & echo $!';
        echo $cmdLine;
        return $this->jobId;
    }
}
