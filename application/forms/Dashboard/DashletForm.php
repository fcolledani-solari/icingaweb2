<?php
/* Icinga Web 2 | (c) 2013 Icinga Development Team | GPLv2+ */

namespace Icinga\Forms\Dashboard;

use Icinga\Exception\ProgrammingError;
use Icinga\Web\Notification;
use Icinga\Web\Widget\Dashboard;
use Icinga\Web\Dashboard\Dashlet;
use ipl\Html\Html;
use ipl\Html\HtmlElement;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Url;

/**
 * Form to add an url a dashboard pane
 */
class DashletForm extends CompatForm
{
    /** @var Dashboard */
    private $dashboard;

    /** @var array dashboard pane items name=>title */
    private $panes = [];

    public $paneName;

    /**
     * DashletForm constructor.
     *
     * @param Dashboard $dashboard
     */
    public function __construct($dashboard)
    {
        $this->setDashboard($dashboard);
    }

    /**
     * @inheritdoc
     *
     * @return bool
     */
    public function hasBeenSubmitted()
    {
        return $this->hasBeenSent()
            && ($this->getPopulatedValue('remove_dashlet')
                || $this->getPopulatedValue('submit'));
    }

    protected function assemble()
    {
        $dashboardHomes = [];
        $home = Url::fromRequest()->getParam('home');
        $populated = $this->getPopulatedValue('home');

        if ($this->dashboard) {
            $dashboardHomes = $this->dashboard->getHomeKeyNameArray();
            if (empty($populated) && ! empty($home)) {
                $dashboardHomes = $this->dashboard->changeElementPos($dashboardHomes, $home);
            }

            if (empty($populated) && $this->getPopulatedValue('create_new_home') !== 'y') {
                if (! empty($home)) {
                    $this->panes = $this->dashboard->getPaneKeyTitleArray();
                } else {
                    // This tab was opened from where the home parameter is not present
                    $firstHome = $this->dashboard->rewindHomes();
                    if (! empty($firstHome)) {
                        // Load dashboards from the DB by the given home Id
                        $this->dashboard->loadUserDashboardsFromDatabase($firstHome->getAttribute('homeId'));
                        $this->panes = $this->dashboard->getPaneKeyTitleArray();
                    }
                }
            } else {
                if (array_key_exists($populated, $dashboardHomes)) {
                    $homeId = $this->dashboard->getHomes()[$populated]->getAttribute('homeId');
                    // We have to load dashboards each time the home Id changed
                    $this->dashboard->loadUserDashboardsFromDatabase($homeId);
                    $this->panes = $this->dashboard->getPaneKeyTitleArray();
                }
            }
        }

        if (Url::fromRequest()->getPath() === 'dashboard/remove-dashlet') {
            $this->add(new HtmlElement('h1', null, sprintf(
                t('Please confirm removal of dashlet "%s"'),
                Url::fromRequest()->getParam('dashlet')
            )));
            $this->addElement('submit', 'remove_dashlet', [
                'label'          => t('Remove Dashlet'),
            ]);
        } else {
            $submitLabel = t('Add To Dashboard');
            $formTitle = t('Add Dashlet To Dashboard');

            if (Url::fromRequest()->getPath() === 'dashboard/update-dashlet') {
                $submitLabel = t('Update Dashlet');
                $formTitle = t('Edit Dashlet');
            }

            $this->add(Html::tag('h1', $formTitle));
            $this->addElement('hidden', 'org_pane', ['required'     => false]);
            $this->addElement('hidden', 'org_parentId', ['required' => false]);
            $this->addElement('hidden', 'org_dashlet', ['required'  => false]);
            $this->addElement(
                'checkbox',
                'create_new_home',
                [
                    'class'         => 'autosubmit',
                    'disabled'      => empty($dashboardHomes) ?: null,
                    'required'      => false,
                    'label'         => t('New Dashboard Home'),
                    'description'   => t('Check this box if you want to add the dashboard to a new dashboard home.'),
                ]
            );

            $shouldDisable = empty($this->panes) || $this->getPopulatedValue('create_new_home') === 'y';
            if (empty($dashboardHomes) || $this->getPopulatedValue('create_new_home') === 'y') {
                if (empty($dashboardHomes)) {
                    $this->getElement('create_new_home')->addAttributes(['checked' => 'checked']);
                }
                $this->addElement(
                    'text',
                    'home',
                    [
                        'required'      => true,
                        'label'         => t('Dashboard Home'),
                        'description'   => t('Enter a title for the new dashboard'),
                    ]
                );
            } else {
                if (! empty($this->panes)) {
                    $shouldDisable = false;
                }
                $this->addElement(
                    'select',
                    'home',
                    [
                        'class'         => 'autosubmit',
                        'required'      => true,
                        'label'         => t('Dashboard Home'),
                        'multiOptions'  => $dashboardHomes,
                        'description'   => t('Select a home you want to add the pane to'),
                    ]
                );
            }

            $this->addElement(
                'checkbox',
                'create_new_pane',
                [
                    'class'         => 'autosubmit',
                    'disabled'      => $shouldDisable ?: null,
                    'required'      => false,
                    'label'         => t('New Dashboard'),
                    'description'   => t('Check this box if you want to add the dashlet to a new dashboard'),
                ]
            );

            if (empty($this->panes) || $shouldDisable || $this->getPopulatedValue('create_new_pane') === 'y') {
                if ($shouldDisable) {
                    $this->getElement('create_new_pane')->addAttributes(['checked' => 'checked']);
                }
                $this->addElement(
                    'text',
                    'pane',
                    [
                        'required'      => true,
                        'label'         => t('New Dashboard Title'),
                        'description'   => t('Enter a title for the new dashboard'),
                    ]
                );
            } else {
                $this->addElement(
                    'select',
                    'pane',
                    [
                        'required'      => true,
                        'label'         => t('Dashboard'),
                        'multiOptions'  => $this->panes,
                        'description'   => t('Select a dashboard you want to add the dashlet to'),
                    ]
                );
            }

            $this->add(new HtmlElement('hr'));
            if (Url::fromRequest()->getPath() === 'dashboard/update-dashlet') {
                $pane = $this->dashboard->getPane(Url::fromRequest()->getParam('pane'));
                $dashlet = $pane->getDashlet(Url::fromRequest()->getParam('dashlet'));
                if ($dashlet->getDisabled()) {
                    $this->addElement(
                        'checkbox',
                        'enable_dashlet',
                        [
                            'label'         => t('Enable Dashlet'),
                            'value'         => 'y',
                            'description'   => t('Uncheck this checkbox if you want to enable this dashlet.')
                        ]
                    );
                }
            }
            $this->addElement(
                'textarea',
                'url',
                [
                    'required'      => true,
                    'label'         => t('Url'),
                    'description'   => t(
                        'Enter url to be loaded in the dashlet. You can paste the full URL, including filters.'
                    ),
                ]
            );

            $this->addElement(
                'text',
                'dashlet',
                [
                    'required'      => true,
                    'label'         => t('Dashlet Title'),
                    'description'   => t('Enter a title for the dashlet.'),
                ]
            );
            $this->add(
                new HtmlElement(
                    'div',
                    [
                        'class' => 'control-group form-controls',
                        'style' => 'position: relative;  margin-top: 2em;'
                    ],
                    [
                        Url::fromRequest()->getPath() !== 'dashboard/update-dashlet' ? '' :
                            new HtmlElement(
                                'input',
                                [
                                    'class'         => 'btn-primary',
                                    'type'          => 'submit',
                                    'name'          => 'remove_dashlet',
                                    'value'         => t('Remove Dashlet'),
                                    'formaction'    => (string)Url::fromRequest()->setPath('dashboard/remove-dashlet')
                                ]
                            ),
                        new HtmlElement(
                            'input',
                            [
                                'class' => 'btn-primary',
                                'type'  => 'submit',
                                'name'  => 'submit',
                                'value' => $submitLabel
                            ]
                        ),
                    ]
                )
            );
        }
    }

