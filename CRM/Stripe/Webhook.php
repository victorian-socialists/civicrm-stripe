<?php

use CRM_Stripe_ExtensionUtil as E;

class CRM_Stripe_Webhook {

  use CRM_Stripe_Webhook_Trait;

  /**
   * Checks whether the payment processors have a correctly configured
   * webhook (we may want to check the test processors too, at some point, but
   * for now, avoid having false alerts that will annoy people).
   *
   * @see stripe_civicrm_check()
   */
  public static function check() {
    $result = civicrm_api3('PaymentProcessor', 'get', [
      'class_name' => 'Payment_Stripe',
      'is_active' => 1,
    ]);

    foreach ($result['values'] as $paymentProcessor) {
      $webhook_path = self::getWebhookPath(TRUE, $paymentProcessor['id']);

      \Stripe::setApiKey(CRM_Core_Payment_Stripe::getSecretKey($paymentProcessor));
      try {
        $webhooks = \Stripe\WebhookEndpoint::all(["limit" => 100]);
      }
      catch (Exception $e) {
        $error = $e->getMessage();
        $messages[] = new CRM_Utils_Check_Message(
          'stripe_webhook',
          E::ts('The %1 (%2) Payment Processor has an error: %3', [
            1 => $paymentProcessor['name'],
            2 => $paymentProcessor['id'],
            3 => $error,
          ]),
          E::ts('Stripe - API Key'),
          \Psr\Log\LogLevel::ERROR,
          'fa-money'
        );

        continue;
      }

      $found_wh = FALSE;
      foreach ($webhooks->data as $wh) {
        if ($wh->url == $webhook_path) {
          $found_wh = TRUE;
          // Check and update webhook
          self::checkAndUpdateWebhook($wh);
        }
      }

      if (!$found_wh) {
        try {
          self::createWebhook($paymentProcessor['id']);
        }
        catch (Exception $e) {
          $messages[] = new CRM_Utils_Check_Message(
            'stripe_webhook',
            E::ts('Could not create webhook. You can review from your Stripe account, under Developers > Webhooks.<br/>The webhook URL is: %3', [
              1 => $paymentProcessor['name'],
              2 => $paymentProcessor['id'],
              3 => urldecode($webhook_path),
            ]) . '.<br/>Error from Stripe: <em>' . $e->getMessage() . '</em>',
            E::ts('Stripe Webhook: %1 (%2)', [
                1 => $paymentProcessor['name'],
                2 => $paymentProcessor['id'],
              ]
            ),
            \Psr\Log\LogLevel::WARNING,
            'fa-money'
          );
        }
      }
    }
    return $messages;
  }

  /**
   * Create a new webhook for payment processor
   *
   * @param int $paymentProcessorId
   */
  public static function createWebhook($paymentProcessorId) {
    \Stripe\Stripe::setApiKey(CRM_Core_Payment_Stripe::getSecretKeyById($paymentProcessorId));

    $params = [
      'enabled_events' => self::getDefaultEnabledEvents(),
      'url' => self::getWebhookPath(TRUE, $paymentProcessorId),
      'api_version' => CRM_Core_Payment_Stripe::getApiVersion(),
      'connect' => FALSE,
    ];
    \Stripe\WebhookEndpoint::create($params);
  }

  /**
   * Check and update existing webhook
   *
   * @param array $webhook
   */
  public static function checkAndUpdateWebhook($webhook) {
    $update = FALSE;
    if ($webhook['api_version'] !== CRM_Core_Payment_Stripe::API_VERSION) {
      $update = TRUE;
      $params['api_version'] = CRM_Core_Payment_Stripe::API_VERSION;
    }
    if (array_diff(self::getDefaultEnabledEvents(), $webhook['enabled_events'])) {
      $update = TRUE;
      $params['enabled_events'] = self::getDefaultEnabledEvents();
    }
    if ($update) {
      \Stripe\WebhookEndpoint::update($webhook['id'], $params);
    }
  }

  /**
   * List of webhooks we currently handle
   * @return array
   */
  public static function getDefaultEnabledEvents() {
    return [
      'invoice.payment_succeeded',
      'invoice.payment_failed',
      'charge.failed',
      'charge.refunded',
      'charge.succeeded',
      'customer.subscription.updated',
      'customer.subscription.deleted',
    ];
  }

}
