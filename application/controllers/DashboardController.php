<?php
/* Icinga Web 2 | (c) 2013 Icinga Development Team | GPLv2+ */

namespace Icinga\Controllers;

use GuzzleHttp\Psr7\ServerRequest;
use Icinga\Application\Icinga;
use Icinga\Common\Database;
use Icinga\Forms\Dashboard\AvailableDashlets;
use Icinga\Forms\Dashboard\RemovalForm;
use Icinga\Forms\Dashboard\RenamePaneForm;
use Icinga\Web\Controller\ActionController;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Forms\Dashboard\DashletForm;
use Icinga\Web\Widget\Dashboard;
use Icinga\Web\Widget\Tabextension\DashboardSettings;
use Zend_Controller_Action_Exception;
use ipl\Web\Url;

/**
 * Handle creation, removal and displaying of dashboards, panes and dashlets
 *
 * @see Icinga\Web\Widget\Dashboard for more information about dashboards
 */
class DashboardController extends ActionController
{
    use Database;

    /**
     * @var Dashboard;
     */
    private $dashboard;

    public function init()
    {
        $this->dashboard = new Dashboard();
        $this->dashboard->setUser($this->Auth()->getUser());
        $this->dashboard->load();
    }

    public function newDashletAction()
    {
        $this->getTabs()->add('new-dashlet', array(
            'active'    => true,
            'label'     => $this->translate('New Dashlet'),
            'url'       => $this->getRequest()->getUrl()
        ));

        $dashboard = $this->dashboard->unloadDefaultPanes();
        $dashletForm = new DashletForm($dashboard);
        $dashletForm->on(DashletForm::ON_SUCCESS, function () use ($dashletForm, $dashboard) {
            $navigation = $dashboard->getDashboardHomeItems();
            if (! array_key_exists($dashletForm->getValue('home'), $navigation)) {
                $dashboard->loadDashboardHomeItems();

                $navigation = $dashboard->getDashboardHomeItems();
            }

            $this->redirectNow(Url::fromPath('dashboard/home')->addParams([
                'home'      => $dashletForm->getValue('home'),
                'homeId'    => $navigation[$dashletForm->getValue('home')]->getUrl()->getParam('homeId'),
                'pane'      => $dashletForm->getValue('pane'),
            ]));
        })->handleRequest(ServerRequest::fromGlobals());

        if ($this->_request->getParam('url')) {
            $params = $this->_request->getParams();
            $params['url'] = rawurldecode($this->_request->getParam('url'));
            $dashletForm->populate($params);
        }

        $this->view->form = $dashletForm;
    }

    public function updateDashletAction()
    {
        $this->getTabs()->add('update-dashlet', array(
            'active'    => true,
            'label'     => $this->translate('Update Dashlet'),
            'url'       => $this->getRequest()->getUrl()
        ));

        if (! $this->_request->getParam('pane')) {
            throw new Zend_Controller_Action_Exception(
                'Missing parameter "pane"',
                400
            );
        }
        if (! $this->_request->getParam('dashlet')) {
            throw new Zend_Controller_Action_Exception(
                'Missing parameter "dashlet"',
                400
            );
        }

        $dashboard = $this->dashboard->unloadDefaultPanes();
        $pane = $dashboard->getPane($this->getParam('pane'));
        $dashlet = $pane->getDashlet($this->getParam('dashlet'));
        $dashletForm = (new DashletForm($dashboard, $pane->getName()))
            ->on(DashletForm::ON_SUCCESS, function () {
                $this->redirectNow('dashboard/settings');
            })
            ->handleRequest(ServerRequest::fromGlobals());

        $dashletForm->load($dashlet);

        $this->view->form = $dashletForm;
    }

    public function removeDashletAction()
    {
        $this->getTabs()->add('remove-dashlet', array(
            'active'    => true,
            'label'     => $this->translate('Remove Dashlet'),
            'url'       => $this->getRequest()->getUrl()
        ));

        if (! $this->_request->getParam('pane')) {
            throw new Zend_Controller_Action_Exception(
                'Missing parameter "pane"',
                400
            );
        }
        if (! $this->_request->getParam('dashlet')) {
            throw new Zend_Controller_Action_Exception(
                'Missing parameter "dashlet"',
                400
            );
        }
        $pane = $this->_request->getParam('pane');
        $dashboard = $this->dashboard->unloadDefaultPanes();
        $dashletForm = (new RemovalForm($dashboard, $pane))
            ->on(RemovalForm::ON_SUCCESS, function () {
                $this->redirectNow('dashboard/settings');
            })
            ->handleRequest(ServerRequest::fromGlobals());

        $this->view->pane = $pane;
        $this->view->dashlet = $dashboard->getPane($pane)->getDashlet($this->getRequest()->getParam('dashlet'));
        $this->view->form = $dashletForm;
    }