    public function createDashlet()
    {
        $homes = $this->dashboard->getHomes();
        $db = $this->dashboard->getConn();
        if (! array_key_exists($this->getValue('home'), $homes)) {
            $db->insert('dashboard_home', [
                'name'  => $this->getValue('home'),
                'owner' => $this->dashboard->getUser()->getUsername()
            ]);

            $newParent = $db->lastInsertId();
        } else {
            $newParent = $homes[$this->getValue('home')]->getAttribute('homeId');
        }

        try {
            $this->paneName = $this->getValue('pane');
            if (in_array($this->paneName, $this->panes)) {
                $this->paneName = array_search($this->paneName, $this->panes);
            }

            $pane = $this->dashboard->getPane($this->paneName);
            $paneId = $pane->getPaneId();
        } catch (ProgrammingError $e) {
            $db->insert('dashboard', [
                'home_id'   => $newParent,
                'name'      => $this->getValue('pane'),
                'label'     => $this->getValue('pane'),
            ]);

            $paneId = $db->lastInsertId();
            $pane = null;
        }

        try {
            if (! empty($pane)) {
                $dashlet = array_filter($pane->getDashlets(), function ($dashlet) {
                    if ($dashlet->getName() === $this->getValue('dashlet')) {
                        return $dashlet;
                    }
                });
            } else {
                $dashlet = null;
            }

            if (empty($dashlet)) {
                throw new ProgrammingError('Dashlet does not exist.');
            }

            Notification::info(t('There already exists a Dashlet with the same name.'));
        } catch (ProgrammingError $err) {
            $db->insert('dashlet', [
                'dashboard_id'  => $paneId,
                'owner'         => $this->dashboard->getUser()->getUsername(),
                'name'          => $this->getValue('dashlet'),
                'label'         => $this->getValue('dashlet'),
                'url'           => $this->getValue('url')
            ]);

            Notification::success(t('Dashlet created'));
        }
    }

