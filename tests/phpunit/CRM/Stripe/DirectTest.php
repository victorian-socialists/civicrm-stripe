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

/**
 * Test a simple, direct payment via Stripe. 
 *
 * @group headless
 */
class CRM_Stripe_DirectTest extends CRM_Stripe_BaseTest {

  public function setUp(): void {
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  /**
   * Test making a recurring contribution.
   */
  public function testDirectSuccess() {
    $this->setupTransaction();
    $this->doPayment();
    $this->assertValidTrxn();
  }

}
