<?php

/**
 * This file is part of contao-community-alliance/dc-general-contao-frontend.
 *
 * (c) 2015-2017 Contao Community Alliance.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    contao-community-alliance/dc-general-contao-frontend
 * @author     Richard Henkenjohann <richardhenkenjohann@googlemail.com>
 * @copyright  2015-2017 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Listener;

use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\ActionHandler\CreateHandler;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\ActionHandler\EditHandler;
use ContaoCommunityAlliance\DcGeneral\Event\ActionEvent;

/**
 * This class selects the right handler for the action.
 */
class ActionListener
{

    /**
     * Handle the event.
     *
     * @param ActionEvent $event The event.
     *
     * @return void
     */
    public function handleEvent(ActionEvent $event)
    {
        if (null !== $event->getResponse()) {
            return;
        }

        switch ($event->getAction()->getName()) {
            case 'create':
                $handler = new CreateHandler();
                break;
            case 'edit':
                $handler = new EditHandler();
                break;
            default:
                return;
        }

        $handler->handleEvent($event);
    }
}