    public function updateDashlet()
    {
        $homes = $this->dashboard->getHomes();
        $db = $this->dashboard->getConn();

        $orgParent = (int)$this->getValue('org_parentId');
        $pane = $this->dashboard->getPane($this->getValue('org_pane'));
        $dashlet = $pane->getDashlet($this->getValue('org_dashlet'));

        if (! empty($dashlet->getGlobalUid())) {
            if ($this->getPopulatedValue('enable_dashlet') === 'n') {
                $db->update('dashlet', [
                    'disabled'  => (int)false
                ], ['id = ?'    => $dashlet->getDashletId()]);
            }

            Notification::info(sprintf(t('Default dashlet "%s" can\'t be edited'), $dashlet->getTitle()));
            return;
        }

        if (Url::fromRequest()->getParam('home') === $this->getValue('home')) {
            $newParent = $orgParent;
        } elseif (array_key_exists($this->getValue('home'), $homes)) {
            $newParent = $homes[$this->getValue('home')]->getAttribute('homeId');
        } else {
            $db->insert('dashboard_home', [
                'name'  => $this->getValue('home'),
                'owner' => $this->dashboard->getUser()->getUsername()
            ]);

            $newParent = $db->lastInsertId();
        }

        if ($pane->getParentId() !== $orgParent) {
            $this->dashboard->loadUserDashboardsFromDatabase($orgParent);
            $pane = $this->dashboard->getPane($this->getValue('org_pane'));
        }

        $paneId = $pane->getPaneId();
        $this->dashboard->loadUserDashboardsFromDatabase($newParent);
        if ($this->dashboard->hasPane($this->getValue('pane'))) {
            $newPane = $this->dashboard->getPane($this->getValue('pane'));
            if ($newPane->getParentId() === $newParent) {
                if ($paneId !== $newPane->getPaneId()) {
                    $paneId = $newPane->getPaneId();
                }
            } else {
                $db->insert('dashboard', [
                    'home_id'   => $newParent,
                    'name'      => $this->getValue('pane'),
                    'label'     => $this->getValue('pane'),
                ]);

                $paneId = $db->lastInsertId();
            }
        } else {
            $db->insert('dashboard', [
                'home_id'   => $newParent,
                'name'      => $this->getValue('pane'),
                'label'     => $this->getValue('pane'),
            ]);

            $paneId = $db->lastInsertId();
        }

        $db->update('dashlet', [
            'dashboard_id'  => $paneId,
            'owner'         => $this->dashboard->getUser()->getUsername(),
            'name'          => $dashlet->getName(),
            'label'         => $this->getValue('dashlet'),
            'url'           => $this->getValue('url')
        ], ['dashlet.id=?'  => $dashlet->getDashletId()]);

        Notification::success(t('Dashlet updated'));
    }

    public function onSuccess()
    {
        if (Url::fromRequest()->getPath() === 'dashboard/new-dashlet') {
            $this->createDashlet();
        } else {
            if ($this->getPopulatedValue('remove_dashlet')) {
                $dashlet = Url::fromRequest()->getParam('dashlet');
                $pane = $this->dashboard->getPane(Url::fromRequest()->getParam('pane'));
                $pane->removeDashlet($dashlet);

                Notification::success(t('Dashlet has been removed from') . ' ' . $pane->getTitle());
            } else {
                $this->updateDashlet();
            }
        }
    }

    /**
     * @param \Icinga\Web\Widget\Dashboard $dashboard
     */
    public function setDashboard(Dashboard $dashboard)
    {
        $this->dashboard = $dashboard;
    }

    /**
     * @return \Icinga\Web\Widget\Dashboard
     */
    public function getDashboard()
    {
        return $this->dashboard;
    }

    /**
     * @param Dashlet $dashlet
     */
    public function load(Dashlet $dashlet)
    {
        $this->populate(array(
            'pane'          => $dashlet->getPane()->getName(),
            'org_pane'      => $dashlet->getPane()->getName(),
            'org_parentId'  => $dashlet->getPane()->getParentId(),
            'dashlet'       => $dashlet->getTitle(),
            'org_dashlet'   => $dashlet->getName(),
            'url'           => $dashlet->getUrl()->getRelativeUrl()
        ));
    }
}
