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
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Richard Henkenjohann <richardhenkenjohann@googlemail.com>
 * @copyright  2015-2017 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\ActionHandler;

use Contao\CoreBundle\Exception\PageNotFoundException;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\EditMask;
use ContaoCommunityAlliance\DcGeneral\Data\ModelId;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\BasicDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;
use ContaoCommunityAlliance\DcGeneral\View\ActionHandler\AbstractHandler;

/**
 * This class handles the edit actions in the frontend.
 */
class EditHandler extends AbstractHandler
{
    /**
     * Handle the action.
     *
     * @return void
     *
     * @throws DcGeneralRuntimeException When the definition is not creatable.
     */
    public function process()
    {
        $environment     = $this->getEnvironment();
        $event           = $this->getEvent();
        $definition      = $environment->getDataDefinition();
        $basicDefinition = $definition->getBasicDefinition();

        if (!$basicDefinition->isEditable()) {
            throw new DcGeneralRuntimeException('DataContainer ' . $definition->getName() . ' is not creatable');
        }
        // We only support flat tables, sorry.
        if (BasicDefinitionInterface::MODE_FLAT !== $basicDefinition->getMode()) {
            return;
        }
        $modelId = ModelId::fromSerialized($environment->getInputProvider()->getParameter('id'));

        $dataProvider = $environment->getDataProvider();
        $model        = $dataProvider->fetch($dataProvider->getEmptyConfig()->setId($modelId->getId()));
        $clone        = $dataProvider->getEmptyModel();

        if (null === $model) {
            throw new PageNotFoundException('MetaModel not found: ' . $modelId->getSerialized());
        }
        $editMask = new EditMask($environment, $model, $clone, null, null);

        $event->setResponse($editMask->execute());
    }
}
