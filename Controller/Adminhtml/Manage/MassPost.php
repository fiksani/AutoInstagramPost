<?php

namespace GhoSter\AutoInstagramPost\Controller\Adminhtml\Manage;

use Magento\Backend\App\Action;
use Magento\Framework\Exception\LocalizedException;
use GhoSter\AutoInstagramPost\Model\Instagram;
use GhoSter\AutoInstagramPost\Model\Item as InstagramItem;
use Magento\Catalog\Model\ProductFactory;
use GhoSter\AutoInstagramPost\Model\ImageProcessor;
use Magento\Framework\Exception\NotFoundException;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use GhoSter\AutoInstagramPost\Model\Logger;


class MassPost extends Action
{
    /**
     * @var \GhoSter\AutoInstagramPost\Helper\Data
     */
    protected $helper;

    /**
     * @var Logger
     */
    protected $_logger;


    /**
     * @var array
     */
    protected $_account;

    /**
     * @var Instagram
     */
    protected $_instagram;

    /**
     * @var \Magento\Framework\App\Filesystem\DirectoryList
     */
    protected $_directoryList;

    /**
     * Store manager
     *
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var ProductFactory
     */
    protected $productFactory;

    /**
     * @var ImageProcessor
     */
    protected $imageProcessor;

    /**
     * MassActions filter
     *
     * @var Filter
     */
    protected $filter;

    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;


    public function __construct(
        Action\Context $context,
        \GhoSter\AutoInstagramPost\Helper\Data $helper,
        ProductFactory $productFactory,
        Instagram $instagram,
        ImageProcessor $imageProcessor,
        Filter $filter,
        CollectionFactory $collectionFactory,
        Logger $logger
    )
    {
        $this->helper = $helper;
        $this->productFactory = $productFactory;
        $this->_instagram = $instagram;
        $this->imageProcessor = $imageProcessor;
        $this->_account = $this->helper->getAccountInformation();
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->_logger = $logger;
        parent::__construct($context);
    }

    public function execute()
    {
        if (!$this->getRequest()->isPost()) {
            throw new NotFoundException(__('Page not found'));
        }

        /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        $productIds = $this->getRequest()->getParam('selected');

        if (!empty($productIds)) {
            $storeId = (int)$this->getRequest()->getParam('store', 0);

            $productCollection = $this->collectionFactory->create()
                ->addAttributeToSelect('*')
                ->addAttributeToFilter('entity_id', ['in' => $productIds]);
            try {

                if (!empty($this->_account)
                    && isset($this->_account['username'])
                    && isset($this->_account['password'])) {
                    $this->_getInstagram()->setUser(
                        $this->_account['username'],
                        $this->_account['password']
                    );
                }


                if (!$this->_getInstagram()->login()) {
                    $this->messageManager->addErrorMessage(__('Unauthorized Instagram Account, check your user/password setting'));
                }

                $productUploaded = $errorNumber = 0;

                foreach ($productCollection as $product) {

                    $image = $this->imageProcessor->processBaseImage($product);

                    if ($image) {
                        $caption = $this->helper->getInstagramPostDescription($product);
                        $result = $this->_getInstagram()->uploadPhoto($image, $caption);

                        if (empty($result)) {
                            $errorNumber++;
                            $this->_logger->recordInstagramLog(
                                $product,
                                [],
                                InstagramItem::TYPE_ERROR
                            );
                        }

                        if (isset($result['status'])
                            && $result['status'] === Instagram::STATUS_OK
                        ) {
                            $productUploaded++;
                            $this->_logger->recordInstagramLog(
                                $product,
                                $result,
                                InstagramItem::TYPE_SUCCESS
                            );
                        }
                    }
                }

                if ($errorNumber) {
                    $this->messageManager->addComplexErrorMessage(
                        'InstagramError',
                        [
                            'instagram_link' => 'https://help.instagram.com/1631821640426723'
                        ]
                    );
                }

                if ($productUploaded) {
                    $this->messageManager->addSuccessMessage(
                        __('A total of %1 images(s) have been uploaded to Instagram.', $productUploaded)
                    );
                }

                return $resultRedirect->setPath('*/*/');

            } catch (LocalizedException $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            } catch (\Exception $e) {
                $this->messageManager->addExceptionMessage($e, __('Something went wrong while posting to Instagram.'));
            }

            return $resultRedirect->setPath('*/*/');
        }

        return $resultRedirect->setPath('*/*/');
    }

    /**
     * Get Instagram Client
     *
     * @return \GhoSter\AutoInstagramPost\Model\Instagram
     */
    private function _getInstagram()
    {
        return $this->_instagram;
    }


    /**
     * Is the user allowed to view the blog post grid.
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('GhoSter_AutoInstagramPost::auto_instagram_post');
    }
}