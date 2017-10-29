<?php
/**
 * @package      Crowdfunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2017 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      GNU General Public License version 3 or later; see LICENSE.txt
 */

// no direct access
defined('_JEXEC') or die;

use Crowdfunding\Payment;
use Joomla\Registry\Registry;
use Joomla\String\StringHelper;
use Joomla\Utilities\ArrayHelper;
use Crowdfunding\Country\Country;
use Crowdfunding\Transaction\Transaction;

use Prism\Payment\Result as PaymentResult;
use Crowdfunding\Transaction\TransactionManager;

use \PayPal\IPN\PPIPNMessage;
use \PayPal\Types\AP\PayRequest;
use \PayPal\Core\PPMessage;
use \PayPal\Types\AP\ReceiverList;
use \PayPal\Types\AP\PreapprovalRequest;
use \PayPal\Types\AP\CancelPreapprovalRequest;
use \PayPal\Types\Common\RequestEnvelope;
use \PayPal\Service\AdaptivePaymentsService;

use Crowdfunding\Payment\Transaction as PaymentTransaction;
use Crowdfunding\Payment\Session\Session as PaymentSession;
use Crowdfunding\Payment\Session\Mapper as PaymentSessionMapper;
use Crowdfunding\Payment\Session\Repository as PaymentSessionRepository;
use Crowdfunding\Payment\Session\Gateway\JoomlaGateway as PaymentSessionGateway;

use Crowdfunding\Project\Repository as ProjectRepository;
use Crowdfunding\Project\Mapper as ProjectMapper;
use Crowdfunding\Project\Gateway\JoomlaGateway as ProjectGateway;

jimport('Prism.init');
jimport('Crowdfunding.init');
jimport('Emailtemplates.init');
jimport('Crowdfundingfinance.init');

JObserverMapper::addObserverClassToClass(
    Crowdfunding\Observer\Transaction\TransactionObserver::class,
    Crowdfunding\Transaction\TransactionManager::class,
    array('typeAlias' => 'com_crowdfunding.payment')
);

/**
 * Crowdfunding PayPal Adaptive payment plugin.
 *
 * @package      Crowdfunding
 * @subpackage   Plugins
 */
class plgCrowdfundingPaymentPayPalAdaptive extends Payment\Plugin
{
    public function __construct(&$subject, $config = array())
    {
        $this->serviceProvider = 'PayPal Adaptive';
        $this->serviceAlias    = 'paypaladaptive';

        parent::__construct($subject, $config);
    }

    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string   $context This string gives information about that where it has been executed the trigger.
     * @param stdClass $item    A project data.
     * @param Registry $params  The parameters of the component
     *
     * @return null|string
     * @throws \InvalidArgumentException
     */
    public function onProjectPayment($context, $item, $params)
    {
        if (strcmp('com_crowdfunding.payment', $context) !== 0) {
            return null;
        }

        if ($this->app->isClient('administrator')) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        if (strcmp('html', $doc->getType()) !== 0) {
            return null;
        }

        // This is a URI path to the plugin folder
        $html   = array();
        $html[] = '<div class="well">'; // Open "well".

        $html[] = '<h4><img src="plugins/crowdfundingpayment/paypaladaptive/images/paypal_icon.png" width="36" height="32" alt="PayPal" />' . JText::_($this->textPrefix . '_TITLE') . '</h4>';
        $html[] = '<form action="/index.php?option=com_crowdfunding" method="post">';
        $html[] = '<input type="hidden" name="task" value="payments.checkout" />';
        $html[] = '<input type="hidden" name="payment_service" value="' . $this->serviceAlias . '" />';
        $html[] = '<input type="hidden" name="pid" value="' . $item->id . '" />';
        $html[] = JHtml::_('form.token');

        $this->prepareLocale($html);

        $html[] = '<img alt="" border="0" width="1" height="1" src="https://www.paypal.com/en_US/i/scr/pixel.gif" />';
        $html[] = '</form>';

        $html[] = '<p class="alert alert-info p-10-5"><span class="fa fa-info-circle"></span> ' . JText::_($this->textPrefix . '_INFO') . '</p>';

        if ($this->params->get('paypal_sandbox', 1)) {
            $html[] = '<p class="alert alert-info p-10-5"><span class="fa fa-info-circle"></span> ' . JText::_($this->textPrefix . '_WORKS_SANDBOX') . '</p>';
        }

        $html[] = '</div>'; // Close "well".

        return implode("\n", $html);
    }

