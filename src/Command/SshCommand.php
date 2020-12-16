<?php

namespace DevCoding\Mac\Ssh\Command;

use DevCoding\Mac\Command\AbstractMacConsole;
use DevCoding\Mac\Ssh\Drivers\JssDriver;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Class SshCommand.
 *
 * @package DevCoding\Mac\Ssh\Command
 */
class SshCommand extends AbstractMacConsole
{
  /** @var JssDriver */
  protected $_Driver;
  /** @var string */
  protected $_projectRoot;

  protected function isAllowUserOption()
  {
    return false;
  }

  protected function configure()
  {
    $d = [
        'command'       => 'Connects to a host via SSH, automatically negotiating needed hostname or IP lookup.',
        'defaultDomain' => 'Default domain name to append to hosts when saved in SSH config.',
        'defaultUser'   => 'Default username to use for connections saved to SSH config.',
        'defaultPort'   => 'Default port to use for connections saved to SSH config.',
        'identity'      => 'Use an identity file when connecting.  Defaults to the default SSH identity.',
        'dotfiles'      => 'Path to "dotfiles" to sync when connected.',
        'jamf'          => 'Use the Jamf API to find additional information',
        'screen'        => 'Reconnect to screen session upon connect.',
    ];

    $this->setName('ssh')
         ->setDescription($d['command'])
         ->addArgument('host')
         ->addOption('defaultDomain', null, InputOption::VALUE_REQUIRED, $d['defaultDomain'])
         ->addOption('defaultUser', null, InputOption::VALUE_REQUIRED, $d['defaultUser'])
         ->addOption('defaultPort', null, InputOption::VALUE_REQUIRED, $d['defaultPort'])
         ->addOption('identity', null, InputOption::VALUE_REQUIRED, $d['identity'])
         ->addOption('dotfiles', null, InputOption::VALUE_REQUIRED, $d['dotfiles'])
         ->addOption('jamf', null, InputOption::VALUE_REQUIRED, $d['jamf'])
         ->addOption('screen', null, InputOption::VALUE_REQUIRED, $d['screen'])
    ;
  }

