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

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View;

use Contao\FrontendUser;
use Contao\System;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Event\DcGeneralFrontendEvents;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Event\HandleSubmitEvent;
use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\Event\GetEditMaskSubHeadlineEvent;
use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\Event\GetEditModeButtonsEvent;
use ContaoCommunityAlliance\DcGeneral\Controller\ControllerInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\ContainerInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\DataProviderInformationInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\PropertiesDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Palette\PaletteInterface;
use ContaoCommunityAlliance\DcGeneral\Data\DataProviderInterface;
use ContaoCommunityAlliance\DcGeneral\Data\ModelInterface;
use ContaoCommunityAlliance\DcGeneral\Data\PropertyValueBag;
use ContaoCommunityAlliance\DcGeneral\DcGeneralEvents;
use ContaoCommunityAlliance\DcGeneral\EnvironmentInterface;
use ContaoCommunityAlliance\DcGeneral\Event\EnforceModelRelationshipEvent;
use ContaoCommunityAlliance\DcGeneral\Event\PostPersistModelEvent;
use ContaoCommunityAlliance\DcGeneral\Event\PreEditModelEvent;
use ContaoCommunityAlliance\DcGeneral\Event\PrePersistModelEvent;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralInvalidArgumentException;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;
use ContaoCommunityAlliance\DcGeneral\InputProviderInterface;
use ContaoCommunityAlliance\Translator\TranslatorInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * This class manages the displaying of the edit/create mask containing the widgets.
 *
 * It also handles the persisting of the model.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 */
class EditMask
{
    /**
     * The environment.
     *
     * @var EnvironmentInterface
     */
    private EnvironmentInterface $environment;

    /**
     * The event dispatcher.
     *
     * @var EventDispatcherInterface
     */
    private EventDispatcherInterface $dispatcher;

    /**
     * Retrieve the translation manager to use.
     *
     * @var TranslatorInterface
     */
    private TranslatorInterface $translator;

    /**
     * The data definition from the environment.
     *
     * @var ContainerInterface
     */
    private ContainerInterface $definition;

    /**
     * The data provider of the model being edited.
     *
     * @var DataProviderInformationInterface
     */
    private DataProviderInformationInterface $modelProvider;

    /**
     * The model to be manipulated.
     *
     * @var ModelInterface
     */
    private ModelInterface $model;

    /**
     * The original model from the database.
     *
     * @var ModelInterface
     */
    private ModelInterface $originalModel;

    /**
     * The method to be executed before the model is persisted.
     *
     * @var callable|null
     */
    private $preFunction;

    /**
     * The method to be executed after the model is persisted.
     *
     * @var callable|null
     */
    private $postFunction;

    /**
     * The errors from the widgets.
     *
     * @var array
     */
    private array $errors = [];

    /**
     * Create the edit mask.
     *
     * @param EnvironmentInterface $environment   The view in use.
     * @param ModelInterface       $model         The model with the current data.
     * @param ModelInterface       $originalModel The data from the original data.
     * @param callable|null        $preFunction   The function to call before saving an item.
     * @param callable|null        $postFunction  The function to call after saving an item.
     */
    public function __construct($environment, $model, $originalModel, $preFunction, $postFunction)
    {
        $providerName      = $model->getProviderName();
        $this->environment = $environment;
        $translator       = $environment->getTranslator();
        assert($translator instanceof TranslatorInterface);
        $this->translator  = $translator;
        $dispatcher        = $environment->getEventDispatcher();
        assert($dispatcher instanceof EventDispatcherInterface);
        $this->dispatcher  = $dispatcher;
        $dataDefinition    = $environment->getDataDefinition();
        assert($dataDefinition instanceof ContainerInterface);
        $this->definition    = $dataDefinition;
        $this->modelProvider = $dataDefinition->getDataProviderDefinition()->getInformation($providerName);
        $this->model         = $model;
        $this->originalModel = $originalModel;
        $this->preFunction   = $preFunction;
        $this->postFunction  = $postFunction;
    }

