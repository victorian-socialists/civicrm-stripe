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

use CRM_Stripe_ExtensionUtil as E;

/**
 * Class CRM_Stripe_Webhook
 */
class CRM_Stripe_Webhook {

  use CRM_Mjwshared_WebhookTrait;

  /**
   * Checks whether the payment processors have a correctly configured webhook
   *
   * @see stripe_civicrm_check()
   *
   * @param array $messages
   * @param bool $attemptFix If TRUE, try to fix the webhook.
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function check(&$messages, $attemptFix = FALSE) {
    $result = civicrm_api3('PaymentProcessor', 'get', [
      'class_name' => 'Payment_Stripe',
      'is_active' => 1,
      'domain_id' => CRM_Core_Config::domainID(),
    ]);

    foreach ($result['values'] as $paymentProcessor) {
      $webhook_path = self::getWebhookPath($paymentProcessor['id']);
      $processor = new CRM_Core_Payment_Stripe('', civicrm_api3('PaymentProcessor', 'getsingle', ['id' => $paymentProcessor['id']]));
      $processor->setAPIParams();

      try {
        $webhooks = $processor->stripeClient->webhookEndpoints->all(["limit" => 100]);
      }
      catch (Exception $e) {
        $error = $e->getMessage();
        $messages[] = new CRM_Utils_Check_Message(
          __FUNCTION__ . $paymentProcessor['id'] . 'stripe_webhook',
          $error,
          self::getTitle($paymentProcessor),
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
          try {
            $updates = self::checkWebhook($wh);

            if (!empty($wh->api_version) && (strtotime($wh->api_version) < strtotime(CRM_Stripe_Check::API_MIN_VERSION))) {
              // Add message about API version.
              $messages[] = new CRM_Utils_Check_Message(
                __FUNCTION__ . $paymentProcessor['id'] . 'stripe_webhook',
                E::ts('Webhook API version is set to %2 but CiviCRM requires %3. To correct this please delete the webhook at Stripe and then revisit this page which will recreate it correctly. <em>Webhook path is: <a href="%1" target="_blank">%1</a>.</em>',
                  [
                    1 => urldecode($webhook_path),
                    2 => $wh->api_version,
                    3 => CRM_Stripe_Check::API_VERSION,
                  ]
                ),
                self::getTitle($paymentProcessor),
                \Psr\Log\LogLevel::WARNING,
                'fa-money'
              );
            }

            if ($updates && $wh->status != 'disabled') {
              if ($attemptFix) {
                // We should try to update the webhook.
                $messages[] = new CRM_Utils_Check_Message(
                  __FUNCTION__ . $paymentProcessor['id'] . 'stripe_webhook',
                  E::ts('Unable to update the webhook %1. To correct this please delete the webhook at Stripe and then revisit this page which will recreate it correctly.',
                    [1 => urldecode($webhook_path)]
                  ),
                  self::getTitle($paymentProcessor),
                  \Psr\Log\LogLevel::WARNING,
                  'fa-money'
                );
                $processor->stripeClient->webhookEndpoints->update($wh['id'], $updates);
              }
              else {
                $message = new CRM_Utils_Check_Message(
                  __FUNCTION__ . $paymentProcessor['id'] . 'stripe_webhook',
                  E::ts('Problems detected with Stripe webhook! <em>Webhook path is: <a href="%1" target="_blank">%1</a>.</em>',
                    [1 => urldecode($webhook_path)]
                  ),
                  self::getTitle($paymentProcessor),
                  \Psr\Log\LogLevel::WARNING,
                  'fa-money'
                );
                $message->addAction(
                  E::ts('View and fix problems'),
                  NULL,
                  'href',
                  ['path' => 'civicrm/stripe/fix-webhook', 'query' => ['reset' => 1]]
                );
                $messages[] = $message;
              }
            }
          }
          catch (Exception $e) {
            $messages[] = new CRM_Utils_Check_Message(
              __FUNCTION__ . $paymentProcessor['id'] . 'stripe_webhook',
              E::ts('Could not check/update existing webhooks, got error from stripe <em>%1</em>', [
                  1 => htmlspecialchars($e->getMessage())
                ]
              ),
              self::getTitle($paymentProcessor),
              \Psr\Log\LogLevel::WARNING,
              'fa-money'
            );
          }
        }
      }

      if (!$found_wh) {
        if ($attemptFix) {
          try {
            // Try to create one.
            self::createWebhook($paymentProcessor['id']);
          }
          catch (Exception $e) {
            $messages[] = new CRM_Utils_Check_Message(
              __FUNCTION__ . $paymentProcessor['id'] . 'stripe_webhook',
              E::ts('Could not create webhook, got error from stripe <em>%1</em>', [
                1 => htmlspecialchars($e->getMessage())
              ]),
              self::getTitle($paymentProcessor),
              \Psr\Log\LogLevel::WARNING,
              'fa-money'
            );
          }
        }
        else {
          $message = new CRM_Utils_Check_Message(
            __FUNCTION__ . $paymentProcessor['id'] . 'stripe_webhook',
            E::ts(
              'Stripe Webhook missing or needs update! <em>Expected webhook path is: <a href="%1" target="_blank">%1</a></em>',
              [1 => $webhook_path]
            ),
            self::getTitle($paymentProcessor),
            \Psr\Log\LogLevel::WARNING,
            'fa-money'
          );
          $message->addAction(
            E::ts('View and fix problems'),
            NULL,
            'href',
            ['path' => 'civicrm/stripe/fix-webhook', 'query' => ['reset' => 1]]
          );
          $messages[] = $message;
        }
      }
    }
  }

  /**
   * Get the error message title for the system check
   * @param array $paymentProcessor
   *
   * @return string
   */
  private static function getTitle($paymentProcessor) {
    if (!empty($paymentProcessor['is_test'])) {
      $paymentProcessor['name'] .= ' (test)';
    }
    return E::ts('Stripe Payment Processor: %1 (%2)', [
      1 => $paymentProcessor['name'],
      2 => $paymentProcessor['id'],
    ]);
  }

  /**
   * Create a new webhook for payment processor
   *
   * @param int $paymentProcessorId
   */
  public static function createWebhook($paymentProcessorId) {
    $processor = new CRM_Core_Payment_Stripe('', civicrm_api3('PaymentProcessor', 'getsingle', ['id' => $paymentProcessorId]));
    $processor->setAPIParams();

    $params = [
      'enabled_events' => self::getDefaultEnabledEvents(),
      'url' => self::getWebhookPath($paymentProcessorId),
      'connect' => FALSE,
    ];
    $processor->stripeClient->webhookEndpoints->create($params);
  }


  /**
   * Check and update existing webhook
   *
   * @param array $webhook
   * @return array of correction params. Empty array if it's OK.
   */
  public static function checkWebhook($webhook) {
    $params = [];

    if (array_diff(self::getDefaultEnabledEvents(), $webhook['enabled_events'])) {
      $params['enabled_events'] = self::getDefaultEnabledEvents();
    }

    return $params;
  }

  /**
   * List of webhooks we currently handle
   * @return array
   */
  public static function getDefaultEnabledEvents() {
    return [
      'invoice.finalized',
      //'invoice.paid' Ignore this event because it sometimes causes duplicates (it's sent at almost the same time as invoice.payment_succeeded
      //   and if they are both processed at the same time the check to see if the payment already exists is missed and it gets created twice.
      'invoice.payment_succeeded',
      'invoice.payment_failed',
      'charge.failed',
      'charge.refunded',
      'charge.succeeded',
      'charge.captured',
      'customer.subscription.updated',
      'customer.subscription.deleted',
    ];
  }

}