  protected function interact(InputInterface $input, OutputInterface $output)
  {
    if ($host = $input->getArgument('host'))
    {
      if (preg_match('#^(([^@:]+)@)?([^:]+)(:([0-9]+))?$#', $host, $matches))
      {
        $user = isset($matches[2]) ? $matches[2] : null;
        $host = isset($matches[3]) ? $matches[3] : null;
        $port = isset($matches[5]) ? $matches[5] : null;

        if (preg_match("#^(([^.]+)\.)?((.*)\.([^.]+))$#", $host, $matches))
        {
          $domain = $matches[3];
        }

        $input->setArgument('host', $host);
      }
    }

    $defaultUser   = !empty($user) ? $user : $this->getDefault('username');
    $defaultPort   = !empty($port) ? $port : $this->getDefault('port');
    $defaultDomain = !empty($domain) ? $domain : $this->getDefault('domain');

    if (!empty($defaultDomain) && !$input->getOption('defaultDomain'))
    {
      $input->setOption('defaultDomain', $defaultDomain);
    }

    if (!empty($defaultUser) && !$input->getOption('defaultUser'))
    {
      $input->setOption('defaultUser', $defaultUser);
    }

    if (!$input->getOption('defaultPort'))
    {
      if (!empty($defaultPort))
      {
        $input->setOption('defaultPort', $defaultPort);
      }
      else
      {
        $input->setOption('defaultPort', 22);
      }
    }

    if (!$dotFilesDefault = $input->getOption('dotfiles'))
    {
      if ($config = $this->getConfig())
      {
        if (isset($config['dotfiles']))
        {
          $input->setOption('dotfiles', $config['dotfiles']);
        }
      }
    }
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $this->io()->blankln();
    $host = $this->io()->getArgument('host');
    $fqdn = !empty($host) ? $this->getFullHostname($host) : null;

    if (is_null($fqdn))
    {
      if (false === strpos($host, '.'))
      {
        if ($add = $this->io()->ask('This host is not in your SSH config.  Add it?', 'Y', 2))
        {
          $fqdn = $this->executeSshConfig($host);
        }
      }
      else
      {
        $fqdn = $host;
      }
    }
    elseif (empty($fqdn))
    {
      $fqdn = sprintf('%s.%s', $host, $this->getDefaultDomain());
    }

    $this->io()->msg('Testing Host...', 40);
    if (!$isSsh = $this->isSshAccepted($fqdn))
    {
      $this->io()->errorln('[FAIL]');
      if ($this->isUseJamf())
      {
        $this->io()->msg('Checking Jamf...', 40);
        if ($alt = $this->getAlternateFromJamf($host))
        {
          $this->io()->successln('['.$alt.']');

          $this->io()->msg('Testing Alternate Host...', 40);
          if ($isSsh = $this->isSshAccepted($alt))
          {
            $this->io()->successln('[PASS]');
            $fqdn = $alt;
          }
        }
        else
        {
          $this->io()->errorln('[ERROR]');
        }
      }
    }
    else
    {
      $this->io()->successln('[PASS]');
    }

    if (!$isSsh)
    {
      if ($this->isHostReachable($fqdn))
      {
        $this->io()->blankln()->error('The host ')->write($fqdn)->errorln(' is online, but is not accepting SSH connections');
      }
      else
      {
        if (!$this->isLocalNetwork() && !$this->isUsingVpn() && $this->hasVpn())
        {
          $this->io()->blankln()->errorln('Local network resources cannot be reached.  Should you be connected to the VPN?');
        }
        elseif (!$this->isInternet())
        {
          $this->io()->blankln()->errorln('You may not have access to the internet. Please verify this, then try again.');
        }
        else
        {
          $this->io()->blankln()->error('The host ')->write($fqdn)->errorln(' cannot be reached.');
        }
      }

      $this->io()->blankln();

      return self::EXIT_ERROR;
    }

    // Get CLI Arguments Ready
    $conn = sprintf('%s@%s', $this->getDefaultUser(), $fqdn);

    // Check Identity
    if ($this->isUseIdentity())
    {
      if (!$this->isKeyInstalled($conn))
      {
        $this->io()->msgln(sprintf('Transferring Identity.  Remote Password for %s will be prompted for!', $conn));
        $cmd = sprintf('ssh-copy-id %s', escapeshellarg($conn));
        passthru($cmd);
      }
    }

    // Check Dotfiles
    if ($dotFiles = $this->getDotFiles())
    {
      $dest   = escapeshellarg(sprintf('%s:/Users/%s/', $conn, $this->getDefaultUser()));
      $tmpl   = 'rsync --exclude ".git/" --exclude ".idea/" -a -i --no-perms %s/.??* %s';
      $real   = sprintf($tmpl, $dotFiles, $dest);
      $dryrun = str_replace('--no-perms', '--dry-run --no-perms', $real);

      exec($dryrun, $output, $retval);
      if (0 === $retval)
      {
        if (!empty($output))
        {
          $this->io()->msg('Updating Dotfiles...', 40);
          $output = [];
          exec($real, $output, $retval);
          if (0 === $retval && !empty($output))
          {
            $this->io()->successln('[DONE]');
            foreach ($output as $line)
            {
              $file = explode(' ', $line);
              $this->io()->writeln('  '.$file[1], null, null, OutputInterface::VERBOSITY_VERBOSE);
            }
          }
          else
          {
            $this->io()->errorln('[FAILED]');
          }
        }
      }
    }

    // Do SSH
    $this->io()->msg('Connecting to ');
    $this->io()->write($conn);
    $this->io()->msgln(' ...');
    $this->io()->blankln();
    $conn = escapeshellarg($conn);
    $cmd  = 'ssh '.$conn;
    passthru($cmd);

    return self::EXIT_SUCCESS;
  }

  protected function executeSshConfig($host)
  {
    $defaultDomain = (false === strpos($host, '.')) ? $this->getDefaultDomain() : null;
    $defaultFqdn   = !empty($defaultDomain) ? sprintf('%s.%s', $host, $defaultDomain) : null;
    $defaultHost   = str_replace('.'.$this->getDefaultDomain(), null, $host);

    $alias = $this->io()->ask('What is the alias for this entry?', $defaultHost);
    $fqdn  = $this->io()->ask('What is the fully qualified domain name?', $defaultFqdn, 2);
    $uName = $this->io()->ask('What username should be used to connect?', $this->getDefaultUser(), 2);
    $port  = $this->io()->ask('What port should be used to connect?', $this->getDefaultPort(), 2);

    $this->io()->blankln();
    $this->io()->msg('Adding Host to Config');
    if ($this->addHostToSshConfig($alias, $fqdn, $port, $uName))
    {
      $this->io()->successln('[SUCCESS]');
    }
    else
    {
      $this->io()->errorln('[ERROR]');
    }

    return $fqdn;
  }

  // region //////////////////////////////////////////////// Input Options Methods

  public function getDefaultDomain()
  {
    return $this->io()->getOption('defaultDomain');
  }

  public function getDefaultUser()
  {
    return $this->io()->getOption('defaultUser');
  }

  public function getDefaultPort()
  {
    return $this->io()->getOption('defaultPort');
  }

  /**
   * @return array
   *
   * @throws \Exception
   */
  public function getDotFiles()
  {
    $out = null;
    $in  = $this->io()->getOption('dotfiles');
    if ('false' !== $in && !empty($in))
    {
      if (is_dir($in))
      {
        $out = $in;
      }
    }

    return $out;
  }

