<?php
namespace Swissup\Easycatalogimg\Block;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use \Magento\Framework\UrlInterface;
use \Magento\Framework\App\Filesystem\DirectoryList;
use \Swissup\Easycatalogimg\Model\Config\Backend\Image\Placeholder;

class SubcategoriesList extends \Magento\Framework\View\Element\Template implements
    \Magento\Framework\DataObject\IdentityInterface,
    \Magento\Widget\Block\BlockInterface
{
    const MODE_GRID = 'grid';

    const MODE_MASONRY = 'masonry';

    /**
     * @var \Swissup\Easycatalogimg\Helper\Config
     */
    protected $configHelper;

    /**
     * @var \Swissup\Easycatalogimg\Helper\Image
     */
    protected $imageHelper;

    /**
     * @var \Magento\Framework\Registry
     */
    protected $coreRegistry = null;

    /**
     * @var CategoryRepositoryInterface
     */
    protected $categoryRepository;

    /**
     * @var \Magento\Catalog\Model\Layer
     */
    protected $catalogLayer;

    /**
     * @var \Magento\Framework\File\Mime
     */
    private $mime;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Swissup\Easycatalogimg\Helper\Config $configHelper
     * @param \Magento\Framework\Registry $registry
     * @param CategoryRepositoryInterface $categoryRepository
     * @param \Swissup\Easycatalogimg\Helper\Image $imageHelper
     * @param \Magento\Catalog\Model\Layer\Resolver $layerResolver
     * @param \Magento\Framework\File\Mime $mime
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Swissup\Easycatalogimg\Helper\Config $configHelper,
        \Magento\Framework\Registry $registry,
        CategoryRepositoryInterface $categoryRepository,
        \Swissup\Easycatalogimg\Helper\Image $imageHelper,
        \Magento\Catalog\Model\Layer\Resolver $layerResolver,
        \Magento\Framework\File\Mime $mime,
        array $data = []
    ) {
        $this->configHelper = $configHelper;
        $this->coreRegistry = $registry;
        $this->categoryRepository = $categoryRepository;
        $this->imageHelper = $imageHelper;
        $this->catalogLayer = $layerResolver->get();
        $this->mime = $mime;

        parent::__construct($context, $data);
    }

    public function _construct()
    {
        if ($configPath = $this->getImportConfigFrom()) {
            $config = $this->configHelper->getBlockConfig($configPath);
            foreach ($config as $key => $value) {
                 $this->setData($key, $value);
            }
        }
        return parent::_construct();
    }

    /**
     * Return identifiers for produced content
     *
     * @return array
     */
    public function getIdentities()
    {
        return ['easycatalogimg_subcategories_list'];
    }

    /**
    * Opimized method, to get all categories to show
    *
    * @return array
    * <pre>
    * [
    *  \Magento\Catalog\Model\Category => {
    *      children => [
    *          \Magento\Catalog\Model\Category => {...}
    *      ]
    *  }
    *  \Magento\Catalog\Model\Category => {
    *      children => []
    *  }
    *  ...
    * ]
    * </pre>
    */
    public function getCategories()
    {
        $storeId = $this->getCurrentStore()->getId();
        if ($category = $this->getCurrentCategory()) {
            // fix for categories from another store
            if ($this->getCategoryId()) {
                $storeId = $this->detectStoreId($category);
            }

            $currentLevel = $category->getLevel();
        } else {
            $category = $this->categoryRepository->get($this->getCurrentStore()->getRootCategoryId());
            $currentLevel = 1;
        }

        $collection = $category->getCollection();
        if ($category->getId()) {
            $collection->addPathsFilter($category->getPath() . '/');
        }

        $collection
            ->addAttributeToSelect('name')
            ->addAttributeToSelect('image')
            ->addAttributeToSelect('thumbnail')
            ->addAttributeToSelect('is_anchor')
            ->addAttributeToFilter('is_active', 1)
            ->addUrlRewriteToResult()
            ->addFieldToFilter('level', ['lteq' => $currentLevel + 2])
            ->addFieldToFilter('level', ['gt'   => $currentLevel])
            ->setOrder('level', \Magento\Framework\Data\Collection::SORT_ORDER_ASC)
            ->setOrder('position', \Magento\Framework\Data\Collection::SORT_ORDER_ASC)
            ->load();

        // the next loops is working for two levels only
        if ($categoriesToShow = $this->getCategoryToShow()) {
            $categoriesToShow = explode(',', $categoriesToShow);
        } else {
            $categoriesToShow = [];
        }

        if ($categoriesToHide = $this->getCategoryToHide()) {
            $categoriesToHide = explode(',', $categoriesToHide);
        } else {
            $categoriesToHide = [];
        }

        $result        = [];
        $subcategories = [];
        foreach ($collection as $category) {
            if (in_array($category->getId(), $categoriesToHide)) {
                continue;
            }

            if ($categoriesToShow
                && !in_array($category->getId(), $categoriesToShow)
                && !in_array($category->getParentId(), $categoriesToShow)) {
                continue;
            }

            $category->setStoreId($storeId);
            if ($category->getLevel() == ($currentLevel + 1)) {
                $result[$category->getId()] = $category;
            } else {
                $subcategories[$category->getParentId()][] = $category;
            }
        }

        foreach ($subcategories as $parentId => $_subcategories) {
            if (!isset($result[$parentId])) { // inactive parent category
                continue;
            }

            $parent = $result[$parentId];
            $parent->setSubcategories($_subcategories);
        }

        return $result;
    }

    /**
     * Detect store_id to use, in case of rendering categories on the site with
     * different root category id
     *
     * @param  \Magento\Catalog\Model\Category $category - Category from Widget parameters
     * @return int
     */
    public function detectStoreId($category)
    {
        $store = $this->getCurrentStore();
        $path = explode('/', $category->getPath());
        $rootCategoryId = $path[1];

        if ($store->getRootCategoryId() == $rootCategoryId) {
            return $store->getId();
        }

        $storeIdToWebsiteId = [];
        foreach ($this->_storeManager->getGroups() as $group) {
            if ($group->getRootCategoryId() != $rootCategoryId) {
                continue;
            }

            $storeIdToWebsiteId[$group->getDefaultStoreId()] = $group->getWebsiteId();
        }

        foreach ($storeIdToWebsiteId as $storeId => $websiteId) {
            if ($store->getWebsiteId() == $websiteId) {
                return $storeId;
            }
        }

        return key($storeIdToWebsiteId);
    }

    /**
     * Retrieve current category model object
     *
     * @return \Magento\Catalog\Model\Category
     */
    public function getCurrentCategory()
    {
        if ($categoryId = $this->getCategoryId()) {
            return $this->categoryRepository->get($categoryId);
        }

        if (!$this->hasData('current_category')) {
            $this->setData('current_category', $this->coreRegistry->registry('current_category'));
        }

        return $this->getData('current_category');
    }

    /**
     * @return int
     */
    public function getCategoryId()
    {
        $id = $this->_getData('category_id');
        if (null !== $id && strstr($id, 'category/')) { // category id from widget
            $id = str_replace('category/', '', $id);
        }
        return $id;
    }

    /**
     * Retrieve current store model
     *
     * @return \Magento\Store\Model\Store
     */
    public function getCurrentStore()
    {
        return $this->_storeManager->getStore();
    }

    /**
     * @return \Swissup\Easycatalogimg\Helper\Image
     */
    public function getImageHelper()
    {
        if ($background = $this->getBackgroundColor()) {
            $this->imageHelper->setBackgroundColor($background);
        }
        return $this->imageHelper;
    }

    /**
     * @param \Magento\Catalog\Model\Category $category
     * @param integer $width
     * @param integer $height
     * @return string
     */
    public function getImageSrc($category, $width, $height)
    {
        $image = $category->getThumbnail();
        $folder = $this->imageHelper->getBaseDir();
        $baseUrl = $this->imageHelper->getBaseUrl();

        if (!$image && $this->getUseImageAttribute()) {
            $image = $category->getImage();
        }

        if (!$image) {
            $image = $this->configHelper->getPlaceholderImage();
            $folder = $this->imageHelper->getBaseDir(Placeholder::UPLOAD_DIR . '/');
            $baseUrl = $this->imageHelper->getBaseUrl(Placeholder::UPLOAD_DIR . '/');
        }

        $imagePath = $folder . $image;

        if (!$image || !file_exists($imagePath)) {
            return $this->configHelper->getPlaceholderSvg(true);
        }

        if (!$this->getResizeImage() || $this->isSvg($imagePath)) {
            return $baseUrl . $image;
        }

        return $this->getImageHelper()->resize($imagePath, $width, $height);
    }

    /**
     * @param \Magento\Catalog\Model\Category $category
     * @param integer $width
     * @param integer $height
     * @return string
     */
    public function getImageSrcset($category, $width, $height)
    {
        if (!$width && !$height) {
            return '';
        }

        $x1 = $this->getImageSrc($category, $width, $height);
        $x2 = $this->getImageSrc($category, $width * 2, $height * 2);

        if ($x1 === $x2 ||
            substr($x1, -4) === '.svg' ||
            substr($x1, 0, 18) === 'data:image/svg+xml'
        ) {
            return '';
        }

        return sprintf('%s 1x, %s 2x', $x1, $x2);
    }

    /**
     * Check if image is svg
     * @param  String $filepath path to file
     * @return Boolean
     */
    public function isSvg($filepath)
    {
        return $this->mime->getMimeType($filepath) === 'image/svg+xml';
    }

     /**
     * Get relevant path to template
     * don't show the block:
     * if pagination is used
     * if filter is applied
     *
     * @return string
     */
    public function getTemplate()
    {
        $page = (int) $this->getRequest()->getParam('p', 1);

        if ($this->getHideWhenFilterIsUsed() &&
            ($page > 1 || count($this->catalogLayer->getState()->getFilters()))
        ) {
            return '';
        }

        $category = $this->getCurrentCategory();
        if ($category && $category->getLevel() > 1) {
            $isAnchor = $category->getIsAnchor();
            $enabledForAnchor = $this->getEnabledForAnchor();
            $enabledForDefault = $this->getEnabledForDefault();

            if (($isAnchor && !$enabledForAnchor)
                || (!$isAnchor && !$enabledForDefault)
            ) {
                return '';
            }
        }

        $template = parent::getTemplate();
        if (!$template) {
            $template = $this->_getData('template');
        }

        return $template;
    }

    /**
     * Fix for widget instance
     *
     * @return boolean
     */
    public function getResizeImage()
    {
        return $this->configHelper->useImageResizeHelper();
    }

    /**
     * Get parent category title placement
     *
     * @return string
     */
    public function getParentCategoryPosition()
    {
        if ($this->hasData('parent_category_position')) {
            return $this->getData('parent_category_position');
        }
        return 'top';
    }

    /**
     * Retrieve current listing mode
     *
     * @return string
     */
    public function getMode()
    {
        if ($this->hasData('mode')) {
            return $this->getData('mode');
        }
        return self::MODE_GRID;
    }

    /**
     * Added to support crosssite links (link to category to another store_group)
     *
     * @param  \Magento\Catalog\Model\Category $category
     * @return string
     */
    public function getCategoryUrl($category)
    {
        $url = $category->getUrl();

        $categoryStoreId = $category->getStoreId(); // @see getCategories method
        if ($categoryStoreId != $this->getCurrentStore()->getId()) {
            if (strpos($url, '?') !== false) {
                $prefix = '&';
            } else {
                $prefix = '?';
            }

            $url .= $prefix
                . '___store='
                . $this->_storeManager->getStore($categoryStoreId)->getCode();
        }

        return $url;
    }
}
