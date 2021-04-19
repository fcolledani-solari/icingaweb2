<?php
/* Icinga Web 2 | (c) 2013 Icinga Development Team | GPLv2+ */

namespace Icinga\Web\Widget;

use Icinga\Application\Config;
use Icinga\Common\Database;
use Icinga\Exception\ConfigurationError;
use Icinga\Exception\NotReadableError;
use Icinga\Exception\ProgrammingError;
use Icinga\Legacy\DashboardConfig;
use Icinga\User;
use Icinga\Web\Menu;
use Icinga\Web\Navigation\Navigation;
use Icinga\Web\Navigation\NavigationItem;
use Icinga\Web\Url;
use Icinga\Web\Dashboard\Dashlet;
use Icinga\Web\Widget\Dashboard\Pane;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Web\Widget\Link;
use ipl\Web\Widget\Tabs;
use ipl\Sql\Select;

/**
 * Dashboards display multiple views on a single page
 *
 * The terminology is as follows:
 * - Dashlet:     A single view showing a specific url
 * - Pane:          Aggregates one or more dashlets on one page, displays its title as a tab
 * - Dashboard:     Shows all panes
 *
 */
class Dashboard extends BaseHtmlElement
{
    use Database;

    protected $tag = 'div';

    protected $defaultAttributes = ['class' => 'dashboard content'];

    /** @var string Name of the default home */
    const DEFAULT_HOME = 'Default Home';

    /** @var string Preserve key name for coming features */
    const AVAILABLE_DASHLETS = 'Available Dashlets';

    /** @var string Preserve key name for coming features */
    const SHARED_DASHBOARDS = 'Shared Dashboards';

    /**
     * An array containing all panes of this dashboard
     *
     * @var array
     */
    private $panes = array();

    /**
     * The @see \ipl\Web\Widget\Tabs object for displaying displayable panes
     *
     * @var Tabs
     */
    protected $tabs;

    /**
     * The parameter that will be added to identify panes
     *
     * @var array
     */
    private $tabParam = ['home', 'pane'];

    /**
     * Dashboard home NavigationItems of the main menu item „dashboard“
     *
     * @var array
     */
    private $homes = [];

    /**
     * @var User
     */
    private $user;

    /**
     * Unique home identifier that is being loaded
     *
     * @var integer
     */
    private $activeHome;

    /**
     * Set the given tab name as active.
     *
     * @param string $name      The tab name to activate
     *
     */
    public function activate($name)
    {
        $this->getTabs()->activate($name);
    }

    /**
     * Get Database connection
     *
     * @return \ipl\Sql\Connection
     */
    public function getConn()
    {
        return $this->getDb();
    }

    /**
     * Load Pane items provided by all enabled modules
     *
     * @return  $this
     */
    public function load()
    {
        $this->loadHomeItems();
        $this->loadSystemDashboards();
        $this->loadUserDashboards();
        return $this;
    }

    /**
     * Create and return a Config object for this dashboard
     *
     * @return  Config
     */
    public function getConfig()
    {
        $output = array();
        foreach ($this->panes as $pane) {
            if ($pane->isUserWidget()) {
                $output[$pane->getName()] = $pane->toArray();
            }
            foreach ($pane->getDashlets() as $dashlet) {
                if ($dashlet->isUserWidget()) {
                    $output[$pane->getName() . '.' . $dashlet->getName()] = $dashlet->toArray();
                }
            }
        }

        return DashboardConfig::fromArray($output)->setConfigFile($this->getConfigFile())->setUser($this->user);
    }

    /**
     * Load user dashboards from all config files that match the username
     */
    protected function loadUserDashboards()
    {
        foreach (DashboardConfig::listConfigFilesForUser($this->user) as $file) {
            $this->loadUserDashboardsFromFile($file);
        }

        $this->loadUserDashboardsFromDatabase();
    }

    /**
     * Load dashboard home items from the navigation menu, these have to
     *
     * always be loaded when the $this->load() method is called
     */
    public function loadHomeItems()
    {
        $menu = new Menu();
        /** @var NavigationItem|mixed $child */
        foreach ($menu->getItem('dashboard')->getChildren() as $child) {
            $this->homes[$child->getName()] = $child;
        }
    }