    /**
     * Create the edit mask.
     *
     * @return string
     *
     * @throws DcGeneralRuntimeException         If the data container is not editable, closed.
     * @throws DcGeneralInvalidArgumentException If an unknown property is encountered in the palette.
     */
    public function execute()
    {
        $inputProvider = $this->environment->getInputProvider();
        assert($inputProvider instanceof InputProviderInterface);

        $palettesDefinition = $this->definition->getPalettesDefinition();
        $isSubmitted        = ($inputProvider->getValue('FORM_SUBMIT') === $this->definition->getName());
        $isAutoSubmit       = ($inputProvider->getValue('SUBMIT_TYPE') === 'auto');
        $widgetManager      = new WidgetManager($this->environment, $this->model);

        $this->dispatcher->dispatch(new PreEditModelEvent($this->environment, $this->model), PreEditModelEvent::NAME);

        $this->enforceModelRelationship();

        // Pass 1: Get the palette for the values stored in the model.
        $palette = $palettesDefinition->findPalette($this->model);

        $propertyValues = $this->processInput($widgetManager);
        if ($isSubmitted && null !== $propertyValues) {
            // Pass 2: Determine the real palette we want to work on if we have some data submitted.
            $palette = $palettesDefinition->findPalette($this->model, $propertyValues);

            // Update the model - the model might add some more errors to the propertyValueBag via exceptions.
            $controller = $this->environment->getController();
            assert($controller instanceof ControllerInterface);
            $controller->updateModelFromPropertyBag($this->model, $propertyValues);
        }

        $fieldSets = $this->buildFieldSet($widgetManager, $palette, $propertyValues);

        $buttons = $this->getEditButtons();

        if ((!$isAutoSubmit) && $isSubmitted && empty($this->errors)) {
            $this->doPersist();
            $this->handleSubmit($buttons);
        }

        /** @psalm-suppress DeprecatedClass */
        $template = new ViewTemplate('dcfe_general_edit');
        $template->setData(
            [
                'fieldsets'   => $fieldSets,
                'subHeadline' => $this->getSubHeadline(),
                'table'       => $this->definition->getName(),
                'enctype'     => 'multipart/form-data',
                'error'       => $this->errors,
                'editButtons' => $buttons,
                'model'       => $this->model
            ]
        );

        return $template->parse();
    }

    /**
     * This method triggers the event to update the parent child relationships of the current model.
     *
     * @return void
     */
    private function enforceModelRelationship(): void
    {
        $event = new EnforceModelRelationshipEvent($this->environment, $this->model);

        $this->dispatcher->dispatch($event, DcGeneralEvents::ENFORCE_MODEL_RELATIONSHIP);
    }

    /**
     * Process input and return all modified properties or null if there is no input.
     *
     * @param WidgetManager $widgetManager The widget manager in use.
     *
     * @return null|PropertyValueBag
     */
    private function processInput($widgetManager): ?PropertyValueBag
    {
        $input = $this->environment->getInputProvider();
        assert($input instanceof InputProviderInterface);

        if ($input->getValue('FORM_SUBMIT') === $this->definition->getName()) {
            $propertyValues = new PropertyValueBag();
            $propertyNames  = \array_intersect(
                $this->definition->getPropertiesDefinition()->getPropertyNames(),
                (array) $input->getValue('FORM_INPUTS')
            );

            // Process input and update changed properties.
            foreach ($propertyNames as $propertyName) {
                $propertyValue = $input->hasValue($propertyName) ? $input->getValue($propertyName, true) : null;
                $propertyValues->setPropertyValue($propertyName, $propertyValue);
            }

            $widgetManager->processInput($propertyValues);

            return $propertyValues;
        }

        return null;
    }

    /**
     * Trigger the pre persist event and handle the prePersist function if available.
     *
     * @return void
     */
    private function handlePrePersist(): void
    {
        if (null !== $this->preFunction) {
            \call_user_func($this->preFunction, $this->environment, $this->model, $this->originalModel);
        }

        $this->dispatcher->dispatch(
            new PrePersistModelEvent($this->environment, $this->model, $this->originalModel),
            PrePersistModelEvent::NAME
        );
    }

