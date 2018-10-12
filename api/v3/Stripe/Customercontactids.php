<?php

/**
 * Stripe Customer API
 *
 */

/**
 * Stripe.Customer_Contactids API
 *  This api will update the civicrm_stripe_customers table and add contact IDs for all known email addresses
 *
 * @param array $params
 * @see civicrm_api3_create_success
 *
 * @return array
 */
function civicrm_api3_stripe_customercontactids($params) {
  $dao = CRM_Core_DAO::executeQuery('SELECT email, id FROM civicrm_stripe_customers WHERE contact_id IS NULL');
  $counts = [
    'updated' => 0,
    'failed' => 0,
  ];
  while ($dao->fetch()) {
    $contactId = NULL;
    try {
      $contactId = civicrm_api3('Contact', 'getvalue', [
        'return' => "id",
        'email' => $dao->email,
      ]);

    } catch (Exception $e) {
      // Most common problem is duplicates.
      if(preg_match("/Expected one Contact but found/", $e->getMessage())) {
        // If we find more than one, first try to find it via a related subscription record
        // using the customer id.
        $sql = "SELECT c.id
          FROM civicrm_contribution_recur rc
            JOIN civicrm_stripe_subscriptions sc ON
              ( rc.id = sc.contribution_recur_id OR rc.invoice_id = sc.invoice_id)
            JOIN civicrm_contact c ON c.id = rc.contact_id
          WHERE c.is_deleted = 0 AND customer_id = %0
          ORDER BY start_date DESC LIMIT 1";
        $dao_contribution = CRM_Core_DAO::executeQuery($sql, array(0 => array($dao->id, 'String')));
        $dao_contribution->fetch();
        if ($dao_contribution->id) {
          $contactId = $dao_contribution->id;
        }
        if (empty($contactId)) {
          // Still no luck. Now get desperate.
          $sql = "SELECT c.id
            FROM civicrm_contact c JOIN civicrm_email e ON c.id = e.contact_id
            JOIN civicrm_contribution cc ON c.id = cc.contact_id
            WHERE e.email = %0 AND c.is_deleted = 0 AND is_test = 0 AND
              trxn_id LIKE 'ch_%' AND contribution_status_id = 1
            ORDER BY receive_date DESC LIMIT 1";
          $dao_contribution = CRM_Core_DAO::executeQuery($sql, array(0 => array($dao->email, 'String')));
          $dao_contribution->fetch();
          if ($dao_contribution->id) {
            $contactId = $dao_contribution->id;
          }
        }
      }
      if (empty($contactId)) {
        // Still no luck. Log it and move on.
        Civi::log()
          ->debug('Stripe Upgrader: No contact ID found for stripe customer with email: ' . $dao->email);
        $counts['failed']++;
        continue;
      }
    }
    CRM_Core_DAO::executeQuery("UPDATE `civicrm_stripe_customers` SET contact_id={$contactId} WHERE email='{$dao->email}'");
    $counts['updated']++;
  }
  return civicrm_api3_create_success($counts);
}
