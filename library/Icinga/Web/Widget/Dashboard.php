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

    /**
     * Name of the user dashboards home loaded from all
     *
     * config files that match the username
     *
     * @var string
     */
    const USER_HOME = 'User Home';

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
     * Load global dashboards provided by all enabled modules
     *
     * with the dashboard() method and store them in the DB
     */
    protected function loadGlobalDashboards()
    {
        if (Url::fromRequest()->hasParam('home')) {
            $home = Url::fromRequest()->getParam('home');
            // If the home param being loaded does not match the default home
            // we do not need to load anything
            if ($home !== self::DEFAULT_HOME) {
                return false;
            }

            if (array_key_exists(self::DEFAULT_HOME, $this->homes)) {
                if ($this->isLoadedInitially($this->homes[self::DEFAULT_HOME]->getAttribute('homeId'))) {
                    return false;
                }
            }
        }

        $navigation = new Navigation();
        $navigation->load('dashboard-pane');

        $db = $this->getConn();
        if (! array_key_exists(self::DEFAULT_HOME, $this->homes)) {
            $db->insert('dashboard_home', [
                'name'  => self::DEFAULT_HOME,
                'owner' => null
            ]);

            $parent = $db->lastInsertId();

            $db->insert('initially_loaded', ['home_id' => $parent]);
        } else {
            $parent = $this->homes[self::DEFAULT_HOME]->getAttribute('homeId');
        }

        $this->loadUserDashboardsFromDatabase($parent);
        foreach ($navigation as $dashboardPane) {
            if ($this->hasPane($dashboardPane->getName())) {
                $current = $this->getPane($dashboardPane->getName());

                $db->update('dashboard', [
                    'label' => $dashboardPane->getLabel()
                ], [
                    'id = ?'        => $current->getPaneId(),
                    'home_id = ?'   => $parent
                ]);

                $paneId = $current->getPaneId();
            } else {
                $db->insert('dashboard', [
                    'home_id'   => $parent,
                    'name'      => $dashboardPane->getName(),
                    'label'     => $dashboardPane->getLabel(),
                    'disabled'  => (int)$dashboardPane->getDisabled()
                ]);

                $this->createPane($dashboardPane->getLabel());
                $paneId = $db->lastInsertId();
            }

            $pane = $this->getPane($dashboardPane->getLabel());
            foreach ($dashboardPane->getChildren() as $dashlet) {
                if ($pane->hasDashlet($dashlet->getLabel())) {
                    $db->update('dashlet', [
                        'label' => $dashlet->getLabel(),
                        'url'   => $dashlet->getUrl()->getRelativeUrl(),
                    ], [
                        'id = ?'            => $pane->getDashlet($dashlet->getLabel())->getDashletId(),
                        'dashboard_id = ?'  => $paneId
                    ]);
                } else {
                    $db->insert('dashlet', [
                        'dashboard_id'  => $paneId,
                        'owner'         => null,
                        'name'          => $dashlet->getName(),
                        'label'         => $dashlet->getLabel(),
                        'url'           => $dashlet->getUrl()->getRelativeUrl(),
                    ]);
                }
            }
        }

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
        if (Url::fromRequest()->getParam('home')) {
            if ($parentId === 0) {
                $home = Url::fromRequest()->getParam('home');
                $parentId = $this->homes[$home]->getAttribute('homeId');
            }
        } elseif ($parentId === 0) {
            $home = $this->rewindHomes();
            $parentId = $home ? $home->getAttribute('homeId') : 0;
        }

        $select = $this->getConn()->select((new Select())
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

                $dashlet = (new Dashlet(
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

        if (array_key_exists(self::USER_HOME, $this->homes)) {
            if ($this->isLoadedInitially(
                $this->homes[self::USER_HOME]->getAttribute('homeId'),
                $this->user->getUsername()
            )) {
                return false;
            }
        }

        $db = $this->getConn();
        $this->loadHomeItems();
        if (! array_key_exists(self::USER_HOME, $this->homes)) {
            $db->insert('dashboard_home', [
                'name'  => self::USER_HOME,
                'owner' => $this->user->getUsername()
            ]);

            $parent = $db->lastInsertId();
            $db->insert('initially_loaded', [
                'home_id'   => $parent,
                'owner'     => $this->user->getUsername()
            ]);
        } else {
            $parent = $this->homes[self::USER_HOME]->getAttribute('homeId');
        }

        $this->loadUserDashboardsFromDatabase($parent);
        foreach ($config as $key => $part) {
            if (strpos($key, '.') === false) {
                if ($this->hasPane($key)) {
                    $pane = $this->getPane($key);

                    $db->update('dashboard', [
                        'label'     => $part->get('title', $key),
                        'disabled'  => (int)$part->get('disabled', 0)
                    ], [
                        'home_id = ?'   => $parent,
                        'id = ?'        => $pane->getPaneId()
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
                if ($this->hasPane($paneName)) {
                    $pane = $this->getPane($paneName);
                    if ($pane->hasDashlet($dashletName)) {
                        $db->update('dashlet', [
                            'label'     => $part->get('title', $dashletName),
                            'url'       => $part->get('url'),
                            'disabled'  => (int)$part->get('disabled', 0)
                        ], [
                            'dashboard_id = ?'  => $pane->getPaneId(),
                            'id = ?'            => $pane->getDashlet($dashletName)->getDashletId()
                        ]);
                    } else {
                        $db->insert('dashlet', [
                            'dashboard_id'  => $pane->getPaneId(),
                            'owner'         => $this->user->getUsername(),
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
                                'owner'         => $this->user->getUsername(),
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
        $url = Url::fromPath('dashboards')->getUrlWithout($this->tabParam);
        if ($this->tabs === null) {
            $this->tabs = new Tabs();
            $this->tabs->disableLegacyExtensions();

            foreach ($this->panes as $key => $pane) {
                if ($pane->getDisabled()) {
                    continue;
                }
                if (Url::fromRequest()->hasParam('home')) {
                    try {
                        $url = Url::fromPath('dashboards/home')->addParams([
                            'home'   => $this->getHomeById($pane->getParentId())->getName(),
                        ]);
                    } catch (ProgrammingError $e) {
                        $url = Url::fromPath('dashboards/home');
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

    public function removeHome($home)
    {
        if (array_key_exists($home, $this->homes)) {
            foreach ($this->panes as $pane) {
                $pane->removeDashlets();
            }

            $parent = $this->homes[$home]->getAttribute('homeId');
            $this->removePanes($parent);

            $this->getConn()->delete('initially_loaded', ['home_id = ?'  => $parent]);
            $this->getConn()->delete('dashboard_home', ['id = ?'    => $parent]);
        } else {
            throw new ProgrammingError('Home does not exist: ' . $home);
        }
    }

    protected function isLoadedInitially($home, $user = null)
    {
        $select = (new Select())
            ->columns('*')
            ->from('initially_loaded')
            ->where(['home_id = ?'  => $home, 'owner = ?'   => $user]);

        return $this->getConn()->select($select)->fetch();
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

    /**
     * Remove a specific pane from this dashboard
     *
     * @param $title
     *
     * @return $this
     *
     * @throws ProgrammingError
     */
    public function removePane($title)
    {
        if ($this->hasPane($title) === true) {
            $pane = $this->getPane($title);
            if ($pane->isUserWidget() === true) {
                $this->getConn()->delete('dashboard', ['id = ?' => $pane->getPaneId()]);
            } else {
                $this->getConn()->update('dashboard', [
                    'disabled'  => true
                ], ['id = ?'    => $pane->getPaneId()]);
            }
        } else {
            throw new ProgrammingError('Pane not found: ' . $title);
        }

        return $this;
    }

    public function removePanes($parent, $panes = [])
    {
        if (empty($panes)) {
            $default = $this->getHomeByName(self::DEFAULT_HOME);
            if ($this->getHomeById($parent)->getAttribute('homeId') === $default->getAttribute('homeId')) {
                $this->getConn()->update('dashboard', [
                    'disabled'   => true
                ], ['home_id = ?'   => $default]);
            } else {
                $this->getConn()->delete('dashboard', ['home_id = ?'    => $parent]);
            }
        } else {
            foreach ($panes as $pane) {
                $this->removePane($pane);
            }
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

    public function assemble()
    {
        $this->add($this->determineActivePane()->getDashlets());
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
}
