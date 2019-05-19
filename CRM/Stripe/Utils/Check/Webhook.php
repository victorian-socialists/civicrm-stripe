<?php

use CRM_Stripe_ExtensionUtil as E;

class CRM_Stripe_Utils_Check_Webhook {

  /**
   * Checks whether the live Stripe processors have a correctly configured
   * webhook (we may want to check the test processors too, at some point, but
   * for now, avoid having false alerts that will annoy people).
   *
   * @see stripe_civicrm_check()
   */
  public static function check(&$messages) {
    $result = civicrm_api3('PaymentProcessor', 'get', [
      'class_name' => 'Payment_Stripe',
      'is_active' => 1,
      'is_test' => 0,
    ]);

    foreach ($result['values'] as $pp) {
      $sk = $pp['user_name'];

      $webhook_path = stripe_get_webhook_path(TRUE);
      $webhook_path = str_replace('NN', $pp['id'], $webhook_path);

      \Stripe\Stripe::setApiKey($sk);
      try {
        $webhooks = \Stripe\WebhookEndpoint::all(["limit" => 100]);
      }
      catch (Exception $e) {
        $error = $e->getMessage();
        $messages[] = new CRM_Utils_Check_Message(
          'stripe_webhook',
          E::ts('The %1 (%2) Payment Processor has an error: %3', [
            1 => $pp['name'],
            2 => $pp['id'],
            3 => $error,
          ]),
          E::ts('Stripe - API Key'),
          \Psr\Log\LogLevel::ERROR,
          'fa-money'
        );

        continue;
      }

      if (empty($webhooks->data)) {
        $messages[] = new CRM_Utils_Check_Message(
          'stripe_webhook',
          E::ts('The %1 (%2) Payment Processor does not have a webhook configured. This is only required for recurring contributions. You can review from your Stripe account, under Developers > Webhooks. The webhook URL is: %3', [
            1 => $pp['name'],
            2 => $pp['id'],
            3 => $webhook_path,
          ]),
          E::ts('Stripe - Webhook'),
          \Psr\Log\LogLevel::INFO,
          'fa-money'
        );

        continue;
      }

      $found_wh = FALSE;

      foreach ($webhooks->data as $wh) {
        if ($wh->url == $webhook_path) {
          $found_wh = TRUE;
        }
      }

      if ($found_wh) {
        $messages[] = new CRM_Utils_Check_Message(
          'stripe_webhook',
          E::ts('The %1 (%2) Payment Processor has a webhook configured (%3).', [
            1 => $pp['name'],
            2 => $pp['id'],
            3 => $webhook_path,
          ]),
          E::ts('Stripe - Webhook'),
          \Psr\Log\LogLevel::INFO,
          'fa-money'
        );
      }
      else {
        $messages[] = new CRM_Utils_Check_Message(
          'stripe_webhook',
          E::ts('The %1 (%2) Payment Processor does not have a valid webhook configured for this website. This is only required for recurring contributions. You can review from your Stripe account, under Developers > Webhooks. The webhook URL is: %3', [
            1 => $pp['name'],
            2 => $pp['id'],
            3 => urldecode($webhook_path),
          ]),
          E::ts('Stripe - Webhook'),
          \Psr\Log\LogLevel::WARNING,
          'fa-money'
        );
      }
    }
  }

}