    /**
     * Load system dashboards provided by all enabled modules
     *
     * with the dashboard() method and store them in the DB
     */
    protected function loadSystemDashboards()
    {
        $db = $this->getConn();
        $navigation = new Navigation();
        $navigation->load('dashboard-pane');

        if (! array_key_exists(self::DEFAULT_HOME, $this->homes)) {
            $db->insert('dashboard_home', [
                'name'  => self::DEFAULT_HOME,
                'owner' => null
            ]);

            $parent = $db->lastInsertId();
        } else {
            $parent = $this->getHomeByName(self::DEFAULT_HOME)->getAttribute('homeId');
        }

        $panes = [];
        /** @var NavigationItem $dashboardPane */
        foreach ($navigation as $dashboardPane) {
            $paneId = $this->getSHA1($dashboardPane->getAttribute('module') . $dashboardPane->getName());
            $pane = (new Pane($dashboardPane->getName()))
                ->setTitle($dashboardPane->getLabel())
                ->setParentId($parent)
                ->setPaneId($paneId);

            if (! $this->hasHomeThisPane($parent, $pane->getName())) {
                $this->getConn()->insert('dashboard', [
                    'id'        => $pane->getPaneId(),
                    'home_id'   => $parent,
                    'name'      => $pane->getName(),
                    'label'     => $pane->getTitle(),
                    'disabled'  => (int)$pane->getDisabled()
                ]);
            }

            /** @var NavigationItem $dashlet */
            foreach ($dashboardPane->getChildren() as $dashlet) {
                $systemDashletId = $this->getSHA1(
                    $dashboardPane->getAttribute('module') . $pane->getName() . $dashlet->getName()
                );

                $select = (new Select())
                    ->columns('*')
                    ->from('dashlet_override')
                    ->where([
                        'owner = ?'         => $this->user->getUsername(),
                        'dashlet_id = ?'    => $systemDashletId
                    ]);

                $result = $this->getConn()->select($select)->fetch();
                $dashletLabel = $dashlet->getLabel();
                $dashletUrl = $dashlet->getUrl()->getRelativeUrl();

                if ($result) {
                    $dashletDeleted = false;
                    if (($result->label === $dashletLabel || $result->label === null) &&
                        ($result->url === $dashletUrl || $result->url === null) && $result->disabled === 0) {
                        $this->getConn()->delete('dashlet_override', ['dashlet_id = ?' => $systemDashletId]);

                        $dashletDeleted = true;
                    }

                    if (! $dashletDeleted && $result->label !== null) {
                        $dashletLabel = $result->label;
                    } elseif (! $dashletDeleted && $result->url !== null) {
                        $dashletUrl = $result->url;
                    }
                }

                $newDashlet = new Dashlet($dashletLabel, $dashletUrl, $pane);
                $newDashlet
                    ->setName($dashlet->getName())
                    ->setDashletId($systemDashletId);

                if ($result) {
                    $newDashlet
                        ->setDisabled($result->disabled)
                        ->setUserWidget()
                        ->setOverride();
                }

                $pane->addDashlet($newDashlet);
            }

            $panes[$dashboardPane->getName()] = $pane;
        }

        $this->mergePanes($panes);

        return true;
    }

