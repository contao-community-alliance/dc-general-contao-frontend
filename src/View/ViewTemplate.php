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
 * @author     Ingolf Steinhardt <info@e-spin.de>
 * @copyright  2015-2025 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View;

use Contao\BackendTemplate;
use ContaoCommunityAlliance\DcGeneral\View\ViewTemplateInterface;
use ContaoCommunityAlliance\Translator\TranslatorInterface;

/**
 * This class is used for the contao frontend view as template.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 * @psalm-suppress DeprecatedClass
 *
 * @deprecated Deprecated since Contao 5.0, to be removed in Contao 6; use Twig templates instead
 */
class ViewTemplate extends BackendTemplate implements ViewTemplateInterface, TranslatorInterface
{
    /**
     * The translator.
     *
     * @var TranslatorInterface
     */
    protected TranslatorInterface $translator;

    /**
     * Get the translator.
     *
     * @return TranslatorInterface
     */
    public function getTranslator()
    {
        return $this->translator;
    }

    /**
     * Set the translator.
     *
     * @param TranslatorInterface $translator The translator.
     *
     * @return $this
     */
    public function setTranslator(TranslatorInterface $translator)
    {
        $this->translator = $translator;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function setData($arrData)
    {
        parent::setData($arrData);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function set($name, $value)
    {
        $this->$name = $value;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function get($name)
    {
        return $this->$name;
    }

    /**
     * {@inheritdoc}
     */
    public function translate($string, $domain = null, array $parameters = [], $locale = null)
    {
        return $this->translator->translate($string, $domain, $parameters, $locale);
    }

    /**
     * {@inheritdoc}
     */
    public function translatePluralized($string, $number, $domain = null, array $parameters = [], $locale = null)
    {
        return $this->translator->translatePluralized($string, $number, $domain, $parameters, $locale);
    }

    // @codingStandardsIgnoreStart
    /**
     * {@inheritDoc}
     */
    public function getData()
    {
        return parent::getData();
    }

    /**
     * {@inheritDoc}
     */
    public function parse()
    {
        return parent::parse();
    }

    /**
     * {@inheritDoc}
     */
    public function output()
    {
        /** @psalm-suppress DeprecatedMethod */
        parent::output();
    }
    // @codingStandardsIgnoreEnd
}
