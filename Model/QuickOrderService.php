<?php
/**
 * MagoArab_EasYorder Enhanced Quick Order Service
 * Supports third-party extensions and catalog rules
 */

declare(strict_types=1);

namespace MagoArab\EasYorder\Model;

use MagoArab\EasYorder\Api\QuickOrderServiceInterface;
use MagoArab\EasYorder\Api\Data\QuickOrderDataInterface;
use MagoArab\EasYorder\Helper\Data as HelperData;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Quote\Api\ShippingMethodManagementInterface;
use Magento\Payment\Api\PaymentMethodListInterface;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Shipping\Model\Config as ShippingConfig;
use Magento\Directory\Model\RegionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\DataObject;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\CatalogRule\Model\RuleFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;

class QuickOrderService implements QuickOrderServiceInterface
{
    private $productRepository;
    private $quoteFactory;
    private $quoteManagement;
    private $storeManager;
    private $customerFactory;
    private $customerRepository;
    private $orderSender;
    private $helperData;
    private $cartRepository;
    private $cartManagement;
    private $scopeConfig;
    private $logger;
    private $shippingMethodManagement;
    private $paymentMethodList;
    private $paymentConfig;
    private $shippingConfig;
    private $regionFactory;
    private $orderRepository;
    private $priceHelper;
    private $customerSession;
    private $checkoutSession;
    private $ruleFactory;
    private $dateTime;
    private $request;

    /**
     * Property to store current order attributes
     */
    private $currentOrderAttributes = null;

	public function __construct(
			ProductRepositoryInterface $productRepository,
			QuoteFactory $quoteFactory,
			QuoteManagement $quoteManagement,
			StoreManagerInterface $storeManager,
			CustomerFactory $customerFactory,
			CustomerRepositoryInterface $customerRepository,
			OrderSender $orderSender,
			HelperData $helperData,
			CartRepositoryInterface $cartRepository,
			CartManagementInterface $cartManagement,
			ScopeConfigInterface $scopeConfig,
			LoggerInterface $logger,
			ShippingMethodManagementInterface $shippingMethodManagement,
			PaymentMethodListInterface $paymentMethodList,
			PaymentConfig $paymentConfig,
			ShippingConfig $shippingConfig,
			RegionFactory $regionFactory,
			OrderRepositoryInterface $orderRepository,
			PriceHelper $priceHelper,
			CustomerSession $customerSession,
			CheckoutSession $checkoutSession,
			RuleFactory $ruleFactory,
			DateTime $dateTime,
			RequestInterface $request
    ) {
        $this->productRepository = $productRepository;
        $this->quoteFactory = $quoteFactory;
        $this->quoteManagement = $quoteManagement;
        $this->storeManager = $storeManager;
        $this->customerFactory = $customerFactory;
        $this->customerRepository = $customerRepository;
        $this->orderSender = $orderSender;
        $this->helperData = $helperData;
        $this->cartRepository = $cartRepository;
        $this->cartManagement = $cartManagement;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->shippingMethodManagement = $shippingMethodManagement;
        $this->paymentMethodList = $paymentMethodList;
        $this->paymentConfig = $paymentConfig;
        $this->shippingConfig = $shippingConfig;
        $this->regionFactory = $regionFactory;
        $this->orderRepository = $orderRepository;
        $this->priceHelper = $priceHelper;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->ruleFactory = $ruleFactory;
        $this->dateTime = $dateTime;
		$this->request = $request;
    }

