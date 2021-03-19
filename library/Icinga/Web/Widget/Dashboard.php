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
use Icinga\Web\Navigation\DashboardPane;
use Icinga\Web\Navigation\Navigation;
use Icinga\Web\Navigation\NavigationItem;
use Icinga\Web\Url;
use Icinga\Web\Widget\Dashboard\Dashlet as DashboardDashlet;
use Icinga\Web\Widget\Dashboard\Pane;
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
class Dashboard extends AbstractWidget
{
    use Database;

    /** @var string Name of the default home */
    const DEFAULT_HOME = 'Default Home';

    /**
     * Name of the user dashboards home loaded from all
     *
     * config files that match the username
     *
     * @var string
     */
    const USER_HOME = 'User Home';

    /** @var string Preserve name for coming features */
    const AVAILABLE_DASHLETS = 'Available Dashlets';

    /** @var string Preserve name for coming features */
    const SHARED_DASHBOARDS = 'Shared Dashboards';

    /**
     * An array containing all panes of this dashboard
     *
     * @var array
     */
    private $panes = array();

    /**
     * The @see Icinga\Web\Widget\Tabs object for displaying displayable panes
     *
     * @var Tabs
     */
    protected $tabs;

    /**
     * The parameter that will be added to identify panes
     *
     * @var string
     */
    private $tabParam = 'pane';

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
        $this->loadGlobalDashboards();
        $this->loadUserDashboards();
        return $this;
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
     * Get the name of the dashboard home from the Navigation
     *
     * if its identifier matches the given $id
     *
     * @param integer $id
     *
     * @return NavigationItem
     *
     * @throws ProgrammingError
     */
    public function getHome($id)
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
     * Load global dashboards provided by all enabled module
     *
     * with the dashboard() method and store them in the DB
     */
    protected function loadGlobalDashboards()
    {
        if (Url::fromRequest()->hasParam('home')) {
            $home = Url::fromRequest()->getParam('home');
            // If the home parameter being loaded does not match the default home
            // we do not need to load anything
            if ($home !== self::DEFAULT_HOME) {
                return false;
            }
        }

        $navigation = new Navigation();
        $navigation->load('dashboard-pane');

        $db = $this->getConn();

        if (! array_key_exists(self::DEFAULT_HOME, $this->homes)) {
            $db->insert('dashboard_home', [
                'name' => self::DEFAULT_HOME,
                'owner' => null
            ]);

            $parent = $db->lastInsertId();
        } else {
            $parent = $this->homes[self::DEFAULT_HOME]->getAttribute('homeId');
        }

        $panes = array();
        foreach ($navigation as $dashboardPane) {
            /** @var DashboardPane $dashboardPane */
            $pane = new Pane($dashboardPane->getLabel());
            $pane->setParentId($parent);

            if ($current = $this->hasHomePane($parent, $dashboardPane->getName())) {
                $db->update('dashboard', [
                    'label' => $dashboardPane->getLabel()
                ], [
                    'id = ?' => $current->id,
                    'home_id = ?' => $parent
                ]);

                $pane->setPaneId($current->id);

                $paneId = $current->id;
            } else {
                $db->insert('dashboard', [
                    'home_id' => $parent,
                    'name' => $dashboardPane->getName(),
                    'label' => $dashboardPane->getLabel(),
                    'disabled' => (int)$dashboardPane->getDisabled()
                ]);

                $paneId = $db->lastInsertId();
                $pane->setPaneId($paneId);
            }

            foreach ($dashboardPane->getChildren() as $dashlet) {
                $pane->addDashlet($dashlet->getLabel(), $dashlet->getUrl());

                if ($orgDashlet = $this->hasPaneDashlet($paneId, $dashlet->getName())) {
                    $db->update('dashlet', [
                        'label' => $dashlet->getLabel(),
                        'url' => $dashlet->getUrl()->getRelativeUrl(),
                    ], [
                        'id = ?' => $orgDashlet->id,
                        'dashboard_id = ?' => $paneId
                    ]);

                    $pane->getDashlet($dashlet->getLabel())->setDashletId($orgDashlet->id);
                } else {
                    $db->insert('dashlet', [
                        'dashboard_id' => $paneId,
                        'owner' => $this->getUser()->getUsername(),
                        'name' => $dashlet->getName(),
                        'label' => $dashlet->getLabel(),
                        'url' => $dashlet->getUrl()->getRelativeUrl(),
                    ]);

                    $dashletId = $db->lastInsertId();
                    $pane->getDashlet($dashlet->getLabel())->setDashletId($dashletId);
                }
            }

            $panes[] = $pane;
        }

        $this->mergePanes($panes);
        return true;
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
     * Load user specific dashboards and dashlets from the database
     * and merges them to the dashboards loaded from an ini file
     *
     * @param   integer  $parentId
     *
     * @return  bool
     */
    public function loadUserDashboardsFromDatabase($parentId = 0)
    {
        $dashboards = array();
        if (Url::fromRequest()->hasParam('home') && $parentId === 0) {
            $home = Url::fromRequest()->getParam('home');
            $parentId = $this->homes[$home]->getAttribute('homeId');
        }

        $select = $this->getDb()->select((new Select())
            ->columns('*')
            ->from('dashboard')
            ->where(['home_id = ?' => $parentId]));

        foreach ($select as $dashboard) {
            if ((bool)$dashboard->disabled) {
                continue;
            }

            $dashboards[$dashboard->name] = (new Pane($dashboard->name))
                ->setPaneId($dashboard->id)
                ->setParentId($dashboard->home_id)
                ->setTitle($dashboard->label);

            if ($dashboard->name !== self::DEFAULT_HOME) {
                $dashboards[$dashboard->name]->setUserWidget();
            }

            $newResults = $this->getDb()->select((new Select())
                ->columns('*')
                ->from('dashlet')
                ->where(['dashboard_id = ?' => $dashboard->id, 'dashlet.owner = ?' => $this->user->getUsername()]));

            foreach ($newResults as $dashletData) {
                if ((bool)$dashletData->disabled) {
                    continue;
                }

                $dashlet = (new DashboardDashlet(
                    $dashletData->label,
                    $dashletData->url,
                    $dashboards[$dashboard->name]
                ))
                    ->setName($dashletData->name)
                    ->setDashletId($dashletData->id);

                if ($dashboard->name !== self::DEFAULT_HOME) {
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
        $this->loadHomeItems();
        if (! array_key_exists(self::USER_HOME, $this->homes)) {
            $db->insert('dashboard_home', [
                'name'  => self::USER_HOME,
                'owner' => $this->getUser()->getUsername()
            ]);

            $parent = $db->lastInsertId();
        } else {
            $parent = $this->homes[self::USER_HOME]->getAttribute('homeId');
        }

        foreach ($config as $key => $part) {
            if (strpos($key, '.') === false) {
                if ($pane = $this->hasHomePane($parent, $key)) {
                    $db->update('dashboard', [
                        'label'  => $part->get('title', $key),
                        'disabled'  => (int)$part->get('disabled', 0)
                    ], [
                        'home_id = ?'   => $parent,
                        'id = ?'        => $pane->id
                    ]);
                } else {
                    $db->insert('dashboard', [
                        'home_id'   => $parent,
                        'name'      => $key,
                        'label'     => $part->get('title', $key),
                        'disabled'  => (int)$part->get('disabled', 0)
                    ]);
                }
            } else {
                list($paneName, $dashletName) = explode('.', $key, 2);
                if ($pane = $this->hasHomePane($parent, $paneName)) {
                    if ($dashlet = $this->hasPaneDashlet($pane->id, $dashletName)) {
                        $db->update('dashlet', [
                            'label'     => $part->get('title', $dashletName),
                            'url'       => $part->get('url'),
                            'disabled'  => (int)$part->get('disabled', 0)
                        ], [
                            'dashboard_id = ?'  => $pane->id,
                            'id = ?'            => $dashlet->id
                        ]);
                    } else {
                        $db->insert('dashlet', [
                            'dashboard_id'  => $pane->id,
                            'owner'         => $this->getUser()->getUsername(),
                            'name'          => $dashletName,
                            'label'         => $part->get('title', $dashletName),
                            'url'           => $part->get('url'),
                            'disabled'      => (int)$part->get('disabled', 0)
                        ]);
                    }
                }
            }
        }

        return true;
    }

    public function hasHomePane($parent, $pane)
    {
        $select = (new Select())
            ->columns('*')
            ->from('dashboard')
            ->where(['home_id = ?'  => $parent, 'name = ?'  => $pane]);

        return $this->getConn()->select($select)->fetch();
    }

    public function hasPaneDashlet($paneId, $dashlet)
    {
        $select = (new Select())
            ->columns('*')
            ->from('dashlet')
            ->where(['dashboard_id = ?' => $paneId, 'name = ?'  => $dashlet]);

        return $this->getConn()->select($select)->fetch();
    }

    /**
     * Merge panes with existing panes
     *
     * @param   array $panes
     *
     * @return  $this
     */
    public function mergePanes(array $panes)
    {
        /** @var $pane Pane  */
        foreach ($panes as $pane) {
            if ($this->hasPane($pane->getName()) === true) {
                /** @var $current Pane */
                $current = $this->panes[$pane->getName()];

                if ($current->getParentId() === $pane->getParentId()) {
                    $this->getConn()->update('dashboard', [
                        'label' => $pane->getTitle(),
                    ], [
                        'id = ?'        => $pane->getPaneId(),
                        'home_id = ?'   => $pane->getParentId()
                    ]);

                    foreach ($current->getDashlets() as $dashlet) {
                        if (! $pane->hasDashlet($dashlet->getTitle())) {
                            $this->getConn()->insert('dashlet', [
                                'dashboard_id'  => $pane->getPaneId(),
                                'owner'         => $this->getUser()->getUsername(),
                                'name'          => $dashlet->getName(),
                                'label'         => $dashlet->getTitle(),
                                'url'           => $dashlet->getUrl()->getRelativeUrl()
                            ]);
                        }
                    }
                }
            } else {
                $this->panes[$pane->getName()] = $pane;
            }
        }

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
        $url = Url::fromPath('dashboard')->getUrlWithout($this->tabParam);
        if ($this->tabs === null) {
            $this->tabs = new Tabs();

            foreach ($this->panes as $key => $pane) {
                if ($pane->getDisabled()) {
                    continue;
                }
                if (Url::fromRequest()->hasParam('home')) {
                    try {
                        $url = Url::fromPath('dashboard/home')->addParams([
                            'home'   => $this->getHome($pane->getParentId())->getName(),
                        ]);
                    } catch (ProgrammingError $e) {
                        $url = Url::fromPath('dashboard/home');
                    }
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
                        'urlParams' => [$this->tabParam => $key]
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

    public function removePane($title)
    {
        if ($this->hasPane($title) === true) {
            $pane = $this->getPane($title);
            if ($pane->isUserWidget() === true) {
                unset($this->panes[$pane->getName()]);
            } else {
                $pane->setDisabled();
                $pane->setUserWidget();
            }
        } else {
            throw new ProgrammingError('Pane not found: ' . $title);
        }
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
        //TODO: Shall we replace this method with the one implemented below?
        $list = array();
        foreach ($this->panes as $name => $pane) {
            $list[$name] = $pane->getTitle();
        }
        return $list;
    }

    /**
     * Return an array with pane name=>title format used for comboboxes
     *
     * @param $homeId
     *
     * @return array
     */
    public function getPaneKeyNameArray($homeId)
    {
        $lists = [];
        foreach ($this->getPanes() as $name => $pane) {
            if ($pane->getParentId() !== (int)$homeId) {
                continue;
            }

            $lists[$name] = $pane->getTitle();
        }

        return $lists;
    }

    /**
     * @see Icinga\Web\Widget::render
     */
    public function render()
    {
        if (empty($this->panes)) {
            return '';
        }

        return $this->determineActivePane()->render();
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
        $active = $this->getTabs()->getActiveName();
        if (! $active) {
            if ($active = Url::fromRequest()->getParam($this->tabParam)) {
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
}
