<?php

namespace Icinga\Forms\Dashboard;

use Icinga\Web\Notification;
use Icinga\Web\Url;
use Icinga\Web\Widget\Dashboard;
use ipl\Html\Html;
use ipl\Web\Compat\CompatForm;

class RemovalForm extends CompatForm
{
    /** @var Dashboard  */
    private $dashboard;

    /** @var string $pane name of the pane to be deleted */
    private $pane;

    /**
     * RemovalForm constructor.
     *
     * @param Dashboard $dashboard
     *
     * @param $pane
     */
    public function __construct(Dashboard $dashboard, $pane = null)
    {
        $this->dashboard = $dashboard;
        $this->pane = $pane;
    }

    protected function assemble()
    {
        $formTitle = Html::sprintf(t('Please confirm removal of dashboard \'%s\''), $this->pane);
        if (Url::fromRequest()->getPath() === 'dashboard/remove-dashlet') {
            $dashlet = $this->dashboard->getPane($this->pane)->getDashlet(
                Url::fromRequest()->getParam('dashlet')
            )->getName();

            $formTitle = Html::sprintf(t('Please confirm removal of dashlet \'%s\''), $dashlet);
        } elseif (Url::fromRequest()->getPath() === 'dashboard/remove-home') {
            $formTitle = Html::sprintf(
                t('Please confirm removal of dashboard home \'%s\''),
                Url::fromRequest()->getParam('home')
            );
        }

        $this->add(Html::tag('h1', null, $formTitle));
        $this->addElement('submit', 'submit', [
            'label'             => 'Confirm Removal',
            'data-base-target'  => '_main'
        ]);
    }

    public function removeHome()
    {
        $homes = $this->dashboard->getHomes();
        $home = $homes[Url::fromRequest()->getParam('home')];

        $db = $this->dashboard->getConn();

        foreach ($this->dashboard->getPanes() as $pane) {
            if ($pane->getParentId() === $home->getAttribute('homeId')) {
                $db->delete('dashlet', ['dashboard_id = ?'    => $pane->getPaneId()]);
                $db->delete('dashboard', ['home_id = ?'       => $home->getAttribute('homeId')]);
            }
        }

        $db->delete('dashboard_home', ['id = ?' => $home->getAttribute('homeId')]);

        Notification::success(t('Dashboard home has been removed') . ': ' . $home->getName());
    }

    public function removePane()
    {
        $pane = $this->dashboard->getPane($this->pane);

        try {
            $db = $this->dashboard->getConn();
            $db->delete('dashlet', ['dashboard_id = ?' => $pane->getPaneId()]);
            $db->delete('dashboard', [
                'home_id = ?' => $pane->getParentId(),
                'id = ?'      => $pane->getPaneId(),
                'name = ?'    => $this->pane
            ]);

            Notification::success(t('Dashboard has been removed') . ': ' . $pane->getTitle());
        } catch (\PDOException $e) {
            $this->addMessage($e);
            return;
        }
    }

    public function removeDashlet()
    {
        $dashlet = Url::fromRequest()->getParam('dashlet');
        $pane = $this->dashboard->getPane($this->pane);
        try {
            $this->dashboard->getConn()->delete('dashlet', [
                'id = ?' => $pane->getDashlet($dashlet)->getDashletId()
            ]);
        } catch (\PDOException $err) {
            $this->addMessage($err);
            return;
        }

        Notification::success(t('Dashlet has been removed from') . ' ' . $pane->getTitle());
    }

    public function onSuccess()
    {
        if (Url::fromRequest()->getPath() === 'dashboard/remove-pane') {
            $this->removePane();
        } elseif (Url::fromRequest()->getPath() === 'dashboard/remove-home') {
            $this->removeHome();
        } else {
            $this->removeDashlet();
        }
    }
}