    /**
     * Load user specific dashboards and dashlets from the database
     * and merges them to the dashboards loaded from an ini file
     *
     * @param   integer  $parentId
     *
     * @return  bool
     */
    public function loadUserDashboardsFromDatabase($parentId = 0)
    {
        if (Url::fromRequest()->getParam('home')) {
            if ($parentId === 0) {
                $home = Url::fromRequest()->getParam('home');
                $parentId = $this->homes[$home]->getAttribute('homeId');
            }
        } elseif ($parentId === 0) {
            $home = $this->rewindHomes();
            $parentId = $home ? $home->getAttribute('homeId') : $parentId;
        }

        $select = $this->getConn()->select((new Select())
            ->columns('*')
            ->from('dashboard')
            ->where(['home_id = ?' => $parentId]));

        $this->setActiveHome($parentId);

        $dashboards = [];
        foreach ($select as $dashboard) {
            $dashboards[$dashboard->name] = (new Pane($dashboard->name))
                ->setPaneId($dashboard->id)
                ->setParentId($dashboard->home_id)
                ->setDisabled($dashboard->disabled)
                ->setTitle($dashboard->label)
                ->setOwner($dashboard->owner);

            if ($dashboard->owner !== null) {
                $dashboards[$dashboard->name]->setUserWidget();
            }

            $newResults = $this->getDb()->select((new Select())
                ->columns('*')
                ->from('dashlet')
                ->where(['dashboard_id = ?'  => $dashboard->id])
                ->where([
                    'dashlet.owner = ?' => $this->user->getUsername(),
                    'dashlet.owner IS NULL'
                ], 'OR'));

            foreach ($newResults as $dashletData) {
                $dashlet = (new Dashlet(
                    $dashletData->label,
                    $dashletData->url,
                    $dashboards[$dashboard->name]
                ))
                    ->setName($dashletData->name)
                    ->setDashletId($dashletData->id);

                if ($dashletData->owner !== null) {
                    $dashlet->setUserWidget();
                }

                $dashboards[$dashboard->name]->addDashlet($dashlet);
            }
        }

        $this->mergePanes($dashboards);

        return true;
    }

    /**
     * Load user dashboards from the given config file
     *
     * @param   string  $file
     *
     * @return  bool
     */
    protected function loadUserDashboardsFromFile($file)
    {
        try {
            $config = Config::fromIni($file);
        } catch (NotReadableError $e) {
            return false;
        }

        if (! count($config)) {
            return false;
        }

        $db = $this->getConn();
        if (! array_key_exists(self::DEFAULT_HOME, $this->homes)) {
            $db->insert('dashboard_home', ['name'  => self::DEFAULT_HOME]);

            $homeId = $db->lastInsertId();
        } else {
            $homeId = $this->getHomeByName(self::DEFAULT_HOME)->getAttribute('homeId');
        }

        $panes = [];
        foreach ($config as $key => $part) {
            if (strpos($key, '.') === false) {
                $paneId = $this->getSHA1(self::DEFAULT_HOME . $key);
                if (! array_key_exists($key, $panes)) {
                    if ($pane = $this->hasHomeThisPane($homeId, $key)) {
                        $paneId = $pane->id;
                    } else {
                        $this->getConn()->insert('dashboard', [
                            'id'        => $paneId,
                            'home_id'   => $homeId,
                            'owner'     => $this->user->getUsername(),
                            'name'      => $key,
                            'label'     => $part->get('title', $key),
                            'disabled'  => (int)$part->get('disabled', false)
                        ]);
                    }

                    $pane = (new Pane($key))
                        ->setTitle($part->get('title', $key))
                        ->setOwner($this->user->getUsername())
                        ->setParentId($homeId)
                        ->setPaneId($paneId)
                        ->setDisabled($part->get('disabled', false));

                    $panes[$key] = $pane;
                }
            } else {
                list($paneName, $dashletName) = explode('.', $key, 2);
                if (array_key_exists($paneName, $panes)) {
                    $pane = $panes[$paneName];
                    if ($pane->hasDashlet($dashletName)) {
                        $db->update('dashlet', [
                            'label'     => $part->get('title', $dashletName),
                            'url'       => $part->get('url'),
                            'disabled'  => (int)$part->get('disabled', false)
                        ], [
                            'dashboard_id = ?'  => $pane->getPaneId(),
                            'id = ?'            => $pane->getDashlet($dashletName)->getDashletId()
                        ]);
                    } else {
                        $dashletId = $this->getSHA1(self::DEFAULT_HOME . $pane->getName() . $dashletName);
                        $select = (new Select())
                            ->columns('name')
                            ->from('dashlet')
                            ->where(['id = ?' => $dashletId, 'dashboard_id = ?' => $pane->getPaneId()]);

                        $result = $this->getConn()->select($select)->fetch();
                        $dashlet = (new Dashlet($part->get('title', $dashletName), $part->get('url'), $pane))
                            ->setName($dashletName)
                            ->setDashletId($dashletId)
                            ->setDisabled($part->get('disabled', false))
                            ->setUserWidget();

                        if (! $result) {
                            $db->insert('dashlet', [
                                'id'            => $dashlet->getDashletId(),
                                'dashboard_id'  => $pane->getPaneId(),
                                'owner'         => $this->user->getUsername(),
                                'name'          => $dashlet->getName(),
                                'label'         => $dashlet->getTitle(),
                                'url'           => $dashlet->getUrl(),
                            ]);
                        }

                        $pane->addDashlet($dashlet);
                    }
                }
            }
        }

        $this->mergePanes($panes);

        return true;
    }

