<?php
namespace Mondu\Mondu\Observer;

use Mondu\Mondu\Helpers\Log;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class ShipOrder implements \Magento\Framework\Event\ObserverInterface
{
    protected $_monduLogger;
    private $_requestFactory;
    private $_config;
    private $monduFileLogger;
    private $paymentMethodHelper;
    private $orderHelper;

    public function __construct(
        RequestFactory $requestFactory,
        ConfigProvider $config,
        Log $logger,
        MonduFileLogger $monduFileLogger,
        PaymentMethod $paymentMethodHelper,
        OrderHelper $orderHelper

    )
    {
        $this->_requestFactory = $requestFactory;
        $this->_config = $config;
        $this->_monduLogger = $logger;
        $this->monduFileLogger = $monduFileLogger;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->orderHelper = $orderHelper;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment->getOrder();
        $payment = $order->getPayment();
        $this->monduFileLogger->info('Entered ShipOrder observer', ['orderNumber' => $order->getIncrementId()]);

        if (!$this->paymentMethodHelper->isMondu($payment)) {
            $this->monduFileLogger->info('Not a mondu order, skipping', ['orderNumber' => $order->getIncrementId()]);
            return;
        }

        $invoiceIds = $order->getInvoiceCollection()->getAllIds();

        $monduLog = $this->_monduLogger->getLogCollection($order->getData('mondu_reference_id'));
        $arr = [];

        if($monduLog->getSkipShipObserver()) {
            $this->monduFileLogger->info('Already invoiced using invoice orders action, skipping', ['orderNumber' => $order->getIncrementId()]);
            return;
        }

        if($monduLog->getAddons() && $monduLog->getAddons() !== 'null') {
            $invoices = json_decode($monduLog->getAddons(), true);
            $arr = array_values(array_map(function ($a) {
                return $a['local_id'];
            }, $invoices));
        }

        $shipSkuQtyArray = [];
        $invoiceSkuQtyArray = [];
        foreach($shipment->getItems() as $item) {
            if($item->getQty()) {
                $shipSkuQtyArray[$item->getSku()] = $item->getQty();
            } elseif(!@$shipSkuQtyArray[$item->getSku()]) {
                $shipSkuQtyArray[$item->getSku()] = $item->getQty();
            }
        }
        foreach($order->getInvoiceCollection()->getItems() as $invoice) {
            if (in_array($invoice->getEntityId(), $arr)) {
                continue;
            }
            foreach($invoice->getAllItems() as $i) {
                $price = (float) $i->getBasePrice();
                if (!$price) {
                    continue;
                }

                if(!@$invoiceSkuQtyArray[$i->getSku()]) {
                    $invoiceSkuQtyArray[$i->getSku()] = 0;
                }

                $invoiceSkuQtyArray[$i->getSku()] += (int) $i->getQty();
            }
        }

        foreach($shipSkuQtyArray as $key => $shipSkuQty) {
            if(@$invoiceSkuQtyArray[$key] !== $shipSkuQty) {
                throw new LocalizedException(__('Mondu: Invalid shipment amount'));
            }
        }
        foreach($invoiceSkuQtyArray as $key => $invoiceSkuQty) {
            if(@$shipSkuQtyArray[$key] !== $invoiceSkuQty) {
                throw new LocalizedException(__('Mondu: Invalid shipment amount'));
            }
        }

        if($invoiceIds) {
            if($monduLog->getAddons() && $monduLog->getAddons() !== 'null') {
                $invoiceMapping = json_decode($monduLog->getAddons(), true);
            } else {
                $invoiceMapping = [];
            }

            $monduId = $order->getData('mondu_reference_id');
            $invoiceCollection = $order->getInvoiceCollection();

            if(!$this->_monduLogger->canShipOrder($monduId)) {
                throw new LocalizedException(__('Can\'t ship order: Mondu order state must be confirmed or partially_shipped'));
            }

            foreach($invoiceCollection as $invoiceItem) {
                if (in_array($invoiceItem->getEntityId(), $arr)) {
                    continue;
                }
                $this->createInvoiceForItem($invoiceItem, $monduId, $shipment, $invoiceMapping);
                $this->monduFileLogger->info('ShipOrder Observer: Invoice sent to mondu '.$invoiceItem->getEntityId() . '. Order: ' . $order->getIncrementId());
            }

            $this->_monduLogger->syncOrder($monduId);
        } else {
            throw new LocalizedException(__('Please invoice all order items first'));
        }
    }

    private function getInvoiceUrl($orderUid, $invoiceId) {
        return $this->_config->getPdfUrl($orderUid, $invoiceId);
    }

    /**
     * @throws LocalizedException
     */
    private function createInvoiceForItem($invoiceItem, $monduId, $shipment, &$invoiceMapping) {
        $gross_amount_cents = $invoiceItem->getGrandTotal() * 100;

        $invoice_url = $this->getInvoiceUrl($monduId, $invoiceItem->getIncrementId());

        $invoiceBody = [
            'order_uid' => $monduId,
            'external_reference_id' => $invoiceItem->getIncrementId(),
            'gross_amount_cents' => $gross_amount_cents,
            'invoice_url' => $invoice_url,
        ];

        $invoiceBody = $this->orderHelper->addLineItemsToInvoice($invoiceItem, $invoiceBody);

        $shipOrderData = $this->_requestFactory->create(RequestFactory::SHIP_ORDER)
            ->process($invoiceBody);

        if(!$shipOrderData) {
            throw new LocalizedException(__('Can\'t ship the order at this time'));
        }

        if(@$shipOrderData['errors']) {
            throw new LocalizedException(__($shipOrderData['errors'][0]['name']. ' '. $shipOrderData['errors'][0]['details']));
        }

        $invoiceData = $shipOrderData['invoice'];

        $invoiceMapping[$invoiceItem->getIncrementId()] = [
            'uuid' => $invoiceData['uuid'],
            'state' => $invoiceData['state'],
            'local_id' => $invoiceItem->getId()
        ];

        $this->_monduLogger->updateLogInvoice($monduId, $invoiceMapping);

        $shipment->addComment(__('Mondu: invoice created with id %1', $shipOrderData['invoice']['uuid']));

        return $shipOrderData;
    }
}
