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

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View;

use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\Event\GetEditModeButtonsEvent;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Event\DcGeneralFrontendEvents;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Event\HandleSubmitEvent;
use ContaoCommunityAlliance\DcGeneral\Data\DataProviderInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\ContainerInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\PropertiesDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Palette\PaletteInterface;
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
use ContaoCommunityAlliance\Translator\TranslatorInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * This class manages the displaying of the edit/create mask containing the widgets.
 *
 * It also handles the persisting of the model.
 */
class EditMask
{
    /**
     * The environment.
     *
     * @var EnvironmentInterface
     */
    private $environment;

    /**
     * The event dispatcher.
     *
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * Retrieve the translation manager to use.
     *
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * The data definition from the environment.
     *
     * @var ContainerInterface
     */
    private $definition;

    /**
     * The data provider of the model being edited.
     *
     * @var DataProviderInterface
     */
    private $modelProvider;

    /**
     * The model to be manipulated.
     *
     * @var ModelInterface
     */
    private $model;

    /**
     * The original model from the database.
     *
     * @var ModelInterface
     */
    private $originalModel;

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
    private $errors = [];

    /**
     * Create the edit mask.
     *
     * @param EnvironmentInterface $environment   The view in use.
     *
     * @param ModelInterface       $model         The model with the current data.
     *
     * @param ModelInterface       $originalModel The data from the original data.
     *
     * @param callable             $preFunction   The function to call before saving an item.
     *
     * @param callable             $postFunction  The function to call after saving an item.
     */
    public function __construct($environment, $model, $originalModel, $preFunction, $postFunction)
    {
        $providerName        = $model->getProviderName();
        $this->environment   = $environment;
        $this->translator    = $environment->getTranslator();
        $this->dispatcher    = $environment->getEventDispatcher();
        $this->definition    = $environment->getDataDefinition();
        $this->modelProvider = $this->definition->getDataProviderDefinition()->getInformation($providerName);
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
     *
     * @throws DcGeneralInvalidArgumentException If an unknown property is encountered in the palette.
     */
    public function execute()
    {
        $inputProvider      = $this->environment->getInputProvider();
        $palettesDefinition = $this->definition->getPalettesDefinition();
        $isSubmitted        = ($inputProvider->getValue('FORM_SUBMIT') === $this->definition->getName());
        $isAutoSubmit       = ($inputProvider->getValue('SUBMIT_TYPE') === 'auto');
        $widgetManager      = new WidgetManager($this->environment, $this->model);

        $this->dispatcher->dispatch(PreEditModelEvent::NAME, new PreEditModelEvent($this->environment, $this->model));

        $this->enforceModelRelationship();

        // Pass 1: Get the palette for the values stored in the model.
        $palette = $palettesDefinition->findPalette($this->model);

        $propertyValues = $this->processInput($widgetManager);
        if ($isSubmitted && $propertyValues) {
            // Pass 2: Determine the real palette we want to work on if we have some data submitted.
            $palette = $palettesDefinition->findPalette($this->model, $propertyValues);

            // Update the model - the model might add some more errors to the propertyValueBag via exceptions.
            $this->environment->getController()->updateModelFromPropertyBag($this->model, $propertyValues);
        }

        $fieldSets = $this->buildFieldSet($widgetManager, $palette, $propertyValues);

        $buttons = $this->getEditButtons();

        if ((!$isAutoSubmit) && $isSubmitted && empty($this->errors)) {
            $this->doPersist();
            $this->handleSubmit($buttons);
        }

        $template = new ViewTemplate('dcfe_general_edit');
        $template->setData(
            array(
                'fieldsets'   => $fieldSets,
                'subHeadline' => $this->getHeadline(),
                'table'       => $this->definition->getName(),
                'enctype'     => 'multipart/form-data',
                'error'       => $this->errors,
                'editButtons' => $buttons
            )
        );

        return $template->parse();
    }

    /**
     * This method triggers the event to update the parent child relationships of the current model.
     *
     * @return void
     */
    private function enforceModelRelationship()
    {
        $event = new EnforceModelRelationshipEvent($this->environment, $this->model);

        $this->dispatcher->dispatch(DcGeneralEvents::ENFORCE_MODEL_RELATIONSHIP, $event);
    }

    /**
     * Process input and return all modified properties or null if there is no input.
     *
     * @param WidgetManager $widgetManager The widget manager in use.
     *
     * @return null|PropertyValueBag
     */
    private function processInput($widgetManager)
    {
        $input = $this->environment->getInputProvider();

        if ($input->getValue('FORM_SUBMIT') == $this->definition->getName()) {
            $propertyValues = new PropertyValueBag();
            $propertyNames  = array_intersect(
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
    private function handlePrePersist()
    {
        if (null !== $this->preFunction) {
            call_user_func_array($this->preFunction, [$this->environment, $this->model, $this->originalModel]);
        }

        $this->dispatcher->dispatch(
            PrePersistModelEvent::NAME,
            new PrePersistModelEvent($this->environment, $this->model, $this->originalModel)
        );
    }

    /**
     * Trigger the post persist event and handle the postPersist function if available.
     *
     * @return void
     */
    private function handlePostPersist()
    {
        if (null !== $this->postFunction) {
            call_user_func_array($this->postFunction, [$this->environment, $this->model, $this->originalModel]);
        }

        $event = new PostPersistModelEvent($this->environment, $this->model, $this->originalModel);
        $this->dispatcher->dispatch($event::NAME, $event);
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
     *
     * @param array  $parameters  The parameters to pass to the translator.
     *
     * @return string
     */
    private function translateLabel($transString, $parameters = [])
    {
        $translator = $this->translator;
        if ($transString !==
            ($label = $translator->translate($transString, $this->definition->getName(), $parameters))
        ) {
            return $label;
        } elseif ($transString !== ($label = $translator->translate('MSC.' . $transString, $parameters))) {
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
    private function getEditButtons()
    {
        $buttons = [];

        $buttons['save'] = sprintf(
            '<input type="submit" name="save" id="save" class="tl_submit" accesskey="s" value="%s" />',
            $this->translateLabel('save')
        );

        if ($this->definition->getBasicDefinition()->isCreatable()) {
            $buttons['saveNcreate'] = sprintf(
                '<input type="submit" name="saveNcreate" id="saveNcreate" class="tl_submit" accesskey="n" ' .
                ' value="%s" />',
                $this->translateLabel('saveNcreate')
            );
        }

        $event = new GetEditModeButtonsEvent($this->environment);
        $event->setButtons($buttons);

        $this->dispatcher->dispatch($event::NAME, $event);

        return $event->getButtons();
    }

    /**
     * Build the field sets.
     *
     * @param WidgetManager    $widgetManager  The widget manager in use.
     *
     * @param PaletteInterface $palette        The palette to use.
     *
     * @param PropertyValueBag $propertyValues The property values.
     *
     * @return array
     */
    private function buildFieldSet($widgetManager, $palette, $propertyValues)
    {
        $propertyDefinitions = $this->definition->getPropertiesDefinition();
        $isAutoSubmit        = ($this->environment->getInputProvider()->getValue('SUBMIT_TYPE') === 'auto');

        $fieldSets = [];
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
                if ((!$isAutoSubmit)
                    && $propertyValues
                    && $propertyValues->hasPropertyValue($propertyName)
                    && $propertyValues->isPropertyValueInvalid($propertyName)
                ) {
                    $this->errors = array_merge(
                        $this->errors,
                        $propertyValues->getPropertyValueErrors($propertyName)
                    );
                }

                $fields[] = $widgetManager->renderWidget($propertyName, $isAutoSubmit, $propertyValues);
                $hidden[] = sprintf('<input type="hidden" name="FORM_INPUTS[]" value="%s">', $propertyName);
            }

            $fieldSet['label']   = $legendName;
            $fieldSet['class']   = ($first) ? 'tl_tbox' : 'tl_box';
            $fieldSet['palette'] = implode('', $hidden) . implode('', $fields);
            $fieldSet['legend']  = $legend->getName();
            $fieldSets[]         = $fieldSet;

            $first = false;
        }

        return $fieldSets;
    }

    /**
     * Ensure a property is defined in the data definition and raise an exception if it is unknown.
     *
     * @param string                        $property            The property name to check.
     *
     * @param PropertiesDefinitionInterface $propertyDefinitions The property definitions.
     *
     * @return void
     *
     * @throws DcGeneralInvalidArgumentException When the property is not registered in the definition.
     */
    private function ensurePropertyExists($property, $propertyDefinitions)
    {
        if (!$propertyDefinitions->hasProperty($property)) {
            throw new DcGeneralInvalidArgumentException(
                sprintf(
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
    private function storeVersion(ModelInterface $model)
    {
        if (!$this->modelProvider->isVersioningEnabled()) {
            return;
        }

        $environment    = $this->environment;
        $modelId        = $model->getId();
        $dataProvider   = $environment->getDataProvider($this->model->getProviderName());
        $currentVersion = $dataProvider->getActiveVersion($modelId);
        // Compare version and current record.
        if (!$currentVersion
            || !$dataProvider->sameModels($model, $dataProvider->getVersion($modelId, $currentVersion))
        ) {
            $user     = \FrontendUser::getInstance();
            $username = '(frontend anonymous)';
            if ($user->authenticate()) {
                $username = $user->username;
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
    private function handleSubmit($buttons)
    {
        $inputProvider = $this->environment->getInputProvider();
        foreach (array_keys($buttons) as $button) {
            if ($inputProvider->hasValue($button)) {
                $event = new HandleSubmitEvent($this->environment, $this->model, $button);

                $this->dispatcher->dispatch(DcGeneralFrontendEvents::HANDLE_SUBMIT, $event);

                break;
            }
        }
    }

    /**
     * Determine the headline to use.
     *
     * @return string.
     */
    private function getHeadline()
    {
        if ($this->model->getId()) {
            return $this->translateLabel('editRecord', [$this->model->getId()]);
        }
        return $this->translateLabel('newRecord');
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

        // FIXME: manual sorting property handling is not enabled here as it originates from the backend defininiton.
        // Save the model.
        $dataProvider = $this->environment->getDataProvider($this->model->getProviderName());

        $dataProvider->save($this->model);

        $this->handlePostPersist();

        $this->storeVersion($this->model);
    }
}
