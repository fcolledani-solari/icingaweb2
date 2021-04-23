<?php
/* Icinga Web 2 | (c) 2013 Icinga Development Team | GPLv2+ */

namespace Icinga\Web\Widget;

use Icinga\Common\Database;
use Icinga\Exception\ConfigurationError;
use Icinga\Exception\ProgrammingError;
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

    /**
     * Name of the default home
     *
     * @var string
     */
    const DEFAULT_HOME = 'Default Home';

    /**
     * Default user of the "Default Home"
     *
     * @var string
     */
    const DEFAULT_HOME_USER = 'icingaweb2';

    /**
     * Preserve key name for coming features
     *
     * @var string
     */
    const AVAILABLE_DASHLETS = 'Available Dashlets';

    /**
     * Preserve key name for coming features
     *
     * @var string
     */
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
    private $tabParam = 'pane';

    /**
     * Home items loaded from the „dashboard“ menu item
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
        if (Url::fromRequest()->hasParam('home')) {
            $home = Url::fromRequest()->getParam('home');
            // If the home param being loaded does not match the default home do nothing
            if ($home !== self::DEFAULT_HOME) {
                return false;
            }
        }

        $db = $this->getConn();
        if (! array_key_exists(self::DEFAULT_HOME, $this->homes)) {
            $db->insert('dashboard_home', [
                'name'  => self::DEFAULT_HOME,
                'owner' => self::DEFAULT_HOME_USER
            ]);

            $parent = $db->lastInsertId();
        } else {
            $parent = $this->getHomeByName(self::DEFAULT_HOME)->getAttribute('homeId');
        }

        $navigation = new Navigation();
        $navigation->load('dashboard-pane');
        $this->setActiveHome($parent);

        $panes = [];
        /** @var NavigationItem $dashboardPane */
        foreach ($navigation as $dashboardPane) {
            $paneId = $this->getSHA1(self::DEFAULT_HOME_USER . self::DEFAULT_HOME . $dashboardPane->getName());
            $pane = (new Pane($dashboardPane->getName()))
                ->setTitle($dashboardPane->getLabel())
                ->setParentId($parent)
                ->setPaneId($paneId);

            $dashlets = [];
            /** @var NavigationItem $dashlet */
            foreach ($dashboardPane->getChildren() as $dashlet) {
                $dashletId = $this->getSHA1(
                    self::DEFAULT_HOME_USER . self::DEFAULT_HOME . $pane->getName() . $dashlet->getName()
                );

                $newDashlet = (new Dashlet($dashlet->getLabel(), $dashlet->getUrl()->getRelativeUrl(), $pane))
                    ->setName($dashlet->getName())
                    ->setDashletId($dashletId);

                $dashlets[$newDashlet->getName()] = $newDashlet;
            }

            $pane->addDashlets($dashlets);
            $panes[$dashboardPane->getName()] = $pane;
        }

        $this->mergePanes($panes);

        return true;
    }

    /**
     * Load user specific dashboards and dashlets from the database
     * and merges them to the system dashboards
     *
     * @param   integer  $parentId
     *
     * @return  bool
     */
    public function loadUserDashboards($parentId = 0)
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
            ->where([
                'home_id = ?'   => $parentId,
                'owner = ?'     => $this->user->getUsername()
            ]));

        $this->setActiveHome($parentId);

        $dashboards = [];
        foreach ($select as $dashboard) {
            $dashboards[$dashboard->name] = (new Pane($dashboard->name))
                ->setPaneId($dashboard->id)
                ->setParentId($dashboard->home_id)
                ->setTitle($dashboard->label)
                ->setOwner($dashboard->owner)
                ->setUserWidget();

            $newResults = $this->getDb()->select((new Select())
                ->columns('*')
                ->from('dashlet')
                ->where([
                    'dashboard_id = ?'  => $dashboard->id,
                    'dashlet.owner = ?' => $this->user->getUsername()
                ]));

            $dashlets = [];
            foreach ($newResults as $dashletData) {
                $dashlet = (new Dashlet(
                    $dashletData->label,
                    $dashletData->url,
                    $dashboards[$dashboard->name]
                ))
                    ->setName($dashletData->name)
                    ->setDashletId($dashletData->id)
                    ->setUserWidget();

                $dashlets[$dashlet->getName()] = $dashlet;
            }

            $dashboards[$dashboard->name]->addDashlets($dashlets);
        }

        $this->mergePanes($dashboards);

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
        // Get the id of the dashboard home which is currently loaded
        $activeHome = $this->getActiveHome();

        /** @var $pane Pane  */
        foreach ($panes as $pane) {
            if (empty($pane->getParentId()) || empty($pane->getPaneId())) {
                throw new ProgrammingError(
                    'Pane "%s" doesn\'t contain an \'%s\'.',
                    $pane->getName(),
                    $pane->getPaneId() ? 'home id' : 'identifier'
                );
            }

            // Skip when the parent id does not match the activeHome's id
            if ($activeHome && $activeHome !== $pane->getParentId()) {
                continue;
            }

            $currentPane = null;
            if ($this->hasPane($pane->getName())) {
                $currentPane = $this->getPane($pane->getName());
                // Check if the user has cloned system pane without modifying it
                if ($pane->getOwner() && ! $currentPane->getOwner()) {
                    // Cleaning up cloned system panes from the DB
                    if (! $pane->hasDashlets() && $pane->getTitle() === $currentPane->getTitle()) {
                        $this->getConn()->delete('dashboard', [
                            'id = ?'    => $pane->getPaneId(),
                            'owner = ?' => $pane->getOwner()
                        ]);

                        continue;
                    }
                }

                if (! $currentPane->getOwner() && $pane->getOwner()) {
                    $currentPane->setPaneId($pane->getPaneId());
                }
            }

            // Check if the pane does have an owner, if not it's a system pane
            if (! $pane->getOwner()) {
                $customPane = $this->getConn()->select((new Select())
                    ->columns('*')
                    ->from('dashboard_override')
                    ->where([
                        'owner = ?'         => $this->user->getUsername(),
                        'home_id = ?'       => $pane->getParentId(),
                        'dashboard_id = ?'  => $pane->getPaneId()
                    ]))->fetch();

                if ($customPane) {
                    // Remove the custom pane if label is null|rolled back to it's original value and is not disabled
                    if ((! $customPane->label || $customPane->label == $pane->getTitle()) &&
                        ! (bool)$customPane->disabled) {
                        $this->getConn()->delete('dashboard_override', ['dashboard_id = ?' => $pane->getPaneId()]);
                    } else {
                        $pane
                            ->setUserWidget()
                            ->setOverride(true)
                            ->setOwner($customPane->owner)
                            ->setDisabled($customPane->disabled);

                        if ($customPane->label) {
                            $pane->setTitle($customPane->label);
                        }
                    }
                }
            }

            /** @var Dashlet $dashlet */
            foreach ($pane->getDashlets() as $dashlet) {
                if (! $dashlet->isUserWidget()) {
                    $customDashlet = $this->getConn()->select((new Select())
                        ->columns('*')
                        ->from('dashlet_override')
                        ->where([
                            'owner = ?'         => $this->user->getUsername(),
                            'dashlet_id = ?'    => $dashlet->getDashletId(),
                            'dashboard_id = ?'  => $pane->getPaneId()
                        ]))->fetch();

                    if ($customDashlet) {
                        // Remove the custom dashlet if label & url are null|rolled back to their original
                        // value and is not disabled
                        if ((! $customDashlet->label || $customDashlet->label === $dashlet->getTitle()) &&
                            (! $customDashlet->url || $customDashlet->url === $dashlet->getUrl()->getRelativeUrl()) &&
                            ! (bool)$customDashlet->disabled) {
                            $this->getConn()->delete('dashlet_override', [
                                'dashlet_id = ?' => $dashlet->getDashletId()
                            ]);
                        } else {
                            $dashlet
                                ->setUserWidget()
                                ->setOverride()
                                ->setDisabled($customDashlet->disabled);

                            if ($customDashlet->url) {
                                $dashlet->setUrl($customDashlet->url);
                            }

                            if ($customDashlet->label) {
                                $dashlet->setTitle($customDashlet->label);
                            }
                        }
                    }
                }

                if ($currentPane) {
                    if (! $currentPane->hasDashlet($dashlet->getTitle())) {
                        $currentPane->addDashlet($dashlet);
                    } else {
                        $currentPane->getDashlet($dashlet->getTitle())
                            ->setUrl($dashlet->getUrl())
                            ->setName($dashlet->getName())
                            ->setDisabled($dashlet->getDisabled())
                            ->setUserWidget($dashlet->isUserWidget())
                            ->setOverride($dashlet->isOverridesSystem());
                    }
                }
            }

            if ($currentPane) {
                // We need to set these attributes here again because they might have changed above
                $currentPane
                    ->setTitle($pane->getTitle())
                    ->setOwner($pane->getOwner())
                    ->setDisabled($pane->getDisabled())
                    ->setUserWidget($pane->isUserWidget())
                    ->setOverride($pane->isOverridesSystem());

                continue;
            }

            $this->panes[$pane->getName()] = $pane;
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
        if (Url::fromRequest()->hasParam('home')) {
            $home = Url::fromRequest()->getParam('home');
            $url = Url::fromPath('dashboard/home')->getUrlWithout(['home', $this->tabParam]);
            $url->addParams(['home'  => $home]);
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

        // This is only required in DashboardController::homeAction()
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
            'Dashboard home with the given id does not exist: "%s"',
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
            'Dashboard home with the given name does not exist: "%s"',
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
                $this->getConn()->delete('dashboard_home', ['id = ?' => $parent]);
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
     * Reset the current position of the internal home object
     *
     * @return null|NavigationItem
     */
    public function rewindHomes()
    {
        return reset($this->homes);
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

        if ($pane->getOwner() && ! $pane->getDisabled()) {
            $tableName = 'dashboard';
            $rowName = 'id = ?';

            if ($pane->isOverridesSystem()) {
                $tableName = 'dashboard_override';
                $rowName = 'dashboard_id = ?';
            }

            $this->getConn()->delete($tableName, [
                $rowName    => $pane->getPaneId(),
                'owner = ?' => $pane->getOwner()
            ]);
        } elseif (! $pane->getDisabled()) {
            // User is going to disable this system pane
            $this->getConn()->insert('dashboard_override', [
                'dashboard_id'  => $pane->getPaneId(),
                'home_id'       => $pane->getParentId(),
                'owner'         => $this->user->getUsername(),
                'disabled'      => true
            ]);
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
    public function getPaneKeyTitleArray($parent = 0)
    {
        $list = [];
        foreach ($this->panes as $name => $pane) {
            if ($pane->getDisabled()) {
                continue;
            }

            // We don't allow any system panes to be listed at other homes as well
            if (! empty($parent) && $parent !== $pane->getParentId()) {
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
                $dashlets = new HtmlElement('h1', null, t('No dashlet added to this pane.'));
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
     * Generate the sha1 hash of the provided string
     *
     * @param string $name
     *
     * @return string
     */
    public function getSHA1($name)
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
    public function switchElementPos(array $list, $key)
    {
        array_unshift($list, $key);
        $list = array_unique($list);

        $keys = array_keys($list);
        $keys[array_search(0, $keys, true)] = $key;

        return array_combine($keys, $list);
    }
}
