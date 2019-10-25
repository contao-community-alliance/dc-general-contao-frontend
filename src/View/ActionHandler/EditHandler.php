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
 * @author     Richard Henkenjohann <richardhenkenjohann@googlemail.com>
 * @copyright  2015-2019 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\ActionHandler;

use Contao\CoreBundle\Exception\PageNotFoundException;
use ContaoCommunityAlliance\DcGeneral\Contao\RequestScopeDeterminator;
use ContaoCommunityAlliance\DcGeneral\Contao\RequestScopeDeterminatorAwareTrait;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\EditMask;
use ContaoCommunityAlliance\DcGeneral\Data\ModelId;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\BasicDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\EnvironmentInterface;
use ContaoCommunityAlliance\DcGeneral\Event\ActionEvent;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralInvalidArgumentException;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;
use ContaoCommunityAlliance\DcGeneral\Exception\NotEditableException;

/**
 * This class handles the edit actions in the frontend.
 */
class EditHandler
{
    use RequestScopeDeterminatorAwareTrait;

    /**
     * EditHandler constructor.
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
     * @throws PageNotFoundException             If item to edit was not found.
     * @throws DcGeneralInvalidArgumentException If the data container is not editable, closed.
     * @throws DcGeneralRuntimeException         When the definition is not editable.
     */
    public function handleEvent(ActionEvent $event): void
    {
        if (!$this->scopeDeterminator->currentScopeIsFrontend()) {
            return;
        }

        $action = $event->getAction();
        // Only handle if we do not have a manual sorting or we know where to insert.
        // Manual sorting is handled by clipboard.
        if ('edit' !== $action->getName()) {
            return;
        }

        // Only run when no response given yet.
        if (null !== $event->getResponse()) {
            return;
        }

        $response = $this->process($event->getEnvironment());
        if (false !== $response) {
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
     * @throws PageNotFoundException If item to edit was not found.
     * @throws NotEditableException  When the definition is not editable.
     */
    public function process(EnvironmentInterface $environment)
    {
        $definition      = $environment->getDataDefinition();
        $basicDefinition = $definition->getBasicDefinition();

        if (!$basicDefinition->isEditable()) {
            throw new NotEditableException('DataContainer ' . $definition->getName() . ' is not editable');
        }
        // We only support flat tables, sorry.
        if (BasicDefinitionInterface::MODE_FLAT !== $basicDefinition->getMode()) {
            return false;
        }
        $modelId = ModelId::fromSerialized($environment->getInputProvider()->getParameter('id'));

        $dataProvider = $environment->getDataProvider();
        $model        = $dataProvider->fetch($dataProvider->getEmptyConfig()->setId($modelId->getId()));
        $clone        = clone $model;

        if (null === $model) {
            throw new PageNotFoundException('Model not found: ' . $modelId->getSerialized());
        }

        return (new EditMask($environment, $model, $clone, null, null))->execute();
    }
}
