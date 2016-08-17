<?php
namespace Taka7646\JobRunner;

class JobTest extends \PHPUnit_Framework_TestCase
{

    public function testJob()
    {
        $job = new Job([
            'parallel' => 1,
        ]);
        $jobId = $job->run("./sample.sh", ['hoge']);
        $this->assertNotEmpty($jobId);

        // 多重実行チェック
        try {
            $job->run("./sample.sh", ['hoge']);
            throw new \Exception("error");
        } catch (\RuntimeException $e) {
           $this->assertStringStartsWith("Parallel", $e->getMessage());
        }
    }
}
