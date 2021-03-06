<?php

/**
 * This file is part of contao-community-alliance/dc-general-contao-frontend.
 *
 * (c) 2015-2019 Contao Community Alliance.
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
 * @copyright  2015-2019 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\ActionHandler;

use ContaoCommunityAlliance\DcGeneral\Contao\RequestScopeDeterminator;
use ContaoCommunityAlliance\DcGeneral\Contao\RequestScopeDeterminatorAwareTrait;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\EditMask;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\BasicDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\EnvironmentInterface;
use ContaoCommunityAlliance\DcGeneral\Event\ActionEvent;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralInvalidArgumentException;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;
use ContaoCommunityAlliance\DcGeneral\Exception\NotCreatableException;

/**
 * This class handles the create actions in the frontend.
 */
class CreateHandler
{
    use RequestScopeDeterminatorAwareTrait;

    /**
     * CreateHandler constructor.
     *
     * @param RequestScopeDeterminator $scopeDeterminator The request mode determinator.
     */
    public function __construct(RequestScopeDeterminator $scopeDeterminator)
    {
        $this->setScopeDeterminator($scopeDeterminator);
    }

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
    public function handleEvent(ActionEvent $event): void
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
     * @throws NotCreatableException If the data container is not editable, closed.
     */
    public function process(EnvironmentInterface $environment)
    {
        $definition      = $environment->getDataDefinition();
        $basicDefinition = $definition->getBasicDefinition();

        if (!$basicDefinition->isCreatable()) {
            throw new NotCreatableException('DataContainer ' . $definition->getName() . ' is not creatable');
        }
        // We only support flat tables, sorry.
        if (BasicDefinitionInterface::MODE_HIERARCHICAL === $basicDefinition->getMode()) {
            return false;
        }

        $dataProvider = $environment->getDataProvider();
        $properties   = $definition->getPropertiesDefinition()->getProperties();
        $model        = $dataProvider->getEmptyModel();
        $clone        = $dataProvider->getEmptyModel();

        // If some of the fields have a default value, set it.
        foreach ($properties as $property) {
            $propName = $property->getName();

            if ($property->getDefaultValue() !== null) {
                $model->setProperty($propName, $property->getDefaultValue());
                $clone->setProperty($propName, $property->getDefaultValue());
            }
        }

        return (new EditMask($environment, $model, $clone, null, null))->execute();
    }
}