    /**
     * Trigger the post persist event and handle the postPersist function if available.
     *
     * @return void
     */
    private function handlePostPersist(): void
    {
        if (null !== $this->postFunction) {
            \call_user_func($this->postFunction, $this->environment, $this->model, $this->originalModel);
        }

        $event = new PostPersistModelEvent($this->environment, $this->model, $this->originalModel);
        $this->dispatcher->dispatch($event, $event::NAME);
    }

    /**
     * Get a translated label from the translator.
     *
     * The fallback is as follows:
     * 1. Try to translate via the data definition name as translation section.
     * 2. Try to translate with the prefix 'MSC.'.
     * 3. Return the input value as nothing worked out.
     *
     * @param string $transString The non translated label for the button.
     * @param array  $parameters  The parameters to pass to the translator.
     *
     * @return string
     */
    private function translateLabel(string $transString, array $parameters = []): string
    {
        $translator = $this->translator;
        if (
            $transString !== ($label =
                $translator->translate($transString, $this->definition->getName(), $parameters))
        ) {
            return $label;
        }

        if (
            $transString !== ($label =
                $translator->translate('MSC.' . $transString, $this->definition->getName(), $parameters))
        ) {
            return $label;
        }

        // Fallback, just return the key as is it.
        return $transString;
    }

    /**
     * Retrieve a list of html buttons to use in the bottom panel (submit area).
     *
     * @return string[]
     */
    private function getEditButtons(): array
    {
        $button  = '<button type="submit" name="%s" id="%s" class="submit %s" accesskey="%s">%s</button>';
        $buttons = [];

        $buttons['save'] = \sprintf(
            $button,
            'save',
            'save',
            'save',
            's',
            $this->translateLabel('save')
        );

        if ($this->definition->getBasicDefinition()->isCreatable()) {
            $buttons['saveNcreate'] = \sprintf(
                $button,
                'saveNcreate',
                'saveNcreate',
                'saveNcreate',
                'n',
                $this->translateLabel('saveNcreate')
            );
        }

        $event = new GetEditModeButtonsEvent($this->environment);
        $event->setButtons($buttons);

        $this->dispatcher->dispatch($event, $event::NAME);

        return $event->getButtons();
    }

    /**
     * Build the field sets.
     *
     * @param WidgetManager         $widgetManager  The widget manager in use.
     * @param PaletteInterface      $palette        The palette to use.
     * @param PropertyValueBag|null $propertyValues The property values.
     *
     * @return array
     *
     * @throws DcGeneralRuntimeException         For unknown properties.
     * @throws DcGeneralInvalidArgumentException When the property is not registered in the definition.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function buildFieldSet(
        WidgetManager $widgetManager,
        PaletteInterface $palette,
        ?PropertyValueBag $propertyValues
    ): array {
        $propertyDefinitions = $this->definition->getPropertiesDefinition();

        $inputProvider = $this->environment->getInputProvider();
        assert($inputProvider instanceof InputProviderInterface);

        $isAutoSubmit = ($inputProvider->getValue('SUBMIT_TYPE') === 'auto');

        $fieldSets = [];
        $errors    = [];
        $first     = true;
        foreach ($palette->getLegends() as $legend) {
            $legendName = $this->translator->translate(
                $legend->getName() . '_legend',
                $this->definition->getName()
            );
            $fields     = [];
            $hidden     = [];
            $properties = $legend->getProperties($this->model, $propertyValues);

            if (!$properties) {
                continue;
            }

            foreach ($properties as $property) {
                $propertyName = $property->getName();
                $this->ensurePropertyExists($propertyName, $propertyDefinitions);

                // If this property is invalid, fetch the error.
                if (
                    (!$isAutoSubmit)
                    && $propertyValues
                    && $propertyValues->hasPropertyValue($propertyName)
                    && $propertyValues->isPropertyValueInvalid($propertyName)
                ) {
                    $errors[] = $propertyValues->getPropertyValueErrors($propertyName);
                }

                $fields[] = $widgetManager->renderWidget($propertyName, $isAutoSubmit, $propertyValues);
                $hidden[] = \sprintf('<input type="hidden" name="FORM_INPUTS[]" value="%s">', $propertyName);
            }

            $fieldSet            = [];
            $fieldSet['label']   = $legendName;
            $fieldSet['class']   = $first ? 'tl_tbox' : 'tl_box';
            $fieldSet['palette'] = \implode('', $hidden) . \implode('', $fields);
            $fieldSet['legend']  = $legend->getName();
            $fieldSets[]         = $fieldSet;

            $first = false;
        }

        if ([] !== $errors) {
            $this->errors = array_merge(...$errors);
        }

        return $fieldSets;
    }

    /**
     * Ensure a property is defined in the data definition and raise an exception if it is unknown.
     *
     * @param string                        $property            The property name to check.
     * @param PropertiesDefinitionInterface $propertyDefinitions The property definitions.
     *
     * @return void
     *
     * @throws DcGeneralInvalidArgumentException When the property is not registered in the definition.
     */
    private function ensurePropertyExists(string $property, PropertiesDefinitionInterface $propertyDefinitions): void
    {
        if (!$propertyDefinitions->hasProperty($property)) {
            throw new DcGeneralInvalidArgumentException(
                \sprintf(
                    'Property %s is mentioned in palette but not defined in propertyDefinition.',
                    $property
                )
            );
        }
    }