    /**
     * Merge panes with existing panes
     *
     * @param  array $panes
     *
     * @return $this
     *
     * @throws ProgrammingError|ConfigurationError
     */
    public function mergePanes(array $panes)
    {
        /** @var $pane Pane  */
        foreach ($panes as $pane) {
            if (empty($pane->getParentId()) || empty($pane->getPaneId())) {
                throw new ProgrammingError(
                    'Pane "%s" doesn\'t contain an \'%s\'.',
                    $pane->getName(),
                    $pane->getPaneId() ? 'homeId' : 'identifier'
                );
            }

            if ($this->hasPane($pane->getName())) {
                /** @var Pane $current */
                $current = $this->panes[$pane->getName()];

                if ($pane->getParentId() !== $current->getParentId()) {
                    $this->panes[$current->getName()] = $pane;
                    continue;
                }

                $current->setTitle($pane->getTitle());
                $current->setDisabled($pane->getDisabled());

                foreach ($pane->getDashlets() as $dashlet) {
                    if (! $current->hasDashlet($dashlet->getTitle())) {
                        $current->addDashlet($dashlet);
                    }
                }

                continue;
            }

            $this->panes[$pane->getName()] = $pane;
        }

        $this->panes = array_filter($this->panes, function ($pane) {
            if ($this->getActiveHome() !== null) {
                return $this->getActiveHome() === $pane->getParentId();
            }

            return true;
        });

        return $this;
    }

    /**
     * Return the tab object used to navigate through this dashboard
     *
     * @param bool $defaultPane
     *
     * @return Tabs
     */
    public function getTabs($defaultPane = false)
    {
        if (Url::fromRequest()->hasParam('home')) {
            $url = Url::fromPath('dashboard/home')->getUrlWithout($this->tabParam);
        } else {
            $url = Url::fromPath('dashboard')->getUrlWithout($this->tabParam);
        }

        if ($this->tabs === null) {
            $this->tabs = new Tabs();
            $this->tabs->disableLegacyExtensions();

            foreach ($this->panes as $key => $pane) {
                if ($pane->getDisabled()) {
                    continue;
                }

                if (Url::fromRequest()->hasParam('home')) {
                    $url->addParams([$this->tabParam[0]  => $this->getHomeById($pane->getParentId())->getName()]);
                }

                $this->tabs->add(
                    $key,
                    [
                        'title' => sprintf(
                            t('Show %s', 'dashboard.pane.tooltip'),
                            $pane->getTitle()
                        ),
                        'label'     => $pane->getTitle(),
                        'url'       => clone($url),
                        'urlParams' => [$this->tabParam[1] => $key]
                    ]
                );
            }
        }

        // This is only required for dashboard homes to activate a default pane
        if ($defaultPane) {
            $this->setDefaultPane();
        }

        return $this->tabs;
    }

    /**
     * Set the home id that is being loaded
     *
     * @param $home
     *
     * @return $this
     */
    public function setActiveHome($home)
    {
        $this->activeHome = $home;

        return $this;
    }

    /**
     * Get the active home identifier
     *
     * @return integer
     */
    public function getActiveHome()
    {
        return $this->activeHome;
    }

    /**
     * Return dashboard home Navigation items
     *
     * @return array
     */
    public function getHomes()
    {
        return $this->homes;
    }

    /**
     * Get home from the Navigation by the given identifier
     *
     * @param integer $id
     *
     * @return NavigationItem
     *
     * @throws ProgrammingError
     */
    public function getHomeById($id)
    {
        foreach ($this->homes as $home) {
            if ($home->getAttribute('homeId') === $id) {
                return $home;
            }
        }

        throw new ProgrammingError(
            'Dashboard home doesn\'t exist with the provided id: "%s"',
            $id
        );
    }

