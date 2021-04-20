<?php
/* Icinga Web 2 | (c) 2021 Icinga GmbH | GPLv2+ */

namespace Icinga\Application\Hook;

use ipl\Web\Url;
use LogicException;

abstract class HealthHook
{
    /** @var int */
    const STATE_OK = 0;

    /** @var int */
    const STATE_WARNING = 1;

    /** @var int */
    const STATE_CRITICAL = 2;

    /** @var int */
    const STATE_UNKNOWN = 3;

    /** @var int The overall state */
    protected $state;

    /** @var string Message describing the overall state */
    protected $message;

    /** @var array Available metrics */
    protected $metrics;

    /** @var Url Url to a graphical representation of the available metrics */
    protected $url;

    /**
     * Get overall state
     *
     * @return int
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Set overall state
     *
     * @param int $state
     *
     * @return $this
     */
    public function setState($state)
    {
        $this->state = $state;

        return $this;
    }

    /**
     * Get the message describing the overall state
     *
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * Set the message describing the overall state
     *
     * @param string $message
     *
     * @return $this
     */
    public function setMessage($message)
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Get available metrics
     *
     * @return array
     */
    public function getMetrics()
    {
        return $this->metrics;
    }

    /**
     * Set available metrics
     *
     * @param array $metrics
     *
     * @return $this
     */
    public function setMetrics(array $metrics)
    {
        $this->metrics = $metrics;

        return $this;
    }

    /**
     * Get the url to a graphical representation of the available metrics
     *
     * @return Url
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Set the url to a graphical representation of the available metrics
     *
     * @param Url $url
     *
     * @return $this
     */
    public function setUrl(Url $url)
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Get the name of the hook
     *
     * Only used in API responses to differentiate it from other hooks of the same module.
     *
     * @return string
     */
    public function getName()
    {
        $classPath = get_class($this);
        $parts = explode('\\', $classPath);
        $className = array_pop($parts);

        if (substr($className, -4) === 'Hook') {
            $className = substr($className, 1, -4);
        }

        return strtolower($className[0]) . substr($className, 1);
    }

    /**
     * Get the name of the module providing this hook
     *
     * @return string
     *
     * @throws LogicException
     */
    public function getModuleName()
    {
        $classPath = get_class($this);
        if (substr($classPath, 0, 14) !== 'Icinga\\Module\\') {
            throw new LogicException('Not a module hook');
        }

        $withoutPrefix = substr($classPath, 14);
        return strtolower(substr($withoutPrefix, 0, strpos($withoutPrefix, '\\')));
    }

    /**
     * Collect health information
     *
     * Implement this method and set the overall state, message and url (optional) to be shown in the UI.
     *
     * @return void
     */
    abstract public function collectHealthInfo();

    /**
     * Collect health metrics
     *
     * Implement this method and set the overall state, message and available metrics to be served in API responses.
     *
     * @return void
     */
    abstract public function collectHealthMetrics();
}
