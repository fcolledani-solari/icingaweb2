<?php
/* Icinga Web 2 | (c) 2018 Icinga Development Team | GPLv2+ */

namespace Icinga\Web;

use Icinga\Application\Logger;
use Icinga\Authentication\Auth;
use Icinga\Common\Database;
use Icinga\Web\Navigation\Navigation;
use ipl\Sql\Select;

/**
 * Main menu for Icinga Web 2
 */
class Menu extends Navigation
{
    use Database;

    /**
     * Create the main menu
     */
    public function __construct()
    {
        $this->init();
        $this->loadDashboardHomeFromDatabase();
        $this->load('menu-item');
    }

    /**
     * Setup the main menu
     */
    public function init()
    {
        $this->addItem('dashboard', [
            'label'     => t('Dashboard'),
            'url'       => 'dashboard',
            'icon'      => 'dashboard',
            'priority'  => 10
        ]);
        $this->addItem('system', [
            'label'     => t('System'),
            'icon'      => 'services',
            'priority'  => 700,
            'renderer'  => [
                'SummaryNavigationItemRenderer',
                'state' => 'critical'
            ],
            'children'  => [
                'about' => [
                    'icon'        => 'info',
                    'description' => t('Open about page'),
                    'label'       => t('About'),
                    'url'         => 'about',
                    'priority'    => 700
                ],
                'announcements' => [
                    'icon'        => 'megaphone',
                    'description' => t('List announcements'),
                    'label'       => t('Announcements'),
                    'url'         => 'announcements',
                    'priority'    => 710
                ]
            ]
        ]);
        $this->addItem('configuration', [
            'label'         => t('Configuration'),
            'icon'          => 'wrench',
            'permission'    => 'config/*',
            'priority'      => 800,
            'children'      => [
                'application' => [
                    'icon'        => 'wrench',
                    'description' => t('Open application configuration'),
                    'label'       => t('Application'),
                    'url'         => 'config',
                    'priority'    => 810
                ],
                'authentication' => [
                    'icon'        => 'users',
                    'description' => t('Open access control configuration'),
                    'label'       => t('Access Control'),
                    'permission'  => 'config/access-control/*',
                    'priority'    => 830,
                    'url'         => 'role'
                ],
                'navigation' => [
                    'icon'        => 'sitemap',
                    'description' => t('Open shared navigation configuration'),
                    'label'       => t('Shared Navigation'),
                    'url'         => 'navigation/shared',
                    'permission'  => 'config/navigation',
                    'priority'    => 840,
                ],
                'modules' => [
                    'icon'        => 'cubes',
                    'description' => t('Open module configuration'),
                    'label'       => t('Modules'),
                    'url'         => 'config/modules',
                    'permission'  => 'config/modules',
                    'priority'    => 890
                ]
            ]
        ]);
        $this->addItem('user', [
            'cssClass'  => 'user-nav-item',
            'label'     => Auth::getInstance()->getUser()->getUsername(),
            'icon'      => 'user',
            'priority'  => 900,
            'children'  => [
                'account' => [
                    'icon'        => 'sliders',
                    'description' => t('Open your account preferences'),
                    'label'       => t('My Account'),
                    'priority'    => 100,
                    'url'         => 'account'
                ],
                'logout' => [
                    'icon'        => 'off',
                    'description' => t('Log out'),
                    'label'       => t('Logout'),
                    'priority'    => 200,
                    'attributes'  => ['target' => '_self'],
                    'url'         => 'authentication/logout'
                ]
            ]
        ]);

        if (Logger::writesToFile()) {
            $this->getItem('system')->addChild($this->createItem('application_log', [
                'icon'        => 'doc-text',
                'description' => t('Open Application Log'),
                'label'       => t('Application Log'),
                'url'         => 'list/applicationlog',
                'permission'  => 'application/log',
                'priority'    => 900
            ]));
        }
    }

    /**
     * Load user specific and shared dashboard homes from the db
     *
     * and append them as child items to the dashboard menu
     */
    protected function loadDashboardHomeFromDatabase()
    {
        $dashboardHomes = $this->getDb()->select((new Select())
            ->columns('*')
            ->from('dashboard_home as dh')
            ->where([
                'dh.owner = ?'  => Auth::getInstance()->getUser()->getUsername(),
                'dh.owner IS NULL'
            ], 'OR'));

        $priority = 10;
        foreach ($dashboardHomes as $dashboardHome) {
            $this->getItem('dashboard')->addChild($this->createItem($dashboardHome->name, [
                'label'         => t($dashboardHome->name),
                'description'   => $dashboardHome->name,
                'url'           => 'dashboard/home?home=' . $dashboardHome->name . '&homeId=' . $dashboardHome->id,
                'priority'      => $priority,
                'owner'         => $dashboardHome->owner
            ]));

            $priority += 10;
        }
    }
}
