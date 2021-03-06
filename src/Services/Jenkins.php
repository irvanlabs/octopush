<?php

namespace Services;
use Models\JobStatus,
    Library\HttpRequest;

class Jenkins
{
    private $_host;
    private $_token;
    private $_jobs;
    private $_log;

    public function __construct($config, $log)
    {
        $this->_host = $config['jenkins']['host'];
        $this->_token = $config['jenkins']['token'];
        $this->_jobs = $config['jenkins']['jobs'];
        $this->_log = $log;
        $this->_log->addInfo("New Jenkins instance created");
    }

    public function push($job)
    {
        $url = $this->_getUrlForJob($job);
        $url .= '/buildWithParameters';
        $data = array('ENVIRONMENT' => $job->getTargetEnvironment(),
            'COMPONENT' => $job->getTargetModule(),
            'VERSION' =>  $job->getTargetVersion());
        $buildNro = $this->_doPush($job, $url, $data);
        $job->setDeploymentJobId(str_replace("buildWithParameters", $buildNro, $url));
        return ($buildNro>0);
    }

    public function pushLive($job)
    {
        $url = $this->_getLiveUrlForJob($job);
        $url .= '/buildWithParameters';
        $data = array('ENVIRONMENT' => $job->getTargetEnvironment(),
            'COMPONENT' => $job->getTargetModule(),
            'VERSION' =>  $job->getTargetVersion());
        if ($job->isARollback())
            $data['wait'] = "0";

        $buildNro = $this->_doPush($job, $url, $data);
        $job->setLiveJobId(str_replace("buildWithParameters", $buildNro, $url));
        return ($buildNro>0);
    }

    public function getLastBuildStatus($job)
    {
        $url = $job->getDeploymentJobId();
        $url .= "/api/json";
        $rawResponse = $this->_send($url);
        $jsonResponse = json_decode($rawResponse, true);

        return $jsonResponse['result'];
    }

    public function getLastBuildStatusLive($job)
    {
        $url = $job->getLiveJobId();
        $url .= "/api/json";
        $rawResponse = $this->_send($url);
        $jsonResponse = json_decode($rawResponse, true);

        return $jsonResponse['result'];
    }

    public function getLastBuildId($job)
    {
        $url = "";
        if ( ($job->getStatus() == JobStatus::QUEUED) ||
                ($job->getStatus() == JobStatus::DEPLOYING)) {
            $url = $this->_getUrlForJob($job);
        } else {
            $url = $this->_getLiveUrlForJob($job);
        }
        $url .= '/lastBuild/api/json';
        $this->_log->addInfo("GettingLastBuild:" . $url);
        $rawResponse = $this->_send($url);
        $jsonResponse = json_decode($rawResponse, true);

        return $jsonResponse['number'];
    }

    public function notifyResult($job, $status)
    {
        $url = 'http://' . $job->getRequestorJenkins() . "/job/";
        $url .= "/{$this->_jobs['notifications']}/";
        $url .= 'buildWithParameters?';
        $url .= 'env=' . $job->getTargetEnvironment();
        $url .= '&repo=' . $job->getTargetModule();
        $url .= '&revision=' . $job->getTargetVersion();
        $url .= '&status=' . $status;
        $url .= '&jobId=' . $job->getId();

        return $this->_send($url);
    }

    public function ping()
    {
        $url = $this->_host;
        $httpRequest = new HttpRequest($url);
        $rawResponse = $httpRequest->send();
        if ($httpRequest->getResponseCode() != 200) {
            throw new \Exception();
        }
        return true;
    }

    private function _send($url)
    {
        try {
            $httpRequest = new HttpRequest($url);
            $this->_log->addInfo("Calling Jenkins:" . $url);
            $response = $httpRequest->send();
            $this->_log->addInfo("Response:" . $httpRequest->getResponseCode());
            if ($httpRequest->getResponseCode() > 400) {
                $this->_log->addError("Error while calling jenkins: " . $url);
                throw new \Exception();
            }
        } catch (\Exception $e) {
            $this->_log->addError($e->getMessage());
            throw $e;
        }

        return $response;
    }

    public function getPreProdJobDeployUrl($job)
    {
        $url = $job->getDeploymentJobId();

        return $url;
    }

    public function getRequestorJobConsoleUrl($job)
    {
        $url = $job->getRequestorJenkins();

        return empty($url) ? "" : $url . "/console";
      }

    public function getTestJobConsoleUrl($job)
    {
        $url = $job->getTestJobUrl();

        return empty($url) ? "" : $url . "/console";
    }

    public function getLiveJobDeployUrl($job)
    {
        $url = $job->getLiveJobId();

        return $url;
    }

    public function getCustomJob($job, $prefix)
    {
        return $this->_host . "/job/" .
            $prefix . "_" . $job->getTargetModule() . "/";
    }

    public function customJobExists($job, $prefix)
    {
        $httpRequest = new HttpRequest($this->getCustomJob($job, $prefix));
        $rawResponse = $httpRequest->send();
        return ($httpRequest->getResponseCode() == 200);
    }

    private function _getUrlForJob($job)
    {
      if ($this->customJobExists($job, $this->_jobs['prefix']))
        return $this->getCustomJob($job, $this->_jobs['prefix']);
      else
        return $this->_host . "/job/" . $this->_jobs['prefix']; //. $job->getTargetModule();
    }

    private function _getLiveUrlForJob($job)
    {
      if ($this->customJobExists($job, $this->_jobs['live.prefix']))
        return $this->getCustomJob($job, $this->_jobs['live.prefix']);
      else
        return $this->_host . "/job/" . $this->_jobs['live.prefix']; //. $job->getTargetModule();
    }

    private function _doPush($job, $pushUrl, $data)
    {
        try {
            $currentBuildId = 0;
            $lastBuildId = $this->getLastBuildId($job);
            $this->_log->addInfo("lastBuildId: " . $lastBuildId);
            $req = new HttpRequest($pushUrl);
            $data['token'] = $this->_token;
            $req->addPostFields($data);
            $this->_log->addInfo("About to call JenkinsRM to queue job: " . $pushUrl);
            $req->send();
            $this->_log->addInfo("Response:" . $req->getResponseCode());
            //$this->_log->addInfo("Response:" . $req->getResponseBody());
            if ($req->getResponseCode() > 400) {
                $this->_log->addError("Error while calling jenkins: " . $req->getUrl());
                throw new \Exception();
            }
            while ($lastBuildId>=$currentBuildId) {
                $this->_log->addInfo("lastBuildId: " . $lastBuildId);
                $this->_log->addInfo("currentBuildId: " . $currentBuildId);
                sleep(2);
                $buildId = $this->getLastBuildId($job);
                if (is_numeric($buildId))
                    $currentBuildId = $buildId;
            }
            $this->_log->addInfo("currentBuildIdAssigned: " . $job->getDeploymentJobId());

            return $buildId;
        } catch (\Exception $ex) {
            $this->_log->addError("Error while pushing Job to Jenkins RM:" . $ex->getMessage());
            error_log($ex->getMessage());
            return 0;
        }

    }
}
