<?php

/**
 * This file is part of contao-community-alliance/dc-general-contao-frontend.
 *
 * (c) 2016-2025 Contao Community Alliance.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package   contao-community-alliance/dc-general-contao-frontend
 * @author    Sven Baumann <baumann.sv@gmail.com>
 * @author    Ingolf Steinhardt <info@e-spin.de>
 * @copyright 2016-2025 Contao Community Alliance.
 * @license   https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 *
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Widgets;

use Contao\Controller;
use Contao\CoreBundle\Image\ImageFactory;
use Contao\CoreBundle\Slug\Slug as SlugGenerator;
use Contao\CoreBundle\Framework\Adapter;
use Contao\Dbafs;
use Contao\File;
use Contao\FilesModel;
use Contao\FormUpload;
use Contao\Input;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @psalm-suppress PropertyNotSetInConstructor
 * @psalm-suppress UndefinedThisPropertyFetch
 * @psalm-suppress UndefinedThisPropertyAssignment
 */
class UploadOnSteroids extends FormUpload
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
     * Image sizes as serialized string.
     *
     * @var string
     */
    protected string $imageSize;

    /**
     * The translator.
     *
     * @var TranslatorInterface|null
     */
    protected ?TranslatorInterface $translator = null;

    /**
     * The input provider.
     *
     * @var Adapter<Input>
     */
    protected ?Adapter $inputProvider = null;

    /**
     * The file model.
     *
     * @var Adapter<FilesModel>|null
     */
    private ?Adapter $filesModel = null;

    /**
     * The filesystem.
     *
     * @var Filesystem|null
     */
    private ?Filesystem $filesystem = null;

    /**
     * The slug generator.
     *
     * @var SlugGenerator|null
     */
    private ?SlugGenerator $slugGenerator = null;

    /**
     * {@inheritDoc}
     */
    public function __set($strKey, $varValue)
    {
        if (
            \in_array(
                $strKey,
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
            )
        ) {
            $this->arrConfiguration[$strKey] = $varValue;

            return;
        }

        parent::__set($strKey, $varValue);
    }

    /**
     * {@inheritDoc}
     */
    public function parse($arrAttributes = null)
    {
        $this->addIsDeletable();
        $this->addIsDeselectable();
        $this->addIsMultiple();
        $this->addShowThumbnail();
        $this->addFiles($this->sortBy);

        $this->value = \implode(',', \array_map('\Contao\StringUtil::binToUuid', (array) $this->value));

        return parent::parse($arrAttributes);
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
        if (empty($filename)) {
            return $filename;
        }

        return $this->convertFilename($filename);
    }

    /**
     * {@inheritDoc}
     */
    public function validate(): void
    {
        $inputName = $this->name;

        if ($this->normalizeExtendFolder && $this->extendFolder) {
            $this->extendFolder = $this->slugGenerator()->generate($this->extendFolder, $this->getSlugOptions());
        }

        if ($this->extendFolder) {
            /** @psalm-suppress InternalMethod - Class ContaoFramework is internal, not the getAdapter() method. */
            $uploadFolder     = $this->filesModel()->findByUuid($this->uploadFolder);
            $uploadFolderPath = (string) $uploadFolder?->path . DIRECTORY_SEPARATOR . $this->extendFolder;


            $newUploadFolder = null;
            if (!$this->filesystem()->exists($uploadFolderPath)) {
                $this->filesystem()->mkdir($uploadFolderPath);
                $newUploadFolder = Dbafs::addResource($uploadFolderPath);
            }

            if (null === $newUploadFolder) {
                /** @psalm-suppress InternalMethod - Class ContaoFramework is internal, not the getAdapter() method. */
                $newUploadFolder = $this->filesModel()->findByPath($uploadFolderPath);
            }

            $this->uploadFolder = $newUploadFolder?->uuid ?? '';
        }

        $this->deselectFile($inputName);
        $this->deleteFile($inputName);
        $this->validateSingleUpload();
        $this->validateMultipleUpload();
    }

    /**
     * Validate single upload widget.
     *
     * @return void
     *
     * @throws \Exception
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    private function validateSingleUpload(): void
    {
        if ($this->multiple || $this->hasErrors()) {
            return;
        }

        $inputName                  = $this->name;
        $_FILES[$inputName]['name'] = $this->parseFilename($_FILES[$inputName]['name'] ?? '');

        parent::validate();

        $file = $this->varValue;

        if ($this->hasErrors() || !\is_array($file) || !isset($file['uuid'])) {
            return;
        }

        $this->value = StringUtil::uuidToBin($file['uuid']);
    }

    /**
     * Validate multiple upload widget.
     *
     * @return void
     *
     * @throws \Exception
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    private function validateMultipleUpload(): void
    {
        if (!$this->multiple || $this->hasErrors()) {
            return;
        }

        $inputName = $this->name;
        $values    = \array_map('\Contao\StringUtil::binToUuid', (array) $this->value);

        $files      = [];
        $inputFiles = $this->getMultipleUploadedFiles();
        foreach ($inputFiles as $inputFile) {
            $_FILES[$inputName] = $inputFile;

            $_FILES[$inputName]['name'] = $this->parseFilename($_FILES[$inputName]['name']);

            parent::validate();

            $file = $this->varValue;

            if ($this->hasErrors() || !\is_array($file) || !isset($file['uuid'])) {
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
            foreach ((array) $values as $key => $value) {
                $files[$key][$propertyName] = $value;
            }
        }

        return $files;
    }

    /**
     * Deselect the file, if is mark for deselect.
     *
     * @param string $inputName The input name.
     *
     * @return void
     */
    private function deselectFile(string $inputName): void
    {
        /** @psalm-suppress InternalMethod - Class ContaoFramework is internal, not the getAdapter() method. */
        if (
            !$this->deselect
            || $this->hasErrors()
            || [] === ($post = ($this->getCurrentRequest()?->request->all($inputName . '__reset')))
        ) {
            return;
        }

        if (!$this->multiple && (StringUtil::binToUuid($this->value) === $post[0])) {
            $this->value = '';

            return;
        }

        $values     = \array_map('\Contao\StringUtil::binToUuid', (array) $this->value);
        $diffValues = \array_values(\array_diff($values, $post));

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
    private function deleteFile(string $inputName): void
    {
        /** @psalm-suppress InternalMethod - Class ContaoFramework is internal, not the getAdapter() method. */
        if (
            !$this->delete
            || $this->hasErrors()
            || [] === ($post = ($this->getCurrentRequest()?->request->all($inputName . '__delete')))
        ) {
            return;
        }

        if (!$this->multiple && (StringUtil::binToUuid($this->value) === $post[0])) {
            /** @psalm-suppress InternalMethod - Class ContaoFramework is internal, not the getAdapter() method. */
            $file = $this->filesModel()->findByUuid($this->value);
            if (null !== $file) {
                $this->filesystem()->remove($file->path);
                $file->delete();
                Dbafs::deleteResource($file->path);
            }
            $this->value = '';

            return;
        }

        $values     = \array_map('\Contao\StringUtil::binToUuid', (array) $this->value);
        $diffValues = \array_values(\array_diff($values, $post));

        foreach ($post as $delete) {
            /** @psalm-suppress InternalMethod - Class ContaoFramework is internal, not the getAdapter() method. */
            $file = $this->filesModel()->findByUuid(StringUtil::uuidToBin((string) $delete));
            if (null === $file) {
                continue;
            }

            $this->filesystem()->remove($file->path);
            $file->delete();
            Dbafs::deleteResource($file->path);
        }

        $this->value = \array_map('\Contao\StringUtil::uuidToBin', $diffValues);
    }

    /**
     * Convert the filename.
     *
     * @param string $filename The filename.
     *
     * @return string
     */
    private function convertFilename(string $filename): string
    {
        $fileInfo  = \pathinfo($filename);
        $extension = $fileInfo['extension'] ?? '';
        $filename  = $fileInfo['filename'] ?? '';

        if ($this->normalizeFilename) {
            $extension = $this->slugGenerator()->generate($extension, $this->getSlugOptions());
            $filename  = $this->slugGenerator()->generate($filename, $this->getSlugOptions());
        }

        return $this->preOrPostFixFilename($filename) . '.' . $extension;
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

        // We save the default delimiter '-' at prefix and postfix
        // see https://github.com/ausi/slug-generator/issues/34.
        $prefix = $this->prefixFilename;
        if ($this->prefixFilename && $this->normalizeFilename) {
            $prefix = \str_repeat('-', \strspn($this->prefixFilename, '-')) .
                      $this->slugGenerator()->generate($this->prefixFilename, $this->getSlugOptions()) .
                      \str_repeat('-', \strspn(\strrev($this->prefixFilename), '-'));
        }

        $postfix = $this->postfixFilename;
        if ($this->postfixFilename && $this->normalizeFilename) {
            $postfix = \str_repeat('-', \strspn($this->postfixFilename, '-')) .
                       $this->slugGenerator()->generate($this->postfixFilename, $this->getSlugOptions()) .
                       \str_repeat('-', \strspn(\strrev($this->postfixFilename), '-'));
        }

        return $prefix . $filename . $postfix;
    }

    /**
     * Add the files from the value.
     *
     * @param string $sortBy The file sorting.
     *
     * @return void
     *
     * @throws Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function addFiles($sortBy): void
    {
        $this->files = [];

        if (empty($this->value)) {
            return;
        }

        /** @var Connection $connection */
        $connection = self::getContainer()->get('database_connection');
        $builder    = $connection->createQueryBuilder();

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
                't.id',
                't.pid',
                't.tstamp',
                't.uuid',
                't.type',
                't.path',
                't.extension',
                't.hash',
                't.found',
                't.name',
                't.importantPartX',
                't.importantPartY',
                't.importantPartWidth',
                't.importantPartHeight',
                't.meta'
            )
            ->from('tl_files', 't')
            ->where($builder->expr()->in('t.uuid', ':uuids'))
            ->setParameter('uuids', (array) $this->value, ArrayParameterType::STRING);

        $statement = $builder->executeQuery();
        if (!$statement->rowCount()) {
            return;
        }

        // Generate simple file list.
        if (!$this->showThumbnail) {
            $this->files = $statement->fetchAllAssociative();

            return;
        }

        // Generate file list with thumbnails.
        $fileList   = [];
        $container  = System::getContainer();
        $projectDir = $container->getParameter('kernel.project_dir');
        assert(\is_string($projectDir));
        $imageFactory = $container->get('contao.image.factory');
        assert($imageFactory instanceof ImageFactory);
        foreach ($statement->fetchAllAssociative() as $file) {
            if (null === ($objFile = FilesModel::findByUuid($file['uuid']))) {
                continue;
            }
            $src              = $imageFactory
                ->create($projectDir . '/' . \rawurldecode($objFile->path), $this->imageSize)
                ->getUrl($projectDir);
            $objThumbnailFile = new File(\rawurldecode($src));

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
     * @param string $strId     The message id (may also be an object that can be cast to string).
     * @param array  $arrParams An array of parameters for the message.
     * @param string $strDomain The domain for the message or null to use the default.
     * @param string $locale    The locale or null to use the default.
     *
     * @return string
     */
    public function trans(
        $strId,
        array $arrParams = [],
        $strDomain = 'contao_default',
        $locale = null
    ): string {
        return $this->translator()->trans($strId, $arrParams, $strDomain, $locale);
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

        $this->prefix .= ' is-multiple';

        $this->addAttribute('multiple', 'multiple');
    }

    private function getCurrentRequest(): ?Request
    {
        $requestStack = System::getContainer()->get('request_stack');
        if (!$requestStack instanceof RequestStack) {
            return null;
        }
        return $requestStack->getCurrentRequest();
    }

    /**
     * Get the files model.
     *
     * @return Adapter<FilesModel>
     */
    private function filesModel(): Adapter
    {
        if (null === $this->filesModel) {
            $filesModel = self::getContainer()->get('contao.framework')->getAdapter(FilesModel::class);
            assert($filesModel instanceof Adapter);
            $this->filesModel = $filesModel;
        }

        return $this->filesModel;
    }

    /**
     * Get the filesystem.
     *
     * @return Filesystem
     */
    private function filesystem(): Filesystem
    {
        if (null === $this->filesystem) {
            $filesystem = self::getContainer()->get('cca.dc-general.contao_frontend.filesystem');
            assert($filesystem instanceof Filesystem);
            $this->filesystem = $filesystem;
        }

        return $this->filesystem;
    }

    /**
     * Get the slug generator.
     *
     * @return SlugGenerator
     */
    private function slugGenerator(): SlugGenerator
    {
        if (null === $this->slugGenerator) {
            $slugGenerator = System::getContainer()->get('contao.slug');
            assert($slugGenerator instanceof SlugGenerator);
            $this->slugGenerator = $slugGenerator;
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
        if (null === $this->translator) {
            $translator = self::getContainer()->get('translator');
            assert($translator instanceof TranslatorInterface);
            $this->translator = $translator;
        }

        return $this->translator;
    }
}
