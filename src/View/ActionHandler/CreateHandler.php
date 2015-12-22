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

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\ActionHandler;

use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\EditMask;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\BasicDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;
use ContaoCommunityAlliance\DcGeneral\View\ActionHandler\AbstractHandler;

/**
 * This class handles the create actions in the frontend.
 */
class CreateHandler extends AbstractHandler
{
    /**
     * Handle the action.
     *
     * @return mixed
     *
     * @throws DcGeneralRuntimeException When the definition is not creatable.
     */
    public function process()
    {
        $environment     = $this->getEnvironment();
        $event           = $this->getEvent();
        $definition      = $environment->getDataDefinition();
        $basicDefinition = $definition->getBasicDefinition();

        if (!$basicDefinition->isCreatable()) {
            throw new DcGeneralRuntimeException('DataContainer ' . $definition->getName() . ' is not creatable');
        }
        // We only support flat tables, sorry.
        if (BasicDefinitionInterface::MODE_FLAT !== $basicDefinition->getMode()) {
            return;
        }

        $dataProvider = $environment->getDataProvider();
        $model        = $dataProvider->getEmptyModel();
        $clone        = $dataProvider->getEmptyModel();
        $editMask     = new EditMask($environment, $model, $clone, null, null);

        $event->setResponse($editMask->execute());
    }
}
