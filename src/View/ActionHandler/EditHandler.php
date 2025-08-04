<?php

/**
 * This file is part of contao-community-alliance/dc-general-contao-frontend.
 *
 * (c) 2015-2025 Contao Community Alliance.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    contao-community-alliance/dc-general-contao-frontend
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Richard Henkenjohann <richardhenkenjohann@googlemail.com>
 * @author     Sven Baumann <baumann.sv@gmail.com>
 * @author     Ingolf Steinhardt <info@e-spin.de>
 * @copyright  2015-2025 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\ActionHandler;

use Contao\CoreBundle\Exception\PageNotFoundException;
use ContaoCommunityAlliance\DcGeneral\Contao\RequestScopeDeterminator;
use ContaoCommunityAlliance\DcGeneral\Contao\RequestScopeDeterminatorAwareTrait;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\EditMask;
use ContaoCommunityAlliance\DcGeneral\Data\DataProviderInterface;
use ContaoCommunityAlliance\DcGeneral\Data\LanguageInformationInterface;
use ContaoCommunityAlliance\DcGeneral\Data\ModelId;
use ContaoCommunityAlliance\DcGeneral\Data\MultiLanguageDataProviderInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\ContainerInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\BasicDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\EnvironmentInterface;
use ContaoCommunityAlliance\DcGeneral\Event\ActionEvent;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralInvalidArgumentException;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;
use ContaoCommunityAlliance\DcGeneral\Exception\NotEditableException;
use ContaoCommunityAlliance\DcGeneral\InputProviderInterface;

/**
 * This class handles the actions of edit in the frontend.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
        if (
            null === ($scopeDeterminator = $this->scopeDeterminator)
            || !$scopeDeterminator->currentScopeIsFrontend()
        ) {
            return;
        }

        // Only handle if we do not have a manual sorting or we know where to insert.
        // Manual sorting is handled by clipboard.
        if ('edit' !== $event->getAction()->getName()) {
            return;
        }

        // Only run when no response given yet.
        if (null !== $event->getResponse()) {
            return;
        }

        if ('' !== ($response = $this->process($event->getEnvironment()))) {
            $event->setResponse($response);
        }
    }

    /**
     * Handle the action.
     *
     * @param EnvironmentInterface $environment The environment.
     *
     * @return string
     *
     * @throws PageNotFoundException If item to edit was not found.
     * @throws NotEditableException  When the definition is not editable.
     */
    public function process(EnvironmentInterface $environment)
    {
        $definition = $environment->getDataDefinition();
        assert($definition instanceof ContainerInterface);

        $basicDefinition = $definition->getBasicDefinition();

        if (!$basicDefinition->isEditable()) {
            throw new NotEditableException('DataContainer ' . $definition->getName() . ' is not editable.');
        }

        // We only support flat tables, sorry.
        if (BasicDefinitionInterface::MODE_HIERARCHICAL === $basicDefinition->getMode()) {
            throw new NotEditableException('Mode "' . $basicDefinition->getMode() . '" is not editable.');
        }

        $inputProvider = $environment->getInputProvider();
        assert($inputProvider instanceof InputProviderInterface);

        $modelId = ModelId::fromSerialized($inputProvider->getParameter('id'));

        $dataProvider = $environment->getDataProvider();
        assert($dataProvider instanceof DataProviderInterface);

        $model = $dataProvider->fetch($dataProvider->getEmptyConfig()->setId($modelId->getId()));

        if (null === $model) {
            throw new PageNotFoundException('Model not found: ' . $modelId->getSerialized());
        }

        $clone = clone $model;

        return (new EditMask($environment, $model, $clone, null, null))->execute();
    }
}