  /**
   * @return string|null
   *
   * @throws \Exception
   */
  public function getIdentity()
  {
    $in = $this->io()->getOption('identity');
    if ('false' !== strtolower($in) && !empty($in))
    {
      if (is_file($in))
      {
        return $in;
      }
      else
      {
        throw new \Exception('The specified identity file cannot be read.');
      }
    }
    elseif ($this->isUseIdentity())
    {
      return true;
    }

    return null;
  }

  public function isUseIdentity()
  {
    $config = $this->getConfig();
    if (isset($config['options']['identity']))
    {
      return $config['options']['identity'];
    }

    return false;
  }

  public function isUseJamf()
  {
    if (!$this->io()->getOption('jamf'))
    {
      $config = $this->getConfig();
      if (isset($config['options']['identity']))
      {
        return $config['options']['identity'];
      }
    }
    elseif ('false' != strtolower($this->io()->getOption('jamf')))
    {
      return true;
    }

    return false;
  }

  public function getJamfInfo()
  {
    $in = $this->io()->getOption('jamf');
    if ('false' !== strtolower($in))
    {
      $config = $this->getConfig();

      return !empty($config['jamf']) ? $config['jamf'] : null;
    }

    return null;
  }

  // endregion ///////////////////////////////////////////// End Input Options

  /**
   * @param string $key
   *
   * @return string|array|null
   *
   * @throws \Exception
   */
  public function getDefault($key)
  {
    if ($config = $this->getConfig())
    {
      return isset($config['defaults'][$key]) ? $config['defaults'][$key] : null;
    }

    return null;
  }

  /**
   * @param string $file
   *
   * @return string|string[]|null
   *
   * @throws \Exception
   */
  public function getConfig($file = null)
  {
    if (empty($file))
    {
      $path = sprintf('%s/SSHdash/%s', $this->getUser()->getLibrary(), 'config.yml');
      if (!is_file($path))
      {
        $path = sprintf('%s/resources/config/sshdash.yml', $this->getProjectRoot());
      }
    }
    else
    {
      $path = $file;
    }

    if (is_file($path))
    {
      if ($yaml = file_get_contents($path))
      {
        return Yaml::parse($yaml);
      }
    }

    throw new \Exception('Could not read Yaml config file: '.$file);
  }

  // region //////////////////////////////////////////////// Jamf Info

  /**
   * @param string $host
   *
   * @return string|null
   */
  protected function getAlternateFromJamf($host)
  {
    if ($jamf = $this->getJamfInfo())
    {
      if (!empty($jamf['ea_vpn']))
      {
        if ($ip = $this->getAlternateIpFromJamf($host))
        {
          return $ip;
        }
      }

      if (!empty($jamf['ea_fqdn']))
      {
        if ($jfqdn = $this->getFqdnFromJamf($host))
        {
          return $jfqdn;
        }
      }
    }

    return null;
  }

  /**
   * @return JssDriver
   *
   * @throws \Exception
   */
  protected function getJssDriver()
  {
    if (empty($this->_Driver))
    {
      $jamf = $this->getJamfInfo();
      $user = empty($jamf['username']) ? $this->io()->ask('What is the username for Jamf?', $this->getUser()) : $jamf['username'];
      $url  = empty($jamf['url']) ? $this->io()->ask('What is the url for Jamf?') : $jamf['url'];
      if (!$pass = $this->getUser()->getPasswordFromKeychain('jamf'))
      {
        $pass = $this->io()->ask(sprintf('What is the Jamf password for the user %s?', $user));

        if (false !== $this->io()->ask('Would you like to save this password in your keychain for the future?', 'N'))
        {
          $this->getUser()->setPasswordInKeychain('jamf', $pass);
        }
      }

      $this->_Driver = new JssDriver($url, $user, $pass, $this->getBinaryPath('curl'));
    }

    return $this->_Driver;
  }

  /**
   * @param string $host
   *
   * @return string|null
   */
  protected function getAlternateIpFromJamf($host)
  {
    $jamf = $this->getJamfInfo();
    if (!empty($jamf['ea_vpn']))
    {
      if ($ip = $this->getJamfExtensionAttribute($host, $jamf['ea_vpn']))
      {
        return !in_array(strtolower($ip), ['none', 'false', '0', '']) ? $ip : null;
      }
    }

    return null;
  }

  /**
   * @param string $host
   *
   * @return string|null
   */
  protected function getFqdnFromJamf($host)
  {
    $jamf = $this->getJamfInfo();
    if (!empty($jamf['ea_fqdn']))
    {
      return $this->getJamfExtensionAttribute($host, $jamf['ea_fqdn']);
    }

    return null;
  }

