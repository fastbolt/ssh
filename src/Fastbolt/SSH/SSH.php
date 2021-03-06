<?php
/**
 * @copyright 2017 Daniel Hirtzbruch / Fastbolt Schraubengroßhandels GmbH (http://www.fastbolt.com)
 * @package   Fastbolt\SSH
 */

namespace Fastbolt\SSH;

use Symfony\Component\Process\Process;

/**
 * Class SSH
 */
class SSH
{

    /**
     * @var Process
     */
    private $process;

    /**
     * @var Config
     */
    private $config;

    /**
     * $user, $sshHost, $localPort, $remoteHost, $remotePort, $privateKey, $sshPort = '22'
     *
     * @param Config $config
     *
     * @return SSH
     *
     * @throws SSHException Throws on configuration errors or connection failure.
     */
    public function openTunnel(Config $config)
    {
        $this->config = $this->prepareConfiguration($config);

        $sshCommand = sprintf(
            'ssh -p %s %s@%s -M -L %s:%s:%s -i %s -fN -o ExitOnForwardFailure=yes -o StrictHostKeyChecking=no -S %s',
            $config->getSshPort(),
            $config->getSshUsername(),
            $config->getSshHostname(),
            $config->getForwardPortLocal(),
            $config->getForwardHostRemote(),
            $config->getForwardPortRemote(),
            $config->getPrivateKeyFilename(),
            $config->getSshSocketPath()
        );

        $this->process = new Process($sshCommand);
        $this->process->setTimeout(60)
                      ->start();

        while ($this->process->isRunning()) {
            sleep(1);
        }

        if ($this->process->getExitCode() !== 0) {
            throw new SSHException(
                sprintf(
                    'Error creating ssh tunnel. %s %s',
                    $this->process->getOutput(),
                    $this->process->getErrorOutput()
                )
            );
        }

        return $this;
    }

    /**
     * @throws SSHException
     */
    public function close()
    {
        if (null === $this->process) {
            throw new SSHException('Tunnel not opened');
        }

        $this->process->setCommandLine(
            sprintf(
                'ssh -S %s -O exit %s',
                $this->config->getSshSocketPath(),
                $this->config->getSshHostname()
            )
        )
                      ->start();

        while ($this->process->isRunning()) {
            sleep(1);
        }

        if ($this->process->getExitCode() !== 0) {
            throw new SSHException(
                sprintf(
                    'Unable to close ssh tunnel. %s %s',
                    $this->process->getOutput(),
                    $this->process->getErrorOutput()
                )
            );
        }

        $this->process = null;
    }

    /**
     * Helper method for writing key to temp file.
     *
     * @param string $key
     *
     * @return string
     *
     * @throws SSHException
     */
    private function writeKeyToFile($key)
    {
        if (empty($key)) {
            throw new SSHException('SSH Key must not be empty');
        }
        $tempFileName = tempnam('/tmp/', 'ssh-key-');
        file_put_contents($tempFileName, $key);
        chmod($tempFileName, 0600);

        return realpath($tempFileName);
    }

    /**
     * Helper method for checking / completing configuration.
     *
     * @param Config $config
     *
     * @return Config
     *
     * @throws SSHException
     */
    private function prepareConfiguration(Config $config)
    {
        if (!$config->getSshSocketPath()) {
            $config->setSshSocketPath(
                sprintf(
                    '%s/ssh-%s@%s:%s',
                    sys_get_temp_dir(),
                    $config->getSshUsername(),
                    $config->getSshHostname(),
                    $config->getSshPort()
                )
            );
        }

        $mandatoryParameters = [
            'sshSocketPath',
            'sshUsername',
            'sshHostname',
            'sshPort',
            'forwardPortLocal',
            'forwardPortRemote',
            'forwardHostRemote',
        ];
        foreach ($mandatoryParameters as $parameter) {
            $getter = 'get'.ucfirst($parameter);
            if (!$config->$getter()) {
                throw new SSHException(sprintf('Missing configuration "%s".', $parameter));
            }
        }

        if (!$config->getPrivateKeyContents() && !$config->getPrivateKeyFilename()) {
            throw new SSHException(
                'Missing configuration: One of "privateKeyContents" and "privateKeyFilename" is mandatory.'
            );
        }

        if ($config->getPrivateKeyContents() && $config->getPrivateKeyFilename()) {
            throw new SSHException(
                'Only one of "privateKeyContents" and "privateKeyFilename" may be set.'
            );
        }

        if ($config->getPrivateKeyFilename() && !file_exists($config->getPrivateKeyFilename())) {
            throw new SSHException(
                sprintf(
                    'Key file "%s" could not be found',
                    $config->getPrivateKeyFilename()
                )
            );
        }

        if ($config->getPrivateKeyContents()) {
            $config->getPrivateKeyFilename = $this->writeKeyToFile($config->getPrivateKeyContents());
        }

        return $config;
    }

    /**
     * Getter for current process instance.
     *
     * @return Process
     */
    public function getProcess()
    {
        return $this->process;
    }
}
