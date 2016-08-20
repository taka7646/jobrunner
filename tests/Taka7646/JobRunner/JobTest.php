<?php
namespace Taka7646\JobRunner;

class JobTest extends \PHPUnit_Framework_TestCase
{

    public function testJob()
    {
        $job = new Job([
            'parallel' => 1,
        ]);
        $jobId = $job->run("./sample.sh", ['1']);
        $this->assertNotEmpty($jobId);

        // 多重実行チェック
        try {
            $job->run("./sample.sh", ['1']);
            throw new \Exception("error");
        } catch (\RuntimeException $e) {
           $this->assertStringStartsWith("Parallel", $e->getMessage());
           // jobm終了待ち
           usleep(1500*1000);
        }
        $state = $job->getJobStatus($jobId);
        $this->assertEquals(0, $state['code']);
    }

    public function testJobLog()
    {
        $job = new Job([
            'parallel' => 1,
        ]);
        $jobId = $job->run("./sample.sh", ['1']);
        usleep(500*1000);
        $data = $job->getJobLog($jobId);
        $this->assertNotEmpty($data);
        $len = strlen($data);
        sleep(1);
        $data = $job->getJobLog($jobId, $len);
        $this->assertNotEmpty($data);
    }
}
