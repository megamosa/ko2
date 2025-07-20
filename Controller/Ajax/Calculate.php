<?php
/**
 * MagoArab_EasYorder Ajax Calculate Controller
 *
 * @category    MagoArab
 * @package     MagoArab_EasYorder
 * @author      MagoArab Development Team
 * @copyright   Copyright (c) 2025 MagoArab
 * @license     https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace MagoArab\EasYorder\Controller\Ajax;

use MagoArab\EasYorder\Api\QuickOrderServiceInterface;
use MagoArab\EasYorder\Helper\Data as HelperData;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Psr\Log\LoggerInterface;

/**
 * Class Calculate
 * 
 * Ajax controller for calculating order total
 */
class Calculate implements HttpPostActionInterface
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @var QuickOrderServiceInterface
     */
    private $quickOrderService;

    /**
     * @var HelperData
     */
    private $helperData;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var PriceHelper
     */
    private $priceHelper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        QuickOrderServiceInterface $quickOrderService,
        HelperData $helperData,
        ProductRepositoryInterface $productRepository,
        PriceHelper $priceHelper,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->quickOrderService = $quickOrderService;
        $this->helperData = $helperData;
        $this->productRepository = $productRepository;
        $this->priceHelper = $priceHelper;
        $this->logger = $logger;
    }

    /**
     * Execute action
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            // Check if module is enabled
            if (!$this->helperData->isEnabled()) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Quick order is not enabled.')
                ]);
            }

            $productId = (int)$this->request->getParam('product_id');
            $qty = (int)$this->request->getParam('qty', 1);
            $shippingMethod = trim($this->request->getParam('shipping_method'));
            $countryId = trim($this->request->getParam('country_id'));
            $region = trim($this->request->getParam('region'));
            $postcode = trim($this->request->getParam('postcode'));

            if (!$productId || !$shippingMethod || !$countryId) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Required parameters are missing.')
                ]);
            }

            // Get product
            $product = $this->productRepository->getById($productId);
            $productPrice = (float)$product->getFinalPrice();
            $subtotal = $productPrice * $qty;

            // Calculate shipping cost
            $shippingCost = $this->quickOrderService->calculateShippingCost(
                $productId,
                $shippingMethod,
                $countryId,
                $region ?: null,
                $postcode ?: null,
                $qty
            );

            $total = $subtotal + $shippingCost;

            return $result->setData([
                'success' => true,
                'calculation' => [
                    'product_price' => $productPrice,
                    'qty' => $qty,
                    'subtotal' => $subtotal,
                    'shipping_cost' => $shippingCost,
                    'total' => $total,
                    'formatted' => [
                        'product_price' => $this->priceHelper->currency($productPrice, true, false),
                        'subtotal' => $this->priceHelper->currency($subtotal, true, false),
                        'shipping_cost' => $this->priceHelper->currency($shippingCost, true, false),
                        'total' => $this->priceHelper->currency($total, true, false)
                    ]
                ]
            ]);

        } catch (LocalizedException $e) {
            $this->logger->error('Error calculating total: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error calculating total: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'message' => __('Unable to calculate total.')
            ]);
        }
    }
}