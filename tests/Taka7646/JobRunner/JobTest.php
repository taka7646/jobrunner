<?php
namespace Taka7646\JobRunner;

class JobTest extends \PHPUnit_Framework_TestCase
{

    public function testJob()
    {
        $job = new Job();
        $job->run("./sample.sh", ['hoge']);
        $this->assertTrue(true);
    }
}
