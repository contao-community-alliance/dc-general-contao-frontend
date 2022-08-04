<?php

/**
 * This file is part of contao-community-alliance/dc-general-contao-frontend.
 *
 * (c) 2016-2022 Contao Community Alliance.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package   contao-community-alliance/dc-general-contao-frontend
 * @author    Sven Baumann <baumann.sv@gmail.com>
 * @author    Ingolf Steinhardt <info@e-spin.de>
 * @copyright 2016-2022 Contao Community Alliance.
 * @license   https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 *
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Widgets;

use Contao\Controller;
use Contao\CoreBundle\Slug\Slug as SlugGenerator;
use Contao\CoreBundle\Framework\Adapter;
use Contao\Dbafs;
use Contao\File;
use Contao\FilesModel;
use Contao\FormFileUpload;
use Contao\Input;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * This is the widget is for upload a file in the frontend editing scope.
 * It has the following functions:
 *  - Saves the uploaded file in the configured directory
 *  - Can be reset from the model
 *  - Can delete the file from disk space
 *  - Can add a default image
 *  - Can add a default image
 *  - Output the Image as Thumbnail
 *  - Normalize the extent folder
 *  - Can prefix and postfix the filename.
 *
 * @property boolean deselect
 * @property boolean delete
 * @property string  extendFolder
 * @property boolean normalizeExtendFolder
 * @property boolean normalizeFilename
 * @property string  prefixFilename
 * @property string  postfixFilename
 * @property array   files
 * @property boolean showThumbnail
 * @property boolean multiple
 * @property string  sortBy
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class UploadOnSteroids extends FormFileUpload
{
    /**
     * The submit indicator.
     *
     * @var boolean
     */
    protected $blnSubmitInput = true;

    /**
     * The template.
     *
     * @var string
     */
    protected $strTemplate = 'form_upload-on-steroids';

    /**
     * CSS classes.
     *
     * @var string
     */
    protected $strPrefix = 'widget widget-upload widget-upload-on-steroids';

    /**
     * Image sizes.
     *
     * @var array
     */
    protected $imageSize;

    /**
     * The translator.
     *
     * @var TranslatorInterface
     */
    protected TranslatorInterface $translator;

    /**
     * The input provider.
     *
     * @var Adapter|Input
     */
    protected $inputProvider;

    /**
     * The file model.
     *
     * @var Adapter|FilesModel
     */
    private $filesModel;

    /**
     * The filesystem.
     *
     * @var Filesystem
     */
    private $filesystem;

    /**
     * The slug generator.
     *
     * @var SlugGenerator
     */
    private $slugGenerator;

    /**
     * {@inheritDoc}
     */
    public function __set($key, $value)
    {
        if (\in_array(
            $key,
            [
                'deselect',
                'delete',
                'extendFolder',
                'normalizeExtendFolder',
                'normalizeFilename',
                'prefixFilename',
                'postfixFilename',
                'files',
                'showThumbnail',
                'multiple',
                'imageSize',
                'sortBy'
            ]
        )) {
            $this->arrConfiguration[$key] = $value;

            return;
        }

        parent::__set($key, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function parse($attributes = null)
    {
        $this->addIsDeletable();
        $this->addIsDeselectable();
        $this->addIsMultiple();
        $this->addShowThumbnail();
        $this->getImageSize();
        $this->addFiles($this->sortBy);

        $this->value = \implode(',', \array_map('\Contao\StringUtil::binToUuid', (array) $this->value));

        return parent::parse($attributes);
    }

    /**
     * Parse the filename.
     *
     * @param string $filename The filename.
     *
     * @return string
     */
    public function parseFilename(string $filename): string
    {
        if (empty($filename) || !\is_string($filename)) {
            return $filename;
        }

        return $this->normalizeFilename($filename);
    }

    /**
     * {@inheritDoc}
     */
    public function validate()
    {
        $inputName = $this->name;

        if ($this->normalizeExtendFolder && $this->extendFolder) {
            $this->extendFolder = $this->slugGenerator()->generate($this->extendFolder, $this->getSlugOptions());
        }

        if ($this->extendFolder) {
            $uploadFolder     = $this->filesModel()->findByUuid($this->uploadFolder);
            $uploadFolderPath = $uploadFolder->path . DIRECTORY_SEPARATOR . $this->extendFolder;


            $newUploadFolder = null;
            if (!$this->filesystem()->exists($uploadFolderPath)) {
                $this->filesystem()->mkdir($uploadFolderPath);
                $newUploadFolder = Dbafs::addResource($uploadFolderPath);
            }

            if (!$newUploadFolder) {
                $newUploadFolder = $this->filesModel()->findByPath($uploadFolderPath);
            }

            $this->uploadFolder = $newUploadFolder->uuid;
        }

        $this->validateSingleUpload();
        $this->validateMultipleUpload();
        $this->deselectFile($inputName);
        $this->deleteFile($inputName);
    }

    /**
     * Validate single upload widget.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    private function validateSingleUpload(): void
    {
        if ($this->multiple || $this->hasErrors()) {
            return;
        }

        $inputName                  = $this->name;
        $_FILES[$inputName]['name'] = $this->parseFilename($_FILES[$inputName]['name']);

        parent::validate();

        if (!isset($_SESSION['FILES'][$inputName]) || $this->hasErrors()) {
            return;
        }

        $file = $_SESSION['FILES'][$inputName];
        if (!isset($file['uuid'])) {
            return;
        }

        $this->value = StringUtil::uuidToBin($file['uuid']);
    }

    /**
     * Validate multiple upload widget.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    private function validateMultipleUpload(): void
    {
        if (!$this->multiple || $this->hasErrors()) {
            return;
        }

        $inputName = $this->name;
        $values    = \array_map('\Contao\StringUtil::binToUuid', $this->value);

        $files      = [];
        $inputFiles = $this->getMultipleUploadedFiles();
        foreach ($inputFiles as $inputFile) {
            $_FILES[$inputName] = $inputFile;

            $_FILES[$inputName]['name'] = $this->parseFilename($_FILES[$inputName]['name']);

            parent::validate();

            if (!isset($_SESSION['FILES'][$inputName]) || $this->hasErrors()) {
                return;
            }

            $file = $_SESSION['FILES'][$inputName];
            if (!isset($file['uuid'])) {
                return;
            }

            $files[] = $file;

            unset($_SESSION['FILES']);
        }

        if (!\count($files)) {
            return;
        }

        $setValues = \array_values(\array_unique(\array_merge($values, \array_column($files, 'uuid'))));

        $this->value = \array_map('\Contao\StringUtil::uuidToBin', $setValues);
    }

    /**
     * Get the multiple uploaded files.
     *
     * @return array
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    private function getMultipleUploadedFiles(): array
    {
        if (!isset($_FILES[$this->name])) {
            return [];
        }

        $files = [];
        foreach ($_FILES[$this->name] as $propertyName => $values) {
            foreach ($values as $key => $value) {
                $files[$key][$propertyName] = $value;
            }
        }

        return $files;
    }

    /**
     * Deselect the file, if is mark for deselect.
     *
     * @param string $inputName The input nanme.
     *
     * @return void
     */
    private function deselectFile(string $inputName)
    {
        if (!$this->deselect
            || $this->hasErrors()
            || !($post = $this->inputProvider()->post($inputName))
            || !isset($post['reset'][0])
        ) {
            return;
        }

        if (!$this->multiple && (StringUtil::binToUuid($this->value) === $post['reset'][0])) {
            $this->value = '';

            return;
        }

        $values     = \array_map('\Contao\StringUtil::binToUuid', $this->value);
        $diffValues = \array_values(\array_diff($values, $post['reset']));

        $this->value = \array_map('\Contao\StringUtil::uuidToBin', $diffValues);
    }

    /**
     * Delete the file, if is mark for delete.
     *
     * @param string $inputName The input name.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function deleteFile(string $inputName)
    {
        if (!$this->delete
            || $this->hasErrors()
            || !($post = $this->inputProvider()->post($inputName))
            || !isset($post['delete'][0])
        ) {
            return;
        }

        if (!$this->multiple && (StringUtil::binToUuid($this->value) === $post['delete'][0])) {
            $this->value = '';

            $file = $this->filesModel()->findByUuid($this->value);
            if ($file) {
                $this->filesystem->remove($file->path);
                $file->delete();
            }

            Dbafs::deleteResource($file->path);

            return;
        }

        $values     = \array_map('\Contao\StringUtil::binToUuid', $this->value);
        $diffValues = \array_values(\array_diff($values, $post['delete']));

        foreach ($post['delete'] as $delete) {
            $file = $this->filesModel()->findByUuid(StringUtil::uuidToBin($delete));
            if (!$file) {
                continue;
            }

            $this->filesystem()->remove($file->path);
            $file->delete();
            Dbafs::deleteResource($file->path);
        }

        $this->value = \array_map('\Contao\StringUtil::uuidToBin', $diffValues);
    }

    /**
     * Normalize the filename.
     *
     * @param string $filename The filename.
     *
     * @return string
     */
    private function normalizeFilename(string $filename): string
    {
        if (!$this->normalizeFilename) {
            return $filename;
        }

        $fileInfo = \pathinfo($filename);

        $currentExtension   = $fileInfo['extension'];
        $normalizeExtension = $this->slugGenerator()->generate($currentExtension, $this->getSlugOptions());

        $currentFilename   = $fileInfo['filename'];
        $normalizeFilename = $this->slugGenerator()->generate($currentFilename, $this->getSlugOptions());

        return $this->preOrPostFixFilename($normalizeFilename) . '.' . $normalizeExtension;
    }

    /**
     * Prefix or postfix the filename.
     *
     * @param string $filename The filename.
     *
     * @return string
     */
    private function preOrPostFixFilename(string $filename): string
    {
        if (!($this->prefixFilename || $this->postfixFilename)) {
            return $filename;
        }

        $prefix  = $this->prefixFilename
            ? $this->slugGenerator()->generate($this->prefixFilename, $this->getSlugOptions())
            : '';
        $postfix = $this->postfixFilename
            ? $this->slugGenerator()->generate($this->postfixFilename, $this->getSlugOptions())
            : '';

        return $prefix . $filename . $postfix;
    }

    /**
     * Add the files from the value.
     *
     * @param string $sortBy The file sorting.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function addFiles($sortBy)
    {
        if (empty($this->value)) {
            $this->files = null;
            return;
        }

        /** @var Connection $connection */
        $connection = self::getContainer()->get('database_connection');

        $platform = $connection->getDatabasePlatform();

        $builder = $connection->createQueryBuilder();

        switch ($sortBy) {
            case 'name_desc':
                $builder->orderBy('name', 'DESC');
                break;
            case 'date_asc':
                $builder->orderBy('tstamp', 'ASC');
                break;
            case 'date_desc':
                $builder->orderBy('tstamp', 'DESC');
                break;
            case 'random':
                $builder->orderBy('RAND()');
                break;
            default:
            case 'name_asc':
                $builder->orderBy('name', 'ASC');
        }

        $builder
            ->select(
                $platform->quoteIdentifier('id'),
                $platform->quoteIdentifier('pid'),
                $platform->quoteIdentifier('tstamp'),
                $platform->quoteIdentifier('uuid'),
                $platform->quoteIdentifier('type'),
                $platform->quoteIdentifier('path'),
                $platform->quoteIdentifier('extension'),
                $platform->quoteIdentifier('hash'),
                $platform->quoteIdentifier('found'),
                $platform->quoteIdentifier('name'),
                $platform->quoteIdentifier('importantPartX'),
                $platform->quoteIdentifier('importantPartY'),
                $platform->quoteIdentifier('importantPartWidth'),
                $platform->quoteIdentifier('importantPartHeight'),
                $platform->quoteIdentifier('meta')
            )
            ->from($platform->quoteIdentifier('tl_files'))
            ->where($builder->expr()->in($platform->quoteIdentifier('uuid'), ':uuids'))
            ->setParameter('uuids', (array) $this->value, Connection::PARAM_STR_ARRAY);

        $statement = $builder->executeQuery();
        if (!$statement->rowCount()) {
            return;
        }

        if (!$this->showThumbnail) {
            $this->files = $statement->fetchAllAssociative();
        }

        $fileList   = [];
        $container  = System::getContainer();
        $projectDir = $container->getParameter('kernel.project_dir');
        foreach ($statement->fetchAllAssociative() as $file) {
            $objFile          = FilesModel::findByUuid($file['uuid']);
            $src              = $container->get('contao.image.image_factory')
                ->create($projectDir . '/' . rawurldecode($objFile->path), $this->imageSize)
                ->getUrl($projectDir);
            $objThumbnailFile = new File(rawurldecode($src));

            $file['thumbnail'] = [
                'src'    => StringUtil::specialcharsUrl(Controller::addFilesUrlTo($src)),
                'width'  => $objThumbnailFile->imageSize[0],
                'height' => $objThumbnailFile->imageSize[1]
            ];

            $fileList[] = $file;
        }

        $this->files = $fileList;
    }

    /**
     * Translate.
     *
     * @param string      $transId    The message id (may also be an object that can be cast to string).
     * @param array       $parameters An array of parameters for the message.
     * @param string|null $domain     The domain for the message or null to use the default.
     * @param string|null $locale     The locale or null to use the default.
     *
     * @return string
     */
    public function trans(
        string $transId,
        array $parameters = [],
        ?string $domain = 'contao_default',
        ?string $locale = null
    ): string {
        return $this->translator()->trans($transId, $parameters, $domain, $locale);
    }

    /**
     * Add file is deletable.
     *
     * @return void
     */
    private function addIsDeletable(): void
    {
        $this->prefix .= $this->delete ? ' is-deletable' : '';
    }

    /**
     * Add file is deselect able.
     *
     * @return void
     */
    private function addIsDeselectable(): void
    {
        $this->prefix .= $this->deselect ? ' is-deselectable' : '';
    }

    /**
     * Add file show thumbnail.
     *
     * @return void
     */
    private function addShowThumbnail(): void
    {
        $this->prefix .= $this->showThumbnail ? ' show-thumbnail' : '';
    }

    /**
     * Add the upload file is multiple.
     *
     * @return void
     */
    private function addIsMultiple(): void
    {
        if (!$this->multiple) {
            return;
        }

        $this->prefix .= $this->multiple ? ' is-multiple' : '';

        $this->addAttribute('multiple', 'multiple');
    }

    /**
     * Get the input provider.
     *
     * @return Adapter|Input
     */
    private function inputProvider(): Adapter
    {
        if (!$this->inputProvider) {
            $this->inputProvider = self::getContainer()->get('contao.framework')->getAdapter(Input::class);
        }

        return $this->inputProvider;
    }

    /**
     * Get the files model.
     *
     * @return Adapter|FilesModel
     */
    private function filesModel(): Adapter
    {
        if (!$this->filesModel) {
            $this->filesModel = self::getContainer()->get('contao.framework')->getAdapter(FilesModel::class);
        }

        return $this->filesModel;
    }

    /**
     * Get the filesystem.
     *
     * @return Filesystem
     */
    private function filesystem()
    {
        if (!$this->filesystem) {
            $this->filesystem = self::getContainer()->get('filesystem');
        }

        return $this->filesystem;
    }

    /**
     * Get the slug generator.
     *
     * @return SlugGenerator
     */
    private function slugGenerator()
    {
        if (!$this->slugGenerator) {
            $this->slugGenerator = System::getContainer()->get('contao.slug');
        }

        return $this->slugGenerator;
    }

    /**
     * Get the slug options.
     *
     * @return array
     */
    protected function getSlugOptions(): array
    {
        return ['locale' => 'de', 'validChars' => '0-9a-z_-'];
    }

    /**
     * Get the translator.
     *
     * @return TranslatorInterface
     */
    private function translator(): TranslatorInterface
    {
        if (!$this->filesystem) {
            $this->filesystem = self::getContainer()->get('translator');
        }

        return $this->filesystem;
    }

    /**
     * Get the image sizes.
     *
     * @return void
     */
    private function getImageSize(): void
    {
        $this->imageSize = StringUtil::deserialize($this->imageSize, true);
    }
}
