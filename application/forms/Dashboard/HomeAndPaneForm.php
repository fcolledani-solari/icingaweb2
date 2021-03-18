<?php

namespace Icinga\Forms\Dashboard;

use Icinga\Web\Notification;
use Icinga\Web\Widget\Dashboard;
use ipl\Html\HtmlElement;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Url;

class HomeAndPaneForm extends CompatForm
{
    /** @var Dashboard */
    private $dashboard;

    private $navigation;

    /**
     * RenamePaneForm constructor.
     *
     * @param $dashboard
     */
    public function __construct($dashboard)
    {
        $this->dashboard = $dashboard;

        $requestPath = Url::fromRequest()->getPath();
        if ($requestPath === 'dashboard/rename-home') {
            $home = Url::fromRequest()->getParam('home');
            $this->populate(['name' => $home]);
        } elseif ($requestPath === 'dashboard/rename-pane') {
            $pane = $this->dashboard->getPane(Url::fromRequest()->getParam('pane'));
            $this->populate(['name'  => $pane->getName()]);
        }
    }

    /**
     * @inheritdoc
     *
     * @return bool
     */
    public function hasBeenSubmitted()
    {
        return $this->hasBeenSent()
            && ($this->getPopulatedValue('btn_remove')
                || $this->getPopulatedValue('btn_update'));
    }

    public function assemble()
    {
        $dashboardHomes = [];
        $home = Url::fromRequest()->getParam('home');
        $populated = $this->getPopulatedValue('home');
        if ($populated === null) {
            $dashboardHomes[$home] = $home;
        }

        foreach ($this->dashboard->getHomes() as $name => $homeItem) {
            $this->navigation[$name] = $homeItem;
            if (! array_key_exists($name, $dashboardHomes)) {
                $dashboardHomes[$name] = $homeItem->getName();
            }
        }

        $description    = t('Edit the current home name');
        $btnUpdateLabel = t('Update Home');
        $btnRemoveLabel = t('Remove Home');
        $formaction     = (string)Url::fromRequest()->setPath('dashboard/remove-home');

        if (Url::fromRequest()->getPath() === 'dashboard/rename-pane') {
            $description    = t('Edit the current pane name');
            $btnUpdateLabel = t('Update Pane');
            $btnRemoveLabel = t('Remove Pane');
            $formaction     = (string)Url::fromRequest()->setPath('dashboard/remove-pane');

            $this->addElement(
                'select',
                'home',
                [
                    'class'         => 'autosubmit',
                    'required'      => true,
                    'label'         => t('Move to home'),
                    'multiOptions'  => $dashboardHomes,
                    'description'   => t('Select a dashboard home you want to move the dashboard to.'),
                ]
            );
        }

        $this->addElement(
            'text',
            'name',
            [
                'required'      => true,
                'label'         => t('Name'),
                'description'   => $description
            ]
        );

        $this->add(
            new HtmlElement(
                'div',
                [
                    'class' => 'control-group form-controls',
                    'style' => 'position: relative; margin-right: 1em; margin-top: 2em;'
                ],
                [
                    new HtmlElement(
                        'input',
                        [
                            'class'             => 'btn-primary',
                            'type'              => 'submit',
                            'name'              => 'btn_remove',
                            'data-base-target'  => '_main',
                            'value'             => $btnRemoveLabel,
                            'formaction'        => $formaction
                        ]
                    ),
                    new HtmlElement(
                        'input',
                        [
                            'class' => 'btn-primary',
                            'type'  => 'submit',
                            'name'  => 'btn_update',
                            'value' => $btnUpdateLabel
                        ]
                    )
                ]
            )
        );
    }

    public function renamePane()
    {
        $orgParent = Url::fromRequest()->getParam('home');
        $newParent = $this->getValue('home');
        $parent = $this->navigation[$orgParent]->getAttribute('homeId');
        if ($orgParent !== $newParent) {
            $parent = $this->navigation[$newParent]->getAttribute('homeId');
        }

        $paneName = Url::fromRequest()->getParam('pane');
        $newName  = $this->getValue('name');

        $pane = $this->dashboard->getPane($paneName);
        $this->dashboard->getConn()->update('dashboard', [
            'home_id'   => $parent,
            'name'      => $pane->getName(),
        ], ['dashboard.id = ?' => $pane->getPaneId()]);

        Notification::success(
            sprintf(t('Pane "%s" successfully renamed to "%s"'), $paneName, $newName)
        );
    }

    public function removePane()
    {
        $pane = $this->dashboard->getPane(Url::fromRequest()->getParam('pane'));
        $db = $this->dashboard->getConn();

        if (Url::fromRequest()->getParam('home') === Dashboard::DEFAULT_HOME) {
            $db->update('dashboard', [
                'disabled'  => true
            ], ['id = ?'    => $pane->getPaneId()]);
        } else {
            $db->delete('dashlet', ['dashboard_id = ?' => $pane->getPaneId()]);
            $db->delete('dashboard', [
                'home_id = ?' => $pane->getParentId(),
                'id = ?'      => $pane->getPaneId(),
                'name = ?'    => $pane->getName()
            ]);
        }

        Notification::success(t('Dashboard has been removed') . ': ' . $pane->getTitle());
    }

    public function renameHome()
    {
        $homes = $this->dashboard->getHomes();
        $home = $homes[Url::fromRequest()->getParam('home')];

        $this->dashboard->getConn()->update('dashboard_home', [
            'name'   => $this->getValue('name')
        ], ['id = ?' => $home->getAttribute('homeId')]);

        Notification::success(
            sprintf(t('Home "%s" successfully renamed to "%s"'), $home->getName(), $this->getValue('name'))
        );
    }

    public function removeHome()
    {
        $homes = $this->dashboard->getHomes();
        $home = $homes[Url::fromRequest()->getParam('home')];

        $db = $this->dashboard->getConn();

        if ($home->getName() !== Dashboard::DEFAULT_HOME) {
            foreach ($this->dashboard->getPanes() as $pane) {
                if ($pane->getParentId() === $home->getAttribute('homeId')) {
                    $db->delete('dashlet', ['dashboard_id = ?'    => $pane->getPaneId()]);
                    $db->delete('dashboard', ['home_id = ?'       => $home->getAttribute('homeId')]);
                }
            }

            $db->delete('dashboard_home', ['id = ?' => $home->getAttribute('homeId')]);

            Notification::success(t('Dashboard home has been removed') . ': ' . $home->getName());
        } else {
            Notification::warning(sprintf(t('%s home can\'t be deleted.'), Dashboard::DEFAULT_HOME));
        }
    }

    public function onSuccess()
    {
        $requestPath = Url::fromRequest()->getPath();
        if ($requestPath === 'dashboard/rename-pane' || $requestPath === 'dashboard/remove-pane') {
            if ($this->getPopulatedValue('btn_update')) {
                $this->renamePane();
            } else {
                $this->removePane();
            }
        } else {
            if ($this->getPopulatedValue('btn_update')) {
                $this->renameHome();
            } else {
                $this->removeHome();
            }
        }
    }
}