    /**
     * Update the versioning information in the data provider for a given model (if necessary).
     *
     * @param ModelInterface $model The model to update.
     *
     * @return void
     */
    private function storeVersion(ModelInterface $model): void
    {
        if (!$this->modelProvider->isVersioningEnabled()) {
            return;
        }

        $environment  = $this->environment;
        $modelId      = $model->getId();
        $dataProvider = $environment->getDataProvider($this->model->getProviderName());
        assert($dataProvider instanceof DataProviderInterface);

        $currentVersion = $dataProvider->getActiveVersion($modelId);
        $version        = $dataProvider->getVersion($modelId, $currentVersion);
        assert($version instanceof ModelInterface);
        // Compare version and current record.
        if (
            !$currentVersion
            || !$dataProvider->sameModels($model, $version)
        ) {
            $user     = FrontendUser::getInstance();
            $username = '(frontend anonymous)';

            if (System::getContainer()->get('contao.security.token_checker')->hasFrontendUser()) {
                $username = $user->username ?? '';
            }

            $dataProvider->saveVersion($model, $username);
        }
    }

    /**
     * Handle the submit and determine which button has been triggered.
     *
     * This method will redirect the client.
     *
     * @param array $buttons The registered edit buttons.
     *
     * @return void
     */
    private function handleSubmit(array $buttons): void
    {
        $inputProvider = $this->environment->getInputProvider();
        assert($inputProvider instanceof InputProviderInterface);

        foreach (\array_keys($buttons) as $button) {
            if ($inputProvider->hasValue($button)) {
                $event = new HandleSubmitEvent($this->environment, $this->model, $button);

                $this->dispatcher->dispatch($event, DcGeneralFrontendEvents::HANDLE_SUBMIT);

                break;
            }
        }
    }

    /**
     * Determine the headline to use.
     *
     * @return string|null
     *
     * @deprecated This is deprecated since 2.3 and will be removed in 3.0.
     */
    private function getHeadline(): ?string
    {
        // @codingStandardsIgnoreStart
        @\trigger_error(__CLASS__ . '::' . __METHOD__ . ' is deprecated - use getSubHeadline()!', E_USER_DEPRECATED);
        // @codingStandardsIgnoreEnd

        return $this->getSubHeadline();
    }

    /**
     * Determine the headline to use.
     *
     * @return string|null
     */
    private function getSubHeadline(): ?string
    {
        $event = new GetEditMaskSubHeadlineEvent($this->environment, $this->model);

        $this->dispatcher->dispatch($event, $event::NAME);

        return $event->getHeadline();
    }

    /**
     * Handle the persisting of the currently loaded model.
     *
     * @return void
     */
    private function doPersist()
    {
        if (!$this->model->getMeta(ModelInterface::IS_CHANGED)) {
            return;
        }

        $this->handlePrePersist();

        // TO DO: manual sorting property handling is not enabled here as it originates from the backend definition.
        // Save the model.
        $dataProvider = $this->environment->getDataProvider($this->model->getProviderName());
        assert($dataProvider instanceof DataProviderInterface);

        $dataProvider->save($this->model);

        $this->handlePostPersist();

        $this->storeVersion($this->model);
    }
}
