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
 * @author     Ingolf Steinhardt <info@e-spin.de>
 * @author     Richard Henkenjohann <richardhenkenjohann@googlemail.com>
 * @copyright  2015-2017 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\ActionHandler;

use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\ActionHandler\AbstractRequestScopeDeterminatorHandler;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\EditMask;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\BasicDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\EnvironmentInterface;
use ContaoCommunityAlliance\DcGeneral\Event\ActionEvent;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralInvalidArgumentException;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;

/**
 * This class handles the create actions in the frontend.
 */
class CreateHandler extends AbstractRequestScopeDeterminatorHandler
{

    /**
     * Handle the event to process the action.
     *
     * @param ActionEvent $event The action event.
     *
     * @return void
     *
     * @throws DcGeneralInvalidArgumentException If an unknown property is encountered in the palette.
     * @throws DcGeneralRuntimeException         If the data container is not editable, closed.
     */
    public function handleEvent(ActionEvent $event)
    {
        if (!$this->scopeDeterminator->currentScopeIsFrontend()) {
            return;
        }

        $environment = $event->getEnvironment();
        $action      = $event->getAction();

        // Only handle if we do not have a manual sorting or we know where to insert.
        // Manual sorting is handled by clipboard.
        if ('create' !== $action->getName()) {
            return;
        }

        // Only run when no response given yet.
        if (null !== $event->getResponse()) {
            return;
        }

        $response = $this->process($environment);
        if ($response !== false) {
            $event->setResponse($response);
        }
    }

    /**
     * Handle the action.
     *
     * @param EnvironmentInterface $environment The environment.
     *
     * @return string|bool
     *
     * @throws DcGeneralRuntimeException         If the data container is not editable, closed.
     */
    public function process(EnvironmentInterface $environment)
    {
        $definition      = $environment->getDataDefinition();
        $basicDefinition = $definition->getBasicDefinition();

        if (!$basicDefinition->isCreatable()) {
            throw new DcGeneralRuntimeException('DataContainer ' . $definition->getName() . ' is not creatable');
        }
        // We only support flat tables, sorry.
        if (BasicDefinitionInterface::MODE_FLAT !== $basicDefinition->getMode()) {
            return false;
        }

        $dataProvider       = $environment->getDataProvider();
        $propertyDefinition = $definition->getPropertiesDefinition();
        $properties         = $propertyDefinition->getProperties();
        $model              = $dataProvider->getEmptyModel();
        $clone              = $dataProvider->getEmptyModel();

        // If some of the fields have a default value, set it.
        foreach ($properties as $property) {
            $propName = $property->getName();

            if ($property->getDefaultValue() !== null) {
                $model->setProperty($propName, $property->getDefaultValue());
                $clone->setProperty($propName, $property->getDefaultValue());
            }
        }

        $editMask = new EditMask($environment, $model, $clone, null, null);

        return $editMask->execute();
    }
}
