<?php

namespace Icinga\Forms\Dashboard;

use Icinga\Web\Notification;
use Icinga\Web\Widget\Dashboard;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Url;

class RenameForm extends CompatForm
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

        if (Url::fromRequest()->getPath() === 'dashboard/rename-pane') {
            $pane = $this->dashboard->getPane(Url::fromRequest()->getParam('pane'));
            $this->populate([
                'name'  => $pane->getName(),
                'title' => $pane->getTitle()
            ]);
        } else {
            $home = Url::fromRequest()->getParam('home');
            $this->populate(['name' => $home]);
        }
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
                $dashboardHomes[$name] = $homeItem->getLabel();
            }
        }

        $submitLabel = t('Update Home');
        if (Url::fromRequest()->getPath() === 'dashboard/rename-pane') {
            $submitLabel = t('Update Pane');

            $this->addElement(
                'select',
                'home',
                [
                    'class'         => 'autosubmit',
                    'required'      => true,
                    'label'         => t('Move to Dashboard home'),
                    'multiOptions'  => $dashboardHomes,
                    'description'   => t('Select a dashboard home you want to move the dashboard to.'),
                ]
            );
        }

        $this->addElement(
            'text',
            'name',
            [
                'required'  => true,
                'label'     => t('Name')
            ]
        );

        if (Url::fromRequest()->getPath() === 'dashboard/rename-pane') {
            $this->addElement(
                'text',
                'title',
                [
                    'required'  => true,
                    'label'     => t('Title')
                ]
            );
        }

        $this->addElement('submit', 'submit', ['label' => $submitLabel]);
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
        $newTitle = $this->getValue('title');

        $pane = $this->dashboard->getPane($paneName);
        $pane->setName($newName);
        $pane->setTitle($newTitle);

        $this->dashboard->getConn()->update('dashboard', [
            'home_id'   => $parent,
            'name'      => $pane->getName(),
        ], ['dashboard.id = ?' => $pane->getPaneId()]);

        Notification::success(
            sprintf(t('Pane "%s" successfully renamed to "%s"'), $paneName, $newName)
        );
    }

    public function renameHome()
    {
        $homes = $this->dashboard->getHomes();
        $home = $homes[Url::fromRequest()->getParam('home')];

        $this->dashboard->getConn()->update('dashboard_home', [
            'name'  => $this->getValue('name')
        ], ['id = ?'    => $home->getAttribute('homeId')]);

        Notification::success(
            sprintf(t('Home "%s" successfully renamed to "%s"'), $home->getName(), $this->getValue('name'))
        );
    }

    public function onSuccess()
    {
        if (Url::fromRequest()->getPath() === 'dashboard/rename-pane') {
            $this->renamePane();
        } else {
            $this->renameHome();
        }
    }
}