    /**
     * Process payment transaction.
     *
     * @param string   $context
     * @param stdClass $item
     * @param Registry $params
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     *
     * @return null|PaymentResult
     */
    public function onPaymentsCheckout($context, $item, $params)
    {
        if (strcmp('com_crowdfunding.payments.checkout.' . $this->serviceAlias, $context) !== 0) {
            return null;
        }

        if ($this->app->isClient('administrator')) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        $paymentResult = new PaymentResult();

        $notifyUrl = $this->getCallbackUrl();
        $cancelUrl = $this->getCancelUrl($item->slug, $item->catslug);
        $returnUrl = $this->getReturnUrl($item->slug, $item->catslug);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_NOTIFY_URL'), $this->debugType, $notifyUrl) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RETURN_URL'), $this->debugType, $returnUrl) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_CANCEL_URL'), $this->debugType, $cancelUrl) : null;

        // Get country and locale code.
        $countryId = $this->params->get('paypal_country');
        $country   = new Country(JFactory::getDbo());
        $country->load($countryId);

        // Prepare starting and ending date.
        if (!$this->params->get('paypal_starting_date', 0)) { // End date of the campaign.
            $startingDate = new JDate(); // Today
            $startingDate->setTime(0, 0); // At 00:00:00
        } else {
            $startingDate = new JDate($item->ending_date);
            $startingDate->modify('+1 day');
            $startingDate->setTime(0, 0); // At 00:00:00
        }

        $endingDate = new JDate($item->ending_date);
        $endingDate->modify('+10 days');

        // Get payment session.

        $paymentSessionContext = Crowdfunding\Constants::PAYMENT_SESSION_CONTEXT . $item->id;
        $paymentSessionLocal   = $this->app->getUserState($paymentSessionContext);

        $paymentSession = $this->getPaymentSession([
            'session_id' => $paymentSessionLocal->session_id
        ], Prism\Constants::NO);

        $preapprovalRequest                              = new PreapprovalRequest(new RequestEnvelope('en_US'), $cancelUrl, $item->currencyCode, $returnUrl, $startingDate->format(DATE_ATOM));
        $preapprovalRequest->endingDate                  = $endingDate->format(DATE_ATOM);
        $preapprovalRequest->maxAmountPerPayment         = $item->amount;
        $preapprovalRequest->maxTotalAmountOfAllPayments = $item->amount;
        $preapprovalRequest->maxNumberOfPayments         = 1;
        $preapprovalRequest->feesPayer                   = $this->params->get('paypal_fees_payer');
        $preapprovalRequest->memo                        = JText::sprintf($this->textPrefix . '_INVESTING_IN_S', htmlentities($item->title, ENT_QUOTES, 'UTF-8'));
        $preapprovalRequest->pinType                     = 'NOT_REQUIRED';
        $preapprovalRequest->displayMaxTotalAmount       = true;
        $preapprovalRequest->ipnNotificationUrl          = $notifyUrl;

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYPAL_ADAPTIVE_OPTIONS'), $this->debugType, $preapprovalRequest) : null;

        $service  = new AdaptivePaymentsService($this->getAccountConfig());
        $response = $service->Preapproval($preapprovalRequest);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYPAL_ADAPTIVE_RESPONSE'), $this->debugType, $response) : null;

        $ack = (isset($response->responseEnvelope) && $response->responseEnvelope->ack) ? strtoupper($response->responseEnvelope->ack) : '';
        if ($ack !== 'SUCCESS') {
            return null;
        }

        $preapprovalKey = isset($response->preapprovalKey) ? $response->preapprovalKey : '';

        // Store token to the payment session.
        $paymentSession->service($this->serviceAlias)->setToken($preapprovalKey);
        $paymentSession->service($this->serviceAlias)->data('starting_date', $startingDate->format(DATE_ATOM));
        $paymentSession->service($this->serviceAlias)->data('ending_date', $endingDate->format(DATE_ATOM));

        $repository = new PaymentSessionRepository(new PaymentSessionMapper(new PaymentSessionGateway(\JFactory::getDbo())));
        $repository->storeServiceData($this->serviceAlias, $paymentSession);

        // Get PayPal checkout URL.
        if (!$this->params->get('paypal_sandbox', Prism\Constants::ENABLED)) {
            $paymentResult->redirectUrl = $this->params->get('paypal_url') . '?cmd=_ap-preapproval&preapprovalkey=' . rawurlencode($preapprovalKey);
        } else {
            $paymentResult->redirectUrl = $this->params->get('paypal_sandbox_url') . '?cmd=_ap-preapproval&preapprovalkey=' . rawurlencode($preapprovalKey);
        }

        $paymentResult
            ->skipEvent(PaymentResult::EVENT_AFTER_PAYMENT_NOTIFY)
            ->skipEvent(PaymentResult::EVENT_AFTER_PAYMENT);

        return $paymentResult;
    }

    /**
     * Capture payments.
     *
     * @param string   $context
     * @param stdClass $item
     * @param Registry $params
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     *
     * @return array|null
     */
    public function onPaymentsCapture($context, $item, $params)
    {
        $allowedContext = array('com_crowdfunding.payments.capture.' . $this->serviceAlias, 'com_crowdfundingfinance.payments.capture.' . $this->serviceAlias);
        if (!in_array($context, $allowedContext, true)) {
            return null;
        }

        if (!$this->app->isClient('administrator')) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        // Create Project object fetching its data from database.
        $mapper     = new ProjectMapper(new ProjectGateway(JFactory::getDbo()));
        $repository = new ProjectRepository($mapper);
        $project    = $repository->fetchById($item->project_id);

        // Fetching the fee values from Crowdfunding Finance.
        $fundingType = $project->getFundingType();
        $fees        = $this->getFees($fundingType);

        // Selected payment type on the plugin settings.
        $paymentType = $this->params->get('paypal_payment_type', 'simple');

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_TYPE'), $this->debugType, $paymentType) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_FEES'), $this->debugType, $fees) : null;

        $notifyUrl = $this->getCallbackUrl();
        $cancelUrl = $this->getCancelUrl($project->getSlug(), $project->getCatSlug());
        $returnUrl = $this->getReturnUrl($project->getSlug(), $project->getCatSlug());

        // Prepare a list with receivers and set their fees.
        $fee          = $this->calculateFee($fundingType, $fees, $item->txn_amount);
        $receiverList = $this->prepareReceiverList($item, $fee, $paymentType);

        // Prepare Pay request.
        $payRequest = new PayRequest(new RequestEnvelope('en_US'), 'PAY', $cancelUrl, $item->txn_currency, new ReceiverList($receiverList), $returnUrl);

        $payRequest->ipnNotificationUrl = $notifyUrl;
        $payRequest->feesPayer          = $this->params->get('paypal_fees_payer');
        $payRequest->preapprovalKey     = $item->txn_id;
        $payRequest->memo               = JText::sprintf($this->textPrefix . '_INVESTING_IN_S', htmlentities($project->getTitle(), ENT_QUOTES, 'UTF-8'));

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DOCAPTURE_OPTIONS'), $this->debugType, $payRequest) : null;

        try {
            $service  = new AdaptivePaymentsService($this->getAccountConfig());
            $response = $service->Pay($payRequest);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DOCAPTURE_RESPONSE'), $this->debugType, $response) : null;

            // Include extra data to transaction record.
            $ack = (isset($response->responseEnvelope) && isset($response->responseEnvelope->ack)) ? strtoupper($response->responseEnvelope->ack) : false;
            if ($ack === 'SUCCESS') {
                $extraData = $this->prepareExtraDataPaypalResponse($response, JText::_($this->textPrefix . '_RESPONSE_NOTE_CAPTURE_PREAPPROVAL'));

                $transaction = new Transaction(JFactory::getDbo());
                $transaction->load($item->id);

                if (strcmp($paymentType, 'simple') !== 0) {
                    $transaction->setFee($fee);
                }

                $transaction->addExtraData($extraData);
                $transaction->store();

                // DEBUG DATA
                JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DOCAPTURE_TRANSACTION'), $this->debugType, $transaction->getProperties()) : null;
            }
        } catch (Exception $e) {
            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_ERROR_DOCAPTURE'), $this->debugType, $e->getMessage()) : null;

            $message = array(
                'text' => JText::sprintf($this->textPrefix . '_CAPTURED_UNSUCCESSFULLY', $item->txn_id),
                'type' => 'error'
            );
            return $message;
        }

        $message = array(
            'text' => JText::sprintf($this->textPrefix . '_CAPTURED_SUCCESSFULLY', $item->txn_id),
            'type' => 'message'
        );
        return $message;
    }

    /**
     * Void payments.
     *
     * @param string   $context
     * @param stdClass $item
     * @param Registry $params
     *
     * @throws \RuntimeException
     *
     * @return array|null
     */
    public function onPaymentsVoid($context, $item, $params)
    {
        $allowedContext = array('com_crowdfunding.payments.void.' . $this->serviceAlias, 'com_crowdfundingfinance.payments.void.' . $this->serviceAlias);
        if (!in_array($context, $allowedContext, true)) {
            return null;
        }

        if (!$this->app->isClient('administrator')) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        // Prepare Pay request.
        $cancelPreapprovalRequest = new CancelPreapprovalRequest(new RequestEnvelope('en_US'), $item->txn_id);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DOVOID_OPTIONS'), $this->debugType, $cancelPreapprovalRequest) : null;

        try {
            $service  = new AdaptivePaymentsService($this->getAccountConfig());
            $response = $service->CancelPreapproval($cancelPreapprovalRequest);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DOVOID_RESPONSE'), $this->debugType, $response) : null;

            // Include extra data to transaction record.
            $ack = (isset($response->responseEnvelope) && isset($response->responseEnvelope->ack)) ? strtoupper($response->responseEnvelope->ack) : false;
            if ($ack === 'SUCCESS') {
                $extraData = $this->prepareExtraDataPaypalResponse($response, JText::_($this->textPrefix . '_RESPONSE_NOTE_CANCEL_PREAPPROVAL'));

                $transaction = new Transaction(JFactory::getDbo());
                $transaction->load($item->id);

                $transaction->addExtraData($extraData);
                $transaction->updateExtraData();

                // DEBUG DATA
                JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DOVOID_TRANSACTION'), $this->debugType, $transaction->getProperties()) : null;
            }
        } catch (Exception $e) {
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_ERROR_DOVOID'), $this->debugType, $e->getMessage()) : null;

            $message = array(
                'text' => JText::sprintf($this->textPrefix . '_VOID_UNSUCCESSFULLY', $item->txn_id),
                'type' => 'error'
            );
            return $message;
        }

        $message = array(
            'text' => JText::sprintf($this->textPrefix . '_VOID_SUCCESSFULLY', $item->txn_id),
            'type' => 'message'
        );
        return $message;
    }

    /**
     * This method processes transaction data that comes from PayPal instant notifier.
     *
     * @param string   $context This string gives information about that where it has been executed the trigger.
     * @param Registry $params  The parameters of the component
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     * @throws \OutOfBoundsException
     *
     * @return null|PaymentResult
     */
    public function onPaymentNotify($context, $params)
    {
        if (strcmp('com_crowdfunding.notify.' . $this->serviceAlias, $context) !== 0) {
            return null;
        }

        if ($this->app->isClient('administrator')) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentRaw */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('raw', $docType) !== 0) {
            return null;
        }

        // Validate request method
        $requestMethod = $this->app->input->getMethod();
        if (strcmp('POST', $requestMethod) !== 0) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_REQUEST_METHOD'), $this->debugType, JText::sprintf($this->textPrefix . '_ERROR_INVALID_TRANSACTION_REQUEST_METHOD', $requestMethod));

            return null;
        }

        // Prepare the array that will be returned by this method
        $paymentResult                  = new PaymentResult;
        $paymentResult->serviceProvider = $this->serviceProvider;
        $paymentResult->serviceAlias    = $this->serviceAlias;

        $status = $this->getPaymentStatus($_POST);
        switch ($status) {
            case 'pending':
                $this->processPreApproval($paymentResult, $params);
                break;

            case 'canceled':
                $this->processCanceled();
                break;

            case 'completed':
                $this->processPay();
                break;

            default:
                $paymentResult = null;
                break;
        }

        return $paymentResult;
    }

    /**
     * Process preapproval creating new transaction record.
     *
     * @param PaymentResult $paymentResult
     * @param Registry      $params The parameters of the component
     *
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     * @throws \RuntimeException
     * @throws \OutOfBoundsException
     */
    protected function processPreApproval($paymentResult, $params)
    {
        $postData   = file_get_contents('php://input');
        $ipnMessage = new PPIPNMessage($postData, $this->getIpnConfig());

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESPONSE'), $this->debugType, $_POST) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_ROW_DATA'), $this->debugType, $ipnMessage->getRawData()) : null;

        try {
            if ($ipnMessage->validate()) {
                $containerHelper = new Crowdfunding\Container\Helper();
                $currency        = $containerHelper->fetchCurrency($this->container, $params);

                $rawData        = $ipnMessage->getRawData();
                $preApprovalKey = ArrayHelper::getValue($rawData, 'preapproval_key');

                // Get payment session data
                $paymentSession = $this->getPaymentSession(['token' => $preApprovalKey], Prism\Constants::NOT_LEGACY);

                // DEBUG DATA
                JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_SESSION'), $this->debugType, $paymentSession->getProperties()) : null;

                // Get project
                $project = $containerHelper->fetchProject($this->container, $paymentSession->getProjectId());
                if (!$project->getId()) {
                    $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_PROJECT'), $this->debugType, $paymentSession->getProperties());
                    return;
                }

                // Validate transaction data
                $transactionData = $this->prepareTransactionData($rawData, $project->getUserId(), $paymentSession);
                $result          = $this->validateData($transactionData, $currency->getCode());
                if ($result === Prism\Constants::INVALID) {
                    return;
                }

                // DEBUG DATA
                JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_VALID_DATA'), $this->debugType, $transactionData->getProperties()) : null;

                // Get reward object.
                $reward = null;
                if ($transactionData->getRewardId()) {
                    $reward = $containerHelper->fetchReward($this->container, $transactionData->getRewardId(), $project->getId());
                }

                // Save transaction data.
                // If it is not completed, return empty results.
                // If it is complete, continue with process transaction data
                $transaction = $this->createNewTransaction($transactionData, $paymentSession);
                if ($transaction === null) {
                    return;
                }

                $paymentResult->transaction = $transaction;
                $paymentResult->project     = $project;

                if ($reward !== null and ($reward instanceof Crowdfunding\Reward)) {
                    $paymentResult->reward = $reward;
                }

                $paymentResult->paymentSession = $paymentSession;

                // Do not remove session records.
                $paymentResult->skipEvent(PaymentResult::EVENT_AFTER_PAYMENT);

                // DEBUG DATA
                JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESULT_DATA'), $this->debugType, $paymentResult->getProperties()) : null;

                // Removing intention.
                $this->removeIntention($paymentSession, $transaction);
            }
        } catch (Exception $e) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'), $this->debugType, array('error message' => $e->getMessage(), '_POST' => $_POST));
        }
    }

    /**
     * Process instant notification data from PayPal when do PAY.
     * This method updates transaction record.
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     *
     * @todo Send message when capture an amount.
     */
    protected function processPay()
    {
        $postData    = file_get_contents('php://input');
        $ipnMessage  = new PPIPNMessage($postData, $this->getIpnConfig());

        $rawPostData = $ipnMessage->getRawData();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESPONSE'), $this->debugType, $_POST) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_ROW_DATA'), $this->debugType, $ipnMessage->getRawData()) : null;

        try {
            if ($ipnMessage->validate()) {
                $rawPostData = $this->parseTransactionResponse($rawPostData);
                JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PARSED_ROW_DATA'), $this->debugType, $rawPostData) : null;

                // Filter the raw data that comes from POST request.
                $rawPostDataFiltered = array();
                if (count($rawPostData) > 0) {
                    $rawPostDataFiltered = $this->filterRawPostTransaction($rawPostData);
                }

                JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_FILTERED_RAW_POST'), $this->debugType, $rawPostDataFiltered) : null;
                unset($rawPostData);

                // Validate transaction data
                $transaction = $this->completeTransaction($rawPostDataFiltered);
            }

        } catch (Exception $e) {
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'),
                $this->debugType,
                array(
                    'error message' => $e->getMessage(),
                    '_POST'         => $_POST,
                    'RAW POST'      => file_get_contents('php://input')
                )
            );
        }
    }

    /**
     * Process instant notification data from PayPal when void.
     * This method updates transaction record after Void.
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     *
     * @todo Send message when cancel a transaction.
     */
    protected function processCanceled()
    {
        $postData    = file_get_contents('php://input');
        $ipnMessage  = new PPIPNMessage($postData, $this->getIpnConfig());

        $rawPostData = $ipnMessage->getRawData();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESPONSE'), $this->debugType, $_POST) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_ROW_DATA'), $this->debugType, $ipnMessage->getRawData()) : null;

        try {
            if ($ipnMessage->validate()) {
                // Filter the raw data that comes from POST request.
                $rawPostDataFiltered = array();
                if (count($rawPostData) > 0) {
                    $rawPostDataFiltered = $this->filterRawPostTransaction($rawPostData);
                }

                JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_FILTERED_RAW_POST'), $this->debugType, $rawPostDataFiltered) : null;
                unset($rawPostData);

                // Validate transaction data
                $transaction = $this->cancelTransaction($rawPostDataFiltered);
            }
        } catch (Exception $e) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'), $this->debugType, array('error message' => $e->getMessage(), '_POST' => $_POST));
        }
    }

    /**
     * Prepare transaction data that comes from PayPal.
     *
     * @param array          $data
     * @param int            $receiverId
     * @param PaymentSession $paymentSession
     *
     * @throws \InvalidArgumentException
     * @return PaymentTransaction
     */
    protected function prepareTransactionData($data, $receiverId, $paymentSession)
    {
        $date = new JDate();

        // Prepare additional information about transaction that will be added to the transaction record.
        $extraData = $this->prepareNotificationExtraData(
            $data,
            JText::_($this->textPrefix . '_RESPONSE_NOTE_NOTIFICATION')
        );

        // Prepare transaction data
        $transactionData = new PaymentTransaction(
            [
                'investor_id'      => (int)$paymentSession->getUserId(),
                'receiver_id'      => (int)$receiverId,
                'project_id'       => (int)$paymentSession->getProjectId(),
                'reward_id'        => (int)$paymentSession->getRewardId(),
                'service_provider' => $this->serviceProvider,
                'service_alias'    => $this->serviceAlias,
                'txn_id'           => ArrayHelper::getValue($data, 'preapproval_key'),
                'txn_amount'       => ArrayHelper::getValue($data, 'max_total_amount_of_all_payments', 0, 'float'),
                'txn_currency'     => ArrayHelper::getValue($data, 'currency_code', '', 'string'),
                'txn_status'       => $this->getPaymentStatus($data),
                'txn_date'         => $date->toSql(),
                'status_reason'    => $this->getStatusReason($data),
                'extra_data'       => $extraData
            ]
        );

        return $transactionData;
    }

    /**
     * Validate transaction data.
     *
     * @param PaymentTransaction $transactionData
     * @param string             $currency
     *
     * @return bool
     */
    protected function validateData(PaymentTransaction $transactionData, $currency)
    {
        if (!$transactionData->getProjectId()) {
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_PROJECT_ID'),
                $this->debugType,
                $transactionData
            );

            return Prism\Constants::INVALID;
        }

        if (!$transactionData->getTxnId()) {
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_ID'),
                $this->debugType,
                $transactionData
            );

            return Prism\Constants::INVALID;
        }

        if (strcmp($transactionData->getTxnCurrency(), $currency) !== 0) {
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_CURRENCY'),
                $this->debugType,
                array('TRANSACTION DATA' => $transactionData, 'CURRENCY' => $currency)
            );

            return Prism\Constants::INVALID;
        }

        return Prism\Constants::VALID;
    }

    /**
     * Process transaction via IPN listener.
     *
     * @param PaymentTransaction $transactionData
     * @param PaymentSession     $paymentSession
     *
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     *
     * @return null|Transaction
     */
    protected function createNewTransaction(PaymentTransaction $transactionData, PaymentSession $paymentSession)
    {
        // Get transaction by ID
        $transaction = new Transaction(JFactory::getDbo());
        $transaction->load(array('txn_id' => $transactionData->getTxnId()));

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_OBJECT'), $this->debugType, $transaction->getProperties()) : null;

        // If the current status is already completed,
        // stop the process to prevent overwriting data.
        if ($transaction->getId() && $transaction->isCompleted()) {
            return null;
        }

        // IMPORTANT: It must be placed before hydrating data to the object.
        $options = array(
            'old_status' => $transaction->getStatus(),
            'new_status' => $transactionData->getTxnStatus()
        );

        $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_STATUSES'), $this->debugType, $options);

        // Create the new transaction record if there is not record.
        $transaction
            ->setReceiverId($transactionData->getReceiverId())
            ->setInvestorId($transactionData->getInvestorId())
            ->setProjectId($transactionData->getProjectId())
            ->setRewardId($transactionData->getRewardId())
            ->setServiceProvider($transactionData->getServiceProvider())
            ->setServiceAlias($transactionData->getServiceAlias())
            ->setTxnId($transactionData->getTxnId())
            ->setParentTxnId($transactionData->getParentTxnId())
            ->setTxnAmount($transactionData->getTxnAmount())
            ->setTxnCurrency($transactionData->getTxnCurrency())
            ->setTxnStatus($transactionData->getTxnStatus())
            ->setTxnDate($transactionData->getTxnDate())
            ->setStatusReason($transactionData->getStatusReason());

        $transaction->setParam('capture_period', [
            'start' => $paymentSession->service($this->serviceAlias)->data('starting_date'),
            'end'   => $paymentSession->service($this->serviceAlias)->data('ending_date')
        ]);

        // Start database transaction.
        $db = JFactory::getDbo();

        try {
            $db->transactionStart();

            $transactionManager = new TransactionManager($db);
            $transactionManager->setTransaction($transaction);
            $transactionManager->process('com_crowdfunding.payment', $options);

            $db->transactionCommit();
        } catch (Exception $e) {
            $db->transactionRollback();

            $this->log->add(JText::_($this->textPrefix . '_ERROR_TRANSACTION_PROCESS'), $this->errorType, $e->getMessage());

            return null;
        }

        return $transaction;
    }

    /**
     * Update transaction record using a data that comes for PayPal Adaptive PAY notifications.
     *
     * @param array $rawPostDataFiltered
     *
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     *
     * @return Transaction
     */
    protected function completeTransaction($rawPostDataFiltered)
    {
        // Get transaction by ID
        $keys = array(
            'txn_id' => ArrayHelper::getValue($rawPostDataFiltered, 'preapproval_key')
        );

        $transaction = new Transaction(JFactory::getDbo());
        $transaction->load($keys);

        // Get additional information from transaction.
        $extraData = $this->prepareNotificationExtraData($rawPostDataFiltered, JText::_($this->textPrefix . '_RESPONSE_NOTE_NOTIFICATION'));
        if (count($extraData) > 0) {
            $transaction->addExtraData($extraData);
        }

        // Prepare the new status.
        $newStatus = $this->getPaymentStatus($rawPostDataFiltered);

        $options = array(
            'old_status' => $transaction->getStatus(),
            'new_status' => $newStatus
        );

        $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_STATUSES'), $this->debugType, $options);

        // Set the status and reset status reason.
        $transaction->setStatus($newStatus);
        $transaction->setStatusReason('');

        // Set the new transaction number.
        $payKey         = array_key_exists('pay_key', $rawPostDataFiltered) ? $rawPostDataFiltered['pay_key'] : null;
        $preapprovalKey = array_key_exists('preapproval_key', $rawPostDataFiltered) ? $rawPostDataFiltered['preapproval_key'] : null;
        if (($payKey && $preapprovalKey) && ($preapprovalKey === $transaction->getTxnId()) && strcmp($newStatus, Prism\Constants::PAYMENT_STATUS_COMPLETED) === 0) {
            $transaction->setParentTxnId($preapprovalKey);
            $transaction->setTxnId($payKey);

            // DEBUG
            $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_OBJECT'), $this->debugType, $transaction->getProperties());
        }

        // Start database transaction.
        $db = JFactory::getDbo();

        try {
            $db->transactionStart();

            $transactionManager = new TransactionManager($db);
            $transactionManager->setTransaction($transaction);
            $transactionManager->process('com_crowdfunding.payment', $options);

            $db->transactionCommit();
        } catch (Exception $e) {
            $db->transactionRollback();
            $this->log->add(JText::_($this->textPrefix . '_ERROR_TRANSACTION_PROCESS'), $this->errorType, $e->getMessage());
        }

        return $transaction;
    }

    /**
     * Update transaction record to canceled.
     *
     * @param array $rawPostDataFiltered
     *
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     *
     * @return Transaction
     */
    protected function cancelTransaction($rawPostDataFiltered)
    {
        // Get transaction by ID
        $keys = array(
            'txn_id' => ArrayHelper::getValue($rawPostDataFiltered, 'preapproval_key')
        );

        $transaction = new Transaction(JFactory::getDbo());
        $transaction->load($keys);

        // Get additional information from transaction.
        $extraData = $this->prepareNotificationExtraData($rawPostDataFiltered, JText::_($this->textPrefix . '_RESPONSE_NOTE_NOTIFICATION'));
        if (count($extraData) > 0) {
            $transaction->addExtraData($extraData);
        }

        // Prepare the new status.
        $newStatus = $this->getPaymentStatus($rawPostDataFiltered);

        $options = array(
            'old_status' => $transaction->getStatus(),
            'new_status' => $newStatus
        );

        $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_STATUSES'), $this->debugType, $options);

        // Set the status and reset status reason.
        $transaction->setStatus($newStatus);
        $transaction->setStatusReason('');

        // Start database transaction.
        $db = JFactory::getDbo();

        try {
            $db->transactionStart();

            $transactionManager = new TransactionManager($db);
            $transactionManager->setTransaction($transaction);
            $transactionManager->process('com_crowdfunding.payment', $options);

            $db->transactionCommit();
        } catch (Exception $e) {
            $db->transactionRollback();
            $this->log->add(JText::_($this->textPrefix . '_ERROR_TRANSACTION_PROCESS'), $this->errorType, $e->getMessage());
        }

        return $transaction;
    }

    protected function prepareLocale(&$html)
    {
        // Get country
        $countryId = $this->params->get('paypal_country');
        $country   = new Country(JFactory::getDbo());
        $country->load($countryId);

        $code  = $country->getCode();
        $code4 = $country->getLocale();

        $button    = $this->params->get('paypal_button_type', 'btn_buynow_LG');
        $buttonUrl = $this->params->get('paypal_button_url');

        // Generate a button
        if (!$this->params->get('paypal_button_default', 0)) {
            if (!$buttonUrl) {
                if (strcmp('US', $code) === 0) {
                    $html[] = '<input type="image" name="submit" border="0" src="https://www.paypalobjects.com/' . $code4 . '/i/btn/' . $button . '.gif" alt="' . JText::_($this->textPrefix . '_BUTTON_ALT') . '" />';
                } else {
                    $html[] = '<input type="image" name="submit" border="0" src="https://www.paypalobjects.com/' . $code4 . '/' . $code . '/i/btn/' . $button . '.gif" alt="' . JText::_($this->textPrefix . '_BUTTON_ALT') . '" />';
                }
            } else {
                $html[] = '<input type="image" name="submit" border="0" src="' . $buttonUrl . '" alt="' . JText::_($this->textPrefix . '_BUTTON_ALT') . '">';
            }

        } else { // Default button
            $html[] = '<input type="image" name="submit" border="0" src="https://www.paypalobjects.com/en_US/i/btn/' . $button . '.gif" alt="' . JText::_($this->textPrefix . '_BUTTON_ALT') . '" />';
        }

        // Set locale
        $html[] = '<input type="hidden" name="lc" value="' . $code . '" />';
    }

    protected function getStatusReason($data)
    {
        $result = '';

        $transactionType = ArrayHelper::getValue($data, 'transaction_type');

        if (strcmp($transactionType, 'Adaptive Payment PREAPPROVAL') === 0) {
            $result = 'preapproval';
        }

        return $result;
    }

    protected function getPaymentStatus($data)
    {
        $result          = '';

        $transactionType = ArrayHelper::getValue($data, 'transaction_type');
        $status          = ArrayHelper::getValue($data, 'status');

        if (strcmp($transactionType, 'Adaptive Payment PREAPPROVAL') === 0) {
            switch ($status) {
                case 'ACTIVE':
                    $approved = ArrayHelper::getValue($data, 'approved', false, 'bool');
                    if ($approved) {
                        $result = 'pending';
                    }
                    break;

                case 'COMPLETED':
                    $result = 'completed';
                    break;

                case 'CANCELED':
                    $result = 'canceled';
                    break;
            }

        } elseif (strcmp($transactionType, 'Adaptive Payment PAY') === 0) {
            switch ($status) {
                case 'COMPLETED':
                    $result = 'completed';
                    break;
            }
        }

        return $result;
    }

    /**
     * Prepare additional data that will be stored to the transaction record.
     * This data will be used as additional information about curren transaction.
     * It is processed by the event "onPaymentNotify".
     *
     * @param array  $data
     * @param string $note
     *
     * @return array
     */
    protected function prepareNotificationExtraData($data, $note = '')
    {
        $date        = new JDate();
        $trackingKey = $date->toUnix();

        $extraData = array(
            $trackingKey => array()
        );

        $keys = array(
            'payment_request_date', 'action_type', 'transaction_type', 'sender_email',
            'starting_date', 'ending_date', 'max_number_of_payments', 'max_amount_per_payment',
            'max_total_amount_of_all_payments', 'current_total_amount_of_all_payments', 'currency_code',
            'transaction', 'preapproval_key', 'approved', 'day_of_week', 'status', 'current_period_attempts',
            'pay_key', 'fees_payer', 'pin_type', 'payment_period', 'notify_version', 'charset',
            'log_default_shipping_address_in_transaction', 'reverse_all_parallel_payments_on_error',
            'memo',
        );

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $extraData[$trackingKey][$key] = $data[$key];
            }
        }

        // Set a note.
        if ($note !== '') {
            $extraData[$trackingKey]['NOTE'] = $note;
        }

        return $extraData;
    }

    /**
     * Prepare extra data that should be stored to database record after Pay response.
     *
     * @param PPMessage $response
     * @param string $note
     *
     * @return array
     */
    protected function prepareExtraDataPaypalResponse(PPMessage $response, $note = '')
    {
        $date        = new JDate();
        $trackingKey = $date->toUnix();

        $extraData = array(
            $trackingKey => array(
                'Acknowledgement Status' => isset($response->responseEnvelope->ack) ? $response->responseEnvelope->ack : '',
                'Timestamp'              => isset($response->responseEnvelope->timestamp) ? $response->responseEnvelope->timestamp : '',
                'Correlation ID'         => isset($response->responseEnvelope->correlationId) ? $response->responseEnvelope->correlationId : '',
                'NOTE'                   => $note
            )
        );

        return $extraData;
    }

    /**
     * Prepare credentials for sandbox or for the live server.
     *
     * @return array
     */
    protected function getAccountConfig()
    {
        $config = [];

        if ($this->params->get('paypal_sandbox', Prism\Constants::ENABLED)) {
            $config['mode']            = 'sandbox';
            $config['acct1.UserName']  = StringHelper::trim($this->params->get('paypal_sandbox_api_username'));
            $config['acct1.Password']  = StringHelper::trim($this->params->get('paypal_sandbox_api_password'));
            $config['acct1.Signature'] = StringHelper::trim($this->params->get('paypal_sandbox_api_signature'));
            $config['acct1.AppId']     = StringHelper::trim($this->params->get('paypal_sandbox_app_id'));
        } else {
            $config['mode']            = 'live';
            $config['acct1.UserName']  = StringHelper::trim($this->params->get('paypal_api_username'));
            $config['acct1.Password']  = StringHelper::trim($this->params->get('paypal_api_password'));
            $config['acct1.Signature'] = StringHelper::trim($this->params->get('paypal_api_signature'));
            $config['acct1.AppId']     = StringHelper::trim($this->params->get('paypal_app_id'));
        }

        return $config;
    }

    /**
     * Prepare the config for IPN message checker.
     *
     * @return array
     */
    protected function getIpnConfig()
    {
        $config = [];

        if ($this->params->get('paypal_sandbox', Prism\Constants::ENABLED)) {
            $config['mode'] = 'sandbox';
        } else {
            $config['mode'] = 'live';
        }

        return $config;
    }

    /**
     * This method prepares the list with amount receivers.
     *
     * @param stdClass $item
     * @param float    $fee
     * @param string   $paymentType
     *
     * @return array
     * @throws RuntimeException
     */
    public function prepareReceiverList($item, $fee, $paymentType)
    {
        $receiverIndex  = 0;

        $receivers       = array();

        $siteOwnerAmount = $item->txn_amount;

        // Payment types that must be used with fees.
        $feesPaymentTypes = array('parallel', 'chained');

        // If there is NO fees and it is not SIMPLE payment type,
        // throw an exception, because there is no logic to
        // process parallel or chained transaction without fee for receiving.
        if (in_array($paymentType, $feesPaymentTypes, true) && !$fee) {
            throw new RuntimeException(JText::_($this->textPrefix . '_ERROR_FEES_NOT_SET'));
        }

        // If it is parallel or chained payment type,
        // the user must provide his PayPal account.
        // He must provide an email using Crowdfunding Finance.
        if (in_array($paymentType, $feesPaymentTypes, true)) {
            $payout = new Crowdfundingfinance\Payout(JFactory::getDbo());
            $payout->load(['project_id' => $item->project_id], ['secret_key' => $this->app->get('secret')]);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYOUT_DATA'), $this->debugType, $payout->getProperties()) : null;

            $receiverEmail = $payout->getPaypalEmail();
            if ($receiverEmail !== '') {
                switch ($paymentType) {
                    case 'chained':
                        // Set the amount that the project owner will receive.
                        $projectOwnerAmount = $siteOwnerAmount;

                        // Set the amount that the site owner will receive.
                        $siteOwnerAmount = $fee;

                        // Prepare primary receiver.
                        $receivers[$receiverIndex]          = new \PayPal\Types\AP\Receiver();
                        $receivers[$receiverIndex]->email   = $receiverEmail;
                        $receivers[$receiverIndex]->amount  = round($projectOwnerAmount, 2);
                        $receivers[$receiverIndex]->primary = true;
                        $receiverIndex++;
                        break;

                    case 'parallel':
                        // Set the amount that the project owner will receive.
                        $projectOwnerAmount = $siteOwnerAmount - $fee;

                        // Set the amount that the site owner will receive.
                        $siteOwnerAmount = $fee;

                        $receivers[$receiverIndex]          = new \PayPal\Types\AP\Receiver();
                        $receivers[$receiverIndex]->email   = $receiverEmail;
                        $receivers[$receiverIndex]->amount  = round($projectOwnerAmount, 2);
                        $receivers[$receiverIndex]->primary = false;

                        $receiverIndex++;
                        break;
                }
            }
        }

        // If the payment type is parallel or chained,
        // project owner must set himself as receiver.
        // If there is not receiver, throw an exception.
        if (in_array($paymentType, $feesPaymentTypes, true) && count($receivers) === 0) {
            throw new RuntimeException(JText::_($this->textPrefix . '_ERROR_INVALID_FIRST_RECEIVER'));
        }

        // Prepare site owner as fee receiver.
        $receivers[$receiverIndex]          = new \PayPal\Types\AP\Receiver();
        $receivers[$receiverIndex]->email   = $this->params->get('paypal_sandbox', Prism\Constants::YES) ? StringHelper::trim($this->params->get('paypal_sandbox_receiver_email')) : StringHelper::trim($this->params->get('paypal_receiver_email'));
        $receivers[$receiverIndex]->amount  = round($siteOwnerAmount, 2);
        $receivers[$receiverIndex]->primary = false;

        return $receivers;
    }

    /**
     * Filter the raw transaction data.
     *
     * @param array $data
     *
     * @return array
     */
    protected function filterRawPostTransaction($data)
    {
        $filter = JFilterInput::getInstance();

        $result = array();

        foreach ($data as $key => $value) {
            $key = $filter->clean($key);
            if (is_array($value)) {
                $result[$key] = $this->filterRawPostTransaction($value);
            } else {
                $result[$key] = $filter->clean($value);
            }
        }

        return $result;
    }

    /**
     * Parse raw POST data and extract transaction one.
     *
     * @param array $data
     *
     * @return array
     */
    protected function parseTransactionResponse(array $data)
    {
        $transactions = array();

        foreach ($data as $key => $value) {
            $key_ = rawurldecode($key);
            if (false !== strpos($key_, 'transaction[')) {
                preg_match("/\[([^\]]*)\]\.(\w+)$/i", $key_, $matches);

                if (array_key_exists(1, $matches)) {
                    if (!array_key_exists($matches[1], $transactions) || !is_array($transactions[$matches[1]])) {
                        $transactions[$matches[1]] = array();
                    }

                    // Add the value to a property.
                    if (!empty($matches[2])) {
                        $transactions[$matches[1]][$matches[2]] = $value;
                    }
                }

                unset($data[$key]);
            }
        }

        $data['transaction'] = $transactions;

        return $data;
    }
}