    public function getAvailableShippingMethods(int $productId, string $countryId, ?string $region = null, ?string $postcode = null): array
    {
        $requestId = uniqid('service_', true);
        
        try {
            $this->logger->info('=== Enhanced QuickOrderService: Starting shipping calculation ===', [
                'request_id' => $requestId,
                'product_id' => $productId,
                'country_id' => $countryId,
                'region' => $region,
                'postcode' => $postcode
            ]);
            
            // Step 1: Create realistic quote like normal checkout
            $quote = $this->createRealisticQuoteWithProduct($productId);
            
            // Step 2: Set shipping address with proper session context
            $shippingAddress = $this->setRealisticShippingAddress($quote, $countryId, $region, $postcode);
            
            // Step 3: Use OFFICIAL Magento Shipping Method Management API
            $shippingMethods = $this->collectShippingMethodsUsingOfficialAPI($quote, $requestId);
            
            // Step 4: Apply admin filtering (keeps third-party compatibility)
            $filteredMethods = $this->helperData->filterShippingMethods($shippingMethods);
            
            $this->logger->info('=== Enhanced QuickOrderService: Shipping calculation completed ===', [
                'request_id' => $requestId,
                'original_methods_count' => count($shippingMethods),
                'filtered_methods_count' => count($filteredMethods),
                'final_methods' => array_column($filteredMethods, 'code')
            ]);
            
            return $filteredMethods;
            
        } catch (\Exception $e) {
            $this->logger->error('=== Enhanced QuickOrderService: Error in shipping calculation ===', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [];
        }
    }
    
    /**
     * Create realistic quote that mimics normal checkout behavior
     */
    private function createRealisticQuoteWithProduct(int $productId)
{
    try {
        $product = $this->productRepository->getById($productId);
        $store = $this->storeManager->getStore();
        
        // Create quote exactly like checkout
        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setStoreId($store->getId());
        $quote->setCurrency();
        
        // Set customer context for catalog rules
        $quote->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
        $quote->setCustomerIsGuest(true);
        
        // Handle product variants properly
// Handle product variants properly with attributes
        if ($product->getTypeId() === 'configurable') {
            // Get selected attributes from request (will be passed from frontend)
            $selectedAttributes = $this->getSelectedProductAttributes();
            
            if ($selectedAttributes && !empty($selectedAttributes)) {
                // Find the specific variant based on selected attributes
                $simpleProduct = $product->getTypeInstance()->getProductByAttributes($selectedAttributes, $product);
                
                if ($simpleProduct) {
                    // Create proper request with super_attribute data
                    $request = new DataObject([
                        'qty' => 1,
                        'product' => $product->getId(), // Keep parent product ID
                        'super_attribute' => $selectedAttributes,
                        'selected_configurable_option' => $simpleProduct->getId()
                    ]);
                    
                    // Add the configurable product with selected options
                    $quote->addProduct($product, $request);
                    
                    $this->logger->info('Added configurable product with selected attributes', [
                        'parent_id' => $productId,
                        'simple_id' => $simpleProduct->getId(),
                        'simple_sku' => $simpleProduct->getSku(),
                        'selected_attributes' => $selectedAttributes,
                        'final_price' => $simpleProduct->getFinalPrice()
                    ]);
                } else {
                    throw new LocalizedException(__('Selected product configuration is not available'));
                }
            } else {
                // Fallback: use first available simple product for shipping calculation only
                $simpleProduct = $this->getFirstAvailableSimpleProduct($product);
                if ($simpleProduct) {
                    $request = new DataObject([
                        'qty' => 1,
                        'product' => $simpleProduct->getId()
                    ]);
                    $quote->addProduct($simpleProduct, $request);
                    
                    $this->logger->info('Added fallback simple variant (no attributes selected)', [
                        'configurable_id' => $productId,
                        'simple_id' => $simpleProduct->getId(),
                        'simple_sku' => $simpleProduct->getSku()
                    ]);
                } else {
                    throw new LocalizedException(__('No available product variants found'));
                }
            }
        } else {
            // Simple product - use proper request
            $request = new DataObject([
                'qty' => 1,
                'product' => $product->getId()
            ]);
            $quote->addProduct($product, $request);
            
            $this->logger->info('Added simple product to quote', [
                'product_id' => $productId,
                'product_sku' => $product->getSku(),
                'product_price' => $product->getFinalPrice()
            ]);
        }
        
        // IMPORTANT: Force totals calculation multiple times
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        
        // Save quote first
        $this->cartRepository->save($quote);
        
        // Reload and recalculate
        $quote = $this->cartRepository->get($quote->getId());
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        
        // Final save
        $this->cartRepository->save($quote);
        
        $this->logger->info('Realistic quote created successfully - FIXED', [
            'quote_id' => $quote->getId(),
            'items_count' => count($quote->getAllItems()),
            'subtotal' => $quote->getSubtotal(),
            'grand_total' => $quote->getGrandTotal(),
            'items' => array_map(function($item) {
                return [
                    'sku' => $item->getSku(),
                    'qty' => $item->getQty(),
                    'price' => $item->getPrice(),
                    'row_total' => $item->getRowTotal(),
                    'row_total_incl_tax' => $item->getRowTotalInclTax(),
                    'discount_amount' => $item->getDiscountAmount()
                ];
            }, $quote->getAllItems())
        ]);
        
        // Validate that totals are calculated
        if ($quote->getSubtotal() <= 0) {
            // Force manual calculation
            foreach ($quote->getAllItems() as $item) {
                $item->calcRowTotal();
            }
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
            $this->cartRepository->save($quote);
            
            $this->logger->warning('Manual totals calculation performed', [
                'quote_id' => $quote->getId(),
                'new_subtotal' => $quote->getSubtotal(),
                'new_grand_total' => $quote->getGrandTotal()
            ]);
        }
        
        return $quote;
        
    } catch (\Exception $e) {
        $this->logger->error('Failed to create realistic quote', [
            'product_id' => $productId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        throw new LocalizedException(__('Unable to create quote: %1', $e->getMessage()));
    }
}
/**
 * Get selected product attributes from current request/session
 * This will be called during order creation
 */
private function getSelectedProductAttributes(): ?array
{
    try {
        // Check if we're in order creation context and have stored attributes
        if (isset($this->currentOrderAttributes)) {
            return $this->currentOrderAttributes;
        }
        
        return null;
        
    } catch (\Exception $e) {
        $this->logger->warning('Could not get selected product attributes: ' . $e->getMessage());
        return null;
    }
}

/**
 * Set selected product attributes for order creation
 */
public function setSelectedProductAttributes(array $attributes): void
{
    $this->currentOrderAttributes = $attributes;
}

    private function setRealisticShippingAddress($quote, string $countryId, ?string $region = null, ?string $postcode = null)
    {
        $shippingAddress = $quote->getShippingAddress();
        
        // Set complete address data
        $shippingAddress->setCountryId($countryId);
        $shippingAddress->setCity($region ? $region : 'Cairo');
        $shippingAddress->setStreet(['123 Main Street', 'Apt 1']);
        $shippingAddress->setFirstname('Guest');
        $shippingAddress->setLastname('Customer');
        $shippingAddress->setTelephone('01234567890');
        $shippingAddress->setEmail('guest@example.com');
        $shippingAddress->setCompany('');
        
        // Set region properly
        if ($region) {
            $regionId = $this->getRegionIdByName($region, $countryId);
            if ($regionId) {
                $shippingAddress->setRegionId($regionId);
                $shippingAddress->setRegion($region);
            } else {
                $shippingAddress->setRegion($region);
            }
        }
        
        // Set postcode
        if ($postcode) {
            $shippingAddress->setPostcode($postcode);
        } else {
            $shippingAddress->setPostcode('11511'); // Default Egyptian postcode
        }
        
        // Save address changes
        $shippingAddress->save();
        
        return $shippingAddress;
    }
    
/**
 * FIXED: Enhanced shipping collection that works with ALL third-party extensions
 */
private function collectShippingMethodsUsingOfficialAPI($quote, string $requestId): array
{
    try {
        $this->logger->info('Enhanced Shipping Collection Started', [
            'request_id' => $requestId,
            'quote_id' => $quote->getId()
        ]);
        
        // STEP 1: Ensure proper customer context
        $quote->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
        $quote->setCustomerIsGuest(true);
        
        // STEP 2: Get shipping address and validate
        $shippingAddress = $quote->getShippingAddress();
        
        if (!$shippingAddress->getCountryId()) {
            throw new \Exception('Shipping address missing country');
        }
        
        // STEP 3: CRITICAL FIX - Force proper address setup for shipping calculation
        $shippingAddress->setCollectShippingRates(true);
        $shippingAddress->removeAllShippingRates();
        
        // Set weight if not set (required for many shipping methods)
        $totalWeight = 0;
        foreach ($quote->getAllItems() as $item) {
            $product = $item->getProduct();
            if ($product && $product->getWeight()) {
                $totalWeight += ($product->getWeight() * $item->getQty());
            }
        }
        
        if ($totalWeight > 0) {
            $shippingAddress->setWeight($totalWeight);
        } else {
            $shippingAddress->setWeight(1); // Default weight for calculation
        }
        
        // STEP 4: Force totals calculation BEFORE shipping collection
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);
        
        // STEP 5: Manual shipping rates collection (more reliable than API)
        $shippingAddress->setCollectShippingRates(true);
        $shippingAddress->collectShippingRates();
        
        // STEP 6: Force another totals collection
        $quote->collectTotals();
        $this->cartRepository->save($quote);
        
        // STEP 7: Get rates from address (most reliable method)
        $shippingRates = $shippingAddress->getAllShippingRates();
        
        $this->logger->info('Shipping rates collected', [
            'request_id' => $requestId,
            'rates_count' => count($shippingRates),
            'quote_subtotal' => $quote->getSubtotal(),
            'quote_weight' => $shippingAddress->getWeight()
        ]);
        
        $methods = [];
 foreach ($shippingRates as $rate) {
    // FIXED: Accept rates even with warnings, but skip null methods
    if ($rate->getMethod() !== null) {
        $methodCode = $rate->getCarrier() . '_' . $rate->getMethod();
        
        if ($rate->getErrorMessage()) {
            $this->logger->info('Shipping rate has warning but will be included', [
                'request_id' => $requestId,
                'carrier' => $rate->getCarrier(),
                'method' => $rate->getMethod(),
                'warning' => $rate->getErrorMessage(),
                'price' => $rate->getPrice()
            ]);
        }
        
        $methods[] = [
            'code' => $methodCode,
            'carrier_code' => $rate->getCarrier(),
            'method_code' => $rate->getMethod(),
            'carrier_title' => $rate->getCarrierTitle(),
            'title' => $rate->getMethodTitle(),
            'price' => (float)$rate->getPrice(),
            'price_formatted' => $this->formatPrice((float)$rate->getPrice())
        ];
        
        $this->logger->info('Valid shipping method found', [
            'request_id' => $requestId,
            'method_code' => $methodCode,
            'price' => $rate->getPrice(),
            'carrier_title' => $rate->getCarrierTitle()
        ]);
    } else {
        $this->logger->warning('Shipping rate has null method - skipped', [
            'request_id' => $requestId,
            'carrier' => $rate->getCarrier(),
            'method' => $rate->getMethod(),
            'error' => $rate->getErrorMessage()
        ]);
    }
}
        
        // STEP 8: If no methods found, try alternative approach
        if (empty($methods)) {
            $this->logger->warning('No shipping rates found, trying alternative collection', [
                'request_id' => $requestId
            ]);
            
            $methods = $this->collectShippingUsingCarrierModels($quote, $requestId);
        }
        
        // STEP 9: Fallback to configured carriers if still empty
        if (empty($methods)) {
            $this->logger->warning('No methods from carriers, using fallback', [
                'request_id' => $requestId
            ]);
            
            $methods = $this->getFallbackShippingMethods();
        }
        
        $this->logger->info('Final shipping methods result', [
            'request_id' => $requestId,
            'methods_count' => count($methods),
            'methods' => array_column($methods, 'code')
        ]);
        
        return $methods;
        
    } catch (\Exception $e) {
        $this->logger->error('Enhanced shipping collection failed', [
            'request_id' => $requestId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        // Ultimate fallback
        return $this->getFallbackShippingMethods();
    }
}

/**
 * FIXED: Enhanced carrier collection that works with all Magento carriers
 */
private function collectShippingUsingCarrierModels($quote, string $requestId): array
{
    $methods = [];
    
    try {
        $this->logger->info('Starting alternative carrier collection', [
            'request_id' => $requestId
        ]);
        
        // Get all carriers from shipping config
        $allCarriers = $this->shippingConfig->getAllCarriers();
        $shippingAddress = $quote->getShippingAddress();
        
        foreach ($allCarriers as $carrierCode => $carrierModel) {
            try {
                // Check if carrier is active
                $isActive = $this->scopeConfig->getValue(
                    'carriers/' . $carrierCode . '/active',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                );
                
                if (!$isActive) {
                    continue;
                }
                
                // Skip freeshipping if it has issues and continue to other carriers
                if ($carrierCode === 'freeshipping') {
                    $this->logger->info('Skipping freeshipping carrier for alternative collection', [
                        'request_id' => $requestId
                    ]);
                    continue;
                }
                
                $this->logger->info('Processing carrier', [
                    'request_id' => $requestId,
                    'carrier' => $carrierCode,
                    'model_class' => get_class($carrierModel)
                ]);
                
                // Create comprehensive shipping rate request
                $request = $this->createShippingRateRequest($quote, $shippingAddress);
                
                // Try to collect rates from carrier
                $result = $carrierModel->collectRates($request);
                
                if ($result && $result->getRates()) {
                    $rates = $result->getRates();
                    
                    $this->logger->info('Carrier returned rates', [
                        'request_id' => $requestId,
                        'carrier' => $carrierCode,
                        'rates_count' => count($rates)
                    ]);
                    
                    foreach ($rates as $rate) {
                        if ($rate->getMethod() !== null) {
                            $methodCode = $rate->getCarrier() . '_' . $rate->getMethod();
                            
                            $methods[] = [
                                'code' => $methodCode,
                                'carrier_code' => $rate->getCarrier(),
                                'method_code' => $rate->getMethod(),
                                'carrier_title' => $rate->getCarrierTitle(),
                                'title' => $rate->getMethodTitle(),
                                'price' => (float)$rate->getPrice(),
                                'price_formatted' => $this->formatPrice((float)$rate->getPrice())
                            ];
                            
                            $this->logger->info('Alternative carrier method collected', [
                                'request_id' => $requestId,
                                'carrier' => $carrierCode,
                                'method' => $methodCode,
                                'price' => $rate->getPrice()
                            ]);
                        }
                    }
                } else {
                    $this->logger->info('Carrier returned no rates', [
                        'request_id' => $requestId,
                        'carrier' => $carrierCode,
                        'result_class' => $result ? get_class($result) : 'null'
                    ]);
                    
                    // For standard carriers, create fallback methods
                    if (in_array($carrierCode, ['flatrate', 'tablerate'])) {
                        $fallbackMethod = $this->createFallbackMethod($carrierCode);
                        if ($fallbackMethod) {
                            $methods[] = $fallbackMethod;
                            
                            $this->logger->info('Created fallback method for carrier', [
                                'request_id' => $requestId,
                                'carrier' => $carrierCode,
                                'method' => $fallbackMethod['code']
                            ]);
                        }
                    }
                }
                
            } catch (\Exception $e) {
                $this->logger->warning('Carrier collection failed', [
                    'request_id' => $requestId,
                    'carrier' => $carrierCode,
                    'error' => $e->getMessage()
                ]);
                
                // Try to create basic method for known carriers
                if (in_array($carrierCode, ['flatrate', 'tablerate'])) {
                    $fallbackMethod = $this->createFallbackMethod($carrierCode);
                    if ($fallbackMethod) {
                        $methods[] = $fallbackMethod;
                    }
                }
                
                // Don't let one carrier failure stop others
                continue;
            }
        }
        
        $this->logger->info('Alternative carrier collection completed', [
            'request_id' => $requestId,
            'total_methods' => count($methods),
            'methods' => array_column($methods, 'code')
        ]);
        
    } catch (\Exception $e) {
        $this->logger->error('Alternative carrier collection failed completely', [
            'request_id' => $requestId,
            'error' => $e->getMessage()
        ]);
    }
    
    return $methods;
}
/**
 * Create comprehensive shipping rate request
 */
private function createShippingRateRequest($quote, $shippingAddress)
{
    // Create proper rate request object
    $request = new \Magento\Framework\DataObject();
    
    // Set destination data
    $request->setDestCountryId($shippingAddress->getCountryId());
    $request->setDestRegionId($shippingAddress->getRegionId());
    $request->setDestRegionCode($shippingAddress->getRegionCode());
    $request->setDestStreet($shippingAddress->getStreet());
    $request->setDestCity($shippingAddress->getCity());
    $request->setDestPostcode($shippingAddress->getPostcode());
    
    // Set package data
    $request->setPackageWeight($shippingAddress->getWeight() ?: 1);
    $request->setPackageValue($quote->getSubtotal());
    $request->setPackageValueWithDiscount($quote->getSubtotalWithDiscount());
    $request->setPackageQty($quote->getItemsQty());
    
    // Set store/website data
    $request->setStoreId($quote->getStoreId());
    $request->setWebsiteId($quote->getStore()->getWebsiteId());
    $request->setBaseCurrency($quote->getBaseCurrencyCode());
    $request->setPackageCurrency($quote->getQuoteCurrencyCode());
    $request->setLimitMethod(null);
    
    // Set origin data
    $request->setOrigCountry($this->scopeConfig->getValue(
        'shipping/origin/country_id',
        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
    ));
    $request->setOrigRegionId($this->scopeConfig->getValue(
        'shipping/origin/region_id',
        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
    ));
    $request->setOrigCity($this->scopeConfig->getValue(
        'shipping/origin/city',
        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
    ));
    $request->setOrigPostcode($this->scopeConfig->getValue(
        'shipping/origin/postcode',
        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
    ));
    
    // Add all items to request
    $items = [];
    foreach ($quote->getAllItems() as $item) {
        if (!$item->getParentItem()) {
            $items[] = new \Magento\Framework\DataObject([
                'qty' => $item->getQty(),
                'weight' => $item->getWeight() ?: 1,
                'product_id' => $item->getProductId(),
                'base_row_total' => $item->getBaseRowTotal(),
                'price' => $item->getPrice(),
                'row_total' => $item->getRowTotal(),
                'product' => $item->getProduct()
            ]);
        }
    }
    $request->setAllItems($items);
    
    return $request;
}

/**
 * Create fallback method for standard carriers
 */
private function createFallbackMethod(string $carrierCode): ?array
{
    $title = $this->scopeConfig->getValue(
        'carriers/' . $carrierCode . '/title',
        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
    );
    
    if (!$title) {
        return null;
    }
    
    $price = 0;
    
    switch ($carrierCode) {
        case 'flatrate':
            $price = (float)$this->scopeConfig->getValue(
                'carriers/flatrate/price',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            ) ?: 25;
            break;
        case 'freeshipping':
            $price = 0;
            break;
        case 'tablerate':
            $price = 25; // Default for tablerate
            break;
    }
    
    return [
        'code' => $carrierCode . '_' . $carrierCode,
        'carrier_code' => $carrierCode,
        'method_code' => $carrierCode,
        'carrier_title' => $title,
        'title' => $title,
        'price' => $price,
        'price_formatted' => $this->formatPrice($price)
    ];
}
/**
 * Force collection of ALL active carriers
 */
private function forceCollectAllActiveCarriers(): array
{
    $methods = [];
    
    // List of standard Magento carriers
    $standardCarriers = [
        'flatrate' => 'Flat Rate',
        'freeshipping' => 'Free Shipping', 
        'tablerate' => 'Table Rate',
        'ups' => 'UPS',
        'usps' => 'USPS',
        'fedex' => 'FedEx',
        'dhl' => 'DHL'
    ];
    
    foreach ($standardCarriers as $carrierCode => $defaultTitle) {
        $isActive = $this->scopeConfig->getValue(
            'carriers/' . $carrierCode . '/active',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        
        if ($isActive) {
            $title = $this->scopeConfig->getValue(
                'carriers/' . $carrierCode . '/title',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            ) ?: $defaultTitle;
            
            $price = 0;
            $methodCode = $carrierCode . '_' . $carrierCode;
            
            // Set appropriate prices
            switch ($carrierCode) {
                case 'flatrate':
                    $price = (float)$this->scopeConfig->getValue(
                        'carriers/flatrate/price',
                        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                    ) ?: 25;
                    break;
                case 'freeshipping':
                    $price = 0;
                    $methodCode = 'freeshipping_freeshipping';
                    break;
                case 'tablerate':
                    $price = 30; // Default tablerate price
                    break;
                default:
                    $price = 35; // Default for other carriers
            }
            
            $methods[] = [
                'code' => $methodCode,
                'carrier_code' => $carrierCode,
                'method_code' => $carrierCode,
                'carrier_title' => $title,
                'title' => $title,
                'price' => $price,
                'price_formatted' => $this->formatPrice($price)
            ];
            
            $this->logger->info('Force collected carrier method', [
                'carrier' => $carrierCode,
                'method' => $methodCode,
                'price' => $price
            ]);
        }
    }
    
    return $methods;
}
/**
 * Get fallback shipping methods from system configuration
 */
private function getFallbackShippingMethods(): array
{
    // First try to force collect all active carriers
    $forcedMethods = $this->forceCollectAllActiveCarriers();
    
    if (!empty($forcedMethods)) {
        $this->logger->info('Using forced carrier methods', [
            'methods_count' => count($forcedMethods),
            'methods' => array_column($forcedMethods, 'code')
        ]);
        return $forcedMethods;
    }
    
    // Ultimate fallback
    return [[
        'code' => 'fallback_standard',
        'carrier_code' => 'fallback',
        'method_code' => 'standard',
        'carrier_title' => 'Standard Shipping',
        'title' => 'Standard Delivery',
        'price' => 25.0,
        'price_formatted' => $this->formatPrice(25.0)
    ]];
}
    
    /**
     * Fallback shipping collection if official API fails
     */
    private function fallbackShippingCollection($quote, string $requestId): array
    {
        try {
            $this->logger->info('Using fallback shipping collection', ['request_id' => $requestId]);
            
            $shippingAddress = $quote->getShippingAddress();
            $shippingAddress->setCollectShippingRates(true);
            $shippingAddress->collectShippingRates();
            
            $quote->collectTotals();
            $this->cartRepository->save($quote);
            
            $shippingRates = $shippingAddress->getAllShippingRates();
            
            $methods = [];
            foreach ($shippingRates as $rate) {
                if (!$rate->getErrorMessage()) {
                    $methods[] = [
                        'code' => $rate->getCarrier() . '_' . $rate->getMethod(),
                        'carrier_code' => $rate->getCarrier(),
                        'method_code' => $rate->getMethod(),
                        'carrier_title' => $rate->getCarrierTitle(),
                        'title' => $rate->getMethodTitle(),
                        'price' => (float)$rate->getPrice(),
                        'price_formatted' => $this->formatPrice((float)$rate->getPrice())
                    ];
                }
            }
            
            return $methods;
            
        } catch (\Exception $e) {
            $this->logger->error('Fallback shipping collection failed', [
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Get first available simple product for configurable
     */
    private function getFirstAvailableSimpleProduct($configurableProduct)
    {
        try {
            $childProducts = $configurableProduct->getTypeInstance()->getUsedProducts($configurableProduct);
            
            foreach ($childProducts as $childProduct) {
                if ($childProduct->isSalable() && $childProduct->getStatus() == 1) {
                    return $this->productRepository->getById($childProduct->getId());
                }
            }
            
            if (!empty($childProducts)) {
                return $this->productRepository->getById($childProducts[0]->getId());
            }
            
            return null;
            
        } catch (\Exception $e) {
            $this->logger->error('Error getting simple product variant', [
                'configurable_id' => $configurableProduct->getId(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

   public function getAvailablePaymentMethods(): array
{
    try {
        $store = $this->storeManager->getStore();
        
        // Use OFFICIAL Payment Method List API
        $paymentMethods = $this->paymentMethodList->getActiveList($store->getId());
        
        $methods = [];
        foreach ($paymentMethods as $method) {
            $methodCode = $method->getCode();
            $title = $method->getTitle() ?: $this->getPaymentMethodDefaultTitle($methodCode);
            
            $methods[] = [
                'code' => $methodCode,
                'title' => $title
            ];
        }
        
        // Apply admin filtering
        $methods = $this->helperData->filterPaymentMethods($methods);
        
        $this->logger->info('Enhanced payment methods retrieved', [
            'count' => count($methods),
            'methods' => array_column($methods, 'code'),
            'store_id' => $store->getId()
        ]);
        
        return $methods;
        
    } catch (\Exception $e) {
        $this->logger->error('Error getting enhanced payment methods: ' . $e->getMessage());
        return $this->getFallbackPaymentMethods();
    }
}
    
    private function getFallbackPaymentMethods(): array
    {
        try {
            $store = $this->storeManager->getStore();
            $activePayments = $this->paymentConfig->getActiveMethods();
            $methods = [];
            
            foreach ($activePayments as $code => $config) {
                $isActive = $this->scopeConfig->getValue(
                    'payment/' . $code . '/active',
                    ScopeInterface::SCOPE_STORE,
                    $store->getId()
                );
                
                if ($isActive) {
                    $title = $this->scopeConfig->getValue(
                        'payment/' . $code . '/title',
                        ScopeInterface::SCOPE_STORE,
                        $store->getId()
                    ) ?: $this->getPaymentMethodDefaultTitle($code);
                    
                    $methods[] = [
                        'code' => $code,
                        'title' => $title
                    ];
                }
            }
            
            return $methods;
        } catch (\Exception $e) {
            $this->logger->error('Error getting fallback payment methods: ' . $e->getMessage());
            return [];
        }
    }
    
    private function getPaymentMethodDefaultTitle(string $methodCode): string
    {
        $titles = [
            'checkmo' => 'Check / Money order',
            'banktransfer' => 'Bank Transfer Payment',
            'cashondelivery' => 'Cash On Delivery',
            'free' => 'No Payment Information Required',
            'purchaseorder' => 'Purchase Order'
        ];
        
        return $titles[$methodCode] ?? ucfirst(str_replace('_', ' ', $methodCode));
    }


public function createQuickOrder(QuickOrderDataInterface $orderData): array
{
    // Set selected product attributes if provided
    $superAttribute = $this->request->getParam('super_attribute');
    if ($superAttribute && is_array($superAttribute)) {
        $this->setSelectedProductAttributes($superAttribute);
    }
    
    try {
        $this->logger->info('=== Enhanced Order Creation Started ===', [
            'product_id' => $orderData->getProductId(),
            'shipping_method' => $orderData->getShippingMethod(),
            'payment_method' => $orderData->getPaymentMethod(),
            'country' => $orderData->getCountryId(),
            'qty' => $orderData->getQty()
        ]);
        
        // STEP 1: Create quote with shipping calculation FIRST
        $quote = $this->createRealisticQuoteWithProduct($orderData->getProductId());
        
        // STEP 2: Set customer info early
        $this->setCustomerInformation($quote, $orderData);
        
        // STEP 3: Set addresses BEFORE shipping calculation
        $this->setBillingAddress($quote, $orderData);
        $this->setShippingAddressEarly($quote, $orderData);
        
        // STEP 4: Update quantity if different from 1
        if ($orderData->getQty() > 1) {
            $this->updateQuoteItemQuantity($quote, $orderData->getQty());
        }
        
        // STEP 5: Get FRESH shipping methods for this specific quote
        $availableShippingMethods = $this->getQuoteShippingMethods($quote);
        
        $this->logger->info('Fresh shipping methods for order', [
            'methods_count' => count($availableShippingMethods),
            'methods' => array_column($availableShippingMethods, 'code'),
            'requested_method' => $orderData->getShippingMethod()
        ]);
        
        // STEP 6: Validate and set shipping method
        $validShippingMethod = $this->validateAndSetShippingMethod($quote, $orderData, $availableShippingMethods);
        
        // STEP 7: Set payment method
        $this->setPaymentMethod($quote, $orderData);
        
        // STEP 8: Final totals collection
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);
        
        // STEP 9: Validate quote is ready for order
        $this->validateQuoteForOrder($quote);
        
        // STEP 10: Place order using official API
        $orderId = $this->cartManagement->placeOrder($quote->getId());
        $order = $this->orderRepository->get($orderId);
        $this->ensureOrderVisibility($order);
        
        // STEP 11: Send email if enabled
        $this->sendOrderNotification($order);
        
        $this->logger->info('Enhanced order created successfully', [
            'order_id' => $orderId,
            'increment_id' => $order->getIncrementId(),
            'grand_total' => $order->getGrandTotal(),
            'shipping_method' => $order->getShippingMethod(),
            'shipping_description' => $order->getShippingDescription()
        ]);
        
        return [
            'success' => true,
            'order_id' => $orderId,
            'increment_id' => $order->getIncrementId(),
            'message' => $this->helperData->getSuccessMessage(),
            'redirect_url' => $this->getOrderSuccessUrl($order)
        ];
        
    } catch (\Exception $e) {
        $this->logger->error('Enhanced order creation failed: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
            'order_data' => [
                'product_id' => $orderData->getProductId(),
                'shipping_method' => $orderData->getShippingMethod(),
                'payment_method' => $orderData->getPaymentMethod()
            ]
        ]);
        throw new LocalizedException(__('Unable to create order: %1', $e->getMessage()));
    }
}

/**
 * Set shipping address early without method calculation
 */
private function setShippingAddressEarly($quote, QuickOrderDataInterface $orderData): void
{
    $shippingAddress = $quote->getShippingAddress();
    $this->setAddressData($shippingAddress, $orderData, $quote->getCustomerEmail());
    
    // Set weight for shipping calculation
    $totalWeight = 0;
    foreach ($quote->getAllItems() as $item) {
        $product = $item->getProduct();
        if ($product && $product->getWeight()) {
            $totalWeight += ($product->getWeight() * $item->getQty());
        }
    }
    $shippingAddress->setWeight($totalWeight > 0 ? $totalWeight : 1);
    
    // Save address data
    $quote->collectTotals();
    $this->cartRepository->save($quote);
}

/**
 * Get shipping methods for specific quote
 */
private function getQuoteShippingMethods($quote): array
{
    $shippingAddress = $quote->getShippingAddress();
    
    // Force shipping rates collection
    $shippingAddress->setCollectShippingRates(true);
    $shippingAddress->removeAllShippingRates();
    $shippingAddress->collectShippingRates();
    
    $quote->collectTotals();
    $this->cartRepository->save($quote);
    
    $shippingRates = $shippingAddress->getAllShippingRates();
    $methods = [];
    
    foreach ($shippingRates as $rate) {
        if ($rate->getMethod() !== null) {
            $methodCode = $rate->getCarrier() . '_' . $rate->getMethod();
            
            $methods[] = [
                'code' => $methodCode,
                'carrier_code' => $rate->getCarrier(),
                'method_code' => $rate->getMethod(),
                'carrier_title' => $rate->getCarrierTitle(),
                'title' => $rate->getMethodTitle(),
                'price' => (float)$rate->getPrice(),
                'rate_object' => $rate // Keep reference to original rate
            ];
        }
    }
    
    return $methods;
}

/**
 * Validate and set shipping method on quote
 */
private function validateAndSetShippingMethod($quote, QuickOrderDataInterface $orderData, array $availableShippingMethods): string
{
    $requestedMethod = $orderData->getShippingMethod();
    $shippingAddress = $quote->getShippingAddress();
    
    $this->logger->info('Validating shipping method', [
        'requested_method' => $requestedMethod,
        'available_methods' => array_column($availableShippingMethods, 'code')
    ]);
    
    // Find exact match
    foreach ($availableShippingMethods as $method) {
        if ($method['code'] === $requestedMethod) {
            $shippingAddress->setShippingMethod($method['code']);
            $shippingAddress->setShippingDescription($method['carrier_title'] . ' - ' . $method['title']);
            
            $this->logger->info('Exact shipping method match found', [
                'method' => $method['code'],
                'price' => $method['price']
            ]);
            
            return $method['code'];
        }
    }
    
    // Find carrier match
    $requestedCarrier = explode('_', $requestedMethod)[0];
    foreach ($availableShippingMethods as $method) {
        if ($method['carrier_code'] === $requestedCarrier) {
            $shippingAddress->setShippingMethod($method['code']);
            $shippingAddress->setShippingDescription($method['carrier_title'] . ' - ' . $method['title']);
            
            $this->logger->info('Carrier match found', [
                'requested' => $requestedMethod,
                'used' => $method['code']
            ]);
            
            return $method['code'];
        }
    }
    
    // Use first available method
    if (!empty($availableShippingMethods)) {
        $firstMethod = $availableShippingMethods[0];
        $shippingAddress->setShippingMethod($firstMethod['code']);
        $shippingAddress->setShippingDescription($firstMethod['carrier_title'] . ' - ' . $firstMethod['title']);
        
        $this->logger->info('Using first available shipping method', [
            'requested' => $requestedMethod,
            'used' => $firstMethod['code']
        ]);
        
        return $firstMethod['code'];
    }
    
    throw new LocalizedException(__('No valid shipping method available for this order.'));
}

/**
 * Validate quote is ready for order placement
 */
private function validateQuoteForOrder($quote): void
{
    $shippingAddress = $quote->getShippingAddress();
    $payment = $quote->getPayment();
    
    // Check shipping method
    if (!$shippingAddress->getShippingMethod()) {
        throw new LocalizedException(__('Shipping method is missing. Please select a shipping method and try again.'));
    }
    
    // Check payment method
    if (!$payment->getMethod()) {
        throw new LocalizedException(__('Payment method is missing. Please select a payment method and try again.'));
    }
    
    // Check quote has items
    if (!$quote->getItemsCount()) {
        throw new LocalizedException(__('Quote has no items. Please add products to continue.'));
    }
    
    // Check shipping address
    if (!$shippingAddress->getCountryId() || !$shippingAddress->getCity()) {
        throw new LocalizedException(__('Shipping address is incomplete. Please provide complete address.'));
    }
    
    $this->logger->info('Quote validation passed', [
        'quote_id' => $quote->getId(),
        'shipping_method' => $shippingAddress->getShippingMethod(),
        'payment_method' => $payment->getMethod(),
        'items_count' => $quote->getItemsCount(),
        'grand_total' => $quote->getGrandTotal()
    ]);
}	

/**
 * Ensure order is properly indexed and visible
 */
/**
 * FIXED: Ensure order is properly saved and visible in admin
 */
private function ensureOrderVisibility($order): void
{
    try {
        // Force order state and status
        if ($order->getState() === 'new' && $order->getStatus() === 'pending') {
            $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
            $order->setStatus('processing');
        }
        
        // Force order save multiple times to ensure persistence
        $this->orderRepository->save($order);
        
        // Clear cache
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        
        try {
            $cacheManager = $objectManager->get(\Magento\Framework\App\Cache\Manager::class);
            $cacheManager->clean(['db_ddl', 'collections', 'eav']);
        } catch (\Exception $e) {
            $this->logger->warning('Could not clean cache: ' . $e->getMessage());
        }
        
        // Force manual indexing
        try {
            $connection = $objectManager->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
            
            // Insert into sales_order_grid manually if not exists
            $gridTable = $connection->getTableName('sales_order_grid');
            $orderTable = $connection->getTableName('sales_order');
            
            // Check if record exists in grid
            $exists = $connection->fetchOne(
                "SELECT entity_id FROM {$gridTable} WHERE entity_id = ?",
                [$order->getId()]
            );
            
            if (!$exists) {
                // Insert manually into grid table
                $orderData = $connection->fetchRow(
                    "SELECT * FROM {$orderTable} WHERE entity_id = ?",
                    [$order->getId()]
                );
                
                if ($orderData) {
                    $gridData = [
                        'entity_id' => $order->getId(),
                        'status' => $order->getStatus(),
                        'store_id' => $order->getStoreId(),
                        'store_name' => $order->getStoreName(),
                        'customer_id' => $order->getCustomerId(),
                        'base_grand_total' => $order->getBaseGrandTotal(),
                        'grand_total' => $order->getGrandTotal(),
                        'increment_id' => $order->getIncrementId(),
                        'base_currency_code' => $order->getBaseCurrencyCode(),
                        'order_currency_code' => $order->getOrderCurrencyCode(),
                        'shipping_name' => $order->getShippingAddress() ? $order->getShippingAddress()->getName() : '',
                        'billing_name' => $order->getBillingAddress() ? $order->getBillingAddress()->getName() : '',
                        'created_at' => $order->getCreatedAt(),
                        'updated_at' => $order->getUpdatedAt(),
                        'billing_address' => $order->getBillingAddress() ? 
                            implode(', ', $order->getBillingAddress()->getStreet()) . ', ' . 
                            $order->getBillingAddress()->getCity() : '',
                        'shipping_address' => $order->getShippingAddress() ? 
                            implode(', ', $order->getShippingAddress()->getStreet()) . ', ' . 
                            $order->getShippingAddress()->getCity() : '',
                        'shipping_information' => $order->getShippingDescription(),
                        'customer_email' => $order->getCustomerEmail(),
                        'customer_group' => $order->getCustomerGroupId(),
                        'subtotal' => $order->getSubtotal(),
                        'shipping_and_handling' => $order->getShippingAmount(),
                        'customer_name' => $order->getCustomerName(),
                        'payment_method' => $order->getPayment() ? $order->getPayment()->getMethod() : '',
                        'total_refunded' => $order->getTotalRefunded() ?: 0
                    ];
                    
                    $connection->insert($gridTable, $gridData);
                    
                    $this->logger->info('Order manually inserted into grid', [
                        'order_id' => $order->getId(),
                        'increment_id' => $order->getIncrementId()
                    ]);
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Manual grid insertion failed: ' . $e->getMessage());
        }
        
        // Try to run indexer via CLI command (if possible)
        try {
            $indexerRegistry = $objectManager->get(\Magento\Framework\Indexer\IndexerRegistry::class);
            $salesOrderGridIndexer = $indexerRegistry->get('sales_order_grid');
            if ($salesOrderGridIndexer && $salesOrderGridIndexer->isValid()) {
                $salesOrderGridIndexer->reindexRow($order->getId());
            }
        } catch (\Exception $e) {
            $this->logger->warning('Indexer reindex failed: ' . $e->getMessage());
        }
        
        // Final save
        $this->orderRepository->save($order);
        
        $this->logger->info('Order visibility ensured - ENHANCED', [
            'order_id' => $order->getId(),
            'increment_id' => $order->getIncrementId(),
            'status' => $order->getStatus(),
            'state' => $order->getState(),
            'customer_email' => $order->getCustomerEmail(),
            'grand_total' => $order->getGrandTotal()
        ]);
        
    } catch (\Exception $e) {
        $this->logger->error('Error ensuring order visibility: ' . $e->getMessage(), [
            'order_id' => $order->getId(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

    // Rest of the methods remain the same as previous version...
    private function updateQuoteItemQuantity($quote, int $qty): void
    {
        foreach ($quote->getAllItems() as $item) {
            $item->setQty($qty);
        }
        $quote->collectTotals();
    }
    
    private function setCustomerInformation($quote, QuickOrderDataInterface $orderData): void
    {
        $customerEmail = $orderData->getCustomerEmail();
        if (!$customerEmail && $this->helperData->isAutoGenerateEmailEnabled()) {
            $customerEmail = $this->helperData->generateGuestEmail($orderData->getCustomerPhone());
        }
        
        $quote->setCustomerEmail($customerEmail);
        $quote->setCustomerFirstname($orderData->getCustomerName());
        $quote->setCustomerLastname('');
    }
    
    private function setBillingAddress($quote, QuickOrderDataInterface $orderData): void
    {
        $billingAddress = $quote->getBillingAddress();
        $this->setAddressData($billingAddress, $orderData, $quote->getCustomerEmail());
    }
    
 /**
 * FIXED: Properly set shipping address and method
 */
private function setShippingAddressAndMethod($quote, QuickOrderDataInterface $orderData): void
{
    $shippingAddress = $quote->getShippingAddress();
    $this->setAddressData($shippingAddress, $orderData, $quote->getCustomerEmail());
    
    // CRITICAL: Force shipping rates collection
    $shippingAddress->setCollectShippingRates(true);
    $shippingAddress->removeAllShippingRates();
    
    // Set weight for shipping calculation
    $totalWeight = 0;
    foreach ($quote->getAllItems() as $item) {
        $product = $item->getProduct();
        if ($product && $product->getWeight()) {
            $totalWeight += ($product->getWeight() * $item->getQty());
        }
    }
    $shippingAddress->setWeight($totalWeight > 0 ? $totalWeight : 1);
    
    // Collect shipping rates
    $shippingAddress->collectShippingRates();
    
    // Force totals calculation
    $quote->setTotalsCollectedFlag(false);
    $quote->collectTotals();
    $this->cartRepository->save($quote);
    
    // FIXED: Properly validate and set shipping method
    $requestedMethod = $orderData->getShippingMethod();
    $availableRates = $shippingAddress->getAllShippingRates();
    
    $methodFound = false;
    $this->logger->info('Setting shipping method', [
        'requested_method' => $requestedMethod,
        'available_rates_count' => count($availableRates)
    ]);
    
    // Check if requested method exists in available rates
    foreach ($availableRates as $rate) {
        $rateCode = $rate->getCarrier() . '_' . $rate->getMethod();
        $this->logger->info('Available rate', [
            'rate_code' => $rateCode,
            'carrier' => $rate->getCarrier(),
            'method' => $rate->getMethod(),
            'price' => $rate->getPrice()
        ]);
        
        if ($rateCode === $requestedMethod || 
            $rate->getCarrier() === $requestedMethod ||
            strpos($requestedMethod, $rate->getCarrier() . '_') === 0) {
            
            $shippingAddress->setShippingMethod($rateCode);
            $shippingAddress->setShippingDescription($rate->getCarrierTitle() . ' - ' . $rate->getMethodTitle());
            $methodFound = true;
            
            $this->logger->info('Shipping method set successfully', [
                'method_code' => $rateCode,
                'description' => $rate->getCarrierTitle() . ' - ' . $rate->getMethodTitle(),
                'price' => $rate->getPrice()
            ]);
            break;
        }
    }
    
    // If method not found, try to find similar method
    if (!$methodFound) {
        $this->logger->warning('Requested shipping method not found, trying alternatives', [
            'requested_method' => $requestedMethod
        ]);
        
        // Extract carrier from requested method
        $carrierCode = explode('_', $requestedMethod)[0];
        
        foreach ($availableRates as $rate) {
            if ($rate->getCarrier() === $carrierCode) {
                $rateCode = $rate->getCarrier() . '_' . $rate->getMethod();
                $shippingAddress->setShippingMethod($rateCode);
                $shippingAddress->setShippingDescription($rate->getCarrierTitle() . ' - ' . $rate->getMethodTitle());
                $methodFound = true;
                
                $this->logger->info('Alternative shipping method set', [
                    'original_request' => $requestedMethod,
                    'set_method' => $rateCode
                ]);
                break;
            }
        }
    }
    
    // Final fallback - use first available rate
    if (!$methodFound && !empty($availableRates)) {
        $firstRate = reset($availableRates);
        $rateCode = $firstRate->getCarrier() . '_' . $firstRate->getMethod();
        $shippingAddress->setShippingMethod($rateCode);
        $shippingAddress->setShippingDescription($firstRate->getCarrierTitle() . ' - ' . $firstRate->getMethodTitle());
        
        $this->logger->info('Fallback shipping method set', [
            'fallback_method' => $rateCode,
            'original_request' => $requestedMethod
        ]);
        $methodFound = true;
    }
    
    if (!$methodFound) {
        throw new LocalizedException(__('No valid shipping method available. Please try again.'));
    }
    
    // Final totals collection with shipping method
    $quote->setTotalsCollectedFlag(false);
    $quote->collectTotals();
    $this->cartRepository->save($quote);
    
    $this->logger->info('Shipping method final verification', [
        'quote_shipping_method' => $shippingAddress->getShippingMethod(),
        'quote_shipping_amount' => $shippingAddress->getShippingAmount(),
        'quote_grand_total' => $quote->getGrandTotal()
    ]);
}
    
    private function setPaymentMethod($quote, QuickOrderDataInterface $orderData): void
    {
        $payment = $quote->getPayment();
        $payment->importData(['method' => $orderData->getPaymentMethod()]);
    }
    
    private function sendOrderNotification($order): void
    {
        if ($this->helperData->isEmailNotificationEnabled()) {
            try {
                $this->orderSender->send($order);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to send order email: ' . $e->getMessage());
            }
        }
    }
    
    private function getOrderSuccessUrl($order): string
    {
        return $this->storeManager->getStore()->getUrl('checkout/onepage/success', [
            '_query' => ['order_id' => $order->getId()]
        ]);
    }
    
private function setAddressData($address, QuickOrderDataInterface $orderData, string $customerEmail): void
{
    // Split customer name into first and last name
    $fullName = trim($orderData->getCustomerName());
    $nameParts = explode(' ', $fullName, 2);
    $firstName = $nameParts[0];
    $lastName = isset($nameParts[1]) ? $nameParts[1] : $firstName; // Use first name if no last name
    
    $address->setFirstname($firstName);
    $address->setLastname($lastName); // FIXED: Always set lastname
    
    // Handle street address properly
    $streetAddress = $orderData->getAddress();
    if (strpos($streetAddress, ',') !== false) {
        $streetLines = array_map('trim', explode(',', $streetAddress));
    } else {
        $streetLines = [$streetAddress];
    }
    $address->setStreet($streetLines);
    
    $address->setCity($orderData->getCity());
    $address->setCountryId($orderData->getCountryId());
    $address->setTelephone($this->helperData->formatPhoneNumber($orderData->getCustomerPhone()));
    $address->setEmail($customerEmail);

    if ($orderData->getRegion()) {
        $regionId = $this->getRegionIdByName($orderData->getRegion(), $orderData->getCountryId());
        if ($regionId) {
            $address->setRegionId($regionId);
        }
        $address->setRegion($orderData->getRegion());
    }
    
    if ($orderData->getPostcode()) {
        $address->setPostcode($orderData->getPostcode());
    }
    
    // IMPORTANT: Ensure all required fields are set
    if (!$address->getCompany()) {
        $address->setCompany(''); // Set empty company to avoid issues
    }
}

    public function calculateShippingCost(
        int $productId,
        string $shippingMethod,
        string $countryId,
        ?string $region = null,
        ?string $postcode = null,
        int $qty = 1
    ): float {
        try {
            $methods = $this->getAvailableShippingMethods($productId, $countryId, $region, $postcode);
            
            foreach ($methods as $method) {
                if ($method['code'] === $shippingMethod) {
                    return (float)$method['price'] * $qty;
                }
            }

            return $this->helperData->getDefaultShippingPrice();
            
        } catch (\Exception $e) {
            $this->logger->error('Error calculating shipping cost: ' . $e->getMessage());
            return $this->helperData->getDefaultShippingPrice();
        }
    }

    private function getRegionIdByName(string $regionName, string $countryId): ?int
    {
        try {
            $region = $this->regionFactory->create();
            $region->loadByName($regionName, $countryId);
            
            return $region->getId() ? (int)$region->getId() : null;
            
        } catch (\Exception $e) {
            $this->logger->warning('Could not find region ID for: ' . $regionName . ' in country: ' . $countryId);
            return null;
        }
    }

    private function formatPrice(float $price): string
    {
        return $this->priceHelper->currency($price, true, false);
    }
	/**
 * Apply custom order status and state if configured
 */
private function applyCustomOrderStatus($order): void
{
    try {
        $customStatus = $this->helperData->getDefaultOrderStatus();
        $customState = $this->helperData->getDefaultOrderState();
        
        if ($customStatus) {
            $order->setStatus($customStatus);
            $this->logger->info('Applied custom order status', [
                'order_id' => $order->getId(),
                'custom_status' => $customStatus
            ]);
        }
        
        if ($customState) {
            $order->setState($customState);
            $this->logger->info('Applied custom order state', [
                'order_id' => $order->getId(),
                'custom_state' => $customState
            ]);
        }
        
        if ($customStatus || $customState) {
            $this->orderRepository->save($order);
        }
        
    } catch (\Exception $e) {
        $this->logger->warning('Could not apply custom order status/state: ' . $e->getMessage());
    }
}
}