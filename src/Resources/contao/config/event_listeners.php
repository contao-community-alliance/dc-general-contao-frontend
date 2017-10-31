<?php

/**
 * This file is part of contao-community-alliance/dc-general-contao-frontend.
 *
 * (c) 2015 Contao Community Alliance.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    contao-community-alliance/dc-general-contao-frontend
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @copyright  2015 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Listener\HandleSubmitListener;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Event\DcGeneralFrontendEvents;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\ActionHandler\CreateHandler;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\ActionHandler\EditHandler;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\DefaultWidgetBuilder;
use ContaoCommunityAlliance\DcGeneral\DcGeneralEvents;
use ContaoCommunityAlliance\DcGeneral\Event\ActionEvent;

// We only work in frontend.
if (TL_MODE !== 'FE') {
    return [];
}

return [
    DcGeneralEvents::ACTION => [
        function (ActionEvent $event) {

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
    ],
    DcGeneralFrontendEvents::BUILD_WIDGET => [
        [new DefaultWidgetBuilder(), 'handleEvent'],
    ],
    DcGeneralFrontendEvents::HANDLE_SUBMIT => [
        [new HandleSubmitListener($GLOBALS['container']['environment']), 'handleEvent']
    ],
];
