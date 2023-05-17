<?php

/**
 * This file is part of contao-community-alliance/dc-general-contao-frontend.
 *
 * (c) 2015-2023 Contao Community Alliance.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    contao-community-alliance/dc-general-contao-frontend
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Ingolf Steinhardt <info@e-spin.de>
 * @copyright  2015-2023 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Event;

/**
 * This class holds the event name constants of all events.
 */
class DcGeneralFrontendEvents
{
    /**
     * This event is being emitted when the widget manager wants to create a new widget instance.
     *
     * @see ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Event\BuildWidgetEvent
     */
    public const BUILD_WIDGET = 'dc-general.contao-frontend.build-widget';

    /**
     * This event is being emitted when the edit mask has encountered a submit and post action shall be performed.
     *
     * @see ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Event\HandleSubmitEvent
     */
    public const HANDLE_SUBMIT = 'dc-general.contao-frontend.handle-submit';
}
