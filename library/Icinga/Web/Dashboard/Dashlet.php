<?php

namespace Icinga\Web\Dashboard;

use Icinga\Web\Url;
use Icinga\Web\Widget\Dashboard\Pane;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Web\Widget\Link;

/**
 * A dashboard pane dashlet
 *
 * This is the new element being used for the Dashlets view
 */
class Dashlet extends BaseHtmlElement
{
    protected $tag = 'div';

    protected $defaultAttributes = ['class' => 'container'];

    /**
     * The url of this Dashlet
     *
     * @var Url|null
     */
    private $url;

    private $name;

    /**
     * The title being displayed on top of the dashlet
     * @var
     */
    private $title;

    /**
     * The pane containing this dashlet, needed for the 'remove button'
     * @var Pane
     */
    private $pane;

    /**
     * The disabled option is used to "delete" default dashlets provided by modules
     *
     * @var bool
     */
    private $disabled = false;

    /**
     * The progress label being used
     *
     * @var string
     */
    private $progressLabel;

    /** @var integer Unique identifier of this dashlet */
    private $dashletId;

    public function __construct($title, $url, Pane $pane = null)
    {
        $this->name = $title;
        $this->title = $title;
        $this->pane = $pane;
        $this->url = $url;
    }

    /**
     * Set the identifier of this dashlet
     *
     * @param integer $id
     *
     * @return Dashlet
     */
    public function setDashletId($id)
    {
        $this->dashletId = $id;
        return $this;
    }

    /**
     * Get the unique identifier of this dashlet
     *
     * @return int
     */
    public function getDashletId()
    {
        return $this->dashletId;
    }

    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getTitle()
    {
        return $this->title;
    }

    /**
     * @param string $title
     */
    public function setTitle($title)
    {
        $this->title = $title;
    }

    /**
     * Retrieve the dashlets url
     *
     * @return Url|null
     */
    public function getUrl()
    {
        if ($this->url !== null && ! $this->url instanceof Url) {
            $this->url = Url::fromPath($this->url);
        }
        return $this->url;
    }

    /**
     * Set the dashlets URL
     *
     * @param  string|Url $url  The url to use, either as an Url object or as a path
     *
     * @return $this
     */
    public function setUrl($url)
    {
        $this->url = $url;
        return $this;
    }

    /**
     * Set the disabled property
     *
     * @param boolean $disabled
     */
    public function setDisabled($disabled)
    {
        $this->disabled = $disabled;
    }

    /**
     * Get the disabled property
     *
     * @return boolean
     */
    public function getDisabled()
    {
        return $this->disabled;
    }

    /**
     * Set the progress label to use
     *
     * @param   string  $label
     *
     * @return  $this
     */
    public function setProgressLabel($label)
    {
        $this->progressLabel = $label;
        return $this;
    }

    /**
     * Return the progress label to use
     *
     * @return  string
     */
    public function getProgressLabe()
    {
        if ($this->progressLabel === null) {
            return $this->progressLabel = t('Loading');
        }

        return $this->progressLabel;
    }

    /**
     * Return this dashlet's structure as array
     *
     * @return  array
     */
    public function toArray()
    {
        $array = array(
            'url'   => $this->getUrl()->getRelativeUrl(),
            'title' => $this->getTitle()
        );
        if ($this->getDisabled() === true) {
            $array['disabled'] = 1;
        }
        return $array;
    }

    protected function assemble()
    {
        if (! $this->url) {
            $this->add(new HtmlElement('h1', null, $this->getTitle()));
            $this->add(new HtmlElement(
                'p',
                ['class' => 'error-message'],
                sprintf(t('Cannot create dashboard dashlet "%s" without valid URL'), $this->getTitle())
            ));
        } else {
            $url = $this->getUrl();
            $url->setParam('showCompact', true);

            $this->addAttributes(['data-icinga-url' => $url]);
            $this->add(new HtmlElement('h1', null, new Link(
                $this->getTitle(),
                $url->getUrlWithout(['showCompact', 'limit'])->getRelativeUrl(),
                [
                    'aria-label'        => $this->getTitle(),
                    'title'             => $this->getTitle(),
                    'data-base-target'  => 'col1'
                ]
            )));

            $this->add(new HtmlElement(
                'p',
                ['class'    => 'progress-label'],
                [
                    $this->getProgressLabe(),
                    new HtmlElement('span', null, '.'),
                    new HtmlElement('span', null, '.'),
                    new HtmlElement('span', null, '.'),
                ]
            ));
        }
    }

    /**
     * @param \Icinga\Web\Widget\Dashboard\Pane $pane
     */
    public function setPane(Pane $pane)
    {
        $this->pane = $pane;
    }

    /**
     * @return \Icinga\Web\Widget\Dashboard\Pane
     */
    public function getPane()
    {
        return $this->pane;
    }
}