    public function renamePaneAction()
    {
        $this->getTabs()->add('update-pane', [
            'title' => $this->translate('Update Pane'),
            'url'   => $this->getRequest()->getUrl()
        ])->activate('update-pane');

        $dashboard = $this->dashboard->unloadDefaultPanes();
        $paneName = $this->params->getRequired('pane');
        if (! $dashboard->hasPane($paneName)) {
            throw new HttpNotFoundException('Pane not found');
        }

        $paneForm = (new RenamePaneForm($dashboard))
            ->on(RenamePaneForm::ON_SUCCESS, function () {
                $this->redirectNow('dashboard/settings');
            })
            ->handleRequest(ServerRequest::fromGlobals());

        $this->view->form = $paneForm;
    }

    public function removePaneAction()
    {
        if (! $this->_request->getParam('pane')) {
            throw new Zend_Controller_Action_Exception(
                'Missing parameter "pane"',
                400
            );
        }
        $pane = $this->_request->getParam('pane');
        $this->getTabs()->add('remove-pane', [
            'active'    => true,
            'title'     => sprintf($this->translate('Remove Dashboard: %s'), $pane),
            'url'       => $this->getRequest()->getUrl()
        ]);

        $dashboard = $this->dashboard->unloadDefaultPanes();
        $paneForm = (new RemovalForm($dashboard, $pane))
            ->on(RemovalForm::ON_SUCCESS, function () {
                $this->redirectNow('dashboard/settings');
            })
            ->handleRequest(ServerRequest::fromGlobals());

        $this->view->form = $paneForm;
    }

    public function homeAction()
    {
        $dashboardHome = $this->translate($this->params->getRequired('home'));
        $homeOwner = $this->dashboard->getDashboardHomeItems()[$dashboardHome]->getAttribute('owner');

        if ($dashboardHome === 'Available Dashlets' || $homeOwner === null) {
            $this->view->tabeleView = true;

            $this->getTabs()->add($dashboardHome, [
                'label' => $dashboardHome,
                'url'   => $this->getRequest()->getUrl()
            ])->activate($dashboardHome);

            if ($dashboardHome === 'Available Dashlets') {
                $moduleManager = Icinga::app()->getModuleManager();
                $dashlets = [];

                foreach ($moduleManager->getLoadedModules() as $module) {
                    if ($this->dashboard->getUser()->can($moduleManager::MODULE_PERMISSION_NS . $module->getName())) {
                        if (empty($module->getDashletHomes())) {
                            continue;
                        }

                        $dashlets[$module->getName()] = $module->getDashletHomes();
                    }
                }

                $dashlet = new AvailableDashlets($dashlets);
                $this->view->dashlets = $dashlet;
            }
        } else {
            // Table view and dashboard/dashlets view have different div contents
            // so we need to set tableView to false
            $this->view->tabeleView = false;
            $dashboard = $this->dashboard->unloadDefaultPanes();
            $this->view->tabs = $dashboard->getTabs(true)->extend(new DashboardSettings());

            if ($this->params->get('pane')) {
                $pane = $this->params->get('pane');
                $dashboard->activate($pane);
            }

            $this->view->dashboard = $dashboard;
        }
    }

    public function homeDetailAction()
    {
        $dashlet = $this->params->get('dashlet');
        $this->getTabs()->add($dashlet, [
            'label' => $this->params->get('module') . ' Dashboard',
            'url'   => $this->getRequest()->getUrl()
        ])->activate($dashlet);

        $dashletWidget = new Dashboard\Dashlet($dashlet, $this->getRequest()->getUrl());
        $this->view->dashlets = $dashletWidget;
    }

    /**
     * Display the dashboard with the pane set in the 'pane' request parameter
     *
     * If no pane is submitted or the submitted one doesn't exist, the default pane is
     * displayed (normally the first one)
     */
    public function indexAction()
    {
        $this->createTabs();
        if (! $this->dashboard->hasPanes()) {
            $this->view->title = 'Dashboard';
        } else {
            $panes = array_filter(
                $this->dashboard->getPanes(),
                function ($pane) {
                    return ! $pane->getDisabled();
                }
            );
            if (empty($panes)) {
                $this->view->title = 'Dashboard';
                $this->getTabs()->add('dashboard', array(
                    'active'    => true,
                    'title'     => $this->translate('Dashboard'),
                    'url'       => $this->getRequest()->getUrl()
                ));
            } else {
                if ($this->_getParam('pane')) {
                    $pane = $this->_getParam('pane');
                    $this->dashboard->activate($pane);
                }
                if ($this->dashboard === null) {
                    $this->view->title = 'Dashboard';
                } else {
                    $this->view->title = $this->dashboard->getActivePane()->getTitle() . ' :: Dashboard';
                    if ($this->hasParam('remove')) {
                        $this->dashboard->getActivePane()->removeDashlet($this->getParam('remove'));
                        $this->dashboard->getConfig()->saveIni();
                        $this->redirectNow($this->getRequest()->getUrl()->remove('remove'));
                    }
                    $this->view->dashboard = $this->dashboard;
                }
            }
        }
    }

    /**
     * Setting dialog
     */
    public function settingsAction()
    {
        $dashboard = $this->dashboard->unloadDefaultPanes();
        $this->view->tabs = $dashboard->getTabs()->extend(new DashboardSettings());
        $this->view->dashboard = $dashboard;
    }

    /**
     * Create tab aggregation
     */
    private function createTabs()
    {
        $this->view->tabs = $this->dashboard->getTabs()->extend(new DashboardSettings());
    }
}