  protected function getJamfExtensionAttribute($host, $name)
  {
    try
    {
      if ($xml = $this->getJssDriver()->getComputer($host))
      {
        $XmlObj = new \SimpleXMLElement($xml);
        $attr   = $XmlObj->extension_attributes->children();

        foreach ($attr as $ExtAttr)
        {
          if ($ExtAttr->name[0] == $name)
          {
            return $ExtAttr->value[0];
          }
        }
      }

      return null;
    }
    catch (\Exception $e)
    {
      return null;
    }
  }

  // endregion ///////////////////////////////////////////// End Jamf Info

  // region //////////////////////////////////////////////// Network Methods

  /**
   * @return bool
   */
  protected function hasVpn()
  {
    return ($vpn = $this->getShellExec('scutil --nc list | grep -i "PPP\|VPN\|L2TP\|IPSEC\|IKEV2"')) ? true : false;
  }

  /**
   * @param string $host
   *
   * @return bool
   */
  protected function isHostReachable($host)
  {
    if ($x = $this->getShellExec('ping -c1 -W1 '.$host.' 2> /dev/null'))
    {
      if (false === strpos($x, '100.0% packet loss'))
      {
        if (preg_match('#([1-9]+) packets received#', $x))
        {
          return true;
        }
      }
    }

    return false;
  }

  /**
   * @return bool
   */
  protected function isUsingVpn()
  {
    return $this->getDevice()->getNetwork()->isActiveVpn();
  }

  /**
   * @return bool
   *
   * @throws \Exception
   */
  protected function isInternet()
  {
    if ($config = $this->getConfig())
    {
      if (!empty($config['network']['internet']))
      {
        foreach ($config['network']['internet'] as $test)
        {
          if ($this->isHostReachable($test))
          {
            return true;
          }
        }

        return false;
      }
    }

    return true;
  }

  /**
   * @return bool
   *
   * @throws \Exception
   */
  protected function isLocalNetwork()
  {
    if ($config = $this->getConfig())
    {
      if (!empty($config['network']['local']))
      {
        foreach ($config['network']['local'] as $test)
        {
          if ($this->isHostReachable($test))
          {
            return true;
          }
        }

        return false;
      }
    }

    return true;
  }

  // endregion ///////////////////////////////////////////// End Network Methods

  // region //////////////////////////////////////////////// SSH Methods

  protected function addHostToSshConfig($host, $fqdn, $port, $uName)
  {
    $configFile = $this->getUser()->getDir().'/.ssh/config';
    if (is_file($configFile))
    {
      $config = file_get_contents($configFile);
    }
    else
    {
      $config = '';
    }

    if (false === strpos($config, sprintf("Host %s\n", $host)))
    {
      $new[] = sprintf("\nHost %s", $host);
      $new[] = sprintf('    Port %s', $port);
      $new[] = sprintf('    HostName %s', $fqdn);

      if (!empty($uName))
      {
        $new[] = sprintf('User %s', $uName);
      }

      $config .= implode("\n", $new);
      $res = file_put_contents($configFile, $config);

      return !empty($res);
    }

    return false;
  }

  /**
   * @param string $host
   *
   * @return string
   */
  protected function getFullHostname($host)
  {
    return $this->getShellExec(sprintf("ssh -G %s | awk '/^hostname / { print $2 }'", $host), $host);
  }

  /**
   * @param string $conn
   *
   * @return bool
   */
  protected function isKeyInstalled($conn)
  {
    $user = substr($conn, 0, strpos($conn, '@'));
    $cmd  = sprintf("ssh -o StrictHostKeyChecking=no -o 'PreferredAuthentications=publickey' -q %s whoami", escapeshellarg($conn));
    $who  = $this->getShellExec($cmd);

    return $who == $user;
  }

  /**
   * @param string $host
   *
   * @return bool
   */
  protected function isSshAccepted($host)
  {
    try
    {
      $fp = @fsockopen($host, 22, $errno, $errstr, 5);

      return false !== $fp;
    }
    catch (\Exception $e)
    {
      return false;
    }
  }

  // endregion ///////////////////////////////////////////// End SSH Methods

  // region //////////////////////////////////////////////// Other Methods

  /**
   * @return string
   *
   * @throws \Exception
   */
  public function getProjectRoot()
  {
    if (empty($this->_projectRoot))
    {
      if ($this->isPhar())
      {
        return \Phar::running(true);
      }

      $dir = __DIR__;
      if (empty($dir))
      {
        throw new \Exception('The current path could not be retrieved.');
      }

      while (!is_dir($dir.'/src'))
      {
        if ($dir === dirname($dir))
        {
          throw new \Exception('The project directory could not be determined.  You must have a "src" directory in the project root!');
        }

        $dir = dirname($dir);
      }

      $this->_projectRoot = $dir;
    }

    return $this->_projectRoot;
  }

  // endregion ///////////////////////////////////////////// End Other Methods
}