    /**
     * Get home from the Navigation by the given name
     *
     * @param  string $name
     *
     * @return NavigationItem
     *
     * @throws ProgrammingError
     */
    public function getHomeByName($name)
    {
        if (array_key_exists($name, $this->homes)) {
            return $this->homes[$name];
        }

        throw new ProgrammingError(
            'Dashboard home doesn\'t exist with the provided name: "%s"',
            $name
        );
    }

    /**
     * Remove a specific home from this dashboard
     *
     * @param string $home
     *
     * @return $this
     *
     * @throws ProgrammingError
     */
    public function removeHome($home)
    {
        if (array_key_exists($home, $this->homes)) {
            /** @var Pane $pane */
            foreach ($this->panes as $pane) {
                $pane->removeDashlets();
            }

            $parent = $this->homes[$home]->getAttribute('homeId');
            $this->removePanes();

            if (self::DEFAULT_HOME !== $home) {
                $this->getConn()->delete('dashboard_home', ['id = ?'    => $parent]);
            }
        } else {
            throw new ProgrammingError('Home does not exist: ' . $home);
        }

        return $this;
    }

    /**
     * Return an array with home name=>name format used for comboboxes
     *
     * @return array
     */
    public function getHomeKeyNameArray()
    {
        $list = [];
        foreach ($this->homes as $name => $home) {
            $list[$name] = $home->getName();
        }
        return $list;
    }

    /**
     * Return all panes of this dashboard
     *
     * @return array
     */
    public function getPanes()
    {
        return $this->panes;
    }

    /**
     * Creates a new empty pane with the given title
     *
     * @param string $title
     *
     * @return $this
     */
    public function createPane($title)
    {
        $pane = new Pane($title);
        $pane->setTitle($title);
        $this->addPane($pane);

        return $this;
    }

    /**
     * Checks if the current dashboard has any panes
     *
     * @return bool
     */
    public function hasPanes()
    {
        return ! empty($this->panes);
    }

    /**
     * Check if a panel exist
     *
     * @param   string  $pane
     * @return  bool
     */
    public function hasPane($pane)
    {
        return $pane && array_key_exists($pane, $this->panes);
    }

    /**
     * Check and get if any of the panes contains the given uid
     *
     * @param  $homeId
     *
     * @param  $pane
     *
     * @return mixed
     */
    public function hasHomeThisPane($homeId, $pane)
    {
        $select = (new Select())
            ->columns('*')
            ->from('dashboard')
            ->where(['name = ?' => $pane, 'home_id = ?' => $homeId]);

        return $this->getConn()->select($select)->fetch();
    }

    /**
     * Add a pane object to this dashboard
     *
     * @param Pane $pane        The pane to add
     *
     * @return $this
     */
    public function addPane(Pane $pane)
    {
        $this->panes[$pane->getName()] = $pane;
        return $this;
    }

    /**
     * Remove a specific pane from this dashboard
     *
     * @param  $pane
     *
     * @return $this
     *
     * @throws ProgrammingError
     */
    public function removePane($pane)
    {
        if (! $pane instanceof Pane) {
            if (! $this->hasPane($pane)) {
                throw new ProgrammingError('Pane not found: ' . $pane);
            }

            $pane = $this->getPane($pane);
        }

        if ($pane->isUserWidget() === true) {
            $this->getConn()->delete('dashboard', ['id = ?' => $pane->getPaneId()]);
        } else {
            $this->getConn()->update('dashboard', ['disabled' => true], ['id = ?' => $pane->getPaneId()]);
        }

        return $this;
    }

    /**
     * @throws ProgrammingError
     */
    public function removePanes($panes = [])
    {
        if (empty($panes)) {
            $panes = $this->getPanes();
        }

        foreach ($panes as $pane) {
            $this->removePane($pane);
        }

        return $this;
    }

