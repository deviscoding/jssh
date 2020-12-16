<?php


namespace DevCoding\Mac\Ssh\Drivers;

/**
 * Class JssDriver
 * @package DevCoding\Mac\Ssh\Drivers
 */
class JssDriver
{
  const GET="%s -s -u %s:%s -X GET https://%s/JSSResource/%s/%s";

  private $url;
  private $user;
  private $password;
  private $curl;
  private $projectRoot;

  /**
   * @param string $url
   * @param string $user
   * @param string $password
   * @param string $cUrlPath
   * @param string $projectRoot
   */
  public function __construct($url, $user, $password, $cUrlPath = 'curl', $projectRoot = '../../')
  {
    $this->url         = $url;
    $this->user        = $user;
    $this->password    = $password;
    $this->curl        = $cUrlPath;
    $this->projectRoot = $projectRoot;
  }

  public function getGroup($groupName)
  {
    $endpoint = 'computergroups/name';
    $cmd = sprintf(self::GET, $this->curl, $this->user, $this->password, $this->url, $endpoint, urlencode($groupName));

    return $this->execute($cmd, true);
  }

  public function getComputer($computerName)
  {
    $endpoint = 'computers/name';
    $cmd = sprintf(self::GET, $this->curl, $this->user, $this->password, $this->url, $endpoint, urlencode($computerName));

    return $this->execute($cmd, true);
  }

  protected function execute($cmd, $returnData = false)
  {
    $output = array();
    $retval = null;

    exec($cmd, $output, $retval);

    $out = is_array($output) ? implode("\n",$output) : "";

    if (!$retval === 0 || strpos($out,'<?xml') === false)
    {
      foreach($output as $line)
      {
        $text = strip_tags($line);
        if (strpos($text, 'Error') !== false)
        {
          throw new \Exception($text);
        }
      }

      throw new \Exception('Unknown Error');
    }
    else
    {
      if ($returnData)
      {
        return is_array($output) ? implode("\n", $output) : $output;
      }
      else
      {
        return true;
      }
    }
  }
}