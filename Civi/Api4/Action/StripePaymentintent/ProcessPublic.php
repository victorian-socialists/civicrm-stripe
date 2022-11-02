<?php

/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

namespace Civi\Api4\Action\StripePaymentintent;

use Brick\Math\RoundingMode;
use Brick\Money\Context\DefaultContext;
use Brick\Money\Money;

/**
 * Process a Stripe Intent with public/anonymous permissions
 *
 */
class ProcessPublic extends \Civi\Api4\Generic\AbstractAction {

  /**
   * The Stripe PaymentMethod ID
   *
   * @var string
   */
  protected $paymentMethodID = '';

  /**
   * The Stripe intent ID
   *
   * @var string
   */
  protected $intentID = '';

  /**
   * The amount formatted as eg. 12.10
   *
   * @var string
   */
  protected $amount = '';

  /**
   * Should we create a setupIntent?
   *
   * @var bool
   */
  protected $setup = FALSE;

  /**
   * The CiviCRM Payment Processor ID
   *
   * @var int
   */
  protected $paymentProcessorID;

  /**
   * An optional description to describe where the request came from
   *
   * @var string
   */
  protected $description = '';

  /**
   * The currency (eg. USD)
   *
   * @var string
   */
  protected $currency;

  /**
   * An array of extra data to store with the intent.
   *
   * @var string
   */
  protected $extraData = '';

  /**
   * A CSRF token
   *
   * @var string
   */
  protected $csrfToken = '';

  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function _run(\Civi\Api4\Generic\Result $result) {
    if (class_exists('\Civi\Firewall\Firewall')) {
      $firewall = new \Civi\Firewall\Firewall();
      if (!$firewall->checkIsCSRFTokenValid(\CRM_Utils_Type::validate($this->csrfToken, 'String'))) {
        throw new \CRM_Core_Exception($firewall->getReasonDescription());
      }
    }

    if (empty($this->amount) && !$this->setup) {
      \Civi::log('stripe')->error(__CLASS__ . 'missing amount and not capture or setup');
      throw new \CRM_Core_Exception('Bad request');
    }

    // If we configured a minimum allowed amount for processing check it here
    $minAmount = \Civi::settings()->get('stripe_minamount');
    if (!empty($minAmount)) {
      $moneyAmount = Money::of($this->amount, $this->currency, new DefaultContext(), RoundingMode::CEILING);
      $moneyMinAmount = Money::of($minAmount, $this->currency, new DefaultContext(), RoundingMode::CEILING);
      if ($moneyAmount->isLessThan($moneyMinAmount)) {
        \Civi::log('stripe')->error(__CLASS__ . 'amount: ' . $this->amount . ' is less than min_amount: ' . $minAmount);
        throw new \CRM_Core_Exception('Bad request');
      }
    }

    if (empty($this->paymentProcessorID)) {
      \Civi::log('stripe')->error(__CLASS__ . ' missing paymentProcessorID');
      throw new \CRM_Core_Exception('Bad request');
    }

    $intentProcessor = new \CRM_Stripe_PaymentIntent();
    $intentProcessor->setDescription($this->description);
    $intentProcessor->setReferrer($_SERVER['HTTP_REFERER'] ?? '');
    $intentProcessor->setExtraData($this->extraData ?? '');

    $processIntentParams = [
      'paymentProcessorID' => $this->paymentProcessorID,
      'setup' => $this->setup,
      'paymentMethodID' => $this->paymentMethodID,
      'intentID' => $this->intentID,
      'amount' => $this->amount,
      'currency' => $this->currency,
      'moto' => FALSE,
    ];

    $processIntentResult = $intentProcessor->processIntent($processIntentParams);

    if ($processIntentResult->ok) {
      $result->exchangeArray($processIntentResult->data);
    }
    else {
      throw new \CRM_Core_Exception($processIntentResult->message);
    }
  }

}