    /**
     * Return the pane with the provided name
     *
     * @param string $name      The name of the pane to return
     *
     * @return Pane        The pane or null if no pane with the given name exists
     * @throws ProgrammingError
     */
    public function getPane($name)
    {
        if (! array_key_exists($name, $this->panes)) {
            throw new ProgrammingError(
                'Trying to retrieve invalid dashboard pane "%s"',
                $name
            );
        }
        return $this->panes[$name];
    }

    /**
     * Return an array with pane name=>title format used for comboboxes
     *
     * @return array
     */
    public function getPaneKeyTitleArray()
    {
        $list = [];
        foreach ($this->panes as $name => $pane) {
            if ($pane->getDisabled()) {
                continue;
            }

            $list[$name] = $pane->getTitle();
        }
        return $list;
    }

    /**
     * @inheritDoc
     */
    public function assemble()
    {
        $panes = array_filter($this->panes, function ($pane) {
            return ! $pane->getDisabled();
        });

        if (! empty($panes)) {
            $dashlets = array_filter($this->determineActivePane()->getDashlets(), function ($dashlet) {
                return ! $dashlet->getDisabled();
            });

            if (empty($dashlets)) {
                $this->setAttribute('class', 'content');
                $dashlets = new HtmlElement('h1', null, 'No dashlet added to this pane.');
            }
        } else {
            $this->setAttribute('class', 'content');
            $format = t(
                'Currently there is no pane available. This might change once you enabled some of the available %s.'
            );

            $dashlets = [
                new HtmlElement('h1', null, t('Welcome to Icinga Web!')),
                sprintf($format, new Link('modules', 'config/modules'))
            ];
        }

        $this->add($dashlets);
    }

    /**
     * Activates the default pane of this dashboard and returns its name
     *
     * @return mixed
     */
    private function setDefaultPane()
    {
        $active = null;

        foreach ($this->panes as $key => $pane) {
            if ($pane->getDisabled() === false) {
                $active = $key;
                break;
            }
        }

        if ($active !== null) {
            $this->activate($active);
        }
        return $active;
    }

    /**
     * @see determineActivePane()
     */
    public function getActivePane()
    {
        return $this->determineActivePane();
    }

    /**
     * Determine the active pane either by the selected tab or the current request
     *
     * @throws \Icinga\Exception\ConfigurationError
     * @throws \Icinga\Exception\ProgrammingError
     *
     * @return Pane The currently active pane
     */
    public function determineActivePane()
    {
        $active = $this->getTabs()->getActiveTab();
        if (! $active) {
            if ($active = Url::fromRequest()->getParam($this->tabParam[1])) {
                if ($this->hasPane($active)) {
                    $this->activate($active);
                } else {
                    throw new ProgrammingError(
                        'Try to get an inexistent pane.'
                    );
                }
            } else {
                $active = $this->setDefaultPane();
            }
        } else {
            $active = $active->getName();
        }

        if (isset($this->panes[$active])) {
            return $this->panes[$active];
        }

        throw new ConfigurationError('Could not determine active pane');
    }

    /**
     * Setter for user object
     *
     * @param User $user
     */
    public function setUser(User $user)
    {
        $this->user = $user;
    }

    /**
     * Getter for user object
     *
     * @return User
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Get config file
     *
     * @return string
     */
    public function getConfigFile()
    {
        if ($this->user === null) {
            throw new ProgrammingError('Can\'t load dashboards. User is not set');
        }
        return Config::resolvePath('dashboards/' . strtolower($this->user->getUsername()) . '/dashboard.ini');
    }

    /**
     * Reset the current position of the internal home object
     *
     * @return null|NavigationItem
     */
    public function rewindHomes()
    {
        return reset($this->homes);
    }

    public function getSHA1(string $name)
    {
        return sha1($name, true);
    }

    /**
     * Sets the position of the specified key of array element as the first
     *
     * element of the list e.g $arr ['two' => 2, 'one' => 1]
     *
     * is going to be $arr ['one' => 1, 'two' => 2]
     *
     * @param  array $list
     *
     * @param  $key
     *
     * @return array
     */
    public function changeElementPos(array $list, $key)
    {
        array_unshift($list, $key);
        $list = array_unique($list);

        $keys = array_keys($list);
        $keys[array_search(0, $keys, true)] = $key;

        return array_combine($keys, $list);
    }
}
