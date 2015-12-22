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

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Event;

use ContaoCommunityAlliance\DcGeneral\Data\ModelInterface;
use ContaoCommunityAlliance\DcGeneral\EnvironmentInterface;
use ContaoCommunityAlliance\DcGeneral\Event\AbstractModelAwareEvent;

/**
 * This event is being emitted when the edit mask has encountered a submit and post action shall be performed.
 *
 * This could be redirecting to another page or the like.
 */
class HandleSubmitEvent extends AbstractModelAwareEvent
{
    /**
     * The button that caused the submit.
     *
     * @var string
     */
    private $buttonName;

    /**
     * Create a new event.
     *
     * @param EnvironmentInterface $environment The environment instance in use.
     *
     * @param ModelInterface       $model       The model holding the data for the widget that shall be instantiated.
     *
     * @param string               $buttonName  The button that caused the submit.
     */
    public function __construct(
        EnvironmentInterface $environment,
        ModelInterface $model,
        $buttonName
    ) {
        parent::__construct($environment, $model);

        $this->buttonName = $buttonName;
    }

    /**
     * Retrieve the button that caused the submit.
     *
     * @return string
     */
    public function getButtonName()
    {
        return $this->buttonName;
    }
}
