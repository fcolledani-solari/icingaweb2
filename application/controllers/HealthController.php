<?php
/* Icinga Web 2 | (c) 2021 Icinga GmbH | GPLv2+ */

namespace Icinga\Controllers;

use Exception;
use Icinga\Application\Hook;
use Icinga\Application\Logger;
use Icinga\Exception\IcingaException;
use Icinga\Web\View\AppHealth;
use ipl\Web\Compat\CompatController;

class HealthController extends CompatController
{
    public function indexAction()
    {
        $this->setTitle(t('Health'));
        $this->addContent(new AppHealth());
    }

    public function checksAction()
    {
        if (! $this->getRequest()->isApiRequest()) {
            $this->httpBadRequest('This endpoint can only respond with JSON content');
        }

        $checks = [];
        foreach (Hook::all('health') as $hook) {
            /** @var Hook\HealthHook $hook */

            try {
                $hook->collectHealthMetrics();
                $state = $hook->getState();
                $message = $hook->getMessage();
                $metrics = $hook->getMetrics();
            } catch (Exception $e) {
                Logger::error('Failed to collect health metrics: %s', $e);

                $state = Hook\HealthHook::STATE_UNKNOWN;
                $message = IcingaException::describe($e);
                $metrics = null;
            }

            $checks[$hook->getModuleName()][$hook->getName()] = [
                'state'     => $state,
                'message'   => $message,
                'metrics'   => $metrics
            ];
        }

        $this->getResponse()
            ->json()
            ->setSuccessData($checks)
            ->sendResponse();
    }
}
