<?php
namespace IiifServer\Media\Ingester;

use IiifServer\Mvc\Controller\Plugin\TileBuilder;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\TempFile;
use Omeka\File\Uploader;
use Omeka\File\Validator;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;
use Zend\Form\Element\File;
use Zend\Validator\File\IsImage;
use Zend\View\Renderer\PhpRenderer;

class Tile implements IngesterInterface
{
    /**
     * @var Validator
     */
    protected $validator;

    /**
     * @var Uploader
     */
    protected $uploader;

    /**
     * @var TileBuilder
     */
    protected $tileBuilder;

    /**
     * @var array
     */
    protected $tileParams;

    public function __construct(
        Validator $validator,
        Uploader $uploader,
        TileBuilder $tileBuilder,
        array $tileParams
    ) {
        $this->validator = $validator;
        $this->uploader = $uploader;
        $this->tileBuilder = $tileBuilder;
        $this->tileParams = $tileParams;
    }

    /**
     * {@inheritDoc}
     */
    public function getLabel()
    {
        return 'Tiler'; // @translate
    }

    /**
     * {@inheritDoc}
     */
    public function getRenderer()
    {
        return 'tile';
    }

    /**
     * {@inheritDoc}
     * @see \Omeka\Media\Ingester\IngesterInterface::ingest()
     * @see \Omeka\Media\Ingester\Upload::ingest()
     */
    public function ingest(Media $media, Request $request, ErrorStore $errorStore)
    {
        $data = $request->getContent();
        $fileData = $request->getFileData();
        if (!isset($fileData['tile'])) {
            $errorStore->addError('error', 'No files were uploaded for tiling');
            return;
        }

        if (!isset($data['tile_index'])) {
            $errorStore->addError('error', 'No tiling index was specified');
            return;
        }

        $index = $data['tile_index'];
        if (!isset($fileData['tile'][$index])) {
            $errorStore->addError('error', 'No file uploaded for tiling for the specified index');
            return;
        }

        $tempFile = $this->uploader->upload($fileData['tile'][$index], $errorStore);
        if (!$tempFile) {
            return;
        }

        $tempFile->setSourceName($fileData['tile'][$index]['name']);
        if (!$this->validator->validate($tempFile, $errorStore)) {
            return;
        }

        if (!$this->validatorFileIsImage($tempFile, $errorStore)) {
            return;
        }

        $media->setStorageId($tempFile->getStorageId());
        $media->setExtension($tempFile->getExtension());
        $media->setMediaType($tempFile->getMediaType());
        $media->setSha256($tempFile->getSha256());
        $hasThumbnails = $tempFile->storeThumbnails();
        $media->setHasThumbnails($hasThumbnails);
        $media->setHasOriginal(true);
        if (!array_key_exists('o:source', $data)) {
            $media->setSource($fileData['tile'][$index]['name']);
        }
        $tempFile->storeOriginal();
        if (file_exists($tempFile->getTempPath())) {
            $tempFile->delete();
        }

        $storagePath = $this->getStoragePath('original', $media->getFilename());
        $source = OMEKA_PATH
            . DIRECTORY_SEPARATOR . 'files'
            . DIRECTORY_SEPARATOR . $storagePath;

        $tileDir = OMEKA_PATH
            . DIRECTORY_SEPARATOR . 'files'
            . DIRECTORY_SEPARATOR . $this->tileParams['tile_dir'];

        $params = $this->tileParams;
        $params['storageId'] = $media->getStorageId();

        $tileBuilder = $this->tileBuilder;
        $tileBuilder($source, $tileDir, $params);
    }

    /**
     * {@inheritDoc}
     */
    public function form(PhpRenderer $view, array $options = [])
    {
        $fileInput = new File('tile[__index__]');
        $fileInput->setOptions([
            'label' => 'Upload image to tile', // @translate
            'info' => $view->uploadLimit(),
        ]);
        $fileInput->setAttributes([
            'id' => 'media-tile-input-__index__',
            'required' => true,
        ]);
        $field = $view->formRow($fileInput);
        return $field . '<input type="hidden" name="o:media[__index__][tile_index]" value="__index__">';
    }

    /**
     * Get a storage path.
     *
     * @param string $prefix The storage prefix
     * @param string $name The file name, or basename if extension is passed
     * @param null|string $extension The file extension
     * @return string
     * @todo Refactorize.
     */
    protected function getStoragePath($prefix, $name, $extension = null)
    {
        return sprintf('%s/%s%s', $prefix, $name, $extension ? ".$extension" : null);
    }

    /**
     * Validate if the file is an image.
     *
     * Note: Omeka S beta 5 doesn't use Zend file input validator chain any more.
     *
     * Pass the $errorStore object if an error should raise an API validation
     * error.
     *
     * @see Omeka\File\Validator
     *
     * @param TempFile $tempFile
     * @param ErrorStore|null $errorStore
     * @return bool
     */
    protected function validatorFileIsImage(TempFile $tempFile, ErrorStore $errorStore = null)
    {
        // $validatorChain = $fileInput->getValidatorChain();
        // $validatorChain->attachByName('FileIsImage', [], true);
        // $fileInput->setValidatorChain($validatorChain);

        $validator = new IsImage();
        $result = $validator->isValid([
            'tmp_name' => $tempFile->getTempPath(),
            'name' => $tempFile->getSourceName(),
            'type' => $tempFile->getMediaType(),
        ]);
        if (!$result) {
            if ($errorStore) {
                $errorStore->addError('tile', sprintf(
                    'Error validating "%s". The file to tile should be an image, not "%s".', // @translate
                    $tempFile->getSourceName(), $tempFile->getMediaType()
                ));
            }
        }
        return $result;
    }
}
