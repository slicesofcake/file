<?php
namespace File\Model\Behavior;

use Cake\ORM\Behavior;
use Cake\Utility\Text;
use Cake\Event\Event;
use Cake\Datasource\EntityInterface;

class FileBehavior extends Behavior
{

    /**
     * @inheritdoc
     */
    protected $_defaultConfig = [
        'types' => [ // Default allowed types
            'image/jpeg',
            'image/jpg',
            'image/pjpeg',
            'image/pjpg',
            'image/png',
            'image/x-png',
            'image/gif',
            'image/webp',
        ],
        'extensions' => [ // Default allowed extensions
            'jpeg',
            'jpg',
            'pjpg',
            'pjpeg',
            'png',
            'gif',
            'webp',
        ],
        'path' => 'files',
        'background' => [255, 255, 255, 127],
        'watermark' => '',
        'thumbs' => [],
    ];

    /**
     * Array of files to upload
     *
     * @var array
     */
    protected $_files = [];

    /**
     * @inheritdoc
     */
    public function initialize(array $config)
    {
        foreach ($config as $field => $array) {
            $this->_config = [];

            if (is_array($array)) {
                $this->_config[$this->_table->getAlias()][$field] = array_merge($this->_defaultConfig, $array);
            } else {
                $this->_config[$this->_table->getAlias()][$array] = $this->_defaultConfig;
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function beforeMarshal(Event $event, $data = [], $options = [])
    {
        if (!empty($fieldList = $this->_config[$this->_table->getAlias()])) {
            foreach ($fieldList as $fieldName => $fieldOptions) {
                // Check for temporary file
                if (isset($data[$fieldName]) && !empty($data[$fieldName]['name']) && file_exists($data[$fieldName]['tmp_name'])) {
                    // Create archive file data with suffix on original field name
                    // @todo Create only when field name is used in database
                    $data['_' . $fieldName] = $data[$fieldName];

                    $this->_files[$fieldName] = $data[$fieldName];
                    $this->_files[$fieldName]['path'] = $this->_prepareDir($fieldOptions['path']);
                    $this->_files[$fieldName]['name'] = $this->_prepareName($data, $fieldName);

                    $data[$fieldName] = $this->_files[$fieldName]['name'];
                } else {
                    if (isset($data[$fieldName]) && is_array($data[$fieldName])) {
                        // Delete file array from data when is not attached
                        unset($data[$fieldName]);
                    }
                }
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function afterSave(Event $event, EntityInterface $entity, $options = [])
    {
        //if ($created !== true && empty($this->_files)) {

        //} else {
        $this->prepareFile($entity);
        //}
    }

    /**
     * @inheritdoc
     */
    public function beforeDelete(Model $model, $cascade = true)
    {
        return $this->deleteFile($model);
    }

    /**
     * Copy file to destination and if field has configurations for thumbs, then create them.
     *
     * @param EntityInterface $entity Entity
     */
    public function prepareFile(EntityInterface $entity)
    {
        foreach ($this->_files as $fieldName => $fieldOptions) {
            // Path to default file
            $fileName = $fieldOptions['path'] . DS . $this->_files[$fieldName]['name'];

            if (move_uploaded_file($this->_files[$fieldName]['tmp_name'], $fileName) || rename($this->_files[$fieldName]['tmp_name'], $fileName)) {
                if (isset($this->_config[$this->_table->getAlias()][$fieldName]['thumbs'])) {
                    $this->prepareThumbs($fileName, $this->_config[$this->_table->getAlias()][$fieldName]);
                }
            }
        }
    }

    /**
     * Delete file with created thumbs
     *
     * @param Model $model Reference to model
     * @return boolean True if is success
     */
    public function deleteFile(Model $model)
    {
        // Get field list of model schema
        $modelSchema = $model->schema();

        foreach ($this->settings[$model->alias] as $fieldName => $fieldOptions) {
            // Check is field in model schema
            if (isset($modelSchema[$fieldName])) {
                $dataField = $model->findById($model->id);

                if (is_array($dataField) && !empty($dataField[$model->alias][$fieldName])) {
                    // Pattern for original file with thumbs
                    $filePattern = $this->settings[$model->alias][$fieldName]['path'] . DS . substr($dataField[$model->alias][$fieldName], 0, 14);

                    foreach (glob($filePattern . '*') as $fileName) {
                        // Remove file
                        @unlink($fileName);
                    }
                }
            }
        }

        return true;
    }

    /**
     * Generate thumbs by names with parameters
     *
     * @param string $originalFile Path to original file
     * @param array $thumbParams Settings for uploaded files
     * @return boolean Output image to save file
     */
    public function prepareThumbs($originalFile, $settingParams)
    {
        if (is_file($originalFile) && is_array($settingParams)) {
            // Get extension from original file
            $fileExtension = $this->getExtension($originalFile);

            // Get image resource
            switch ($fileExtension) {
                case 'jpg':
                    ini_set('gd.jpeg_ignore_warning', 1);

                    $sourceImage = imagecreatefromjpeg($originalFile);

                    break;
                case 'gif':
                    $sourceImage = imagecreatefromgif($originalFile);

                    break;
                case 'png':
                    $sourceImage = imagecreatefrompng($originalFile);

                    break;
                case 'webp':
                    $sourceImage = imagecreatefromwebp($originalFile);

                    break;
                default:
                    $sourceImage = null;

                    break;
            }

            // Check for image resource type
            if (is_resource($sourceImage) && get_resource_type($sourceImage) === 'gd') {
                // Get original width and height
                $originalWidth = imagesx($sourceImage);
                $originalHeight = imagesy($sourceImage);

                $offsetX = 0;
                $offsetY = 0;

                $cropX = 0;
                $cropY = 0;

                foreach ($settingParams['thumbs'] as $thumbName => $thumbParam) {
                    if (is_array($thumbParam)) {
                        if (isset($thumbParam['width']) && is_array($thumbParam['width']) && count($thumbParam['width']) === 1) {
                            list($newWidth, $newHeight) = $this->_byWidth($originalWidth, $originalHeight, $thumbParam['width'][0]);
                        } elseif (isset($thumbParam['height']) && is_array($thumbParam['height']) && count($thumbParam['height']) === 1) {
                            list($newWidth, $newHeight) = $this->_byHeight($originalWidth, $originalHeight, $thumbParam['height'][0]);
                        } elseif (isset($thumbParam['shorter']) && is_array($thumbParam['shorter']) && count($thumbParam['shorter']) === 2) {
                            list($newWidth, $newHeight) = $this->_byShorter($originalWidth, $originalHeight, $thumbParam['shorter'][0], $thumbParam['shorter'][1]);
                        } elseif (isset($thumbParam['longer']) && is_array($thumbParam['longer']) && count($thumbParam['longer']) === 2) {
                            list($newWidth, $newHeight) = $this->_byLonger($originalWidth, $originalHeight, $thumbParam['longer'][0], $thumbParam['longer'][1]);
                        } elseif (isset($thumbParam['fit']) && is_array($thumbParam['fit']) && count($thumbParam['fit']) === 2) {
                            list($newWidth, $newHeight, $offsetX, $offsetY, $cropX, $cropY) = $this->_byFit($originalWidth, $originalHeight, $thumbParam['fit'][0], $thumbParam['fit'][1]);
                        } elseif (isset($thumbParam['fit']) && is_array($thumbParam['fit']) && count($thumbParam['fit']) === 3) {
                            list($newWidth, $newHeight, $offsetX, $offsetY, $cropX, $cropY) = $this->_byFit($originalWidth, $originalHeight, $thumbParam['fit'][0], $thumbParam['fit'][1], $thumbParam['fit'][2]);
                        } elseif (isset($thumbParam['square']) && is_array($thumbParam['square']) && count($thumbParam['square']) === 1) {
                            list($newWidth, $newHeight, $offsetX, $offsetY, $cropX, $cropY) = $this->_bySquare($originalWidth, $originalHeight, $thumbParam['square'][0]);
                        } elseif (isset($thumbParam['square']) && is_array($thumbParam['square']) && count($thumbParam['square']) === 2) {
                            list($newWidth, $newHeight, $offsetX, $offsetY, $cropX, $cropY) = $this->_bySquare($originalWidth, $originalHeight, $thumbParam['square'][0], $thumbParam['square'][1]);
                        } else {
                            $newWidth = $originalWidth;
                            $newHeight = $originalHeight;
                        }

                        $newImage = imagecreatetruecolor($newWidth, $newHeight);

                        if (is_array($settingParams['background'])) {
                            // Set background color and transparent indicates
                            imagefill($newImage, 0, 0, imagecolorallocatealpha($newImage, $settingParams['background'][0], $settingParams['background'][1], $settingParams['background'][2], $settingParams['background'][3]));
                        }

                        imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

                        if (isset($thumbParam['square']) && is_array($thumbParam['square']) || isset($thumbParam['fit']) && is_array($thumbParam['fit'])) {
                            $fitImage = imagecreatetruecolor($newWidth + (2 * $offsetX) - (2 * $cropX), $newHeight + (2 * $offsetY) - (2 * $cropY));

                            if (is_array($settingParams['background'])) {
                                // Set background color and transparent indicates
                                imagefill($fitImage, 0, 0, imagecolorallocatealpha($fitImage, $settingParams['background'][0], $settingParams['background'][1], $settingParams['background'][2], $settingParams['background'][3]));
                            }

                            imagecopyresampled($fitImage, $newImage, $offsetX, $offsetY, $cropX, $cropY, $newWidth, $newHeight, $newWidth, $newHeight);

                            $newImage = $fitImage;
                        }

                        imagealphablending($newImage, false);
                        imagesavealpha($newImage, true);

                        if (isset($thumbParam['watermark']) && file_exists($settingParams['watermark'])) {
                            $watermarkImage = imagecreatefrompng($settingParams['watermark']);

                            $watermarkPositions = $this->getPosition(imagesx($newImage), imagesy($newImage), imagesx($watermarkImage), imagesy($watermarkImage), $offsetX, $offsetY, $thumbParam['watermark']);

                            // Set transparent
                            imagealphablending($newImage, true);
                            imagecopy($newImage, $watermarkImage, $watermarkPositions[0], $watermarkPositions[1], 0, 0, imagesx($watermarkImage), imagesy($watermarkImage));
                        }

                        $thumbFile = str_replace('default', $thumbName, $originalFile);

                        // Get image resource
                        switch ($fileExtension) {
                            case 'gif':
                                imagegif($newImage, $thumbFile);

                                break;
                            case 'png':
                                imagepng($newImage, $thumbFile);

                                break;
                            case 'webp':
                                imagewebp($newImage, $thumbFile);

                                break;
                            default:
                                imagejpeg($newImage, $thumbFile, 100);

                                break;
                        }
                    }
                }
            }
        }
    }

    /**
     * Get extension from original name
     *
     * @param string $originalName Name of original file
     * @return string Extension of uploaded file
     */
    public function getExtension($originalName)
    {
        $fileName = strtolower($originalName);
        $fileParts = explode('.', $fileName);
        $fileExtension = end($fileParts);

        switch ($fileExtension) {
            case 'jpg':
            case 'jpeg':
            case 'pjpg':
            case 'pjpeg':
                // Standarize JPEG image file extension
                return 'jpg';

                break;
            default:
                return $fileExtension;

                break;
        }
    }

    /**
     * Get position of watermark image
     *
     * @param integer $newWidth New width of uploaded image
     * @param integer $newHeight New height of uploaded image
     * @param integer $watermarkWidth Original width of watermark image
     * @param integer $watermarkHeight Original height of watermark image
     * @param integer $offsetX Horizontal offset
     * @param integer $offsetY Vertical offset
     * @param integer $positionValue Value for position watermark, value between 1 and 9
     * @return array Coordinates of position watermark
     */
    public function getPosition($newWidth, $newHeight, $watermarkWidth, $watermarkHeight, $offsetX = 0, $offsetY = 0, $positionValue = 1)
    {
        switch (intval($positionValue)) {
            case 1: // Top left
                return [$offsetX, $offsetY];

                break;
            case 2: // Top center
                return [($newWidth / 2) - ($watermarkWidth / 2), 0 + $offsetY];

                break;
            case 3: // Top right
                return [($newWidth - $watermarkWidth) - $offsetX, 0 + $offsetY];

                break;
            case 4: // Middle left
                return [$offsetX, ($newHeight / 2) - ($watermarkHeight / 2)];

                break;
            case 5: // Middle center
                return [($newWidth / 2) - ($watermarkWidth / 2), ($newHeight / 2) - ($watermarkHeight / 2)];

                break;
            case 6: // Middle right
                return [($newWidth - $watermarkWidth) - $offsetX, ($newHeight / 2) - ($watermarkHeight / 2)];

                break;
            case 7: // Bottom left
                return [$offsetX, ($newHeight - $watermarkHeight) - $offsetY];

                break;
            case 8: // Bottom center
                return [($newWidth / 2) - ($watermarkWidth / 2), ($newHeight - $watermarkHeight) - $offsetY];

                break;
            case 9: // Bottom right
                return [($newWidth - $watermarkWidth) - $offsetX, ($newHeight - $watermarkHeight) - $offsetY];

                break;
            default:
                return [$offsetX, $offsetY];

                break;
        }
    }

    /**
     * Generate random name of uploaded file.
     * If action is for update with not used file then it will be removed.
     *
     * @todo Prepare method for working without primary key field
     * @todo Generate names of files by user method
     * @param array $data File data
     * @param string $fieldName Name of file field name
     * @return string New name of file
     */
    protected function _prepareName($data, $fieldName)
    {
        /*if (isset($entity->id)) {
         $dataField = $entity->findById($model->id);

         if (is_array($dataField) && !empty($dataField) && is_file($this->_files[$fieldName]['path'] . DS . $dataField[$model->alias][$fieldName])) {
         $filePattern = $this->settings[$model->alias][$fieldName]['path'] . DS . substr($dataField[$model->alias][$fieldName], 0, 14);

         foreach (glob($filePattern . '*') as $fileName) {
         // Remove file
         @unlink($fileName);
         }
         }
         }*/

        $name = substr(Text::uuid(), -27, 14) . '_default.' . $this->getExtension($this->_files[$fieldName]['name']);

        return $name;
    }

    /**
     * Set path to directory for save uploaded files.
     * If directory isn't exists, will be created with full privileges.
     *
     * @param string $dirPath Path to directory
     * @return string Path to directory
     */
    protected function _prepareDir($dirPath)
    {
        $dirPath = WWW_ROOT . str_replace('/', DS, $dirPath);

        if (!is_dir($dirPath) && mb_strlen($dirPath) > 0) {
            mkdir($dirPath, 0777, true);
        }

        chmod($dirPath, 0777);

        return $dirPath;
    }

    /**
     * Create image dimension by new width.
     *
     * @param integer $originalWidth Original width of uploaded image
     * @param integer $originalHeight Original height of uploaded image
     * @param integer $newWidth Set new image width
     * @return array New width and height
     */
    protected function _byWidth($originalWidth, $originalHeight, $newWidth)
    {
        $newWidth = intval($newWidth);

        if ($newWidth > $originalWidth) {
            $newWidth = $originalWidth;
            $newHeight = $originalHeight;
        } else {
            $newHeight = intval($newWidth * ($originalHeight / $originalWidth));
        }

        return [$newWidth, $newHeight];
    }

    /**
     * Create image dimension by new height.
     *
     * @param integer $originalWidth Original width of uploaded image
     * @param integer $originalHeight Original height of uploaded image
     * @param integer $newHeight Set new image height
     * @return array New width and height
     */
    protected function _byHeight($originalWidth, $originalHeight, $newHeight)
    {
        $newHeight = intval($newHeight);

        if ($newHeight > $originalHeight) {
            $newHeight = $originalHeight;
            $newWidth = $originalWidth;
        } else {
            $newWidth = intval($newHeight * ($originalWidth / $originalHeight));
        }

        return [$newWidth, $newHeight];
    }

    /**
     * Create image dimension by shorter side.
     *
     * @param integer $originalWidth Original width of uploaded image
     * @param integer $originalHeight Original height of uploaded image
     * @param integer $newWidth Set new image min width
     * @param integer $newHeight Set new image min height
     * @return array New width and height
     */
    protected function _byShorter($originalWidth, $originalHeight, $newWidth, $newHeight)
    {
        $newWidth = intval($newWidth);
        $newHeight = intval($newHeight);

        if ($originalWidth < $originalHeight) {
            list($newWidth, $newHeight) = $this->_byWidth($originalWidth, $originalHeight, $newWidth);
        } else {
            list($newWidth, $newHeight) = $this->_byHeight($originalWidth, $originalHeight, $newHeight);
        }

        return [$newWidth, $newHeight];
    }

    /**
     * Create image dimension by longer side.
     *
     * @param integer $originalWidth Original width of uploaded image
     * @param integer $originalHeight Original height of uploaded image
     * @param integer $newWidth Set new image max width
     * @param integer $newHeight Set new image max height
     * @return array New width and height
     */
    protected function _byLonger($originalWidth, $originalHeight, $newWidth, $newHeight)
    {
        $newWidth = intval($newWidth);
        $newHeight = intval($newHeight);

        if ($originalWidth > $originalHeight) {
            list($newWidth, $newHeight) = $this->_byWidth($originalWidth, $originalHeight, $newWidth);
        } else {
            list($newWidth, $newHeight) = $this->_byHeight($originalWidth, $originalHeight, $newHeight);
        }

        return [$newWidth, $newHeight];
    }

    /**
     * Create image dimension by fit.
     *
     * @param integer $originalWidth Original width of uploaded image
     * @param integer $originalHeight Original height of uploaded image
     * @param integer $newWidth Set new image width
     * @param integer $newHeight Set new image height
     * @param boolean $originalKeep Save original shape
     * @return array New width and height and offsets of position with keeping original shape
     */
    protected function _byFit($originalWidth, $originalHeight, $newWidth, $newHeight, $originalKeep = false)
    {
        $newWidth = intval($newWidth);
        $newHeight = intval($newHeight);

        $offsetX = 0;
        $offsetY = 0;
        $cropX = 0;
        $cropY = 0;

        if ($originalKeep === true) {
            if ($originalWidth == $originalHeight) {
                $newSizes = $this->_byLonger($originalWidth, $originalHeight, min($newWidth, $newHeight), min($newWidth, $newHeight));
            } else {
                $newSizes = $this->_byLonger($originalWidth, $originalHeight, $newWidth, $newHeight);

                if ($newWidth < $newSizes[0] || $newHeight < $newSizes[1]) {
                    $newSizes = $this->_byShorter($originalWidth, $originalHeight, $newWidth, $newHeight);
                }
            }
        } else {
            if ($originalWidth == $originalHeight) {
                $newSizes = $this->_byShorter($originalWidth, $originalHeight, max($newWidth, $newHeight), max($newWidth, $newHeight));
            } else {
                $newSizes = $this->_byShorter($originalWidth, $originalHeight, $newWidth, $newHeight);

                if ($newWidth > $newSizes[0] || $newHeight > $newSizes[1]) {
                    $newSizes = $this->_byLonger($originalWidth, $originalHeight, $newWidth, $newHeight);
                }
            }
        }

        if ($newWidth < $newSizes[0]) {
            $cropX = ($newSizes[0] - $newWidth) / 2;
        } else {
            $offsetX = ($newWidth - $newSizes[0]) / 2;
        }

        if ($newHeight < $newSizes[1]) {
            $cropY = ($newSizes[1] - $newHeight) / 2;
        } else {
            $offsetY = ($newHeight - $newSizes[1]) / 2;
        }

        return [$newSizes[0], $newSizes[1], $offsetX, $offsetY, $cropX, $cropY];
    }

    /**
     * Create image dimension to square
     *
     * @param integer $originalWidth Original width of uploaded image
     * @param integer $originalHeight Original height of uploaded image
     * @param integer $newSide Set new image side
     * @param boolean $originalKeep Save original shape
     * @return array New width and height with coordinates of crop or offsets of position
     */
    protected function _bySquare($originalWidth, $originalHeight, $newSide, $originalKeep = false)
    {
        $newSide = intval($newSide);

        $offsetX = 0;
        $offsetY = 0;
        $cropX = 0;
        $cropY = 0;

        if ($originalKeep === true) {
            list($newWidth, $newHeight) = $this->_byLonger($originalWidth, $originalHeight, $newSide, $newSide);

            if ($newSide > $newWidth) {
                $offsetX = ($newSide - $newWidth) / 2;
            }

            if ($newSide > $newHeight) {
                $offsetY = ($newSide - $newHeight) / 2;
            }
        } else {
            list($newWidth, $newHeight) = $this->_byShorter($originalWidth, $originalHeight, $newSide, $newSide);

            if ($newSide < $newWidth) {
                $cropX = ($newWidth - $newSide) / 2;
            } else {
                $offsetX = ($newSide - $newWidth) / 2;
            }

            if ($newSide < $newHeight) {
                $cropY = ($newHeight - $newSide) / 2;
            } else {
                $offsetY = ($newSide - $newHeight) / 2;
            }
        }

        return [$newWidth, $newHeight, $offsetX, $offsetY, $cropX, $cropY];
    }
}
