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

use Joomla\Utilities\ArrayHelper;
use Joomla\String\StringHelper;
use Joomla\Registry\Registry;
use Crowdfunding\Payment;
use Crowdfunding\Transaction\Transaction;
use Crowdfunding\Transaction\TransactionManager;
use Crowdfunding\Country\Country;
use Prism\Payment\Result as PaymentResult;

jimport('Prism.init');
jimport('Crowdfunding.init');
jimport('Emailtemplates.init');
jimport('Crowdfundingfinance.init');

JObserverMapper::addObserverClassToClass(Crowdfunding\Observer\Transaction\TransactionObserver::class, Crowdfunding\Transaction\TransactionManager::class, array('typeAlias' => 'com_crowdfunding.payment'));

/**
 * Crowdfunding PayPal Adaptive payment plugin.
 *
 * @package      Crowdfunding
 * @subpackage   Plugins
 */
class plgCrowdfundingPaymentPayPalAdaptive extends Payment\Plugin
{
    protected $envelope = array(
        'errorLanguage' => 'en_US',
        'detailLevel' => 'returnAll'
    );

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
     * @param string                   $context This string gives information about that where it has been executed the trigger.
     * @param stdClass                   $item    A project data.
     * @param Registry $params  The parameters of the component
     *
     * @return null|string
     */
    public function onProjectPayment($context, $item, $params)
    {
        if (strcmp('com_crowdfunding.payment', $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        // This is a URI path to the plugin folder
        $pluginURI = 'plugins/crowdfundingpayment/paypalexpress';

        $html   = array();
        $html[] = '<div class="well">'; // Open "well".

        $html[] = '<h4><img src="' . $pluginURI . '/images/paypal_icon.png" width="36" height="32" alt="PayPal" />' . JText::_($this->textPrefix . '_TITLE') . '</h4>';
        $html[] = '<form action="/index.php?option=com_crowdfunding" method="post">';

        $html[] = '<input type="hidden" name="task" value="payments.checkout" />';
        $html[] = '<input type="hidden" name="payment_service" value="'.$this->serviceAlias.'" />';
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
        if (strcmp('com_crowdfunding.payments.checkout.'.$this->serviceAlias, $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        $result    = new PaymentResult();
        $result->triggerEvents = array();

        $notifyUrl = $this->getCallbackUrl();
        $cancelUrl = $this->getCancelUrl($item->slug, $item->catslug);
        $returnUrl = $this->getReturnUrl($item->slug, $item->catslug);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_NOTIFY_URL'), $this->debugType, $notifyUrl) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RETURN_URL'), $this->debugType, $returnUrl) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_CANCEL_URL'), $this->debugType, $cancelUrl) : null;

        // Get country and locale code.
        $countryId    = $this->params->get('paypal_country');

        $country = new Country(JFactory::getDbo());
        $country->load($countryId);

        // Create transport object.
        $options = new Registry;
        /** @var  $options Registry */

        $transport = new JHttpTransportCurl($options);
        $http      = new JHttp($options, $transport);

        // Create payment object.
        $options   = new Registry;
        /** @var  $options Registry */

        $options->set('urls.cancel', $cancelUrl);
        $options->set('urls.return', $returnUrl);
        $options->set('urls.notify', $notifyUrl);

        $this->prepareCredentials($options);

        // Get server IP address.
        /*$serverIP = $this->app->input->server->get("SERVER_ADDR");
        $options->set("credentials.ip_address", $serverIP);*/

        // Prepare starting and ending date.
        if (!$this->params->get('paypal_starting_date', 0)) { // End date of the campaign.
            $startingDate = new JDate(); // Today
            $startingDate->setTime(0, 0, 0); // At 00:00:00
        } else {
            $startingDate = new JDate($item->ending_date);
            $startingDate->modify('+1 day');
            $startingDate->setTime(0, 0, 0); // At 00:00:00
        }

        $endingDate   = new JDate($item->ending_date);
        $endingDate->modify('+10 days');

        $options->set('payment.starting_date', $startingDate->format(DATE_ATOM));
        $options->set('payment.ending_date', $endingDate->format(DATE_ATOM));

        $options->set('payment.max_amount', $item->amount);
        $options->set('payment.max_total_amount', $item->amount);
        $options->set('payment.number_of_payments', 1);
        $options->set('payment.currency_code', $item->currencyCode);

        $options->set('payment.fees_payer', $this->params->get('paypal_fees_payer'));
        $options->set('payment.ping_type', 'NOT_REQUIRED');

        $title = JText::sprintf($this->textPrefix . '_INVESTING_IN_S', htmlentities($item->title, ENT_QUOTES, 'UTF-8'));
        $options->set('payment.memo', $title);

        $options->set('request.envelope', $this->envelope);

        // Get payment session.

        $paymentSessionContext    = Crowdfunding\Constants::PAYMENT_SESSION_CONTEXT.$item->id;
        $paymentSessionLocal      = $this->app->getUserState($paymentSessionContext);

        $paymentSessionRemote = $this->getPaymentSession(array(
            'session_id'    => $paymentSessionLocal->session_id
        ));

        // Get API url.
        $apiUrl = $this->getApiUrl();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYPAL_ADAPTIVE_OPTIONS'), $this->debugType, $options->toArray()) : null;

        $adaptive = new Prism\Payment\PayPal\Adaptive($apiUrl, $options);
        $adaptive->setTransport($http);

        $response = $adaptive->doPreppproval();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYPAL_ADAPTIVE_RESPONSE'), $this->debugType, $response) : null;

        $preapprovalKey = $response->getPreApprovalKey();
        if (!$preapprovalKey) {
            return null;
        }

        // Store token to the payment session.
        $paymentSessionRemote->setUniqueKey($preapprovalKey);
        $paymentSessionRemote->setData('starting_date', $startingDate->format(DATE_ATOM));
        $paymentSessionRemote->setData('ending_date', $endingDate->format(DATE_ATOM));

        $paymentSessionRemote->store();

        // Get PayPal checkout URL.
        if (!$this->params->get('paypal_sandbox', 1)) {
            $result->redirectUrl = $this->params->get('paypal_url') . '?cmd=_ap-preapproval&preapprovalkey=' . rawurlencode($preapprovalKey);
        } else {
            $result->redirectUrl = $this->params->get('paypal_sandbox_url') . '?cmd=_ap-preapproval&preapprovalkey=' . rawurlencode($preapprovalKey);
        }

        return $result;
    }

    /**
     * Capture payments.
     *
     * @param string                   $context
     * @param stdClass                 $item
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
        $allowedContext = array('com_crowdfunding.payments.capture.'.$this->serviceAlias, 'com_crowdfundingfinance.payments.capture.'.$this->serviceAlias);
        if (!in_array($context, $allowedContext, true)) {
            return null;
        }

        if (!$this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        // Load project object and set "memo".
        $project = new Crowdfunding\Project(JFactory::getDbo());
        $project->load($item->project_id);

        $fundingType = $project->getFundingType();
        $fees = $this->getFees($fundingType);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_FEES'), $this->debugType, $fees) : null;

        // Create transport object.
        $transport = new JHttpTransportCurl(new Registry);
        $http      = new JHttp(new Registry, $transport);

        $notifyUrl = $this->getCallbackUrl();
        $cancelUrl = $this->getCancelUrl($project->getSlug(), $project->getCatSlug());
        $returnUrl = $this->getReturnUrl($project->getSlug(), $project->getCatSlug());

        // Prepare payment options.
        $options = new Registry;

        $options->set('urls.notify', $notifyUrl);
        $options->set('urls.cancel', $cancelUrl);
        $options->set('urls.return', $returnUrl);

        $this->prepareCredentials($options);

        $options->set('payment.action_type', 'PAY');
        $options->set('payment.preapproval_key', $item->txn_id);

        $options->set('payment.fees_payer', $this->params->get('paypal_fees_payer'));
        $options->set('payment.currency_code', $item->txn_currency);

        $options->set('request.envelope', $this->envelope);

        $title = JText::sprintf($this->textPrefix . '_INVESTING_IN_S', htmlentities($project->getTitle(), ENT_QUOTES, 'UTF-8'));
        $options->set('payment.memo', $title);
        
        // Get API url.
        $apiUrl = $this->getApiUrl();

        $fee = $this->calculateFee($fundingType, $fees, $item->txn_amount);

        // Get receiver list and set it to service options.
        $receiverList = $this->getReceiverList($item, $fee);
        $options->set('payment.receiver_list', $receiverList);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DOCAPTURE_OPTIONS'), $this->debugType, $options) : null;

        try {
            $adaptive = new Prism\Payment\PayPal\Adaptive($apiUrl, $options);
            $adaptive->setTransport($http);

            $response = $adaptive->doCapture();

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DOCAPTURE_RESPONSE'), $this->debugType, $response) : null;

            // Include extra data to transaction record.
            if ($response->isSuccess()) {
                $note      = JText::_($this->textPrefix . '_RESPONSE_NOTE_CAPTURE_PREAPPROVAL');
                $extraData = $this->prepareExtraData($response, $note);

                $transaction = new Transaction(JFactory::getDbo());
                $transaction->load($item->id);

                $transaction->setFee($fee);
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
     * @param string                   $context
     * @param stdClass                 $item
     * @param Registry $params
     *
     * @throws \RuntimeException
     *
     * @return array|null
     */
    public function onPaymentsVoid($context, &$item, &$params)
    {
        $allowedContext = array('com_crowdfunding.payments.void.'.$this->serviceAlias, 'com_crowdfundingfinance.payments.void.'.$this->serviceAlias);
        if (!in_array($context, $allowedContext, true)) {
            return null;
        }

        if (!$this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        // Create transport object.
        $transport = new JHttpTransportCurl(new Registry);
        $http      = new JHttp(new Registry, $transport);

        // Prepare payment options.
        $options   = new Registry;
        $options->set('payment.preapproval_key', $item->txn_id);
        $options->set('request.envelope', $this->envelope);

        $this->prepareCredentials($options);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DOVOID_OPTIONS'), $this->debugType, $options) : null;

        // Get API url.
        $apiUrl = $this->getApiUrl();

        try {
            $adaptive = new Prism\Payment\PayPal\Adaptive($apiUrl, $options);
            $adaptive->setTransport($http);

            $response = $adaptive->doVoid();

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DOVOID_RESPONSE'), $this->debugType, $response) : null;

            // Include extra data to transaction record.
            if ($response->isSuccess()) {
                $note = JText::_($this->textPrefix.'_RESPONSE_NOTE_CANCEL_PREAPPROVAL');
                $extraData = $this->prepareExtraData($response, $note);

                $transaction = new Transaction(JFactory::getDbo());
                $transaction->load($item->id);

                $transaction->addExtraData($extraData);
                $transaction->updateExtraData();

                // DEBUG DATA
                JDEBUG ? $this->log->add(JText::_($this->textPrefix.'_DEBUG_DOVOID_TRANSACTION'), $this->debugType, $transaction->getProperties()) : null;
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
        if (strcmp('com_crowdfunding.notify.'.$this->serviceAlias, $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
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

        // Get PayPal URL
        if ($this->params->get('paypal_sandbox', 1)) {
            $url = $this->params->get('paypal_sandbox_url', 'https://www.sandbox.paypal.com/cgi-bin/webscr');
        } else {
            $url = $this->params->get('paypal_url', 'https://www.paypal.com/cgi-bin/webscr');
        }

        // Prepare the array that will be returned by this method
        $paymentResult                  = new PaymentResult;
        $paymentResult->serviceProvider = $this->serviceProvider;
        $paymentResult->serviceAlias    = $this->serviceAlias;

        $transactionType = ArrayHelper::getValue($_POST, 'transaction_type');
        switch ($transactionType) {
            case 'Adaptive Payment PREAPPROVAL':
                $this->processPreApproval($paymentResult, $url, $params);
                break;

            case 'Adaptive Payment PAY':
                $this->processPay($url);
                break;

            default:
                $paymentResult = null;
                break;
        }

        return $paymentResult;
    }

    /**
     * Process preapproval notification data from PayPal.
     *
     * @param PaymentResult $paymentResult
     * @param string $url  The parameters of the component
     * @param Registry $params  The parameters of the component
     *
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     * @throws \RuntimeException
     * @throws \OutOfBoundsException
     *
     * @return null
     */
    protected function processPreApproval($paymentResult, $url, $params)
    {
        $loadCertificate = (bool)$this->params->get('paypal_load_certificate', 0);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESPONSE'), $this->debugType, $_POST) : null;

        $paypalIpn  = new Prism\Payment\PayPal\Ipn($url, $_POST);
        $paypalIpn->verify($loadCertificate);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_IPN_OBJECT'), $this->debugType, $paypalIpn) : null;

        if ($paypalIpn->isVerified()) {
            $containerHelper  = new Crowdfunding\Container\Helper();
            $currency         = $containerHelper->fetchCurrency($this->container, $params);

            $preApprovalKey   = ArrayHelper::getValue($_POST, 'preapproval_key');

            // Get payment session data
            $keys = array(
                'unique_key' => $preApprovalKey
            );
            $paymentSessionRemote = $this->getPaymentSession($keys);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_SESSION'), $this->debugType, $paymentSessionRemote->getProperties()) : null;

            // Validate transaction data
            $validData = $this->validateData($_POST, $currency->getCode(), $paymentSessionRemote);
            if ($validData === null) {
                return null;
            }

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_VALID_DATA'), $this->debugType, $validData) : null;

            // Get project and
            $project = $containerHelper->fetchProject($this->container, $validData['project_id']);
            
            // Check for valid project
            if (!$project->getId()) {
                $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_PROJECT'), $this->debugType, $validData);
                return null;
            }

            // Set the receiver ID to transaction data.
            $validData['receiver_id'] = $project->getUserId();

            // Get reward object.
            $reward = null;
            if ($validData['reward_id']) {
                $reward = $containerHelper->fetchReward($this->container, $validData['reward_id'], $project->getId());
            }
            
            // Set the receiver of funds
            $validData['receiver_id'] = $project->getUserId();

            // Save transaction data.
            // If it is not completed, return empty results.
            // If it is complete, continue with process transaction data
            $transaction = $this->storeTransaction($validData, $preApprovalKey, $paymentSessionRemote);
            if ($transaction === null) {
                return null;
            }

            $paymentResult->transaction = $transaction;
            $paymentResult->project     = $project;

            if ($reward !== null and ($reward instanceof Crowdfunding\Reward)) {
                $paymentResult->reward = $reward;
            }
            
            $paymentResult->paymentSession = $paymentSessionRemote;

            // Do not remove session records.
            $paymentResult->triggerEvents['AfterPayment'] = false;

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESULT_DATA'), $this->debugType, $paymentResult) : null;

            // Removing intention.
            $this->removeIntention($paymentSessionRemote, $transaction);

        } else {
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'),
                $this->debugType,
                array('error message' => $paypalIpn->getError(), 'paypalIPN' => $paypalIpn, '_POST' => $_POST)
            );
        }
    }

    /**
    * Process PAY notification data from PayPal.
    * This method updates transaction record.
    *
    * @param string $url  The parameters of the component
    *
    * @throws \InvalidArgumentException
    * @throws \RuntimeException
    */
    protected function processPay($url)
    {
        $loadCertificate = (bool)$this->params->get('paypal_load_certificate', 0);

        // Get raw post data and parse it.
        $rowPostString   = file_get_contents('php://input');

        $rawPost = Prism\Utilities\StringHelper::parseNameValue($rowPostString);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESPONSE'), $this->debugType, $_POST) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESPONSE_INPUT'), $this->debugType, $rawPost) : null;

        $paypalIpn       = new Prism\Payment\PayPal\Ipn($url, $rawPost);
        $paypalIpn->verify($loadCertificate);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_IPN_OBJECT'), $this->debugType, $paypalIpn) : null;

        if ($paypalIpn->isVerified()) {
            // Parse raw post transaction data.
            $rawPostTransaction = $paypalIpn->getTransactionData();
            if (count($rawPostTransaction) !== 0) {
                $_POST['transaction'] = $this->filterRawPostTransaction($rawPostTransaction);
            }

            JDEBUG ? $this->log->add(JText::_('PLG_CROWDFUNDINGPAYMENT_PAYPALADAPTIVE_DEBUG_FILTERED_RAW_POST'), $this->debugType, $_POST) : null;
            unset($rawPostTransaction, $rawPost);

            $preApprovalKey = ArrayHelper::getValue($_POST, 'preapproval_key');

            // Validate transaction data
            $this->updateTransactionDataOnPay($_POST, $preApprovalKey);

        } else {
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'),
                $this->debugType,
                array('error message' => $paypalIpn->getError(), 'paypalIPN' => $paypalIpn, '_POST' => $_POST, 'RAW POST' => $rawPost)
            );
        }
    }

    /**
     * Validate PayPal transaction
     *
     * @param array  $data
     * @param string $currency
     * @param Crowdfunding\Payment\Session $paymentSession
     *
     * @throws \InvalidArgumentException
     * @return array|null
     */
    protected function validateData($data, $currency, $paymentSession)
    {
        $date    = new JDate();

        // Get additional information from transaction.
        $extraData = $this->prepareNotificationExtraData($data, JText::_('PLG_CROWDFUNDINGPAYMENT_PAYPALADAPTIVE_RESPONSE_NOTE_NOTIFICATION'));

        // Prepare transaction data
        $transaction = array(
            'investor_id'      => (int)$paymentSession->getUserId(),
            'project_id'       => (int)$paymentSession->getProjectId(),
            'reward_id'        => $paymentSession->isAnonymous() ? 0 : (int)$paymentSession->getRewardId(),
            'service_provider' => $this->serviceProvider,
            'service_alias'    => $this->serviceAlias,
            'txn_id'           => ArrayHelper::getValue($data, 'preapproval_key'),
            'parent_txn_id'    => '',
            'txn_amount'       => ArrayHelper::getValue($data, 'max_total_amount_of_all_payments', 0, 'float'),
            'txn_currency'     => ArrayHelper::getValue($data, 'currency_code', '', 'string'),
            'txn_status'       => $this->getPaymentStatus($data),
            'txn_date'         => $date->toSql(),
            'status_reason'    => $this->getStatusReason($data),
            'extra_data'       => $extraData
        );

        // Check Project ID and Transaction ID
        if (!$transaction['project_id'] or !$transaction['txn_id']) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'), $this->debugType, $transaction);
            return null;
        }
        
        // Check currency
        if (strcmp($transaction['txn_currency'], $currency) !== 0) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_CURRENCY'), $this->debugType, array('TRANSACTION DATA' => $transaction, 'CURRENCY' => $currency));
            return null;
        }

        return $transaction;
    }


    /**
     * Update transaction record using a data that comes for PayPal Adaptive PAY notifications.
     *
     * @param array  $transactionData
     * @param string $preApprovalKey
     *
     * @throws \RuntimeException
     */
    protected function updateTransactionDataOnPay($transactionData, $preApprovalKey)
    {
        // Get transaction by ID
        $keys = array(
            'txn_id' => $preApprovalKey
        );

        $transaction = new Transaction(JFactory::getDbo());
        $transaction->load($keys);

        // Get additional information from transaction.
        $extraData = $this->prepareNotificationExtraData($transactionData, JText::_($this->textPrefix.'_RESPONSE_NOTE_NOTIFICATION'));
        if (count($extraData) !== 0) {
            $transaction->addExtraData($extraData);
        }

        // Prepare the new status.
        $newStatus = $this->getPaymentStatus($transactionData);

        // IMPORTANT: It must be placed before ->bind();
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
    }

    /**
     * Save transaction data.
     *
     * @param array  $transactionData
     * @param string $preApprovalKey
     * @param Crowdfunding\Payment\Session $paymentSessionRemote
     *
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     *
     * @return null|Transaction
     */
    protected function storeTransaction($transactionData, $preApprovalKey, $paymentSessionRemote)
    {
        // Get transaction by ID
        $transaction = new Transaction(JFactory::getDbo());
        $transaction->load(array('txn_id' => $preApprovalKey));

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_OBJECT'), $this->debugType, $transaction->getProperties()) : null;

        // Check for existed transaction record.
        if ($transaction->getId()) { // Update existed transaction record.

            // If the current status is completed,
            // stop the process to prevent overwriting data.
            if ($transaction->isCompleted()) {
                return null;
            }

            $txnStatus = ArrayHelper::getValue($transactionData, 'txn_status');

            switch ($txnStatus) {
                case 'completed':
                    $this->processCompleted($transaction, $transactionData);
                    break;

                case 'canceled':
                    $this->processVoided($transaction, $transactionData);
                    break;
            }

            return null;

        // Create new transaction record.
        } else {
            // Do not create new transaction record, if the payment process has been canceled on PayPal.
            // NOTE: PayPal sends information about payment when the backer click on button "Cancel".
            // It is not necessary to create transaction record when the payment has been canceled on PayPal.
            if (strcmp($transactionData['txn_status'], Prism\Constants::PAYMENT_STATUS_CANCELED) === 0) {
                return null;
            }

            // IMPORTANT: It must be placed before ->bind();
            $options = array(
                'old_status' => $transaction->getStatus(),
                'new_status' => $transactionData['txn_status']
            );

            $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_STATUSES'), $this->debugType, $options);
            
            // Create the new transaction record if there is not record.
            // If there is new record, store new data with new status.
            // Example: It has been 'pending' and now is 'completed'.
            // Example2: It has been 'pending' and now is 'failed'.
            $transaction->bind($transactionData);

            $transaction->setParam('capture_period', [
                'start' => $paymentSessionRemote->getData('starting_date'),
                'end'   => $paymentSessionRemote->getData('ending_date')
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
    }

    /**
     * @param Transaction $transaction
     * @param array  $transactionData
     *
     * @throws \RuntimeException
     */
    protected function processCompleted($transaction, $transactionData)
    {
        // Merge existed extra data with the new one.
        if (!empty($data['extra_data'])) {
            $transaction->addExtraData($data['extra_data']);
            unset($data['extra_data']);
        }

        // IMPORTANT: It must be placed before ->bind();
        $options = array(
            'old_status' => $transaction->getStatus(),
            'new_status' => Prism\Constants::PAYMENT_STATUS_COMPLETED
        );

        $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_STATUSES'), $this->debugType, $options);

        // Create the new transaction record if there is not record.
        // If there is new record, store new data with new status.
        // Example: It has been 'pending' and now is 'completed'.
        // Example2: It has been 'pending' and now is 'failed'.
        $transaction->bind($transactionData);

        // Remove the status reason.
        if ($transaction->isCompleted()) {
            $transaction->setStatusReason('');
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
    }

    /**
     * @param Transaction $transaction
     * @param array  $transactionData
     *
     * @throws \RuntimeException
     */
    protected function processVoided($transaction, $transactionData)
    {
        // It is possible only to void a transaction with status "pending".
        if (!$transaction->isPending()) {
            return;
        }

        // IMPORTANT: It must be placed before ->bind();
        $options = array(
            'old_status' => $transaction->getStatus(),
            'new_status' => Prism\Constants::PAYMENT_STATUS_CANCELED
        );

        $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_STATUSES'), $this->debugType, $options);

        // Create the new transaction record if there is not record.
        // If there is new record, store new data with new status.
        // Example: It has been 'pending' and now is 'completed'.
        // Example2: It has been 'pending' and now is 'failed'.
        $transaction->bind($transactionData);

        // Remove the status reason.
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
        $result = 'pending';

        $transactionType = ArrayHelper::getValue($data, 'transaction_type');
        $status = ArrayHelper::getValue($data, 'status');

        if (strcmp($transactionType, 'Adaptive Payment PREAPPROVAL') === 0) {
            switch ($status) {
                case 'ACTIVE':
                    $approved = ArrayHelper::getValue($data, 'approved', false, 'bool');
                    if ($approved) {
                        $result = 'pending';
                    }

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
     * @param array $data
     * @param string $note
     *
     * @return array
     */
    protected function prepareNotificationExtraData($data, $note = '')
    {
        $date = new JDate();
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
     * Prepare an extra data that should be stored to database record.
     *
     * @param Prism\Payment\PayPal\Adaptive\Response $data
     * @param string $note
     *
     * @return array
     */
    protected function prepareExtraData($data, $note = '')
    {
        $date = new JDate();
        $trackingKey = $date->toUnix();

        $extraData = array(
            $trackingKey => array(
                'Acknowledgement Status' => $data->getEnvelopeProperty('ack'),
                'Timestamp' => $data->getEnvelopeProperty('timestamp'),
                'Correlation ID' => $data->getEnvelopeProperty('correlationId'),
                'NOTE' => $note
            )
        );

        return $extraData;
    }

    /**
     * Prepare credentials for sandbox or for the live server.
     *
     * @param Registry $options
     */
    protected function prepareCredentials(Registry $options)
    {
        if ($this->params->get('paypal_sandbox', 1)) {
            $options->set('credentials.username', StringHelper::trim($this->params->get('paypal_sandbox_api_username')));
            $options->set('credentials.password', StringHelper::trim($this->params->get('paypal_sandbox_api_password')));
            $options->set('credentials.signature', StringHelper::trim($this->params->get('paypal_sandbox_api_signature')));
            $options->set('credentials.app_id', StringHelper::trim($this->params->get('paypal_sandbox_app_id')));
        } else {
            $options->set('credentials.username', StringHelper::trim($this->params->get('paypal_api_username')));
            $options->set('credentials.password', StringHelper::trim($this->params->get('paypal_api_password')));
            $options->set('credentials.signature', StringHelper::trim($this->params->get('paypal_api_signature')));
            $options->set('credentials.app_id', StringHelper::trim($this->params->get('paypal_app_id')));
        }
    }

    /**
     * This method prepares the list with amount receivers.
     *
     * @param stdClass $item
     * @param float $fee
     *
     * @return array
     * @throws RuntimeException
     */
    public function getReceiverList($item, $fee)
    {
        $receiverList = array();

        $siteOwnerAmount = $item->txn_amount;

        // Payment types that must be used with fees.
        $feesPaymentTypes = array('parallel', 'chained');

        // Get payment types.
        $paymentType = $this->params->get('paypal_payment_type', 'simple');

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_TYPE'), $this->debugType, $paymentType) : null;

        // If there is NO fees and it is not SIMPLE payment type,
        // return empty receiver list, because there is no logic to
        // process parallel or chained transaction without amount (a fee) for receiving.
        if (in_array($paymentType, $feesPaymentTypes, true) and !$fee) {
            throw new RuntimeException(JText::_($this->textPrefix . '_ERROR_FEES_NOT_SET'));
        }

        // If it is parallel or chained payment type,
        // the user must provide us his PayPal account.
        // He must provide us an email using Crowdfunding Finance.
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
                        $receiverList['receiver'][] = array(
                            'email'   => $receiverEmail,
                            'amount'  => round($projectOwnerAmount, 2),
                            'primary' => true
                        );

                        break;

                    case 'parallel':
                        // Set the amount that the project owner will receive.
                        $projectOwnerAmount = $siteOwnerAmount - $fee;

                        // Set the amount that the site owner will receive.
                        $siteOwnerAmount = $fee;

                        $receiverList['receiver'][] = array(
                            'email'   => $receiverEmail,
                            'amount'  => round($projectOwnerAmount, 2),
                            'primary' => false
                        );

                        break;
                }
            }
        }

        // If the payment type is parallel or chained,
        // the user must provide himself as receiver.
        // If receiver missing, return an empty array.
        if (in_array($paymentType, $feesPaymentTypes, true) and count($receiverList) === 0) {
            throw new RuntimeException(JText::_($this->textPrefix . '_ERROR_INVALID_FIRST_RECEIVER'));
        }

        // If the payment type is parallel or chained,
        // and there is a receiver but there is no fee ( the result of the calculation of fees is 0 ),
        // I will not continue. I will not set the site owner as receiver of fee, because the fee is 0.
        // There is no logic to set more receivers which have to receive amount 0.
        if (in_array($paymentType, $feesPaymentTypes, true) and !$fee) {
            return $receiverList;
        }

        if ($this->params->get('paypal_sandbox', 1)) { // Simple
            $receiverList['receiver'][] = array(
                'email'   => StringHelper::trim($this->params->get('paypal_sandbox_receiver_email')),
                'amount'  => round($siteOwnerAmount, 2),
                'primary' => false
            );
        } else {
            $receiverList['receiver'][] = array(
                'email'   => StringHelper::trim($this->params->get('paypal_receiver_email')),
                'amount'  => round($siteOwnerAmount, 2),
                'primary' => false
            );
        }

        return $receiverList;
    }

    /**
     * Return PayPal API URL.
     *
     * @return string
     */
    protected function getApiUrl()
    {
        if ($this->params->get('paypal_sandbox', 1)) {
            return StringHelper::trim($this->params->get('paypal_sandbox_api_url'));
        } else {
            return StringHelper::trim($this->params->get('paypal_api_url'));
        }
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
                /** @var array $value */
                foreach ($value as $k => $v) {
                    $value[$k] = $filter->clean($v);
                }

                $result[$key] = $value;
            } else {
                $result[$key] = $filter->clean($value);
            }
        }

        return $result;
    }
}
